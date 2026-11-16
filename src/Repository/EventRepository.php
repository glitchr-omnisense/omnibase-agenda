<?php

namespace Base\Agenda\Repository;

use Base\Agenda\Entity\Event;
use Base\Contract\Runtime;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Enum\ThreadState;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Type\Utc;

/**
 * The dates, read through omnibase's finder language (plan "base-agnostic", phase 1): criteria arrays, groups for
 * the parentheses, nothing of a backend's query language. "Now" is the application's clock, in UTC (one time for
 * every engine: no database clock); "today" and a month are the visitor's, in the visitor's zone (PHP's, which the
 * host sets from the timezone cookie), as the instants that bound them.
 *
 * @method Event|null find($id, $lockMode = null, $lockVersion = null)
 * @method Event|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Event[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Event::class);
    }

    /**
     * The published dates to come, the nearest first. Today's count until
     * midnight, and a date of several days until its last one.
     *
     * @return list<Event>
     */
    public function findUpcoming(?int $limit = null): array
    {
        return $this->findBy($this->upcoming(), ['startsAt' => 'ASC', 'id' => 'ASC'], $limit);
    }

    /**
     * The dates on today, wherever they are: a window of a day and a half
     * around now, then each one's own day in its own timezone (Event::isToday).
     *
     * @return list<Event>
     */
    public function findToday(?\DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $from = self::moment($now->modify('-36 hours'));
        $to = self::moment($now->modify('+36 hours'));
        $candidates = $this->findBy([...$this->published(), 'cancelled' => false, Group::of(
            Parser::SEPARATOR_OR,
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('startsAt', $from, Parser::OPTION_GREATER_EQUAL),
                Criterion::of('startsAt', $to, Parser::OPTION_LOWER_EQUAL),
            ),
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('endsAt', null, Parser::OPTION_NOT_NULL),
                Criterion::of('startsAt', $to, Parser::OPTION_LOWER_EQUAL),
                Criterion::of('endsAt', $from, Parser::OPTION_GREATER_EQUAL),
            ),
        )], ['startsAt' => 'ASC']);

        return array_values(array_filter($candidates, static fn (Event $event) => $event->isToday($now)));
    }

    public function countUpcoming(): int
    {
        return $this->count($this->upcoming());
    }

    /**
     * A page of the published dates gone by, the latest first. Paged by
     * hand (a limit and an offset).
     *
     * @return list<Event>
     */
    public function findPast(?int $limit = null, int $page = 1): array
    {
        return $this->findBy($this->past(), ['startsAt' => 'DESC', 'id' => 'DESC'], $limit, $limit ? max(0, $page - 1) * $limit : 0);
    }

    public function countPast(): int
    {
        return $this->count($this->past());
    }

    public function findOnePublished(string $slug): ?Event
    {
        return $this->findOneBy([...$this->published(), 'slug' => $slug]);
    }

    /**
     * The published dates starting from $from up to (not including) $to,
     * in order: a calendar feed, a newsletter's digest.
     *
     * @return list<Event>
     */
    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->findBy([...$this->published(), Group::of(Parser::SEPARATOR_AND,
            Criterion::of('startsAt', self::moment($from), Parser::OPTION_GREATER_EQUAL),
            Criterion::of('startsAt', self::moment($to), Parser::OPTION_LOWER),
        )], ['startsAt' => 'ASC', 'id' => 'ASC']);
    }

    /** The published dates from $from on, in order: what a subscribed calendar holds. @return list<Event> */
    public function findSince(\DateTimeInterface $from): array
    {
        return $this->findBy([...$this->published(), Group::of(Parser::SEPARATOR_AND,
            Criterion::of('startsAt', self::moment($from), Parser::OPTION_GREATER_EQUAL),
        )], ['startsAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * A page of the JSON feed: the dates to come (the nearest first), or
     * the ones gone by ($past, the latest first), within [$from, $to] when
     * given (both days included, in the server's timezone). One more than
     * $limit is asked for: whether a next page exists.
     *
     * @return list<Event>
     */
    public function findForFeed(bool $past = false, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 100, int $page = 1): array
    {
        $criteria = match (true) {
            $past => $this->past(),
            null !== $from || null !== $to => $this->published(),
            default => $this->upcoming(),
        };
        if ($from) {
            $from = self::moment($from->setTime(0, 0));
            $criteria[] = Group::of(Parser::SEPARATOR_OR,
                Criterion::of('startsAt', $from, Parser::OPTION_GREATER_EQUAL),
                Group::of(Parser::SEPARATOR_AND,
                    Criterion::of('endsAt', null, Parser::OPTION_NOT_NULL),
                    Criterion::of('endsAt', $from, Parser::OPTION_GREATER_EQUAL),
                ),
            );
        }
        if ($to) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('startsAt', self::moment($to->setTime(0, 0)->modify('+1 day')), Parser::OPTION_LOWER));
        }
        $order = $past ? 'DESC' : 'ASC';

        return $this->findBy($criteria, ['startsAt' => $order, 'id' => $order], $limit + 1, max(0, $page - 1) * $limit);
    }

    /** Every published date, the latest first: the sitemap's. @return list<Event> */
    public function findAllPublished(int $limit = 5000): array
    {
        return $this->findBy($this->published(), ['startsAt' => 'DESC'], $limit);
    }

    /** The published dates of one month (the visitor's, in the visitor's zone). @return list<Event> */
    public function findByMonth(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone(Runtime::find(\Base\Contract\Locales::class)?->getTimezone() ?? date_default_timezone_get()));

        return $this->findBetween($from, $from->modify('+1 month'));
    }

    /** The date read from that calendar entry, whatever its state: the importer's. */
    public function findBySourceUid(string $uid): ?Event
    {
        return $this->findOneBy(['sourceUid' => $uid]);
    }

    /**
     * The dates to come read from one calendar and not cancelled yet: the
     * ones the importer checks are still in it.
     *
     * @return list<Event>
     */
    public function findUpcomingOfSource(string $source): array
    {
        return $this->findBy(['source' => $source, 'cancelled' => false, Group::of(Parser::SEPARATOR_AND,
            Criterion::of('startsAt', self::today(), Parser::OPTION_GREATER_EQUAL),
        )]);
    }

    /** @return array<int|string, mixed> the criteria of the published dates to come (or still on: a date of several days) */
    private function upcoming(): array
    {
        $today = self::today();

        return [...$this->published(), Group::of(Parser::SEPARATOR_OR,
            Criterion::of('startsAt', $today, Parser::OPTION_GREATER_EQUAL),
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('endsAt', null, Parser::OPTION_NOT_NULL),
                Criterion::of('endsAt', $today, Parser::OPTION_GREATER_EQUAL),
            ),
        )];
    }

    /** @return array<int|string, mixed> the criteria of the published dates gone by */
    private function past(): array
    {
        $today = self::today();

        return [...$this->published(), Group::of(Parser::SEPARATOR_AND,
            Criterion::of('startsAt', $today, Parser::OPTION_LOWER),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('endsAt', null, Parser::OPTION_NULL),
                Criterion::of('endsAt', $today, Parser::OPTION_LOWER),
            ),
        )];
    }

    /**
     * @return array<int|string, mixed> the criteria of the published dates: in the published state, out (no
     *                                  publication date, or one gone by on the application's clock)
     */
    private function published(): array
    {
        return ['state' => ThreadState::PUBLISH, Group::of(Parser::SEPARATOR_OR,
            Criterion::of('publishedAt', null, Parser::OPTION_NULL),
            Criterion::of('publishedAt', self::now(), Parser::OPTION_LOWER_EQUAL),
        )];
    }

    /** The application's clock, in UTC. */
    private static function now(): \DateTimeImmutable
    {
        return Utc::from(Runtime::clock()->now());
    }

    /** The start of the visitor's today (in the visitor's zone) on the application's clock, as a bound of startsAt and endsAt. */
    private static function today(): \DateTimeImmutable
    {
        return self::moment(Runtime::clock()->now()->setTimezone(new \DateTimeZone(Runtime::find(\Base\Contract\Locales::class)?->getTimezone() ?? date_default_timezone_get()))->setTime(0, 0));
    }

    /** A bound of startsAt or endsAt: the same moment in UTC, as both engines store those columns. */
    private static function moment(\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(Utc::zone());
    }
}

<?php

namespace Base\Agenda\Tests\Persistence;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Base\Agenda\Repository\EventRepository;
use Base\Contract\Locales;
use Base\Contract\Runtime;
use Base\Entity\Thread;
use Base\Entity\ThreadIntl;
use Base\Enum\ThreadState;
use Base\Enum\WorkflowState;
use Base\Testing\Engines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * The dates' "published", "upcoming", "past" and "today" are read on the application's clock, in UTC
 * (Runtime::clock()), the same rows on Doctrine and on Eloquent: no query reads the database's clock.
 */
final class EventsOnTheClockTest extends TestCase
{
    private static Engines $engines;

    private MockClock $clock;

    private string $zone;

    /** The Runtime's Locales before the case (a host's, booted earlier), put back after it. */
    private ?Locales $locales = null;

    /** @var Locales&object{zone: string} the visitor the cases are */
    private Locales $visitor;

    /** The visitor's zone (the Runtime's Locales') - and PHP's, as a host puts it. */
    private function visitorIn(string $zone): void
    {
        $this->visitor->zone = $zone;
        date_default_timezone_set($zone);
    }

    public static function setUpBeforeClass(): void
    {
        self::$engines = Engines::of([Thread::class, Event::class, Venue::class]);
        // Published at 10:00 UTC, on stage next year.
        self::event('at-ten', '2026-03-01 10:00:00', '2027-01-01 20:00:00');
        // Published long ago: one the evening before, one early today (UTC).
        self::event('yesterday', '2026-01-01 10:00:00', '2026-02-28 20:00:00');
        self::event('early-today', '2026-01-01 10:00:00', '2026-03-01 01:00:00');
        self::event('draft', '2026-01-01 10:00:00', '2027-01-02 20:00:00', ThreadState::DRAFT);
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return Engines::backends();
    }

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        // The visitor's zone is the Runtime's Locales' (a host's, when one was booted before): a double, whose zone
        // each case chooses with visitorIn().
        $this->locales = Runtime::find(Locales::class);
        $this->visitor = new class($this->locales) implements Locales {
            public string $zone = 'UTC';
            public function __construct(private readonly ?Locales $host) {}
            public function getLocale(): string { return $this->host?->getLocale() ?? 'fr'; }
            public function getDefaultLocale(): string { return $this->host?->getDefaultLocale() ?? 'fr'; }
            public function getAvailableLocales(): array { return $this->host?->getAvailableLocales() ?? ['fr']; }
            public function getTimezone(): string { return $this->zone; }
        };
        Runtime::set(Locales::class, $this->visitor);
        $this->visitorIn('UTC');
        Runtime::set(ClockInterface::class, $this->clock = new MockClock('2026-03-01 09:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Runtime::set(ClockInterface::class, null);
        Runtime::set(Locales::class, $this->locales);
        date_default_timezone_set($this->zone);
    }

    private static function event(string $slug, string $publishedAt, string $startsAt, string $state = ThreadState::PUBLISH): void
    {
        $id = self::$engines->insert('thread', ['uuid' => uniqid('', true), 'slug' => $slug, 'state' => $state, 'workflow' => WorkflowState::APPROVED,
            'createdAt' => '2026-01-01 10:00:00', 'updatedAt' => '2026-01-01 10:00:00', 'publishedAt' => $publishedAt, 'class' => self::$engines->discriminator(Event::class)]);
        self::$engines->insert('threadIntl', ['title' => $slug, 'keywords' => '[]', 'locale' => 'fr', 'translatable_id' => $id, 'class' => self::$engines->discriminator(ThreadIntl::class)]);
        self::$engines->insert('agenda_event', ['id' => $id, 'startsAt' => $startsAt, 'timezone' => 'UTC', 'allDay' => 0, 'role' => 'other', 'cancelled' => 0, 'syncLocked' => 0]);
    }

    /** @param iterable<Event> $events @return list<string> */
    private static function slugs(iterable $events): array
    {
        return array_values(array_map(static fn (Event $event): string => (string) $event->getSlug(), [...$events]));
    }

    /**
     * Published at 10:00 UTC: out at 10:30 UTC, not at 09:30 UTC - also with PHP in Tokyo, where 09:30 UTC is
     * 18:30 (a comparison of wall clocks would publish it).
     * @dataProvider backends
     */
    #[DataProvider('backends')]
    public function testPublishedOnTheApplicationsClockInUtc(string $backend): void
    {
        $this->visitorIn('Asia/Tokyo');
        $events = new EventRepository(self::$engines->finders($backend));

        self::assertNull($events->findOnePublished('at-ten'), 'half an hour early');
        self::assertNotContains('at-ten', self::slugs($events->findAllPublished()));

        $this->clock->modify('2026-03-01 10:30:00 UTC');
        self::assertSame('at-ten', $events->findOnePublished('at-ten')?->getSlug(), 'half an hour after');
        self::assertContains('at-ten', self::slugs($events->findAllPublished()));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testUpcomingPastAndTodayOnTheApplicationsDay(string $backend): void
    {
        $events = new EventRepository(self::$engines->finders($backend));

        self::assertSame(['early-today'], self::slugs($events->findUpcoming()), "today's count until midnight (UTC); at-ten not out yet, the draft never");
        self::assertSame(['yesterday'], self::slugs($events->findPast()));
        self::assertSame(['early-today'], self::slugs($events->findToday()), 'today on the application\'s clock');
        self::assertSame(1, $events->countUpcoming());

        $this->clock->modify('2026-03-02 00:30:00 UTC');
        self::assertSame(['early-today', 'yesterday'], self::slugs($events->findPast()), 'the next day: gone by');
        self::assertSame(['at-ten'], self::slugs($events->findUpcoming()));
    }

    /**
     * "Today" is the VISITOR's day, in the visitor's zone (PHP's, set by the host from the timezone cookie): at
     * 16:00 UTC on 2026-03-01 it is already the 2nd in Tokyo, so early-today (01:00 UTC on the 1st) is gone by there,
     * and still on today for a visitor in UTC.
     * @dataProvider backends
     */
    #[DataProvider('backends')]
    public function testTodayIsTheVisitorsDay(string $backend): void
    {
        $events = new EventRepository(self::$engines->finders($backend));
        $this->clock->modify('2026-03-01 16:00:00 UTC');

        self::assertSame(['early-today', 'at-ten'], self::slugs($events->findUpcoming()), 'in UTC: still the 1st (at-ten is out since 10:00 UTC)');
        $this->visitorIn('Asia/Tokyo');
        self::assertSame(['at-ten'], self::slugs($events->findUpcoming()), 'in Tokyo: already the 2nd');
        self::assertSame(['early-today', 'yesterday'], self::slugs($events->findPast()));
    }
}

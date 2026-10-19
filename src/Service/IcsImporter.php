<?php

namespace Base\Agenda\Service;

use Base\Agenda\Entity\Event;
use Base\Agenda\Entity\Venue;
use Base\Agenda\Enum\Role;
use Base\Agenda\Model\Change;
use Base\Agenda\Model\ImportSummary;
use Base\Agenda\Model\ParsedEvent;
use Base\Agenda\Repository\EventRepository;
use Base\Agenda\Repository\VenueRepository;
use Base\Enum\ThreadState;
use Base\Service\SettingBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The dates read from where they are kept - a Google Calendar's secret
 * address (the artist's, from her phone), an agency's ICS feed, a
 * Squarespace events page (the former site's), another omnibase site's
 * /agenda.json - into Events, matched by the entry's UID.
 *
 * The calendar is the source of truth: at each sync a date takes from it
 * its title, its hours, its place (a Venue found or opened), the
 * organiser's link, a cancellation, and what its description says
 * (Service\DescriptionParser: the ensemble, the conductor, the role, the
 * performers, the programme, the tickets) - what it does not say stays as
 * written on the site, and a date marked `syncLocked` takes only its hours
 * and its cancellation. A house's picture the source has (Squarespace's,
 * another site's) becomes the photo of a house that has none, a date's own
 * the cover of a date that has none. A new date goes online at once.
 *
 * Every change is told (Model\Change: each field as it was and as it
 * becomes), and can be only told: import(apply: false) is the preview the
 * back office shows before "Apply". A date gone from its calendar is
 * neither cancelled nor deleted behind anyone's back - a slip of the
 * finger in Google Calendar would strike a concert through on the site -
 * but held (Change::MISSING, the `agenda.sync.missing` setting) until
 * someone says whether it is cancelled or kept (resolveMissing()). The
 * syncs that changed something are kept, latest first (history()).
 */
final class IcsImporter
{
    /** The setting the back office keeps the calendar's address in (Controller\Admin\CalendarController). */
    public const ADDRESS_SETTING = 'agenda.ics';

    /** The setting the last sync's summary is kept in, as JSON. */
    public const LAST_SYNC_SETTING = 'agenda.sync.last';

    /** The setting the syncs that changed something are kept in, latest first, as JSON. */
    public const HISTORY_SETTING = 'agenda.sync.history';

    /** The setting the dates gone from their calendar wait in (their ids), as JSON. */
    public const MISSING_SETTING = 'agenda.sync.missing';

    /** How many syncs the history keeps, and how many changes of each. */
    private const HISTORY_RUNS = 10;
    private const HISTORY_CHANGES = 100;

    /** How many pages of a Squarespace page's past dates are read. */
    private const SQUARESPACE_PAGES = 6;

    /** How many pages of another site's feed are read. */
    private const FEED_PAGES = 20;

    /** The largest picture taken as a cover or a house's photo (their Uploader's limit). */
    private const IMAGE_MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, Venue> the venues of this run, by name and city */
    private array $venues = [];

    /** @param list<string> $sources */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly VenueRepository $venueRepository,
        private readonly IcsParser $parser,
        private readonly SquarespaceParser $squarespace,
        private readonly FeedParser $feeds,
        private readonly HttpClientInterface $http,
        private readonly ?SettingBagInterface $settings = null,
        #[Autowire('%agenda.sources%')] private readonly array $sources = [],
        #[Autowire('%agenda.ics%')] private readonly ?string $ics = null,
    ) {
    }

    /**
     * Every calendar to read: agenda.sources, agenda.ics (an environment
     * variable, comma-separated) and the address typed in the back office.
     *
     * @return list<string>
     */
    public function getSources(): array
    {
        $more = array_map('trim', explode(',', (string) $this->ics.','.(string) $this->getAddress()));

        return array_values(array_unique(array_filter([...$this->sources, ...$more])));
    }

    public function hasSources(): bool
    {
        return [] !== $this->getSources();
    }

    /** The address typed in the back office (comma-separated when several), or null. */
    public function getAddress(): ?string
    {
        try {
            $address = $this->settings?->getScalar(self::ADDRESS_SETTING);
        } catch (\Throwable) {
            return null; // no settings table yet: none typed
        }

        return \is_string($address) && '' !== trim($address) ? trim($address) : null;
    }

    public function setAddress(?string $address): void
    {
        $address = null !== $address && '' !== trim($address) ? trim($address) : null;
        $this->settings?->set(self::ADDRESS_SETTING, $address, self::locale());
    }

    /**
     * What a sync that was applied did, for the back office: when, its
     * counts and errors (the last sync), its changes (the history, when it
     * changed something), the dates it found gone (waiting for a word).
     */
    public function remember(ImportSummary $summary, ?\DateTimeInterface $at = null): void
    {
        $at = \DateTimeImmutable::createFromInterface($at ?? new \DateTimeImmutable())->format(\DATE_ATOM);
        try {
            $this->writeSetting(self::LAST_SYNC_SETTING, ['at' => $at, ...$summary->toArray(), 'errors' => $summary->errors]);
            if (!$summary->isEmpty() || $summary->errors) {
                $this->writeSetting(self::HISTORY_SETTING, \array_slice([[
                    'at' => $at,
                    ...$summary->toArray(),
                    'errors' => $summary->errors,
                    'changes' => array_map(fn (Change $change) => $change->toArray(), \array_slice($summary->changes, 0, self::HISTORY_CHANGES)),
                    'more' => max(0, \count($summary->changes) - self::HISTORY_CHANGES),
                ], ...$this->history()], 0, self::HISTORY_RUNS));
            }
            // A calendar out of reach said nothing of its dates: the ones waiting stay waiting.
            $missing = $summary->missingIds();
            $this->writeSetting(self::MISSING_SETTING, array_values(array_unique($summary->errors ? [...$this->missingIds(), ...$missing] : $missing)));
        } catch (\Throwable) {
            // Not remembered: the sync itself is done.
        }
    }

    /** @return array{at: string, created: int, updated: int, cancelled: int, missing: int, unchanged?: int, errors: list<string>}|null */
    public function lastSync(): ?array
    {
        $last = $this->readSetting(self::LAST_SYNC_SETTING);

        return \is_array($last) && isset($last['at']) ? $last + ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'missing' => 0, 'errors' => []] : null;
    }

    /** @return list<array{at: string, created: int, updated: int, cancelled: int, missing: int, errors: list<string>, changes: list<Change>, more: int}> the syncs that changed something, latest first */
    public function history(): array
    {
        $history = $this->readSetting(self::HISTORY_SETTING);

        return array_values(array_map(fn (array $run) => ['changes' => array_map(fn ($change) => \is_array($change) ? Change::fromArray($change) : $change, $run['changes'] ?? [])]
            + $run + ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'missing' => 0, 'errors' => [], 'more' => 0], array_filter(\is_array($history) ? $history : [], fn ($run) => \is_array($run) && isset($run['at']))));
    }

    /** @return list<int> the dates gone from their calendar, waiting for a word */
    public function missingIds(): array
    {
        $ids = $this->readSetting(self::MISSING_SETTING);

        return \is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @return list<Event> those dates, still to come and not cancelled meanwhile */
    public function missing(): array
    {
        $ids = $this->missingIds();

        return $ids ? array_values(array_filter($this->events->findBy(['id' => $ids], ['startsAt' => 'ASC']), fn (Event $event) => !$event->isCancelled() && $event->isUpcoming())) : [];
    }

    /**
     * The word on dates gone from their calendar: cancelled (struck through
     * on the site, who had a ticket finds it), or kept - the site's own
     * from now on, no calendar asks about it again (an entry back in its
     * calendar takes it back).
     *
     * @param list<int> $ids
     *
     * @return int the dates settled
     */
    public function resolveMissing(array $ids, bool $cancel): int
    {
        $waiting = $this->missingIds();
        $settled = 0;
        foreach ($this->events->findBy(['id' => array_values(array_intersect($waiting, array_map('intval', $ids)))]) as $event) {
            $cancel ? $event->setCancelled(true) : $event->setSource(null);
            ++$settled;
        }
        $this->entityManager->flush();
        $this->writeSetting(self::MISSING_SETTING, array_values(array_diff($waiting, array_map('intval', $ids))));

        return $settled;
    }

    private function writeSetting(string $setting, mixed $value): void
    {
        $this->settings?->set($setting, json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), self::locale());
    }

    private function readSetting(string $setting): mixed
    {
        try {
            $value = $this->settings?->getScalar($setting);
        } catch (\Throwable) {
            return null; // no settings table yet: nothing kept
        }

        return \is_string($value) ? json_decode($value, true) : $value;
    }

    /**
     * Every source configured, or the ones given - applied, or only told
     * ($apply false: the preview).
     *
     * @param list<string>|null $sources
     */
    public function sync(?array $sources = null, bool $apply = true): ImportSummary
    {
        $summary = new ImportSummary();
        foreach ($sources ?? $this->getSources() as $source) {
            try {
                $address = preg_match('#^(webcals?|https?)://#i', trim($source)) ? trim($source) : null;
                $summary->add($this->import($this->read($source), self::key($source), $address, $apply));
            } catch (\Throwable $e) {
                // One calendar out of reach: the others are read, and nothing of its own is cancelled.
                $summary->errors[] = sprintf('%s: %s', self::display($source), self::display($e->getMessage()));
                $this->venues = [];
            }
        }

        return $summary;
    }

    /**
     * One source's content, already read: an iCalendar file, a Squarespace
     * events page's JSON or an agenda feed. $source names it, so that the
     * dates which left it can be told from the ones of another calendar;
     * $address, where it was read, is where its next pages are. $apply
     * false: nothing is written, nothing is fetched (no picture), the
     * changes are only told.
     */
    public function import(string $content, string $source = 'ics', ?string $address = null, bool $apply = true): ImportSummary
    {
        $summary = new ImportSummary();
        $seen = [];
        foreach ($this->parse($content, $address) as $parsed) {
            if (isset($seen[$parsed->uid])) {
                continue; // two pages overlapping
            }
            $seen[$parsed->uid] = true;
            $event = $this->events->findBySourceUid($parsed->uid);
            $proposal = $this->proposal($event, $parsed);

            if (!$event) {
                $summary->record(new Change(Change::CREATED, $parsed->summary, self::clock($parsed->start, $parsed->timezone, $parsed->allDay), null, $this->diff(null, $proposal)));
                if ($apply) {
                    $this->entityManager->persist($this->create($parsed, $source, $proposal));
                }
                continue;
            }

            $fields = $this->diff($event, $proposal);
            $change = $fields ? new Change(
                isset($fields['cancelled']) && $proposal['cancelled'] ? Change::CANCELLED : Change::UPDATED,
                (string) $event->getTitle(),
                self::clock($event->getStartsAt(), $event->getTimezone(), $event->isAllDay()),
                $event->getId(),
                $fields,
            ) : null;
            if ($apply) {
                $this->apply($event, $proposal);
                $event->setSource($source);
                $event->setSourceUpdatedAt($parsed->lastModified);
                if (!$event->isSyncLocked()) {
                    $this->pictures($event, $parsed);
                }
            }
            $change ? $summary->record($change) : ++$summary->unchanged;
        }

        // Gone from its calendar: held, not cancelled (see the class). Only the dates to
        // come are asked: a Squarespace page lists the past ones a few pages deep.
        foreach ($this->events->findUpcomingOfSource($source) as $event) {
            if (!isset($seen[$event->getSourceUid()]) && !$event->isCancelled()) {
                $summary->record(new Change(Change::MISSING, (string) $event->getTitle(), self::clock($event->getStartsAt(), $event->getTimezone(), $event->isAllDay()), $event->getId()));
            }
        }

        if ($apply) {
            $this->entityManager->flush();
        }
        $this->venues = [];

        return $summary;
    }

    /**
     * The dates a content holds, whatever it is: an iCalendar file (each
     * entry's description read for its fields), a Squarespace events page
     * (its past dates followed a few pages back), an agenda feed (its pages
     * followed).
     *
     * @return list<ParsedEvent>
     */
    public function parse(string $content, ?string $address = null): array
    {
        if (str_contains($content, 'BEGIN:VCALENDAR')) {
            return array_map(fn (ParsedEvent $event) => self::described($event), $this->parser->parse($content));
        }

        $data = json_decode(trim($content), true);
        if (SquarespaceParser::supports($data)) {
            $events = $this->squarespace->parse($data);
            for ($page = 1; $address && $page < self::SQUARESPACE_PAGES && ($next = SquarespaceParser::nextPage($data, self::https($address))); ++$page) {
                $data = json_decode($this->fetch($next, 'application/json'), true);
                if (!SquarespaceParser::supports($data)) {
                    break;
                }
                array_push($events, ...$this->squarespace->parse($data));
            }

            return $events;
        }
        if (FeedParser::supports($data)) {
            $events = $this->feeds->parse($data);
            for ($page = 1; $page < self::FEED_PAGES && ($next = FeedParser::next($data)); ++$page) {
                $data = json_decode($this->fetch($next, 'application/json'), true);
                if (!FeedParser::supports($data)) {
                    break;
                }
                array_push($events, ...$this->feeds->parse($data));
            }

            return $events;
        }

        throw new \UnexpectedValueException('neither an iCalendar file, a Squarespace events page nor an agenda feed');
    }

    /**
     * An entry with what its description says filled in: the fields its
     * lines name; the lines no word opens are the programme when none is
     * named (the description of an entry nobody structured).
     */
    public static function described(ParsedEvent $event): ParsedEvent
    {
        if (null === $event->description) {
            return $event;
        }
        $read = DescriptionParser::parse($event->description);
        $programme = $read['programme'] ?: preg_split('/\R/u', (string) $read['rest'], -1, \PREG_SPLIT_NO_EMPTY);

        return $event->with([
            'ensemble' => $read['ensemble'],
            'conductor' => $read['conductor'],
            'role' => $read['role'],
            'performers' => $read['performers'] ?: null,
            'programme' => $programme ?: null,
            'tickets' => $read['tickets'],
        ]);
    }

    /** What names a source in the database: short, stable, nothing of a private address in it. */
    public static function key(string $source): string
    {
        return sha1(trim($source));
    }

    /**
     * "Elbphilharmonie, Platz der Deutschen Einheit 1, 20457 Hamburg, Germany":
     * the name before the first comma, the town in the last part (the one
     * before it when the last is a country: no figure in it, a postcode in
     * the one before), the street in between.
     *
     * @return array{name: string, address: ?string, postcode: ?string, city: ?string}
     */
    public static function splitLocation(string $location): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', str_replace("\n", ',', $location))), fn (string $part) => '' !== $part));
        $name = array_shift($parts) ?? trim($location);
        if (!$parts) {
            return ['name' => $name, 'address' => null, 'postcode' => null, 'city' => null];
        }

        $city = array_pop($parts);
        if ($parts && !preg_match('/\d/', $city) && preg_match('/\d{4,5}/', end($parts))) {
            $city = array_pop($parts);
        }
        $postcode = null;
        if (preg_match('/^(?:[A-Z]{1,2}-)?(\d{4,5})\s+(.+)$/u', $city, $m) || preg_match('/^(.+?)\s+(\d{4,5})$/u', $city, $m)) {
            [$postcode, $city] = ctype_digit($m[1]) ? [$m[1], $m[2]] : [$m[2], $m[1]];
        }

        return ['name' => $name, 'address' => $parts ? implode(', ', $parts) : null, 'postcode' => $postcode, 'city' => '' !== $city ? $city : null];
    }

    /** @param array<string, mixed> $proposal */
    private function create(ParsedEvent $parsed, string $source, array $proposal): Event
    {
        // The mapped class: App\Entity\Agenda\Event when the application took over.
        $class = $this->events->getClassName();
        /** @var Event $event */
        $event = new $class();
        $event->setSourceUid($parsed->uid);
        $event->setSource($source);
        $event->setSourceUpdatedAt($parsed->lastModified);
        $this->apply($event, $proposal);
        $event->setState(ThreadState::PUBLISH);
        $event->setPublishedAt(new \DateTime());
        $this->pictures($event, $parsed);

        return $event;
    }

    /**
     * What the calendar gives a date, field by field: when, and whether it
     * takes place, always (locked or not); the rest when the date is not
     * locked, and only what the entry says - what it does not say stays as
     * written on the site; a word with nothing after it ("Chef :") clears.
     *
     * @return array<string, mixed>
     */
    private function proposal(?Event $event, ParsedEvent $parsed): array
    {
        $proposal = ['startsAt' => $parsed->start, 'endsAt' => $parsed->end, 'allDay' => $parsed->allDay, 'timezone' => $parsed->timezone, 'cancelled' => $parsed->cancelled];
        if ($event?->isSyncLocked()) {
            return $proposal;
        }

        $proposal['title'] = $parsed->summary;
        // The site's own page of the date (what its .ics gives as URL) is not the organiser's.
        if ($parsed->url && !($event?->getSlug() && preg_match('#/agenda/'.preg_quote($event->getSlug(), '#').'/?$#', (string) parse_url($parsed->url, \PHP_URL_PATH)))) {
            $proposal['eventUrl'] = $parsed->url;
        }
        if ($place = self::placeOf($parsed)) {
            $proposal['venue'] = $place;
        }
        foreach (['ensemble' => 180, 'conductor' => 180] as $field => $length) {
            if (null !== $parsed->{$field}) {
                $proposal[$field] = self::text($parsed->{$field}, $length);
            }
        }
        if (null !== $parsed->role) {
            $proposal['role'] = Role::of($parsed->role)->value;
        }
        if (null !== $parsed->performers) {
            $proposal['performers'] = self::text(implode("\n", $parsed->performers));
        }
        if (null !== $parsed->programme) {
            $proposal['programme'] = self::text(implode("\n", $parsed->programme));
        }
        if (null !== $parsed->tickets) {
            $proposal['ticketsUrl'] = self::text($parsed->tickets, 500);
        }

        return $proposal;
    }

    /**
     * What a proposal changes on a date, as it reads: each field as it was
     * and as it becomes (the hours on the hall's clock, the house by its
     * name and town). A date not yet on the site: every field it gets.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private function diff(?Event $event, array $proposal): array
    {
        $fields = [];
        foreach ($proposal as $field => $value) {
            $after = $this->shown($field, $value, $proposal['timezone'], $proposal['allDay']);
            $before = $event ? $this->shown($field, match ($field) {
                'startsAt' => $event->getStartsAt(),
                'endsAt' => $event->getEndsAt(),
                'allDay' => $event->isAllDay(),
                'timezone' => $event->getTimezone(),
                'cancelled' => $event->isCancelled(),
                'title' => $event->getTitle(),
                'eventUrl' => $event->getEventUrl(),
                'venue' => $event->getVenue(),
                'ensemble' => $event->getEnsemble(),
                'conductor' => $event->getConductor(),
                'role' => $event->getRole(),
                'performers' => $event->getPerformers(),
                'programme' => $event->getProgramme(),
                'ticketsUrl' => $event->getTicketsUrl(),
            }, $event->getTimezone(), $event->isAllDay()) : null;
            if ($before !== $after && ($event || null !== $after)) {
                $fields[$field] = [$before, $after];
            }
        }

        return $fields;
    }

    /** A field's value as a person reads it: a moment on its hall's clock, yes or no, a house by its label. */
    private function shown(string $field, mixed $value, string $timezone, bool $allDay): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof \DateTimeInterface => self::clock($value, $timezone, $allDay),
            \is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof Venue => $value->getLabel(),
            'venue' === $field && \is_array($value) => ($this->findVenue($value) ?? new Venue($value['name'], $value['city'] ?? null))->getLabel(),
            default => '' !== (string) $value ? (string) $value : null,
        };
    }

    /** @param array<string, mixed> $proposal */
    private function apply(Event $event, array $proposal): void
    {
        foreach ($proposal as $field => $value) {
            match ($field) {
                'startsAt' => $event->setStartsAt($value),
                'endsAt' => $event->setEndsAt($value),
                'allDay' => $event->setAllDay($value),
                'timezone' => $event->setTimezone($value),
                'cancelled' => $event->setCancelled($value),
                'title' => $event->getTitle() !== $value ? $event->setTitle($value) : null,
                'eventUrl' => $event->setEventUrl($value),
                'venue' => $event->setVenue($this->venue($value)),
                'ensemble' => $event->setEnsemble($value),
                'conductor' => $event->setConductor($value),
                'role' => $event->setRole($value),
                'performers' => $event->setPerformers($value),
                'programme' => $event->setProgramme($value),
                'ticketsUrl' => $event->setTicketsUrl($value),
            };
        }
    }

    /** A moment as its hall's clock shows it: "2026-11-23 20:00", a day alone for a date of whole days. */
    private static function clock(?\DateTimeInterface $at, string $timezone, bool $allDay): string
    {
        if (!$at) {
            return '';
        }
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }

        return \DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format($allDay ? 'Y-m-d' : 'Y-m-d H:i');
    }

    /** A text cut to its column, empty as none. */
    private static function text(string $text, ?int $length = null): ?string
    {
        $text = null !== $length ? mb_substr($text, 0, $length) : $text;

        return '' !== trim($text) ? $text : null;
    }

    /**
     * The hall of an entry: the one the source structures, else the one its
     * LOCATION names; null when it names none.
     *
     * @return array<string, mixed>|null
     */
    private static function placeOf(ParsedEvent $parsed): ?array
    {
        $place = $parsed->place;
        if (!$place && null !== $parsed->location && '' !== trim($parsed->location)) {
            // The whole line kept: a house's name may hold a comma ("Elbphilharmonie, Great Hall").
            $place = ['location' => trim($parsed->location)] + self::splitLocation($parsed->location);
        }
        if (!$place || '' === trim((string) ($place['name'] ?? ''))) {
            return null;
        }
        $place['name'] = mb_substr(trim($place['name']), 0, 180);
        $place['city'] = isset($place['city']) && '' !== trim((string) $place['city']) ? mb_substr(trim($place['city']), 0, 120) : null;

        return $place;
    }

    /**
     * The house a place names, among the ones of this run and the database's;
     * null: none yet. A LOCATION the site wrote itself (its .ics read back
     * from a Google Calendar) is the full address of one of its houses.
     */
    private function findVenue(array $place): ?Venue
    {
        // One house under the spellings a calendar gives it ("Bogota", "Bogotá"), as the database's own comparison would have it.
        return $this->venues[Venue::key($place['name'], $place['city'])]
            ?? (isset($place['location']) ? $this->venueRepository->findOneByFullAddress($place['location']) : null)
            ?? $this->venueRepository->findOneByNameAndCity($place['name'], $place['city']);
    }

    /** The house a place names, opened when there is none. */
    private function venue(array $place): Venue
    {
        return $this->venues[Venue::key($place['name'], $place['city'])] ??= $this->findVenue($place) ?? $this->openVenue($place);
    }

    /** @param array{name: string, city: ?string, hall?: ?string, address?: ?string, postcode?: ?string, country?: ?string, latitude?: ?float, longitude?: ?float} $place */
    private function openVenue(array $place): Venue
    {
        $venue = (new Venue($place['name'], $place['city']))
            ->setHall(isset($place['hall']) ? mb_substr((string) $place['hall'], 0, 180) : null)
            ->setAddress(isset($place['address']) ? mb_substr((string) $place['address'], 0, 255) : null)
            ->setPostcode(isset($place['postcode']) ? mb_substr((string) $place['postcode'], 0, 16) : null)
            ->setCountry(isset($place['country']) && preg_match('/^[A-Za-z]{2}$/', (string) $place['country']) ? $place['country'] : null)
            ->setLatitude($place['latitude'] ?? null)
            ->setLongitude($place['longitude'] ?? null);
        $this->entityManager->persist($venue);

        return $venue;
    }

    /** The house's picture to a house that has none (with its credit), the date's own to a date that has none. */
    private function pictures(Event $event, ParsedEvent $parsed): void
    {
        $venue = $event->getVenue();
        $photo = $parsed->place['photo'] ?? null;
        if (null !== $photo && $venue && !$venue->hasPhoto() && ($file = $this->download($photo))) {
            $venue->setPhoto($file);
            $venue->setPhotoCredit($venue->getPhotoCredit() ?? ($parsed->place['photoCredit'] ?? null));
        }
        if (null !== $parsed->image && !$event->hasCover() && ($file = $this->download($parsed->image))) {
            $event->setCover($file);
        }
    }

    /** A picture read from its address, as an upload; null when it cannot be had. */
    private function download(string $image): ?File
    {
        if (!preg_match('#^https?://#i', $image)) {
            return null;
        }
        try {
            $response = $this->http->request('GET', $image, ['timeout' => 20, 'max_duration' => 60]);
            $type = strtolower($response->getHeaders()['content-type'][0] ?? '');
            $content = $response->getContent();
            if ('' === $content || \strlen($content) > self::IMAGE_MAX_BYTES) {
                return null;
            }
            // A server that does not say what it sends (application/octet-stream
            // for a file without an extension): the picture says it itself.
            if (!str_starts_with($type, 'image/')) {
                $type = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content));
            }
            if (!str_starts_with($type, 'image/')) {
                return null;
            }
            $extension = match (true) {
                str_contains($type, 'png') => 'png',
                str_contains($type, 'webp') => 'webp',
                str_contains($type, 'gif') => 'gif',
                default => 'jpg',
            };
            $tmp = tempnam(sys_get_temp_dir(), 'agenda');
            if (false === $tmp || !rename($tmp, $tmp .= '.'.$extension) || false === file_put_contents($tmp, $content)) {
                return null;
            }

            return new File($tmp);
        } catch (\Throwable) {
            return null; // No picture: the date is read all the same.
        }
    }

    /**
     * What a source holds: an address (http, https, webcal) read - a
     * Squarespace page's address gives its HTML, its JSON is asked then -,
     * a file, or the content itself.
     */
    public function read(string $source): string
    {
        $source = trim($source);
        if (str_contains($source, 'BEGIN:VCALENDAR') || str_starts_with($source, '{')) {
            return $source;
        }
        if (preg_match('#^(webcals?|https?)://#i', $source)) {
            $url = self::https($source);
            $content = $this->fetch($url, 'text/calendar, application/json;q=0.9, */*;q=0.5');
            if (preg_match('/^\s*(?:<!doctype|<html)/i', $content) && !preg_match('/[?&]format=json\b/', $url)) {
                // An HTML page: a Squarespace one gives its collection as JSON when asked.
                $content = $this->fetch(SquarespaceParser::jsonUrl($url), 'application/json');
            }

            return $content;
        }
        if (is_file($source) && is_readable($source)) {
            return (string) file_get_contents($source);
        }

        throw new \InvalidArgumentException('neither an address, a readable file nor a calendar');
    }

    /** A source as an error message may show it: no private key of a Google address. */
    public static function display(string $source): string
    {
        $source = trim($source);
        if (str_contains($source, 'BEGIN:VCALENDAR')) {
            return 'ICS content';
        }

        return preg_replace('#/private-[^/\s]+/#', '/private-…/', $source);
    }

    private function fetch(string $url, string $accept): string
    {
        return $this->http->request('GET', $url, [
            'headers' => ['Accept' => $accept],
            'timeout' => 20,
        ])->getContent();
    }

    /** webcal:// is https:// to a client. */
    private static function https(string $address): string
    {
        return preg_replace('#^webcal(s?)://#i', 'https://', trim($address));
    }

    /** The settings' default language: a value written in the back office's is read in every other. */
    private static function locale(): ?string
    {
        try {
            return class_exists(\Base\Service\Localizer::class) ? \Base\Service\Localizer::getDefaultLocale() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

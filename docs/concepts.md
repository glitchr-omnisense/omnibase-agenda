---
title: Dates and venues
order: 20
---

# Dates and venues

## `Event`

`Base\Agenda\Entity\Event` extends the core's `Thread` (`agenda_event`, discriminator
`agenda_event`, indexed on `startsAt`):

| Property | Column | Meaning |
|---|---|---|
| `startsAt` | `datetime_immutable`, required | stored as given; read on the hall's clock with `getStartsAtLocal()` |
| `endsAt` | nullable | for whole days, the last day |
| `timezone` | `string(64)` | the hall's (`agenda.timezone` for a new date) |
| `allDay` | `boolean` | |
| `venue` | many-to-one `Venue`, `SET NULL` | |
| `ensemble`, `conductor` | `string(180)` | |
| `role` | `string(24)` | a `Role` value: `soloist`, `leader` (concertmaster), `chamber`, `orchestra`, `recital`, `masterclass`, `other` (default); `Role::of()` reads anything, `other` when unknown |
| `programme` | `text` | one work per line: "Glière — Harp Concerto op. 74" |
| `performers` | `text` | one per line: "Anna Meyer, harp" |
| `ticketsUrl`, `eventUrl` | `string(500)`, URLs | where the tickets are; the organiser's page |
| `cancelled` | `boolean` | struck through, never removed |
| `cover` | upload, image, 8 MB | |
| `sourceUid` (unique), `source`, `sourceUpdatedAt` | | the calendar entry it was read from |
| `syncLocked` | `boolean` | "Keep the site's texts": a sync brings only its hours and its cancellation |

Methods:

- `getStartsAtLocal()`, `getEndsAtLocal()`, `getDateTimeZone()` (UTC when the timezone is not
  one);
- `isMultiDay()`; `isUpcoming(?$now)` - its (last) day not over, on the hall's clock; `isPast()`;
  `isToday(?$now)` - not cancelled, today in its own timezone or within its run; `isTonight()` -
  today and starting at 17:00 or later, not all day; `isOnStage()` - started and not over (two
  hours without an end);
- `getProgrammeLines()`, `getPerformerLines()`, `getProgrammeWorks()` (`{composer, work}` split on
  a dash between spaces or a colon), `getPerformerNames()` (`{name, part}`);
- `getPictureUrl()` - its cover, else its venue's photo; `getPictureCredit()` - the venue's photo
  credit when the picture is the venue's;
- `getDescriptionText()` - what a calendar entry says under the title, in the words the sync
  reads back (`Orchestra:`, `Conductor:`, `Role:`, `With:`, `Tickets:`, `Programme:`);
- `toCalendarEntry(string $uid, ?string $url)` - the core's `CalendarEntry` (`null` without a
  start), two hours (`Event::DEFAULT_DURATION`, `PT2H`) without an end;
- `duplicate(string $titleSuffix)` - a copy to start the next date of a tour from: same times,
  venue, programme, people and links, its owners, no calendar behind it;
- `isImported()` - read from a calendar.

## `Venue`

`agenda_venue`: `name` (not blank), `hall`, `address`, `postcode`, `city`, `country` (ISO),
`mapUrl`, `website`, `latitude`, `longitude`, `photo` (8 MB), `photoCredit`, `aliases` (the
spellings merged into it), `slug` (unique, given at the first save from the name and town, `-2`
when taken).

`getLabel()` ("Elbphilharmonie, Hamburg"), `getFullAddress()` (name, hall, address, postcode and
town, country), `getMapLink()` (the map typed, else an OpenStreetMap point from the coordinates,
else a search of the address), `isKnownAs(string $name, ?string $city)` (its own name and town or
an alias, case and accents aside), `Venue::key()`, `getPhotoUrl()`.

## Repositories

| Repository | Methods |
|---|---|
| `EventRepository` | `findUpcoming(?int $limit)` (published dates whose day is not over, nearest first), `countUpcoming()`, `findToday(?$now)`, `findPast(?int $limit, int $page)`, `countPast()`, `findOnePublished(string $slug)`, `findBetween($from, $to)`, `findSince($from)`, `findForFeed(bool $past, ?$from, ?$to, int $limit, int $page)` (one more than the limit, to know there is a next page), `findAllPublished()`, `findByMonth()`, `findBySourceUid()`, `findUpcomingOfSource()` |
| `VenueRepository` | `findOneByNameAndCity()`, `findOneByFullAddress()`, `findOneByAlias()`, `merge(Venue $from, Venue $into): int`, `findAllByName()` |

`merge()` moves every date of `$from` (drafts and trashed ones included) to `$into`, fills what
`$into` lacks from `$from` (hall, address, postcode, country, map, site, coordinates, the photo
copied with its credit), keeps `$from`'s spellings as aliases of `$into`, deletes `$from`, and
returns the dates moved; a house is never merged into itself.

`Service\Months::group($events)` groups dates by month for the pages (`agenda_months()`).

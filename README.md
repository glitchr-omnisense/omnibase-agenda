# Agenda

Concert dates for [omnibase](https://github.com/glitchr-studio/omnibase): the
dates of a site - a harpist's, a violinist's, a school's open days, a shop's
late openings - where they happen, with whom, what is played, where the
tickets are; the calendar visitors subscribe to, the "add to my calendar"
links, the same dates as JSON for other sites and apps, and the dates read
from where they are kept - a Google Calendar the artist fills in from her
phone, an agency's feed, a former Squarespace site, another omnibase site.

An `Event` **is** an omnibase `Thread` (a JOINED subclass), so it inherits what
every thread has - a title (the concert's name: *Perspectives concertantes -
NDR Elbphilharmonie*), a headline, an excerpt, the text (EditorJS or HTML),
tags, owners, a slug, publish states and scheduling, revisions, soft delete,
translations - and the bundle adds the moment (`startsAt`, `endsAt`, the hall's
`timezone`, `allDay`), the `venue`, the `ensemble` and the `conductor`, the
artist's `role` (soloist, chamber music, orchestra, recital, masterclass,
other), the `programme` and the `performers` (one per line:
`Glière — Harp Concerto op. 74`), the tickets' and the organiser's links, a
cover, and `cancelled` - a cancelled date stays on the page, struck through:
who had a ticket must find it.

A `Venue` is not a thread: a name, a hall, an address, the country, a map
link (else the address is searched on OpenStreetMap), the house's site,
coordinates, a photo and who took it. Typed once, chosen on every date: a
date shows its own cover, else its house's photo with its credit
(`Event::getPictureUrl()`, `getPictureCredit()`). Two houses that are one
(a typo in a calendar: "Pärmu" for "Pärnu") are merged in the back office
(*Merge into…*, `VenueRepository::merge()`): the dates move, what the house
kept lacked is taken from the other (its photo copied), and the spelling
that went is kept in `aliases`, so a calendar that still writes it finds
the house that stays.

## Install

```bash
composer require omnibase/agenda:dev-main
```

```php
// config/bundles.php
Base\Agenda\AgendaBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
agenda_controller:
    resource: "@AgendaBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

```yaml
# config/routes.yaml - the back office's Google Calendar page (omnibase/admin)
agenda_admin_controller:
    resource: "@AgendaBundle/src/Controller/Admin/CalendarController.php"
    type: attribute
    prefix: /
```

```php
// the dashboard's Agenda section
MenuItem::linkToRoute('agenda_admin_calendar', [], 'Google Calendar', 'fa-brands fa-google'),
```

```yaml
# config/packages/agenda.yaml (every key optional)
agenda:
    sources: []                 # addresses agenda:sync reads (ICS, Squarespace, /agenda.json)
    ics: ~                      # more, comma-separated: the place for '%env(AGENDA_ICS)%'
    past_per_page: 24
    timezone: Europe/Berlin     # a new date's, and an imported one's that names none
    calendar_name: ~            # the feed's name; ~: the site's title (base.settings.title)
    jsonld: true                # a schema.org event on each date's page
    jsonld_type: MusicEvent     # its type: MusicEvent (a masterclass: EducationEvent), EducationEvent, Event
    show_past: true             # the past dates, folded under the ones to come
```

Then `bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate`
and `bin/console assets:install` (the stylesheet lives in `public/css/agenda.css`).

## Routes

| Route | Path | |
|---|---|---|
| `agenda_index` | `/agenda` | the dates to come by month, the past ones folded (`?past=1&page=2` opens them) |
| `agenda_event` | `/agenda/{slug}` | one date, its programme, its JSON-LD |
| `agenda_feed` | `/agenda.ics` | every published date from a year back: the calendar to subscribe to |
| `agenda_event_ics` | `/agenda/{slug}.ics` | one date, to add |
| `agenda_json` | `/agenda.json` | the dates as JSON, for other sites and apps |
| `agenda_event_json` | `/agenda/{slug}.json` | one date as JSON (404 when not published) |
| `agenda_event_cover` | `/agenda/{slug}/cover` | a date's cover, with its type |
| `agenda_venue_photo` | `/agenda/venue/{slug}/photo` | a house's photo, with its type |
| `agenda_admin_calendar` | `/admin/agenda/calendar` | the back office's Google Calendar page (ROLE_ADMIN); `?preview=1` reads the calendars and shows what they would change |

`/agenda` and every date's page are in `/sitemap.xml` (the dates through
`EventListener\SitemapListener`); the calendars and the feeds are not.

## The JSON feed

`GET /agenda.json` - anyone may read it, from anywhere: `Content-Type:
application/json`, `Access-Control-Allow-Origin: *`, `Cache-Control: public,
max-age=300`, an `ETag` (a reader sending it back in `If-None-Match` gets a
304). `/agenda`'s `<head>` announces it (`<link rel="alternate"
type="application/json">`, in the `stylesheets` block, the one the host's
`<head>` holds).

| Query | |
|---|---|
| (none) | the dates to come, the nearest first |
| `?past=1` | the dates gone by, the latest first |
| `?from=2026-11-01&to=2026-12-31` | a span of days, both included (a malformed day: 400) |
| `?limit=` | 100 by default, 500 at most |
| `?page=` | the page; `next` is the next one's address, `null` on the last |

```json
{
  "calendar": {"name": "Anaëlle Tourret", "url": "https://…/agenda", "timezone": "Europe/Berlin", "ics": "https://…/agenda.ics", "generatedAt": "2026-10-03T10:57:17+02:00"},
  "events": [{
    "id": "squarespace:6aa6df513f879725ec9b82a2",
    "slug": "…", "url": "https://…/agenda/…", "ics": "https://…/agenda/….ics",
    "title": "M. Fröst, A. Poga | NDR Elbphilharmonie Orchestra",
    "start": "2026-10-03T20:00:00+02:00", "end": "2026-10-03T22:00:00+02:00",
    "timezone": "Europe/Berlin", "allDay": false, "cancelled": false,
    "role": "orchestra", "roleLabel": "Orchestra",
    "ensemble": "NDR Elbphilharmonie Orchestra", "conductor": "Andris Poga",
    "performers": ["Martin Fröst, clarinet"],
    "programme": ["Wolfgang Amadeus Mozart — Konzert für Klarinette und Orchester A-Dur KV 622"],
    "tickets": null, "eventUrl": null, "image": "https://…/agenda/venue/…/photo", "imageCredit": "Peter Kaminski",
    "venue": {"name": "…", "hall": null, "address": "10 Fährstraße", "postcode": "17449", "city": "Peenemünde", "country": "DE", "latitude": 54.13, "longitude": 13.76, "map": "https://www.openstreetmap.org/…", "photo": "https://…/agenda/venue/…/photo", "photoCredit": "Peter Kaminski"},
    "updatedAt": "2026-10-03T08:11:35+00:00"
  }],
  "next": null
}
```

`id` is the calendar entry's UID when the date was read from one, else
`<id>@<host>` - the same as in the ICS files. `start` and `end` carry the
hall's offset (a date of whole days: midnight, `allDay` true, `end` its last
day). `roleLabel` is in the request's language. `image` is the picture the
date shows - its cover, else its house's photo (then `imageCredit` names the
photographer); `venue.photo` is the house's. The serialisation is
`Service\Feed` (plain arrays, unit-tested); `Service\FeedParser` reads it
back, so one omnibase site can take another's dates (see below).

## Calendars

The page offers `webcal://…/agenda.ics`: a phone, Apple Calendar or Outlook
subscribes to it and follows the changes; Google Calendar takes the same
address in *Other calendars → From URL*. Each date has two links more: Google
Calendar's own template page and its `.ics`, both written by omniscient/omnibase's
`Base\Service\Calendar` from `Event::toCalendarEntry()` (`docs/calendar.md`).

The files are written by hand (omniscient/omnibase's `Base\Service\Calendar\Ics`, RFC 5545): CRLF, lines folded at
75 octets, texts escaped, the hour in the hall's timezone with its
`VTIMEZONE`, whole days as dates, `STATUS:CANCELLED`, and the UID of the entry
a date was imported from, so a calendar that holds both never shows it twice.

## Where the dates come from

The artist (or her agency) keeps the dates where she already keeps them,
and never needs to log into the site. `agenda:sync` reads every source and
applies what changed - run it from cron (every half hour); the back office
reads the calendars on demand and shows what they would change before
anything is applied (*Read the calendar now*, then *Apply these changes*):

```bash
bin/console agenda:sync                        # every source
bin/console agenda:sync --dry-run              # what would change, each field as it was and as it becomes; nothing applied
bin/console agenda:sync --source=<address|file> # one instead (not remembered as the last sync)
```

The sources are `agenda.sources`, `agenda.ics` (comma-separated, an
environment variable) and the address pasted in the back office (the
`agenda.ics` setting). What a source holds is told by its content: an
iCalendar file, a Squarespace events collection, an agenda feed.

### Google Calendar (the way to go)

1. In Google Calendar, a calendar of its own for the concerts ("Concerts"),
   shared with the agency if it keeps the dates too.
2. When the site has dates already (a former site's, read once): the site's
   own calendar (`/agenda.ics`, linked on the page) imported into it,
   *Settings → Import & export → Import*. Each date carries its UID, which
   the sync matches on, and its fields written in the words the sync reads
   (`Event::getDescriptionText()`): read back, the site's dates change
   nothing (`tests/Service/IcsTest::testEveryPieceComesBackWhereItWas`; a
   host's own test can preview its whole `/agenda.ics`).
3. Its *Settings and sharing → Integrate calendar → Secret address in iCal
   format* (`https://calendar.google.com/calendar/ical/…/private-…/basic.ics`).
   It is read-only and needs no OAuth - but whoever holds it reads the whole
   calendar: keep it out of git.
4. Paste it in the back office, *Agenda → Google Calendar*
   (`/admin/agenda/calendar`): it is saved in the `agenda.ics` setting and
   shown half hidden. The page has the last sync (the `agenda.sync.last`
   setting: when, created, updated, cancelled, gone, errors), *Read the
   calendar now* (the preview, then *Apply*), the dates gone from their
   calendar waiting for a word (`agenda.sync.missing`), the syncs that
   changed something with their changes (`agenda.sync.history`, the last
   ten), and this how-to with an example in the reader's language. (Or
   `secrets:set AGENDA_ICS` and `agenda: { ics: '%env(AGENDA_ICS)%' }`.)
5. A concert is an event of that calendar: the title is the concert's name,
   the location the hall and its address, and the description one piece of
   information per line:

```
Orchestre : NDR Elbphilharmonie Orchester
Chef : Vasily Petrenko
Rôle : soliste
Billets : https://www.elbphilharmonie.de/...
Programme :
Glière — Concerto pour harpe op. 74
Debussy — Danses sacrée et profane
```

`Service\DescriptionParser` reads the words whatever their case and
accents, in English, French, German and Italian: *Orchestra / Ensemble /
Orchestre / Orchester* (the ensemble), *Conductor / Chef / Direction /
Dirigent / Leitung / Direttore*, *Role / Rôle / Rolle / Ruolo* (soloist,
concertmaster, chamber, orchestra, recital, masterclass in any of the four
languages), *With / Avec / Mit / Con* (the performers: on the line,
comma-separated - "Anna Meyer, harpe" stays one -, or one per line under
it), *Tickets / Billets / Karten / Biglietti* (the first address), *Programme
/ Program / Programm / Programma* (the lines under it, until a blank line or
another word). A line no word opens is the programme once a word was read;
a description with no word at all is the programme, as it always was.
Google's HTML (bold, line breaks, its wrapped links) is read as text.

### The calendar is the source of truth

A date is matched by its entry's UID. At each sync it takes from its
calendar the title, the hours, the place (a `Venue` found by its full
address as the site writes it, else by name and town or a name merged
into it, else opened: from `LOCATION`, the name before the first comma, the
town in the last part), the `URL` as the organiser's page (not the date's
own page on the site, which the site's .ics gives), `STATUS:CANCELLED`, and
what the description says - a field the description does not mention
keeps what the site has; a word with nothing after it ("Chef :") clears it.
A house's picture the source gives (Squarespace's, another site's
`venue.photo`) becomes the photo of a house that has none - once, not on
every date; a date's own picture (another site's cover) the cover of a date
that has none.

Each change is a `Model\Change`: the date opened, brought up to date
(each field as it was and as it becomes, the hours on the hall's clock),
cancelled by its calendar, or gone from it. `IcsImporter::import(apply:
false)` and `sync(apply: false)` only tell them: nothing written, no
picture fetched.

A date whose texts were rewritten on the site is protected by **Keep the
site's texts** (`Event::$syncLocked`, in the date's form, shown for an
imported date only): the sync then brings its hours and its cancellation,
nothing else.

A new date goes online at once. One that leaves its calendar - an event
deleted in Google Calendar - is neither cancelled nor deleted behind
anyone's back (a slip of the finger would strike a concert through): it
waits on the calendar page until someone says *Mark cancelled* (struck
through, who had a ticket finds it) or *Keep on the site* (the site's own
from then on, no calendar asks about it again; `resolveMissing()`). Only the
dates to come are checked, per source. A repeating entry gives its first
occurrence.

### A Squarespace site, during a move

The JSON of a Squarespace events page (`https://…/performances?format=json`
- the address works with or without `?format=json`) is read by
`Service\SquarespaceParser`: uid `squarespace:<id>`, the bold first line of
the body as the ensemble, "**Name** Conductor" / "**Name** Piano" as the
conductor and the performers, "**Composer** Work" as the programme, the
`sourceUrl` as the organiser's page, the `assetUrl` as the hall's photo
(the cover of a date that names no hall), an end typed a day late (20:30
the next evening) as the same evening's, the past
dates followed six pages back. Squarespace keeps every hour on the site's
clock (Berlin's) whatever the hall: the hour typed is set back in the
hall's timezone, the country's (`addressCountry`, else the end of the
address - *Allemagne*, *Japon*, *Corée du Sud*, *Taïwan*…).

### Another omnibase site

Another site's `/agenda.json` is a source too (`Service\FeedParser`, its
`next` pages followed): its dates keep their `id`, so a date read from two
places is one.
## What the host provides

The templates extend `layout1.html.twig` and fill `title`, `description`,
`content` and `stylesheets`: that is the whole contract. They use
`twig/intl-extra`'s date filters and omnibase's `|wysiwyg`. The look follows
the host's custom properties when it defines them - `--agenda-accent`,
`--agenda-on-accent`, `--agenda-ink`, `--agenda-soft`, `--agenda-line`,
`--agenda-surface`, `--agenda-font-display`, `--agenda-measure` - and is
neutral otherwise.

Twig functions for the host's own pages:

- `agenda_upcoming(n = 3)`: the next dates, for a home page;
- `agenda_next()`: the very next one, for a banner;
- `agenda_google_url(event)`, `agenda_ics_url(event)`: the two calendar links
  (`{% include '@Agenda/client/_calendar_links.html.twig' %}` renders both);
- `agenda_webcal_url()`: the subscription address;
- `agenda_months(events)`: dates grouped by month;
- `event.role|agenda_role_label`: the role's name.

`{% include '@Agenda/client/_event.html.twig' %}` renders a date as the list
does.

The back office gets `Event` and `Venue` CRUDs (duplicate a date as a draft,
for the next one of a tour; read the calendars now), the Google Calendar
page (`Controller\Admin\CalendarController`) and a dashboard widget,
`agenda_upcoming`, with the next five dates. When omnibase/newsletter is
installed, `Digest\AgendaDigestSource` puts the coming dates in its digest.

The two CRUDs are written by the site's administrator (`ROLE_ADMIN`), not by
the super-admin only: they carry omnibase/admin's `#[OpenToAdmins]` -
creating, editing, deleting, and the dates' own `duplicate` and `sync`. The
attribute is omnibase/admin's from 7474f85.

## Tests

`vendor/bin/phpunit`: unit tests of the ICS writer and reader, the Google
link, the JSON-LD, the JSON feed (written and read back), the description
reader (fr/en/de), the Squarespace reader (on a real excerpt,
`tests/Fixtures/squarespace.json`) and the Event's own rules. The bundle does not boot alone,
so none of them needs a kernel - but `tests/Controller/Admin/OpenToAdminsTest`
(an administrator writes in the back office, a plain user does not), which
runs inside a host application
(`php vendor/bin/phpunit -c vendor/omnibase/agenda/phpunit.xml.dist`) and is
skipped elsewhere.

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

---
title: Where the dates come from
order: 32
---

# Where the dates come from

The artist (or her agency) keeps the dates where she already keeps them. `agenda:sync` reads
every source and applies what changed; the back office reads them on demand and shows what they
would change before anything is applied.

```bash
bin/console agenda:sync                          # every source
bin/console agenda:sync --dry-run                # what would change, each field as it was and as it becomes; nothing applied
bin/console agenda:sync --source=<address|file>  # one instead (repeatable; not remembered as the last sync)
bin/console agenda:sync -v                       # the changes too
```

With no source it says where to set one and exits 0; it fails (exit 1) only when every source
failed. The summary lists `created`, `updated`, `cancelled`, `missing` and `unchanged`.

## The sources

`Base\Agenda\Service\IcsImporter::getSources()`: `agenda.sources`, `agenda.ics` (comma-separated:
an environment variable) and the address pasted in the back office (the setting `agenda.ics`).
What a source holds is told by its content: an iCalendar file (`IcsParser`), a Squarespace
events collection (`SquarespaceParser`, six pages back), an agenda feed (`FeedParser`, its `next`
pages followed, twenty at most). An address is `http(s)://` or `webcal(s)://`; anything else is a
file.

## Google Calendar

1. In Google Calendar, a calendar of its own for the concerts, shared with the agency if it
   keeps the dates too.
2. When the site has dates already: the site's own `/agenda.ics` imported into it (*Settings →
   Import & export → Import*). Each date carries its UID, which the sync matches on, and its
   fields in the words the sync reads (`Event::getDescriptionText()`): read back, the site's
   dates change nothing.
3. Its *Settings and sharing → Integrate calendar → Secret address in iCal format*. It needs no
   OAuth, but whoever holds it reads the whole calendar: keep it out of git.
4. Paste it on the back office's calendar page (`/admin/agenda/calendar`): kept in the
   `agenda.ics` setting, shown half hidden (or `secrets:set AGENDA_ICS` and
   `agenda: { ics: '%env(AGENDA_ICS)%' }`).
5. A concert is an event of that calendar: the title is the concert's name, the location the
   hall and its address, the description one piece of information per line.

```
Orchestre : NDR Elbphilharmonie Orchester
Chef : Vasily Petrenko
Rôle : soliste
Billets : https://www.elbphilharmonie.de/...
Programme :
Glière — Concerto pour harpe op. 74
Debussy — Danses sacrée et profane
```

### The words of a description

`Base\Agenda\Service\DescriptionParser::parse(?string $description)` reads them whatever their
case and accents, in English, French, German and Italian:

| Field | Words |
|---|---|
| ensemble | Orchestra, Ensemble, Orchestre, Orchester |
| conductor | Conductor, Chef, Direction, Dirigent, Leitung, Direttore |
| role | Role, Rôle, Rolle, Ruolo - soloist, concertmaster, chamber, orchestra, recital, masterclass in any of the four languages |
| performers | With, Avec, Mit, Con - on the line, comma-separated ("Anna Meyer, harpe" stays one), or one per line under it |
| tickets | Tickets, Billets, Karten, Biglietti - the first address |
| programme | Programme, Program, Programm, Programma - the lines under it, until a blank line or another word |

A line no word opens is the programme once a word was read; a description with no word at all is
the programme. Google's HTML (bold, line breaks, wrapped links) is read as text. Run in
omnisense/omnibase's harness image:

```php
use Base\Agenda\Service\DescriptionParser;

print_r(DescriptionParser::parse("Orchestre : NDR Elbphilharmonie Orchester\nChef : Vasily Petrenko\nRôle : soliste\nBillets : https://www.elbphilharmonie.de/de/programm/123\nProgramme :\nGlière — Concerto pour harpe op. 74\nDebussy — Danses sacrée et profane"));
```

```
Array
(
    [ensemble] => NDR Elbphilharmonie Orchester
    [conductor] => Vasily Petrenko
    [role] => soloist
    [performers] => Array
        (
        )

    [tickets] => https://www.elbphilharmonie.de/de/programm/123
    [programme] => Array
        (
            [0] => Glière — Concerto pour harpe op. 74
            [1] => Debussy — Danses sacrée et profane
        )

    [rest] => 
)
```

## The calendar is the source of truth

A date is matched by its entry's UID. At each sync it takes from its calendar the title, the
hours, the place - a `Venue` found by its full address as the site writes it, else by name and
town or an alias merged into it, else opened from `LOCATION` (the name before the first comma,
the town in the last part) -, the `URL` as the organiser's page, `STATUS:CANCELLED`, and what the
description says: a field the description does not mention keeps what the site has; a word with
nothing after it ("Chef :") clears it. A house's picture the source gives becomes the photo of a
house that has none (once); a date's own picture the cover of a date that has none. A repeating
entry gives its first occurrence. A new date goes online at once.

A date whose texts were rewritten on the site is protected by **Keep the site's texts**
(`syncLocked`): the sync brings its hours and its cancellation, nothing else.

Each change is a `Base\Agenda\Model\Change` - `created`, `updated` (each field as it was and as it
becomes, the hours on the hall's clock), `cancelled` by its calendar, or `missing` from it.
`IcsImporter::import(..., apply: false)` and `sync(apply: false)` only tell them: nothing written,
no picture fetched. `Model\ImportSummary` adds them up, with the sources that could not be read
(`errors`).

## The dates gone from their calendar

A date that leaves its calendar is neither cancelled nor deleted behind anyone's back: it waits
on the calendar page (the setting `agenda.sync.missing`) until someone says *Mark cancelled* or
*Keep on the site* (the site's own from then on: no calendar asks about it again;
`IcsImporter::resolveMissing(array $ids, bool $cancel)`). Only the dates to come are checked, per
source.

## What a sync leaves

`IcsImporter::remember(ImportSummary)` keeps the last sync (`agenda.sync.last`: when, the counts,
the errors) and, when it changed something, appends it to `agenda.sync.history` (the last ten,
a hundred changes each), for the calendar page. A calendar that could not be read leaves the dates
already waiting where they are. A `--source` or `--dry-run` run is not kept.

## A Squarespace site, during a move

The JSON of a Squarespace events page (`https://…/performances?format=json`, with or without
`?format=json`) is read by `Service\SquarespaceParser`: uid `squarespace:<id>`, the bold first line
of the body as the ensemble, "**Name** Conductor" / "**Name** Piano" as the conductor and the
performers, "**Composer** Work" as the programme, the `sourceUrl` as the organiser's page, the
`assetUrl` as the hall's photo (the cover of a date that names no hall), an end typed a day late as
the same evening's, the past dates followed six pages back. Squarespace keeps every hour on the
site's clock (Berlin's): the hour typed is set back in the hall's timezone, the country's
(`addressCountry`, else the end of the address).

## Another omnibase site

Another site's `/agenda.json` is a source too: its dates keep their `id`, so a date read from two
places is one.

## Reading an ICS file

`Base\Agenda\Service\IcsParser::parse(string $ics)` gives `Model\ParsedEvent`s (UID, summary,
start, end - for whole days the last day -, timezone - the entry's `TZID`, else `agenda.timezone`
-, description, location, URL, last modified, cancelled, sequence, recurring); the description is
read by `DescriptionParser` when the importer applies it:

```php
use Base\Agenda\Service\IcsParser;

$ics = implode("\r\n", [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT',
    'UID:abc123@google.com',
    'DTSTART;TZID=Europe/Berlin:20261115T200000',
    'DTEND;TZID=Europe/Berlin:20261115T220000',
    'SUMMARY:Perspectives concertantes',
    'LOCATION:Elbphilharmonie\, Platz der Deutschen Einheit 4\, Hamburg',
    'END:VEVENT', 'END:VCALENDAR', '',
]);
foreach ((new IcsParser('Europe/Berlin'))->parse($ics) as $event) {
    echo $event->uid, ' | ', $event->summary, ' | ', $event->start->format(DATE_ATOM), ' | ', $event->timezone, "\n";
    echo 'location: ', $event->location, "\n";
}
```

```
abc123@google.com | Perspectives concertantes | 2026-11-15T20:00:00+01:00 | Europe/Berlin
location: Elbphilharmonie, Platz der Deutschen Einheit 4, Hamburg
```

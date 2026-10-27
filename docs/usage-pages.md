---
title: The pages and the JSON feed
order: 30
---

# The pages and the JSON feed

| Route | Path | Methods | |
|---|---|---|---|
| `agenda_index` | `/agenda` | any | the dates to come by month, the past ones folded; sitemap 0.8, weekly |
| `agenda_event` | `/agenda/{slug}` | any | one date, its programme, its JSON-LD; sitemap 0.6, weekly |
| `agenda_feed` | `/agenda.ics` | any | [ICS feeds](calendar.md) |
| `agenda_event_ics` | `/agenda/{slug}.ics` | any | |
| `agenda_json` | `/agenda.json` | GET, HEAD | the dates as JSON |
| `agenda_event_json` | `/agenda/{slug}.json` | GET, HEAD | one date as JSON |
| `agenda_event_cover` | `/agenda/{slug}/cover` | GET, HEAD | a date's cover with its type |
| `agenda_venue_photo` | `/agenda/venue/{slug}/photo` | GET, HEAD | a house's photo with its type |

A slug that names no published date is a 404. Every date's page is in `/sitemap.xml`
(`EventListener\SitemapListener`, on the core's `SitemapEvent::BUILD`); the calendars and the
feeds are not.

## `/agenda`

`upcoming` and `months` (the dates to come, by month); with `agenda.show_past`, the past dates
(`past`, `past_months`) folded under them, `agenda.past_per_page` a page - `?past=1&page=2`
opens them.

## `/agenda.json`

Anyone may read it, from anywhere: `Content-Type: application/json`,
`Access-Control-Allow-Origin: *`, `Cache-Control: public, max-age=300`, an `ETag` of its content
(the moment it was written aside) - a reader sending it back in `If-None-Match` gets a 304.

| Query | |
|---|---|
| (none) | the dates to come, the nearest first |
| `?past=1` | the dates gone by, the latest first |
| `?from=2026-11-01&to=2026-12-31` | a span of days, both included; a malformed day is a 400 with `{"error": ...}` |
| `?limit=` | 100 by default, 1 to 500 |
| `?page=` | the page; `next` is the next one's address, `null` on the last |

The document (`Service\Feed::document()`) is `{calendar, events, next}`: `calendar` holds the
`name`, `url`, `timezone`, `ics` and `generatedAt`; each event (`Feed::event()`) `id`, `slug`,
`url`, `ics`, `title`, `start`, `end`, `timezone`, `allDay`, `cancelled`, `role`, `roleLabel` (in
the request's language), `ensemble`, `conductor`, `performers` and `programme` (lists),
`tickets`, `eventUrl`, `image` and `imageCredit` (the picture the date shows: its cover, else its
house's photo and the photographer), `venue` (`name`, `hall`, `address`, `postcode`, `city`,
`country`, `latitude`, `longitude`, `map`, `photo`), `updatedAt`.

`id` is the calendar entry's UID when the date was read from one, else `<id>@<host>` - the same
as in the ICS files. `start` and `end` carry the hall's offset; a date of whole days starts at
midnight, `allDay` true, `end` its last day. `Service\FeedParser` reads the document back: one
omnibase site can take another's dates ([Where the dates come from](usage-sync.md#another-omnibase-site)).

`/agenda/{slug}.json` is `Feed::event()` alone, with the same headers.

## The pictures

`/agenda/{slug}/cover` and `/agenda/venue/{slug}/photo` serve the upload with its own type, CORS
open, cached a day (public), 304 when not modified; a 404 without an image. They are the
addresses the JSON feed and the JSON-LD give; the pages' own `<img>` keep the files' addresses.

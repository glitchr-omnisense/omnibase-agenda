---
title: ICS feeds and Google Calendar links
order: 31
---

# ICS feeds and Google Calendar links

The iCalendar writing (RFC 5545) and the Google Calendar link are
omnisense/omnibase's `Base\Service\Calendar\{Ics, GoogleCalendarLink}`, on a
`CalendarEntry`. The agenda maps its dates there:

- `Event::toCalendarEntry(string $uid, ?string $url)`: the date as a calendar
  sees it (the hall's timezone, whole days, the venue's address and GPS point,
  cancelled or not, `Event::DEFAULT_DURATION` without an end).
- `Base\Agenda\Service\Ics`: `calendar($events, $name)` and `event($event)` (the
  `/agenda.ics` and `/agenda/{slug}.ics` feeds), `google($event)` (the Twig
  function `agenda_google_url()`), `uid($event)` (the source's UID, else
  `<id>@<host>`), `entry($event)`. `Ics::escape()` and `Ics::fold()` are the
  core's.

Nothing to configure.

## What the files hold

The files are written by the core's `Base\Service\Calendar\Ics` (RFC 5545): CRLF, lines folded
at 75 octets, texts escaped, the hour in the hall's timezone with its `VTIMEZONE`, whole days as
dates, `STATUS:CANCELLED`, and the UID of the entry a date was imported from - so a calendar
that holds both never shows it twice. `PRODID` is `-//omnibase//agenda//EN`.

| Address | Holds | Headers |
|---|---|---|
| `/agenda.ics` (`agenda_feed`) | every published date from a year back, named `agenda.calendar_name` (else the site's title, else "Agenda") | `text/calendar; charset=utf-8`, inline `agenda.ics`, `Cache-Control: public, max-age=900` |
| `/agenda/{slug}.ics` (`agenda_event_ics`) | one date, named after its title | the same, `<slug>.ics` |

The page offers `webcal://…/agenda.ics` (`agenda_webcal_url()`): a phone, Apple Calendar or
Outlook subscribes to it and follows the changes; Google Calendar takes the same address in
*Other calendars → From URL*. Each date has two links more: Google Calendar's template page
(`agenda_google_url(event)`) and its `.ics` (`agenda_ics_url(event)`);
`@Agenda/client/_calendar_links.html.twig` renders both.

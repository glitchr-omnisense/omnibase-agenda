---
title: The back office
order: 38
---

# The back office

Loaded when omnibase/admin is installed. The two CRUDs carry omnibase/admin's
`#[OpenToAdmins]`: `ROLE_ADMIN` creates, edits and deletes, and uses their own actions.

## Dates

`EventCrudController` (slug `events`, newest first): title, state, slug (following the title),
start and end, timezone, all day, cancelled, venue, role, ensemble, conductor, programme,
performers, tickets, organiser's page, cover, headline, excerpt, text, the source UID (detail),
and - for an imported date only - "Keep the site's texts". Filters: state, venue, role, start,
cancelled. A new date is in `agenda.timezone`, owned by the signed-in user.

- **Duplicate** (`/admin/events/{entityId}/duplicate`) - a copy as a draft, " (copy)" in its
  language after the title, opened for editing: the next date of a tour.
- **Read the calendars now** (`/admin/events/sync`, when there are sources) - the calendar
  page's preview.

## Venues

`VenueCrudController` (`venues`, by name): name, hall, address, postcode, town, country, site, map,
latitude, longitude, photo and credit, aliases and slug (detail). Filters: town, country.
**Merge into…** (`/admin/venues/{entityId}/merge`) lists the other houses, the likely same first
(names a letter or two apart, the same town counting: 80 or more out of 100), with their dates;
posted, the dates move and this house goes (`VenueRepository::merge()`).

## The calendar page

`Base\Agenda\Controller\Admin\CalendarController`, `ROLE_ADMIN`, in the admin's layout:

| Route | Path | Method | |
|---|---|---|---|
| `agenda_admin_calendar` | `/admin/agenda/calendar` | GET | the address (half hidden), the configured calendars, the last sync, the dates gone waiting for a word, the syncs that changed something, the how-to; `?preview=1` reads the calendars now and shows what they would change |
| `agenda_admin_calendar_save` | the same | POST | the address saved (`webcal://`, `webcals://`, `http(s)://` only) or forgotten (`forget`); token `agenda-calendar` |
| `agenda_admin_calendar_sync` | `/admin/agenda/calendar/sync` | POST | every calendar read and applied, as the cron does; token `agenda-sync` |
| `agenda_admin_calendar_missing` | `/admin/agenda/calendar/missing` | POST | `ids[]` and `decision=cancel` or keep; token `agenda-missing` |

## The tile

`agenda_upcoming` (`UpcomingEventsWidgetType`): how many dates are to come and the next five, each
opening its record.

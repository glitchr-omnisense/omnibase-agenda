---
title: In a host page
order: 35
---

# In a host page

`Base\Agenda\Twig\AgendaExtension`:

| | Returns |
|---|---|
| `agenda_upcoming(limit = 3)` | the next dates, for a home page |
| `agenda_next()` | the very next one, for a banner |
| `agenda_today()` | today's dates, each in its own timezone, cancelled ones left out |
| `agenda_google_url(event)` | Google Calendar's template page for the date |
| `agenda_ics_url(event, absolute = false)` | the date's `.ics` |
| `agenda_webcal_url()` | `webcal://…/agenda.ics`, the subscription address |
| `agenda_months(events)` | dates grouped by month |
| `role\|agenda_role_label` | the role's name in the `agenda` domain |

Partials: `@Agenda/client/_event.html.twig` (a date as the list renders it),
`_calendar_links.html.twig` (the two calendar links), `_today.html.twig` (today's concert before
anything else: "Tonight", the hour, the hall, the part, the orchestra, the tickets; "On stage now"
while it plays) and `_today_chip.html.twig` (the same in one line, for a home page's first screen);
both render nothing on a day without a date. Every template is overridden in
`templates/bundles/AgendaBundle/...`.

The stylesheet `bundles/agenda/css/agenda.css` follows the host's custom properties when it
defines them - `--agenda-accent`, `--agenda-on-accent`, `--agenda-ink`, `--agenda-soft`,
`--agenda-line`, `--agenda-surface`, `--agenda-font-display`, `--agenda-measure` - and is neutral
otherwise.

Translations: the domains `agenda` and `entities`, ICU, in English, French and German.

## In the newsletter's digest

With omnibase/newsletter installed, `Base\Agenda\Digest\AgendaDigestSource` (tagged
`newsletter.digest_source`) gives its digest a part named `agenda`: each date between the two
days, its title, a line - "cancelled" when it is, the day and hour in the letter's language, the
venue, the ensemble and conductor -, its page and its start.

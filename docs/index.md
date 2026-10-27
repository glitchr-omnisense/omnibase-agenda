# omnibase/agenda

Dates for the sites built on [omnisense/omnibase](https://github.com/glitchr-omnisense/omnibase):
a harpist's concerts, a violinist's, a school's open days, a shop's late openings - where they
happen, with whom, what is played, where the tickets are; the calendar visitors subscribe to,
the "add to my calendar" links, the same dates as JSON for other sites and apps, and the dates
read from where they are kept: a Google Calendar the artist fills in from her phone, an agency's
feed, a former Squarespace site, another omnibase site. It is a Symfony bundle
(`Base\Agenda\AgendaBundle`) on the core 3 or 4, with its back office on omnibase/admin.

An `Event` **is** an omnibase `Thread` (a JOINED subclass): a title (the concert's name), a
headline, an excerpt, the text, tags, owners, a slug, publish states and scheduling, revisions,
soft delete, translations; the bundle adds the moment (`startsAt`, `endsAt`, the hall's
`timezone`, `allDay`), the `venue`, the `ensemble` and the `conductor`, the artist's `role`, the
`programme` and the `performers`, the tickets' and the organiser's links, a cover, and
`cancelled` - a cancelled date stays on the page, struck through: who had a ticket must find it.
A `Venue` is a plain row: typed once, chosen on every date.

The artist never needs to log into the site: `agenda:sync` reads her calendars, and the back
office shows what a sync would change before it is applied.

## How this manual is organised

- [Installation](installation.md): requirements, the bundle, the routes, the schema, the cron.
- [Dates and venues](concepts.md): the entities, their fields and methods, the roles, the
  queries.
- [The pages and the JSON feed](usage-pages.md): `/agenda`, a date's page, `/agenda.json`, the
  pictures, the sitemap.
- [ICS feeds and Google Calendar links](calendar.md): the calendars to subscribe to and to add.
- [Where the dates come from](usage-sync.md): the sources, Google Calendar, the words of a
  description, how a sync matches and changes, Squarespace, another site, the dates gone.
- [Structured data (JSON-LD)](structured-data.md): the schema.org event of each date.
- [In a host page](usage-twig.md): the Twig functions, the partials, the stylesheet, the digest.
- [The back office](back-office.md): the screens, the calendar page, merging venues, the tile.
- [Configuration reference](configuration.md): `agenda:`, the settings, the services.
- [Testing](testing.md).

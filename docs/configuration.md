---
title: Configuration reference
order: 40
---

# Configuration reference

## `agenda:`

`Base\Agenda\DependencyInjection\AgendaConfiguration`, dumped with Symfony's
`YamlReferenceDumper` in omnisense/omnibase's harness image:

```yaml
agenda:

    # ICS addresses agenda:sync reads (a Google Calendar's private address, an orchestra's feed).
    sources:              []

    # More ICS addresses, comma-separated, read when the command runs: the place for "%env(AGENDA_ICS)%", which a list cannot take.
    ics:                  null

    # Past dates listed per page.
    past_per_page:        24

    # The timezone of a new date, and of an imported one that names none.
    timezone:             Europe/Berlin

    # The name of the ICS feed; null: the site's title.
    calendar_name:        null

    # A schema.org event on each date's page.
    jsonld:               true

    # Its schema.org type: a musician's MusicEvent (a masterclass stays an EducationEvent), a lecturer's EducationEvent, or Event.
    jsonld_type:          MusicEvent # One of "MusicEvent"; "EducationEvent"; "Event"

    # The past dates under the ones to come.
    show_past:            true
```

| Option | Type | Default | Read by |
|---|---|---|---|
| `sources` | list of addresses | `[]` | `IcsImporter` |
| `ics` | comma-separated string or null | `null` | `IcsImporter` |
| `past_per_page` | int, 1 or more | `24` | `AgendaController` |
| `timezone` | timezone | `Europe/Berlin` | `IcsParser`, `SquarespaceParser`, `AgendaController`, `EventCrudController` |
| `calendar_name` | string or null | `null` | `AgendaController` (ICS and JSON); null: `base.settings.title`, else "Agenda" |
| `jsonld` | bool | `true` | `AgendaController` |
| `jsonld_type` | `MusicEvent`, `EducationEvent`, `Event` | `MusicEvent` | `JsonLd` |
| `show_past` | bool | `true` | `AgendaController` |

## Settings

Kept in the core's setting bag by `IcsImporter`: `agenda.ics` (the address pasted),
`agenda.sync.last`, `agenda.sync.history`, `agenda.sync.missing`.

## Services

`config/services.php` autowires and autoconfigures `src/` but `DependencyInjection/`, `Entity/`,
`Enum/`, `Model/`, `Controller/Admin/`, `Admin/`, `Digest/` and the bundle class;
`Controller/Client/` is tagged `controller.service_arguments`; with omnibase/admin,
`Controller/Admin/` and `Admin/`; with omnibase/newsletter's `DigestSourceInterface`, `Digest/`.
`SyncCommand` is `#[AsCommand]`, `SitemapListener` `#[AsEventListener]`.

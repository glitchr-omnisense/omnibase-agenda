---
title: Installation
order: 10
---

# Installation

## Requirements

What `composer.json` requires: PHP 8.2 or later, `doctrine/orm` 2.15 or 3,
`omnisense/omnibase` 3.0 or 4.0, `omnibridge/symfony` 1.x, `omnibase/admin`,
`twig/intl-extra` 3 (the templates' date filters), and `symfony/config`, `console`,
`dependency-injection`, `form`, `http-client` (reading the calendars), `http-kernel`,
`security-bundle` and `string` 6, 7 or 8. It suggests omnibase/newsletter: the coming dates in
its monthly digest.

## The bundle

```php
// config/bundles.php
return [
    // ...
    Base\BaseBundle::class => ['all' => true],
    Base\Admin\AdminBundle::class => ['all' => true],
    Base\Agenda\AgendaBundle::class => ['all' => true],
];
```

`AgendaBundle` aliases `Base\Agenda\Entity\*` to `App\Entity\Agenda\*` and the repositories to
`App\Repository\Agenda\*`, unless the application declares its own; `templates/` is `@Agenda`,
`public/` is `bundles/agenda/`.

## The routes

```yaml
# config/routes.yaml
agenda_controller:
    resource: "@AgendaBundle/src/Controller/Client"
    type: attribute
    prefix: /

# the back office's Google Calendar page (omnibase/admin)
agenda_admin_controller:
    resource: "@AgendaBundle/src/Controller/Admin/CalendarController.php"
    type: attribute
    prefix: /
```

and the page in the dashboard's menu:

```php
MenuItem::linkToRoute('agenda_admin_calendar', [], 'Google Calendar', 'fa-brands fa-google'),
```

## Configuration

```yaml
# config/packages/agenda.yaml (every key optional)
agenda:
    sources: []
    ics: ~                   # '%env(AGENDA_ICS)%' for a secret address
    past_per_page: 24
    timezone: Europe/Berlin
    calendar_name: ~
    jsonld: true
    jsonld_type: MusicEvent
    show_past: true
```

## The schema and the public files

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
bin/console assets:install
```

Tables `agenda_event` (joined to the core's thread table) and `agenda_venue`; the stylesheet
`bundles/agenda/css/agenda.css`.

## The sync

```cron
*/30 * * * *  bin/console agenda:sync
```

## What the layout provides

The pages extend `layout1.html.twig` and fill `title`, `description`, `content` and
`stylesheets` (where `/agenda` also announces its JSON feed with a
`<link rel="alternate" type="application/json">`). They use `twig/intl-extra`'s date filters and
the core's `|wysiwyg`.

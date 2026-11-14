# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-14

### Changed

- The classes omnisense/omnibase 5.0 moved to omnibridge/symfony named by their new names:
  `Omnibridge\Symfony\Omnibase\Bundle\{AbstractBaseBundle, AbstractBaseExtension, AbstractBaseConfiguration}`
  in place of `Base\Bundle\*`, former names the core keeps only as aliases until 6.0. No behaviour change.
- docs/installation.md registers `Omnibridge\Symfony\Omnibase\BaseBundle`, `Base\BaseBundle`'s new name.

## [1.0.0] - 2026-11-13

First stable release.

### Changed

- Installs with the default minimum-stability: stable requirements (`omnisense/omnibase` ^5.1, `omnibridge/symfony` ^1.1, `omnibase/admin` ^1.0), a branch alias,
  no `minimum-stability: dev` left to the sites.
- CI: glitchr-studio/ci's package workflow (the bundle installed alone, composer validate, PHPUnit,
  PHPStan against its baseline, a consumer install without require-dev) and its release workflow.
- `doctrine/doctrine-bundle` required: the repositories extend its `ServiceEntityRepository`.
- Neutral example data in the docs, the translations' help texts and the tests.

---
title: Testing
order: 60
---

# Testing

```bash
vendor/bin/phpunit
```

Unit tests of the ICS writer and reader, the Google link, the JSON-LD, the JSON feed (written and
read back), the description reader, the Squarespace reader (on a real excerpt,
`tests/Fixtures/squarespace.json`) and the Event's own rules; none needs a kernel, but
`tests/Controller/Admin/OpenToAdminsTest` (an administrator writes in the back office, a plain
user does not), which runs inside a host application and is skipped elsewhere.

In omnisense/omnibase's harness, this checkout mounted over the package:

```bash
docker run --rm --network none -v "$PWD":/srv/demo/vendor/omnibase/agenda:ro omnisense/omnibase-harness test agenda
```

```
..............................................................    62 / 62 (100%)

OK (62 tests, 569 assertions)
```

`tests/Service/IcsTest::testEveryPieceComesBackWhereItWas` proves the round trip of
[Google Calendar's step 2](usage-sync.md#google-calendar): a date written to the site's `.ics` and
read back changes nothing.

# assets/ornament

This directory holds the public domain ornament bundled with Certificate Pro.
It is currently **empty**, and that is deliberate rather than an oversight.

Every file here is written by `cli/import_pd_assets.php` from the queue in
`../pd_sources.json`, and every file here has a matching provenance record in
`../pd_manifest.json`. Nothing arrives by hand without a record.

Layout is one directory per source:

```
ornament/
  publicdomainvectors/
    pdv-22864-printing-ornament-border.svg
  openclipart/
    openclipart-222491-decorative-ornamental-frame-border.svg
  freesvg/
    freesvg-83802-guilloche-rosette.svg
```

To populate it:

```
php mod/certificatepro/cli/import_pd_assets.php --dry-run
php mod/certificatepro/cli/import_pd_assets.php
```

Read `../../tools/README.md` first. It carries the licence rules, and it lists
the sources that must never be imported from.

## Adding a file by hand

If you trace an engraving yourself, or lift a file from a source the importer
cannot reach, put it here and add its record to `../pd_manifest.json` with
`"hand_curated": true`. The importer will then refuse to overwrite it, prune it
or re-fetch over it, forever. A file sitting here with no manifest record at all
is also protected, on the assumption that a person put it there — but flag it
properly anyway, because `../CREDITS.md` is generated from the records and an
unrecorded file is an unlicensed file as far as an assessor is concerned.

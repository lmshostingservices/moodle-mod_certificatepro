# Certificate Pro — asset tooling

Two tools live here, and they answer two different questions.

| Tool | Question it answers |
| --- | --- |
| `harvest-collection.js` | Which Vecteezy resource ids are in a hand-curated collection? Browser console script. Produces `assets/curated_manifest.json`. **Legacy** — see the note at the end. |
| `../cli/import_pd_assets.php` | How does public domain ornament get from a CC0 website into the plugin bundle, with provenance? Moodle CLI script. Produces `assets/ornament/*` and `assets/pd_manifest.json`. |

This document is mostly about the second one, and about the licence rules a
curator has to follow before touching it.

---

## Running the import

```bash
cd /path/to/moodle

# See what would happen. Fetches and validates, writes nothing.
php mod/certificatepro/cli/import_pd_assets.php --dry-run

# Do it.
php mod/certificatepro/cli/import_pd_assets.php

# One source, twenty items, being polite about it.
php mod/certificatepro/cli/import_pd_assets.php --source=openclipart --limit=20 --delay=1000

# Re-fetch one asset that you know has changed upstream.
php mod/certificatepro/cli/import_pd_assets.php --id=pdv-22864-printing-ornament-border --force

php mod/certificatepro/cli/import_pd_assets.php --help
```

The script must be run as a user that can write to the plugin directory. It is a
**build-time** tool, not a runtime one: it writes into the plugin's own
`assets/` directory, which on a production site is usually read-only and should
stay that way. Run it on a development checkout, commit the result, ship the ZIP.

### What it does to each asset

1. **Refuses denied hosts** before making any request. The deny list is
   `denied_hosts` in `assets/pd_sources.json`. This is checked on the resolved
   download URL, so an entry cannot smuggle a blocked host in through a
   redirect target written into `download_url`.
2. **Refuses unverified licences.** A queue entry without
   `"licence_verified": true` is skipped with a message telling you to go and
   read the item page.
3. **Fetches** over Moodle's `\curl` wrapper, so the site's proxy settings and
   blocked-host rules apply. One megabyte ceiling, redirects followed, timeout
   configurable.
4. **Checks it is really SVG.** ZIP, PNG, JPEG, GIF and PDF magic bytes are
   detected and reported rather than written. A ZIP in particular is common on
   Public Domain Vectors, where the download bundles AI and EPS with the SVG;
   the fix is to extract by hand and set `download_url` on the queue entry to
   the extracted file.
5. **Sanitises** through `\mod_certificatepro\local\ai\svg_sanitiser`, the same
   allow-list sanitiser that generated artwork goes through. Scripts, external
   references, entity declarations, embedded documents and anything that can
   make a network request do not survive. Then `is_drawable()` confirms
   something is actually left to draw.
6. **Normalises the viewBox.** Absolute units (`mm`, `pt`, `in`, `pc`, `cm`) are
   converted to user units at 96 per inch. A missing viewBox is synthesised from
   width and height. A viewBox with a non-zero origin has its content wrapped in
   a translating group so that every asset in the library is rooted at 0,0.
   `width`, `height` and `preserveAspectRatio` are rewritten to match.
7. **Strips authoring metadata.** Inkscape and Illustrator namespaces are
   already gone by allow list; the source's own `<title>` and `<desc>` are
   removed and replaced with our own title and a one-line provenance string, so
   a single SVG lifted out of the bundle still says where it came from.
8. **Writes** to `assets/ornament/<source>/<id>.svg` and records the file in
   `assets/pd_manifest.json` with a sha256, geometry, licence, licence quote and
   the date the licence was read.

### Idempotency and what it will not overwrite

Re-running is a no-op for anything unchanged. An asset is reported `unchanged`
when the file on disk hashes to the sha256 in the manifest, and again when the
freshly fetched and sanitised bytes hash to the same value.

Two things are never overwritten, and `--force` does not change this:

- **A record flagged `"hand_curated": true`.** If you traced an engraving,
  reduced its nodes and dropped it into the library, no script is going to
  undo that afternoon.
- **A file on disk with no manifest record at all.** Unknown provenance is
  treated as human provenance. Fix it by writing a proper record, not by
  deleting the file.

`--prune` removes records that no longer have a queue entry, and deletes their
files. It skips hand-curated records unconditionally.

### Exit codes

`0` when nothing failed, `1` when at least one entry failed. Entries that were
merely *skipped* — raster source, unverified licence, source not enabled — do
not fail the run, because those are normal states for a queue that includes
sources a human has to handle.

---

## Adding an asset to the queue

Edit the `queue` array in `assets/pd_sources.json`:

```json
{
  "source": "openclipart",
  "ref": "222491",
  "slug": "decorative-ornamental-frame-border",
  "id": "openclipart-222491-decorative-ornamental-frame-border",
  "title": "Decorative Ornamental Frame Border",
  "author": "GDJ",
  "role": "border",
  "style": "baroque",
  "orientation": "both",
  "palette_affinity": ["any"],
  "recolourable": true,
  "licence_verified": true,
  "verified_on": "2026-08-16",
  "verified_quote": "Openclipart is 100% Public Domain",
  "notes": "Artist handle GDJ."
}
```

- **`id`** must be unique across the whole queue and is what the filename is
  derived from. Prefix it with the source so ids stay readable in the wizard.
- **`role`** must be one of `border`, `corner`, `seal`, `pattern`, `divider`,
  `full`. Anything else fails the entry rather than being quietly filed under
  something wrong. The role is a *placement* decision, and it is the taste step:
  a repeating band is a `divider`, not a `border`, no matter what the uploader
  called it.
- **`style`** is free text, but stay inside the vocabulary already in use —
  `victorian`, `baroque`, `floral`, `celtic`, `artnouveau`, `deco`, `geometric`,
  `guilloche`, `classical` — because the picker groups on it.
- **`palette_affinity`** and **`recolourable`**: most public domain ornament is
  flat line work that the painter recolours to the site's brand, so
  `recolourable: true` with `["any"]` is the usual answer. Set it honestly for
  multi-colour art that only works in its own scheme.
- **`licence_verified`** means *you personally opened the item page and read the
  licence*, and `verified_quote` is what it said. Not what the category page
  said. Not what the site's front page said. Not what this README said.
- Per-entry `licence`, `licence_url`, `attribution_required` and `attribution`
  override the source defaults. Wikimedia Commons entries **must** set them,
  because Commons licences are per file.

---

## Licence rules

The one rule everything else follows from: **anything bundled inside the plugin
ZIP must be GPL-compatible**, because Moodle's plugin contribution rules say so
and because the plugin is GPL v3. Public domain, CC0 and CC BY clear that bar.
Almost nothing else does.

### DO NOT import from these

**No CC BY-NC, CC BY-ND, or any other non-commercial or no-derivatives term.**
A non-commercial restriction is not free content and cannot live inside a GPL
work — GPL section 6 forbids imposing further restrictions on the recipient's
exercise of the rights granted. It does not matter that we do not intend to sell
the artwork; the recipient's rights are the point. No exceptions, no "but this
one is fine".

**No Rawpixel.** Their user terms, clause 12.10:

> "you must not copy or download ANY of our content or Images/designs with a
> view to adding them to or creating or compiling any form of comprehensive
> collection, compilation, directory, database, image gallery, templates or
> stock image agency for the distribution (for free or for sale) to others or
> for AI training, unless you have been given our express written permission to
> do so."

and, in the same clause:

> "You must not use automatic programming of devices to enable automatic or
> repeated creation/downloading of Images/designs from our Website."

Between them those describe a bundled asset library and an automated importer.
Rawpixel hosts a great deal of genuinely public domain material, which is
exactly the trap: the underlying works being out of copyright says nothing about
the contractual terms attached to Rawpixel's copies of them, which is what you
agree to by downloading. Go to the original institution instead.

**No Dover Publications archives.** Dover's *Ready-to-Use* clip-art books carry
a per-book permission notice capping how many designs may be reused in one work
and prohibiting redistribution as part of another clip-art collection. Dover's
selection, cleanup and typesetting are their own work product even where the
underlying ornament is centuries old. Scanned Dover books circulating online
carry that notice with them.

**No Fleuron / ECCO.** The Fleuron database of eighteenth-century printers'
ornaments is built by machine-extracting images from Gale's *Eighteenth Century
Collections Online* page scans. ECCO is a commercial subscription product and
there is no public licence permitting redistribution of images derived from its
scans. The ornaments themselves are long out of copyright — get them from a
source that grants redistribution, such as the British Library's Flickr Commons
upload, not from a research interface built on a licensed database.

**No Vecteezy, Freepik, Flaticon or Iconscout.** Freepik group terms bar use of
their content in software that lets a third party generate on-demand designs.
Flaticon's legal terms state it almost word for word:

> "The Flaticon Content is not used in software applications which allow a third
> party to generate on-demand designs."

It is drafted the same way twice by the same group, so it is boilerplate rather
than a quirk, and it is a *content-use* clause rather than a delivery-channel
one — an API key does not buy past it. Iconscout carries the equivalent
restriction and says it holds even if you modify the item. This is why the
bundled-public-domain plan replaced the licensing plan.

All of the above are listed in `denied_hosts` in `assets/pd_sources.json` and
the importer refuses to make a request to any of them. Do not remove entries
from that list to "just try it".

### Before adding any source

1. Find the licence statement **on the item page**, not the front page.
2. Copy it verbatim into `verified_quote` and put today's date in
   `verified_on`.
3. If the licence is CC BY or anything else requiring credit, set
   `attribution_required: true` and write the required credit line into
   `attribution`. It has to survive into `assets/CREDITS.md` and into whatever
   the renderer shows.
4. If the licence has a share-alike term, stop and ask. CC BY-SA inside a GPL
   plugin is a question, not a formality.
5. Update `assets/CREDITS.md`. That file is what a Moodle plugin assessor reads.

---

## Note on `harvest-collection.js`

That script belongs to the earlier plan, in which artwork was licensed from
Vecteezy and fetched per institution at runtime rather than bundled. That plan
is dead: the Freepik group licence quoted above prohibits it, and the public
domain route is free, already in SVG, and available in far greater quantity.

It is kept because `curated_library.php` and `assets/curated_manifest.json` are
still in the tree and the harvester is what produces that manifest. If the
curated path is removed, this script goes with it. Nothing it produces may be
bundled.

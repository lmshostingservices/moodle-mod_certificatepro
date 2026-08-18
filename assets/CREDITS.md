# Certificate Pro — bundled media credits and provenance

Certificate Pro is licensed **GNU GPL v3 or later**. Moodle's plugin
contribution rules require that any media bundled inside a plugin is itself
GPL-compatible, and that its origin can be established. This file is the record
of where every bundled ornament came from, under what licence, and whether that
licence asks for a credit.

**Status at the time of writing: no artwork is bundled yet.** `assets/ornament/`
is empty and `assets/pd_manifest.json` holds zero items. The sources below are
the approved shelf that `cli/import_pd_assets.php` draws from; nothing has been
drawn from it in this repository state. When the importer runs, each file it
writes gains a record in `assets/pd_manifest.json` carrying its source, its
licence, the licence text quoted from the page it was read on, the date it was
read, and a sha256 of the stored bytes. That record, not this prose, is the
authoritative per-file provenance.

---

## What is bundled, and what is not

| Bundled in the plugin ZIP | Not bundled |
| --- | --- |
| Public domain and CC0 ornament in `assets/ornament/` | Anything licensed from a stock vendor |
| The manifests describing it | Anything under CC BY-NC or any non-commercial term |
| Generated artwork: guilloche, rosettes, security bands, backgrounds — drawn in code from a seed, so no third party has any interest in them at all | Anything whose licence forbids redistribution inside a design tool |

Fonts are handled separately and are not covered by this file; see the plugin's
font manager and `fonts/`.

`thirdpartylibs.xml` remains empty: no third-party *code* is vendored. Media is
recorded here instead, which is where Moodle's guidance puts it.

---

## Approved sources

Each entry below states what the source is, the licence, the URL, whether
attribution is required, and what we actually verified ourselves. Where we could
not verify something directly, it says so rather than repeating a claim.

### 1. Public Domain Vectors — `publicdomainvectors`

- **What it is.** A library of vector clip art with roughly 3,301 results under
  its ornament-border category: Victorian, baroque, floral, Celtic, Art Nouveau
  and geometric borders, corners and rules. Delivered as SVG, so no tracing is
  needed.
- **URL.** <https://publicdomainvectors.org/en/ornament-border-clip-art>
- **Licence.** Public domain. Verified on an individual item page,
  <https://publicdomainvectors.org/en/free-clipart/printing-ornament-border/22864.html>,
  which states verbatim:
  > "Public Domain. You can copy, modify, distribute and perform the work, even
  > for commercial purposes, all without asking permission."
- **Attribution required.** No.
- **Caveats.** The licence line is printed per item, not only site-wide, so the
  curator reads the *item* page and records that item's own wording. Some
  downloads arrive as a ZIP containing AI and EPS alongside the SVG; the
  importer refuses archives rather than guessing at their contents.

### 2. FreeSVG.org — `freesvg`

- **What it is.** A CC0 SVG library, including ornamental borders and the
  guilloche rosettes that are tagged for certificate use.
- **URL.** <https://freesvg.org>
- **Licence.** CC0 1.0 Universal, site-wide. Verified at
  <https://freesvg.org/about>, which states verbatim:
  > "FreeSVG.org offers free vector images in SVG format with Creative Commons 0
  > license (public domain)."
- **Attribution required.** No.
- **Caveats.** The grant is site-wide rather than per file, so it rests on the
  site's own assurance about what its uploaders contributed. For ornament out of
  copyright by age this is low risk; for anything that looks like a modern
  branded mark, it is not, and such files are not what we are here for.

### 3. Openclipart — `openclipart`

- **What it is.** A long-running community clip art library with a substantial
  ornament, frame and border holding. SVG throughout.
- **URL.** <https://openclipart.org>
- **Licence.** Creative Commons Zero 1.0 Public Domain. Verified at
  <https://openclipart.org/share>:
  > "Each artist at Openclipart releases all rights to the images they share at
  > Openclipart."
- **Attribution required.** No. The site's own FAQ says
  > "there is no need to cite Openclipart."
- **What we do anyway.** We record the contributor handle in the manifest and
  list it here. It costs nothing and CC0 contributors have said for two decades
  that they like seeing it.

### 4. Wikimedia Commons — `wikimedia-commons`

- **What it is.** Categories of direct interest: *Ornamental borders*, *SVG
  frames*, *Typographic ornaments*.
- **URL.** <https://commons.wikimedia.org>
- **Licence.** **Varies per file.** Commons accepts CC BY and CC BY-SA as well
  as public domain and CC0. The licence is a property of the individual file,
  not of the site.
- **Attribution required.** **Assume yes until the file says otherwise.**
- **How this source is handled.** It is disabled for automated import on
  purpose. A curator opens each file description page, reads its licence
  template, and writes that file's own `licence`, `licence_url`,
  `attribution_required` and `attribution` string into the queue entry, which
  overrides the source defaults. In `assets/pd_sources.json` this source
  defaults to `attribution_required: true`, so a careless import cannot quietly
  drop a credit.
- **Also note.** CC BY-SA is GPL-compatible only in one direction and is
  awkward inside a GPL plugin; prefer PD, CC0 or CC BY files from Commons and
  leave CC BY-SA alone unless somebody has thought about it properly.

### 5. Smithsonian Open Access — `smithsonian-open-access`

- **What it is.** Over three million assets released under CC0, with a public
  API. Relevant holdings are printed ornament and decorated bookwork.
- **URL.** <https://www.si.edu/openaccess>
- **Licence.** CC0. Verified at <https://www.si.edu/openaccess/faq>:
  > "you may use Smithsonian Open Access assets designated as CC0 for commercial
  > purposes without any attribution, permission, or fee paid to the
  > Smithsonian"
- **Attribution required.** No, though the Smithsonian asks that credit and a
  link be given where practical, and we do.
- **Caveats.** Raster. Nothing from here enters the bundle without being traced,
  and tracing is a deliberate human step with node reduction, so the importer
  never fetches from this source. The Smithsonian's CC0 waiver covers their own
  copyright only; third-party rights such as trademark or publicity may still
  attach to a particular object, so the object record is read before use.

### 6. Rijksmuseum — `rijksmuseum`

- **What it is.** Sixteenth-century grotesque ornament engravings — Cornelis
  Floris, the Van Doetecum brothers — at archival resolution.
- **URL.** <https://data.rijksmuseum.nl/about/>
- **Licence.** Reported as CC0 with no additional rights asserted. **We did not
  verify this ourselves.** The rights page was not reachable during this pass.
- **Attribution required.** Believed no, unverified.
- **Caveats.** Two reasons nothing from here is bundled yet: the rights
  statement needs confirming on the individual object record, and the material
  is raster and needs tracing.

### 7. British Library on Flickr Commons — `british-library-flickr`

- **What it is.** The *Decorations & Designs* album — 1,706 border and ornament
  images machine-extracted from 18th and 19th century books, part of the
  Library's million-image Flickr Commons release.
- **URL.** <https://www.flickr.com/photos/britishlibrary/albums/72157638797895895/>
- **Licence.** Flickr Commons "no known copyright restrictions". We confirmed
  the album holds **1,706 photos** and sits inside the Library's public domain
  upload, but **no per-album rights statement rendered on the page we fetched**.
- **Attribution required.** No, but treat "no known copyright restrictions" as
  the Library's assertion about its own holdings rather than as a licence grant,
  and credit the Library as a matter of course.
- **Caveats.** Raster; tracing required. Not fetched by the importer.

---

## Sources that are excluded, and why

These are recorded here as well as in `tools/README.md` because an assessor's
first question about a bundled art library is what was left out.

| Source | Reason for exclusion |
| --- | --- |
| **Vecteezy, Freepik, Flaticon, Iconscout** | Group licence terms bar use of the content in software that lets a third party generate on-demand designs. Flaticon's legal terms put it almost verbatim: *"The Flaticon Content is not used in software applications which allow a third party to generate on-demand designs."* That is a content-use clause, not a delivery-channel clause, and it describes this plugin exactly. Nothing from these vendors may ever be bundled. |
| **Rawpixel** | User terms clause 12.10: *"you must not copy or download ANY of our content or Images/designs with a view to adding them to or creating or compiling any form of comprehensive collection, compilation, directory, database, image gallery, templates or stock image agency for the distribution (for free or for sale) to others"*, and *"You must not use automatic programming of devices to enable automatic or repeated creation/downloading of Images/designs from our Website."* Both clauses describe our importer. The underlying works being old does not change the terms attached to Rawpixel's copies of them. |
| **Dover Publications archives** | Dover's clip-art titles carry a per-book permission notice that caps how many designs may be reused in a single work and bars redistribution as part of another clip-art collection. Dover's selection, cleanup and typesetting are their own work product. We did not obtain the notice text verbatim; the source is excluded on precautionary grounds rather than on a quoted clause. |
| **Fleuron / ECCO** | Fleuron's ornaments are machine-extracted from Gale's *Eighteenth Century Collections Online* page scans. ECCO is a commercial subscription database and we found no public licence granting redistribution of images derived from those scans. Excluded on precautionary grounds. The underlying 18th-century ornaments are of course out of copyright — the route to them has to be a source that grants redistribution, such as the British Library's own Flickr Commons upload. |
| **Anything CC BY-NC or CC BY-ND** | Not GPL-compatible and not free content. A non-commercial term cannot survive inside a GPL plugin, because GPL section 6 forbids adding restrictions on the recipient's rights. No exceptions, including for material that "nobody would ever sell". |

---

## Generated artwork

Guilloche, rosettes, security bands, engine-turned fields and every background
style in `classes/local/art/` are drawn in code from a numeric seed. No third
party has any interest in them, nothing is copied, and the output is unique per
seed. They are covered by the plugin's own GPL v3 licence along with the rest of
the source.

---

## How to check this file against reality

```
php mod/certificatepro/cli/import_pd_assets.php --dry-run
```

lists every queued asset, its source, and whether its licence has been verified.
`assets/pd_manifest.json` holds the per-file record including the sha256 of each
stored file, so any file in `assets/ornament/` that does not appear there, or
whose hash does not match, is unaccounted for and should be treated as such.

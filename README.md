# Certificate Pro

A certificate activity for Moodle. Design a certificate on one drag-and-drop canvas, issue it automatically or by hand, and let anyone verify it without an account.

Certificate Pro is original code released under the GNU GPL v3 or later. It is not a fork of, and shares no code with, any existing certificate plugin.

---

## What makes it different

**A designer that actually helps you.** As you drag an element towards a part of the page, the area a certificate would conventionally put it in appears as a labelled dotted rectangle — *Certificate title — top centre*, *Date — lower left*, *QR code — bottom right* — and the element snaps into it, adopting the right anchoring and text alignment as it lands. Twenty-six such areas are defined per orientation, and every one of them is optional: drag anywhere and the element stays exactly where you dropped it, to three decimal places of a millimetre.

Alongside the zones there is a full alignment system: magnetic guides for the page centre, the optical centre, margins, thirds and quarters, plus live edge-and-centre guides against every other element on the page. Multi-select, align, distribute, nudge by half a millimetre, rotate, lock, reorder, undo and redo all work the way you would expect from a design tool.

**The preview does not lie.** The canvas loads the same font files the PDF embeds, served as web fonts, so the browser and the PDF measure text with identical outlines. Long values shrink to fit their box rather than overflowing it, and a real PDF preview is one click away from the toolbar.

**Fonts you can actually install.** A stock Moodle ships very few embeddable fonts, which is why certificates in Arabic, Hindi, Thai or Chinese so often arrive as rows of question marks. Certificate Pro has a font manager: upload a TrueType or OpenType file in the browser and it is prepared for embedding, catalogued with the scripts it covers, and offered to authors. No shell access, no FTP, and it survives Moodle upgrades.

**An issued certificate never changes.** Every value a design resolves is written into a snapshot at the moment of issue, and the rendered PDF is stored. Rename the course, edit the design, correct a grade or delete the activity: a credential somebody already holds says exactly what it said the day they earned it.

**Verification that works.** Every certificate carries a checksummed code and a vector QR code. The verification page is genuinely public — a scanned code lands there and stays there — and reports only whether the code is genuine and what it attests to.

**Design that follows your brand.** The site logo comes straight from the Moodle theme. The primary colour is resolved from the active theme and its ancestors, with a plugin-level override. Ten curated font pairings set headings, body and accents together, in the spirit of the pairings a design tool would offer you.

**Background artwork.** Ten procedural styles — colour washes, guilloche security patterns, art deco fans, botanical flourishes, waves, sunbursts, colour meshes — drawn as vectors in your own palette, with no network access and no cost. If the site has an AI provider configured, artwork can also be composed from a written description; anything it returns is sanitised against a strict allow list before it is stored.

**Shared media library and four logo slots.** Signatures, seals and backgrounds are uploaded once at site level and chosen by name in any design — replace the file and every certificate that uses it updates. Alongside it, four named logo slots, because most organisations carry more than one mark: the institution, the accrediting body, a funding partner, a programme.

**Accessible output, described honestly.** The PDF carries a title, a language and a text direction, and asks the reader to announce the title rather than the filename. Alternative text is collected on every image and used in the designer, on the activity page and in the mobile app. It is not claimed in the PDF itself: the library Moodle ships has no way to tag an image with a text alternative, so full PDF/UA is not achievable and this plugin does not pretend otherwise.

---

## Elements

Thirty element types, in five drawers.

| Drawer | Elements |
| --- | --- |
| Text | Text (with placeholders), Formatted text |
| Course and learner data | Recipient name, Course name, Category name, Organisation name, Grade item name, Teacher name, Group name, Date, Expiry date, Grade, Profile field, Course field |
| Pictures | Picture, Background picture, Site logo, Profile picture, Signature block, Award seal |
| Verification | Verification code, Serial number, Verification address, QR code, Digital signature |
| Decoration | Page border, Divider, Shape, Generated background |

Plus a placeholder that keeps an unknown element's position and settings intact rather than throwing an error, so a design built elsewhere always opens.

Notable behaviours:

- **Text** supports placeholders such as `{{user.fullname}}`, `{{course.fullname}}` and `{{cert.date}}`, so a whole sentence is one element rather than five separately positioned fragments.
- **Date** fixes its format, language, timezone and numeral system in the design, so the same certificate reads the same to everyone. Arabic-Indic and Devanagari numerals are built in.
- **Grade** offers points, points out of maximum, percentage, letter and pass/fail wording, with configurable decimal places.
- **Signature block** is one element containing the image, the rule, the name and the role, in proportion.
- **QR code** is drawn as vector artwork rather than a bitmap, so it stays sharp and cannot be turned into a black square by a transparency mishap.
- **Course field** and **Profile field** keep formatting by default, so a rich-text custom field does not collapse into one flat line.
- **Award seal** is drawn with native PDF primitives rather than handed over as SVG, because SVG gradients and text-on-a-path are not supported by the PDF library and produce a hard diagonal split with missing lettering.
- **Background picture** takes a file from the shared library or a direct upload, with cover, contain and stretch fitting and an opacity control.

---

## Starting designs

Six, five of which are complete certificates rather than empty pages:

- **Classic** — framed and centred, with two signature blocks, a seal, a code and a QR code.
- **Ceremonial** — the same, with a guilloche security pattern and a laurel seal.
- **Modern** — no frame, one accent bar, generous space.
- **Corporate** — banded, led by the site logo.
- **Portrait** — the full layout arranged for a portrait page.
- **Blank page** — nothing at all.

---

## Issuing

- Automatic issue when a learner has met their own criteria: minimum time in the course, minimum course grade, course completion, or any combination.
- Manual issue and revoke from the issued certificates report, with an audit trail. Nobody ever has to "log in as" a learner to mint a credential.
- Issuing is never a side effect of another learner's action or of an email setting.
- Sequential serial numbers with a configurable prefix and padding, handed out one at a time and never reused.
- Validity periods, with the expiry date fixed on the issue record and reminders before it falls due.

---

## Verified

Built and tested against a real Moodle 5.0.9 on PostgreSQL 16, not a mock: **257 automated checks, 0 failures**, covering install, all 30 element types, PDF rendering, issuing, snapshot immutability, verification, revocation, the media library, sizing, geometry, privacy and teardown. Run it yourself with `php mod/certificatepro/cli/verify.php`.

## Requirements

- Moodle 4.5 LTS or later (tested through 5.3)
- PHP 8.1 or later
- No Composer step, no bundled libraries, no external services required

---

## Installation

1. Copy this folder to `mod/certificatepro` in your Moodle installation, or install the ZIP through **Site administration → Plugins → Install plugins**.
2. Visit **Site administration → Notifications** to complete the database upgrade.
3. Optionally visit **Site administration → Plugins → Activity modules → Certificate Pro** to set a brand colour override, add fonts, or configure artwork generation.

---

## Privacy

Certificate Pro stores, per issued certificate: the recipient, the verification code, the serial number, the values printed on the certificate, the issue and expiry dates, and the rendered PDF. All of it is exported and deleted through Moodle's privacy API.

Artwork generation is off unless an administrator configures it. When it is on, the only thing sent outside the site is the author's written description of the background they want, together with the site's brand colours. Nothing about any learner is ever sent.

---

## Licence

Copyright 2026 LMS Labs.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [LICENSE](LICENSE).

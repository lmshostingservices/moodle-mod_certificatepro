# Changelog

## [2.0.8] - 2026-11-11

Closes a known gap in bulk download. Includes everything in 2.0.5 to 2.0.7.

- Fixed: **"Download all as a zip" could pack a withdrawn certificate as a current one.**
  It decided what to include from the revoked column alone, but a certification-cycle
  award can be withdrawn in the recertification ledger without that column changing.
  Downloading such a certificate one at a time was already refused, so the zip
  contradicted the rest of the plugin. It now asks for the certificate's status, the
  same question every other part asks, and leaves out awards that are withdrawn,
  unfinished, or whose ledger cannot be read.
- The cycle-only activity now explains itself when Course Recertification is not
  installed, instead of showing "Error occurred" with the explanation discarded.


## [2.0.7] - 2026-11-11

Two settings that quietly did the wrong thing. Includes everything in 2.0.5 and 2.0.6.

- Fixed: **"Minimum time in the course" was sixty times too long.** The setting is entered
  as minutes or hours and stored as seconds, and the time a learner has spent is counted
  in seconds, but the stored number was multiplied by a minute again before the two were
  compared. A trainer asking for ten minutes was asking for ten hours, the learner was
  told to "spend at least 10 hours in this course", and on any sensible setting the
  certificate never issued at all.
- Fixed: **a pass or fail result printed nothing unless the wording was typed in by
  hand.** The element always supplies its pass and fail wording as text, so a field left
  alone arrives empty rather than unset, and the fall-back to "Passed" and "Not passed"
  could never fire. A design left at its defaults printed a blank space where the result
  belongs. The default wording is used again, and wording that is only spaces falls back
  to it as well.


## [2.0.6] - 2026-11-11

Four more checks that the 1.4.0 merge dropped. Each one guards an action the
interface already appears to guard, so none of this was visible from the screen.
Includes everything in 2.0.5.

- Fixed: **a hidden activity still issued certificates.** A certificate set up for next
  term and hidden from learners went on posting certificates out on its schedule, as did
  one in the middle of being deleted.
- Fixed: **opening the activity page issued a certificate to whoever opened it.** The
  scheduled task was corrected in 2.0.3; this is the same fault on the other path, so a
  trainer looking at her own certificate activity was handed one for the course she
  teaches.
- Fixed: **"Allow revocation" was only hiding a button.** With the setting off the
  Revoke control disappeared, but the address behind it still worked, so a certificate
  an activity had declared permanent could still be withdrawn.
- Fixed: **issuing by hand accepted any user id.** It did not check that the person was
  in the course or could receive a certificate at all, and did not exclude deleted users,
  so a certificate could be issued to somebody who had never been enrolled.

### Hardening

- An issued certificate's file is now refused unless the address names a module context
  and the certificate belongs to that activity. The file was already fetched from the
  context in the address, so a mismatch found nothing; the request is now refused on the
  grounds that are meant rather than by accident.


## [2.0.5] - 2026-11-11

A security fix, and two more things the 1.4.0 merge dropped. Upgrading is strongly
recommended for any site running 1.4.0 or later.

### Security

- Fixed: **a certificate could be made to print a learner's password hash.** The field
  a "User field" element prints is chosen from a list, and only the names on that list
  were ever meant to be readable. The check that enforced it was lost, leaving only a
  test that the column exists — so a design asking for `password` printed the stored
  hash onto the certificate, and `email`, `auth` and `lastip` printed just as readily.
  Confirmed end to end: the complete hash appeared in the text of a rendered PDF.

  The settings form and the field picker both reject a name that is not on the list, so
  this could not be done through the designer. It could be done by a user who can edit
  a design — a teacher or a manager, not a learner — through the undo service, which
  takes an element's settings back as JSON, and by restoring a design crafted elsewhere.
  Certificates are documents that get downloaded, sent on and publicly verified, so the
  disclosure does not stay on the site.

  The list is enforced again where the value is read. Sites that have issued
  certificates from a design with a hand-edited user field should check them.

### Also fixed

- Fixed: **a copied or restored course printed grades from the original course.** A
  design can print a named grade item rather than the course total, and it stores that
  choice as the item's own id. Those ids belong to the course the design came from, and
  nothing remapped them, so a duplicated course issued certificates carrying a number
  out of another course's gradebook. Measured directly: the copy still pointed at grade
  item 101, which belonged to the course it was copied from. The reference now follows
  the restore, and falls back to the course total when the item did not come across.
- Fixed: none of the plugin's seven events described how to remap their ids, so Moodle
  warned about each one and could not restore course logs accurately.


## [2.0.4] - 2026-11-11

A third thing the 1.4.0 merge dropped, found by the same comparison against 1.1.6.

- Fixed: **"Email the certificate to the learner" did nothing.** The setting was on the
  activity form, it saved, it was carried through backup and restore, the message
  provider was declared so "Certificate issued" appeared in every user's notification
  preferences, and the issue record kept an "emailed" flag — but nothing anywhere read
  the setting, so no learner was ever told. The same applied to notifying staff and to
  the list of extra addresses. All three now send again, carrying the verification code
  and a link to the certificate, and the issue is marked as sent. A mail failure still
  cannot undo or block an issued certificate.
- Fixed: **the privacy declaration no longer matched what the plugin stores.** An issued
  certificate records who issued it, who revoked it and the reason they gave, and the
  artwork, media library and font tables each record who created or uploaded the item.
  None of that was declared, so a site answering a subject access request would not have
  been told about any of it, and the notifications the plugin sends were not declared
  either. All of it is declared again. The verification harness now compares the
  declaration against the tables themselves, so a column added later cannot slip through.


## [2.0.3] - 2026-11-11

Two regressions that came in with the 1.4.0 merge, found by comparing this line
against the published 1.1.6 branch it was merged with. Neither was present in 1.1.6.

- Fixed: **certificates were issued automatically to people who should never receive
  one.** Automatic issuing chose everyone who could *open* the activity, which on a
  default site includes the trainer, the assessor and the manager — so a trainer was
  handed a publicly verifiable certificate saying she had completed the course she
  teaches. It also included learners whose enrolment had been suspended when they
  withdrew, and learners the activity's own access restrictions shut out. Measured on
  a twelve-learner course, thirteen certificates were issued where eleven were due,
  and with a group restriction in place, eleven where four were due. Issuing now
  follows a new capability, "Receive a certificate", which only learners hold by
  default; it counts active enrolments only; and it honours the activity's access
  restrictions. The issued-certificates report follows the same rule, and still lists
  anyone who already holds a certificate so that a wrongly issued one stays visible
  and can be revoked.
- Fixed: generating artwork through Moodle's own AI provider failed on Moodle 5.x
  with "Too few arguments to function core_ai\manager::__construct()". Moodle 5.0
  injects the database into that class, so it has to be built through the dependency
  container rather than directly.

Administrators upgrading should look at the issued-certificates report for any
certificate held by a member of staff and revoke it.


## [2.0.2] - 2026-11-11

One blocker, found by building the demo course for the tutorial video on Moodle 5.2.

- Fixed: **the activity could not be added or edited on Moodle 5.x at all.** Opening
  "Add an activity — Certificate Pro", or the settings of a certificate that already
  existed, produced "Coding error detected... 60 is not one of the units allowed for
  this MoodleQuickForm_duration element" and no form. The validity-period field offers
  its units as days and weeks but never said which to use by default, and Moodle's
  duration field defaults to minutes; since Moodle 5.0 it refuses to build when that
  default is not among the units offered. Moodle 4.5 did not check, which is why the
  fault never appeared on a 4.5 site and why every earlier test passed. The field now
  names days as its default.

  Sites on Moodle 4.5 were never affected. Sites on Moodle 5.0 or later could not
  create a certificate activity at all.


## [2.0.1] - 2026-11-11

Two defects found by testing 2.0.0 on a live site.

- Fixed: a course restored or copied with its issued certificates would start numbering
  its serials again from one, so the next certificate issued carried a serial number
  already printed on one of the certificates the same restore had just brought across.
  Nothing in the database prevented it and nothing reported it: only the verification
  code is unique, so the duplicate was issued in silence. A restored activity now
  continues from the highest serial it actually holds. Restoring a course without its
  user data still starts at one, which is correct, and restoring into a course that
  already has certificates continues past those too.
- Fixed: the fonts page reported six of the bundled families as incomplete and offered
  to install them, on a site where every face that exists was already installed. Four of
  them — Cairo, Cinzel, Space Grotesk and Tajawal — publish no italic at all, and
  Archivo Black and Great Vibes publish neither bold nor italic, so the page was asking
  for work that no amount of installing could ever finish: the button reappeared after
  every run and the count never fell. A family is now complete when it has every face
  its publisher offers. What an install discovers is remembered, so a family that gains
  a face upstream corrects itself, and a family that is genuinely short of a face Google
  does publish is still reported.

## [2.0.0] - 2026-11-11

A major version because of what a site upgrading from the 1.1.x line actually receives,
not because of anything new in this release itself: the code is 1.4.5, renumbered.

Two development lines had diverged in August. The published 1.1.x line carried the
certification-cycle work and Moodle 5.2 and 5.3 compatibility; an unpublished line carried
a large body of defect work and a cleanup that removed the stock background and ornament
libraries. 1.4.0 merged them, and 1.4.1 through 1.4.5 fixed what live testing then found.
This release is that whole body of work under one number.

What a site coming from 1.1.6 gains:

- Multi-page certificates: pages can be added and removed. Multi-page rendering worked
  before, but nothing could create a second page.
- Every certificate an activity has issued can be downloaded as a single zip, each file
  the document exactly as it was issued rather than a fresh render of the current design.
- Deleting an element in the designer is reversible, including the picture on an image
  element.
- The designer is operable by keyboard throughout, with visible focus indicators.
- A recipient whose name is in Chinese, Japanese, Korean, Arabic or Hebrew gets a correct
  certificate; the name is drawn in a face that covers it and Arabic is properly shaped.
- The stock background and ornament libraries are gone, replaced by the guided artwork and
  guided seal wizards, which draw in the design's own colours.
- Fonts the bundled designs need can be installed from the fonts page, with every weight.
- Public verification works without a login, so a scanned QR code reaches the result.
- Issuing by hand, revoking and deleting shared media all confirm first, and a revocation
  records a reason that is shown to anyone checking the code.

Fixed along the way, all of them reproduced on a live site before being fixed:

- Text was unreadable on dark backgrounds.
- Backgrounds printed differently from the preview, because gradients were being flattened.
- Saving a text element's settings set its size to zero and the text vanished from every
  certificate issued afterwards.
- The guided artwork wizard could fail outright when applying a background.
- Scanning a certificate's QR code led to a login page on Moodle 5.0 and later.
- Installing many font families at once left some of them half finished.

Verified on Moodle 5.2.4+ with PostgreSQL 16: the plugin's own harness passes 257 checks;
a learner's downloads, the shared media library, phone layouts, multi-page mixed-orientation
output and the permission checks were each exercised directly. Moodle 5.3 is declared as
supported on the strength of the 1.1.x line's testing, not retested here.

## [1.4.5] - 2026-11-10

- Installing all the fonts the designs need left some families half finished. Preparing
  every face of thirty-odd families is a burst of roughly a hundred and forty writes into
  Moodle's file pool, and on a busy or space-tight server a few come back with "cannot
  create local file pool directories" — the same face then installs perfectly on its own
  a minute later. The administrator was handed a set of partly installed families and
  left to notice and repair them by hand. Each face is now offered to the file pool up to
  three times with a short, growing pause. A face that installs first time is not delayed.
- The torch centre mark read as a fleur-de-lis rather than a flame. The width of each
  tongue ramped up too quickly below the tip, rounding it into a petal; the ramp is
  steeper now and the outer two tongues lean away from the centre, so the group flickers
  instead of sitting symmetrical like a flower.

## [1.4.4] - 2026-11-10

- Blocker: the designer canvas and the printed certificate disagreed about every background that
  uses a gradient, which is twenty-three of the twenty-six in the catalogue. Backgrounds reached
  the page through TCPDF's SVG parser, and that parser does not render gradients: it collapses
  each one to a single flat fill of its last stop. A wash that swept from a deep tint to near
  white on screen printed as a slab of the deep tint; "Aurora Field" printed as a blank white
  page; "Duotone Drift" printed as a flat blue with a hard navy wedge across it. Measured over a
  grid of sixty-three points on a real render, the canvas and the print were up to 206 of 255
  apart per colour channel.
  The plugin already draws a background as an operation list that two painters replay — one for
  the browser, one for the page — and the award seal has always printed through the second of
  them. Backgrounds now do the same. A background the style catalogue drew is redrawn at print
  time from its own recipe, which is now recorded alongside it: the style, the composition seed
  and the whole palette it was drawn from. The same six styles now measure between 0.3 and 4.2
  of 255 apart, and the print follows the page it is actually on rather than being stretched
  from the sheet the artwork was first drawn for.
  A background composed by a language model is not redrawn and cannot be: its markup is the
  original artwork and the only copy of it. It keeps the SVG parser, and so does any background
  stored before the recipe was recorded or drawn from a style the catalogue no longer knows.
  Those print exactly as they did before.
- Three faults in the native painter, each of which had been invisible while nothing but the seal
  used it, and each of which measured: a gradient with any vertical component was drawn upside
  down, because the canvas describes one the way SVG does and PDF measures the other way up; a
  radial gradient's focus was placed in the mirrored half of its box for the same reason; and a
  stroked rectangle was not drawn at all, because TCPDF reads a bare line style as a list of
  per-side borders and finds no sides in it. The last of those had been swallowing the frame of
  every background that draws one.
- Backgrounds now record their seed and their full palette, so what the author saw when they
  generated artwork is what prints, whatever the design's colours are changed to afterwards.

## [1.4.3] - 2026-11-10

- Blocker: the guided artwork wizard's "Deep Field" background is a saturated full-bleed ground,
  and applying it left every mark on the page in its light-page colour. Measured on a real
  render, the title sat at 1.63:1 against the ground, the recipient's name and the citation at
  1.99:1, the course name at exactly 1.00:1 — the same colour as the page, invisible — the page
  border at 1.06:1, the dividing rules at 1.59:1, the seal at 2.29:1 and the QR code at 2.47:1.
  An author who accepted the wizard's result shipped an illegible certificate.
  The background catalogue has always declared, per style, whether text has to sit dark on it or
  be reversed out of it. Nothing read that declaration. It is now read, per page, at render time:
  a page standing on a ground that needs light ink resolves its palette roles against that ground
  and lifts any role that does not clear its contrast floor, so text, seals, borders, rules,
  signatures and codes all come right together, on the designer canvas and in the print alike.
  The same page now measures 7.21:1 on the title, 7.24:1 on the name and the citation and on the
  course name, 4.57:1 on the secondary lines, 4.50:1 on the border and the rules, 5.03:1 on the
  seal and 8.50:1 on the code.
  Nothing an author saved is rewritten: a colour an author set as an explicit hex is their
  choice and is left exactly as it is, and moving a design onto a dark background and back off
  it again returns it byte for byte to where it started. A design already saved against a dark
  ground comes right the next time it is drawn.
- A QR code on a dark ground is drawn on its own light plate rather than being inverted, because
  an inverted code is read by some scanners and not others.

## [1.4.2] - 2026-11-10

- Blocker, introduced in 1.4.0 and caught before release: "Use this background" in the guided
  artwork wizard failed with "Invalid parameter value detected". The variant key the wizard
  passes is `style~seed`, and 1.4.0 had narrowed that parameter to `PARAM_ALPHANUMEXT`, which
  does not allow a tilde. Moodle's web service layer rejects a value that does not match its
  declared type exactly, so the whole call failed rather than quietly losing the author's
  choice. The three keys of that shape are read as text and are still decoded against the
  catalogue, so a tampered key can only ever name artwork the wizard would have drawn anyway.

## [1.4.1] - 2026-11-10

- Blocker: opening a text element's settings and pressing Save set its point size to zero, and
  text at zero points is simply not on the page. The title, the recipient's name or whichever
  element it was done to vanished from every certificate issued afterwards. The size box is
  allowed to be empty, meaning "take it from the design's typography", but `PARAM_FLOAT` turned
  that empty box into a 0 before the save code could tell the difference. The box is read as
  text and checked as a number instead, and a stored zero is now treated as "no size of its own",
  so an element already spoiled this way comes right as soon as this version is installed. Line
  height is guarded the same way: a cleared box used to stack every line on top of the next.

## [1.4.0] - 2026-11-10

Merges the 1.1.x line into 1.3.0. The two branches diverged on 15 August: 1.3.0 carried the
defect work and the asset-library cleanup, while 1.1.x carried certification cycles and Moodle
5.2/5.3 compatibility. This release is 1.3.0 with 1.1.x's additions ported forward.

- Certification cycles, from 1.1.1. A dedicated cycle-bound issuer whose ordinary course
  issuance is refused, immutable historical PDF bytes, concurrent issuance and retries that
  reuse one award identity, and backed-up cycle documents that restore as historical copies
  rather than rebinding to a new cycle. Adds `cycleonly` and `cycleid`.
- Fixed: the public verification page sent every QR scan to a login screen on Moodle 5.0 and
  later, because new 5.x sites force login by default. Verification is public again, as core's
  badge pages are, and a new "Public verification page" setting lets a site require login.
- Fixed: `cli/verify.php` used `course_delete_module()`, deprecated in Moodle 5.2.
- Supported Moodle versions are now 4.5 to 5.3.
- The opaque variant keys in the brief web services take `PARAM_ALPHANUMEXT` rather than a
  raw parameter, which is both narrower and what they always were.
- `certificatepro_fonts.style` no longer declares an empty-string default, so a fresh install
  and an upgraded site end with the same column.

All notable changes to mod_certificatepro are documented here.

## [1.3.0] - 2026-08-21

### Certificates that print correctly

- Fixed: a recipient whose name is in a non-Latin script got a broken certificate. No font TCPDF ships draws Latin,
  Arabic and Han at once, so the name was dropped from the page or printed as rows of tofu, and on a site with
  developer debugging on the download was an error page served as `application/pdf` — an unopenable file. Text is now
  split into script runs and each run is drawn in a face that covers it, so a name like "李小龍 عبد الرحمن" prints
  complete, with the Arabic correctly shaped and joined. Emoji, which no available face can draw, are removed rather
  than left as boxes. The gaps in TCPDF's own bidirectional character table are filled before use, which is what used
  to put PHP warnings inside the PDF bytes.
- Fixed: the canvas never scaled type to the page. Element boxes were drawn in millimetres times the zoom while the
  text inside them was an absolute point size the browser resolved at 96dpi and never scaled, so the design visibly
  re-composed itself as you zoomed. Type is now expressed in container-query units: the median canvas-to-PDF
  discrepancy is 1.1%, and it no longer moves with the zoom.
- Fixed: shrink-to-fit applied in the PDF but not on the canvas, so a long course title was sliced mid-word on screen
  and complete on paper.
- Fixed: font weight and family differed between canvas and PDF. Pairing weight was applied only in the PDF, and
  built-in families got no `@font-face` at all, so the canvas fell through to the browser's generic serif.
- Fixed: every installed face is now published to the browser under its own family name, so bold is the real bold
  outline rather than one the browser synthesises. The designer and the PDF measure the same letters again.
- Fixed: the award seal painted its outermost rule half outside its own box — the canvas clipped it and the PDF did
  not. Every edge treatment (plain, double, scallop, cog, starburst, perforated, dotted, interrupted, rope) now fits
  its declared square exactly.
- Fixed: the QR code never drew on the canvas, though it printed correctly.
- Fixed: the page border ignored its own box in the PDF, and the Portrait starter shipped a square border on a
  rectangular page, so the canvas drew two stray rules.
- Removed `Powered by TCPDF (www.tcpdf.org)` from the text layer of every certificate.

### The designer

- Fixed: element settings could never be saved. Every "Save changes" ended in an HTTP 500 and any picture uploaded on
  that form was lost with it, because the form posted the element's id in a field the receiving page reads as the
  course module id.
- Fixed: bold, italic and underline never round-tripped. The form always redisplayed them unticked, so re-saving an
  element that had them silently took them away.
- Fixed: several actions made the canvas show invented sample content. The edit page built the render context with
  the course and the activity; every web service built it without them, so adding an element or changing the paper
  size made the whole canvas start reading "Introduction to Workplace Safety" and the grade element print a result
  for a learner who has none.
- New: add and remove pages. Multi-page certificates rendered correctly but nothing could ever make a second page,
  take one away, or reorder them.
- New: deleting an element is reversible. The elements come back with their settings, position, rotation, styling and
  their uploaded picture, which the delete no longer destroys.
- Fixed: undo history was global while its snapshots were per page, so an undo after switching pages wiped the page
  you were looking at or filled it with another page's elements. A step now carries the page it describes and the
  undo travels to it.
- Fixed: undo was polluted by plain clicks, and adding, duplicating and deleting were not on the stack at all.
- Fixed: every keyboard shortcut was dead until you tabbed into the designer, because the canvas suppressed its own
  focus. The designer is now fully operable by keyboard — select, move, resize, multi-select, the toolbar, the panels
  and the dialogs — with focus that survives a redraw, a live region that says what changed, and focus rings that
  meet WCAG 2.4.7 in both light and dark.
- Fixed: resizing an element did not rescale what was drawn inside it until the page was reloaded.
- Fixed: square elements were distorted and shrank permanently. A seal stretched to its box on canvas while the PDF
  kept it circular, zone docking clamped width and height independently so it became an oval the first time it was
  dragged, and every orientation round trip made it smaller. Squareness is now enforced on the canvas, through zone
  docking, through the settings form, and when an element is first created into a wide, shallow zone.
- Fixed: an element could be dragged off the page and could not be recovered with the mouse.
- Fixed: a locked element swallowed clicks, making anything beneath it unreachable.
- Fixed: adding a Text, Formatted text or Shape element produced an invisible element, because the element was
  created with empty data and the form defaults never applied.
- Fixed: an element's name was written into the layers panel as markup rather than as text, so a name containing a
  tag ran as script in the next author's browser.
- New: marquee selection, undo and redo buttons that grey out when there is nothing to undo, and an inspector that
  says how many elements are held instead of showing the last one's measurements.
- Fixed: nine overlapping layout zones in portrait and three in landscape, plus two pairs that rounded into overlap
  at Letter and Legal heights.
- Fixed: the layers panel was unstyled, because the panel emitted one set of class names and the stylesheet defined
  another.

### Fonts

- Fixed: the two administration pages, "Manage certificate fonts" and "Manage the shared media library", both
  returned HTTP 500 and could not be opened at all.
- Fixed: the Google Fonts installer had no caller anywhere in the plugin, so there was no way to install the families
  the bundled designs name. It is now reachable from the fonts page, which leads with what the site is missing and
  installs everything the designs need in one action, or any family by name.
- Fixed: the installer registered weight 700 as a separate family called, for example, "Lato 700", so asking for bold
  found no bold face and silently drew the regular one. A family now installs as one family with its regular, bold,
  italic and bold-italic faces, and a face the publisher does not offer is skipped cleanly.
- Fixed: styled faces were listed in the font picker as families in their own right, so one family appeared four
  times under the same name, three of them wrong to choose.
- New: an optional Google Fonts API key setting, which lets the font search reach the whole catalogue instead of the
  list bundled with the plugin.

### Issuing, revoking and reporting

- New: download every certificate an activity has issued as one zip. The `downloadall` capability shipped with
  nothing behind it. Each certificate is served as the document it was issued as, honouring its snapshot; 2,000
  certificates pack in about 11 seconds at flat memory. Expired certificates are included by default, revoked ones
  are not, and a revoked certificate that is included is named as revoked so it cannot be passed on as current.
- Fixed: revoking a certificate, issuing one by hand and deleting a file from the media library all happened on a
  single click with no confirmation. Revoking never asked why, so the reason shown to anyone checking the code was
  always empty. A reason is now required and appears on the public verification page.
- Fixed: the grade element printed nothing in a preview when the learner had no grade row, when a pass mark was not
  set, or when the grade range was zero.
- Fixed: the "Minimum course grade" setting redisplayed as `60.00000` after saving `60`.
- Fixed: the activity description was rendered twice on the activity page, with a duplicate DOM id.

### Packaging

- The plugin installs from the ZIP on Moodle 4.4 through 5.1.
- Fixed: a PHP notice raised by `cli/verify.php`, the verification tool shipped with the plugin, and a username
  collision that could make the tool fail on about one run in nine thousand.
- The language file carries no strings that are referenced but undefined, and none that nothing references.

## [1.2.0] - 2026-08-18

- The plugin supports Moodle 4.4 and later, rather than requiring Moodle 4.5.
- Removed the stock background and ornament libraries, and the artwork picker that went with them. The guided artwork
  and guided seal wizards replace them: both draw in the design's own palette, with no network access and no cost.
- The organisation seal is generated rather than sourced: nine edge treatments, four ring layouts and nine centre
  marks, with ring lettering tracked the way a seal engraver sets it and a contrast guard that stops a mark being
  drawn in a colour nobody could read against its field.
- The designer toolbar was rebuilt into labelled groups, and the stylesheet was hardened so a site theme cannot leave
  white text on a white canvas or hide an icon.
- Undo, redo, save and revert-to-original are on the toolbar and on the keyboard.
- Rotation snaps to 90 degrees by default, with free rotation on a modifier.

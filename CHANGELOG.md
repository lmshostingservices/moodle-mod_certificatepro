# Changelog

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

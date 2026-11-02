# Changelog

## [1.1.6] - 2026-11-02

Release pipeline fixes (no behaviour change).

- Blocker: the text size and required grade fields no longer use PARAM_RAW_TRIMMED; they use PARAM_TEXT and are still validated as numbers (localised decimals accepted).
- Closure declaration spacing (`function (`) in the issue notification code.
- Multi-line function calls reformatted so the opening parenthesis ends the line, one argument per line.
- AMD sources use `function(`; all seven build files and source maps regenerated.
- Logo setting description no longer reads as all-caps; one comment block now starts with a capital letter.

## [1.1.5] - 2026-11-02

Full feature test on a fresh Moodle 5.3 site (web services, designer UI, issuing, verification, tasks, backup).

- The public verification page (where the QR code points) sent visitors to the login page on Moodle 5.0+ sites, because new 5.x installs force login by default. Verification now stays public like core's badge pages; a new "Public verification page" setting lets a site require login instead.
- cli/verify.php used course_delete_module(), deprecated in Moodle 5.2; it now uses the course format actions where available.

## [1.1.4] - 2026-11-02

Tested on Moodle 4.5, 5.0, 5.1, 5.2 and 5.3 (PostgreSQL 16/17, PHP 8.3).

### Fixed
- Moodle 5.1+: adding or editing the activity failed with "60 is not one of the units allowed" (validity period duration had no valid default unit).
- Saving an element's settings always failed: the form posted the element id as the course module id.
- A blank text size was saved as 0 pt (invisible text); sizes are validated.
- A blank "required grade" was saved as 0, refusing certificates to anyone without a course grade. Upgrade step resets 0 to "no requirement".
- Minimum time in course was multiplied by 60 (2 hours required 120 hours).
- Moodle AI never worked on 5.0+ (core_ai manager constructor changed); it is now obtained through the DI container.
- Font and media management pages failed with "Section error!"; they are now registered admin pages.
- "Issued" notifications (learner, teachers, other addresses) were never sent; they are now sent and recorded.
- Teachers, managers and site admins were automatically issued certificates when opening the activity. A new mod/certificatepro:receive capability (students) controls who receives certificates; the report, scheduled task and mobile view use it.
- The scheduled task issued to suspended enrolments and to learners who cannot see the activity.
- Grade elements printed blank after backup/restore or course copy; grade item references are now remapped.
- Mobile app: download used a sesskey from the web-service session and always failed; it now uses the stored PDF via pluginfile, auto-issues like the web view and hides the button when there is no document.
- Blank pass/fail wording printed nothing.
- The verify web service ignored "verify all certificates" and cycle names (different result from verify.php).
- Brand colour for badges and Vecteezy credentials were read from the wrong config component; Vecteezy settings added.
- Lock timeouts and pending/unknown statuses showed [[...]] strings.
- Empty default on a NOT NULL column produced an XMLDB warning on install.

### Security
- Revocation was possible through a crafted POST even when the activity disallows it.
- Manual issue accepted any user id (deleted or not enrolled).
- The user-field element could print any user column (e.g. the password hash) via a restored or crafted design; only whitelisted fields are printed.
- Mobile template put raw HTML inside an attribute.
- Layer names were inserted into the designer with innerHTML.
- The designer's save_layout write service was exposed to the mobile app service.

### Other
- Privacy metadata now declares issuer/revoker, revocation reason, document hash, authoring references and messaging; deleting a user clears their issuer/revoker references.
- Events implement restore mappings.
- cli/verify.php (which creates data) now refuses to run without --yes-this-writes-to-this-site.
- New regression tests, including a full backup/restore round trip.

## [1.1.3] - 2026-10-12

- Deny previously copied PDF URLs after revocation for ordinary awards as well as cycle awards.

- Scale the designer's physical-unit text and artwork together with their element boxes.
- Prevent small canvas widths and zoom levels from clipping text that fits the PDF.
- Preserve issued documents and the existing evidence-bound issuance rules.

## [1.1.1] - 2026-10-12

- Support dedicated, evidence-bound certification-cycle awards.
- Preserve immutable historical PDF bytes and distinguish revoked and expired awards.
- Make concurrent issuance and retries reuse the same award identity.
- Prevent ordinary course issuance from bypassing a cycle-bound issuer.
- Preserve backed-up cycle documents as historical copies rather than rebinding them.
- Run seal-engine data providers on both legacy and modern PHPUnit versions.

<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_certificatepro\local;

use mod_certificatepro\element\render_context;
use mod_certificatepro\local\render\pdf_renderer;
use mod_certificatepro\template;

/**
 * Issues, stores, verifies and revokes certificates.
 *
 * The central decision here is that an issued certificate is a fixed document,
 * not a live query. At the moment of issue every value the design resolves is
 * written into a snapshot and the finished PDF is stored. Renaming the course
 * afterwards, editing the design, correcting a grade or deleting the activity
 * cannot change what an already issued certificate says, which is the whole
 * point of issuing one.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue_service {
    /** @var string The file area storing rendered certificates. */
    public const FILEAREA = 'issue';

    /** @var string The alphabet used for verification codes, chosen to avoid lookalikes. */
    protected const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Why this recipient cannot have a certificate yet.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $user the recipient
     * @param \stdClass $course the course
     * @param \cm_info|\stdClass $cm the course module
     * @return string[] translated reasons, empty when the recipient is eligible
     */
    public static function unmet_criteria(\stdClass $instance, \stdClass $user, \stdClass $course,
            $cm): array {
        global $CFG, $DB;

        $reasons = [];

        if (!empty($instance->requiredtime)) {
            $spent = course_helper::time_in_course((int) $course->id, (int) $user->id);
            $required = (int) $instance->requiredtime * MINSECS;
            if ($spent < $required) {
                $reasons[] = get_string('criteriontime', 'mod_certificatepro',
                    format_time($required));
            }
        }

        if ($instance->requiregrade !== null && $instance->requiregrade !== '') {
            require_once($CFG->libdir . '/gradelib.php');

            $item = \grade_item::fetch_course_item((int) $course->id);
            $achieved = null;
            if ($item) {
                $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id]);
                if ($grade && $grade->finalgrade !== null) {
                    $range = (float) $item->grademax - (float) $item->grademin;
                    $achieved = $range > 0
                        ? ((float) $grade->finalgrade - (float) $item->grademin) / $range * 100
                        : null;
                }
            }
            if ($achieved === null || $achieved < (float) $instance->requiregrade) {
                $reasons[] = get_string('criteriongrade', 'mod_certificatepro',
                    format_float((float) $instance->requiregrade, 1));
            }
        }

        if (!empty($instance->requirecompletion)) {
            $completion = $DB->get_record('course_completions',
                ['course' => $course->id, 'userid' => $user->id]);
            if (!$completion || empty($completion->timecompleted)) {
                $reasons[] = get_string('criterioncompletion', 'mod_certificatepro');
            }
        }

        return $reasons;
    }

    /**
     * The issue a recipient already holds, if any.
     *
     * @param int $certificateproid the activity instance id
     * @param int $userid the recipient
     * @return \stdClass|null
     */
    public static function get_issue(int $certificateproid, int $userid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('certificatepro_issues', [
            'certificateproid' => $certificateproid,
            'userid' => $userid,
        ]);

        return $record ?: null;
    }

    /**
     * Issue a certificate, or return the one the recipient already holds.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \context $context the module context
     * @param \stdClass $user the recipient
     * @param int $issuedby the user issuing it, or 0 when the system did
     * @return \stdClass the issue record
     */
    public static function issue(\stdClass $instance, \stdClass $course, \context $context,
            \stdClass $user, int $issuedby = 0): \stdClass {
        global $DB;

        $existing = self::get_issue((int) $instance->id, (int) $user->id);
        if ($existing !== null) {
            return $existing;
        }

        $now = time();

        $issue = new \stdClass();
        $issue->certificateproid = (int) $instance->id;
        $issue->userid = (int) $user->id;
        $issue->code = self::generate_code();
        $issue->serial = null;
        $issue->serialnumber = 0;
        $issue->snapshot = null;
        $issue->templaterevision = 0;
        $issue->pdfhash = null;
        $issue->emailed = 0;
        $issue->expires = !empty($instance->validityperiod)
            ? $now + (int) $instance->validityperiod
            : 0;
        $issue->expirynotified = 0;
        $issue->revoked = 0;
        $issue->revokedby = 0;
        $issue->revokedreason = null;
        $issue->timerevoked = 0;
        $issue->issuedby = $issuedby;
        $issue->timecreated = $now;

        if (!empty($instance->serialprefix)) {
            [$issue->serial, $issue->serialnumber] = self::next_serial(
                (string) $instance->serialprefix,
                (int) ($instance->serialpadding ?: 6),
                'instance_' . $instance->id
            );
        }

        $issue->id = $DB->insert_record('certificatepro_issues', $issue);

        self::materialise($instance, $course, $context, $user, $issue);

        \mod_certificatepro\event\certificate_issued::create([
            'objectid' => $issue->id,
            'context' => $context,
            'relateduserid' => $user->id,
            'other' => ['code' => $issue->code],
        ])->trigger();

        return $DB->get_record('certificatepro_issues', ['id' => $issue->id], '*', MUST_EXIST);
    }

    /**
     * Render the certificate once and store it, along with everything it resolved to.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \context $context the module context
     * @param \stdClass $user the recipient
     * @param \stdClass $issue the issue record, updated in place
     * @return void
     */
    protected static function materialise(\stdClass $instance, \stdClass $course, \context $context,
            \stdClass $user, \stdClass $issue): void {
        global $DB;

        try {
            $template = template::load((int) $instance->templateid);
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not load a design to issue: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return;
        }

        $ctx = render_context::for_issue($context, $instance, $course, $user, $issue);
        $renderer = new pdf_renderer($template);

        $content = $renderer->render($ctx, [
            'title' => format_string($instance->name, true, ['context' => $context]),
            'protection' => pdf_renderer::protection_flags($instance),
        ]);

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_certificatepro', self::FILEAREA, $issue->id);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_certificatepro',
            'filearea' => self::FILEAREA,
            'itemid' => $issue->id,
            'filepath' => '/',
            'filename' => self::filename($instance, $course, $user, $issue),
        ], $content);

        // The badge is drawn from the design's seal, and a design can be edited
        // after an issue is minted. Freeze the seal's settings alongside the
        // resolved values so a badge regenerated later is the badge issued.
        $sealdata = credential\badge_service::seal_data_for_template($template->get_id());
        if ($sealdata !== null) {
            $ctx->merge_snapshot([credential\badge_service::SNAPSHOT_KEY => $sealdata]);
        }

        $issue->snapshot = json_encode($ctx->get_snapshot(), JSON_PRESERVE_ZERO_FRACTION);
        $issue->templaterevision = (int) $template->get_record()->revision;
        $issue->pdfhash = hash('sha256', $content);

        $DB->update_record('certificatepro_issues', (object) [
            'id' => $issue->id,
            'snapshot' => $issue->snapshot,
            'templaterevision' => $issue->templaterevision,
            'pdfhash' => $issue->pdfhash,
        ]);
    }

    /**
     * The stored PDF for an issue, rendering it once if it is somehow missing.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \context $context the module context
     * @param \stdClass $user the recipient
     * @param \stdClass $issue the issue record
     * @return \stored_file|null
     */
    public static function get_file(\stdClass $instance, \stdClass $course, \context $context,
            \stdClass $user, \stdClass $issue): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_certificatepro', self::FILEAREA,
            $issue->id, 'id', false);

        if (!empty($files)) {
            return reset($files);
        }

        self::materialise($instance, $course, $context, $user, $issue);

        $files = $fs->get_area_files($context->id, 'mod_certificatepro', self::FILEAREA,
            $issue->id, 'id', false);

        return empty($files) ? null : reset($files);
    }

    /**
     * Revoke an issued certificate.
     *
     * @param \stdClass $issue the issue to revoke
     * @param \context $context the module context
     * @param string $reason why it was revoked
     * @return void
     */
    public static function revoke(\stdClass $issue, \context $context, string $reason): void {
        global $DB, $USER;

        $DB->update_record('certificatepro_issues', (object) [
            'id' => $issue->id,
            'revoked' => 1,
            'revokedby' => (int) $USER->id,
            'revokedreason' => $reason,
            'timerevoked' => time(),
        ]);

        \mod_certificatepro\event\certificate_revoked::create([
            'objectid' => $issue->id,
            'context' => $context,
            'relateduserid' => $issue->userid,
        ])->trigger();
    }

    /**
     * Find an issue by its verification code.
     *
     * Accepts the code with or without its grouping hyphens and in any case,
     * because people retype what they see printed and scanners add characters.
     *
     * @param string $code the code as typed or scanned
     * @return \stdClass|null
     */
    public static function find_by_code(string $code): ?\stdClass {
        global $DB;

        $normalised = self::normalise_code($code);
        if ($normalised === '') {
            return null;
        }

        $record = $DB->get_record('certificatepro_issues', ['code' => $normalised]);

        return $record ?: null;
    }

    /**
     * Reduce a typed code to its canonical stored form.
     *
     * @param string $code the code as typed
     * @return string
     */
    public static function normalise_code(string $code): string {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^0-9A-Z]/', '', $code) ?? '';

        if ($code === '') {
            return '';
        }

        // Restore the grouping used when the code was minted.
        if (strlen($code) === 14 && str_starts_with($code, 'CP')) {
            return 'CP-' . substr($code, 2, 4) . '-' . substr($code, 6, 4) . '-' . substr($code, 10, 4);
        }

        return $code;
    }

    /**
     * Mint a verification code that is unique across the site.
     *
     * @return string
     */
    public static function generate_code(): string {
        global $DB;

        $alphabet = self::CODE_ALPHABET;
        $length = strlen($alphabet);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $body = '';
            for ($i = 0; $i < 11; $i++) {
                $body .= $alphabet[random_int(0, $length - 1)];
            }
            $body .= self::check_character($body);

            $code = 'CP-' . substr($body, 0, 4) . '-' . substr($body, 4, 4) . '-' . substr($body, 8, 4);

            if (!$DB->record_exists('certificatepro_issues', ['code' => $code])) {
                return $code;
            }
        }

        // Astronomically unlikely, but never hand back a duplicate.
        throw new \moodle_exception('errorcodegeneration', 'mod_certificatepro');
    }

    /**
     * A check character, so an obvious typo is rejected before a database lookup.
     *
     * @param string $body the code body
     * @return string a single character from the code alphabet
     */
    protected static function check_character(string $body): string {
        $alphabet = self::CODE_ALPHABET;
        // A non-zero start means an all-zero body does not accidentally
        // produce a self-consistent code.
        $sum = 7;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $value = strpos($alphabet, $body[$i]);
            $sum += ($value === false ? 0 : $value) * ($i + 2);
        }

        return $alphabet[$sum % strlen($alphabet)];
    }

    /**
     * Whether a code's check character is consistent with its body.
     *
     * @param string $code a code in any form
     * @return bool
     */
    public static function code_looks_valid(string $code): bool {
        $normalised = self::normalise_code($code);
        $stripped = str_replace('-', '', $normalised);

        if (!str_starts_with($stripped, 'CP') || strlen($stripped) !== 14) {
            return false;
        }

        $body = substr($stripped, 2);

        return self::check_character(substr($body, 0, 11)) === substr($body, 11, 1);
    }

    /**
     * Take the next serial number in a scope.
     *
     * @param string $prefix the text placed before the number
     * @param int $padding how many digits the number is padded to
     * @param string $scope the counter to draw from
     * @return array{0: string, 1: int} the formatted serial and its numeric part
     */
    public static function next_serial(string $prefix, int $padding, string $scope): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            $record = $DB->get_record('certificatepro_serials', ['scope' => $scope]);

            if ($record) {
                $next = (int) $record->counter + 1;
                $DB->set_field('certificatepro_serials', 'counter', $next, ['id' => $record->id]);
            } else {
                $next = 1;
                $DB->insert_record('certificatepro_serials', (object) [
                    'scope' => $scope,
                    'counter' => $next,
                ]);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        $formatted = $prefix . str_pad((string) $next, max(1, $padding), '0', STR_PAD_LEFT);

        return [$formatted, $next];
    }

    /**
     * The file name a downloaded certificate is given.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \stdClass $user the recipient
     * @param \stdClass $issue the issue record
     * @return string
     */
    public static function filename(\stdClass $instance, \stdClass $course, \stdClass $user,
            \stdClass $issue): string {
        if (!empty($instance->usecustomfilename) && !empty($instance->customfilenamepattern)) {
            $replacements = [
                '{firstname}' => $user->firstname ?? '',
                '{lastname}' => $user->lastname ?? '',
                '{fullname}' => fullname($user),
                '{courseshortname}' => $course->shortname ?? '',
                '{coursefullname}' => $course->fullname ?? '',
                '{code}' => $issue->code ?? '',
                '{serial}' => $issue->serial ?? '',
                '{date}' => userdate($issue->timecreated ?? time(), '%Y-%m-%d'),
            ];
            $name = str_ireplace(array_keys($replacements), array_values($replacements),
                (string) $instance->customfilenamepattern);
        } else {
            $name = format_string($instance->name, true) . ' - ' . fullname($user);
        }

        return clean_filename(trim($name)) . '.pdf';
    }

    /**
     * Whether an issue has passed its expiry date.
     *
     * @param \stdClass $issue the issue to check
     * @return bool
     */
    public static function has_expired(\stdClass $issue): bool {
        return !empty($issue->expires) && (int) $issue->expires < time();
    }

    /**
     * A one word status for an issue.
     *
     * @param \stdClass $issue the issue to describe
     * @return string one of valid, expired or revoked
     */
    public static function status(\stdClass $issue): string {
        if (!empty($issue->revoked)) {
            return 'revoked';
        }
        if (self::has_expired($issue)) {
            return 'expired';
        }
        return 'valid';
    }
}

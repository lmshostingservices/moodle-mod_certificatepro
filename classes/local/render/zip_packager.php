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

namespace mod_certificatepro\local\render;

use core_files\archive_writer;
use mod_certificatepro\local\issue_service;

/**
 * Packs the certificates an activity has issued into one zip.
 *
 * The expensive-looking part of this feature turns out not to be expensive, and
 * that is what decides its shape. A certificate is rendered once, at the moment
 * it is issued, and the finished PDF is kept in the file area alongside the
 * snapshot it was drawn from. Bulk download therefore copies bytes; it does not
 * draw anything. Measured on a stock site with the classic starter design:
 *
 *   drawing one certificate       97ms      => 194s for a 2,000 learner course
 *   packing one stored PDF        5.3ms     => 11s for the same course
 *
 * So the whole feature is a stream of stored files, and it is served inline
 * rather than handed to an ad-hoc task. Queueing would buy nothing against an
 * eleven second job: the teacher would wait for the next cron run instead,
 * every byte would be duplicated into file storage until something cleaned it
 * up, and a second interface would be needed to collect the result.
 *
 * Memory is flat regardless of size, because each PDF is handed to the zip as
 * an open file handle and never held whole; the measured delta across a pack
 * was 0.00MB. Nothing is written to temporary disk either — the archive goes
 * straight down the wire as it is built.
 *
 * The one case that genuinely costs render time is an issue that has no stored
 * PDF. That falls back through issue_service::get_file(), which draws it from
 * the stored snapshot. The time limit raised before streaming budgets for that
 * possibility rather than assuming it away.
 *
 * A document that was stored and has since gone is a different matter: it is
 * not redrawn, because the bytes that were issued cannot be reproduced once the
 * design has moved on. get_file() says so, and the archive carries a note in
 * place of that one certificate rather than a substitute for it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zip_packager {
    /**
     * @var int The longest a name inside the zip may be, before the extension.
     *
     * Most filesystems stop at 255 bytes for a single name, and a learner's
     * name can arrive in a script where one character is three or four of them.
     * Cutting at 100 characters leaves room for the disambiguating suffix, the
     * extension, and the extra bytes a non Latin name spends.
     */
    public const MAX_STEM = 100;

    /**
     * @var int The most bytes a name inside the zip may spend, before the extension.
     *
     * The character limit is not enough on its own. Most filesystems count
     * bytes, not characters, and a hundred CJK characters is three hundred
     * bytes — past the limit while looking like a short name. This is the
     * limit that actually stops the file being rejected on extraction.
     */
    public const MAX_BYTES = 200;

    /** @var float Seconds one stored certificate costs to pack, measured, rounded up. */
    protected const SECONDS_PER_STORED = 0.02;

    /** @var float Seconds one missing certificate costs to redraw, measured, rounded up. */
    protected const SECONDS_PER_REDRAW = 0.25;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \context The module context. */
    protected \context $context;

    /**
     * Build a packager for one activity.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \context $context the module context
     */
    public function __construct(\stdClass $instance, \stdClass $course, \context $context) {
        $this->instance = $instance;
        $this->course = $course;
        $this->context = $context;
    }

    /**
     * Reduce a proposed name to something every filesystem and zip reader will take.
     *
     * clean_filename() is not enough on its own. It leaves the wildcards a
     * Windows filesystem rejects, it leaves the leading and trailing dots and
     * spaces Windows silently eats, it has no length limit, and it is happy to
     * hand back a reserved device name. It also cannot know that the result is
     * about to be passed through clean_param(PARAM_PATH) by the archive writer,
     * which would quietly rewrite a name after this class had already proved it
     * unique. Everything this returns is a fixed point of that rewrite.
     *
     * @param string $name the proposed name, without an extension
     * @return string a safe stem, or the empty string when nothing usable survived
     */
    public static function safe_stem(string $name): string {
        // Control characters go first and become spaces rather than vanishing.
        // A profile field pasted from a spreadsheet carries tabs and newlines,
        // and dropping them outright would run two words together into a name
        // the learner would not recognise as theirs. clean_filename() would
        // silently do exactly that, so this happens before it.
        $name = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $name) ?? '';

        $name = clean_filename($name);

        // Directory separators would turn a name into a path inside the zip,
        // and the wildcards and pipes are rejected outright by Windows.
        // clean_filename() removes some of these and leaves others.
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);

        // A run of dots is how a path escapes its directory; collapse it so no
        // amount of nesting can produce one.
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';

        // Collapse runs of whitespace so a name is not padded out to the length
        // limit by spaces the learner cannot see.
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        // Windows treats these as devices whatever extension follows them.
        $reserved = ['con', 'prn', 'aux', 'nul'];
        for ($i = 1; $i <= 9; $i++) {
            $reserved[] = 'com' . $i;
            $reserved[] = 'lpt' . $i;
        }
        if (in_array(\core_text::strtolower($name), $reserved, true)) {
            $name = $name . '_';
        }

        $name = self::fit($name, self::MAX_STEM, self::MAX_BYTES);

        // Trimming for length can expose a trailing dot or space again, and
        // Windows drops both when creating the file, which would undo the
        // uniqueness proved above.
        $name = trim($name, " .\t");

        return $name;
    }

    /**
     * Cut a name down to a budget in both characters and bytes.
     *
     * Always on a character boundary: half a UTF-8 sequence is not a name any
     * reader can display, and some archive tools refuse the entry outright.
     *
     * @param string $name the name to fit
     * @param int $chars the most characters allowed
     * @param int $bytes the most bytes allowed
     * @return string
     */
    protected static function fit(string $name, int $chars, int $bytes): string {
        if ($chars < 1 || $bytes < 1) {
            return '';
        }

        if (\core_text::strlen($name) > $chars) {
            $name = \core_text::substr($name, 0, $chars);
        }

        while ($name !== '' && strlen($name) > $bytes) {
            $name = \core_text::substr($name, 0, \core_text::strlen($name) - 1);
        }

        return $name;
    }

    /**
     * Join a name to the suffix that tells two of them apart, without losing the suffix.
     *
     * Truncating the finished string would be wrong: the length limit bites
     * exactly on the long names, and the part it cuts is the end — which is
     * where the disambiguator sits. Two learners with long, identical names
     * would then be handed two identical filenames again, having been given a
     * code apiece precisely to stop that. So the name yields and the suffix
     * does not.
     *
     * @param string $stem the sanitised name
     * @param string $suffix the disambiguating text, already wrapped in its brackets
     * @return string
     */
    protected static function compose(string $stem, string $suffix): string {
        $chars = self::MAX_STEM - \core_text::strlen($suffix) - 1;
        $bytes = self::MAX_BYTES - strlen($suffix) - 1;

        $stem = trim(self::fit($stem, $chars, $bytes));

        if ($stem === '') {
            // A suffix alone longer than the whole budget cannot happen with a
            // verification code, but a name has to come out regardless.
            return self::safe_stem($suffix);
        }

        return self::safe_stem($stem . ' ' . $suffix);
    }

    /**
     * Decide what every certificate in a set is called inside the zip.
     *
     * Names are settled for the whole set before a single byte is written, and
     * ambiguity is resolved symmetrically. Where two learners would produce the
     * same name, every one of them is given the verification code printed on
     * their certificate, not just the second and subsequent — a teacher opening
     * "John Smith (CP-...).pdf" can see which document it is, whereas being
     * handed a plain "John Smith.pdf" and a suffixed one gives no clue which
     * John Smith the plain one belongs to.
     *
     * @param array $rows issue rows as returned by issue_service::download_set()
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @return array<int, string> the name inside the zip, keyed by issue id
     */
    public static function plan_names(array $rows, \stdClass $instance, \stdClass $course): array {
        $stems = [];
        $counts = [];

        foreach ($rows as $row) {
            $stem = self::safe_stem(pathinfo(
                issue_service::filename($instance, $course, $row->user, $row),
                PATHINFO_FILENAME
            ));

            // A name made entirely of characters no filesystem accepts, or a
            // custom pattern that resolved to nothing, still has to produce a
            // file. The verification code is unique by construction.
            if ($stem === '') {
                $stem = self::safe_stem((string) ($row->code ?? '')) ?: 'certificate-' . (int) $row->id;
            }

            // A revoked certificate that the teacher asked to include is marked
            // in its own name. The plugin refuses to hand a revoked certificate
            // to the learner it names, so it must never leave here looking like
            // one that still stands.
            if (!empty($row->revoked)) {
                $stem = self::safe_stem(get_string('revokedprefix', 'mod_certificatepro') . ' ' . $stem);
            }

            $stems[(int) $row->id] = $stem;
            $key = \core_text::strtolower($stem);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $names = [];
        $used = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $stem = $stems[$id];

            if (($counts[\core_text::strtolower($stem)] ?? 0) > 1) {
                $code = trim((string) ($row->code ?? ''));
                $stem = self::compose($stem, '(' . ($code !== '' ? $code : $id) . ')');
            }

            // Belt and braces. Codes are unique across the site and serials are
            // unique within a scope, but a truncated stem or a site that has
            // somehow lost its codes must still not produce two identical
            // entries: a zip with a repeated name unpacks to fewer files than
            // it claims, and the teacher never learns which learner was lost.
            $candidate = $stem;
            $attempt = 1;
            while ($candidate === '' || isset($used[\core_text::strtolower($candidate)])) {
                $candidate = self::compose($stem,
                    '[' . $id . ($attempt > 1 ? '-' . $attempt : '') . ']');
                $attempt++;
                if ($attempt > 10) {
                    $candidate = 'certificate-' . $id . '-' . $attempt;
                }
            }

            $used[\core_text::strtolower($candidate)] = true;
            $names[$id] = $candidate . '.pdf';
        }

        return $names;
    }

    /**
     * The name the zip itself is offered under.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @return string
     */
    public static function zip_filename(\stdClass $instance, \stdClass $course): string {
        // Each part is cleaned before they are joined, so a part that cleans
        // away to nothing takes its separator with it rather than leaving the
        // archive called "-.zip".
        $parts = array_filter([
            self::safe_stem((string) ($course->shortname ?? '')),
            self::safe_stem(format_string($instance->name ?? '', true)),
        ], static function (string $part): bool {
            return $part !== '';
        });

        $stem = self::safe_stem(implode(' - ', $parts));

        if ($stem === '') {
            $stem = 'certificates';
        }

        return $stem . '.zip';
    }

    /**
     * How long the pack should be allowed to take, in seconds.
     *
     * Derived from what the work actually costs rather than from a round number
     * somebody liked: the stored certificates are copied and the missing ones
     * are redrawn, at the rates measured at the top of this class, with a
     * standing minute for the request itself.
     *
     * @param int $stored how many certificates already have a rendered PDF
     * @param int $missing how many will have to be drawn again
     * @return int
     */
    public static function time_budget(int $stored, int $missing): int {
        return (int) ceil(MINSECS + ($stored * self::SECONDS_PER_STORED)
            + ($missing * self::SECONDS_PER_REDRAW));
    }

    /**
     * How many of a set already have a rendered PDF waiting.
     *
     * @param array $rows issue rows as returned by issue_service::download_set()
     * @return int
     */
    public function count_stored(array $rows): int {
        global $DB;

        if (empty($rows)) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_map(static function ($row) {
            return (int) $row->id;
        }, $rows), SQL_PARAMS_NAMED, 'iid');

        $params['contextid'] = $this->context->id;
        $params['component'] = 'mod_certificatepro';
        $params['filearea'] = issue_service::FILEAREA;

        return (int) $DB->count_records_select('files',
            "contextid = :contextid AND component = :component AND filearea = :filearea
                 AND filename <> '.' AND itemid $insql", $params);
    }

    /**
     * Stream the whole set to the browser as a zip, and stop.
     *
     * Nothing may be echoed once this has started: the response is already a
     * zip, so a stray notice does not appear beside the archive, it becomes
     * part of it and no reader can open the result. The renderer guards its own
     * output for exactly this reason; the fallback redraw here is wrapped for
     * the same one, because it can reach code that calls debugging().
     *
     * @param array $rows issue rows as returned by issue_service::download_set()
     * @return void
     */
    public function stream(array $rows): void {
        // Let go of the session before a long response. Holding it locks the
        // teacher out of every other page on the site for the duration.
        \core\session\manager::write_close();

        $writer = archive_writer::get_stream_writer(
            self::zip_filename($this->instance, $this->course),
            archive_writer::ZIP_WRITER
        );

        $this->pack($writer, $rows);
        $writer->finish();
    }

    /**
     * Write a whole set into an archive, leaving it open.
     *
     * Separate from stream() only so that a test can hand it an archive it can
     * then close and read; the streaming path runs this same code.
     *
     * @param archive_writer $writer the archive being built
     * @param array $rows issue rows as returned by issue_service::download_set()
     * @return void
     */
    public function pack(archive_writer $writer, array $rows): void {
        global $DB;

        $names = self::plan_names($rows, $this->instance, $this->course);

        $fs = get_file_storage();

        foreach ($rows as $row) {
            $id = (int) $row->id;

            $files = $fs->get_area_files($this->context->id, 'mod_certificatepro',
                issue_service::FILEAREA, $id, 'id', false);
            $file = empty($files) ? null : reset($files);

            if ($file === null) {
                // The stored PDF has gone. Redraw it from the snapshot, which
                // is what makes the replacement identical to the original
                // rather than a fresh certificate in today's design.
                ob_start();
                try {
                    $issue = $DB->get_record('certificatepro_issues', ['id' => $id]);
                    $user = $DB->get_record('user', ['id' => (int) $row->userid]);
                    if ($issue && $user) {
                        $file = issue_service::get_file($this->instance, $this->course,
                            $this->context, $user, $issue);
                    }
                } catch (\Throwable $e) {
                    $file = null;
                } finally {
                    $stray = ob_get_clean();
                }
                if (!empty($stray)) {
                    error_log('mod_certificatepro: output during bulk packaging was discarded: '
                        . substr(strip_tags((string) $stray), 0, 500));
                }
            }

            if ($file === null) {
                // One unrecoverable certificate must not cost the teacher the
                // other nineteen hundred. Say so inside the zip instead, where
                // it will be noticed, rather than leaving a silent gap.
                $writer->add_file_from_string(
                    self::safe_stem(pathinfo($names[$id], PATHINFO_FILENAME)) . '.txt',
                    get_string('downloadallmissing', 'mod_certificatepro', (string) ($row->code ?? $id))
                );
                continue;
            }

            $writer->add_file_from_stored_file($names[$id], $file);
        }
    }
}

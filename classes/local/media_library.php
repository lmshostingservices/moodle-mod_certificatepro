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

/**
 * A shared library of pictures every certificate on the site can draw from.
 *
 * Uploading the same signature into thirty certificates is how a site ends up
 * with thirty slightly different signatures and no way to replace them all when
 * the signatory leaves. A picture is uploaded once here, given a name and a
 * kind, and then chosen by name wherever it is needed. Replacing the file
 * updates every certificate that references it.
 *
 * Pictures live in the system context, so they survive course deletion and are
 * managed in one place by whoever holds the site level capability.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media_library {
    /** @var string The file area holding shared pictures. */
    public const FILEAREA = 'media';

    /** @var string A logo or organisation mark. */
    public const KIND_LOGO = 'logo';

    /** @var string A scanned or drawn signature. */
    public const KIND_SIGNATURE = 'signature';

    /** @var string An award seal or medallion. */
    public const KIND_SEAL = 'seal';

    /** @var string A full page background. */
    public const KIND_BACKGROUND = 'background';

    /** @var string Anything else. */
    public const KIND_OTHER = 'other';

    /**
     * The kinds a picture can be filed under.
     *
     * @return array<string, string> keyed by kind, each value a translated name
     */
    public static function kinds(): array {
        return [
            self::KIND_LOGO => get_string('mediakind_logo', 'mod_certificatepro'),
            self::KIND_SIGNATURE => get_string('mediakind_signature', 'mod_certificatepro'),
            self::KIND_SEAL => get_string('mediakind_seal', 'mod_certificatepro'),
            self::KIND_BACKGROUND => get_string('mediakind_background', 'mod_certificatepro'),
            self::KIND_OTHER => get_string('mediakind_other', 'mod_certificatepro'),
        ];
    }

    /**
     * Every picture in the library, newest first.
     *
     * @param string|null $kind limit to one kind, or null for all
     * @return \stdClass[] keyed by id
     */
    public static function all(?string $kind = null): array {
        global $DB;

        $conditions = $kind !== null ? ['kind' => $kind] : null;

        return $DB->get_records('certificatepro_media', $conditions, 'name ASC');
    }

    /**
     * A selector menu of the library, optionally limited to one kind.
     *
     * @param string|null $kind limit to one kind, or null for all
     * @param bool $includenone whether to offer a none option first
     * @return array<int, string>
     */
    public static function menu(?string $kind = null, bool $includenone = true): array {
        $menu = $includenone ? [0 => get_string('medianone', 'mod_certificatepro')] : [];

        foreach (self::all($kind) as $record) {
            $menu[(int) $record->id] = format_string($record->name);
        }

        return $menu;
    }

    /**
     * One library record.
     *
     * @param int $mediaid the record to fetch
     * @return \stdClass|null
     */
    public static function get(int $mediaid): ?\stdClass {
        global $DB;

        if ($mediaid <= 0) {
            return null;
        }

        $record = $DB->get_record('certificatepro_media', ['id' => $mediaid]);

        return $record ?: null;
    }

    /**
     * The stored file behind a library record.
     *
     * @param int $mediaid the record to fetch
     * @return \stored_file|null
     */
    public static function file(int $mediaid): ?\stored_file {
        if ($mediaid <= 0) {
            return null;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'mod_certificatepro',
            self::FILEAREA,
            $mediaid,
            'filename',
            false
        );

        if (empty($files)) {
            return null;
        }

        return reset($files);
    }

    /**
     * A library picture copied to a temporary path a PDF library can read.
     *
     * @param int $mediaid the record to fetch
     * @return string|null an absolute path, or null when there is no such picture
     */
    public static function path(int $mediaid): ?string {
        $file = self::file($mediaid);

        if ($file === null) {
            return null;
        }

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) ?: 'png';
        $path = make_request_directory() . '/media' . $mediaid . '.' . $extension;
        $file->copy_content_to($path);

        return $path;
    }

    /**
     * A browser URL for a library picture.
     *
     * @param int $mediaid the record to fetch
     * @return string|null
     */
    public static function url(int $mediaid): ?string {
        $file = self::file($mediaid);

        if ($file === null) {
            return null;
        }

        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * The pixel dimensions recorded for a library picture.
     *
     * @param int $mediaid the record to fetch
     * @return array{0: int, 1: int} width then height, both zero when unknown
     */
    public static function dimensions(int $mediaid): array {
        $record = self::get($mediaid);

        if ($record === null) {
            return [0, 0];
        }

        return [(int) $record->pixelwidth, (int) $record->pixelheight];
    }

    /**
     * Add a picture to the library, or replace the file behind an existing one.
     *
     * @param \stored_file $file the uploaded picture
     * @param string $name the name authors will choose it by
     * @param string $kind a key from {@see self::kinds()}
     * @param int $mediaid an existing record to replace, or 0 to create one
     * @return int the library record id
     */
    public static function save(\stored_file $file, string $name, string $kind,
            int $mediaid = 0): int {
        global $DB, $USER;

        $context = \context_system::instance();
        $kinds = self::kinds();
        $kind = array_key_exists($kind, $kinds) ? $kind : self::KIND_OTHER;

        if ($mediaid > 0 && $DB->record_exists('certificatepro_media', ['id' => $mediaid])) {
            $record = $DB->get_record('certificatepro_media', ['id' => $mediaid], '*', MUST_EXIST);
            $record->name = $name;
            $record->kind = $kind;
        } else {
            $record = new \stdClass();
            $record->name = $name;
            $record->kind = $kind;
            $record->pixelwidth = 0;
            $record->pixelheight = 0;
            $record->createdby = (int) $USER->id;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->id = $DB->insert_record('certificatepro_media', $record);
        }

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_certificatepro', self::FILEAREA, $record->id);
        $stored = $fs->create_file_from_storedfile(
            [
                'contextid' => $context->id,
                'component' => 'mod_certificatepro',
                'filearea' => self::FILEAREA,
                'itemid' => $record->id,
                'filepath' => '/',
                'filename' => $file->get_filename(),
            ],
            $file
        );

        // Record the intrinsic size once, so every certificate that uses this
        // picture can size itself correctly without re-reading the file.
        $info = $stored->get_imageinfo();
        $record->pixelwidth = !empty($info['width']) ? (int) $info['width'] : 0;
        $record->pixelheight = !empty($info['height']) ? (int) $info['height'] : 0;
        $record->timemodified = time();
        $DB->update_record('certificatepro_media', $record);

        return (int) $record->id;
    }

    /**
     * Remove a picture from the library.
     *
     * @param int $mediaid the record to remove
     * @return void
     */
    public static function delete(int $mediaid): void {
        global $DB;

        $fs = get_file_storage();
        $fs->delete_area_files(
            \context_system::instance()->id,
            'mod_certificatepro',
            self::FILEAREA,
            $mediaid
        );

        $DB->delete_records('certificatepro_media', ['id' => $mediaid]);
    }

    /**
     * How many certificate elements currently point at a library picture.
     *
     * Shown before deletion, so nobody removes a signature that is still in use.
     *
     * @param int $mediaid the record to count
     * @return int
     */
    public static function usage_count(int $mediaid): int {
        global $DB;

        $like = $DB->sql_like('data', ':needle');
        $params = ['needle' => '%"mediaid":' . $mediaid . '%'];

        return $DB->count_records_select('certificatepro_elements', $like, $params);
    }

    /**
     * The physical size a picture should be drawn at, in millimetres.
     *
     * Given a box, this keeps the picture's own proportions and returns the
     * largest size that fits. Given only one dimension, it derives the other.
     * A picture with no known dimensions falls back to 150 dots per inch, which
     * is a sensible print density rather than an arbitrary guess.
     *
     * @param int $pixelwidth the picture width in pixels
     * @param int $pixelheight the picture height in pixels
     * @param float $boxwidth the available width in millimetres, or 0
     * @param float $boxheight the available height in millimetres, or 0
     * @param string $fitmode contain, cover or stretch
     * @return array{0: float, 1: float} width then height in millimetres
     */
    public static function fit(int $pixelwidth, int $pixelheight, float $boxwidth,
            float $boxheight, string $fitmode = 'contain'): array {
        if ($pixelwidth <= 0 || $pixelheight <= 0) {
            return [max(1.0, $boxwidth), max(1.0, $boxheight)];
        }

        $ratio = $pixelwidth / $pixelheight;

        if ($boxwidth <= 0 && $boxheight <= 0) {
            return [$pixelwidth / 150 * 25.4, $pixelheight / 150 * 25.4];
        }
        if ($boxwidth <= 0) {
            return [round($boxheight * $ratio, 3), $boxheight];
        }
        if ($boxheight <= 0) {
            return [$boxwidth, round($boxwidth / $ratio, 3)];
        }

        if ($fitmode === 'stretch') {
            return [$boxwidth, $boxheight];
        }

        $bybox = $boxwidth / $boxheight;

        if ($fitmode === 'cover') {
            // Fill the box, overflowing on the axis that does not fit.
            return $ratio > $bybox
                ? [round($boxheight * $ratio, 3), $boxheight]
                : [$boxwidth, round($boxwidth / $ratio, 3)];
        }

        // Contain: the whole picture inside the box, nothing cropped.
        return $ratio > $bybox
            ? [$boxwidth, round($boxwidth / $ratio, 3)]
            : [round($boxheight * $ratio, 3), $boxheight];
    }
}

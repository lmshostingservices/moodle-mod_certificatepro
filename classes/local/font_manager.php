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
 * Installs, catalogues and serves the fonts a certificate can be set in.
 *
 * Two problems are solved here. The first is that a stock Moodle ships very
 * few embeddable fonts, which is why certificates in Arabic, Hindi, Thai or
 * Chinese so often come out as rows of question marks; an administrator can
 * upload a font through the browser and this class converts it for embedding
 * without anyone needing shell access. The second is that the designer and the
 * PDF must agree on how wide a word is, so the original font file is kept and
 * served to the browser as a web font: both sides then measure with the same
 * outlines.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class font_manager {
    /** @var string The file area holding original uploaded font files. */
    public const FILEAREA = 'font';

    /** @var string[] Font families that cannot represent anything beyond Latin 1. */
    public const LEGACY_FAMILIES = ['courier', 'helvetica', 'times', 'symbol', 'zapfdingbats'];

    /**
     * Every font family available for use, keyed by family with a display name.
     *
     * @param bool $unicodeonly true to hide the legacy single byte families
     * @return array<string, string>
     */
    public static function catalogue(bool $unicodeonly = true): array {
        global $CFG, $DB;

        $cache = \cache::make('mod_certificatepro', 'fontcatalogue');
        $key = $unicodeonly ? 'unicode' : 'all';
        $cached = $cache->get($key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        require_once($CFG->libdir . '/pdflib.php');

        $catalogue = [];

        $pdf = new \pdf();
        foreach ($pdf->get_font_families() as $family => $styles) {
            $family = strtolower($family);
            if ($unicodeonly && in_array($family, self::LEGACY_FAMILIES, true)) {
                continue;
            }
            $catalogue[$family] = self::prettify($family);
        }
        unset($pdf);

        $records = $DB->get_records('certificatepro_fonts', null, 'displayname ASC');
        foreach ($records as $record) {
            $catalogue[strtolower($record->family)] = $record->displayname;
        }

        ksort($catalogue);
        $cache->set($key, $catalogue);

        return $catalogue;
    }

    /**
     * The family keys available for use.
     *
     * @return string[]
     */
    public static function installed_families(): array {
        return array_keys(self::catalogue(false));
    }

    /**
     * Whether a family exists on this site.
     *
     * @param string $family the family key
     * @return bool
     */
    public static function is_installed(string $family): bool {
        return array_key_exists(strtolower($family), self::catalogue(false));
    }

    /**
     * The TCPDF family key a display name maps to.
     *
     * Split out from {@see sanitise_family()} because that method falls back to
     * an already installed family when the name is unknown, which is exactly
     * the wrong behaviour during installation: at that moment the family is by
     * definition not installed yet.
     *
     * @param string $displayname the human facing family name
     * @return string the key, or an empty string when nothing survives
     */
    public static function family_key(string $displayname): string {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $displayname) ?? '');
    }

    /**
     * Reduce an arbitrary string to a safe TCPDF family key, falling back when unknown.
     *
     * @param string $family the requested family
     * @return string a family that exists on this site
     */
    public static function sanitise_family(string $family): string {
        $family = self::family_key($family);
        if ($family !== '' && self::is_installed($family)) {
            return $family;
        }
        return typography::FALLBACK_SERIF;
    }

    /**
     * Turn a family key into something a person would recognise.
     *
     * @param string $family the family key
     * @return string
     */
    protected static function prettify(string $family): string {
        $known = [
            'freeserif' => 'Free Serif',
            'freesans' => 'Free Sans',
            'freemono' => 'Free Mono',
            'dejavusans' => 'DejaVu Sans',
            'dejavuserif' => 'DejaVu Serif',
            'dejavusansmono' => 'DejaVu Sans Mono',
            'helvetica' => 'Helvetica (Latin only)',
            'times' => 'Times (Latin only)',
            'courier' => 'Courier (Latin only)',
        ];
        if (isset($known[$family])) {
            return $known[$family];
        }
        return ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $family) ?? $family);
    }

    /**
     * The CSS font stack the designer should use for a family.
     *
     * @param string $family the family key
     * @return string a CSS font-family value
     */
    public static function css_font_stack(string $family): string {
        $family = strtolower($family);
        $generic = match (true) {
            str_contains($family, 'mono') => 'monospace',
            str_contains($family, 'sans') => 'sans-serif',
            default => 'serif',
        };
        return '"cpro-' . $family . '", ' . $generic;
    }

    /**
     * The at font face rules that let the browser measure text exactly as the PDF will.
     *
     * @return string CSS
     */
    public static function web_font_css(): string {
        global $DB;

        $css = '';
        $records = $DB->get_records('certificatepro_fonts');
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;

        foreach ($records as $record) {
            $files = $fs->get_area_files($contextid, 'mod_certificatepro', self::FILEAREA,
                $record->id, 'filename', false);
            if (empty($files)) {
                continue;
            }
            $file = reset($files);
            $url = \moodle_url::make_pluginfile_url(
                $contextid,
                'mod_certificatepro',
                self::FILEAREA,
                $record->id,
                $file->get_filepath(),
                $file->get_filename()
            );
            $weight = str_contains($record->style, 'B') ? '700' : '400';
            $fontstyle = str_contains($record->style, 'I') ? 'italic' : 'normal';
            $css .= '@font-face{font-family:"cpro-' . strtolower($record->family) . '";'
                . 'src:url("' . $url->out(false) . '");'
                . 'font-weight:' . $weight . ';font-style:' . $fontstyle . ';font-display:block;}' . "\n";
        }

        return $css;
    }

    /**
     * Install a font from an uploaded file so it can be embedded in PDFs.
     *
     * @param \stored_file $file the uploaded TrueType or OpenType file
     * @param string $displayname the name to show authors
     * @param string $style empty, B, I or BI
     * @return int the id of the new catalogue record
     * @throws \moodle_exception when the font cannot be converted
     */
    public static function install(\stored_file $file, string $displayname, string $style = ''): int {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/pdflib.php');

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['ttf', 'otf'], true)) {
            throw new \moodle_exception('errorfonttype', 'mod_certificatepro');
        }

        $targetdirectory = self::custom_font_directory();

        // TCPDF derives the family key from the FILE NAME, not from anything we
        // pass it. So the file has to be named after the family we want, or a
        // download called Lora-Variable.ttf registers as "loravariable" and
        // nothing that asks for "Lora" will ever find it. Renaming here makes
        // the round trip exact: the key TCPDF produces is the same key
        // sanitise_family() computes from the display name.
        $temporary = make_request_directory() . '/'
            . self::family_key($displayname) . ($style !== '' ? strtolower($style) : '')
            . '.' . $extension;
        $file->copy_content_to($temporary);

        $family = \TCPDF_FONTS::addTTFfont($temporary, '', '', 32, $targetdirectory);
        if ($family === false || $family === '') {
            throw new \moodle_exception('errorfontconvert', 'mod_certificatepro');
        }

        $existing = $DB->get_record('certificatepro_fonts', ['family' => $family, 'style' => $style]);
        if ($existing) {
            $record = $existing;
            $record->displayname = $displayname;
        } else {
            $record = new \stdClass();
            $record->family = $family;
            $record->displayname = $displayname;
            $record->style = $style;
            $record->scripts = '';
            $record->glyphcount = 0;
            $record->builtin = 0;
            $record->uploadedby = $USER->id;
            $record->timecreated = time();
            $record->id = $DB->insert_record('certificatepro_fonts', $record);
        }

        $record->scripts = implode(',', self::detect_scripts($temporary));
        $DB->update_record('certificatepro_fonts', $record);

        // Keep the original file so the designer can load it as a web font.
        $fs = get_file_storage();
        $fs->delete_area_files(\context_system::instance()->id, 'mod_certificatepro',
            self::FILEAREA, $record->id);
        $fs->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_certificatepro',
            'filearea' => self::FILEAREA,
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ], $file);

        self::purge_cache();

        return (int) $record->id;
    }

    /**
     * Install a font that is already on disk rather than uploaded through a form.
     *
     * Used by the Google Fonts installer, which downloads a face to a temporary
     * file. The file is wrapped as a stored file and passed through the normal
     * install path, so a downloaded font gets exactly the same conversion,
     * script detection and catalogue record as one an administrator uploaded by
     * hand — there is no second, weaker code path to keep in step.
     *
     * @param string $path the file on disk
     * @param string $displayname the name to show authors
     * @param string $style empty, B, I or BI
     * @return int the id of the new catalogue record
     * @throws \moodle_exception when the file is missing or cannot be converted
     */
    public static function install_from_path(string $path, string $displayname,
            string $style = ''): int {
        if (!is_readable($path)) {
            throw new \moodle_exception('errorfontmissing', 'mod_certificatepro');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['ttf', 'otf'], true)) {
            throw new \moodle_exception('errorfonttype', 'mod_certificatepro');
        }

        $fs = get_file_storage();
        $context = \context_system::instance();

        // Item zero is never a catalogue record id, so it is safe to use as a
        // scratch slot for the handover.
        $record = [
            'contextid' => $context->id,
            'component' => 'mod_certificatepro',
            'filearea' => self::FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => clean_param(basename($path), PARAM_FILE),
        ];

        $fs->delete_area_files($context->id, 'mod_certificatepro', self::FILEAREA, 0);
        $file = $fs->create_file_from_pathname($record, $path);

        try {
            $id = self::install($file, $displayname, $style);
        } finally {
            $fs->delete_area_files($context->id, 'mod_certificatepro', self::FILEAREA, 0);
        }

        return $id;
    }

    /**
     * Remove an installed font from the catalogue.
     *
     * @param int $fontid the catalogue record id
     * @return void
     */
    public static function uninstall(int $fontid): void {
        global $DB;

        $record = $DB->get_record('certificatepro_fonts', ['id' => $fontid], '*', MUST_EXIST);
        if (!empty($record->builtin)) {
            throw new \moodle_exception('errorfontbuiltin', 'mod_certificatepro');
        }

        $fs = get_file_storage();
        $fs->delete_area_files(\context_system::instance()->id, 'mod_certificatepro',
            self::FILEAREA, $record->id);

        $DB->delete_records('certificatepro_fonts', ['id' => $fontid]);

        self::purge_cache();
    }

    /**
     * The directory converted fonts are written to.
     *
     * Moodle only uses a custom directory when it holds a copy of the core
     * fonts as well, so this seeds it on first use.
     *
     * @return string an absolute path with a trailing slash
     */
    public static function custom_font_directory(): string {
        global $CFG;

        $directory = defined('PDF_CUSTOM_FONT_PATH')
            ? PDF_CUSTOM_FONT_PATH
            : $CFG->dataroot . '/fonts/';

        if (!is_dir($directory)) {
            make_writable_directory($directory);
        }

        // Seed the directory with the core fonts the first time we use it,
        // otherwise Moodle ignores it entirely and nothing new is found.
        $marker = $directory . '.certificatepro-seeded';
        if (!file_exists($marker)) {
            $source = $CFG->libdir . '/tcpdf/fonts/';
            if (is_dir($source)) {
                foreach (glob($source . '*') ?: [] as $path) {
                    $target = $directory . basename($path);
                    if (is_file($path) && !file_exists($target)) {
                        @copy($path, $target);
                    }
                }
            }
            @file_put_contents($marker, (string) time());
        }

        return $directory;
    }

    /**
     * Whether the custom font directory is usable on this site.
     *
     * @return bool
     */
    public static function can_install_fonts(): bool {
        global $CFG;

        $directory = defined('PDF_CUSTOM_FONT_PATH')
            ? PDF_CUSTOM_FONT_PATH
            : $CFG->dataroot . '/fonts/';

        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return is_writable(dirname(rtrim($directory, '/')));
    }

    /**
     * The unicode scripts a font file appears to cover.
     *
     * The check is deliberately cheap: it samples one representative code point
     * per script from the font's character map rather than parsing every table.
     *
     * @param string $path an absolute path to a font file
     * @return string[] script identifiers
     */
    public static function detect_scripts(string $path): array {
        $samples = [
            'latin' => 0x0041,
            'latinext' => 0x0100,
            'greek' => 0x0391,
            'cyrillic' => 0x0410,
            'arabic' => 0x0627,
            'hebrew' => 0x05D0,
            'devanagari' => 0x0915,
            'bengali' => 0x0995,
            'tamil' => 0x0B95,
            'thai' => 0x0E01,
            'burmese' => 0x1000,
            'georgian' => 0x10A0,
            'armenian' => 0x0531,
            'khmer' => 0x1780,
            'hangul' => 0xAC00,
            'hiragana' => 0x3042,
            'han' => 0x4E00,
        ];

        $found = [];
        try {
            $map = self::read_character_map($path);
            if ($map === null) {
                return [];
            }
            foreach ($samples as $script => $codepoint) {
                if (isset($map[$codepoint])) {
                    $found[] = $script;
                }
            }
        } catch (\Throwable $e) {
            debugging('Could not inspect font ' . $path . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }

        return $found;
    }

    /**
     * Read a TrueType character map into an array of code points.
     *
     * @param string $path an absolute path to a font file
     * @return array<int, bool>|null the code points present, or null when unreadable
     */
    protected static function read_character_map(string $path): ?array {
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) < 12) {
            return null;
        }

        $numtables = unpack('n', substr($contents, 4, 2))[1] ?? 0;
        $cmapoffset = 0;
        for ($i = 0; $i < $numtables; $i++) {
            $entry = 12 + ($i * 16);
            if (strlen($contents) < $entry + 16) {
                break;
            }
            $tag = substr($contents, $entry, 4);
            if ($tag === 'cmap') {
                $cmapoffset = unpack('N', substr($contents, $entry + 8, 4))[1] ?? 0;
                break;
            }
        }
        if ($cmapoffset === 0 || strlen($contents) < $cmapoffset + 4) {
            return null;
        }

        $subtables = unpack('n', substr($contents, $cmapoffset + 2, 2))[1] ?? 0;
        $best = 0;
        for ($i = 0; $i < $subtables; $i++) {
            $entry = $cmapoffset + 4 + ($i * 8);
            if (strlen($contents) < $entry + 8) {
                break;
            }
            $platform = unpack('n', substr($contents, $entry, 2))[1] ?? 0;
            $encoding = unpack('n', substr($contents, $entry + 2, 2))[1] ?? 0;
            $offset = unpack('N', substr($contents, $entry + 4, 4))[1] ?? 0;
            if ($platform === 3 && in_array($encoding, [1, 10], true)) {
                $best = $cmapoffset + $offset;
            }
        }
        if ($best === 0 || strlen($contents) < $best + 14) {
            return null;
        }

        $format = unpack('n', substr($contents, $best, 2))[1] ?? 0;
        if ($format !== 4) {
            return null;
        }

        $segcountx2 = unpack('n', substr($contents, $best + 6, 2))[1] ?? 0;
        $segcount = (int) ($segcountx2 / 2);
        if ($segcount === 0) {
            return null;
        }

        $endbase = $best + 14;
        $startbase = $endbase + $segcountx2 + 2;
        if (strlen($contents) < $startbase + $segcountx2) {
            return null;
        }

        $map = [];
        for ($i = 0; $i < $segcount; $i++) {
            $end = unpack('n', substr($contents, $endbase + ($i * 2), 2))[1] ?? 0;
            $start = unpack('n', substr($contents, $startbase + ($i * 2), 2))[1] ?? 0;
            if ($start > $end || $start === 0xFFFF) {
                continue;
            }
            // Record only the segment boundaries; that is enough to answer
            // whether a representative code point is covered.
            for ($code = $start; $code <= $end && $code - $start < 512; $code++) {
                $map[$code] = true;
            }
        }

        return $map;
    }

    /**
     * Forget the cached font catalogue.
     *
     * @return void
     */
    public static function purge_cache(): void {
        \cache::make('mod_certificatepro', 'fontcatalogue')->purge();
    }

    /**
     * A warning for the author when the chosen font cannot draw the text it is given.
     *
     * @param string $family the family key
     * @param string $text the text that will be drawn
     * @return string|null a translated warning, or null when the font is fine
     */
    public static function coverage_warning(string $family, string $text): ?string {
        if (in_array(strtolower($family), self::LEGACY_FAMILIES, true)) {
            if (preg_match('/[^\x00-\xFF]/u', $text)) {
                return get_string('warningfontlatinonly', 'mod_certificatepro');
            }
        }
        return null;
    }
}

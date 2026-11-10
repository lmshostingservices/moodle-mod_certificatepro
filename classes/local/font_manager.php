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
            // A bold or italic face is not a family an author chooses between:
            // it is the same family, and asking for bold is what reaches it.
            // Listing them made Lora appear four times in the font picker, each
            // entry labelled "Lora" and three of them wrong to choose.
            if ($record->style !== '') {
                continue;
            }
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
     * The family key a styled face belongs to.
     *
     * TCPDF names a bold face by suffixing the family key, so "lora" bold is
     * stored as "lorab". Anything that wants to talk about the family rather
     * than the face has to take that suffix back off.
     *
     * @param string $family the stored family key, which may name a styled face
     * @param string $style empty, B, I or BI
     * @return string the family key without the style suffix
     */
    public static function base_family(string $family, string $style): string {
        $family = strtolower($family);
        $suffix = strtolower($style);

        if ($suffix !== '' && str_ends_with($family, $suffix)) {
            $stripped = substr($family, 0, -strlen($suffix));
            if ($stripped !== '') {
                return $stripped;
            }
        }

        return $family;
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

            // Every face of a family has to be published under the family's own
            // CSS name, with the weight and slope that say which face it is.
            // Publishing the bold as "cpro-lorab" meant the browser never found
            // a real bold for "cpro-lora" and synthesised one, so the designer
            // measured bold text differently from the PDF — the one thing this
            // whole mechanism exists to prevent.
            $cssfamily = self::base_family($record->family, $record->style);

            $css .= '@font-face{font-family:"cpro-' . $cssfamily . '";'
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

        // The custom font directory is created and seeded BEFORE pdflib is
        // loaded, because loading it fixes K_PATH_FONTS as a constant. On a site
        // where the directory does not exist yet, doing it the other way round
        // pinned TCPDF to the core font directory for the rest of the request,
        // and a font installed a moment earlier was invisible to any PDF drawn
        // in that same request.
        $targetdirectory = self::custom_font_directory();

        require_once($CFG->libdir . '/pdflib.php');

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['ttf', 'otf'], true)) {
            throw new \moodle_exception('errorfonttype', 'mod_certificatepro');
        }

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
     * Fill the gaps in TCPDF's bidirectional character class table.
     *
     * TCPDF looks the class of every character up in a table it ships, and that
     * table is incomplete: most CJK ideographs are missing from it, including
     * common name characters. Every lookup of a missing one raises an undefined
     * index warning, and a site with display debugging on used to have those
     * warnings written into the middle of the PDF bytes. The table is a public
     * static, so the honest fix is to supply the classes Unicode defines for the
     * characters actually being drawn rather than to silence the warnings.
     *
     * @param string $text the text about to be measured or drawn
     * @return void
     */
    public static function prime_bidi_table(string $text): void {
        if ($text === '' || !preg_match('/[^\x00-\x7F]/', $text)) {
            return;
        }

        if (!class_exists('\TCPDF_FONT_DATA')) {
            return;
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return;
        }

        foreach ($characters as $character) {
            $codepoint = \core_text::utf8ord($character);
            if ($codepoint < 0x80 || isset(\TCPDF_FONT_DATA::$uni_type[$codepoint])) {
                continue;
            }

            // The Unicode default for a code point with no explicit class is
            // left to right, except inside the ranges the standard reserves for
            // right to left scripts.
            \TCPDF_FONT_DATA::$uni_type[$codepoint] = match (self::script_of($codepoint)) {
                'arabic' => 'AL',
                'hebrew' => 'R',
                default => 'L',
            };
        }
    }

    /**
     * The unicode script a single code point belongs to.
     *
     * Only the scripts a certificate plausibly has to draw are named. Spaces,
     * digits and punctuation come back as "common", which means "whatever face
     * the surrounding text is using" — that is what stops a space between two
     * Han characters from splitting a run in two.
     *
     * @param int $codepoint the code point to classify
     * @return string a script identifier, or "common"
     */
    public static function script_of(int $codepoint): string {
        static $ranges = [
            ['han', 0x2E80, 0x2FDF], ['han', 0x3400, 0x4DBF], ['han', 0x4E00, 0x9FFF],
            ['han', 0xF900, 0xFAFF], ['han', 0x20000, 0x2FA1F],
            ['kana', 0x3040, 0x30FF], ['kana', 0x31F0, 0x31FF],
            ['hangul', 0x1100, 0x11FF], ['hangul', 0x3130, 0x318F], ['hangul', 0xAC00, 0xD7AF],
            ['arabic', 0x0600, 0x06FF], ['arabic', 0x0750, 0x077F],
            ['arabic', 0xFB50, 0xFDFF], ['arabic', 0xFE70, 0xFEFF],
            ['hebrew', 0x0590, 0x05FF],
            ['greek', 0x0370, 0x03FF], ['greek', 0x1F00, 0x1FFF],
            ['cyrillic', 0x0400, 0x052F],
            ['armenian', 0x0530, 0x058F],
            ['devanagari', 0x0900, 0x097F],
            ['bengali', 0x0980, 0x09FF],
            ['tamil', 0x0B80, 0x0BFF],
            ['thai', 0x0E00, 0x0E7F],
            ['burmese', 0x1000, 0x109F],
            ['georgian', 0x10A0, 0x10FF],
            ['khmer', 0x1780, 0x17FF],
            ['latin', 0x0041, 0x024F], ['latin', 0x1E00, 0x1EFF],
        ];

        // Anything below the Latin block, and the general punctuation and CJK
        // punctuation blocks, belong to no script in particular.
        if ($codepoint < 0x0041
                || ($codepoint >= 0x2000 && $codepoint <= 0x206F)
                || ($codepoint >= 0x3000 && $codepoint <= 0x303F)) {
            return 'common';
        }

        foreach ($ranges as [$script, $from, $to]) {
            if ($codepoint >= $from && $codepoint <= $to) {
                return $script;
            }
        }

        return 'common';
    }

    /**
     * Every code point a font can draw, when that can be established exactly.
     *
     * TCPDF writes a width table keyed by code point for every TrueType font it
     * converts, which is an exact answer to "can this face draw that character"
     * for both the bundled faces and anything an administrator has installed.
     * The CID keyed CJK faces are different: their glyph coverage lives in a
     * character collection the viewer supplies, not in the definition file, so
     * there is nothing to read and the answer has to come from the script table
     * instead.
     *
     * @param string $family the family key
     * @return array<int, true>|null the code points, or null when unknowable
     */
    protected static function coverage_set(string $family): ?array {
        static $cache = [];

        $family = strtolower($family);
        if (array_key_exists($family, $cache)) {
            return $cache[$family];
        }

        $cache[$family] = null;

        $path = self::definition_path($family);
        if ($path === null) {
            return null;
        }

        // The definition file is plain PHP that assigns a handful of variables.
        // Including it in this scope keeps those assignments local.
        $cw = null;
        $type = null;
        try {
            include($path);
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not read the font definition ' . $path
                . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if (!is_array($cw) || $cw === [] || $type === 'cidfont0') {
            return null;
        }

        $cache[$family] = array_fill_keys(array_keys($cw), true);

        return $cache[$family];
    }

    /**
     * Where a family's TCPDF definition file lives.
     *
     * @param string $family the family key
     * @return string|null an absolute path, or null when there is no such file
     */
    protected static function definition_path(string $family): ?string {
        global $CFG;

        $family = preg_replace('/[^a-z0-9_]/', '', strtolower($family)) ?? '';
        if ($family === '') {
            return null;
        }

        $directories = [
            defined('PDF_CUSTOM_FONT_PATH') ? PDF_CUSTOM_FONT_PATH : $CFG->dataroot . '/fonts/',
            $CFG->libdir . '/tcpdf/fonts/',
        ];

        foreach ($directories as $directory) {
            $path = rtrim($directory, '/') . '/' . $family . '.php';
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The scripts a CID keyed CJK face can draw.
     *
     * @param string $family the family key
     * @return string[]|null the scripts, or null when the family is not one of them
     */
    protected static function cid_scripts(string $family): ?array {
        static $known = [
            // Adobe Japan1: kana and the JIS kanji set.
            'kozminproregular' => ['latin', 'common', 'han', 'kana'],
            'kozgopromedium' => ['latin', 'common', 'han', 'kana'],
            // Adobe GB1: simplified Chinese, with the traditional forms too.
            'stsongstdlight' => ['latin', 'common', 'han'],
            // Adobe CNS1: traditional Chinese.
            'msungstdlight' => ['latin', 'common', 'han'],
            // Adobe Korea1: hangul and hanja.
            'hysmyeongjostdmedium' => ['latin', 'common', 'han', 'hangul'],
        ];

        return $known[strtolower($family)] ?? null;
    }

    /**
     * Whether a family can draw a given code point.
     *
     * @param string $family the family key
     * @param int $codepoint the code point
     * @return bool
     */
    public static function covers(string $family, int $codepoint): bool {
        $family = strtolower($family);

        if (in_array($family, self::LEGACY_FAMILIES, true)) {
            return $codepoint <= 0xFF;
        }

        $cid = self::cid_scripts($family);
        if ($cid !== null) {
            return in_array(self::script_of($codepoint), $cid, true);
        }

        $set = self::coverage_set($family);
        if ($set === null) {
            // Nothing readable. Assume Latin 1, which every face manages.
            return $codepoint <= 0xFF;
        }

        return isset($set[$codepoint]);
    }

    /**
     * The families to fall back through, best first, for a given script.
     *
     * @param string $script a script identifier from {@see script_of()}
     * @return string[] family keys, which may not all be installed
     */
    protected static function fallbacks(string $script): array {
        return match ($script) {
            'kana' => ['kozminproregular', 'kozgopromedium', 'hysmyeongjostdmedium'],
            'hangul' => ['hysmyeongjostdmedium', 'kozminproregular'],
            'han' => ['stsongstdlight', 'msungstdlight', 'kozminproregular',
                'hysmyeongjostdmedium'],
            default => ['freeserif', 'freesans', 'freemono'],
        };
    }

    /**
     * Split text into the longest runs each of which one face can draw.
     *
     * A recipient's name is not negotiable, and no single face TCPDF ships can
     * draw Latin, Arabic and Han at once. Rather than lose the name, or print it
     * in a face that has glyphs for only part of it, the string is cut into runs
     * and each run is given a face that actually covers it. The design's own
     * choice always wins wherever it can draw the characters, so a name in the
     * Latin alphabet is untouched by any of this.
     *
     * @param string $family the family the design asked for
     * @param string $text the text about to be drawn
     * @return array<int, array{family: string, text: string}> runs in order
     */
    public static function runs(string $family, string $text): array {
        if ($text === '') {
            return [];
        }

        $family = strtolower($family);
        self::prime_bidi_table($text);

        // The overwhelmingly common case: plain ASCII in the chosen face.
        if (!preg_match('/[^\x00-\x7F]/', $text) && !in_array($family, self::LEGACY_FAMILIES, true)) {
            return [['family' => $family, 'text' => $text]];
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return [['family' => $family, 'text' => $text]];
        }

        $runs = [];
        $current = null;
        $currentfamily = $family;

        foreach ($characters as $character) {
            $codepoint = \core_text::utf8ord($character);
            $script = self::script_of($codepoint);

            if ($script === 'common' && $current !== null) {
                // Punctuation and spaces stay with the run they are inside, so
                // that "李 小" does not become three runs.
                $current .= $character;
                continue;
            }

            $chosen = self::family_covering($family, $currentfamily, $codepoint, $script);

            if ($current !== null && $chosen === $currentfamily) {
                $current .= $character;
                continue;
            }

            if ($current !== null) {
                $runs[] = ['family' => $currentfamily, 'text' => $current];
            }
            $current = $character;
            $currentfamily = $chosen;
        }

        if ($current !== null) {
            $runs[] = ['family' => $currentfamily, 'text' => $current];
        }

        return $runs;
    }

    /**
     * Pick the face for one character.
     *
     * @param string $preferred the design's chosen family
     * @param string $currentfamily the family the run being built is using
     * @param int $codepoint the character to draw
     * @param string $script the script that character belongs to
     * @return string a family key
     */
    protected static function family_covering(string $preferred, string $currentfamily,
            int $codepoint, string $script): string {
        // Staying in the current face avoids a needless run break mid word.
        if (self::covers($currentfamily, $codepoint)) {
            return $currentfamily;
        }
        if (self::covers($preferred, $codepoint)) {
            return $preferred;
        }

        foreach (self::fallbacks($script) as $candidate) {
            if (self::is_installed($candidate) && self::covers($candidate, $codepoint)) {
                return $candidate;
            }
        }

        // Nothing on this site can draw it. Keep the design's face so the
        // character at least occupies its place rather than shifting the line.
        return $preferred;
    }

    /**
     * Make a family addressable from an HTML font-family declaration.
     *
     * TCPDF resolves a font-family value by stripping the words "regular",
     * "bold", "italic" and "oblique" off the end of it before looking the name
     * up, so a family whose key ends in one of those words — the bundled
     * Japanese face kozminproregular is the example — can never be named in
     * markup. Registering the same definition file under a second key that
     * survives that stripping is the only way to reach it.
     *
     * @param \pdf $pdf the document the font has to be usable in
     * @param string $family the family key
     * @return string the name to write in the markup
     */
    public static function html_family(\pdf $pdf, string $family): string {
        static $aliases = [];

        $family = strtolower($family);

        $stripped = preg_replace(
            ['/regular$/', '/italic$/', '/oblique$/', '/bold([i]?)$/'],
            ['', 'i', 'i', 'b$1'],
            $family
        ) ?? $family;

        if ($stripped === $family) {
            try {
                $pdf->AddFont($family);
            } catch (\Throwable $e) {
                debugging('Certificate Pro could not load the font ' . $family . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return $family;
        }

        if (isset($aliases[$family])) {
            $alias = $aliases[$family];
        } else {
            $alias = 'cpro' . substr(md5($family), 0, 10);
            $aliases[$family] = $alias;
        }

        $path = self::definition_path($family);
        if ($path === null) {
            return $family;
        }

        try {
            $pdf->AddFont($alias, '', $path);
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not alias the font ' . $family . ': '
                . $e->getMessage(), DEBUG_DEVELOPER);
            return $family;
        }

        return $alias;
    }

    /**
     * Tag the parts of some markup that need a different face than the rest.
     *
     * Text nodes are the only thing touched: tags and attribute values pass
     * through untouched, so an author's own markup keeps working.
     *
     * @param \pdf $pdf the document the text will be drawn into
     * @param string $family the family the design asked for
     * @param string $html the markup about to be written
     * @return string the markup, with substituted runs wrapped in a span
     */
    public static function apply_font_runs(\pdf $pdf, string $family, string $html): string {
        if ($html === '' || !preg_match('/[^\x00-\x7F]/', $html)) {
            return $html;
        }

        self::prime_bidi_table($html);
        $family = strtolower($family);
        $parts = preg_split('/(<[^>]*>)/', $html, -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $result = '';
        foreach ($parts as $part) {
            if ($part === '' || $part[0] === '<') {
                $result .= $part;
                continue;
            }

            // Entities are decoded so that a code point written as &#x674E; is
            // classified by what it is rather than by its ASCII spelling, and
            // re encoded on the way back out.
            $plain = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $runs = self::runs($family, $plain);
            if (count($runs) <= 1) {
                $result .= $part;
                continue;
            }

            foreach ($runs as $run) {
                $escaped = htmlspecialchars($run['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($run['family'] === $family) {
                    $result .= $escaped;
                    continue;
                }
                $result .= '<span style="font-family:'
                    . self::html_family($pdf, $run['family']) . ';">' . $escaped . '</span>';
            }
        }

        return $result;
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

        $runs = self::runs($family, $text);
        foreach ($runs as $run) {
            if (strtolower($run['family']) !== strtolower($family)) {
                return get_string('warningfontsubstituted', 'mod_certificatepro',
                    self::prettify($run['family']));
            }
        }

        return null;
    }

    /**
     * Drop the characters no available font can draw.
     *
     * Emoji are the practical case: a learner whose display name ends in an
     * emoji is not unusual, and none of the faces TCPDF ships has a glyph for
     * one. TCPDF answers with an undefined-index warning per character, which on
     * a site with display debugging on used to end up inside the PDF bytes.
     * Removing them is honest — the certificate simply prints the name without
     * the decoration, rather than with a row of boxes or not at all.
     *
     * Pictographs, dingbats, flags, variation selectors and zero width joiners
     * go; everything a writing system needs stays.
     *
     * @param string $text the text about to be drawn
     * @return string the text with undrawable characters removed
     */
    public static function strip_undrawable(string $text): string {
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{E0020}-\x{E007F}]/u',
            '',
            $text
        );

        if ($stripped === null) {
            return $text;
        }

        // Collapse the gap a removed character leaves mid-string.
        return trim(preg_replace('/\s{2,}/u', ' ', $stripped) ?? $stripped);
    }

    /**
     * A family that can draw the first character of the text that needs one.
     *
     * Kept for the callers that can only set a single face for a whole string,
     * such as the arc text on a seal. Everything that draws through the HTML
     * writer should use {@see apply_font_runs()} instead, which keeps the
     * design's own face wherever it works.
     *
     * @param string $family the family the design asked for
     * @param string $text the text about to be drawn
     * @return string a family that covers the text, which may be the one asked for
     */
    public static function family_for_text(string $family, string $text): string {
        $runs = self::runs($family, $text);
        foreach ($runs as $run) {
            if (strtolower($run['family']) !== strtolower($family)) {
                return $run['family'];
            }
        }

        return $family;
    }
}

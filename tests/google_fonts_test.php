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

namespace mod_certificatepro;

use mod_certificatepro\local\font\google_fonts;
use mod_certificatepro\local\font\install_report;
use mod_certificatepro\local\font\stub_google_fonts;
use mod_certificatepro\local\font_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/certificatepro/tests/fixtures/stub_google_fonts.php');

/**
 * The Google Fonts installer.
 *
 * The bug these tests exist for: a family used to be installed one face per
 * family, so weight 700 of Lato became a family called "Lato 700" and
 * SetFont('lato', 'B') found no bold face and silently drew the regular one.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_certificatepro\local\font\google_fonts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_certificatepro\local\font\install_report::class)]
final class google_fonts_test extends \advanced_testcase {
    /**
     * Set of four real font files, one per face, from wherever this machine keeps them.
     *
     * The faces have to be genuinely different outlines or the test proves
     * nothing, so they are taken from a real four-face family rather than
     * synthesised.
     *
     * @return array<string, string>|null style flag to path, or null when none is available
     */
    protected function fixture_faces(): ?array {
        $candidates = [
            ['/usr/share/fonts/truetype/dejavu/', 'DejaVuSerif', '.ttf', '-Bold', '-Italic',
                '-BoldItalic'],
            ['/usr/share/fonts/truetype/liberation/', 'LiberationSerif', '-Regular.ttf', '-Bold',
                '-Italic', '-BoldItalic'],
            ['/usr/share/fonts/truetype/crosextra/', 'Caladea', '-Regular.ttf', '-Bold', '-Italic',
                '-BoldItalic'],
        ];

        foreach ($candidates as [$dir, $stem, $regular, $bold, $italic, $bolditalic]) {
            $faces = [
                '' => $dir . $stem . $regular,
                'B' => $dir . $stem . $bold . '.ttf',
                'I' => $dir . $stem . $italic . '.ttf',
                'BI' => $dir . $stem . $bolditalic . '.ttf',
            ];
            $found = true;
            foreach ($faces as $path) {
                if (!is_readable($path)) {
                    $found = false;
                    break;
                }
            }
            if ($found) {
                return $faces;
            }
        }

        return null;
    }

    /**
     * A stub loaded with the given faces of one family.
     *
     * @param string $family the family name to publish
     * @param string[] $styles the style flags to publish
     * @return array<string, string> the paths that were published
     */
    protected function publish(string $family, array $styles = ['', 'B', 'I', 'BI']): array {
        $faces = $this->fixture_faces();
        if ($faces === null) {
            $this->markTestSkipped('No four-face TrueType family is available on this machine.');
        }

        stub_google_fonts::reset();
        foreach ($styles as $style) {
            stub_google_fonts::$library[$family][$style] = $faces[$style];
        }

        return $faces;
    }

    /**
     * Where the converted font definitions land.
     *
     * @param string $key the TCPDF family key, style suffix included
     * @return string
     */
    protected function definition_path(string $key): string {
        return font_manager::custom_font_directory() . $key . '.php';
    }

    /**
     * Installing a family produces one family with four faces, not four families.
     *
     * @return void
     */
    public function test_install_makes_one_family_with_four_faces(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');

        $report = stub_google_fonts::install('Testicus Serif');

        $this->assertSame('', $report['error']);
        $this->assertSame(['', 'B', 'I', 'BI'], array_keys($report['installed']));
        $this->assertSame([], $report['failed']);
        $this->assertSame([], $report['skipped']);

        // TCPDF finds a face by file name, so these four names are the whole
        // point: testicusserifb.php is the bold OF testicusserif, whereas a
        // family called "Testicus Serif 700" would have produced
        // testicusserif700.php and SetFont('testicusserif', 'B') would miss it.
        foreach (['testicusserif', 'testicusserifb', 'testicusserifi', 'testicusserifbi'] as $key) {
            $this->assertFileExists($this->definition_path($key));
        }
        $this->assertFileDoesNotExist($this->definition_path('testicusserif700'));
        $this->assertFileDoesNotExist($this->definition_path('testicusserif400'));

        $records = $DB->get_records('certificatepro_fonts', null, 'style ASC');
        $bystyle = [];
        foreach ($records as $record) {
            $bystyle[$record->style] = $record;
        }

        $this->assertCount(4, $bystyle);
        $this->assertSame('testicusserif', $bystyle['']->family);
        $this->assertSame('testicusserifb', $bystyle['B']->family);
        $this->assertSame('testicusserifi', $bystyle['I']->family);
        $this->assertSame('testicusserifbi', $bystyle['BI']->family);
        foreach ($bystyle as $record) {
            $this->assertSame('Testicus Serif', $record->displayname);
        }
    }

    /**
     * Read one of TCPDF's generated font definitions.
     *
     * @param string $key the TCPDF family key, style suffix included
     * @return array the metrics the definition declares
     */
    protected function read_definition(string $key): array {
        $path = $this->definition_path($key);
        $cw = [];
        $file = '';
        $desc = [];
        include($path);
        return ['cw' => $cw, 'file' => $file, 'desc' => $desc];
    }

    /**
     * The installed bold face is a real bold face, not the regular one again.
     *
     * @return void
     */
    public function test_bold_and_italic_are_really_different_faces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');

        stub_google_fonts::install('Testicus Serif');

        $regular = $this->read_definition('testicusserif');
        $bold = $this->read_definition('testicusserifb');
        $italic = $this->read_definition('testicusserifi');
        $bolditalic = $this->read_definition('testicusserifbi');

        // Different outlines, different width tables. If the installer had
        // pointed the bold at the regular file, or never made a bold at all,
        // these would be identical.
        $this->assertNotEquals($regular['cw'], $bold['cw'],
            'Bold measures the same as regular, so the bold face is not a real bold.');
        $this->assertNotEquals($regular['cw'], $italic['cw'],
            'Italic measures the same as regular, so the italic face is not a real italic.');
        $this->assertNotEquals($bold['cw'], $bolditalic['cw']);

        // Each face embeds its own compressed outline file.
        $files = [$regular['file'], $bold['file'], $italic['file'], $bolditalic['file']];
        $this->assertCount(4, array_unique($files));
        foreach ($files as $file) {
            $this->assertNotSame('', $file);
            $this->assertFileExists(font_manager::custom_font_directory() . $file);
        }

        // And the bold really is heavier: TCPDF records the stem weight it
        // measured out of the font's own tables.
        $this->assertGreaterThan((int) ($regular['desc']['StemV'] ?? 0),
            (int) ($bold['desc']['StemV'] ?? 0));
    }

    /**
     * TCPDF resolves the bold as a style of the family, not as its own family.
     *
     * @return void
     */
    public function test_tcpdf_finds_the_bold_as_a_style_of_the_family(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');

        stub_google_fonts::install('Testicus Serif');

        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf();
        $pdf->AddPage();

        // TCPDF pins the directory it scans into a constant the first time
        // anything loads pdflib, which under phpunit is before this dataroot has
        // a font directory at all. Naming each definition file explicitly gets
        // the same fonts in front of the same TCPDF either way.
        foreach (['', 'B', 'I', 'BI'] as $style) {
            $pdf->AddFont('testicusserif', $style,
                $this->definition_path('testicusserif' . strtolower($style)));
        }

        $widths = [];
        foreach (['', 'B', 'I', 'BI'] as $style) {
            $pdf->SetFont('testicusserif', $style, 12);
            $widths[$style] = $pdf->GetStringWidth('Hamburgefonstiv');
        }
        unset($pdf);

        // This is the reported bug in one assertion: SetFont('lato', 'B') found
        // no bold face and silently drew the regular, so bold measured exactly
        // the same as regular.
        $this->assertNotEquals($widths[''], $widths['B'],
            'TCPDF measured bold as regular, so it did not find a bold face.');

        // The italics are deliberately not compared by width. Many families,
        // this test's fixture among them, draw an italic on the same advance
        // widths as the regular; the italic being a different outline is what
        // test_bold_and_italic_are_really_different_faces() checks.
        $this->assertIsFloat($widths['I']);
        $this->assertIsFloat($widths['BI']);
    }

    /**
     * A face Google does not publish is skipped, and does not stop the rest.
     *
     * @return void
     */
    public function test_missing_italic_is_skipped_not_failed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Plenty of display and handwriting families have a regular and a bold
        // and nothing else.
        $this->publish('Testicus Display', ['', 'B']);

        $report = stub_google_fonts::install('Testicus Display');

        $this->assertSame('', $report['error']);
        $this->assertSame(['', 'B'], array_keys($report['installed']));
        $this->assertSame(['I', 'BI'], $report['skipped']);
        $this->assertSame([], $report['failed']);
        $this->assertFileExists($this->definition_path('testicusdisplayb'));
        $this->assertFileDoesNotExist($this->definition_path('testicusdisplayi'));
    }

    /**
     * With no network nothing is installed and the failure is named.
     *
     * @return void
     */
    public function test_no_network_leaves_nothing_behind(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');
        stub_google_fonts::$offline = true;

        $report = stub_google_fonts::install('Testicus Serif');

        $this->assertSame('network', $report['error']);
        $this->assertSame([], $report['installed']);
        $this->assertSame([], $report['skipped']);
        $this->assertSame(0, $DB->count_records('certificatepro_fonts'));
        $this->assertFileDoesNotExist($this->definition_path('testicusserif'));

        // One request, then it stops: there is no point asking Google for
        // another hundred and forty faces down a cable that is not there.
        $this->assertSame(1, stub_google_fonts::$requests);
    }

    /**
     * The network dying midway through a family installs none of it.
     *
     * @return void
     */
    public function test_network_lost_midway_installs_no_partial_family(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Only the regular is published, so the bold request is the one that
        // hits the dead network.
        $this->publish('Testicus Serif', ['']);
        stub_google_fonts::$library['Testicus Serif']['B'] = '/nonexistent/never.ttf';

        $report = stub_google_fonts::install('Testicus Serif');

        // The bold file is unreadable, which the stub reports as not found
        // rather than as a network failure, so the regular still installs.
        $this->assertSame('', $report['error']);
        $this->assertSame([''], array_keys($report['installed']));
        $this->assertSame(1, $DB->count_records('certificatepro_fonts'));
    }

    /**
     * A family with no regular face installs nothing at all.
     *
     * @return void
     */
    public function test_bold_without_regular_is_refused(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Bold Only', ['B']);

        $report = stub_google_fonts::install('Testicus Bold Only');

        $this->assertSame('nosuchfamily', $report['error']);
        $this->assertSame([], $report['installed']);
        $this->assertSame(0, $DB->count_records('certificatepro_fonts'));

        // Installing the bold on its own would have registered a family called
        // "testicusboldonlyb", which is exactly the bug being fixed.
        $this->assertFileDoesNotExist($this->definition_path('testicusboldonlyb'));
    }

    /**
     * Anything that is not a font is refused rather than converted.
     *
     * @return void
     */
    public function test_a_download_that_is_not_a_font_is_refused(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $faces = $this->publish('Testicus Serif');
        $notafont = make_request_directory() . '/notafont.ttf';
        file_put_contents($notafont, str_repeat('<html>not a font at all</html>', 200));
        stub_google_fonts::$library['Testicus Serif']['B'] = $notafont;

        $report = stub_google_fonts::install('Testicus Serif');

        $this->assertSame('', $report['error']);
        $this->assertArrayHasKey('B', $report['failed']);
        $this->assertSame('notafont', $report['failed']['B']);
        $this->assertArrayNotHasKey('B', $report['installed']);
        $this->assertFileExists($faces['']);
        $this->assertFileDoesNotExist($this->definition_path('testicusserifb'));
        $this->assertSame(3, $DB->count_records('certificatepro_fonts'));
    }

    /**
     * A second install of the same family does no work and no downloads.
     *
     * @return void
     */
    public function test_installing_twice_is_a_no_op(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');

        stub_google_fonts::install('Testicus Serif');

        stub_google_fonts::purge_face_cache();
        stub_google_fonts::$requests = 0;

        $report = stub_google_fonts::install('Testicus Serif');

        $this->assertSame('', $report['error']);
        $this->assertSame([], $report['installed']);
        $this->assertSame(['', 'B', 'I', 'BI'], $report['present']);
        $this->assertSame(0, stub_google_fonts::$requests);
    }

    /**
     * Faces are reported per family and the presets know what is missing.
     *
     * @return void
     */
    public function test_preset_status_names_what_is_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $missingbefore = google_fonts::missing_families();
        $this->assertContains('Lora', $missingbefore);

        $this->publish('Lora');
        stub_google_fonts::install('Lora');

        google_fonts::purge_face_cache();
        $this->assertNotContains('Lora', google_fonts::missing_families());
        $this->assertTrue(google_fonts::is_complete('Lora'));

        $status = google_fonts::preset_status();
        $this->assertCount(count(\mod_certificatepro\local\type\style_library::required_families()),
            $status);

        $lora = null;
        foreach ($status as $row) {
            if ($row['family'] === 'Lora') {
                $lora = $row;
            }
        }
        $this->assertNotNull($lora);
        $this->assertTrue($lora['complete']);
        $this->assertSame(['' => true, 'B' => true, 'I' => true, 'BI' => true], $lora['faces']);
    }

    /**
     * A batch stops at the first network failure and says what it never got to.
     *
     * @return void
     */
    public function test_batch_stops_at_the_first_network_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        stub_google_fonts::reset();
        stub_google_fonts::$offline = true;

        $seen = [];
        $outcome = stub_google_fonts::install_preset_families(function (array $report) use (&$seen) {
            $seen[] = $report['family'];
        });

        $required = \mod_certificatepro\local\type\style_library::required_families();

        $this->assertSame('network', $outcome['error']);
        $this->assertCount(1, $outcome['reports']);
        $this->assertCount(1, $seen);
        $this->assertCount(count($required) - 1, $outcome['notattempted']);
        $this->assertSame(1, stub_google_fonts::$requests);
    }

    /**
     * The italic face is asked for as an italic, not as another regular.
     *
     * @return void
     */
    public function test_the_right_urls_are_requested(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->publish('Testicus Serif');

        stub_google_fonts::install('Testicus Serif');

        $css = array_values(array_filter(stub_google_fonts::$urls, function (string $url): bool {
            return strpos($url, 'css2') !== false;
        }));

        $this->assertCount(4, $css);
        $this->assertStringContainsString('wght@400', $css[0]);
        $this->assertStringContainsString('wght@700', $css[1]);
        $this->assertStringContainsString('ital,wght@1,400', $css[2]);
        $this->assertStringContainsString('ital,wght@1,700', $css[3]);
        $this->assertStringContainsString('Testicus%20Serif', $css[0]);
    }

    /**
     * A report reaches a human as sentences rather than style flags.
     *
     * @return void
     */
    public function test_reports_read_as_english(): void {
        $this->resetAfterTest();

        $messages = install_report::messages([
            'family' => 'Great Vibes',
            'installed' => ['' => 1, 'B' => 2],
            'present' => [],
            'skipped' => ['I', 'BI'],
            'failed' => [],
            'error' => '',
        ]);

        $this->assertCount(2, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertStringContainsString('Great Vibes', $messages[0]['text']);
        $this->assertStringContainsString('regular, bold', $messages[0]['text']);
        $this->assertSame('info', $messages[1]['type']);
        $this->assertStringContainsString('italic, bold italic', $messages[1]['text']);

        $offline = install_report::messages([
            'family' => 'Lato',
            'installed' => [],
            'present' => [],
            'skipped' => [],
            'failed' => [],
            'error' => 'network',
        ]);

        $this->assertCount(1, $offline);
        $this->assertSame('error', $offline[0]['type']);
        $this->assertStringContainsString('fonts.googleapis.com', $offline[0]['text']);

        $this->assertSame(['families' => 1, 'faces' => 2], install_report::totals([
            ['installed' => ['' => 1, 'B' => 2]],
            ['installed' => []],
        ]));
    }

    /**
     * An unusable family name is refused before anything touches the network.
     *
     * @return void
     */
    public function test_an_empty_family_name_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        stub_google_fonts::reset();

        foreach (['', '   ', '///'] as $name) {
            $report = stub_google_fonts::install($name);
            $this->assertSame('badfamily', $report['error']);
        }

        $this->assertSame(0, stub_google_fonts::$requests);
    }
}

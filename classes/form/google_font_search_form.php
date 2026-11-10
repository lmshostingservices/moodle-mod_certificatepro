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

namespace mod_certificatepro\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Search the Google Fonts catalogue for a family to install.
 *
 * A search changes nothing, so it is a GET: the result is a bookmarkable URL
 * and a reload does not re-run anything. Installing is a separate, confirmed
 * POST from the results.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_font_search_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        // No header element: the page already carries the heading, and a second
        // copy of it as a fieldset legend reads as a stutter.
        $mform->addElement('text', 'q',
            get_string('gfontssearchquery', 'mod_certificatepro'), ['size' => 32]);
        $mform->setType('q', PARAM_TEXT);

        $mform->addElement('select', 'category',
            get_string('gfontscategory', 'mod_certificatepro'), self::categories());
        $mform->setType('category', PARAM_ALPHAEXT);

        $mform->addElement('submit', 'gfontssearch',
            get_string('gfontssearchgo', 'mod_certificatepro'));
    }

    /**
     * The categories the Google Fonts catalogue uses.
     *
     * @return array<string, string> keyed by the catalogue's own category value
     */
    public static function categories(): array {
        return [
            '' => get_string('gfontscategory_any', 'mod_certificatepro'),
            'serif' => get_string('gfontscategory_serif', 'mod_certificatepro'),
            'sans-serif' => get_string('gfontscategory_sansserif', 'mod_certificatepro'),
            'display' => get_string('gfontscategory_display', 'mod_certificatepro'),
            'handwriting' => get_string('gfontscategory_handwriting', 'mod_certificatepro'),
            'monospace' => get_string('gfontscategory_monospace', 'mod_certificatepro'),
        ];
    }
}

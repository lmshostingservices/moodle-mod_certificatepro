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

namespace mod_certificatepro\element\type;

use mod_certificatepro\element\base;
use mod_certificatepro\element\image_base;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;

/**
 * Stands in for an element whose type is not installed on this site.

Keeping the record intact means a design created elsewhere still opens, still
saves, and still carries the missing element's configuration, rather than
throwing an error or silently discarding it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unavailable extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'unavailable';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DECOR;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-circle-question';
    }

    /**
     * The original type this record asked for.
     *
     * @return string
     */
    public function missing_type(): string {
        return (string) ($this->record->element ?? '');
    }

    /**
     * The translated display name, naming the missing type.
     *
     * @return string
     */
    public static function display_name(): string {
        return get_string('element_unavailable', 'mod_certificatepro');
    }

    /**
     * Nothing to resolve.
     *
     * @param render_context $ctx the render context
     * @return null
     */
    public function resolve(render_context $ctx) {
        return null;
    }

    /**
     * Draw nothing, so a missing element leaves a blank space rather than an error.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        // Deliberately empty.
    }

    /**
     * Show the author what is missing.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        return \html_writer::div(
            get_string('elementunavailabledetail', 'mod_certificatepro', s($this->missing_type())),
            'certificatepro-element-placeholder certificatepro-element-missing'
        );
    }}

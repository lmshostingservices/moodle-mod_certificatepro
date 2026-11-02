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

/**
 * Reads grades out of the gradebook and writes them the way a certificate wants them.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_helper {
    /**
     * A selector menu of the grade items a certificate can print.
     *
     * @param \context $context a module or course context
     * @return array<string, string>
     */
    public static function menu(\context $context): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $menu = ['' => get_string('choosedots'), 'course' => get_string('coursegrade', 'mod_certificatepro')];

        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return $menu;
        }

        $items = \grade_item::fetch_all(['courseid' => $coursecontext->instanceid]);
        if (!$items) {
            return $menu;
        }

        foreach ($items as $item) {
            if ($item->itemtype === 'course') {
                continue;
            }
            $name = $item->get_name(true);
            if ($name === null || $name === '') {
                continue;
            }
            $menu['item_' . $item->id] = format_string($name, true, ['context' => $coursecontext]);
        }

        return $menu;
    }

    /**
     * The grade item a stored reference points at.
     *
     * @param string $reference course, or item_ followed by a grade item id
     * @param render_context $ctx the render context
     * @return \grade_item|null
     */
    public static function item(string $reference, render_context $ctx): ?\grade_item {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $course = $ctx->get_course();
        if ($course === null) {
            return null;
        }

        if ($reference === 'course' || $reference === '') {
            $item = \grade_item::fetch_course_item($course->id);
            return $item ?: null;
        }

        if (str_starts_with($reference, 'item_')) {
            $id = (int) substr($reference, 5);
            $item = \grade_item::fetch(['id' => $id, 'courseid' => $course->id]);
            return $item ?: null;
        }

        return null;
    }

    /**
     * The name of the grade item a stored reference points at.
     *
     * @param string $reference course, or item_ followed by a grade item id
     * @param render_context $ctx the render context
     * @return string
     */
    public static function item_name(string $reference, render_context $ctx): string {
        $item = self::item($reference, $ctx);
        if ($item === null) {
            return $ctx->is_preview() ? get_string('sample_gradeitem', 'mod_certificatepro') : '';
        }
        if ($item->itemtype === 'course') {
            return get_string('coursegrade', 'mod_certificatepro');
        }
        return format_string($item->get_name(true), true, ['context' => $ctx->get_context()]);
    }

    /**
     * A recipient's grade, written in the requested form.
     *
     * @param string $reference course, or item_ followed by a grade item id
     * @param string $format percent, points, pointsmax, letter or passfail
     * @param int $decimals how many decimal places to show
     * @param render_context $ctx the render context
     * @param array $wording the pass and fail words to use
     * @return string
     */
    public static function format(string $reference, string $format, int $decimals,
            render_context $ctx, array $wording = []): string {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $user = $ctx->get_user();
        $item = self::item($reference, $ctx);

        if ($item === null || $user === null) {
            return $ctx->is_preview() ? self::sample($format, $decimals, $wording) : '';
        }

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id]);
        if (!$grade || $grade->finalgrade === null) {
            return '';
        }

        $final = (float) $grade->finalgrade;
        $min = (float) $item->grademin;
        $max = (float) $item->grademax;

        switch ($format) {
            case 'points':
                return format_float($final, $decimals);

            case 'pointsmax':
                return format_float($final, $decimals) . ' / ' . format_float($max, $decimals);

            case 'letter':
                return grade_format_gradevalue($final, $item, true, GRADE_DISPLAY_TYPE_LETTER, $decimals);

            case 'passfail':
                $passmark = $item->gradepass > 0 ? (float) $item->gradepass : null;
                if ($passmark === null) {
                    return '';
                }
                return $final >= $passmark
                    ? self::word($wording, 'pass')
                    : self::word($wording, 'fail');

            default:
                $range = $max - $min;
                if ($range <= 0) {
                    return '';
                }
                $percent = (($final - $min) / $range) * 100;
                return format_float($percent, $decimals) . '%';
        }
    }

    /**
     * A believable grade for the designer preview.
     *
     * @param string $format percent, points, pointsmax, letter or passfail
     * @param int $decimals how many decimal places to show
     * @param array $wording the pass and fail words to use
     * @return string
     */
    protected static function sample(string $format, int $decimals, array $wording): string {
        return match ($format) {
            'points' => format_float(87, $decimals),
            'pointsmax' => format_float(87, $decimals) . ' / ' . format_float(100, $decimals),
            'letter' => 'A',
            'passfail' => self::word($wording, 'pass'),
            default => format_float(87, $decimals) . '%',
        };
    }
    /**
     * Configured pass/fail wording, falling back to the defaults when left blank.
     *
     * @param array $wording
     * @param string $key pass or fail
     * @return string
     */
    protected static function word(array $wording, string $key): string {
        $value = trim((string)($wording[$key] ?? ''));
        return $value !== '' ? $value : get_string('grade' . $key . 'default', 'mod_certificatepro');
    }
}

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

namespace mod_certificatepro\local\zone;

/**
 * The catalogue of layout zones used by the designer.
 *
 * A zone is a named, conventional region of a certificate: the place a title
 * belongs, the place a signature belongs, and so on. Zones are expressed as
 * fractions of the page so that a single definition works for any page size,
 * and are resolved to millimetres by {@see self::resolve()}.
 *
 * The designer draws each zone as a dotted rectangle with its label the moment
 * a dragged element comes within range, and snaps the element into the zone on
 * release. Nothing about a zone is mandatory: an author can always drop an
 * element anywhere, and free positioning is preserved to three decimal places.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zone_library {
    /** @var string Landscape orientation. */
    public const LANDSCAPE = 'L';

    /** @var string Portrait orientation. */
    public const PORTRAIT = 'P';

    /** @var string Zones that hold a headline. */
    public const ROLE_TITLE = 'title';

    /** @var string Zones that hold the recipient. */
    public const ROLE_RECIPIENT = 'recipient';

    /** @var string Zones that hold running text. */
    public const ROLE_BODY = 'body';

    /** @var string Zones that hold small metadata. */
    public const ROLE_META = 'meta';

    /** @var string Zones that hold a signature block. */
    public const ROLE_SIGNATURE = 'signature';

    /** @var string Zones that hold imagery. */
    public const ROLE_IMAGE = 'image';

    /** @var string Zones that hold verification furniture. */
    public const ROLE_VERIFY = 'verify';

    /** @var string Zones that span the whole page. */
    public const ROLE_CANVAS = 'canvas';

    /**
     * Every zone for the given orientation, as raw fractional definitions.
     *
     * @param string $orientation self::LANDSCAPE or self::PORTRAIT
     * @return array[] list of zone definition arrays
     */
    public static function definitions(string $orientation): array {
        if ($orientation === self::PORTRAIT) {
            return self::portrait_definitions();
        }
        return self::landscape_definitions();
    }

    /**
     * Zone definitions for a landscape page.
     *
     * @return array[]
     */
    protected static function landscape_definitions(): array {
        return [
            self::zone(
                'background',
                'canvas',
                self::ROLE_CANVAS,
                0.0,
                0.0,
                1.0,
                1.0,
                'tl',
                'C',
                'M',
                ['bgimage', 'artwork'],
                0,
                false
            ),
            self::zone(
                'border',
                'canvas',
                self::ROLE_CANVAS,
                0.0,
                0.0,
                1.0,
                1.0,
                'tl',
                'C',
                'M',
                ['border'],
                0,
                false
            ),

            self::zone(
                'logotopleft',
                'top',
                self::ROLE_IMAGE,
                0.060,
                0.055,
                0.180,
                0.105,
                'tl',
                'L',
                'T',
                ['image', 'themelogo', 'qrcode', 'userpicture']
            ),
            self::zone(
                'logotopcentre',
                'top',
                self::ROLE_IMAGE,
                0.400,
                0.045,
                0.200,
                0.115,
                'tc',
                'C',
                'T',
                ['image', 'themelogo', 'seal']
            ),
            self::zone(
                'logotopright',
                'top',
                self::ROLE_IMAGE,
                0.760,
                0.055,
                0.180,
                0.105,
                'tr',
                'R',
                'T',
                ['image', 'themelogo', 'qrcode']
            ),
            self::zone(
                'eyebrowtopcentre',
                'top',
                self::ROLE_META,
                0.250,
                0.135,
                0.500,
                0.045,
                'tc',
                'C',
                'M',
                ['text', 'categoryname', 'organisation']
            ),

            self::zone(
                'titlecentre',
                'headline',
                self::ROLE_TITLE,
                0.120,
                0.180,
                0.760,
                0.100,
                'tc',
                'C',
                'M',
                ['text', 'coursename'],
                12
            ),
            self::zone(
                'subtitlecentre',
                'headline',
                self::ROLE_TITLE,
                0.200,
                0.285,
                0.600,
                0.055,
                'tc',
                'C',
                'M',
                ['text', 'categoryname', 'gradeitemname'],
                8
            ),

            self::zone(
                'presentedto',
                'recipient',
                self::ROLE_BODY,
                0.280,
                0.350,
                0.440,
                0.045,
                'tc',
                'C',
                'M',
                ['text'],
                6
            ),
            self::zone(
                'recipientcentre',
                'recipient',
                self::ROLE_RECIPIENT,
                0.100,
                0.400,
                0.800,
                0.110,
                'tc',
                'C',
                'M',
                ['studentname', 'text', 'userfield'],
                14
            ),
            self::zone(
                'recipientrule',
                'recipient',
                self::ROLE_BODY,
                0.250,
                0.512,
                0.500,
                0.020,
                'tc',
                'C',
                'M',
                ['rule', 'text'],
                4
            ),

            self::zone(
                'bodycentre',
                'body',
                self::ROLE_BODY,
                0.140,
                0.545,
                0.720,
                0.090,
                'tc',
                'C',
                'T',
                ['text', 'coursename', 'richtext'],
                10
            ),
            self::zone(
                'coursecentre',
                'body',
                self::ROLE_BODY,
                0.170,
                0.640,
                0.660,
                0.060,
                'tc',
                'C',
                'M',
                ['coursename', 'text', 'gradeitemname'],
                10
            ),

            self::zone(
                'metaleft',
                'meta',
                self::ROLE_META,
                0.070,
                0.712,
                0.230,
                0.055,
                'tl',
                'L',
                'M',
                ['date', 'text', 'expiry', 'grade', 'code', 'serial'],
                8
            ),
            self::zone(
                'metacentre',
                'meta',
                self::ROLE_META,
                0.385,
                0.700,
                0.230,
                0.048,
                'tc',
                'C',
                'M',
                ['grade', 'date', 'text', 'expiry'],
                6
            ),
            self::zone(
                'metaright',
                'meta',
                self::ROLE_META,
                0.700,
                0.712,
                0.230,
                0.055,
                'tr',
                'R',
                'M',
                ['code', 'serial', 'date', 'expiry', 'text'],
                8
            ),

            self::zone(
                'signatureleft',
                'signature',
                self::ROLE_SIGNATURE,
                0.090,
                0.775,
                0.200,
                0.125,
                'tl',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text'],
                10
            ),
            self::zone(
                'signaturecentre',
                'signature',
                self::ROLE_SIGNATURE,
                0.400,
                0.775,
                0.200,
                0.125,
                'tc',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text', 'seal'],
                8
            ),
            self::zone(
                'signatureright',
                'signature',
                self::ROLE_SIGNATURE,
                0.665,
                0.775,
                0.200,
                0.125,
                'tr',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text'],
                10
            ),

            self::zone(
                'sealbottomcentre',
                'signature',
                self::ROLE_IMAGE,
                0.445,
                0.755,
                0.110,
                0.133,
                'tc',
                'C',
                'M',
                ['seal', 'image', 'themelogo'],
                8
            ),
            self::zone(
                'sealbottomright',
                'signature',
                self::ROLE_IMAGE,
                0.740,
                0.715,
                0.110,
                0.155,
                'tr',
                'C',
                'M',
                ['seal', 'image'],
                6
            ),

            self::zone(
                'qrbottomright',
                'footer',
                self::ROLE_VERIFY,
                0.885,
                0.790,
                0.070,
                0.099,
                'br',
                'R',
                'B',
                ['qrcode'],
                12
            ),
            self::zone(
                'qrbottomleft',
                'footer',
                self::ROLE_VERIFY,
                0.045,
                0.790,
                0.070,
                0.099,
                'bl',
                'L',
                'B',
                ['qrcode'],
                10
            ),
            self::zone(
                'codebottomcentre',
                'footer',
                self::ROLE_VERIFY,
                0.310,
                0.906,
                0.380,
                0.040,
                'bc',
                'C',
                'B',
                ['code', 'serial', 'text', 'verifyurl'],
                10
            ),
            self::zone(
                'footerleft',
                'footer',
                self::ROLE_META,
                0.060,
                0.906,
                0.230,
                0.040,
                'bl',
                'L',
                'B',
                ['text', 'code', 'serial', 'date'],
                6
            ),
            self::zone(
                'footerright',
                'footer',
                self::ROLE_META,
                0.710,
                0.906,
                0.230,
                0.040,
                'br',
                'R',
                'B',
                ['text', 'code', 'serial', 'verifyurl'],
                6
            ),
        ];
    }

    /**
     * Zone definitions for a portrait page.
     *
     * @return array[]
     */
    protected static function portrait_definitions(): array {
        return [
            self::zone(
                'background',
                'canvas',
                self::ROLE_CANVAS,
                0.0,
                0.0,
                1.0,
                1.0,
                'tl',
                'C',
                'M',
                ['bgimage', 'artwork'],
                0,
                false
            ),
            self::zone(
                'border',
                'canvas',
                self::ROLE_CANVAS,
                0.0,
                0.0,
                1.0,
                1.0,
                'tl',
                'C',
                'M',
                ['border'],
                0,
                false
            ),

            self::zone(
                'logotopcentre',
                'top',
                self::ROLE_IMAGE,
                0.375,
                0.055,
                0.250,
                0.085,
                'tc',
                'C',
                'T',
                ['image', 'themelogo', 'seal']
            ),
            self::zone(
                'logotopleft',
                'top',
                self::ROLE_IMAGE,
                0.080,
                0.055,
                0.220,
                0.075,
                'tl',
                'L',
                'T',
                ['image', 'themelogo']
            ),
            self::zone(
                'logotopright',
                'top',
                self::ROLE_IMAGE,
                0.700,
                0.055,
                0.220,
                0.075,
                'tr',
                'R',
                'T',
                ['image', 'themelogo']
            ),
            self::zone(
                'eyebrowtopcentre',
                'top',
                self::ROLE_META,
                0.200,
                0.155,
                0.600,
                0.035,
                'tc',
                'C',
                'M',
                ['text', 'categoryname', 'organisation']
            ),

            self::zone(
                'titlecentre',
                'headline',
                self::ROLE_TITLE,
                0.100,
                0.195,
                0.800,
                0.075,
                'tc',
                'C',
                'M',
                ['text', 'coursename'],
                12
            ),
            self::zone(
                'subtitlecentre',
                'headline',
                self::ROLE_TITLE,
                0.180,
                0.278,
                0.640,
                0.042,
                'tc',
                'C',
                'M',
                ['text', 'categoryname', 'gradeitemname'],
                8
            ),

            self::zone(
                'presentedto',
                'recipient',
                self::ROLE_BODY,
                0.270,
                0.335,
                0.460,
                0.035,
                'tc',
                'C',
                'M',
                ['text'],
                6
            ),
            self::zone(
                'recipientcentre',
                'recipient',
                self::ROLE_RECIPIENT,
                0.080,
                0.378,
                0.840,
                0.085,
                'tc',
                'C',
                'M',
                ['studentname', 'text', 'userfield'],
                14
            ),
            self::zone(
                'recipientrule',
                'recipient',
                self::ROLE_BODY,
                0.230,
                0.468,
                0.540,
                0.015,
                'tc',
                'C',
                'M',
                ['rule', 'text'],
                4
            ),

            self::zone(
                'bodycentre',
                'body',
                self::ROLE_BODY,
                0.120,
                0.500,
                0.760,
                0.085,
                'tc',
                'C',
                'T',
                ['text', 'coursename', 'richtext'],
                10
            ),
            self::zone(
                'coursecentre',
                'body',
                self::ROLE_BODY,
                0.150,
                0.595,
                0.700,
                0.050,
                'tc',
                'C',
                'M',
                ['coursename', 'text', 'gradeitemname'],
                10
            ),

            self::zone(
                'metaleft',
                'meta',
                self::ROLE_META,
                0.090,
                0.672,
                0.320,
                0.042,
                'tl',
                'L',
                'M',
                ['date', 'text', 'expiry', 'grade', 'code', 'serial'],
                8
            ),
            self::zone(
                'metaright',
                'meta',
                self::ROLE_META,
                0.590,
                0.672,
                0.320,
                0.042,
                'tr',
                'R',
                'M',
                ['code', 'serial', 'date', 'expiry', 'text'],
                8
            ),
            self::zone(
                'metacentre',
                'meta',
                self::ROLE_META,
                0.340,
                0.700,
                0.320,
                0.040,
                'tc',
                'C',
                'M',
                ['grade', 'date', 'text', 'expiry'],
                6
            ),

            self::zone(
                'signatureleft',
                'signature',
                self::ROLE_SIGNATURE,
                0.090,
                0.762,
                0.290,
                0.105,
                'tl',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text'],
                10
            ),
            self::zone(
                'signatureright',
                'signature',
                self::ROLE_SIGNATURE,
                0.620,
                0.762,
                0.290,
                0.105,
                'tr',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text'],
                10
            ),
            self::zone(
                'signaturecentre',
                'signature',
                self::ROLE_SIGNATURE,
                0.355,
                0.762,
                0.290,
                0.105,
                'tc',
                'C',
                'B',
                ['signature', 'teachername', 'image', 'text', 'seal'],
                6
            ),

            self::zone(
                'sealbottomcentre',
                'signature',
                self::ROLE_IMAGE,
                0.430,
                0.752,
                0.140,
                0.098,
                'tc',
                'C',
                'M',
                ['seal', 'image', 'themelogo'],
                8
            ),

            self::zone(
                'qrbottomright',
                'footer',
                self::ROLE_VERIFY,
                0.820,
                0.882,
                0.100,
                0.071,
                'br',
                'R',
                'B',
                ['qrcode'],
                12
            ),
            self::zone(
                'qrbottomleft',
                'footer',
                self::ROLE_VERIFY,
                0.080,
                0.882,
                0.100,
                0.071,
                'bl',
                'L',
                'B',
                ['qrcode'],
                10
            ),
            self::zone(
                'codebottomcentre',
                'footer',
                self::ROLE_VERIFY,
                0.230,
                0.898,
                0.540,
                0.030,
                'bc',
                'C',
                'B',
                ['code', 'serial', 'text', 'verifyurl'],
                10
            ),
            self::zone(
                'footerleft',
                'footer',
                self::ROLE_META,
                0.080,
                0.934,
                0.270,
                0.028,
                'bl',
                'L',
                'B',
                ['text', 'code', 'serial', 'date'],
                6
            ),
            self::zone(
                'footerright',
                'footer',
                self::ROLE_META,
                0.650,
                0.934,
                0.270,
                0.028,
                'br',
                'R',
                'B',
                ['text', 'code', 'serial', 'verifyurl'],
                6
            ),
        ];
    }

    /**
     * Build a single zone definition.
     *
     * @param string $id zone identifier, also the lang string suffix
     * @param string $band visual grouping used by the designer sidebar
     * @param string $role semantic role, one of the ROLE_ constants
     * @param float $x left edge as a fraction of page width
     * @param float $y top edge as a fraction of page height
     * @param float $w width as a fraction of page width
     * @param float $h height as a fraction of page height
     * @param string $refpoint suggested anchor point for elements docked here
     * @param string $alignment suggested horizontal text alignment
     * @param string $valignment suggested vertical alignment
     * @param string[] $accepts element types this zone is a natural home for
     * @param int $priority magnetic strength, higher wins when zones overlap
     * @param bool $snappable whether dragging can snap into this zone
     * @return array
     */
    protected static function zone(string $id, string $band, string $role, float $x, float $y, float $w,
            float $h, string $refpoint, string $alignment, string $valignment, array $accepts,
            int $priority = 5, bool $snappable = true): array {
        return [
            'id' => $id,
            'band' => $band,
            'role' => $role,
            'fx' => $x,
            'fy' => $y,
            'fw' => $w,
            'fh' => $h,
            'refpoint' => $refpoint,
            'alignment' => $alignment,
            'valignment' => $valignment,
            'accepts' => $accepts,
            'priority' => $priority,
            'snappable' => $snappable,
        ];
    }

    /**
     * Resolve every zone for a page to absolute millimetres, with labels.
     *
     * @param string $orientation self::LANDSCAPE or self::PORTRAIT
     * @param float $pagewidth page width in millimetres
     * @param float $pageheight page height in millimetres
     * @return array[] zone definitions with x, y, w, h in millimetres and a translated label
     */
    public static function resolve(string $orientation, float $pagewidth, float $pageheight): array {
        $resolved = [];
        foreach (self::definitions($orientation) as $zone) {
            $zone['x'] = round($zone['fx'] * $pagewidth, 3);
            $zone['y'] = round($zone['fy'] * $pageheight, 3);
            $zone['w'] = round($zone['fw'] * $pagewidth, 3);
            $zone['h'] = round($zone['fh'] * $pageheight, 3);
            $zone['label'] = get_string('zone_' . $zone['id'], 'mod_certificatepro');
            $zone['bandlabel'] = get_string('zoneband_' . $zone['band'], 'mod_certificatepro');
            $resolved[$zone['id']] = $zone;
        }
        return $resolved;
    }

    /**
     * Anchor a box of the given size inside a zone, honouring the zone's reference point.
     *
     * Returns the stored posx and posy for an element, which are always the
     * coordinates of the element's reference point, not of its top left corner.
     *
     * @param array $zone a zone resolved by {@see self::resolve()}
     * @param float $width the element width in millimetres
     * @param float $height the element height in millimetres
     * @return array{posx: float, posy: float} the anchored coordinates
     */
    public static function anchor(array $zone, float $width, float $height): array {
        $refpoint = $zone['refpoint'];
        $horizontal = substr($refpoint, 1, 1);
        $vertical = substr($refpoint, 0, 1);

        $x = match ($horizontal) {
            'c' => $zone['x'] + ($zone['w'] / 2),
            'r' => $zone['x'] + $zone['w'],
            default => $zone['x'],
        };

        $y = match ($vertical) {
            'm' => $zone['y'] + ($zone['h'] / 2),
            'b' => $zone['y'] + $zone['h'],
            default => $zone['y'],
        };

        // Keep the box inside the zone when it is anchored to an edge.
        if ($horizontal === 'l') {
            $x = min($x, $zone['x'] + max(0.0, $zone['w'] - $width));
        }
        if ($vertical === 't') {
            $y = min($y, $zone['y'] + max(0.0, $zone['h'] - $height));
        }

        return ['posx' => round($x, 3), 'posy' => round($y, 3)];
    }

    /**
     * The zones a given element type is a natural fit for.
     *
     * @param string $orientation self::LANDSCAPE or self::PORTRAIT
     * @param string $elementtype the element type identifier
     * @return string[] zone identifiers, strongest fit first
     */
    public static function zones_for_element(string $orientation, string $elementtype): array {
        $matches = [];
        foreach (self::definitions($orientation) as $zone) {
            if (in_array($elementtype, $zone['accepts'], true)) {
                $matches[$zone['id']] = $zone['priority'];
            }
        }
        arsort($matches);
        return array_keys($matches);
    }

    /**
     * The static alignment guides for a page: margins, centre lines and thirds.
     *
     * @param float $pagewidth page width in millimetres
     * @param float $pageheight page height in millimetres
     * @param float $marginleft left margin in millimetres
     * @param float $marginright right margin in millimetres
     * @param float $margintop top margin in millimetres
     * @param float $marginbottom bottom margin in millimetres
     * @return array{vertical: array[], horizontal: array[]}
     */
    public static function guides(float $pagewidth, float $pageheight, float $marginleft,
            float $marginright, float $margintop, float $marginbottom): array {
        $vertical = [
            ['pos' => round($marginleft, 3), 'type' => 'margin', 'strength' => 8],
            ['pos' => round($pagewidth - $marginright, 3), 'type' => 'margin', 'strength' => 8],
            ['pos' => round($pagewidth / 2, 3), 'type' => 'centre', 'strength' => 12],
            ['pos' => round($pagewidth / 3, 3), 'type' => 'third', 'strength' => 4],
            ['pos' => round($pagewidth * 2 / 3, 3), 'type' => 'third', 'strength' => 4],
            ['pos' => round($pagewidth / 4, 3), 'type' => 'quarter', 'strength' => 3],
            ['pos' => round($pagewidth * 3 / 4, 3), 'type' => 'quarter', 'strength' => 3],
        ];
        $horizontal = [
            ['pos' => round($margintop, 3), 'type' => 'margin', 'strength' => 8],
            ['pos' => round($pageheight - $marginbottom, 3), 'type' => 'margin', 'strength' => 8],
            ['pos' => round($pageheight / 2, 3), 'type' => 'centre', 'strength' => 12],
            ['pos' => round($pageheight / 3, 3), 'type' => 'third', 'strength' => 4],
            ['pos' => round($pageheight * 2 / 3, 3), 'type' => 'third', 'strength' => 4],
            // The optical centre sits slightly above the geometric centre and is
            // where a headline wants to be on a certificate.
            ['pos' => round($pageheight * 0.455, 3), 'type' => 'optical', 'strength' => 6],
        ];
        return ['vertical' => $vertical, 'horizontal' => $horizontal];
    }

    /**
     * The known page sizes, in millimetres, keyed by identifier.
     *
     * @return array[] each entry has a width, a height and a translated name
     */
    public static function page_sizes(): array {
        return [
            'A4' => ['w' => 297.0, 'h' => 210.0, 'name' => 'A4'],
            'A3' => ['w' => 420.0, 'h' => 297.0, 'name' => 'A3'],
            'A5' => ['w' => 210.0, 'h' => 148.0, 'name' => 'A5'],
            'LETTER' => ['w' => 279.4, 'h' => 215.9, 'name' => 'Letter'],
            'LEGAL' => ['w' => 355.6, 'h' => 215.9, 'name' => 'Legal'],
            'TABLOID' => ['w' => 431.8, 'h' => 279.4, 'name' => 'Tabloid'],
        ];
    }

    /**
     * Width and height in millimetres for a page size in a given orientation.
     *
     * @param string $pagesize a key from {@see self::page_sizes()}
     * @param string $orientation self::LANDSCAPE or self::PORTRAIT
     * @return array{0: float, 1: float} width then height
     */
    public static function dimensions(string $pagesize, string $orientation): array {
        $sizes = self::page_sizes();
        $size = $sizes[$pagesize] ?? $sizes['A4'];
        // Definitions are stored landscape first.
        if ($orientation === self::PORTRAIT) {
            return [$size['h'], $size['w']];
        }
        return [$size['w'], $size['h']];
    }
}

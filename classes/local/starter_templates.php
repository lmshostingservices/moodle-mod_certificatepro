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

use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\zone\zone_library;
use mod_certificatepro\template;

/**
 * The designs a new certificate can start from.
 *
 * Every starter is complete: it has a title, a recipient, a citation, a course
 * name, a date, a signature block, a seal, a verification code and a QR code,
 * each already docked into the zone it belongs in. Nobody should have to build
 * a certificate from an empty page to find out what a certificate needs.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class starter_templates {
    /**
     * The starters on offer.
     *
     * @return array<string, array> keyed by identifier
     */
    public static function catalogue(): array {
        return [
            'classic' => [
                'name' => get_string('starter_classic', 'mod_certificatepro'),
                'description' => get_string('starter_classic_desc', 'mod_certificatepro'),
                'orientation' => zone_library::LANDSCAPE,
                'pairing' => 'heritage',
            ],
            'ceremonial' => [
                'name' => get_string('starter_ceremonial', 'mod_certificatepro'),
                'description' => get_string('starter_ceremonial_desc', 'mod_certificatepro'),
                'orientation' => zone_library::LANDSCAPE,
                'pairing' => 'ceremonial',
            ],
            'modern' => [
                'name' => get_string('starter_modern', 'mod_certificatepro'),
                'description' => get_string('starter_modern_desc', 'mod_certificatepro'),
                'orientation' => zone_library::LANDSCAPE,
                'pairing' => 'modernist',
            ],
            'corporate' => [
                'name' => get_string('starter_corporate', 'mod_certificatepro'),
                'description' => get_string('starter_corporate_desc', 'mod_certificatepro'),
                'orientation' => zone_library::LANDSCAPE,
                'pairing' => 'corporate',
            ],
            'portrait' => [
                'name' => get_string('starter_portrait', 'mod_certificatepro'),
                'description' => get_string('starter_portrait_desc', 'mod_certificatepro'),
                'orientation' => zone_library::PORTRAIT,
                'pairing' => 'editorial',
            ],
            'blank' => [
                'name' => get_string('starter_blank', 'mod_certificatepro'),
                'description' => get_string('starter_blank_desc', 'mod_certificatepro'),
                'orientation' => zone_library::LANDSCAPE,
                'pairing' => 'heritage',
            ],
        ];
    }

    /**
     * Build a design from a starter.
     *
     * @param string $starter a key from {@see self::catalogue()}
     * @param string $name the author facing name for the new design
     * @param \context $context the context that will own it
     * @return template
     */
    public static function build(string $starter, string $name, \context $context): template {
        global $DB;

        $catalogue = self::catalogue();
        $definition = $catalogue[$starter] ?? $catalogue['classic'];

        $template = template::create($name, $context);
        $template->update(['fontpairing' => $definition['pairing'], 'starter' => $starter]);

        $pageid = $template->add_page($definition['orientation'], 'A4');

        foreach (self::elements($starter) as $spec) {
            $elementid = $template->add_element($pageid, $spec['type'], $spec['zone'] ?? null);

            $record = $DB->get_record('certificatepro_elements', ['id' => $elementid]);
            if (!$record) {
                continue;
            }

            if (!empty($spec['name'])) {
                $record->name = $spec['name'];
            }
            if (!empty($spec['data'])) {
                $record->data = json_encode($spec['data'], JSON_PRESERVE_ZERO_FRACTION);
            }
            if (!empty($spec['style'])) {
                $existing = json_decode($record->style ?? '', true);
                $record->style = json_encode(array_merge(
                    is_array($existing) ? $existing : [],
                    $spec['style']
                ), JSON_PRESERVE_ZERO_FRACTION);
            }
            foreach (['width', 'height', 'posx', 'posy', 'refpoint'] as $field) {
                if (isset($spec[$field])) {
                    $record->{$field} = $spec[$field];
                }
            }

            // A seal has to stay circular. Its zone is wider than it is tall,
            // so clamping width and height independently would leave the box
            // oblong and the seal drawn small and off-centre inside it.
            if ($spec['type'] === 'seal' && !empty($spec['zone'])) {
                $page = $DB->get_record('certificatepro_pages', ['id' => $pageid]);
                $zones = zone_library::resolve($page->orientation,
                    (float) $page->width, (float) $page->height);
                if (isset($zones[$spec['zone']])) {
                    $side = min((float) $record->width, (float) $record->height,
                        $zones[$spec['zone']]['w'], $zones[$spec['zone']]['h']);
                    $record->width = $side;
                    $record->height = $side;
                }
            }

            // A starter must never ship an element larger than the zone it sits
            // in, or the design arrives with things already overlapping.
            if (!empty($spec['zone']) && $spec['type'] !== 'seal') {
                $page = $DB->get_record('certificatepro_pages', ['id' => $pageid]);
                $zones = zone_library::resolve($page->orientation,
                    (float) $page->width, (float) $page->height);
                if (isset($zones[$spec['zone']])) {
                    $record->width = min((float) $record->width, $zones[$spec['zone']]['w']);
                    $record->height = min((float) $record->height, $zones[$spec['zone']]['h']);
                }
            }
            if (isset($spec['zindex'])) {
                $record->zindex = $spec['zindex'];
            }

            $DB->update_record('certificatepro_elements', $record);

            // Re-dock now that the real size is known, so the element sits square in its zone.
            if (!empty($spec['zone'])) {
                self::redock($template, $pageid, $elementid, $spec['zone']);
            }
        }

        return $template;
    }

    /**
     * Reposition an element inside its zone after its size has changed.
     *
     * @param template $template the design
     * @param int $pageid the page the element sits on
     * @param int $elementid the element to reposition
     * @param string $zoneid the zone to dock into
     * @return void
     */
    protected static function redock(template $template, int $pageid, int $elementid,
            string $zoneid): void {
        global $DB;

        $page = $DB->get_record('certificatepro_pages', ['id' => $pageid]);
        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid]);
        if (!$page || !$record) {
            return;
        }

        $zones = zone_library::resolve($page->orientation, (float) $page->width, (float) $page->height);
        if (!isset($zones[$zoneid])) {
            return;
        }

        $anchored = zone_library::anchor($zones[$zoneid], (float) $record->width, (float) $record->height);

        $DB->update_record('certificatepro_elements', (object) [
            'id' => $elementid,
            'posx' => $anchored['posx'],
            'posy' => $anchored['posy'],
        ]);
    }

    /**
     * The elements that make up a starter.
     *
     * @param string $starter a key from {@see self::catalogue()}
     * @return array[]
     */
    protected static function elements(string $starter): array {
        if ($starter === 'blank') {
            return [];
        }

        $common = self::common_elements();

        return match ($starter) {
            'ceremonial' => self::ceremonial_elements($common),
            'modern' => self::modern_elements($common),
            'corporate' => self::corporate_elements($common),
            'portrait' => self::portrait_elements($common),
            default => self::classic_elements($common),
        };
    }

    /**
     * The elements every complete starter shares.
     *
     * @return array[] keyed by a short name so a starter can pick and adjust
     */
    protected static function common_elements(): array {
        return [
            'eyebrow' => [
                'type' => 'organisation',
                'zone' => 'eyebrowtopcentre',
                'name' => get_string('starterelement_organisation', 'mod_certificatepro'),
                'data' => ['source' => 'fullname'],
                'style' => ['font' => 'accent', 'scalerole' => 'eyebrow', 'size' => null,
                    'colour' => 'muted', 'tracking' => 2.2, 'case' => 'upper'],
                'width' => 150.0,
                'height' => 8.0,
            ],
            'title' => [
                'type' => 'text',
                'zone' => 'titlecentre',
                'name' => get_string('starterelement_title', 'mod_certificatepro'),
                'data' => ['content' => get_string('startertext_title', 'mod_certificatepro')],
                'style' => ['font' => 'heading', 'scalerole' => 'title', 'size' => null,
                    'colour' => 'primarydark', 'maxlines' => 1, 'fit' => true],
                'width' => 226.0,
                'height' => 21.0,
            ],
            'subtitle' => [
                'type' => 'text',
                'zone' => 'subtitlecentre',
                'name' => get_string('starterelement_subtitle', 'mod_certificatepro'),
                'data' => ['content' => get_string('startertext_subtitle', 'mod_certificatepro')],
                'style' => ['font' => 'accent', 'scalerole' => 'subtitle', 'size' => null,
                    'colour' => 'muted', 'tracking' => 1.4, 'maxlines' => 1],
                'width' => 178.0,
                'height' => 11.0,
            ],
            'presentedto' => [
                'type' => 'text',
                'zone' => 'presentedto',
                'name' => get_string('starterelement_presentedto', 'mod_certificatepro'),
                'data' => ['content' => get_string('startertext_presentedto', 'mod_certificatepro')],
                'style' => ['font' => 'body', 'scalerole' => 'presentedto', 'size' => null,
                    'colour' => 'muted', 'maxlines' => 1],
                'width' => 130.0,
                'height' => 9.0,
            ],
            'recipient' => [
                'type' => 'studentname',
                'zone' => 'recipientcentre',
                'name' => get_string('starterelement_recipient', 'mod_certificatepro'),
                'data' => ['nameformat' => 'fullname'],
                'style' => ['font' => 'heading', 'scalerole' => 'recipient', 'size' => null,
                    'colour' => 'ink', 'maxlines' => 2, 'fit' => true],
                'width' => 238.0,
                'height' => 23.0,
            ],
            'rule' => [
                'type' => 'rule',
                'zone' => 'recipientrule',
                'name' => get_string('starterelement_rule', 'mod_certificatepro'),
                'data' => ['linestyle' => 'tapered', 'thickness' => 0.9, 'colour' => 'primary'],
                'width' => 148.0,
                'height' => 4.0,
            ],
            'body' => [
                'type' => 'text',
                'zone' => 'bodycentre',
                'name' => get_string('starterelement_citation', 'mod_certificatepro'),
                'data' => ['content' => get_string('startertext_citation', 'mod_certificatepro')],
                'style' => ['font' => 'body', 'scalerole' => 'body', 'size' => null,
                    'colour' => 'ink', 'lineheight' => 1.45, 'maxlines' => 3, 'fit' => true],
                'width' => 214.0,
                'height' => 19.0,
            ],
            'course' => [
                'type' => 'coursename',
                'zone' => 'coursecentre',
                'name' => get_string('starterelement_course', 'mod_certificatepro'),
                'data' => ['coursenamefield' => 'fullname'],
                'style' => ['font' => 'heading', 'scalerole' => 'course', 'size' => null,
                    'colour' => 'primarydark', 'maxlines' => 2, 'fit' => true],
                'width' => 196.0,
                'height' => 13.0,
            ],
            'date' => [
                'type' => 'date',
                'zone' => 'metaleft',
                'name' => get_string('starterelement_date', 'mod_certificatepro'),
                'data' => [
                    'datesource' => 'issued',
                    'dateformat' => '%d %B %Y',
                    'dateprefix' => get_string('startertext_dateprefix', 'mod_certificatepro'),
                    'datetimezone' => '99',
                    'datenumerals' => 'default',
                ],
                'style' => ['font' => 'body', 'scalerole' => 'meta', 'size' => null,
                    'colour' => 'muted', 'maxlines' => 1],
                'width' => 68.0,
                'height' => 11.0,
            ],
            'grade' => [
                'type' => 'grade',
                'zone' => 'metacentre',
                'name' => get_string('starterelement_grade', 'mod_certificatepro'),
                'data' => [
                    'gradeitem' => 'course',
                    'gradeformat' => 'percent',
                    'decimals' => 0,
                    'label' => get_string('startertext_gradelabel', 'mod_certificatepro'),
                ],
                'style' => ['font' => 'body', 'scalerole' => 'meta', 'size' => null,
                    'colour' => 'muted', 'maxlines' => 1],
                'width' => 68.0,
                'height' => 11.0,
            ],
            'code' => [
                'type' => 'code',
                'zone' => 'metaright',
                'name' => get_string('starterelement_code', 'mod_certificatepro'),
                'data' => [
                    'label' => get_string('codelabeldefault', 'mod_certificatepro'),
                    'showurl' => 0,
                ],
                'style' => ['font' => 'accent', 'scalerole' => 'code', 'size' => null,
                    'colour' => 'muted', 'tracking' => 0.7, 'maxlines' => 1],
                'width' => 68.0,
                'height' => 11.0,
            ],
            'signatureleft' => [
                'type' => 'signature',
                'zone' => 'signatureleft',
                'name' => get_string('starterelement_signatureleft', 'mod_certificatepro'),
                'data' => [
                    'namesource' => 'teacher',
                    'signaturerole' => get_string('startertext_roleinstructor', 'mod_certificatepro'),
                    'showrule' => 1,
                    'keepaspect' => 1,
                ],
                'width' => 68.0,
                'height' => 29.0,
            ],
            'signatureright' => [
                'type' => 'signature',
                'zone' => 'signatureright',
                'name' => get_string('starterelement_signatureright', 'mod_certificatepro'),
                'data' => [
                    'namesource' => 'custom',
                    'signaturename' => '',
                    'signaturerole' => get_string('startertext_roledirector', 'mod_certificatepro'),
                    'showrule' => 1,
                    'keepaspect' => 1,
                ],
                'width' => 68.0,
                'height' => 29.0,
            ],
            'seal' => [
                'type' => 'seal',
                'zone' => 'sealbottomcentre',
                'name' => get_string('starterelement_seal', 'mod_certificatepro'),
                'data' => [
                    'sealedge' => seal_engine::EDGE_DOUBLE,
                    'sealring' => seal_engine::RING_BOTH,
                    'sealmark' => seal_engine::MARK_MONOGRAM,
                    'toptext' => get_string('startertext_sealtop', 'mod_certificatepro'),
                    'bottomtext' => get_string('startertext_sealbottom', 'mod_certificatepro'),
                    'centretext' => '{site.initials}',
                    'yeartext' => '',
                    'mottotext' => '',
                    'colour' => 'primary',
                    'metal' => 'accent',
                    'sealdivider' => 1,
                    'sealkeyline' => 1,
                ],
                'width' => 32.0,
                'height' => 32.0,
            ],
            'qr' => [
                'type' => 'qrcode',
                'zone' => 'qrbottomright',
                'name' => get_string('starterelement_qr', 'mod_certificatepro'),
                'data' => ['errorlevel' => 'H', 'usebrandcolour' => 0, 'quietzone' => 1],
                'width' => 24.0,
                'height' => 24.0,
            ],
            'verifyurl' => [
                'type' => 'verifyurl',
                'zone' => 'codebottomcentre',
                'name' => get_string('starterelement_verifyurl', 'mod_certificatepro'),
                'data' => ['label' => get_string('verifyurllabeldefault', 'mod_certificatepro')],
                'style' => ['font' => 'accent', 'scalerole' => 'footer', 'size' => null,
                    'colour' => 'muted', 'maxlines' => 1],
                'width' => 118.0,
                'height' => 8.0,
            ],
            'logo' => [
                'type' => 'themelogo',
                'zone' => 'logotopleft',
                'name' => get_string('starterelement_logo', 'mod_certificatepro'),
                'data' => ['logosource' => 'themefull', 'keepaspect' => 1, 'opacity' => 1.0,
                    'alttext' => get_string('startertext_logoalt', 'mod_certificatepro')],
                'width' => 50.0,
                'height' => 20.0,
            ],
        ];
    }

    /**
     * The full classic starter, which is what a new certificate gets by default.
     *
     * @param array $common the shared element definitions
     * @return array[]
     */
    protected static function classic_elements(array $common): array {
        $elements = [
            [
                'type' => 'border',
                'zone' => 'border',
                'name' => get_string('starterelement_border', 'mod_certificatepro'),
                'data' => ['borderstyle' => 'classic', 'inset' => 9.0, 'thickness' => 0.8,
                    'colour' => 'primary'],
                'zindex' => 0,
            ],
        ];

        $order = ['logo', 'eyebrow', 'title', 'subtitle', 'presentedto', 'recipient', 'rule',
            'body', 'course', 'date', 'grade', 'code', 'signatureleft', 'signatureright',
            'seal', 'qr', 'verifyurl'];

        $z = 1;
        foreach ($order as $key) {
            $element = $common[$key];
            $element['zindex'] = $z++;
            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * A more ornate starter with a security pattern and a starburst seal.
     *
     * @param array $common the shared element definitions
     * @return array[]
     */
    protected static function ceremonial_elements(array $common): array {
        $elements = self::classic_elements($common);

        foreach ($elements as &$element) {
            if ($element['type'] === 'border') {
                $element['data']['borderstyle'] = 'guilloche';
                $element['data']['inset'] = 7.0;
            }
            if ($element['type'] === 'seal') {
                // The ceremonial register: a scalloped edge and a laurel crest,
                // which is what an academic seal actually looks like.
                $element['data']['sealedge'] = seal_engine::EDGE_SCALLOP;
                $element['data']['sealmark'] = seal_engine::MARK_CREST;
            }
            if ($element['type'] === 'rule') {
                $element['data']['linestyle'] = 'double';
            }
        }
        unset($element);

        return $elements;
    }

    /**
     * A restrained starter with a single accent bar and no frame.
     *
     * @param array $common the shared element definitions
     * @return array[]
     */
    protected static function modern_elements(array $common): array {
        $elements = [
            [
                'type' => 'shape',
                'zone' => null,
                'name' => get_string('starterelement_accentbar', 'mod_certificatepro'),
                'data' => ['shape' => 'rectangle', 'fillcolour' => 'primary',
                    'strokecolour' => '', 'thickness' => 0.0],
                // A full-bleed bar has to be anchored top left. Zone-less
                // elements otherwise default to a middle-centre reference
                // point, which would put half of this bar off the page.
                'refpoint' => 'tl',
                'posx' => 0.0,
                'posy' => 0.0,
                'width' => 6.0,
                'height' => 210.0,
                'zindex' => 0,
            ],
        ];

        $order = ['eyebrow', 'title', 'presentedto', 'recipient', 'rule', 'body', 'course',
            'date', 'code', 'signatureleft', 'qr', 'verifyurl'];

        $z = 1;
        foreach ($order as $key) {
            $element = $common[$key];
            $element['zindex'] = $z++;
            if ($element['type'] === 'rule') {
                $element['data']['linestyle'] = 'solid';
                $element['data']['thickness'] = 0.4;
            }
            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * A banded starter that leads with the site logo.
     *
     * @param array $common the shared element definitions
     * @return array[]
     */
    protected static function corporate_elements(array $common): array {
        $elements = [
            [
                'type' => 'border',
                'zone' => 'border',
                'name' => get_string('starterelement_border', 'mod_certificatepro'),
                'data' => ['borderstyle' => 'band', 'inset' => 0.0, 'thickness' => 0.6,
                    'colour' => 'primary'],
                'zindex' => 0,
            ],
        ];

        $order = ['logo', 'eyebrow', 'title', 'subtitle', 'presentedto', 'recipient', 'body',
            'course', 'date', 'grade', 'code', 'signatureleft', 'signatureright', 'qr'];

        $z = 1;
        foreach ($order as $key) {
            $element = $common[$key];
            $element['zindex'] = $z++;
            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * How much smaller the type runs on a portrait page.
     *
     * A portrait A4 page is 210mm across where a landscape one is 297mm, so a
     * headline set for landscape simply does not fit. Shrink to fit would rescue
     * it at render time, but a starting design should never need rescuing: it
     * should look composed the moment it is created.
     *
     * @return array<string, float> point sizes keyed by the element's role
     */
    protected static function portrait_type_scale(): array {
        return [
            'eyebrow' => 9.5,
            'title' => 26.0,
            'subtitle' => 13.0,
            'presentedto' => 11.0,
            'recipient' => 24.0,
            'body' => 11.0,
            'course' => 15.0,
            'meta' => 9.5,
            'code' => 8.5,
            'footer' => 7.5,
        ];
    }

    /**
     * A portrait starter for wall mounting.
     *
     * @param array $common the shared element definitions
     * @return array[]
     */
    protected static function portrait_elements(array $common): array {
        $elements = [
            [
                'type' => 'border',
                'zone' => 'border',
                'name' => get_string('starterelement_border', 'mod_certificatepro'),
                'data' => ['borderstyle' => 'double', 'inset' => 10.0, 'thickness' => 0.7,
                    'colour' => 'primary'],
                'zindex' => 0,
            ],
        ];

        $order = ['logo', 'eyebrow', 'title', 'subtitle', 'presentedto', 'recipient', 'rule',
            'body', 'course', 'date', 'code', 'signatureleft', 'signatureright', 'seal',
            'qr', 'verifyurl'];

        $z = 1;
        foreach ($order as $key) {
            $element = $common[$key];
            $element['zindex'] = $z++;

            // Portrait pages are narrower, so the wide boxes need trimming and
            // the type needs setting smaller.
            if (isset($element['width'])) {
                $element['width'] = min($element['width'], 168.0);
            }

            $scale = self::portrait_type_scale();
            $role = $element['style']['scalerole'] ?? null;
            if ($role !== null && isset($scale[$role])) {
                $element['style']['size'] = $scale[$role];
            }

            // Headline boxes also need to be shorter, or they collide with the
            // line beneath them once the type comes down.
            if ($key === 'title') {
                $element['height'] = 14.0;
            } else if ($key === 'recipient') {
                $element['height'] = 13.0;
            } else if ($key === 'course') {
                $element['height'] = 9.0;
            } else if ($key === 'body') {
                $element['height'] = 15.0;
            }

            if ($key === 'logo') {
                $element['zone'] = 'logotopcentre';
                $element['width'] = 44.0;
                $element['height'] = 18.0;
            }

            $elements[] = $element;
        }

        return $elements;
    }
}

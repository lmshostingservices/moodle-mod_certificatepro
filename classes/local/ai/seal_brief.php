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

namespace mod_certificatepro\local\ai;

/**
 * A structured brief for a generated organisation seal.
 *
 * Seals are the one piece of a certificate that everybody wants and nobody can
 * draw. The author supplies the things only they know — the organisation's
 * name, the year it was founded, a motto, what it does — picks a silhouette and
 * a drawing style, and this composes the instruction.
 *
 * The constraints matter more here than for a background, because a seal is
 * small, it carries lettering, and the failure modes are specific: models reach
 * for laurel wreaths and fake gold gradients, they set type too small to print,
 * and they centre a motif underneath the lettering.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seal_brief {
    /** @var int The most characters that will fit legibly around a ring at print size. */
    public const RING_TEXT_LIMIT = 46;

    /** @var array The validated answers. */
    protected $answers;

    /**
     * The questions the author is asked.
     *
     * @return array[] keyed by field name
     */
    public static function fields(): array {
        return [
            'organisation' => [
                'label' => 'sealorganisation',
                'type' => 'text',
                'limit' => self::RING_TEXT_LIMIT,
                'default' => '',
                'required' => true,
            ],
            'established' => [
                'label' => 'sealestablished',
                'type' => 'year',
                'default' => '',
            ],
            'motto' => [
                'label' => 'sealmotto',
                'type' => 'text',
                'limit' => self::RING_TEXT_LIMIT,
                'default' => '',
            ],
            'subject' => [
                'label' => 'sealsubject',
                'type' => 'text',
                'limit' => 60,
                'default' => '',
            ],
            'shape' => [
                'label' => 'sealshape',
                'type' => 'choice',
                'default' => 'circle',
                'options' => [
                    'circle' => 'Circle — the classic seal',
                    'oval' => 'Oval — a little more formal',
                    'shield' => 'Shield — institutional, crest-like',
                    'hexagon' => 'Hexagon — modern and technical',
                    'roundel' => 'Rounded square — contemporary badge',
                    'starburst' => 'Scalloped edge — the pressed-wax silhouette',
                ],
            ],
            'style' => [
                'label' => 'sealstyle',
                'type' => 'choice',
                'default' => 'minimal',
                'options' => [
                    'minimal' => 'Modern minimal — few elements, generous space, one weight',
                    'monoline' => 'Monoline — everything drawn at a single line weight',
                    'engraved' => 'Engraved — fine concentric line work, banknote heritage',
                    'geometric' => 'Geometric — built from circles and straight lines only',
                    'wordmark' => 'Wordmark — the name is the seal, minimal ornament',
                    'layered' => 'Layered — flat tints stacked into a solid, confident mark',
                ],
            ],
            'emblem' => [
                'label' => 'sealemblem',
                'type' => 'choice',
                'default' => 'monogram',
                'options' => [
                    'monogram' => 'The organisation\'s initials',
                    'abstract' => 'An abstract geometric mark',
                    'symbolic' => 'A simple symbol drawn from what the organisation does',
                    'star' => 'A single star or asterisk',
                    'none' => 'Nothing in the middle — leave it open',
                ],
            ],
            'weight' => [
                'label' => 'sealweight',
                'type' => 'choice',
                'default' => 'balanced',
                'options' => [
                    'light' => 'Light — hairlines, quiet',
                    'balanced' => 'Balanced',
                    'strong' => 'Strong — heavy, reads at a glance',
                ],
            ],
        ];
    }

    /**
     * Build a brief from raw form input.
     *
     * @param array $input the submitted values
     * @return self
     */
    public static function from_input(array $input): self {
        $brief = new self();
        $brief->answers = [];

        foreach (self::fields() as $name => $field) {
            $value = $input[$name] ?? $field['default'];

            if ($field['type'] === 'choice') {
                $brief->answers[$name] = isset($field['options'][$value])
                    ? $value
                    : $field['default'];
                continue;
            }

            if ($field['type'] === 'year') {
                $year = (int) preg_replace('/\D/', '', (string) $value);
                $brief->answers[$name] = ($year >= 1000 && $year <= (int) date('Y') + 1)
                    ? (string) $year
                    : '';
                continue;
            }

            $text = preg_replace('/[^\p{L}\p{N}\s,.&\'-]/u', '', (string) $value);
            $text = preg_replace('/\s+/u', ' ', (string) $text);
            $brief->answers[$name] = trim(mb_substr((string) $text, 0, $field['limit'] ?? 60));
        }

        return $brief;
    }

    /**
     * The validated answers.
     *
     * @return array
     */
    public function answers(): array {
        return $this->answers;
    }

    /**
     * Problems the author should know about before generating.
     *
     * Returned as language string identifiers with their placeholder data, so
     * the form can show them inline rather than producing a seal that is
     * technically valid and practically unreadable.
     *
     * @return array[] each with a key and its placeholder value
     */
    public function warnings(): array {
        $out = [];

        if ($this->answers['organisation'] === '') {
            $out[] = ['key' => 'sealwarnnoname', 'a' => null];
        }

        $ring = mb_strlen($this->answers['organisation']) + mb_strlen($this->answers['motto']);
        if ($ring > self::RING_TEXT_LIMIT * 1.6) {
            $out[] = ['key' => 'sealwarnringlong', 'a' => $ring];
        }

        if ($this->answers['emblem'] === 'monogram'
                && mb_strlen($this->initials()) > 4) {
            $out[] = ['key' => 'sealwarnmonogramlong', 'a' => $this->initials()];
        }

        if ($this->answers['style'] === 'wordmark'
                && mb_strlen($this->answers['organisation']) > 28) {
            $out[] = ['key' => 'sealwarnwordmarklong', 'a' => null];
        }

        return $out;
    }

    /**
     * The organisation's initials, for a monogram.
     *
     * Words that carry no meaning in a monogram are skipped, which is why "The
     * University of Western Australia" gives UWA rather than TUOWA.
     *
     * @return string
     */
    public function initials(): string {
        $skip = ['the', 'of', 'and', 'for', 'de', 'du', 'la', 'le', 'el', 'van', 'von', 'di',
            'da', 'at', 'in', 'on'];
        $letters = '';

        foreach (preg_split('/\s+/u', $this->answers['organisation'] ?? '') as $word) {
            $clean = trim($word, " \t\n\r\0\x0B.,&'-");
            if ($clean === '' || in_array(mb_strtolower($clean), $skip, true)) {
                continue;
            }
            $letters .= mb_strtoupper(mb_substr($clean, 0, 1));
        }

        return mb_substr($letters, 0, 4);
    }

    /**
     * Compose the instruction sent to the model.
     *
     * @param array $palette the nine-role palette
     * @param float $diameter the seal's drawn diameter in millimetres
     * @return string
     */
    public function to_prompt(array $palette, float $diameter = 34.0): string {
        $fields = self::fields();
        $a = $this->answers;

        $lines = [];
        $lines[] = 'Design an organisation seal as a single SVG document. It will be printed on '
            . 'a certificate at ' . sprintf('%.0f', $diameter) . 'mm across — about the size of a '
            . 'large coin.';
        $lines[] = '';
        $lines[] = 'The organisation:';
        $lines[] = '- Name: ' . ($a['organisation'] !== '' ? $a['organisation'] : '(not given)');

        if ($a['established'] !== '') {
            $lines[] = '- Established: ' . $a['established'];
        }
        if ($a['motto'] !== '') {
            $lines[] = '- Motto: ' . $a['motto'];
        }
        if ($a['subject'] !== '') {
            $lines[] = '- What it does: ' . $a['subject'];
        }

        $lines[] = '';
        $lines[] = 'The design:';
        $lines[] = '- Silhouette: ' . $this->describe('shape') . '.';
        $lines[] = '- Drawing style: ' . $this->describe('style') . '.';
        $lines[] = '- Centre: ' . $this->describe('emblem') . '.';
        $lines[] = '- Weight: ' . $this->describe('weight') . '.';

        if ($a['emblem'] === 'monogram' && $this->initials() !== '') {
            $lines[] = '- Use the monogram "' . $this->initials() . '".';
        }

        $lines[] = '';
        $lines[] = 'Colours — use only these, which are the site\'s brand palette:';
        foreach (['primary', 'primarydark', 'primarylight', 'primarywash', 'accent', 'ink',
                'muted', 'rule', 'paper'] as $role) {
            if (isset($palette[$role])) {
                $lines[] = '    ' . $role . ': ' . $palette[$role];
            }
        }

        $lines[] = '';
        $lines[] = 'How to lay it out:';
        $lines[] = '- The organisation name runs around the upper part of the ring, reading '
            . 'left to right along the arc.';
        if ($a['established'] !== '' || $a['motto'] !== '') {
            $lines[] = '- The year and the motto sit along the lower part of the ring, '
                . 'separated from the name by a small mark on each side.';
        }
        $lines[] = '- Set ring text with <textPath> on a <path>, not as rotated glyphs.';
        $lines[] = '- Nothing may sit behind the ring lettering.';

        $lines[] = '';
        $lines[] = 'Hard constraints. A seal that breaks any of these is discarded:';
        $lines[] = '1. Vector only. No <image>, no raster, no external references, no <filter>, '
            . 'no blur, no drop shadow, no CSS.';
        $lines[] = sprintf('2. No lettering below %.1fmm cap height. At this diameter that means '
            . 'ring text of roughly %.1fmm and never smaller — small type fills in on a laser '
            . 'printer and disappears on a photocopier.', 1.6, max(1.8, $diameter * 0.058));
        $lines[] = '3. No gold, no metallic effect, no gradient meant to imitate foil or emboss. '
            . 'This is printed flat in the brand colour.';
        $lines[] = '4. No laurel wreaths, no ribbon banners, no rope borders, no eagles, no '
            . 'generic heraldry. It should look like it was designed this decade.';
        $lines[] = '5. It must read at 34mm and still read when photocopied in black and white, '
            . 'so carry contrast in value, not in hue.';
        $lines[] = '6. Square viewBox, the design centred and touching the edges of it.';
        $lines[] = '7. Keep it under 150 elements.';
        $lines[] = '8. Return the SVG and nothing else. No commentary, no code fence.';

        return implode("\n", $lines);
    }

    /**
     * The human-readable text of one answer.
     *
     * @param string $field the field name
     * @return string
     */
    protected function describe(string $field): string {
        $fields = self::fields();
        $value = $this->answers[$field] ?? '';
        $label = $fields[$field]['options'][$value] ?? (string) $value;
        return trim(str_replace('—', '-', $label));
    }

    /**
     * The procedural seal style that best matches this brief.
     *
     * Used when no model is configured, when the site is offline, or when
     * nothing drawable survived sanitising.
     *
     * @return string a style identifier understood by the seal engine
     */
    public function to_procedural_style(): string {
        $map = [
            'minimal' => 'modern',
            'monoline' => 'monoline',
            'engraved' => 'engraved',
            'geometric' => 'geometric',
            'wordmark' => 'wordmark',
            'layered' => 'layered',
        ];
        return $map[$this->answers['style']] ?? 'modern';
    }

    /**
     * The ring text, split into its upper and lower arcs.
     *
     * The procedural seal engine takes this directly, so the fallback carries
     * the same words as the generated version rather than a placeholder.
     *
     * @return array the top and bottom strings
     */
    public function ring_text(): array {
        $bottom = [];
        if ($this->answers['established'] !== '') {
            $bottom[] = 'EST. ' . $this->answers['established'];
        }
        if ($this->answers['motto'] !== '') {
            $bottom[] = mb_strtoupper($this->answers['motto']);
        }

        return [
            'top' => mb_strtoupper($this->answers['organisation']),
            'bottom' => implode(' · ', $bottom),
        ];
    }
}

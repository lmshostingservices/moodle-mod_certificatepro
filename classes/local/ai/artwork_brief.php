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

use mod_certificatepro\local\art\background_library;

/**
 * A structured brief for generated background artwork.
 *
 * The author is not asked to write a prompt. They answer a short set of
 * questions — what kind of shapes, how busy, what mood, which way it should
 * sit on the page — and this class composes the instruction that goes to the
 * model, complete with the hard constraints that a certificate background has
 * to satisfy and that a person writing free text would never think to state.
 *
 * The same answers also select the closest procedural style, so if no model is
 * configured, or the model returns nothing drawable, the author still gets
 * artwork that matches what they asked for.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artwork_brief {
    /** @var array The answers, already validated. */
    protected $answers;

    /**
     * The questions the author is asked, in order.
     *
     * Each carries its options and the language string identifier for its
     * label, so the form and the prompt never drift apart.
     *
     * @return array[] keyed by field name
     */
    public static function fields(): array {
        return [
            'shapes' => [
                'label' => 'briefshapes',
                'multiple' => true,
                'default' => ['geometric'],
                'options' => [
                    'geometric' => 'Geometric shapes — squares, circles, triangles, grids',
                    'lines' => 'Line work — rules, hatching, contours, fine engraving',
                    'organic' => 'Organic forms — flowing, natural, irregular',
                    'gradient' => 'Soft gradients — fields of light and colour, no hard edges',
                    'lattice' => 'Repeating pattern — tiling, lattice, modular system',
                    'none' => 'Almost nothing — a single mark or rule',
                ],
            ],
            'density' => [
                'label' => 'briefdensity',
                'multiple' => false,
                'default' => 'balanced',
                'options' => [
                    'sparse' => 'Very sparse — mostly empty paper',
                    'balanced' => 'Balanced — clearly there, still recedes',
                    'rich' => 'Rich — the artwork is part of the design language',
                ],
            ],
            'placement' => [
                'label' => 'briefplacement',
                'multiple' => false,
                'default' => 'edges',
                'options' => [
                    'edges' => 'Around the edges, framing the page',
                    'corner' => 'Anchored in one corner, asymmetric',
                    'bands' => 'Bands across the head and foot',
                    'full' => 'Across the whole page, fading through the middle',
                ],
            ],
            'mood' => [
                'label' => 'briefmood',
                'multiple' => false,
                'default' => 'professional',
                'options' => [
                    'formal' => 'Formal and ceremonial',
                    'professional' => 'Professional and understated',
                    'modern' => 'Modern and confident',
                    'friendly' => 'Warm and approachable',
                    'technical' => 'Technical and precise',
                    'secure' => 'Official, like a banknote or share certificate',
                ],
            ],
            'symmetry' => [
                'label' => 'briefsymmetry',
                'multiple' => false,
                'default' => 'asymmetric',
                'options' => [
                    'symmetric' => 'Symmetrical and balanced',
                    'asymmetric' => 'Asymmetric and composed',
                    'radial' => 'Radial, turning about a point',
                ],
            ],
            'motif' => [
                'label' => 'briefmotif',
                'multiple' => false,
                'default' => '',
                'options' => null,
            ],
        ];
    }

    /**
     * Build a brief from raw form input.
     *
     * Anything unrecognised falls back to the field's default, so a tampered
     * form cannot push arbitrary text into the model instruction except through
     * the one free-text field, which is length-capped and stripped.
     *
     * @param array $input the submitted values
     * @return self
     */
    public static function from_input(array $input): self {
        $brief = new self();
        $brief->answers = [];

        foreach (self::fields() as $name => $field) {
            $value = $input[$name] ?? $field['default'];

            if ($field['options'] === null) {
                // The single free-text field: motif keywords.
                $text = is_string($value) ? $value : '';
                $text = preg_replace('/[^\p{L}\p{N}\s,\'-]/u', '', $text);
                $brief->answers[$name] = trim(mb_substr((string) $text, 0, 120));
                continue;
            }

            if (!empty($field['multiple'])) {
                $chosen = [];
                foreach ((array) $value as $one) {
                    if (isset($field['options'][$one])) {
                        $chosen[] = $one;
                    }
                }
                $brief->answers[$name] = $chosen ?: $field['default'];
                continue;
            }

            $brief->answers[$name] = isset($field['options'][$value])
                ? $value
                : $field['default'];
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
     * Compose the instruction sent to the model.
     *
     * The constraints are not negotiable and are stated last, because that is
     * where they are attended to most reliably. They exist because a model left
     * to itself will happily centre a wreath behind the recipient's name, emit
     * a raster image, or use a filter that TCPDF cannot draw.
     *
     * @param array $palette the nine-role palette
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @return string
     */
    public function to_prompt(array $palette, float $width, float $height): string {
        $fields = self::fields();

        $shapes = [];
        foreach ($this->answers['shapes'] as $key) {
            $shapes[] = strtolower(strtok($fields['shapes']['options'][$key], '—'));
        }

        $lines = [];
        $lines[] = 'Draw a background for a printed certificate as a single SVG document.';
        $lines[] = '';
        $lines[] = 'What the author asked for:';
        $lines[] = '- Shape language: ' . implode(', ', array_map('trim', $shapes)) . '.';
        $lines[] = '- Density: ' . $this->describe('density') . '.';
        $lines[] = '- Placement: ' . $this->describe('placement') . '.';
        $lines[] = '- Mood: ' . $this->describe('mood') . '.';
        $lines[] = '- Symmetry: ' . $this->describe('symmetry') . '.';

        if ($this->answers['motif'] !== '') {
            $lines[] = '- Motifs to suggest, abstractly and never literally: '
                . $this->answers['motif'] . '.';
        }

        $lines[] = '';
        $lines[] = 'Page and palette:';
        $lines[] = sprintf(
            '- The canvas is %.0f by %.0f millimetres. Use viewBox="0 0 %.0f %.0f".',
            $width,
            $height,
            $width,
            $height
        );
        $lines[] = '- Use only these colours, which are the site\'s own brand palette:';
        foreach (['primary', 'primarydark', 'primarylight', 'primarywash', 'accent', 'ink',
                'muted', 'rule', 'paper'] as $role) {
            if (isset($palette[$role])) {
                $lines[] = '    ' . $role . ': ' . $palette[$role];
            }
        }

        $lines[] = '';
        $lines[] = 'Hard constraints. Artwork that breaks any of these is discarded:';
        $lines[] = sprintf(
            '1. Leave the rectangle from %.0f%% to %.0f%% of the width and %.0f%% to %.0f%% of the '
            . 'height almost empty. The certificate title and the recipient\'s name are printed '
            . 'there. A flat tint below 4%% opacity is acceptable; lines, shapes and text are not.',
            background_library::QUIET_U0 * 100,
            background_library::QUIET_U1 * 100,
            background_library::QUIET_V0 * 100,
            background_library::QUIET_V1 * 100
        );
        $lines[] = '2. Vector only. No <image>, no embedded raster, no external references.';
        $lines[] = '3. No <text> and no lettering of any kind. Text is placed by the certificate.';
        $lines[] = '4. No <filter>, no blur, no blend modes, no CSS. These do not print.';
        $lines[] = '5. Use <linearGradient> and <radialGradient> freely; both print correctly.';
        $lines[] = '6. Keep it under 400 elements so it draws quickly and stays a small file.';
        $lines[] = '7. It must still read when photocopied in black and white, so carry the '
            . 'contrast in value, not in hue alone.';
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
     * The procedural style that best matches this brief.
     *
     * Scored rather than mapped one to one, so every combination of answers
     * resolves to something sensible instead of falling through to a default.
     * This is what the author gets when no model is configured, when the site
     * is offline, or when nothing drawable survived sanitising.
     *
     * @return string a style identifier from the background library
     */
    public function to_procedural_style(): string {
        $scores = [];

        foreach (background_library::styles() as $id => $entry) {
            $score = 0.0;

            // Shape language to family.
            $familyfor = [
                'geometric' => ['bold', 'ceremonial'],
                'lines' => ['ceremonial', 'quiet'],
                'organic' => ['quiet', 'wash'],
                'gradient' => ['wash', 'bold'],
                'lattice' => ['ceremonial', 'quiet'],
                'none' => ['wash'],
            ];
            foreach ($this->answers['shapes'] as $shape) {
                if (in_array($entry['family'], $familyfor[$shape] ?? [], true)) {
                    $score += 3.0;
                }
            }

            // Density to presence.
            $presencefor = ['sparse' => 'soft', 'balanced' => 'soft', 'rich' => 'full'];
            if ($entry['presence'] === ($presencefor[$this->answers['density']] ?? '')) {
                $score += 2.5;
            }
            // A sparse brief should not land on the loudest member of a soft
            // family, so the quiet survivors get an extra nudge.
            if ($this->answers['density'] === 'sparse' && $entry['family'] === 'quiet') {
                $score += 1.5;
            }

            // Mood nudges.
            if ($this->answers['mood'] === 'secure'
                    && ($entry['family'] === 'ceremonial' || $id === 'engine_field')) {
                $score += 2.0;
            }
            if ($this->answers['mood'] === 'technical'
                    && in_array($entry['family'], ['quiet', 'bold'], true)) {
                $score += 1.5;
            }
            if ($this->answers['mood'] === 'formal' && $entry['family'] === 'ceremonial') {
                $score += 1.5;
            }
            if ($this->answers['mood'] === 'friendly'
                    && in_array($entry['family'], ['wash', 'quiet'], true)) {
                $score += 1.5;
            }
            if ($this->answers['mood'] === 'modern'
                    && in_array($entry['family'], ['bold', 'wash'], true)) {
                $score += 1.5;
            }
            if ($this->answers['mood'] === 'professional'
                    && in_array($entry['family'], ['wash', 'quiet'], true)) {
                $score += 1.0;
            }

            // Symmetry.
            if ($this->answers['symmetry'] === 'radial'
                    && in_array(
                        $id,
                        ['medallion_watermark', 'wax_seal_frame', 'rosette_corners',
                            'counter_rosette', 'watermark_field', 'vignette_field'],
                        true
                    )) {
                $score += 2.0;
            }
            if ($this->answers['symmetry'] === 'asymmetric'
                    && in_array(
                        $id,
                        ['corner_bloom', 'duotone_drift', 'colour_column',
                            'deep_field', 'drift_field'],
                        true
                    )) {
                $score += 1.5;
            }
            if ($this->answers['symmetry'] === 'symmetric'
                    && in_array(
                        $id,
                        ['guilloche_border', 'crest_frame', 'laurel_arc',
                            'cartouche_title', 'vignette_field'],
                        true
                    )) {
                $score += 1.5;
            }

            // Placement.
            if ($this->answers['placement'] === 'bands'
                    && in_array(
                        $id,
                        ['ceremonial_bands', 'lathe_ribbon', 'tonal_bands',
                            'laurel_arc', 'cartouche_title', 'counter_rosette'],
                        true
                    )) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'corner'
                    && in_array(
                        $id,
                        ['corner_bloom', 'colour_column', 'duotone_drift',
                            'deep_field'],
                        true
                    )) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'full'
                    && in_array(
                        $id,
                        ['engine_field', 'watermark_field', 'wash_gradient',
                            'vignette_field', 'medallion_watermark', 'relief_ruling'],
                        true
                    )) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'edges'
                    && in_array(
                        $id,
                        ['guilloche_border', 'rosette_corners', 'wax_seal_frame',
                            'crest_frame', 'engraved_plate'],
                        true
                    )) {
                $score += 2.0;
            }

            $scores[$id] = $score;
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $best ?: 'datum_rule';
    }

    /**
     * A short sentence describing what this brief asked for.
     *
     * Shown next to the generated artwork so the author can see why they got
     * what they got.
     *
     * @return string
     */
    public function summary(): string {
        $fields = self::fields();
        $shapes = [];
        foreach ($this->answers['shapes'] as $key) {
            $shapes[] = trim(strtok($fields['shapes']['options'][$key], '—'));
        }

        $summary = implode(' and ', $shapes) . ', ' . strtolower($this->describe('density'))
            . ', ' . strtolower($this->describe('placement'));

        if ($this->answers['motif'] !== '') {
            $summary .= ', suggesting ' . $this->answers['motif'];
        }

        return ucfirst($summary) . '.';
    }
}

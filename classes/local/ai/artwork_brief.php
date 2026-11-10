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
 * The author is not asked to write a prompt, and — since the wizard was rebuilt
 * — is not asked to imagine one either. Each option is drawn as a real page in
 * the design's own palette, produced by running this brief's own scoring with
 * that one answer substituted. The picture under an option is therefore the
 * page the author would get by choosing it, not an illustration of a word.
 *
 * That is why the scoring is exposed as {@see ranked_styles()} rather than
 * being hidden inside a single "best" answer: the gallery needs the whole
 * ranking so it can give each option a distinct picture, and the variant grid
 * needs it so nine tiles are nine different backgrounds rather than one
 * background nine times.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artwork_brief {
    /** @var int The seed every deterministic first answer is drawn at. */
    public const BASE_SEED = 7;

    /** @var array The answers, already validated. */
    protected $answers;

    /**
     * The questions the author is asked.
     *
     * @return array[] keyed by field name
     */
    public static function fields(): array {
        return [
            'shapes' => [
                'label' => 'briefshapes',
                'multiple' => false,
                'default' => 'geometric',
                'prefix' => 'wizshape_',
                'altprefix' => 'briefopt_shapes_',
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
                'label' => 'wizartdensity',
                'multiple' => false,
                'default' => 'balanced',
                'prefix' => 'wizdensity_',
                'altprefix' => 'briefopt_density_',
                'options' => [
                    'sparse' => 'Very sparse — mostly empty paper',
                    'balanced' => 'Balanced — clearly there, still recedes',
                    'rich' => 'Rich — the artwork is part of the design language',
                ],
            ],
            'placement' => [
                'label' => 'wizartplacement',
                'multiple' => false,
                'default' => 'edges',
                'prefix' => 'wizplacement_',
                'altprefix' => 'briefopt_placement_',
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
                'prefix' => 'wizmood_',
                'altprefix' => 'briefopt_mood_',
                'options' => [
                    'formal' => 'Formal and ceremonial',
                    'professional' => 'Professional and understated',
                    'modern' => 'Modern and confident',
                    'friendly' => 'Warm and approachable',
                    'technical' => 'Technical and precise',
                    'secure' => 'Official, like a banknote or share certificate',
                ],
            ],
            // Not asked. The variant grid walks all three so the author is
            // offered symmetrical and asymmetric compositions to choose
            // between, which is a better use of a screen than a question about
            // a word most people would answer at random.
            'symmetry' => [
                'label' => 'briefsymmetry',
                'multiple' => false,
                'default' => 'asymmetric',
                'prefix' => 'briefopt_symmetry_',
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
     * The screens the wizard shows, in order.
     *
     * @return array[] each with id, title, hint, fields and galleries
     */
    public static function screens(): array {
        return [
            [
                'id' => 'shapes',
                'title' => 'wizarttitleshapes',
                'hint' => 'wizarthintshapes',
                'fields' => [],
                'galleries' => ['shapes'],
            ],
            [
                'id' => 'presence',
                'title' => 'wizarttitlepresence',
                'hint' => 'wizarthintpresence',
                'fields' => [],
                'galleries' => ['density', 'placement'],
            ],
            [
                'id' => 'mood',
                'title' => 'wizarttitlemood',
                'hint' => 'wizarthintmood',
                'fields' => ['motif'],
                'galleries' => ['mood'],
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

            if ($field['options'] === null) {
                // The single free-text field: motif keywords.
                $text = is_string($value) ? $value : '';
                $text = preg_replace('/[^\p{L}\p{N}\s,\'-]/u', '', $text);
                $brief->answers[$name] = trim(mb_substr((string) $text, 0, 120));
                continue;
            }

            // A value arriving as an array is the old multi-select form of this
            // brief. Take the first recognised answer rather than rejecting it,
            // so a design saved before the rebuild still resolves.
            if (is_array($value)) {
                $value = reset($value);
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
     * This brief with some answers substituted.
     *
     * @param array $overrides answers to replace
     * @return self a new brief
     */
    public function with(array $overrides): self {
        return self::from_input($overrides + $this->answers);
    }

    /**
     * Every catalogue style, scored against this brief, best first.
     *
     * @return array style identifier to score
     */
    public function ranked_styles(): array {
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
            if (in_array($entry['family'], $familyfor[$this->answers['shapes']] ?? [], true)) {
                $score += 3.0;
            }

            // Density to presence.
            //
            // "Balanced" used to score exactly as "sparse" did — both asked for
            // the soft half of the catalogue — so the middle answer could never
            // reach a style with any real presence, and a wizard whose middle
            // setting produces a blank page is not offering a choice. Balanced
            // now leans towards the present half, which is what "clearly there,
            // still recedes" says on the tin.
            $presence = [
                'sparse' => ['soft' => 2.5, 'full' => 0.0],
                'balanced' => ['soft' => 1.0, 'full' => 1.8],
                'rich' => ['soft' => 0.0, 'full' => 2.5],
            ];
            $score += $presence[$this->answers['density']][$entry['presence']] ?? 0.0;

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
                    && in_array($id, ['medallion_watermark', 'wax_seal_frame', 'rosette_corners',
                        'counter_rosette', 'watermark_field', 'vignette_field'], true)) {
                $score += 2.0;
            }
            if ($this->answers['symmetry'] === 'asymmetric'
                    && in_array($id, ['corner_bloom', 'duotone_drift', 'colour_column',
                        'deep_field', 'drift_field'], true)) {
                $score += 1.5;
            }
            if ($this->answers['symmetry'] === 'symmetric'
                    && in_array($id, ['guilloche_border', 'crest_frame', 'laurel_arc',
                        'cartouche_title', 'vignette_field'], true)) {
                $score += 1.5;
            }

            // Placement.
            if ($this->answers['placement'] === 'bands'
                    && in_array($id, ['ceremonial_bands', 'lathe_ribbon', 'tonal_bands',
                        'laurel_arc', 'cartouche_title', 'counter_rosette'], true)) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'corner'
                    && in_array($id, ['corner_bloom', 'colour_column', 'duotone_drift',
                        'deep_field'], true)) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'full'
                    && in_array($id, ['engine_field', 'watermark_field', 'wash_gradient',
                        'vignette_field', 'medallion_watermark', 'relief_ruling'], true)) {
                $score += 2.0;
            }
            if ($this->answers['placement'] === 'edges'
                    && in_array($id, ['guilloche_border', 'rosette_corners', 'wax_seal_frame',
                        'crest_frame', 'engraved_plate'], true)) {
                $score += 2.0;
            }

            $scores[$id] = $score;
        }

        arsort($scores);

        return $scores;
    }

    /**
     * The procedural style that best matches this brief.
     *
     * @return string a style identifier from the background library
     */
    public function to_procedural_style(): string {
        $ranked = $this->ranked_styles();

        return (string) (array_key_first($ranked) ?: 'wash_gradient');
    }

    // ------------------------------------------------------------------
    // Variants.
    // ------------------------------------------------------------------

    /**
     * Write a style and seed as an opaque variant key.
     *
     * @param string $style the style identifier
     * @param int $seed the composition seed
     * @return string the key
     */
    public static function encode_variant(string $style, int $seed): string {
        return $style . '~' . $seed;
    }

    /**
     * Read a variant key back, discarding anything the catalogue does not know.
     *
     * @param string $key the key
     * @return array style and seed, or empty when the key is unusable
     */
    public static function decode_variant(string $key): array {
        $at = strrpos($key, '~');
        if ($at === false) {
            return [];
        }

        $style = substr($key, 0, $at);
        $seed = (int) substr($key, $at + 1);

        if (!background_library::exists($style) || $seed < 1 || $seed > 999) {
            return [];
        }

        return ['style' => $style, 'seed' => $seed];
    }

    /**
     * A spread of backgrounds this brief could resolve to.
     *
     * The first is always the brief's own best answer. The rest are the next
     * best styles, capped at three from any one family so the grid does not
     * fill up with six shades of the same wash, and drawn across the three
     * symmetry readings so the author is offered a real compositional choice.
     *
     * @param int $count how many to produce
     * @param string $like a variant key to stay close to, or an empty string
     * @return array[] each with key, style and seed
     */
    public function variants(int $count, string $like = ''): array {
        $seeds = [self::BASE_SEED, 23, 41];

        if ($like !== '') {
            $near = self::decode_variant($like);
            if ($near !== []) {
                return $this->near_variants($near, $count, $seeds);
            }
        }

        $styles = background_library::styles();
        $ranked = [];

        // Score under each symmetry reading and keep the best rank each style
        // ever achieves, so a composition that only makes sense as a radial one
        // is still reachable from a brief that never mentioned symmetry.
        foreach (['asymmetric', 'symmetric', 'radial'] as $symmetry) {
            foreach ($this->with(['symmetry' => $symmetry])->ranked_styles() as $id => $score) {
                $ranked[$id] = max($ranked[$id] ?? 0.0, $score);
            }
        }
        arsort($ranked);

        $perfamily = [];
        $out = [];
        $i = 0;

        foreach (array_keys($ranked) as $id) {
            if (count($out) >= $count) {
                break;
            }
            $family = $styles[$id]['family'];
            if (($perfamily[$family] ?? 0) >= 3) {
                continue;
            }
            $perfamily[$family] = ($perfamily[$family] ?? 0) + 1;

            $seed = $seeds[$i % count($seeds)];
            $i++;
            $out[] = ['key' => self::encode_variant($id, $seed), 'style' => $id, 'seed' => $seed];
        }

        return $out;
    }

    /**
     * More of the same: the chosen style at other seeds, then its family.
     *
     * @param array $near the decoded variant to stay close to
     * @param int $count how many to produce
     * @param int[] $seeds the seeds to draw at
     * @return array[] each with key, style and seed
     */
    protected function near_variants(array $near, int $count, array $seeds): array {
        $styles = background_library::styles();
        $family = $styles[$near['style']]['family'] ?? '';

        $out = [];
        $seen = [];

        $push = function (string $style, int $seed) use (&$out, &$seen, $count) {
            $key = self::encode_variant($style, $seed);
            if (isset($seen[$key]) || count($out) >= $count) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['key' => $key, 'style' => $style, 'seed' => $seed];
        };

        // The chosen one first, then two more castings of it. Only two: a
        // style whose composition barely moves with the seed would otherwise
        // fill the whole grid with itself under six identical captions, which
        // is not a set of alternatives.
        $push($near['style'], $near['seed']);
        foreach ([11, 29] as $seed) {
            $push($near['style'], $seed);
        }

        foreach (array_keys($this->ranked_styles()) as $id) {
            if (($styles[$id]['family'] ?? '') !== $family) {
                continue;
            }
            $push($id, $seeds[count($out) % count($seeds)]);
        }

        // Still short, because the family is small. Fall back to the ranking.
        foreach (array_keys($this->ranked_styles()) as $id) {
            $push($id, $seeds[count($out) % count($seeds)]);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // The model instruction.
    // ------------------------------------------------------------------

    /**
     * Compose the instruction sent to the model.
     *
     * @param array $palette the nine-role palette
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @return string
     */
    public function to_prompt(array $palette, float $width, float $height): string {
        $lines = [];
        $lines[] = 'Draw a background for a printed certificate as a single SVG document.';
        $lines[] = '';
        $lines[] = 'What the author asked for:';
        $lines[] = '- Shape language: ' . $this->describe('shapes') . '.';
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
        $lines[] = sprintf('- The canvas is %.0f by %.0f millimetres. Use viewBox="0 0 %.0f %.0f".',
            $width, $height, $width, $height);
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
            background_library::QUIET_U0 * 100, background_library::QUIET_U1 * 100,
            background_library::QUIET_V0 * 100, background_library::QUIET_V1 * 100);
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
        $value = (string) ($this->answers[$field] ?? '');
        $label = $fields[$field]['options'][$value] ?? $value;

        return trim(str_replace('—', '-', (string) $label));
    }

    /**
     * A short sentence describing what this brief asked for.
     *
     * @return string
     */
    public function summary(): string {
        $shapes = trim((string) strtok($this->describe('shapes'), '-'));

        $summary = $shapes . ', ' . strtolower($this->describe('density'))
            . ', ' . strtolower($this->describe('placement'));

        if ($this->answers['motif'] !== '') {
            $summary .= ', suggesting ' . $this->answers['motif'];
        }

        return ucfirst($summary) . '.';
    }
}

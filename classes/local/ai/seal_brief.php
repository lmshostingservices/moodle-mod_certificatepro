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

use mod_certificatepro\local\art\seal_engine;

/**
 * A structured brief for an organisation seal.
 *
 * WHY THE QUESTIONS ARE THE ENGINE'S OWN VOCABULARY
 *
 * The first version of this class asked about "silhouette", "drawing style",
 * "weight" and "what the organisation does", then guessed its way from those
 * adjectives to the engine's nine edges, four rings and nine marks. Two
 * consequences followed. The author was answering questions about a picture
 * nobody had shown them, and two different adjective sets collapsed onto the
 * same drawing, so the wizard could not honestly claim the answers mattered.
 *
 * The questions are now the engine's own axes, and every option is shown as a
 * complete seal carrying the author's own name. Choosing an edge means looking
 * at nine seals that differ only in their edge. Nothing has to be imagined and
 * nothing is lost in translation: the thumbnail is the drawing that gets saved.
 *
 * The only things still typed are the things only the author knows — the name,
 * the year, the motto.
 *
 * WHAT IS DELIBERATELY NOT ASKED
 *
 * The engine takes a numeric rule weight, but a placed seal element has nowhere
 * to store one, so a wizard that offered a weight slider would preview
 * something the design could not keep. The weight question therefore resolves
 * only to settings that survive being saved: the inner keyline, the dividers,
 * and which palette roles the ink and the metal take.
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
     * Every choice field names the language string prefix its options are
     * labelled from, so the wizard and the element settings form call the same
     * edge by the same name.
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
            'edge' => [
                'label' => 'sealedge',
                'type' => 'choice',
                'default' => seal_engine::EDGE_DOUBLE,
                'prefix' => 'wizedge_',
                'altprefix' => 'sealedge_',
                'options' => array_fill_keys(seal_engine::edges(), ''),
            ],
            'mark' => [
                'label' => 'sealmark',
                'type' => 'choice',
                'default' => seal_engine::MARK_MONOGRAM,
                'prefix' => 'wizmark_',
                'altprefix' => 'sealmark_',
                'options' => array_fill_keys(seal_engine::marks(), ''),
            ],
            'ring' => [
                'label' => 'sealring',
                'type' => 'choice',
                'default' => seal_engine::RING_BOTH,
                'prefix' => 'wizring_',
                'altprefix' => 'sealring_',
                'options' => array_fill_keys(seal_engine::ring_layouts(), ''),
            ],
            'weight' => [
                'label' => 'wizsealweight',
                'type' => 'choice',
                'default' => 'balanced',
                'prefix' => 'wizweight_',
                'altprefix' => 'sealopt_weight_',
                'options' => ['light' => '', 'balanced' => '', 'strong' => ''],
            ],
        ];
    }

    /**
     * The screens the wizard shows, in order.
     *
     * Four screens: the things only the author knows, then three galleries of
     * complete seals. Each entry names either the text fields it collects or
     * the choice fields it draws as galleries.
     *
     * @return array[] each with id, title, hint, fields and galleries
     */
    public static function screens(): array {
        return [
            [
                'id' => 'name',
                'title' => 'wizsealtitlename',
                'hint' => 'wizsealhintname',
                'fields' => ['organisation', 'established', 'motto'],
                'galleries' => [],
            ],
            [
                'id' => 'outline',
                'title' => 'wizsealtitleoutline',
                'hint' => 'wizsealhintoutline',
                'fields' => [],
                'galleries' => ['edge'],
            ],
            [
                'id' => 'centre',
                'title' => 'wizsealtitlecentre',
                'hint' => 'wizsealhintcentre',
                'fields' => [],
                'galleries' => ['mark'],
            ],
            [
                'id' => 'lettering',
                'title' => 'wizsealtitlelettering',
                'hint' => 'wizsealhintlettering',
                'fields' => [],
                'galleries' => ['ring', 'weight'],
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
     * Problems the author should know about before the seal is placed.
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

        if (in_array($this->answers['mark'], [seal_engine::MARK_MONOGRAM,
                seal_engine::MARK_REVERSED], true) && mb_strlen($this->initials()) > 4) {
            $out[] = ['key' => 'sealwarnmonogramlong', 'a' => $this->initials()];
        }

        if ($this->answers['mark'] === seal_engine::MARK_WORDMARK
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

    // ------------------------------------------------------------------
    // From answers to a drawable, storable seal.
    // ------------------------------------------------------------------

    /**
     * The settings a seal element stores, for these answers.
     *
     * Every key here is one the seal element reads back, so what the wizard
     * previews is by construction what the design keeps. Overrides let a
     * variant differ from the answered brief along one axis without the caller
     * having to know how the rest is derived.
     *
     * @param array $overrides answers to substitute: edge, ring, mark, weight
     * @return array the element's stored settings
     */
    public function to_spec(array $overrides = []): array {
        $a = $this->answers;
        foreach (['edge', 'ring', 'mark', 'weight'] as $axis) {
            if (isset($overrides[$axis]) && isset(self::fields()[$axis]['options'][$overrides[$axis]])) {
                $a[$axis] = $overrides[$axis];
            }
        }

        $name = mb_strtoupper($a['organisation']);
        $motto = $a['motto'] !== '' ? mb_strtoupper($a['motto']) : '';
        $mark = $a['mark'];

        // A wordmark IS the name, set large in the middle. Running it round the
        // ring as well says it twice, which is the single commonest way a
        // generated seal announces that nobody looked at it. The ring carries
        // the motto instead, and the seal reads as one statement.
        $top = $mark === seal_engine::MARK_WORDMARK ? $motto : $name;

        $lower = [];
        if ($a['established'] !== '') {
            $lower[] = 'EST. ' . $a['established'];
        }
        // A crest already carries the motto on its ribbon.
        if ($motto !== '' && $mark !== seal_engine::MARK_WORDMARK
                && $mark !== seal_engine::MARK_CREST) {
            $lower[] = $motto;
        }
        $bottom = implode(' · ', $lower);

        $ring = $a['ring'];
        if ($top === '') {
            $ring = seal_engine::RING_NONE;
        } else if ($ring === seal_engine::RING_BOTH && $bottom === '') {
            // Asking for two arcs and having nothing to put in the second one
            // leaves a bare band and dividers pointing at nothing.
            $ring = seal_engine::RING_TOP;
        }

        if ($ring === seal_engine::RING_NONE && $mark === seal_engine::MARK_NONE) {
            // An empty circle is not a seal.
            $mark = seal_engine::MARK_MONOGRAM;
        }

        $centre = '';
        if ($mark === seal_engine::MARK_WORDMARK) {
            $centre = $name;
        } else if (in_array($mark, [seal_engine::MARK_MONOGRAM, seal_engine::MARK_REVERSED], true)) {
            $centre = $this->initials();
        }

        // The year is set under the centre mark only when the ring is not
        // already carrying it. Printing "EST. 1994" twice on a mark the size of
        // a coin is the commonest way a generated seal looks amateur.
        $ringcarriesyear = in_array($ring, [seal_engine::RING_BOTH], true)
            && $a['established'] !== '';
        $year = $ringcarriesyear ? '' : $a['established'];

        $weight = self::weights()[$a['weight']] ?? self::weights()['balanced'];

        $spec = [
            'sealedge' => $a['edge'],
            'sealring' => $ring,
            'sealmark' => $mark,
            'toptext' => $top,
            'bottomtext' => $ring === seal_engine::RING_BOTH ? $bottom : '',
            'centretext' => $centre,
            'yeartext' => $year,
            'mottotext' => $motto,
            'colour' => $weight['colour'],
            'metal' => $weight['metal'],
            'sealdivider' => ($ring === seal_engine::RING_BOTH && $bottom !== ''
                && $weight['divider']) ? 1 : 0,
            'sealkeyline' => $weight['keyline'],
        ];

        // Variants may name the fine detail directly.
        foreach (['sealdivider', 'sealkeyline'] as $flag) {
            if (isset($overrides[$flag])) {
                $spec[$flag] = $overrides[$flag] ? 1 : 0;
            }
        }
        if ($spec['sealring'] !== seal_engine::RING_BOTH || $spec['bottomtext'] === '') {
            $spec['sealdivider'] = 0;
        }

        return $spec;
    }

    /**
     * What each weight answer resolves to, in settings a seal can store.
     *
     * @return array[] keyed by weight
     */
    public static function weights(): array {
        return [
            // Hairline and monochrome: no keyline, no dividers, no second colour.
            'light' => ['colour' => 'primary', 'metal' => 'paper',
                'keyline' => 0, 'divider' => false],
            'balanced' => ['colour' => 'primary', 'metal' => 'accent',
                'keyline' => 1, 'divider' => true],
            // Near-black ink is what makes a mark read at a glance; the accent
            // dividers and the inner keyline give it the extra ring of weight.
            'strong' => ['colour' => 'ink', 'metal' => 'accent',
                'keyline' => 1, 'divider' => true],
        ];
    }

    // ------------------------------------------------------------------
    // Variants.
    // ------------------------------------------------------------------

    /**
     * The axis order a variant key is written in.
     *
     * @return string[] the field names, in key order
     */
    public static function variant_axes(): array {
        return ['edge', 'ring', 'mark', 'weight'];
    }

    /**
     * Write a set of answers as an opaque variant key.
     *
     * @param array $choice the answers along each axis
     * @return string the key
     */
    public static function encode_variant(array $choice): string {
        $parts = [];
        foreach (self::variant_axes() as $axis) {
            $parts[] = (string) ($choice[$axis] ?? '');
        }
        return implode('-', $parts);
    }

    /**
     * Read a variant key back, discarding anything not in the vocabulary.
     *
     * Every token is checked against the engine's own option list, so a
     * tampered key can only ever name a seal the wizard would have drawn
     * anyway.
     *
     * @param string $key the key
     * @return array the answers along each axis, empty when the key is unusable
     */
    public static function decode_variant(string $key): array {
        $axes = self::variant_axes();
        $parts = explode('-', $key);
        if (count($parts) !== count($axes)) {
            return [];
        }

        $fields = self::fields();
        $out = [];
        foreach ($axes as $i => $axis) {
            if (!isset($fields[$axis]['options'][$parts[$i]])) {
                return [];
            }
            $out[$axis] = $parts[$i];
        }

        return $out;
    }

    /**
     * A spread of seals around this brief.
     *
     * The first is always exactly what the answers describe, so the author can
     * still take the thing they built. The rest walk the four axes by fixed
     * offsets rather than at random: a variant set the author cannot tell apart
     * from its neighbours is not a choice, and one that shares no family
     * resemblance is not a variant.
     *
     * @param int $count how many to produce
     * @param array $base the answers to vary around, or empty for this brief's own
     * @param bool $near whether to stay close to the base, for "more like this"
     * @return array[] each with key and the answers it names
     */
    public function variants(int $count, array $base = [], bool $near = false): array {
        $axes = self::variant_axes();
        $fields = self::fields();

        $seed = [];
        foreach ($axes as $axis) {
            $seed[$axis] = $base[$axis] ?? $this->answers[$axis];
        }

        $pool = [];
        foreach ($axes as $axis) {
            $pool[$axis] = array_keys($fields[$axis]['options']);
        }

        // Steps along [edge, ring, mark, weight]. The near set moves one axis at
        // a time by one place; the wide set moves two or three axes further, so
        // nine tiles cover the vocabulary instead of clustering.
        $steps = $near
            ? [[0, 0, 0, 0], [1, 0, 0, 0], [-1, 0, 0, 0], [0, 0, 1, 0], [0, 0, -1, 0],
               [0, 1, 0, 0], [0, 0, 0, 1], [2, 0, 0, 0], [0, 0, 2, 0], [-2, 0, 1, 0],
               [1, 0, 1, 0], [0, -1, 0, -1]]
            : [[0, 0, 0, 0], [1, 0, 3, 0], [3, 0, 1, 1], [5, 1, 5, 0], [2, 0, 6, 2],
               [7, 0, 2, 1], [4, 3, 4, 0], [6, 1, 7, 2], [8, 0, 8, 1], [1, 3, 2, 2],
               [5, 0, 4, 1], [3, 1, 6, 0]];

        $out = [];
        $seen = [];

        foreach ($steps as $step) {
            if (count($out) >= $count) {
                break;
            }

            $choice = [];
            foreach ($axes as $i => $axis) {
                $list = $pool[$axis];
                $at = array_search($seed[$axis], $list, true);
                $at = $at === false ? 0 : $at;
                $choice[$axis] = $list[(($at + $step[$i]) % count($list) + count($list))
                    % count($list)];
            }

            // A seal with no ring lettering and nothing in the middle is a
            // circle. Never offer one as a variant.
            if ($choice['ring'] === seal_engine::RING_NONE
                    && $choice['mark'] === seal_engine::MARK_NONE) {
                $choice['mark'] = seal_engine::MARK_MONOGRAM;
            }

            $key = self::encode_variant($choice);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = ['key' => $key, 'choice' => $choice];
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
     * @param float $diameter the seal's drawn diameter in millimetres
     * @return string
     */
    public function to_prompt(array $palette, float $diameter = 34.0): string {
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

        $lines[] = '';
        $lines[] = 'The design:';
        $lines[] = '- Outer edge: ' . $this->describe('edge') . '.';
        $lines[] = '- Ring lettering: ' . $this->describe('ring') . '.';
        $lines[] = '- Centre: ' . $this->describe('mark') . '.';
        $lines[] = '- Weight: ' . $this->describe('weight') . '.';

        if (in_array($a['mark'], [seal_engine::MARK_MONOGRAM, seal_engine::MARK_REVERSED], true)
                && $this->initials() !== '') {
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
        $lines[] = '4. It must read at 34mm and still read when photocopied in black and white, '
            . 'so carry contrast in value, not in hue.';
        $lines[] = '5. Square viewBox, the design centred and touching the edges of it.';
        $lines[] = '6. Keep it under 150 elements.';
        $lines[] = '7. Return the SVG and nothing else. No commentary, no code fence.';

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
        // The long name, because a model reads the sentence and a thumbnail
        // does not.
        foreach ([$fields[$field]['altprefix'] ?? '', $fields[$field]['prefix'] ?? ''] as $prefix) {
            if ($prefix !== '' && get_string_manager()->string_exists($prefix . $value,
                    'mod_certificatepro')) {
                return trim(str_replace('—', '-',
                    get_string($prefix . $value, 'mod_certificatepro')));
            }
        }

        return $value;
    }

    /**
     * A short sentence describing the seal these answers make.
     *
     * @return string
     */
    public function summary(): string {
        return implode(' · ', array_filter([
            $this->describe('edge'),
            $this->describe('mark'),
            $this->describe('ring'),
        ]));
    }
}

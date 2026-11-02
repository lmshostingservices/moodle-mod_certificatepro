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

namespace mod_certificatepro\local\credential;

/**
 * Bakes an Open Badges 3.0 credential into SVG artwork, and reads it back out.
 *
 * Baking is defined by Open Badges 3.0 section 5.3. For SVG the recipe is
 * exactly three moves: declare the openbadges namespace on the root element,
 * put a single <openbadges:credential> tag directly after the <svg> tag, and
 * carry the credential either in a CDATA section (embedded proof) or in a
 * verify attribute (VC-JWT). The specification is explicit that "There MUST be
 * only one <openbadges:credential> tag in an SVG", which is why every bake
 * strips whatever was baked before instead of appending beside it.
 *
 * The work is done on the markup as a string rather than through DOMDocument.
 * A round trip through libxml rewrites entity escaping, attribute quoting and
 * empty element syntax throughout the whole drawing, which is a large and
 * pointless change to an image whose only fault was that it had no credential
 * in it. Scanning for the one tag we care about leaves every other byte of the
 * artwork exactly as the designer left it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class svg_baker {
    /** @var string The namespace declared on the root element, from Open Badges 3.0 section 5.3. */
    public const NAMESPACE_URI = 'https://purl.imsglobal.org/ob/v3p0';

    /** @var string The namespace prefix the specification uses in its examples. */
    public const PREFIX = 'openbadges';

    /** @var string The local name of the element carrying the credential. */
    public const ELEMENT = 'credential';

    /** @var string The attribute holding a compact JWS when the proof is a VC-JWT. */
    public const VERIFY_ATTRIBUTE = 'verify';

    /**
     * @var string[] Namespace URIs accepted when reading.
     *
     * Only the first is ever written. The insecure spelling is tolerated on the
     * way in because bakers in the wild have shipped it, and a credential we
     * can read is better than one we silently duplicate.
     */
    protected const KNOWN_NAMESPACES = [
        'https://purl.imsglobal.org/ob/v3p0',
        'http://purl.imsglobal.org/ob/v3p0',
    ];

    /** @var int The largest document we are prepared to rewrite. */
    protected const MAX_BYTES = 4194304;

    /**
     * Bake a credential into SVG markup using an embedded proof.
     *
     * The credential is written into a CDATA section, as the specification
     * requires when the proof is not a VC-JWT. Baking an already baked
     * document replaces the credential rather than adding a second one.
     *
     * @param string $svg the SVG markup to bake into
     * @param array|string $credential the credential as an array or as JSON
     * @return string the baked markup
     * @throws \moodle_exception when the markup has no usable svg root element
     */
    public static function bake(string $svg, $credential): string {
        $json = self::encode($credential);

        $element = '<' . self::PREFIX . ':' . self::ELEMENT . '>'
            . '<![CDATA[' . self::escape_cdata($json) . ']]>'
            . '</' . self::PREFIX . ':' . self::ELEMENT . '>';

        return self::insert($svg, $element);
    }

    /**
     * Bake a credential into SVG markup as a VC-JWT.
     *
     * The compact serialisation goes into the verify attribute and the body of
     * the tag stays empty, which is the form the specification gives for the
     * JSON Web Token Proof Format.
     *
     * @param string $svg the SVG markup to bake into
     * @param string $jwt the credential in compact JWS serialisation
     * @return string the baked markup
     * @throws \moodle_exception when the markup has no usable svg root element
     */
    public static function bake_jwt(string $svg, string $jwt): string {
        $element = '<' . self::PREFIX . ':' . self::ELEMENT . ' '
            . self::VERIFY_ATTRIBUTE . '="' . self::escape_attribute(trim($jwt)) . '">'
            . '</' . self::PREFIX . ':' . self::ELEMENT . '>';

        return self::insert($svg, $element);
    }

    /**
     * Whether the markup already carries a credential.
     *
     * @param string $svg the markup to inspect
     * @return bool true when a credential element is present
     */
    public static function is_baked(string $svg): bool {
        return self::element_span($svg) !== null;
    }

    /**
     * The credential baked into the markup, decoded.
     *
     * Returns null for a document that is not baked, and for one baked as a
     * VC-JWT, whose payload is signed and belongs to extract_jwt().
     *
     * @param string $svg the baked markup
     * @return array|null the credential, or null when there is no readable one
     */
    public static function extract(string $svg): ?array {
        $json = self::extract_json($svg);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The raw JSON baked into the markup, still encoded.
     *
     * @param string $svg the baked markup
     * @return string|null the credential JSON, or null when there is none
     */
    public static function extract_json(string $svg): ?string {
        $span = self::element_span($svg);

        if ($span === null) {
            return null;
        }

        [$start, $length, $openlength] = $span;

        $inner = substr(
            $svg,
            $start + $openlength,
            $length - $openlength - self::close_tag_length($svg, $start)
        );

        if ($inner === '' || $inner === false) {
            return null;
        }

        if (strpos($inner, '<![CDATA[') !== false) {
            // Dropping the section markers concatenates the pieces back
            // together, which is exactly how a split ]]> is repaired.
            $inner = str_replace(']]>', '', $inner);
            $inner = str_replace('<![CDATA[', '', $inner);
        } else {
            $inner = html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $inner = trim($inner);

        return $inner === '' ? null : $inner;
    }

    /**
     * The compact JWS baked into the markup's verify attribute.
     *
     * @param string $svg the baked markup
     * @return string|null the token, or null when the document carries no token
     */
    public static function extract_jwt(string $svg): ?string {
        $span = self::element_span($svg);

        if ($span === null) {
            return null;
        }

        [$start, , $openlength] = $span;
        $tag = substr($svg, $start, $openlength);

        $pattern = '/\s' . preg_quote(self::VERIFY_ATTRIBUTE, '/') . '\s*=\s*("|\')(.*?)\1/s';
        if (!preg_match($pattern, $tag, $matches)) {
            return null;
        }

        $token = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $token === '' ? null : $token;
    }

    /**
     * Remove every credential element from the markup.
     *
     * @param string $svg the markup to clean
     * @return string the markup without any credential element
     */
    public static function strip(string $svg): string {
        $guard = 0;

        while (($span = self::element_span($svg)) !== null && $guard++ < 32) {
            [$start, $length] = $span;

            $before = substr($svg, 0, $start);
            $after = substr($svg, $start + $length);

            // Take the newline the element was written with, so that repeated
            // baking neither walks the drawing down the file nor pulls it up.
            // Baking writes the break before the element; a document baked
            // elsewhere may have put it after.
            if (preg_match('/(?:\r\n|\n|\r)$/', $before, $matches)) {
                $before = substr($before, 0, -strlen($matches[0]));
            } else if (preg_match('/^(?:\r\n|\n|\r)/', $after, $matches)) {
                $after = substr($after, strlen($matches[0]));
            }

            $svg = $before . $after;
        }

        return $svg;
    }

    /**
     * Encode a credential for baking.
     *
     * Slashes and unicode are left alone: the credential is full of URLs, and
     * escaping them makes the baked document needlessly hard to read.
     *
     * @param array|string $credential the credential as an array or as JSON
     * @return string the credential as JSON
     * @throws \moodle_exception when the credential cannot be encoded
     */
    public static function encode($credential): string {
        if (is_string($credential)) {
            $trimmed = trim($credential);

            if ($trimmed === '' || json_decode($trimmed) === null) {
                throw new \moodle_exception('bakefailed', 'mod_certificatepro');
            }

            return $trimmed;
        }

        if (!is_array($credential) || $credential === []) {
            throw new \moodle_exception('bakefailed', 'mod_certificatepro');
        }

        $json = json_encode($credential, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \moodle_exception('bakefailed', 'mod_certificatepro');
        }

        return $json;
    }

    /**
     * Make a JSON payload safe to sit inside a CDATA section.
     *
     * A CDATA section ends at the first ]]>, and a credential is free to
     * contain that sequence inside a string. Closing the section and opening a
     * fresh one between the brackets and the bracket-angle leaves the parsed
     * text identical while removing the premature terminator.
     *
     * @param string $json the payload to escape
     * @return string the payload, safe to wrap in CDATA
     */
    protected static function escape_cdata(string $json): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $json);
    }

    /**
     * Escape a value for use inside a double quoted XML attribute.
     *
     * @param string $value the value to escape
     * @return string the escaped value
     */
    protected static function escape_attribute(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Put a prepared credential element into the markup.
     *
     * @param string $svg the markup to bake into
     * @param string $element the credential element, already serialised
     * @return string the baked markup
     * @throws \moodle_exception when the markup has no usable svg root element
     */
    protected static function insert(string $svg, string $element): string {
        if (trim($svg) === '' || strlen($svg) > self::MAX_BYTES) {
            throw new \moodle_exception('bakefailed', 'mod_certificatepro');
        }

        // Anything baked earlier goes first: only one credential may remain.
        $svg = self::strip($svg);

        $root = self::root_span($svg);

        if ($root === null) {
            throw new \moodle_exception('bakefailed', 'mod_certificatepro');
        }

        [$start, $length] = $root;
        $tag = substr($svg, $start, $length);
        $selfclosing = substr($tag, -2) === '/>';

        // An empty root element cannot contain anything, so give it a body.
        if ($selfclosing) {
            $tag = rtrim(substr($tag, 0, -2)) . '>';
        }

        $tag = self::declare_namespace($tag);

        $tail = $selfclosing ? '</svg>' : '';

        return substr($svg, 0, $start) . $tag . "\n" . $element . $tail
            . substr($svg, $start + $length);
    }

    /**
     * Add the openbadges namespace declaration to a root tag that lacks one.
     *
     * @param string $tag the opening svg tag
     * @return string the tag, with the namespace declared
     */
    protected static function declare_namespace(string $tag): string {
        if (self::declared_prefixes($tag) !== []) {
            return $tag;
        }

        $declaration = ' xmlns:' . self::PREFIX . '="' . self::NAMESPACE_URI . '"';

        return rtrim(substr($tag, 0, -1)) . $declaration . '>';
    }

    /**
     * The prefixes bound to the Open Badges namespace on a root tag.
     *
     * A document baked elsewhere may have chosen its own prefix, and its
     * credential still has to be found so that rebaking replaces it.
     *
     * @param string $tag the opening svg tag
     * @return string[] the declared prefixes, empty when the namespace is absent
     */
    protected static function declared_prefixes(string $tag): array {
        $prefixes = [];

        if (!preg_match_all(
            '/xmlns:([A-Za-z_][\w.\-]*)\s*=\s*("|\')(.*?)\2/s',
            $tag,
            $matches,
            PREG_SET_ORDER
        )) {
            return $prefixes;
        }

        foreach ($matches as $match) {
            $uri = trim(html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if (in_array($uri, self::KNOWN_NAMESPACES, true)) {
                $prefixes[] = $match[1];
            }
        }

        return $prefixes;
    }

    /**
     * Where the opening svg tag begins and how long it is.
     *
     * Comments, the XML declaration, a doctype and processing instructions may
     * all precede the root element, and any of them may contain the letters
     * svg, so the search is for a tag start rather than for a substring.
     *
     * @param string $svg the markup to scan
     * @return array{0: int, 1: int}|null the offset and length, or null when there is no root
     */
    protected static function root_span(string $svg): ?array {
        if (!preg_match('/<svg(?=[\s\/>])/i', $svg, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = (int) $matches[0][1];
        $end = self::tag_end($svg, $start);

        return $end === null ? null : [$start, $end - $start];
    }

    /**
     * Where the credential element begins, how long it is, and how long its opening tag is.
     *
     * @param string $svg the markup to scan
     * @return array{0: int, 1: int, 2: int}|null the offsets, or null when the document is not baked
     */
    protected static function element_span(string $svg): ?array {
        $prefixes = [self::PREFIX];

        $root = self::root_span($svg);
        if ($root !== null) {
            foreach (self::declared_prefixes(substr($svg, $root[0], $root[1])) as $prefix) {
                $prefixes[] = $prefix;
            }
        }

        $best = null;

        foreach (array_unique($prefixes) as $prefix) {
            $name = $prefix . ':' . self::ELEMENT;

            if (!preg_match(
                '/<' . preg_quote($name, '/') . '(?=[\s\/>])/',
                $svg,
                $matches,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            $start = (int) $matches[0][1];
            $openend = self::tag_end($svg, $start);

            if ($openend === null) {
                continue;
            }

            $openlength = $openend - $start;

            if (substr($svg, $openend - 2, 2) === '/>') {
                $length = $openlength;
            } else {
                $close = strpos($svg, '</' . $name, $openend);
                $closeend = $close === false ? null : self::tag_end($svg, $close);

                if ($closeend === null) {
                    continue;
                }

                $length = $closeend - $start;
            }

            if ($best === null || $start < $best[0]) {
                $best = [$start, $length, $openlength];
            }
        }

        return $best;
    }

    /**
     * The length of the closing tag of the credential element at an offset.
     *
     * @param string $svg the markup
     * @param int $start the offset the element starts at
     * @return int the closing tag length, zero for a self closing element
     */
    protected static function close_tag_length(string $svg, int $start): int {
        if (!preg_match(
            '/^<([A-Za-z_][\w.\-]*:' . preg_quote(self::ELEMENT, '/') . ')/',
            substr($svg, $start, 128),
            $matches
        )) {
            return 0;
        }

        $end = self::tag_end($svg, $start);

        if ($end !== null && substr($svg, $end - 2, 2) === '/>') {
            return 0;
        }

        return strlen('</' . $matches[1] . '>');
    }

    /**
     * Where the tag starting at an offset ends.
     *
     * Attribute values may legitimately contain an angle bracket, so quoting is
     * tracked rather than searching for the next > in the string.
     *
     * @param string $svg the markup
     * @param int $start the offset of the opening angle bracket
     * @return int|null the offset just past the closing angle bracket, or null when unterminated
     */
    protected static function tag_end(string $svg, int $start): ?int {
        $length = strlen($svg);
        $quote = '';

        for ($i = $start + 1; $i < $length; $i++) {
            $character = $svg[$i];

            if ($quote !== '') {
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($character === '"' || $character === '\'') {
                $quote = $character;
                continue;
            }

            if ($character === '>') {
                return $i + 1;
            }
        }

        return null;
    }
}

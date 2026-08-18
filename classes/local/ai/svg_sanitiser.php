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
 * Reduces arbitrary SVG to a safe subset of drawing instructions.
 *
 * Generated artwork arrives as markup, and markup that is going to be shown in
 * a browser has to be treated as hostile. This works by allow list rather than
 * block list: any element or attribute not explicitly named below is removed,
 * so a novel attack surface fails closed rather than open. Scripting,
 * embedded documents, external references and anything that can make a network
 * request are all absent from the allow list by design.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class svg_sanitiser {
    /** @var string[] The only elements that survive sanitising. */
    protected const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern',
        'clipPath', 'mask', 'filter',
        'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix', 'feMerge',
        'feMergeNode', 'feFlood', 'feComposite',
    ];

    /** @var string[] The only attributes that survive sanitising. */
    protected const ALLOWED_ATTRIBUTES = [
        'id', 'class', 'width', 'height', 'viewBox', 'preserveAspectRatio', 'xmlns',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
        'opacity', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits',
        'gradientTransform', 'spreadMethod', 'patternUnits', 'patternTransform',
        'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
        'text-anchor', 'dominant-baseline', 'startOffset', 'dx', 'dy', 'rotate',
        'clip-path', 'mask', 'filter', 'clipPathUnits', 'maskUnits',
        'stdDeviation', 'in', 'in2', 'result', 'mode', 'type', 'values',
        'flood-color', 'flood-opacity', 'operator', 'href',
    ];

    /** @var int The largest generated document we are prepared to store. */
    protected const MAX_BYTES = 262144;

    /**
     * Sanitise a document, returning null when nothing usable survives.
     *
     * @param string $svg the markup to sanitise
     * @return string|null clean markup, or null when the input is not usable SVG
     */
    public static function clean(string $svg): ?string {
        $svg = trim($svg);

        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }

        // Entity declarations are a well known route to file disclosure. This is
        // checked against the untouched input, before anything is trimmed away.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg)) {
            return null;
        }

        // Strip anything wrapped around the document, such as prose or code fences.
        $start = stripos($svg, '<svg');
        $end = strripos($svg, '</svg>');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $svg = substr($svg, $start, $end - $start + 6);

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            return null;
        }

        if (strtolower($document->documentElement->nodeName) !== 'svg') {
            return null;
        }

        self::scrub($document->documentElement);

        $result = $document->saveXML($document->documentElement);

        return $result === false ? null : $result;
    }

    /**
     * Remove every disallowed node and attribute beneath an element.
     *
     * @param \DOMElement $element the element to scrub, modified in place
     * @return void
     */
    protected static function scrub(\DOMElement $element): void {
        // Walk children backwards so removals do not disturb the iteration.
        for ($i = $element->childNodes->length - 1; $i >= 0; $i--) {
            $child = $element->childNodes->item($i);
            if ($child === null) {
                continue;
            }

            if ($child instanceof \DOMComment
                    || $child instanceof \DOMProcessingInstruction
                    || $child instanceof \DOMDocumentType) {
                $element->removeChild($child);
                continue;
            }

            if (!($child instanceof \DOMElement)) {
                continue;
            }

            if (!in_array($child->nodeName, self::ALLOWED_ELEMENTS, true)) {
                $element->removeChild($child);
                continue;
            }

            self::scrub($child);
        }

        // Walk attributes backwards for the same reason.
        if ($element->hasAttributes()) {
            for ($i = $element->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $element->attributes->item($i);
                if ($attribute === null) {
                    continue;
                }

                $name = $attribute->nodeName;

                if (!in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                    $element->removeAttribute($name);
                    continue;
                }

                if (!self::value_is_safe($name, (string) $attribute->nodeValue)) {
                    $element->removeAttribute($name);
                }
            }
        }
    }

    /**
     * Whether an attribute value is free of scripting and external references.
     *
     * @param string $name the attribute name
     * @param string $value the attribute value
     * @return bool
     */
    protected static function value_is_safe(string $name, string $value): bool {
        $normalised = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        foreach (['javascript:', 'data:text/html', 'vbscript:', '&#', '\\x'] as $needle) {
            if (str_contains($normalised, $needle)) {
                return false;
            }
        }

        // References may only point within the document itself.
        if ($name === 'href' && !str_starts_with(trim($value), '#')) {
            return false;
        }

        if (in_array($name, ['fill', 'stroke', 'clip-path', 'mask', 'filter'], true)) {
            if (str_contains($normalised, 'url(') && !str_contains($normalised, 'url(#')) {
                return false;
            }
        }

        return true;
    }

    /**
     * A quick check that a document is well formed and draws something.
     *
     * @param string $svg the markup to check
     * @return bool
     */
    public static function is_drawable(string $svg): bool {
        if ($svg === '') {
            return false;
        }
        return (bool) preg_match(
            '/<(path|rect|circle|ellipse|line|polyline|polygon|text|use)\b/i',
            $svg
        );
    }
}

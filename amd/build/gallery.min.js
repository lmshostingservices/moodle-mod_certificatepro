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

/**
 * Keyboard and selection behaviour shared by every gallery in the design libraries.
 *
 * The galleries are single select listboxes with a roving tabindex. Selection
 * follows focus, which is what a person choosing between pictures expects: the
 * arrow keys walk the grid and the thing under the cursor is the thing that
 * would be applied.
 *
 * @module     mod_certificatepro/gallery
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function () {

    'use strict';

    /**
     * Wire a gallery up.
     *
     * @param {HTMLElement} container the element carrying role="listbox"
     * @param {Function} onSelect called with the selected value and its tile
     * @return {Object} a small handle for reading and setting the selection
     */
    var attach = function (container, onSelect) {
        var selected = null;

        /**
         * Every tile currently on show.
         *
         * @return {HTMLElement[]} the visible tiles
         */
        var tiles = function () {
            return Array.prototype.filter.call(
                container.querySelectorAll('[data-region="tile"]'),
                function (tile) {
                    return !tile.hidden;
                }
            );
        };

        /**
         * Mark one tile as the selection, moving the roving tabindex onto it.
         *
         * @param {HTMLElement} tile the tile to select
         * @param {boolean} focus whether to move focus onto it
         * @return {void}
         */
        var select = function (tile, focus) {
            if (!tile) {
                return;
            }

            tiles().forEach(function (other) {
                other.setAttribute('aria-selected', other === tile ? 'true' : 'false');
                other.setAttribute('tabindex', other === tile ? '0' : '-1');
                other.classList.toggle('cpro-selected', other === tile);
            });

            selected = tile.getAttribute('data-value');

            if (focus) {
                tile.focus();
            }

            if (onSelect) {
                onSelect(selected, tile);
            }
        };

        /**
         * Move the selection by a number of tiles.
         *
         * @param {number} delta how far to move
         * @return {void}
         */
        var move = function (delta) {
            var visible = tiles();
            if (!visible.length) {
                return;
            }

            var current = visible.findIndex(function (tile) {
                return tile.getAttribute('aria-selected') === 'true';
            });

            var next = current < 0 ? 0 : current + delta;
            next = Math.max(0, Math.min(visible.length - 1, next));

            select(visible[next], true);
        };

        /**
         * How many tiles sit on one row, so that the up and down keys behave.
         *
         * @return {number} the column count, never less than one
         */
        var columns = function () {
            var visible = tiles();
            if (visible.length < 2) {
                return 1;
            }

            var top = visible[0].offsetTop;
            var count = 0;

            for (var i = 0; i < visible.length; i++) {
                if (visible[i].offsetTop !== top) {
                    break;
                }
                count++;
            }

            return Math.max(1, count);
        };

        container.addEventListener('click', function (event) {
            var tile = event.target.closest('[data-region="tile"]');
            if (tile && container.contains(tile)) {
                select(tile, true);
            }
        });

        container.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowRight':
                    move(1);
                    break;
                case 'ArrowLeft':
                    move(-1);
                    break;
                case 'ArrowDown':
                    move(columns());
                    break;
                case 'ArrowUp':
                    move(-columns());
                    break;
                case 'Home':
                    select(tiles()[0], true);
                    break;
                case 'End':
                    select(tiles()[tiles().length - 1], true);
                    break;
                case 'Enter':
                case ' ':
                    if (event.target.closest('[data-region="tile"]')) {
                        select(event.target.closest('[data-region="tile"]'), true);
                    } else {
                        return;
                    }
                    break;
                default:
                    return;
            }

            event.preventDefault();
        });

        // The listbox itself is the tab stop until something is chosen, at
        // which point the chosen tile takes over.
        container.addEventListener('focus', function () {
            var visible = tiles();
            if (!visible.length) {
                return;
            }
            var current = visible.find(function (tile) {
                return tile.getAttribute('aria-selected') === 'true';
            });
            (current || visible[0]).focus();
        });

        return {
            /**
             * The currently selected value.
             *
             * @return {string|null} the value, or null when nothing is chosen
             */
            value: function () {
                return selected;
            },

            /**
             * Forget the selection, for instance after refiltering.
             *
             * @return {void}
             */
            clear: function () {
                selected = null;
                Array.prototype.forEach.call(
                    container.querySelectorAll('[data-region="tile"]'),
                    function (tile) {
                        tile.setAttribute('aria-selected', 'false');
                        tile.setAttribute('tabindex', '-1');
                        tile.classList.remove('cpro-selected');
                    }
                );
            },

            /**
             * The tiles currently on show.
             *
             * @return {HTMLElement[]} the visible tiles
             */
            tiles: tiles
        };
    };

    return {
        attach: attach
    };
});

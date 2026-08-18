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
 * The procedural background gallery.
 *
 * Previews are fetched in small batches as tiles come into view. The catalogue
 * rendered in one go is roughly three megabytes of vector, so eager loading
 * would make the gallery slower than the thing it is meant to save time on.
 *
 * @module     mod_certificatepro/backgroundpicker
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/templates', 'core/modal', 'core/str',
    'mod_certificatepro/gallery'],
function (Ajax, Notification, Templates, Modal, Str, Gallery) {

    'use strict';

    /** How many previews to ask for at once. Must not exceed the server's batch size. */
    var BATCH = 6;

    /**
     * Open the gallery.
     *
     * @param {Object} config templateid, pageid, and a done callback
     * @return {Promise} resolves once the dialog is on screen
     */
    var open = function (config) {
        return Ajax.call([{
            methodname: 'mod_certificatepro_get_background_styles',
            args: {templateid: config.templateid}
        }])[0].then(function (response) {
            return Promise.all([
                Templates.render('mod_certificatepro/background_picker', response),
                Str.get_string('bgpickertitle', 'mod_certificatepro')
            ]);
        }).then(function (parts) {
            return Modal.create({
                title: parts[1],
                body: parts[0],
                large: true,
                removeOnClose: true,
                returnElement: config.returnElement
            });
        }).then(function (modal) {
            return modal.show().then(function () {
                wire(modal, config);
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Attach behaviour once the dialog is on screen.
     *
     * @param {Object} modal the modal
     * @param {Object} config templateid, pageid, and a done callback
     * @return {void}
     */
    var wire = function (modal, config) {
        var root = modal.getBody()[0].querySelector('[data-region="backgroundpicker"]');
        if (!root) {
            return;
        }

        var gallery = root.querySelector('[data-region="gallery"]');
        var apply = root.querySelector('[data-action="apply"]');
        var note = root.querySelector('[data-region="note"]');
        var loading = false;

        var handle = Gallery.attach(gallery, function (value, tile) {
            apply.disabled = !value;
            var name = tile.querySelector('.cpro-gallery-name');
            note.textContent = name ? name.textContent : '';
        });

        /**
         * Ask the server for the previews of any tiles that still need one.
         *
         * @param {HTMLElement[]} tiles the tiles wanting a preview
         * @return {void}
         */
        var fetch = function (tiles) {
            var wanted = tiles.filter(function (tile) {
                return !tile.hasAttribute('data-loaded');
            }).slice(0, BATCH);

            if (!wanted.length || loading) {
                return;
            }

            loading = true;
            wanted.forEach(function (tile) {
                tile.setAttribute('data-loaded', 'pending');
            });

            Ajax.call([{
                methodname: 'mod_certificatepro_get_background_previews',
                args: {
                    templateid: config.templateid,
                    styles: wanted.map(function (tile) {
                        return tile.getAttribute('data-value');
                    }),
                    seed: 7
                }
            }])[0].then(function (response) {
                response.previews.forEach(function (preview) {
                    var tile = root.querySelector('[data-value="' + preview.id + '"]');
                    var thumb = tile ? tile.querySelector('[data-region="thumb"]') : null;
                    if (thumb) {
                        thumb.innerHTML = preview.svg;
                        thumb.classList.add('cpro-bgthumb-ready');
                    }
                });
                loading = false;
                queue();
                return response;
            }).catch(function (error) {
                loading = false;
                Notification.exception(error);
            });
        };

        /**
         * Whether a tile is close enough to the viewport to be worth drawing.
         *
         * A preview is between one and five hundred kilobytes of vector, so
         * asking for one nobody is looking at is a real cost rather than a
         * theoretical one.
         *
         * @param {HTMLElement} tile the tile to test
         * @return {boolean} true when it is on screen or nearly so
         */
        var isNear = function (tile) {
            var view = gallery.getBoundingClientRect();
            var box = tile.getBoundingClientRect();
            return box.bottom > view.top - 240 && box.top < view.bottom + 240;
        };

        /**
         * Fetch previews for whatever is in view, then keep going.
         *
         * @return {void}
         */
        var queue = function () {
            var pending = handle.tiles().filter(function (tile) {
                return !tile.hasAttribute('data-loaded') && isNear(tile);
            });

            if (pending.length) {
                fetch(pending);
            }
        };

        root.addEventListener('click', function (event) {
            var facet = event.target.closest('[data-action="family"]');
            if (!facet) {
                return;
            }

            var family = facet.getAttribute('data-value');

            Array.prototype.forEach.call(root.querySelectorAll('[data-action="family"]'),
                function (button) {
                    var active = button === facet;
                    button.classList.toggle('active', active);
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                });

            Array.prototype.forEach.call(root.querySelectorAll('[data-region="tile"]'),
                function (tile) {
                    tile.hidden = family !== '' && tile.getAttribute('data-family') !== family;
                });

            handle.clear();
            apply.disabled = true;
            queue();
        });

        apply.addEventListener('click', function () {
            var chosen = handle.value();
            if (!chosen) {
                return;
            }

            apply.disabled = true;

            Ajax.call([{
                methodname: 'mod_certificatepro_apply_background',
                args: {
                    templateid: config.templateid,
                    pageid: config.pageid,
                    style: chosen,
                    seed: 7
                }
            }])[0].then(function (result) {
                modal.destroy();
                if (config.done) {
                    config.done(result);
                }
                return result;
            }).catch(function (error) {
                apply.disabled = false;
                Notification.exception(error);
            });
        });

        gallery.addEventListener('scroll', queue);
        gallery.focus();
        queue();
    };

    return {
        open: open
    };
});

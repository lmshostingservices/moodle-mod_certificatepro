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
 * The bundled public domain ornament picker.
 *
 * The catalogue may be empty, and usually is on a source checkout, because the
 * artwork is fetched by an import step rather than committed. An empty library
 * is a state to explain, not an error to throw, so the dialog opens either way.
 *
 * @module     mod_certificatepro/ornamentpicker
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/templates', 'core/modal', 'core/str',
    'mod_certificatepro/gallery'],
function(Ajax, Notification, Templates, Modal, Str, Gallery) {

    'use strict';

    /** How long to wait after the last keystroke before searching, in milliseconds. */
    var SEARCH_DELAY = 300;

    /**
     * Ask the server for the ornaments matching a filter.
     *
     * @param {Object} config the dialog configuration
     * @param {Object} filter role, orientation and q
     * @return {Promise} the rendered markup
     */
    var load = function(config, filter) {
        return Ajax.call([{
            methodname: 'mod_certificatepro_get_ornaments',
            args: {
                templateid: config.templateid,
                role: filter.role || '',
                orientation: filter.orientation || '',
                palette: '',
                q: filter.q || ''
            }
        }])[0].then(function(response) {
            response.hasitems = response.items.length > 0;
            return Templates.render('mod_certificatepro/ornament_picker', response);
        });
    };

    /**
     * Open the picker.
     *
     * @param {Object} config templateid, pageid, and a done callback
     * @return {Promise} resolves once the dialog is on screen
     */
    var open = function(config) {
        var filter = {role: '', orientation: '', q: ''};

        return Promise.all([
            load(config, filter),
            Str.get_string('ornamenttitle', 'mod_certificatepro')
        ]).then(function(parts) {
            return Modal.create({
                title: parts[1],
                body: parts[0],
                large: true,
                removeOnClose: true,
                returnElement: config.returnElement
            });
        }).then(function(modal) {
            return modal.show().then(function() {
                wire(modal, config, filter);
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Attach behaviour to whatever is currently in the dialog body.
     *
     * Refiltering replaces the body wholesale, so this runs again each time
     * rather than trying to keep stale handles alive.
     *
     * @param {Object} modal the modal
     * @param {Object} config templateid, pageid, and a done callback
     * @param {Object} filter the live filter state
     * @return {void}
     */
    var wire = function(modal, config, filter) {
        var body = modal.getBody()[0];
        var root = body.querySelector('[data-region="ornamentpicker"]');
        if (!root) {
            return;
        }

        var gallery = root.querySelector('[data-region="gallery"]');
        var apply = root.querySelector('[data-action="apply"]');
        var note = root.querySelector('[data-region="note"]');
        var search = root.querySelector('[data-region="search"]');
        var orientation = root.querySelector('[data-region="orientation"]');
        var timer = null;

        /**
         * Reload the body with the current filter and rewire it.
         *
         * @param {boolean} keepsearchfocus whether to put focus back in the search box
         * @return {void}
         */
        var refresh = function(keepsearchfocus) {
            load(config, filter).then(function(html) {
                modal.setBody(html);
                // setBody replaces the markup, so everything below is rebound.
                window.setTimeout(function() {
                    wire(modal, config, filter);
                    var again = modal.getBody()[0].querySelector('[data-region="search"]');
                    if (keepsearchfocus && again) {
                        again.focus();
                        again.setSelectionRange(again.value.length, again.value.length);
                    }
                }, 0);
                return html;
            }).catch(Notification.exception);
        };

        if (!gallery) {
            // An empty library has no gallery, only its explanation.
            return;
        }

        var handle = Gallery.attach(gallery, function(value, tile) {
            apply.disabled = !value;
            var name = tile.querySelector('.cpro-gallery-name');
            note.textContent = name ? name.textContent : '';
        });

        root.addEventListener('click', function(event) {
            var facet = event.target.closest('[data-action="role"]');
            if (!facet) {
                return;
            }
            filter.role = facet.getAttribute('data-value');
            refresh(false);
        });

        if (search) {
            search.value = filter.q;
            search.addEventListener('input', function() {
                window.clearTimeout(timer);
                timer = window.setTimeout(function() {
                    filter.q = search.value;
                    refresh(true);
                }, SEARCH_DELAY);
            });
        }

        if (orientation) {
            orientation.value = filter.orientation;
            orientation.addEventListener('change', function() {
                filter.orientation = orientation.value;
                refresh(false);
            });
        }

        apply.addEventListener('click', function() {
            var chosen = handle.value();
            if (!chosen) {
                return;
            }

            apply.disabled = true;

            Ajax.call([{
                methodname: 'mod_certificatepro_place_ornament',
                args: {
                    templateid: config.templateid,
                    pageid: config.pageid,
                    assetid: chosen
                }
            }])[0].then(function(result) {
                modal.destroy();
                if (config.done) {
                    config.done(result);
                }
                return result;
            }).catch(function(error) {
                apply.disabled = false;
                Notification.exception(error);
            });
        });

        gallery.focus();
    };

    return {
        open: open
    };
});

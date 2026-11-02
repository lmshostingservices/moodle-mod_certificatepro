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
 * The typography combination picker.
 *
 * Twenty combinations, each drawn as a specimen certificate in the site's own
 * web fonts. Choosing one restyles every text element at once; an element that
 * needs to disagree afterwards still can, through its own settings form.
 *
 * @module     mod_certificatepro/stylepicker
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/templates', 'core/modal', 'core/str',
    'mod_certificatepro/gallery'],
function(Ajax, Notification, Templates, Modal, Str, Gallery) {

    'use strict';

    /**
     * Open the picker.
     *
     * @param {Object} config templateid, and a done callback
     * @return {Promise} resolves once the dialog is on screen
     */
    var open = function(config) {
        return Ajax.call([{
            methodname: 'mod_certificatepro_get_style_combinations',
            args: {templateid: config.templateid}
        }])[0].then(function(response) {
            return Promise.all([
                Templates.render('mod_certificatepro/style_picker', response),
                Str.get_string('stylepickertitle', 'mod_certificatepro')
            ]);
        }).then(function(parts) {
            return Modal.create({
                title: parts[1],
                body: parts[0],
                large: true,
                removeOnClose: true,
                returnElement: config.returnElement
            });
        }).then(function(modal) {
            return modal.show().then(function() {
                wire(modal, config);
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Attach behaviour once the dialog is on screen.
     *
     * @param {Object} modal the modal
     * @param {Object} config templateid, and a done callback
     * @return {void}
     */
    var wire = function(modal, config) {
        var root = modal.getBody()[0].querySelector('[data-region="stylepicker"]');
        if (!root) {
            return;
        }

        var gallery = root.querySelector('[data-region="gallery"]');
        var apply = root.querySelector('[data-action="apply"]');
        var note = root.querySelector('[data-region="note"]');

        var handle = Gallery.attach(gallery, function(value, tile) {
            apply.disabled = !value;
            var name = tile.querySelector('.cpro-gallery-name');
            note.textContent = name ? name.textContent : '';
        });

        apply.addEventListener('click', function() {
            var chosen = handle.value();
            if (!chosen) {
                return;
            }

            apply.disabled = true;

            Str.get_string('styleapplying', 'mod_certificatepro').then(function(message) {
                note.textContent = message;
                return message;
            }).catch(Notification.exception);

            Ajax.call([{
                methodname: 'mod_certificatepro_apply_style_combination',
                args: {templateid: config.templateid, combination: chosen}
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

        // The gallery is the point of the dialog, so it takes focus rather than
        // the close button the modal would otherwise settle on. The modal keeps
        // focus inside itself and restores it to the trigger when it closes.
        gallery.focus();
    };

    return {
        open: open
    };
});

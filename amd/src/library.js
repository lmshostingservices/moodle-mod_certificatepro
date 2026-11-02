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
 * The design library bar: typography, backgrounds, ornament and the two wizards.
 *
 * Everything here changes the design on the server rather than in the browser,
 * so the designer is reloaded afterwards. Any geometry the author had moved but
 * not yet had saved is flushed first, because a debounced autosave and a page
 * reload are otherwise a good way to lose the last thing somebody dragged.
 *
 * @module     mod_certificatepro/library
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/notification', 'mod_certificatepro/stylepicker',
    'mod_certificatepro/backgroundpicker', 'mod_certificatepro/ornamentpicker',
    'mod_certificatepro/briefwizard'],
function(Notification, StylePicker, BackgroundPicker, OrnamentPicker, BriefWizard) {

    'use strict';

    /**
     * Start the library bar.
     *
     * @param {string} selector a selector for the designer root
     * @param {Object} config templateid and the fallback page id
     * @return {void}
     */
    /**
     * Read the bootstrap payload the renderer left on the root element.
     *
     * It travels on a data attribute rather than as a js_call_amd argument,
     * because Moodle inlines those into a script tag and warns past 1024
     * characters — which any real design exceeds at once.
     *
     * @param {Element} root the designer root
     * @return {Object} the payload, or an empty object when it is missing
     */
    var bootstrap = function(root) {
        try {
            return JSON.parse(root.getAttribute('data-cpro-bootstrap') || '{}');
        } catch (e) {
            window.console.error('certificatepro: unreadable bootstrap payload', e);
            return {};
        }
    };

    var init = function(selector) {
        var root = document.querySelector(selector);
        if (!root) {
            return;
        }

        var config = bootstrap(root);

        root.addEventListener('click', function(event) {
            var trigger = event.target.closest('[data-library-action]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            open(trigger.getAttribute('data-library-action'), root, config, trigger);

            // core/modal's returnElement option restores focus for us on the
            // versions that have it. This is the belt to that braces: after the
            // dialog goes away, if focus has fallen back to the document, put it
            // where the author left it. Cheap, and it does not depend on which
            // Moodle branch the site is running.
            var restore = function() {
                if (document.activeElement === document.body
                        || document.activeElement === document.documentElement) {
                    trigger.focus();
                }
            };
            window.setTimeout(function watch(tries) {
                if (document.querySelector('.modal.show')) {
                    window.setTimeout(watch, 250);
                    return;
                }
                restore();
            }, 400);
        });
    };

    /**
     * The page the author is looking at.
     *
     * The designer knows; if for any reason it has not started, the first page
     * the server named is a safe answer.
     *
     * @param {HTMLElement} root the designer root
     * (the bootstrap payload is read from the root element)
     * @return {number} a page id
     */
    var currentPage = function(root, config) {
        if (root.cproDesigner && root.cproDesigner.page) {
            var page = root.cproDesigner.page();
            if (page && page.id) {
                return page.id;
            }
        }
        return config.pageid;
    };

    /**
     * The selected element, when it is one of a given type.
     *
     * Opening the seal wizard with a seal selected should restyle that seal
     * rather than adding another one beside it.
     *
     * @param {HTMLElement} root the designer root
     * @param {string} type the element type to look for
     * @return {number} the element id, or 0
     */
    var selectedOfType = function(root, type) {
        var designer = root.cproDesigner;
        if (!designer || !designer.page || !designer.selection
                || designer.selection.length !== 1) {
            return 0;
        }

        var page = designer.page();
        var elements = (page && page.elements) || [];

        for (var i = 0; i < elements.length; i++) {
            if (elements[i].id === designer.selection[0] && elements[i].type === type) {
                return elements[i].id;
            }
        }

        return 0;
    };

    /**
     * Save anything outstanding, then reload so the design redraws from the server.
     *
     * @param {HTMLElement} root the designer root
     * @return {void}
     */
    var reload = function(root) {
        var done = function() {
            window.location.reload();
        };

        if (root.cproDesigner && root.cproDesigner.save) {
            var saving = root.cproDesigner.save();
            if (saving && saving.then) {
                saving.then(done).catch(done);
                return;
            }
        }

        done();
    };

    /**
     * Open one of the libraries.
     *
     * @param {string} action which library
     * @param {HTMLElement} root the designer root
     * (the bootstrap payload is read from the root element)
     * @param {HTMLElement} trigger the button that was pressed
     * @return {void}
     */
    var open = function(action, root, config, trigger) {
        var options = {
            templateid: config.templateid,
            pageid: currentPage(root, config),
            returnElement: trigger,
            done: function() {
                reload(root);
            }
        };

        switch (action) {
            case 'styles':
                StylePicker.open(options);
                break;
            case 'backgrounds':
                BackgroundPicker.open(options);
                break;
            case 'ornaments':
                OrnamentPicker.open(options);
                break;
            case 'artworkbrief':
                BriefWizard.artwork(options);
                break;
            case 'sealbrief':
                options.elementid = selectedOfType(root, 'seal');
                BriefWizard.seal(options);
                break;
            default:
                Notification.exception(new Error('Unknown design library: ' + action));
                break;
        }
    };

    return {
        init: init
    };
});

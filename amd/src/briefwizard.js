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
 * The two guided wizards: one for background artwork, one for an organisation seal.
 *
 * Both ask questions the server defines and neither asks the author to write a
 * prompt. The last screen shows what the answers resolve to before anything is
 * saved, so the author sees the fallback artwork even on a site where a model
 * will be asked first.
 *
 * @module     mod_certificatepro/briefwizard
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/templates', 'core/modal', 'core/str'],
function(Ajax, Notification, Templates, Modal, Str) {

    'use strict';

    /** The services each wizard talks to. */
    var SERVICES = {
        artwork: {
            questions: 'mod_certificatepro_get_artwork_brief',
            preview: 'mod_certificatepro_preview_artwork_brief',
            apply: 'mod_certificatepro_apply_artwork_brief',
            title: 'artworkwizardtitle'
        },
        seal: {
            questions: 'mod_certificatepro_get_seal_brief',
            preview: 'mod_certificatepro_preview_seal_brief',
            apply: 'mod_certificatepro_apply_seal_brief',
            title: 'sealwizardtitle'
        }
    };

    /**
     * Turn the questions the server sent into the shape the template wants.
     *
     * @param {string} kind artwork or seal
     * @param {Object} response the service response
     * @return {Object} the template context
     */
    var context = function(kind, response) {
        var steps = response.questions.map(function(question, index) {
            return {
                index: index,
                human: index + 1,
                name: question.name,
                label: question.label,
                type: question.type,
                multiple: question.multiple,
                required: question.required,
                maxlength: question.maxlength,
                hasmaxlength: question.maxlength > 0,
                ischoice: question.options.length > 0,
                istext: question.options.length === 0,
                first: index === 0,
                last: index === response.questions.length - 1,
                options: question.options
            };
        });

        return {
            kind: kind,
            isartwork: kind === 'artwork',
            isseal: kind === 'seal',
            canusemodel: !!response.canusemodel,
            total: steps.length,
            steps: steps
        };
    };

    /**
     * Open a wizard.
     *
     * @param {string} kind artwork or seal
     * @param {Object} config templateid, pageid, and a done callback
     * @return {Promise} resolves once the dialog is on screen
     */
    var open = function(kind, config) {
        var service = SERVICES[kind];

        return Ajax.call([{
            methodname: service.questions,
            args: {templateid: config.templateid}
        }])[0].then(function(response) {
            return Promise.all([
                Templates.render('mod_certificatepro/brief_wizard', context(kind, response)),
                Str.get_string(service.title, 'mod_certificatepro')
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
                wire(modal, kind, config);
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Attach behaviour once the dialog is on screen.
     *
     * @param {Object} modal the modal
     * @param {string} kind artwork or seal
     * @param {Object} config templateid, pageid, and a done callback
     * @return {void}
     */
    var wire = function(modal, kind, config) {
        var service = SERVICES[kind];
        var root = modal.getBody()[0].querySelector('[data-region="wizard"]');
        if (!root) {
            return;
        }

        var steps = Array.prototype.slice.call(root.querySelectorAll('[data-region="step"]'));
        var result = root.querySelector('[data-region="result"]');
        var back = root.querySelector('[data-action="back"]');
        var next = root.querySelector('[data-action="next"]');
        var apply = root.querySelector('[data-action="apply"]');
        var note = root.querySelector('[data-region="note"]');
        var progress = root.querySelector('[data-region="progress"]');
        var bar = root.querySelector('[data-region="bar"]');
        var current = 0;

        /**
         * Every answer currently on the form.
         *
         * @return {Object[]} name and value pairs
         */
        var answers = function() {
            var out = [];

            steps.forEach(function(step) {
                var name = step.getAttribute('data-name');

                Array.prototype.forEach.call(step.querySelectorAll('input'), function(input) {
                    if (input.type === 'radio' || input.type === 'checkbox') {
                        if (input.checked) {
                            out.push({name: name, value: input.value});
                        }
                    } else if (input.value !== '') {
                        out.push({name: name, value: input.value});
                    }
                });
            });

            return out;
        };

        /**
         * Show one step and describe where the author is.
         *
         * @param {number} index the step to show
         * @return {void}
         */
        var show = function(index) {
            current = Math.max(0, Math.min(steps.length, index));

            steps.forEach(function(step, i) {
                step.hidden = i !== current;
            });

            var onresult = current >= steps.length;
            result.hidden = !onresult;
            back.hidden = current === 0;
            next.hidden = onresult;
            apply.hidden = !onresult;

            var done = Math.min(current, steps.length);
            bar.style.width = Math.round((done / steps.length) * 100) + '%';

            Str.get_string(onresult ? 'briefstepresult' : 'briefstepof', 'mod_certificatepro',
                {current: current + 1, total: steps.length}).then(function(message) {
                progress.textContent = message;
                return message;
            }).catch(Notification.exception);

            if (!onresult) {
                var field = steps[current].querySelector('input');
                if (field) {
                    field.focus();
                }
            } else {
                apply.focus();
            }
        };

        /**
         * Ask the server what the answers resolve to and draw it.
         *
         * @return {void}
         */
        var preview = function() {
            next.disabled = true;

            Str.get_string('briefworking', 'mod_certificatepro').then(function(message) {
                note.textContent = message;
                return message;
            }).catch(Notification.exception);

            Ajax.call([{
                methodname: service.preview,
                args: {templateid: config.templateid, answers: answers()}
            }])[0].then(function(response) {
                next.disabled = false;
                note.textContent = '';
                render(response);
                show(steps.length);
                return response;
            }).catch(function(error) {
                next.disabled = false;
                Notification.exception(error);
            });
        };

        /**
         * Draw a preview response into the result panel.
         *
         * @param {Object} response the preview
         * @return {void}
         */
        var render = function(response) {
            root.querySelector('[data-region="preview"]').innerHTML = response.svg;

            var heading = root.querySelector('[data-region="resultheading"]');
            var summary = root.querySelector('[data-region="summary"]');
            var warnings = root.querySelector('[data-region="warnings"]');

            warnings.innerHTML = '';

            if (kind === 'artwork') {
                heading.textContent = response.stylename;
                summary.textContent = response.summary;
            } else {
                heading.textContent = response.markname;
                summary.textContent = [response.edgename, response.ringname, response.markname]
                    .join(' · ');

                (response.warnings || []).forEach(function(warning) {
                    var item = document.createElement('li');
                    item.textContent = warning.message;
                    warnings.appendChild(item);
                });
            }
        };

        back.addEventListener('click', function() {
            show(current - 1);
        });

        next.addEventListener('click', function() {
            if (current >= steps.length - 1) {
                preview();
                return;
            }
            show(current + 1);
        });

        // Answering a question in place should update the result rather than
        // making the author walk back to the end again.
        root.addEventListener('change', function() {
            if (current >= steps.length) {
                preview();
            }
        });

        apply.addEventListener('click', function() {
            apply.disabled = true;

            var args = {
                templateid: config.templateid,
                pageid: config.pageid,
                answers: answers()
            };

            if (kind === 'artwork') {
                var usemodel = root.querySelector('[data-region="usemodel"]');
                args.usemodel = !!(usemodel && usemodel.checked);
            } else {
                args.elementid = config.elementid || 0;
            }

            Ajax.call([{methodname: service.apply, args: args}])[0].then(function(response) {
                modal.destroy();
                if (config.done) {
                    config.done(response);
                }
                return response;
            }).catch(function(error) {
                apply.disabled = false;
                Notification.exception(error);
            });
        });

        show(0);
    };

    return {
        /**
         * Open the artwork wizard.
         *
         * @param {Object} config templateid, pageid, and a done callback
         * @return {Promise} resolves once the dialog is on screen
         */
        artwork: function(config) {
            return open('artwork', config);
        },

        /**
         * Open the seal wizard.
         *
         * @param {Object} config templateid, pageid, and a done callback
         * @return {Promise} resolves once the dialog is on screen
         */
        seal: function(config) {
            return open('seal', config);
        }
    };
});

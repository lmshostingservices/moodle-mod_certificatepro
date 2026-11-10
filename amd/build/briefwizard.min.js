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
 * The two guided designers: one for background artwork, one for an organisation seal.
 *
 * HOW THIS DIFFERS FROM THE WIZARD IT REPLACES
 *
 * The old one asked six or eight abstract questions as radio lists, showed
 * nothing until the end, and produced a single answer to accept or reject. Its
 * author was choosing between adjectives with no idea what any of them drew.
 *
 * This one shows every option as a real thumbnail rendered by the same engine
 * that prints the certificate, keeps a live preview beside the questions from
 * the first answer onwards, and finishes on a grid of nine finished results
 * with a "more like this" to explore around whichever one is closest. That is
 * the shape every serious generator of this kind converged on, and the reason
 * is not fashion: people are poor at imagining a picture from a word and very
 * good at picking one out of nine.
 *
 * WHERE THE WORK HAPPENS
 *
 * Every screen is one web service call. That call returns the question, the
 * thumbnails for its galleries and the live preview together, because nine
 * separate calls for nine thumbnails is most of a second of latency on a school
 * network and a grid that fills in whatever order the responses land.
 *
 * Selecting a thumbnail does not call anything at all. The tile already holds
 * the drawing that answer produces, so choosing it moves that same markup into
 * the preview: instant, and correct by construction.
 *
 * WHY A SCREEN IS REFRESHED IN PLACE
 *
 * When a screen carries two galleries, answering one makes the other's
 * thumbnails stale — they were drawn against the answer that just changed. The
 * screen is therefore re-fetched, but only the pictures are swapped in. Tearing
 * the markup down and rebuilding it would take focus off the tile the author is
 * arrowing through, and take the cursor out of the field they are typing in.
 *
 * @module     mod_certificatepro/briefwizard
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/templates', 'core/modal', 'core/str',
    'mod_certificatepro/gallery'],
function (Ajax, Notification, Templates, Modal, Str, Gallery) {

    'use strict';

    /** How many finished results the last screen offers. */
    var VARIANTS = 9;

    /** How long to wait after the last keystroke before redrawing. */
    var TYPING_PAUSE = 450;

    /** The services and strings each wizard uses. */
    var KINDS = {
        artwork: {
            apply: 'mod_certificatepro_apply_artwork_brief',
            title: 'artworkwizardtitle',
            variants: 'wizartvariants',
            variantshint: 'wizartvariantshint'
        },
        seal: {
            apply: 'mod_certificatepro_apply_seal_brief',
            title: 'sealwizardtitle',
            variants: 'wizsealvariants',
            variantshint: 'wizsealvariantshint'
        }
    };

    /**
     * Open a wizard.
     *
     * @param {string} kind artwork or seal
     * @param {Object} config templateid, pageid, elementid and a done callback
     * @return {Promise} resolves once the dialog is on screen
     */
    var open = function (kind, config) {
        return Promise.all([
            Templates.render('mod_certificatepro/brief_wizard', {
                kind: kind,
                isartwork: kind === 'artwork',
                isseal: kind === 'seal',
                // Reaching the artwork wizard at all needs the capability to
                // generate artwork, so anybody who can see this may offer the
                // brief to a model.
                canusemodel: kind === 'artwork'
            }),
            Str.get_string(KINDS[kind].title, 'mod_certificatepro')
        ]).then(function (parts) {
            return Modal.create({
                title: parts[1],
                body: parts[0],
                large: true,
                removeOnClose: true,
                returnElement: config.returnElement
            });
        }).then(function (modal) {
            return modal.show().then(function () {
                new Wizard(kind, config, modal).start();
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * One running wizard.
     *
     * @param {string} kind artwork or seal
     * @param {Object} config templateid, pageid, elementid and a done callback
     * @param {Object} modal the dialog it lives in
     * @constructor
     */
    var Wizard = function (kind, config, modal) {
        this.kind = kind;
        this.config = config;
        this.modal = modal;
        this.root = modal.getBody()[0].querySelector('[data-region="wizard"]');

        /** Name to value. The single source of truth for everything asked. */
        this.answers = {};
        /** Screen identifiers, in order, as the server named them. */
        this.screens = [];
        /** Where the author is. Equal to screens.length on the variant grid. */
        this.at = 0;
        /** Screen responses, keyed by screen and the answers they were drawn for. */
        this.cache = {};
        /** The variant the author picked, as an opaque key. */
        this.picked = '';
        /** Which typed fields must be answered before Next is offered. */
        this.required = [];
        /** A pending refresh, after typing or a sibling gallery changing. */
        this.pending = null;

        this.el = {};
        ['rail', 'title', 'hint', 'content', 'preview', 'caption', 'warnings', 'note',
            'extras', 'stagelabel'].forEach(function (name) {
            this.el[name] = this.root.querySelector('[data-region="' + name + '"]');
        }, this);
        ['back', 'next', 'apply', 'more'].forEach(function (name) {
            this.el[name] = this.root.querySelector('[data-action="' + name + '"]');
        }, this);
    };

    /**
     * Wire the buttons up and draw the first screen.
     *
     * @return {void}
     */
    Wizard.prototype.start = function () {
        var self = this;

        this.el.back.addEventListener('click', function () {
            self.go(self.at - 1);
        });
        this.el.next.addEventListener('click', function () {
            self.go(self.at + 1);
        });
        this.el.more.addEventListener('click', function () {
            self.showVariants(self.picked);
        });
        this.el.apply.addEventListener('click', function () {
            self.apply();
        });

        this.go(0);
    };

    // ------------------------------------------------------------------
    // Navigation.
    // ------------------------------------------------------------------

    /**
     * Move to a screen, or to the variant grid when there are no screens left.
     *
     * Answers live on the wizard rather than in the markup, so walking back
     * cannot lose one. The screen is re-requested rather than restored from the
     * DOM, because a thumbnail drawn before an earlier answer changed would be
     * showing the author something that is no longer true; the cache is keyed on
     * the answers, so a walk back that changed nothing is instant.
     *
     * @param {number} index the screen to show
     * @return {void}
     */
    Wizard.prototype.go = function (index) {
        window.clearTimeout(this.pending);

        if (index < 0) {
            return;
        }

        if (this.screens.length && index >= this.screens.length) {
            this.at = this.screens.length;
            this.showVariants('');
            return;
        }

        this.at = index;
        this.fetch(this.screens[index] || '').then(function (response) {
            if (response) {
                this.build(response);
            }
            return response;
        }.bind(this)).catch(Notification.exception);
    };

    /**
     * Ask the server for a screen, from the cache when nothing has changed.
     *
     * @param {string} screen the screen identifier, or an empty string for the first
     * @return {Promise} resolves with the screen
     */
    Wizard.prototype.fetch = function (screen) {
        var self = this;
        var key = screen + '|' + JSON.stringify(this.answers);

        if (this.cache[key]) {
            return Promise.resolve(this.cache[key]);
        }

        this.busy(true);

        return Ajax.call([{
            methodname: 'mod_certificatepro_get_wizard_screen',
            args: {
                templateid: this.config.templateid,
                kind: this.kind,
                screen: screen,
                answers: this.flatten()
            }
        }])[0].then(function (response) {
            self.cache[key] = response;
            self.busy(false);
            return response;
        }).catch(function (error) {
            self.busy(false);
            Notification.exception(error);
            return null;
        });
    };

    /**
     * Fetch the current screen again and swap only the pictures in.
     *
     * @return {void}
     */
    Wizard.prototype.refresh = function () {
        var self = this;

        window.clearTimeout(this.pending);
        this.pending = window.setTimeout(function () {
            var screen = self.screens[self.at] || '';
            self.fetch(screen).then(function (response) {
                if (response && response.screen === screen) {
                    self.repaint(response);
                }
                return response;
            }).catch(Notification.exception);
        }, TYPING_PAUSE);
    };

    /**
     * The answers as the flat list the service takes.
     *
     * @return {Object[]} name and value pairs
     */
    Wizard.prototype.flatten = function () {
        var out = [];

        Object.keys(this.answers).forEach(function (name) {
            var value = this.answers[name];
            if (value !== '' && value !== null && value !== undefined) {
                out.push({name: name, value: String(value)});
            }
        }, this);

        return out;
    };

    /**
     * Show or clear the working state.
     *
     * @param {boolean} working whether a call is outstanding
     * @return {void}
     */
    Wizard.prototype.busy = function (working) {
        var self = this;

        this.el.back.disabled = working;
        this.el.next.disabled = working || this.incomplete();
        this.el.more.disabled = working;
        this.el.apply.disabled = working || (this.onVariants() && !this.picked);
        this.root.classList.toggle('cpro-wiz-busy', working);

        if (!working) {
            this.el.note.textContent = '';
            return;
        }

        Str.get_string('briefworking', 'mod_certificatepro').then(function (message) {
            if (self.root.classList.contains('cpro-wiz-busy')) {
                self.el.note.textContent = message;
            }
            return message;
        }).catch(Notification.exception);
    };

    /**
     * Whether a required answer on this screen is still missing.
     *
     * @return {boolean} true when Next should wait
     */
    Wizard.prototype.incomplete = function () {
        return this.required.some(function (name) {
            return !String(this.answers[name] || '').trim();
        }, this);
    };

    /**
     * Whether the author is on the variant grid.
     *
     * @return {boolean} true on the last screen of all
     */
    Wizard.prototype.onVariants = function () {
        return this.screens.length > 0 && this.at >= this.screens.length;
    };

    // ------------------------------------------------------------------
    // Building a question screen.
    // ------------------------------------------------------------------

    /**
     * Build one screen from the server's answer.
     *
     * @param {Object} response the screen
     * @return {void}
     */
    Wizard.prototype.build = function (response) {
        var self = this;

        this.screens = response.screens;
        this.at = response.index;
        this.picked = '';
        this.required = [];

        this.el.title.textContent = response.title;
        this.el.hint.textContent = response.hint;
        this.el.content.textContent = '';

        if (response.fields.length) {
            this.el.content.appendChild(this.buildFields(response.fields));
        }

        response.groups.forEach(function (group) {
            self.el.content.appendChild(
                self.buildGallery(group, response.groups.length > 1));
        });

        this.showPreview(response.preview);
        this.rail(response.index, response.screens.length);
        this.chrome(false);
        this.stageLabel('wizpreviewlabel');
        this.el.title.focus();
    };

    /**
     * Swap fresh pictures into a screen already on show, touching nothing else.
     *
     * @param {Object} response the screen, redrawn against the current answers
     * @return {void}
     */
    Wizard.prototype.repaint = function (response) {
        var self = this;

        this.showPreview(response.preview);

        response.groups.forEach(function (group) {
            var list = self.el.content.querySelector('[data-group="' + group.name + '"]');
            if (!list) {
                return;
            }
            group.options.forEach(function (option) {
                var tile = list.querySelector('[data-value="' + option.value + '"]');
                var art = tile && tile.querySelector('.cpro-wiz-art');
                if (art) {
                    art.innerHTML = option.svg;
                    self.fit(art);
                }
            });
        });
    };

    /**
     * The typed fields: the things only the author can know.
     *
     * @param {Object[]} fields the field descriptions
     * @return {HTMLElement} the markup
     */
    Wizard.prototype.buildFields = function (fields) {
        var self = this;
        var wrap = document.createElement('div');
        wrap.className = 'cpro-wiz-fields';

        fields.forEach(function (field) {
            if (!Object.prototype.hasOwnProperty.call(self.answers, field.name)) {
                self.answers[field.name] = field.value;
            }
            if (field.required) {
                self.required.push(field.name);
            }

            var id = 'cpro-wiz-' + field.name;
            var row = document.createElement('div');
            row.className = 'cpro-wiz-field';

            var label = document.createElement('label');
            label.setAttribute('for', id);
            label.textContent = field.label;
            row.appendChild(label);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.id = id;
            input.name = field.name;
            input.autocomplete = 'off';
            input.value = self.answers[field.name] || '';

            if (field.maxlength > 0) {
                input.maxLength = field.maxlength;
            }
            if (field.required) {
                input.required = true;
            }
            if (field.type === 'year') {
                input.inputMode = 'numeric';
                input.maxLength = 4;
            }

            input.addEventListener('input', function () {
                self.answers[field.name] = input.value;
                self.el.next.disabled = self.incomplete();
                self.refresh();
            });

            row.appendChild(input);
            wrap.appendChild(row);
        });

        return wrap;
    };

    /**
     * One gallery: a listbox of complete results, one per option.
     *
     * @param {Object} group the gallery description
     * @param {boolean} shared whether this screen carries more than one gallery
     * @return {HTMLElement} the gallery
     */
    Wizard.prototype.buildGallery = function (group, shared) {
        var self = this;
        var block = document.createElement('div');
        block.className = 'cpro-wiz-group';

        // On a screen with one gallery the heading would only repeat the
        // question above it, so it is there for a screen reader and not for
        // the eye.
        var heading = document.createElement('p');
        heading.className = 'cpro-wiz-grouphead';
        heading.id = 'cpro-wiz-head-' + group.name;
        heading.textContent = group.label;
        heading.hidden = !shared;
        block.appendChild(heading);

        var list = document.createElement('ul');
        // Two galleries on one screen halves the room each has, so page-shaped
        // tiles step down a size rather than pushing the second question below
        // the fold.
        list.className = 'cpro-wiz-gallery' + (group.wide ? ' is-wide' : '')
            + (shared ? ' is-compact' : '');
        list.setAttribute('role', 'listbox');
        list.setAttribute('data-group', group.name);
        list.setAttribute('aria-labelledby', heading.id);

        // Whatever the author already chose wins over the server's default, so
        // walking back onto a screen shows the answer they gave.
        var chosen = this.answers[group.name];
        if (!chosen) {
            group.options.forEach(function (option) {
                if (!chosen && option.selected) {
                    chosen = option.value;
                }
            });
            chosen = chosen || (group.options[0] && group.options[0].value) || '';
            this.answers[group.name] = chosen;
        }

        group.options.forEach(function (option) {
            list.appendChild(self.buildTile(option.value, option.label, option.svg,
                option.value === chosen));
        });

        this.tune(list);

        Gallery.attach(list, function (value, tile) {
            self.answers[group.name] = value;
            self.previewFromTile(tile);

            if (shared) {
                // The other gallery on this screen was drawn against the answer
                // that just changed, so its thumbnails are now telling a small
                // lie. Fetch the screen again and swap the pictures.
                self.refresh();
            }
        });

        block.appendChild(list);

        return block;
    };

    /**
     * One tile.
     *
     * @param {string} value what choosing it answers
     * @param {string} label its name
     * @param {string} svg the drawing
     * @param {boolean} chosen whether it is the current answer
     * @return {HTMLElement} the tile
     */
    Wizard.prototype.buildTile = function (value, label, svg, chosen) {
        var tile = document.createElement('li');
        tile.className = 'cpro-wiz-tile' + (chosen ? ' cpro-selected' : '');
        tile.setAttribute('data-region', 'tile');
        tile.setAttribute('data-value', value);
        tile.setAttribute('role', 'option');
        tile.setAttribute('aria-selected', chosen ? 'true' : 'false');
        tile.setAttribute('tabindex', chosen ? '0' : '-1');

        var art = document.createElement('span');
        art.className = 'cpro-wiz-art';
        art.innerHTML = svg;
        this.fit(art);
        tile.appendChild(art);

        var name = document.createElement('span');
        name.className = 'cpro-wiz-name';
        name.textContent = label;
        tile.appendChild(name);

        return tile;
    };

    /**
     * Move a tile's own drawing into the preview.
     *
     * The tile was rendered from every other answer already given, so it is not
     * an approximation of the result — it is the result. Copying it costs
     * nothing and cannot disagree with what the server would draw.
     *
     * @param {HTMLElement} tile the chosen tile
     * @return {void}
     */
    Wizard.prototype.previewFromTile = function (tile) {
        var art = tile.querySelector('.cpro-wiz-art svg');
        var label = tile.querySelector('.cpro-wiz-name');

        if (art) {
            this.el.preview.innerHTML = art.outerHTML;
            this.shape(art.getAttribute('viewBox'));
        }
        if (label) {
            this.el.caption.textContent = label.textContent;
        }
        this.el.warnings.textContent = '';
    };

    // ------------------------------------------------------------------
    // The variant grid.
    // ------------------------------------------------------------------

    /**
     * Draw a spread of finished results.
     *
     * @param {string} like a variant key to explore around, or an empty string
     * @return {Promise} resolves once the grid is on show
     */
    Wizard.prototype.showVariants = function (like) {
        var self = this;

        this.busy(true);

        return Ajax.call([{
            methodname: 'mod_certificatepro_get_wizard_variants',
            args: {
                templateid: this.config.templateid,
                kind: this.kind,
                answers: this.flatten(),
                like: like || '',
                count: VARIANTS
            }
        }])[0].then(function (response) {
            self.busy(false);
            self.buildVariants(response.variants);
            return response;
        }).catch(function (error) {
            self.busy(false);
            Notification.exception(error);
        });
    };

    /**
     * Put a set of variants on screen.
     *
     * @param {Object[]} variants what came back
     * @return {void}
     */
    Wizard.prototype.buildVariants = function (variants) {
        var self = this;
        var strings = KINDS[this.kind];

        this.at = this.screens.length;
        this.required = [];
        this.el.content.textContent = '';

        var list = document.createElement('ul');
        list.className = 'cpro-wiz-gallery is-variants'
            + (this.kind === 'artwork' ? ' is-wide' : '');
        list.setAttribute('role', 'listbox');
        list.setAttribute('data-group', 'variant');

        variants.forEach(function (variant, index) {
            list.appendChild(self.buildTile(variant.key, variant.label, variant.svg,
                index === 0));
        });

        this.picked = variants.length ? variants[0].key : '';
        this.tune(list);

        Gallery.attach(list, function (value, tile) {
            self.picked = value;
            self.previewFromTile(tile);
            self.el.apply.disabled = false;
        });

        this.el.content.appendChild(list);

        if (list.firstChild) {
            this.previewFromTile(list.firstChild);
        }

        this.rail(this.screens.length, this.screens.length);
        this.chrome(true);

        this.stageLabel('wizchosenlabel');

        Str.get_strings([
            {key: strings.variants, component: 'mod_certificatepro'},
            {key: strings.variantshint, component: 'mod_certificatepro'}
        ]).then(function (text) {
            self.el.title.textContent = text[0];
            self.el.hint.textContent = text[1];
            list.setAttribute('aria-label', text[0]);
            return text;
        }).catch(Notification.exception);

        this.el.title.focus();
    };

    // ------------------------------------------------------------------
    // Chrome.
    // ------------------------------------------------------------------

    /**
     * Draw the live preview.
     *
     * @param {Object} preview svg, caption and warnings
     * @return {void}
     */
    Wizard.prototype.showPreview = function (preview) {
        this.el.preview.innerHTML = preview.svg;
        this.el.caption.textContent = preview.caption;
        this.el.warnings.textContent = '';

        var svg = this.el.preview.querySelector('svg');
        if (svg) {
            this.shape(svg.getAttribute('viewBox'));
        }

        (preview.warnings || []).forEach(function (warning) {
            var item = document.createElement('li');
            item.textContent = warning.message;
            this.el.warnings.appendChild(item);
        }, this);
    };

    /**
     * Let a gallery of portrait pages run wider than a gallery of landscape ones.
     *
     * Three landscape thumbnails across is a comfortable row. Three portrait
     * ones is a row half as tall again as the dialog, and the author scrolls
     * past the question to find the answers.
     *
     * @param {HTMLElement} list the gallery
     * @return {void}
     */
    Wizard.prototype.tune = function (list) {
        var svg = list.querySelector('svg');
        var box = String(svg ? svg.getAttribute('viewBox') : '').split(/\s+/);
        var width = parseFloat(box[2]);
        var height = parseFloat(box[3]);

        list.classList.toggle('is-tall', width > 0 && height > width * 1.05);
    };

    /**
     * Set a frame to the shape of the drawing inside it.
     *
     * The CSS cannot know whether a design is landscape or portrait, and the
     * painter emits preserveAspectRatio="none", so a frame left at a fixed
     * shape does not letterbox a portrait page — it squashes one, and every
     * circle in the artwork comes out an ellipse.
     *
     * @param {HTMLElement} frame the element holding the drawing
     * @return {void}
     */
    Wizard.prototype.fit = function (frame) {
        var svg = frame.querySelector('svg');
        if (svg) {
            this.shape(svg.getAttribute('viewBox'), frame);
        }
    };

    /**
     * Set the preview panel to the shape of the thing in it.
     *
     * A portrait certificate previewed in a landscape frame is a preview of a
     * different design, so the frame takes its proportions from the drawing's
     * own viewBox rather than assuming a sheet of A4 on its side.
     *
     * @param {string} viewbox the drawing's viewBox attribute
     * @param {HTMLElement} frame the element to shape, or the preview by default
     * @return {void}
     */
    Wizard.prototype.shape = function (viewbox, frame) {
        var box = String(viewbox || '').split(/\s+/);
        var width = parseFloat(box[2]);
        var height = parseFloat(box[3]);

        if (width > 0 && height > 0) {
            (frame || this.el.preview).style.aspectRatio = width + ' / ' + height;
        }
    };

    /**
     * Name what the panel beside the questions is showing.
     *
     * On a question screen it is a preview of something still being decided; on
     * the last screen it is the result the author has picked. Same panel, two
     * different promises, and walking back has to take the second one away.
     *
     * @param {string} key the language string identifier
     * @return {void}
     */
    Wizard.prototype.stageLabel = function (key) {
        var self = this;

        Str.get_string(key, 'mod_certificatepro').then(function (text) {
            self.el.stagelabel.textContent = text;
            return text;
        }).catch(Notification.exception);
    };

    /**
     * Draw the step rail.
     *
     * @param {number} at which step the author is on
     * @param {number} total how many question screens there are
     * @return {void}
     */
    Wizard.prototype.rail = function (at, total) {
        var self = this;

        this.el.rail.textContent = '';

        for (var i = 0; i <= total; i++) {
            var step = document.createElement('li');
            if (i < at) {
                step.className = 'is-done';
            } else if (i === at) {
                step.className = 'is-here';
                step.setAttribute('aria-current', 'step');
            }

            var pip = document.createElement('span');
            pip.className = 'cpro-wiz-pip';
            pip.setAttribute('aria-hidden', 'true');
            pip.textContent = i < total ? String(i + 1) : '★';
            step.appendChild(pip);

            if (i < total) {
                var tick = document.createElement('span');
                tick.className = 'cpro-wiz-tick';
                tick.setAttribute('aria-hidden', 'true');
                step.appendChild(tick);
            }

            this.el.rail.appendChild(step);
        }

        Str.get_string('briefstepof', 'mod_certificatepro',
            {current: at + 1, total: total + 1}).then(function (message) {
            self.el.rail.setAttribute('aria-label', message);
            return message;
        }).catch(Notification.exception);
    };

    /**
     * Show the buttons this screen needs.
     *
     * @param {boolean} finished whether the author is on the variant grid
     * @return {void}
     */
    Wizard.prototype.chrome = function (finished) {
        this.el.back.hidden = this.at === 0;
        this.el.back.disabled = false;
        this.el.next.hidden = finished;
        this.el.next.disabled = this.incomplete();
        this.el.apply.hidden = !finished;
        this.el.more.hidden = !finished;
        this.el.extras.hidden = !finished;
        this.el.apply.disabled = finished && !this.picked;
    };

    // ------------------------------------------------------------------
    // Landing it on the design.
    // ------------------------------------------------------------------

    /**
     * Save the chosen result onto the design.
     *
     * @return {void}
     */
    Wizard.prototype.apply = function () {
        var self = this;
        var args = {
            templateid: this.config.templateid,
            pageid: this.config.pageid,
            answers: this.flatten(),
            variant: this.picked
        };

        if (this.kind === 'artwork') {
            var usemodel = this.root.querySelector('[data-region="usemodel"]');
            args.usemodel = !!(usemodel && usemodel.checked);
        } else {
            args.elementid = this.config.elementid || 0;
        }

        this.busy(true);

        Ajax.call([{methodname: KINDS[this.kind].apply, args: args}])[0]
            .then(function (response) {
                self.modal.destroy();
                if (self.config.done) {
                    self.config.done(response);
                }
                return response;
            }).catch(function (error) {
                self.busy(false);
                Notification.exception(error);
            });
    };

    return {
        /**
         * Open the artwork wizard.
         *
         * @param {Object} config templateid, pageid, and a done callback
         * @return {Promise} resolves once the dialog is on screen
         */
        artwork: function (config) {
            return open('artwork', config);
        },

        /**
         * Open the seal wizard.
         *
         * @param {Object} config templateid, pageid, elementid and a done callback
         * @return {Promise} resolves once the dialog is on screen
         */
        seal: function (config) {
            return open('seal', config);
        }
    };
});

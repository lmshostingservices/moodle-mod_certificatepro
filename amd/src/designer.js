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
 * The certificate designer canvas.
 *
 * Positions are held in millimetres throughout and converted to pixels only at
 * the moment of drawing, so zooming never introduces rounding drift and what is
 * saved is exactly what the PDF will use.
 *
 * @module     mod_certificatepro/designer
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
function ($, Ajax, Notification, Str) {

    'use strict';

    /** Distance in millimetres within which a guide grabs an edge. */
    var GUIDE_THRESHOLD = 1.6;

    /** Distance in millimetres within which a zone starts to show itself. */
    var ZONE_REVEAL = 26;

    /** Distance in millimetres within which a zone captures a dropped element. */
    var ZONE_CAPTURE = 14;

    /** How far an arrow key moves an element, in millimetres. */
    var NUDGE = 0.5;

    /** How far a shifted arrow key moves an element, in millimetres. */
    var NUDGE_LARGE = 5;

    /** How many steps of history to keep. */
    var HISTORY_LIMIT = 60;

    /** How long to wait after the last change before saving, in milliseconds. */
    var SAVE_DELAY = 700;

    /** How far the pointer must travel before a click counts as a drag, in pixels. */
    var DRAG_THRESHOLD = 2;

    /** How long to gather up keyboard movement before describing it, in milliseconds. */
    var ANNOUNCE_DELAY = 450;

    /** The smallest box an element may be resized to, in millimetres. */
    var MIN_SIZE = 3;

    /** How much of an element has to stay on the page, in millimetres. */
    var KEEP_ON_PAGE = 10;

    /**
     * The designer.
     *
     * @param {HTMLElement} root the designer root element
     * (the bootstrap payload is read from the root element)
     */
    var Designer = function (root, config) {
        this.root = root;
        this.config = config;
        this.template = config.template;
        this.pageIndex = 0;
        this.zoom = 1;
        this.selection = [];
        this.history = [];
        this.future = [];
        this.saveTimer = null;
        this.drag = null;
        this.marquee = null;
        this.strings = {};

        // The page a queued autosave was queued for. A save describes one page's
        // rows, so a save waiting for page one is not an older version of a save
        // for page two and must not be replaced by it. Undo now moves between
        // pages, so this is no longer a theoretical case.
        this.savePage = null;

        // Timers for the two things said out loud: the running commentary of a
        // keyboard move or resize, which is gathered up rather than shouted on
        // every keystroke, and the announcement itself.
        this.announceTimer = null;
        this.geometryTimer = null;

        // The types whose elements own an uploaded file, worked out on the
        // server and carried in the bootstrap payload. Deleting one of these
        // destroys its picture for good, which is the one part of a delete that
        // undo cannot put back, so the author is told before it happens.
        this.filetypes = config.filetypes || [];

        // Snapping is on to begin with, which is what the toolbar button is
        // rendered claiming, and an author can turn it off for fine placement.
        this.snap = true;

        // Set while the whole design is being rebuilt or reshaped on the server,
        // so a second click cannot start a race with the first.
        this.busy = false;

        this.canvas = root.querySelector('[data-region="canvas"]');
        this.stage = root.querySelector('[data-region="stage"]');
        this.overlay = root.querySelector('[data-region="overlay"]');
        this.layers = root.querySelector('[data-region="layers"]');
        this.inspector = root.querySelector('[data-region="inspector"]');
        this.statusbar = root.querySelector('[data-region="status"]');
        this.saveIndicator = root.querySelector('[data-region="saveindicator"]');
        this.liveregion = root.querySelector('[data-region="live"]');
        this.elementhost = root.querySelector('[data-region="elements"]');

        this.init();
    };

    /**
     * Wire everything up and draw the first page.
     *
     * @return {void}
     */
    Designer.prototype.init = function () {
        var self = this;

        Str.get_strings([
            {key: 'designer_snapped', component: 'mod_certificatepro'},
            {key: 'designer_saved', component: 'mod_certificatepro'},
            {key: 'designer_saving', component: 'mod_certificatepro'},
            {key: 'designer_savefailed', component: 'mod_certificatepro'},
            {key: 'designer_selected', component: 'mod_certificatepro'},
            {key: 'designer_free', component: 'mod_certificatepro'},
            {key: 'designer_announceselectedcount', component: 'mod_certificatepro', param: '{$a}'}
        ]).then(function (strings) {
            self.strings = {
                snapped: strings[0],
                saved: strings[1],
                saving: strings[2],
                savefailed: strings[3],
                selected: strings[4],
                free: strings[5],
                selectedcount: strings[6]
            };
            return strings;
        }).catch(Notification.exception);

        this.bindToolbar();
        this.bindToolbarRoving();
        this.bindCanvas();
        this.bindKeyboard();
        this.bindPicker();
        this.render();
        this.syncToolbar();
    };

    /**
     * The page currently being edited.
     *
     * @return {Object} the page record
     */
    Designer.prototype.page = function () {
        return this.template.pages[this.pageIndex];
    };

    /**
     * How many screen pixels represent one millimetre at the current zoom.
     *
     * @return {number} pixels per millimetre
     */
    Designer.prototype.scale = function () {
        var page = this.page();
        if (!page || !this.canvas) {
            return 3;
        }
        var available = this.canvas.clientWidth - 48;
        return (available / page.width) * this.zoom;
    };

    /**
     * Convert millimetres to pixels.
     *
     * @param {number} mm the value in millimetres
     * @return {number} the value in pixels
     */
    Designer.prototype.px = function (mm) {
        return mm * this.scale();
    };

    /**
     * Convert pixels to millimetres.
     *
     * @param {number} px the value in pixels
     * @return {number} the value in millimetres
     */
    Designer.prototype.mm = function (px) {
        return px / this.scale();
    };

    /**
     * Round a millimetre value to the precision the database stores.
     *
     * @param {number} value the value to round
     * @return {number} the rounded value
     */
    Designer.prototype.round = function (value) {
        return Math.round(value * 1000) / 1000;
    };

    // ------------------------------------------------------------------ focus.

    /**
     * A description of what has the keyboard, good enough to find it again.
     *
     * The three panels that are drawn from the model — the elements, the layers
     * and the page strip — are rebuilt by replacing their markup, which throws
     * away whichever node inside them had focus. The browser then drops focus to
     * BODY, and since every shortcut is bound on the designer root, the designer
     * goes deaf: selecting an element from the layers panel and then pressing an
     * arrow key moved nothing at all.
     *
     * A node is described rather than held, because the node itself does not
     * survive the redraw. What survives is the identity written on it: which
     * element it draws, or which action and value it carries.
     *
     * @return {string|null} a selector for the focused node, or null when focus
     *                       is not inside the designer
     */
    Designer.prototype.focusKey = function () {
        var node = document.activeElement;

        if (!node || node === this.root || !this.root.contains(node)) {
            return null;
        }

        if (node.classList && node.classList.contains('cpro-element')) {
            return '.cpro-element[data-element-id="'
                + node.getAttribute('data-element-id') + '"]';
        }

        var action = node.getAttribute('data-action');
        if (action) {
            var value = node.getAttribute('data-value');

            return '[data-action="' + action + '"]'
                + (value === null ? '' : '[data-value="' + value + '"]');
        }

        if (node.id) {
            return '#' + node.id;
        }

        var region = node.getAttribute('data-region');

        return region ? '[data-region="' + region + '"]' : null;
    };

    /**
     * Put the keyboard back where it was before a redraw.
     *
     * @param {string|null} key the description from {@see Designer#focusKey}
     * @return {void}
     */
    Designer.prototype.restoreFocus = function (key) {
        if (!key) {
            return;
        }

        var node = this.root.querySelector(key);

        if (node && !node.disabled) {
            if (node !== document.activeElement) {
                node.focus({preventScroll: true});
            }
            return;
        }

        // What had focus is gone for good — an element that was just deleted,
        // a page tab that was just removed. Focus has to land somewhere inside
        // the designer all the same, or the shortcuts stop answering.
        this.focusFallback();
    };

    /**
     * Redraw something without letting the keyboard fall out of the designer.
     *
     * @param {Function} draw the redraw to run
     * @return {void}
     */
    Designer.prototype.preservingFocus = function (draw) {
        var key = this.focusKey();

        draw.call(this);

        if (key) {
            this.restoreFocus(key);
        }
    };

    /**
     * Where the keyboard goes when what held it no longer exists.
     *
     * The canvas rather than the root: it is a named region with a visible
     * focus ring, so an author can see where they have landed, and it is inside
     * the root, so every shortcut still reaches its listener.
     *
     * @return {void}
     */
    Designer.prototype.focusFallback = function () {
        var target = this.selection.length ? this.nodeFor(this.selection[0]) : null;

        (target || this.canvas || this.root).focus({preventScroll: true});
    };

    /**
     * Take the keyboard back when something has dropped it outside the designer.
     *
     * A Moodle dialogue returns focus to the button that opened it, and that
     * button is sometimes gone by the time the dialogue closes — the page tab
     * that was just deleted, for one. The browser then leaves focus on BODY.
     *
     * @return {void}
     */
    Designer.prototype.ensureFocus = function () {
        var node = document.activeElement;

        if (!node || node === document.body || !document.body.contains(node)) {
            this.focusFallback();
        }
    };

    // ---------------------------------------------------------------- drawing.

    /**
     * Draw the whole designer from the current model.
     *
     * @return {void}
     */
    Designer.prototype.render = function () {
        this.renderPageTabs();
        this.renderStage();
        this.renderElements();
        this.renderLayers();
        this.renderInspector();
    };

    /**
     * Size the page and draw its margins.
     *
     * @return {void}
     */
    Designer.prototype.renderStage = function () {
        var page = this.page();
        if (!page || !this.stage) {
            return;
        }

        this.stage.style.width = this.px(page.width) + 'px';
        this.stage.style.height = this.px(page.height) + 'px';
        this.stage.style.background = page.backgroundcolour || '#ffffff';
        this.stage.setAttribute('data-orientation', page.orientation);

        var margins = this.stage.querySelector('[data-region="margins"]');
        if (margins) {
            margins.style.left = this.px(page.margins.left) + 'px';
            margins.style.top = this.px(page.margins.top) + 'px';
            margins.style.width = this.px(page.width - page.margins.left - page.margins.right) + 'px';
            margins.style.height = this.px(page.height - page.margins.top - page.margins.bottom) + 'px';
        }
    };

    /**
     * Draw every element on the current page.
     *
     * @return {void}
     */
    Designer.prototype.renderElements = function () {
        var self = this;
        var page = this.page();
        var host = this.stage.querySelector('[data-region="elements"]');

        if (!page || !host) {
            return;
        }

        this.preservingFocus(function () {
            host.innerHTML = '';

            page.elements.forEach(function (element) {
                host.appendChild(self.buildElementNode(element));
            });

            self.refreshSelectionVisuals();
        });
    };

    /**
     * Build the node for one element.
     *
     * @param {Object} element the element record
     * @return {HTMLElement} the node
     */
    Designer.prototype.buildElementNode = function (element) {
        var box = this.boxFor(element);
        var node = document.createElement('div');

        node.className = 'cpro-element';
        node.setAttribute('data-element-id', element.id);
        node.setAttribute('data-type', element.type);
        node.setAttribute('aria-label', element.name);

        // An option inside the listbox the elements host declares, rather than a
        // button. A button has no selected state to report, and the selection is
        // the whole point of this canvas: aria-selected is what tells a screen
        // reader which of these the toolbar and the inspector are talking about.
        node.setAttribute('role', 'option');
        node.setAttribute('aria-selected',
            this.selection.indexOf(element.id) === -1 ? 'false' : 'true');

        // A locked element does not answer the pointer, so it must not be a tab
        // stop either: it would be a stop that cannot be acted on, and there are
        // enough of those on this canvas already. It stays in the layers panel,
        // which is where the control that unlocks it is.
        node.setAttribute('tabindex', element.locked ? '-1' : '0');
        if (element.locked) {
            node.setAttribute('aria-disabled', 'true');
        }

        node.style.left = this.px(box.left) + 'px';
        node.style.top = this.px(box.top) + 'px';
        node.style.width = this.px(box.width) + 'px';
        node.style.height = this.px(box.height) + 'px';
        node.style.zIndex = String(100 + (element.zindex || 0));

        if (element.rotation) {
            node.style.transform = 'rotate(' + element.rotation + 'deg)';
        }
        if (element.hidden) {
            node.classList.add('cpro-hidden');
        }
        if (element.locked) {
            node.classList.add('cpro-locked');
            // A locked element must not take the pointer: a locked full page
            // border or artwork sits over the whole design and made everything
            // under it unclickable. styles.css should carry this rule.
            node.style.pointerEvents = 'none';
        }

        var content = document.createElement('div');
        content.className = 'cpro-element-content';
        content.innerHTML = element.html || '';
        node.appendChild(content);

        // The markup was drawn on the server for whatever box the element had
        // then, which is usually the box it has now — but not after a resize
        // whose fresh markup has not landed yet, and not after an undo. Asking
        // here as well as during the drag is what makes a redraw of the whole
        // stage, such as a zoom change, agree with the drag that preceded it.
        this.rescaleDrawing(element, node);

        // An element that cannot be resized gets no resize handles to pull. The
        // class carries the same fact to the stylesheet, so a theme or a rule
        // that wants to mark such an element has something to hang on, but the
        // handles are left unbuilt rather than merely hidden: a handle that is
        // in the markup is a handle a pointer can still find.
        if (!this.isResizable(element)) {
            node.classList.add('cpro-noresize');
        } else {
            ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(function (position) {
                var handle = document.createElement('span');
                handle.className = 'cpro-handle cpro-handle-' + position;
                handle.setAttribute('data-handle', position);
                handle.setAttribute('aria-hidden', 'true');
                node.appendChild(handle);
            });
        }

        var rotate = document.createElement('span');
        rotate.className = 'cpro-handle cpro-handle-rotate';
        rotate.setAttribute('data-handle', 'rotate');
        rotate.setAttribute('aria-hidden', 'true');
        node.appendChild(rotate);

        return node;
    };

    /**
     * Resize the drawing inside an element to match the box it is now in.
     *
     * An element's markup is drawn on the server, and part of what it contains
     * is a length worked out from the box the element had at that moment: the
     * side of a seal's square, the point size a long name was shrunk to so it
     * would fit. Dragging a handle restyles the outer node alone, so without
     * this the drawing keeps the size it was drawn at and the canvas stops
     * showing what will print until fresh markup arrives from the server.
     *
     * Fresh markup is the authority and it does arrive — the autosave brings it
     * back — but it is debounced, so it cannot keep up with a drag. This closes
     * that gap. It knows nothing about seals or about typography: the element
     * describes how its drawing relates to its box, in the fit descriptor the
     * server exports alongside the markup, and this only applies the rule it is
     * given. Anything the server does not describe is left alone.
     *
     * The original lengths are remembered on the node rather than multiplied in
     * place, so a hundred pointer moves during one drag all scale from the same
     * starting point and dragging back to where the box began puts the drawing
     * back exactly, with no accumulated rounding.
     *
     * @param {Object} element the element record
     * @param {HTMLElement} [node] the element's node, when it is not on the stage yet
     * @return {void}
     */
    Designer.prototype.rescaleDrawing = function (element, node) {
        var fit = element.canvasfit;

        if (!fit || !fit.mode || fit.mode === 'none') {
            return;
        }

        var host = node || this.nodeFor(element.id);
        var content = host ? host.querySelector('.cpro-element-content') : null;
        var drawing = content ? content.firstElementChild : null;

        if (!drawing) {
            return;
        }

        var wasWidth = fit.boxwidth;
        var wasHeight = fit.boxheight;
        var width = element.width;
        var height = element.height;

        // A box with no size of its own is a fit to content box, and the drawing
        // in it was never sized from the box in the first place.
        if (!(wasWidth > 0 && wasHeight > 0 && width > 0 && height > 0)) {
            return;
        }

        if (fit.mode === 'square') {
            // The drawing is the largest square the box will hold, so it follows
            // the shorter side. Both sides are written because the markup states
            // both, and a square whose height did not follow its width would be
            // squashed rather than resized.
            var side = Math.min(width, height) / Math.min(wasWidth, wasHeight);
            this.scaleDeclared(drawing, 'width', side);
            this.scaleDeclared(drawing, 'height', side);
        } else if (fit.mode === 'box') {
            this.scaleDeclared(drawing, 'width', width / wasWidth);
            this.scaleDeclared(drawing, 'height', height / wasHeight);
        } else if (fit.mode === 'type') {
            // Type shrunk to fit a box follows the area rather than either side:
            // the words wrap into about width/size lines of about size tall, so
            // the size that just fills the box goes with the square root of the
            // area. Widening a box alone therefore lets the type up a little,
            // which is what re-fitting it on the server does too. The two ends
            // of the server's own shrink to fit come with the descriptor, so a
            // box grown large cannot push the type past the size the author
            // asked for and a box shrunk small cannot push it below the floor.
            this.scaleDeclared(drawing, 'fontSize',
                Math.sqrt((width * height) / (wasWidth * wasHeight)),
                fit.minsize, fit.maxsize);
        }
    };

    /**
     * Split a CSS length into its number and its unit.
     *
     * @param {string} text the length to read
     * @return {Object|null} value and unit, or null when it is not a plain length
     */
    Designer.prototype.parseLength = function (text) {
        var parts = /^\s*(-?\d*\.?\d+)([a-z%]*)\s*$/i.exec(String(text === null
            || text === undefined ? '' : text));

        return parts ? {value: parseFloat(parts[1]), unit: parts[2]} : null;
    };

    /**
     * Multiply one length in a node's own style attribute, keeping its unit.
     *
     * The unit is never converted, only carried, which is what keeps this honest
     * about zoom: the server states these lengths as a fraction of the page, so
     * a scaled value goes on tracking the zoom exactly as the original did.
     *
     * @param {HTMLElement} node the node whose style is being changed
     * @param {string} property the CSS property, in its DOM spelling
     * @param {number} factor what to multiply the original value by
     * @param {string} [floor] a length the result may not go below
     * @param {string} [ceiling] a length the result may not go above
     * @return {void}
     */
    Designer.prototype.scaleDeclared = function (node, property, factor, floor, ceiling) {
        var attribute = 'data-cpro-declared-' + property.toLowerCase();
        var declared = node.getAttribute(attribute);

        if (declared === null) {
            declared = node.style[property];
            // The property this element's markup does not state is a property
            // this must not invent: a placeholder standing in for a text
            // element with nothing to say has no point size of its own.
            if (!declared) {
                return;
            }
            node.setAttribute(attribute, declared);
        }

        var length = this.parseLength(declared);
        if (!length) {
            return;
        }

        var value = length.value * factor;

        // A limit stated in some other unit cannot be compared with this one
        // without knowing the page, so it is ignored rather than guessed at.
        var limit = this.parseLength(floor);
        if (limit && limit.unit === length.unit) {
            value = Math.max(limit.value, value);
        }
        limit = this.parseLength(ceiling);
        if (limit && limit.unit === length.unit) {
            value = Math.min(limit.value, value);
        }

        node.style[property] = (Math.round(value * 10000) / 10000) + length.unit;
    };

    /**
     * The top left corner and size of an element, in millimetres.
     *
     * @param {Object} element the element record
     * @return {Object} left, top, width and height in millimetres
     */
    Designer.prototype.boxFor = function (element) {
        var width = element.width > 0 ? element.width : 40;
        var height = element.height > 0 ? element.height : 10;
        var refpoint = element.refpoint || 'tl';
        var vertical = refpoint.charAt(0);
        var horizontal = refpoint.charAt(1);
        var left = element.posx;
        var top = element.posy;

        if (horizontal === 'c') {
            left = element.posx - (width / 2);
        } else if (horizontal === 'r') {
            left = element.posx - width;
        }

        if (vertical === 'm') {
            top = element.posy - (height / 2);
        } else if (vertical === 'b') {
            top = element.posy - height;
        }

        return {left: left, top: top, width: width, height: height};
    };

    /**
     * The anchored position for a box, given the element's reference point.
     *
     * @param {Object} element the element record
     * @param {Object} box left, top, width and height in millimetres
     * @return {Object} posx and posy in millimetres
     */
    Designer.prototype.anchorFor = function (element, box) {
        var refpoint = element.refpoint || 'tl';
        var vertical = refpoint.charAt(0);
        var horizontal = refpoint.charAt(1);
        var posx = box.left;
        var posy = box.top;

        if (horizontal === 'c') {
            posx = box.left + (box.width / 2);
        } else if (horizontal === 'r') {
            posx = box.left + box.width;
        }

        if (vertical === 'm') {
            posy = box.top + (box.height / 2);
        } else if (vertical === 'b') {
            posy = box.top + box.height;
        }

        return {posx: this.round(posx), posy: this.round(posy)};
    };

    // ------------------------------------------------------- zones and guides.

    /**
     * The zones that would accept an element, sorted by how close they are.
     *
     * @param {Object} element the element being moved
     * @param {Object} box its current box in millimetres
     * @return {Array} candidate zones, each with a distance
     */
    Designer.prototype.candidateZones = function (element, box) {
        var page = this.page();
        var centreX = box.left + (box.width / 2);
        var centreY = box.top + (box.height / 2);
        var candidates = [];

        page.zones.forEach(function (zone) {
            if (!zone.snappable) {
                return;
            }
            if (zone.accepts.indexOf(element.type) === -1) {
                return;
            }

            var zoneCentreX = zone.x + (zone.w / 2);
            var zoneCentreY = zone.y + (zone.h / 2);
            var dx = centreX - zoneCentreX;
            var dy = centreY - zoneCentreY;
            var distance = Math.sqrt((dx * dx) + (dy * dy));

            // Priority pulls a little harder, so the obvious home wins ties.
            var effective = distance - (zone.priority * 0.6);

            if (distance <= ZONE_REVEAL) {
                candidates.push({zone: zone, distance: distance, effective: effective});
            }
        });

        candidates.sort(function (a, b) {
            return a.effective - b.effective;
        });

        return candidates;
    };

    /**
     * Draw the dotted rectangles for whichever zones are currently in range.
     *
     * @param {Array} candidates zones returned by candidateZones
     * @param {Object|null} active the zone that would capture on release
     * @return {void}
     */
    Designer.prototype.paintZones = function (candidates, active) {
        var self = this;

        this.clearLayer('zones');

        candidates.forEach(function (candidate) {
            var zone = candidate.zone;
            var node = document.createElement('div');
            var isActive = active && active.id === zone.id;
            var proximity = Math.max(0, 1 - (candidate.distance / ZONE_REVEAL));

            node.className = 'cpro-zone' + (isActive ? ' cpro-zone-active' : '');
            node.style.left = self.px(zone.x) + 'px';
            node.style.top = self.px(zone.y) + 'px';
            node.style.width = self.px(zone.w) + 'px';
            node.style.height = self.px(zone.h) + 'px';
            node.style.opacity = String(isActive ? 1 : (0.25 + (proximity * 0.5)));

            var label = document.createElement('span');
            label.className = 'cpro-zone-label';
            label.textContent = zone.label;
            node.appendChild(label);

            self.layer('zones').appendChild(node);
        });
    };

    /**
     * Work out where a moving box wants to snap to, and which guides to show.
     *
     * @param {Object} element the element being moved
     * @param {Object} box its proposed box in millimetres
     * @return {Object} the adjusted box plus the guides that caused the adjustment
     */
    Designer.prototype.applyGuides = function (element, box) {
        var page = this.page();
        var result = {box: {left: box.left, top: box.top, width: box.width, height: box.height},
            vertical: [], horizontal: []};

        var verticals = [];
        var horizontals = [];

        page.guides.vertical.forEach(function (guide) {
            verticals.push({pos: guide.pos, type: guide.type, strength: guide.strength});
        });
        page.guides.horizontal.forEach(function (guide) {
            horizontals.push({pos: guide.pos, type: guide.type, strength: guide.strength});
        });

        // Every other element's edges and centres are guides too.
        var self = this;
        page.elements.forEach(function (other) {
            if (other.id === element.id || self.selection.indexOf(other.id) !== -1) {
                return;
            }
            var otherBox = self.boxFor(other);
            verticals.push({pos: otherBox.left, type: 'element', strength: 5});
            verticals.push({pos: otherBox.left + (otherBox.width / 2), type: 'element', strength: 6});
            verticals.push({pos: otherBox.left + otherBox.width, type: 'element', strength: 5});
            horizontals.push({pos: otherBox.top, type: 'element', strength: 5});
            horizontals.push({pos: otherBox.top + (otherBox.height / 2), type: 'element', strength: 6});
            horizontals.push({pos: otherBox.top + otherBox.height, type: 'element', strength: 5});
        });

        var candidatesX = [
            {value: result.box.left, offset: 0},
            {value: result.box.left + (result.box.width / 2), offset: result.box.width / 2},
            {value: result.box.left + result.box.width, offset: result.box.width}
        ];
        var candidatesY = [
            {value: result.box.top, offset: 0},
            {value: result.box.top + (result.box.height / 2), offset: result.box.height / 2},
            {value: result.box.top + result.box.height, offset: result.box.height}
        ];

        var bestX = null;
        candidatesX.forEach(function (candidate) {
            verticals.forEach(function (guide) {
                var delta = Math.abs(candidate.value - guide.pos);
                if (delta > GUIDE_THRESHOLD) {
                    return;
                }
                var score = delta - (guide.strength * 0.05);
                if (bestX === null || score < bestX.score) {
                    bestX = {score: score, pos: guide.pos, offset: candidate.offset, type: guide.type};
                }
            });
        });

        var bestY = null;
        candidatesY.forEach(function (candidate) {
            horizontals.forEach(function (guide) {
                var delta = Math.abs(candidate.value - guide.pos);
                if (delta > GUIDE_THRESHOLD) {
                    return;
                }
                var score = delta - (guide.strength * 0.05);
                if (bestY === null || score < bestY.score) {
                    bestY = {score: score, pos: guide.pos, offset: candidate.offset, type: guide.type};
                }
            });
        });

        if (bestX !== null) {
            result.box.left = bestX.pos - bestX.offset;
            result.vertical.push(bestX);
        }
        if (bestY !== null) {
            result.box.top = bestY.pos - bestY.offset;
            result.horizontal.push(bestY);
        }

        return result;
    };

    /**
     * Draw the alignment guide lines.
     *
     * @param {Array} vertical vertical guides that are active
     * @param {Array} horizontal horizontal guides that are active
     * @return {void}
     */
    Designer.prototype.paintGuides = function (vertical, horizontal) {
        var self = this;

        this.clearLayer('guides');

        vertical.forEach(function (guide) {
            var node = document.createElement('div');
            node.className = 'cpro-guide cpro-guide-v cpro-guide-' + guide.type;
            node.style.left = self.px(guide.pos) + 'px';
            self.layer('guides').appendChild(node);
        });

        horizontal.forEach(function (guide) {
            var node = document.createElement('div');
            node.className = 'cpro-guide cpro-guide-h cpro-guide-' + guide.type;
            node.style.top = self.px(guide.pos) + 'px';
            self.layer('guides').appendChild(node);
        });
    };

    /**
     * The overlay layer with the given name, created on demand.
     *
     * @param {string} name the layer name
     * @return {HTMLElement} the layer node
     */
    Designer.prototype.layer = function (name) {
        var node = this.overlay.querySelector('[data-layer="' + name + '"]');
        if (!node) {
            node = document.createElement('div');
            node.className = 'cpro-layer';
            node.setAttribute('data-layer', name);
            this.overlay.appendChild(node);
        }
        return node;
    };

    /**
     * Empty an overlay layer.
     *
     * @param {string} name the layer name
     * @return {void}
     */
    Designer.prototype.clearLayer = function (name) {
        this.layer(name).innerHTML = '';
    };

    // ------------------------------------------------------------- interaction.

    /**
     * Listen for pointer activity on the canvas.
     *
     * @return {void}
     */
    Designer.prototype.bindCanvas = function () {
        var self = this;

        // The canvas, not the stage: clicking the paper's surround has to give
        // the designer the keyboard too, or the shortcuts stay dead there.
        this.canvas.addEventListener('pointerdown', function (event) {
            self.takeFocus(event);
        });

        this.stage.addEventListener('pointerdown', function (event) {
            var handle = event.target.closest('[data-handle]');
            var node = event.target.closest('.cpro-element');
            var id = node ? parseInt(node.getAttribute('data-element-id'), 10) : 0;
            var element = node ? self.elementById(id) : null;

            // A locked element is not there as far as the pointer is concerned,
            // so this falls through to the empty stage rather than returning: a
            // locked full page border must not make the whole design unclickable.
            if (!element || element.locked) {
                self.beginMarquee(event);
                event.preventDefault();
                return;
            }

            if (event.shiftKey) {
                self.toggleSelection(id);
            } else if (self.selection.indexOf(id) === -1) {
                self.select([id]);
            }

            self.beginDrag(event, element, handle ? handle.getAttribute('data-handle') : 'move');
            event.preventDefault();
        });

        window.addEventListener('pointermove', function (event) {
            self.continueDrag(event);
            self.continueMarquee(event);
        });

        window.addEventListener('pointerup', function (event) {
            self.endDrag(event);
            self.endMarquee(event);
        });

        window.addEventListener('resize', function () {
            self.render();
        });
    };

    /**
     * Give the designer the keyboard.
     *
     * The canvas cancels the pointerdown so that a drag cannot start by
     * selecting text, and cancelling it also cancels the focus the browser
     * would have moved. Nothing else focuses the root, so every shortcut used
     * to stay dead until the author happened to Tab into the designer.
     *
     * @param {Event} event the event asking for focus
     * @return {void}
     */
    Designer.prototype.takeFocus = function (event) {
        var target = event ? event.target : null;

        // A field in the sidebar and a dialog over the designer each own the
        // keyboard while they have it, and taking it away mid-word loses what
        // is being typed.
        if (target && target.closest && target.closest(
                'input, textarea, select, [contenteditable="true"], .modal, [role="dialog"]')) {
            return;
        }

        if (document.body.classList.contains('modal-open')) {
            return;
        }

        this.root.focus({preventScroll: true});
    };

    /**
     * Start a move, resize or rotate.
     *
     * @param {PointerEvent} event the pointer event
     * @param {Object} element the element under the pointer
     * @param {string} mode move, rotate or a resize handle name
     * @return {void}
     */
    Designer.prototype.beginDrag = function (event, element, mode) {
        var self = this;
        var rect = this.stage.getBoundingClientRect();

        // Nothing builds a resize handle for an element that cannot be resized,
        // so this only catches a handle left behind by a stale render or one
        // reached some other way. Falling back to a move is kinder than
        // silently doing nothing: the pointer is already down and the author is
        // already dragging.
        if (mode !== 'move' && mode !== 'rotate' && !this.isResizable(element)) {
            mode = 'move';
        }

        this.drag = {
            mode: mode,
            element: element,
            startX: event.clientX,
            startY: event.clientY,
            stageRect: rect,
            // Nothing has moved yet, so there is nothing to record and nothing
            // to save: the history snapshot waits for the first real movement.
            changed: false,
            origins: {}
        };

        this.selection.forEach(function (id) {
            var selected = self.elementById(id);
            if (selected) {
                self.drag.origins[id] = self.boxFor(selected);
            }
        });

        if (!this.drag.origins[element.id]) {
            this.drag.origins[element.id] = this.boxFor(element);
        }

        this.root.classList.add('cpro-dragging');
    };

    /**
     * Update the drag in progress.
     *
     * @param {PointerEvent} event the pointer event
     * @return {void}
     */
    Designer.prototype.continueDrag = function (event) {
        if (!this.drag) {
            return;
        }

        var self = this;
        var dx = this.mm(event.clientX - this.drag.startX);
        var dy = this.mm(event.clientY - this.drag.startY);
        var element = this.drag.element;
        var origin = this.drag.origins[element.id];
        var travelled = Math.abs(event.clientX - this.drag.startX)
            + Math.abs(event.clientY - this.drag.startY);

        // A click is a pointerdown and a pointerup with a pixel of hand shake in
        // between. Treating that as a change filled the undo stack with entries
        // identical to the one before, so the first few undos did nothing at all.
        if (!this.drag.changed) {
            if (travelled <= DRAG_THRESHOLD) {
                return;
            }
            this.pushHistory();
            this.drag.changed = true;
        }

        if (this.drag.mode === 'rotate') {
            this.applyRotation(event, element, origin);
            return;
        }

        var proposed;

        if (this.drag.mode === 'move') {
            proposed = {
                left: origin.left + dx,
                top: origin.top + dy,
                width: origin.width,
                height: origin.height
            };
        } else {
            proposed = this.resizeBox(origin, this.drag.mode, dx, dy, event.shiftKey,
                this.keepsSquare(element));
        }

        // Holding Alt is the momentary escape from snapping; the toolbar toggle
        // is the standing one. Either way the element follows the pointer exactly.
        var freehand = event.altKey || !this.snap;

        var guided = freehand ? {box: proposed, vertical: [], horizontal: []}
            : this.applyGuides(element, proposed);

        // The pointer can travel a long way past the paper, and an element left
        // out there is unreachable: it draws outside the stage, so a second drag
        // has nothing to take hold of. Zones are worked out from the clamped box
        // so the highlight agrees with where the element actually is.
        if (this.drag.mode === 'move') {
            guided.box = this.clampToPage(guided.box);
        }

        var candidates = this.drag.mode === 'move' && !freehand
            ? this.candidateZones(element, guided.box)
            : [];

        var active = null;
        if (candidates.length && candidates[0].distance <= ZONE_CAPTURE) {
            active = candidates[0].zone;
        }

        this.paintZones(candidates, active);
        this.paintGuides(guided.vertical, guided.horizontal);

        this.drag.result = {box: guided.box, zone: active};

        // Move every selected element by the same amount.
        var deltaX = guided.box.left - origin.left;
        var deltaY = guided.box.top - origin.top;

        this.selection.forEach(function (id) {
            var selected = self.elementById(id);
            var selectedOrigin = self.drag.origins[id];
            if (!selected || !selectedOrigin) {
                return;
            }

            var box;
            if (id === element.id) {
                box = guided.box;
            } else if (self.drag.mode === 'move') {
                // Every element in a multiple selection is clamped in its own
                // right. The formation only breaks at the very edge of the
                // paper, which is the price of never losing one over the side.
                box = self.clampToPage({
                    left: selectedOrigin.left + deltaX,
                    top: selectedOrigin.top + deltaY,
                    width: selectedOrigin.width,
                    height: selectedOrigin.height
                });
            } else {
                return;
            }

            self.applyBox(selected, box);
        });

        this.updateStatus(active);
    };

    /**
     * Work out the new box for a resize handle drag.
     *
     * @param {Object} origin the box when the drag started
     * @param {string} handle the handle being dragged
     * @param {number} dx the horizontal movement in millimetres
     * @param {number} dy the vertical movement in millimetres
     * @param {boolean} keepRatio whether to preserve the aspect ratio
     * @param {boolean} keepSquare whether the element must stay square
     * @return {Object} the proposed box
     */
    Designer.prototype.resizeBox = function (origin, handle, dx, dy, keepRatio, keepSquare) {
        var box = {
            left: origin.left,
            top: origin.top,
            width: origin.width,
            height: origin.height
        };
        var west = handle.indexOf('w') !== -1;
        var north = handle.indexOf('n') !== -1;

        if (west) {
            box.left = origin.left + dx;
            box.width = origin.width - dx;
        }
        if (handle.indexOf('e') !== -1) {
            box.width = origin.width + dx;
        }
        if (north) {
            box.top = origin.top + dy;
            box.height = origin.height - dy;
        }
        if (handle.indexOf('s') !== -1) {
            box.height = origin.height + dy;
        }

        box.width = Math.max(3, box.width);
        box.height = Math.max(3, box.height);

        // A square element is square whichever handle is pulled, including the
        // side handles that only touch one axis: dragging a seal's east handle
        // used to widen it into an ellipse the print would then draw as a
        // circle again, so what the author saw and what they got disagreed. The
        // side the pointer moved along the most is the side that leads, and the
        // corner or edge the author took hold of stays where it was put.
        if (keepSquare) {
            var acrossX = west || handle.indexOf('e') !== -1;
            var acrossY = north || handle.indexOf('s') !== -1;
            var size;

            if (acrossX && acrossY) {
                // A corner is pulled in both directions at once, so the axis the
                // pointer travelled furthest along is the one that leads.
                size = Math.abs(dx) > Math.abs(dy) ? box.width : box.height;
            } else if (acrossX) {
                size = box.width;
            } else {
                size = box.height;
            }

            box.width = size;
            box.height = size;

            if (west) {
                box.left = origin.left + origin.width - size;
            }
            if (north) {
                box.top = origin.top + origin.height - size;
            }

            return box;
        }

        if (keepRatio && origin.width > 0 && origin.height > 0) {
            var ratio = origin.width / origin.height;
            if (Math.abs(dx) > Math.abs(dy)) {
                box.height = box.width / ratio;
            } else {
                box.width = box.height * ratio;
            }
        }

        return box;
    };

    /**
     * Rotate an element to follow the pointer.
     *
     * @param {PointerEvent} event the pointer event
     * @param {Object} element the element being rotated
     * @param {Object} origin the box when the drag started
     * @return {void}
     */
    Designer.prototype.applyRotation = function (event, element, origin) {
        var rect = this.drag.stageRect;
        var centreX = rect.left + this.px(origin.left + (origin.width / 2));
        var centreY = rect.top + this.px(origin.top + (origin.height / 2));
        var angle = Math.atan2(event.clientY - centreY, event.clientX - centreX);
        var degrees = (angle * 180 / Math.PI) + 90;

        // Snapping is the default, and the step is a quarter turn, because
        // that is what a certificate actually needs: upright, or a vertical
        // side band, or upside down. A free drag lands on values like -0.56
        // degrees, which reads as a mistake rather than a design. Hold Shift
        // for a fine 5 degree step when a deliberate tilt is wanted.
        degrees = event.shiftKey
            ? Math.round(degrees / 5) * 5
            : Math.round(degrees / 90) * 90;

        // Keep it in 0-359 so the inspector never shows -90 or 450.
        degrees = ((degrees % 360) + 360) % 360;

        element.rotation = this.round(degrees);

        var node = this.nodeFor(element.id);
        if (node) {
            node.style.transform = 'rotate(' + element.rotation + 'deg)';
        }

        this.drag.result = {rotation: element.rotation};
    };

    /**
     * Write a box back onto an element and its node.
     *
     * @param {Object} element the element to update
     * @param {Object} box the new box in millimetres
     * @return {void}
     */
    Designer.prototype.applyBox = function (element, box) {
        var anchor = this.anchorFor(element, box);

        element.posx = anchor.posx;
        element.posy = anchor.posy;
        element.width = this.round(box.width);
        element.height = this.round(box.height);

        var node = this.nodeFor(element.id);
        if (!node) {
            return;
        }

        node.style.left = this.px(box.left) + 'px';
        node.style.top = this.px(box.top) + 'px';
        node.style.width = this.px(box.width) + 'px';
        node.style.height = this.px(box.height) + 'px';

        // Every way an element's box can change goes through here, so this is
        // the one place the drawing inside it has to be told about. Waiting for
        // the autosave instead left the artwork visibly lagging the box for the
        // length of the drag and for the debounce after it.
        this.rescaleDrawing(element, node);
    };

    /**
     * Finish a drag, docking into a zone if one captured the element.
     *
     * @return {void}
     */
    Designer.prototype.endDrag = function () {
        if (!this.drag) {
            return;
        }

        var element = this.drag.element;
        var result = this.drag.result;
        var changed = this.drag.changed;

        if (changed && result && result.zone) {
            this.dockIntoZone(element, result.zone);
        } else if (changed && result && result.box) {
            element.zoneid = null;
        }

        this.clearLayer('zones');
        this.clearLayer('guides');
        this.root.classList.remove('cpro-dragging');
        this.drag = null;

        // A click that selected something and moved nothing has nothing to
        // write, and saving anyway told the author their design had changed
        // when it had not.
        if (!changed) {
            return;
        }

        this.renderInspector();
        this.queueSave();
    };

    /**
     * Keep a box within reach of the page.
     *
     * Bleeding an element off an edge is a real design choice, so this does not
     * insist that the whole of it stays on the paper; it insists only that
     * enough is left on the paper to take hold of again. Without it an element
     * could be dragged to a position the stage does not draw, and no second
     * drag could ever bring it back.
     *
     * @param {Object} box the proposed box in millimetres
     * @return {Object} the same box, moved back onto the page if it had left it
     */
    Designer.prototype.clampToPage = function (box) {
        var page = this.page();

        if (!page) {
            return box;
        }

        // An element smaller than the margin has to stay wholly on the page,
        // otherwise the strip that must remain would be bigger than the element.
        var keepX = Math.min(KEEP_ON_PAGE, box.width);
        var keepY = Math.min(KEEP_ON_PAGE, box.height);

        box.left = Math.max(keepX - box.width, Math.min(page.width - keepX, box.left));
        box.top = Math.max(keepY - box.height, Math.min(page.height - keepY, box.top));

        return box;
    };

    /**
     * Whether an element is drawn square and has to stay square.
     *
     * The element itself is the only thing that knows how it is drawn, so the
     * answer comes from the flag it exports rather than from a list of type
     * names kept here. A list here goes stale the moment a new square element
     * type is added, and it goes stale silently: the new type simply starts
     * stretching.
     *
     * @param {Object} element the element record
     * @return {boolean} true when the element must keep a square aspect ratio
     */
    Designer.prototype.keepsSquare = function (element) {
        return !!element.keepsquare;
    };

    /**
     * Whether the author may resize an element's box at all.
     *
     * A page border and a generated artwork panel are drawn from the page's own
     * dimensions and ignore their box entirely, so a box of any other size is a
     * promise the print will not keep. Anything missing the flag is treated as
     * resizable, so an element exported by older code still behaves.
     *
     * @param {Object} element the element record
     * @return {boolean} true when the element may be resized
     */
    Designer.prototype.isResizable = function (element) {
        return element.resizable !== false;
    };

    /**
     * Snap an element into a zone, adopting its anchoring and alignment.
     *
     * @param {Object} element the element to dock
     * @param {Object} zone the zone to dock into
     * @return {void}
     */
    Designer.prototype.dockIntoZone = function (element, zone) {
        var width = Math.min(element.width > 0 ? element.width : zone.w, zone.w);
        var height = Math.min(element.height > 0 ? element.height : zone.h, zone.h);

        // Fitting the two sides separately to a zone that is not itself square
        // flattens a seal a little more every time it is docked, and the loss is
        // never given back: a 34mm seal came out 34 by 26.25 the first time and
        // smaller again the next. One side for both keeps the shape.
        if (this.keepsSquare(element)) {
            width = Math.min(width, height);
            height = width;
        }

        element.refpoint = zone.refpoint;
        element.alignment = zone.alignment;
        element.valignment = zone.valignment;
        element.zoneid = zone.id;
        element.width = this.round(width);
        element.height = this.round(height);

        var horizontal = zone.refpoint.charAt(1);
        var vertical = zone.refpoint.charAt(0);
        var left = zone.x;
        var top = zone.y;

        if (horizontal === 'c') {
            left = zone.x + ((zone.w - width) / 2);
        } else if (horizontal === 'r') {
            left = zone.x + zone.w - width;
        }

        if (vertical === 'm') {
            top = zone.y + ((zone.h - height) / 2);
        } else if (vertical === 'b') {
            top = zone.y + zone.h - height;
        }

        this.applyBox(element, {left: left, top: top, width: width, height: height});
    };

    // --------------------------------------------------------------- marquee.

    /**
     * Start a rubber band selection on the empty stage.
     *
     * @param {PointerEvent} event the pointer event that started it
     * @return {void}
     */
    Designer.prototype.beginMarquee = function (event) {
        this.marquee = {
            startX: event.clientX,
            startY: event.clientY,
            stageRect: this.stage.getBoundingClientRect(),
            // Shift adds to what is already selected; a plain drag replaces it,
            // and replacing it at the outset is what a click on the empty stage
            // has always done.
            base: event.shiftKey ? this.selection.slice() : [],
            node: null
        };

        if (!event.shiftKey && this.selection.length) {
            // Quietly: the band is still being drawn, and what it catches is
            // what is worth saying. endMarquee() says it.
            this.select([], true);
        }
    };

    /**
     * Draw the band and show what it has caught so far.
     *
     * @param {PointerEvent} event the pointer event
     * @return {void}
     */
    Designer.prototype.continueMarquee = function (event) {
        if (!this.marquee) {
            return;
        }

        var travelled = Math.abs(event.clientX - this.marquee.startX)
            + Math.abs(event.clientY - this.marquee.startY);

        if (!this.marquee.node && travelled <= DRAG_THRESHOLD) {
            return;
        }

        var box = this.marqueeBox(event);

        if (!this.marquee.node) {
            this.marquee.node = this.buildMarqueeNode();
            this.layer('marquee').appendChild(this.marquee.node);
        }

        this.marquee.node.style.left = this.px(box.left) + 'px';
        this.marquee.node.style.top = this.px(box.top) + 'px';
        this.marquee.node.style.width = this.px(box.width) + 'px';
        this.marquee.node.style.height = this.px(box.height) + 'px';

        // Only the outlines are repainted while the band is moving; the panels
        // are redrawn once, on release, because they are far too expensive to
        // rebuild on every pointer move.
        this.selection = this.caughtBy(box);
        this.refreshSelectionVisuals();
    };

    /**
     * Finish a rubber band selection.
     *
     * @param {PointerEvent} event the pointer event
     * @return {void}
     */
    Designer.prototype.endMarquee = function (event) {
        if (!this.marquee) {
            return;
        }

        var dragged = !!this.marquee.node;
        var caught = dragged ? this.caughtBy(this.marqueeBox(event)) : this.marquee.base;

        this.clearLayer('marquee');
        this.marquee = null;

        this.select(caught);
    };

    /**
     * The band's rectangle in millimetres.
     *
     * @param {PointerEvent} event the pointer event
     * @return {Object} left, top, width and height in millimetres
     */
    Designer.prototype.marqueeBox = function (event) {
        var rect = this.marquee.stageRect;
        var fromX = this.mm(this.marquee.startX - rect.left);
        var fromY = this.mm(this.marquee.startY - rect.top);
        var toX = this.mm(event.clientX - rect.left);
        var toY = this.mm(event.clientY - rect.top);

        return {
            left: Math.min(fromX, toX),
            top: Math.min(fromY, toY),
            width: Math.abs(toX - fromX),
            height: Math.abs(toY - fromY)
        };
    };

    /**
     * The elements a band rectangle has caught, plus whatever it started with.
     *
     * @param {Object} box the band rectangle in millimetres
     * @return {Array} the element ids to select
     */
    Designer.prototype.caughtBy = function (box) {
        var self = this;
        var caught = this.marquee.base.slice();

        this.page().elements.forEach(function (element) {
            // A locked element does not answer the pointer anywhere else in the
            // designer, so the band must not pick it up either.
            if (element.locked || caught.indexOf(element.id) !== -1) {
                return;
            }

            var other = self.boxFor(element);
            var misses = other.left > box.left + box.width
                || other.left + other.width < box.left
                || other.top > box.top + box.height
                || other.top + other.height < box.top;

            if (!misses) {
                caught.push(element.id);
            }
        });

        return caught;
    };

    /**
     * Build the band itself.
     *
     * @return {HTMLElement} the band node
     */
    Designer.prototype.buildMarqueeNode = function () {
        var node = document.createElement('div');

        // How the band looks belongs to styles.css, which carries the rule for
        // this class. Only the geometry is set from here, because only the drag
        // knows it.
        node.className = 'cpro-marquee';

        return node;
    };

    // ------------------------------------------------------------- selection.

    /**
     * The element with the given id on the current page.
     *
     * @param {number} id the element id
     * @return {Object|null} the element record
     */
    Designer.prototype.elementById = function (id) {
        var page = this.page();
        for (var i = 0; i < page.elements.length; i++) {
            if (page.elements[i].id === id) {
                return page.elements[i];
            }
        }
        return null;
    };

    /**
     * The node drawing the element with the given id.
     *
     * @param {number} id the element id
     * @return {HTMLElement|null} the node
     */
    Designer.prototype.nodeFor = function (id) {
        return this.stage.querySelector('[data-element-id="' + id + '"]');
    };

    /**
     * Replace the selection.
     *
     * @param {Array} ids the element ids to select
     * @param {boolean} [quiet] true to change the selection without saying so
     * @return {void}
     */
    Designer.prototype.select = function (ids, quiet) {
        var changed = ids.length !== this.selection.length
            || ids.some(function (id, index) {
                return id !== this.selection[index];
            }, this);

        this.selection = ids.slice();
        this.refreshSelectionVisuals();
        this.renderInspector();
        this.renderLayers();

        if (changed && !quiet) {
            this.announceSelection();
        }
    };

    /**
     * Add or remove one element from the selection.
     *
     * @param {number} id the element id
     * @return {void}
     */
    Designer.prototype.toggleSelection = function (id) {
        var index = this.selection.indexOf(id);
        if (index === -1) {
            this.selection.push(id);
        } else {
            this.selection.splice(index, 1);
        }
        this.refreshSelectionVisuals();
        this.renderInspector();
        this.renderLayers();
        this.announceSelection();
    };

    /**
     * Mark the selected nodes.
     *
     * @return {void}
     */
    Designer.prototype.refreshSelectionVisuals = function () {
        var self = this;
        var nodes = this.stage.querySelectorAll('.cpro-element');

        Array.prototype.forEach.call(nodes, function (node) {
            var id = parseInt(node.getAttribute('data-element-id'), 10);
            var selected = self.selection.indexOf(id) !== -1;

            node.classList.toggle('cpro-selected', selected);
            node.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    };

    // -------------------------------------------------------------- keyboard.

    /**
     * Listen for keyboard shortcuts.
     *
     * The whole designer can be worked from the keyboard:
     *
     *   Tab                 walk the elements on the page, and on to the panels
     *   Enter / Space       select the element that has focus
     *   Ctrl / Cmd + Space  add it to the selection, or take it out again
     *   Arrow keys          move the selection, Shift for a larger step
     *   Ctrl / Cmd + arrow  resize the selection, Shift for a larger step
     *   Delete, Backspace   remove the selection
     *   Ctrl / Cmd + A      select everything on the page
     *   Ctrl / Cmd + D      duplicate the selection
     *   Ctrl / Cmd + Z      undo, with Shift to redo
     *   Escape              drop the selection
     *   F6, Shift + F6      move between the designer's panels
     *
     * Arrow keys mean something else inside the toolbar, which is a real ARIA
     * toolbar and so is arrow-navigated rather than tabbed through; nothing
     * there is worth nudging an element for anyway.
     *
     * @return {void}
     */
    Designer.prototype.bindKeyboard = function () {
        var self = this;

        this.root.addEventListener('keydown', function (event) {
            // Before the guard below, and only this key: F6 is how an author
            // leaves a panel, and a field that swallowed it would be a place the
            // keyboard could get into and not get out of again. No text field
            // wants F6 for anything.
            if (event.key === 'F6') {
                self.cycleRegion(event.shiftKey ? -1 : 1);
                event.preventDefault();
                return;
            }

            if (event.target.matches('input, textarea, select, [contenteditable="true"]')) {
                return;
            }

            var handled = true;
            var step = event.shiftKey ? NUDGE_LARGE : NUDGE;
            var meta = event.metaKey || event.ctrlKey;
            var element = event.target.classList
                && event.target.classList.contains('cpro-element') ? event.target : null;
            var toolbar = !!(event.target.closest
                && event.target.closest('[data-region="toolbar"]'));

            switch (event.key) {
                case 'Enter':
                    // Only on the canvas. Everything else with focus is a real
                    // button, and swallowing its Enter would stop it working.
                    if (element) {
                        self.activateElement(element, false);
                    } else {
                        handled = false;
                    }
                    break;
                case ' ':
                case 'Spacebar':
                    if (element) {
                        self.activateElement(element, meta || event.shiftKey);
                    } else {
                        handled = false;
                    }
                    break;
                case 'ArrowLeft':
                    handled = self.arrow(toolbar, -1, meta ? [-step, 0] : null,
                        meta ? null : [-step, 0]);
                    break;
                case 'ArrowRight':
                    handled = self.arrow(toolbar, 1, meta ? [step, 0] : null,
                        meta ? null : [step, 0]);
                    break;
                case 'ArrowUp':
                    handled = self.arrow(toolbar, -1, meta ? [0, -step] : null,
                        meta ? null : [0, -step]);
                    break;
                case 'ArrowDown':
                    handled = self.arrow(toolbar, 1, meta ? [0, step] : null,
                        meta ? null : [0, step]);
                    break;
                case 'Home':
                    handled = toolbar && self.moveToolbarFocus(-Infinity);
                    break;
                case 'End':
                    handled = toolbar && self.moveToolbarFocus(Infinity);
                    break;
                case 'Delete':
                case 'Backspace':
                    handled = !!self.selection.length;
                    self.deleteSelection();
                    break;
                case 'Escape':
                    // With nothing selected there is nothing to drop, and the
                    // key belongs to whatever is listening further out.
                    handled = !!self.selection.length;
                    self.select([]);
                    break;
                case 'd':
                    if (meta) {
                        self.duplicateSelection();
                    } else {
                        handled = false;
                    }
                    break;
                case 'z':
                    if (meta && event.shiftKey) {
                        self.redo();
                    } else if (meta) {
                        self.undo();
                    } else {
                        handled = false;
                    }
                    break;
                case 'a':
                    if (meta) {
                        self.select(self.page().elements.filter(function (candidate) {
                            return !candidate.locked;
                        }).map(function (candidate) {
                            return candidate.id;
                        }));
                    } else {
                        handled = false;
                    }
                    break;
                default:
                    handled = false;
            }

            if (handled) {
                event.preventDefault();
            }
        });
    };

    /**
     * What an arrow key does, which depends on where it was pressed.
     *
     * @param {boolean} toolbar whether focus is inside the toolbar
     * @param {number} direction which way to walk the toolbar
     * @param {Array|null} resize the resize to apply, as width and height in millimetres
     * @param {Array|null} move the move to apply, as x and y in millimetres
     * @return {boolean} whether the key was acted on
     */
    Designer.prototype.arrow = function (toolbar, direction, resize, move) {
        if (toolbar) {
            return this.moveToolbarFocus(direction);
        }
        if (resize) {
            this.resizeSelection(resize[0], resize[1]);
        } else {
            this.nudge(move[0], move[1]);
        }

        return true;
    };

    /**
     * Select the element that has the keyboard, the way a click would.
     *
     * @param {HTMLElement} node the element's node
     * @param {boolean} additive whether to add to the selection rather than replace it
     * @return {void}
     */
    Designer.prototype.activateElement = function (node, additive) {
        var id = parseInt(node.getAttribute('data-element-id'), 10);
        var element = this.elementById(id);

        if (!element) {
            return;
        }

        // A locked element cannot be picked up by the pointer either. Saying so
        // is the point: silence here reads as a designer that has stopped
        // answering, which is exactly what this whole change is about.
        if (element.locked) {
            this.announce('designer_announcelocked', element.name);
            return;
        }

        if (additive) {
            this.toggleSelection(id);
        } else {
            this.select([id]);
        }
    };

    /**
     * Move the selection by a fixed amount.
     *
     * @param {number} dx the horizontal movement in millimetres
     * @param {number} dy the vertical movement in millimetres
     * @return {void}
     */
    Designer.prototype.nudge = function (dx, dy) {
        var self = this;
        var movable = this.selection.filter(function (id) {
            var element = self.elementById(id);

            return !!element && !element.locked;
        });

        // Nothing to move is not a change, and recording it as one would put a
        // step on the stack that undoes nothing and throw the redo stack away.
        if (!movable.length) {
            return;
        }

        this.pushHistory();

        movable.forEach(function (id) {
            var element = self.elementById(id);
            var box = self.boxFor(element);

            box.left += dx;
            box.top += dy;
            element.zoneid = null;
            self.applyBox(element, self.clampToPage(box));
        });

        this.renderInspector();
        this.announceGeometry('designer_announceposition');
        this.queueSave();
    };

    /**
     * Resize the selection by a fixed amount.
     *
     * The one thing an author could not do without a pointer: the handles are
     * the only way to change a box, the inspector's fields are read only, and
     * so an element added by keyboard kept whatever size it was created at.
     *
     * @param {number} dw the change in width, in millimetres
     * @param {number} dh the change in height, in millimetres
     * @return {void}
     */
    Designer.prototype.resizeSelection = function (dw, dh) {
        var self = this;
        var blocked = null;
        var targets = this.selection.filter(function (id) {
            var element = self.elementById(id);

            if (!element || element.locked) {
                return false;
            }
            if (!self.isResizable(element)) {
                blocked = element;
                return false;
            }

            return true;
        });

        if (!targets.length) {
            if (blocked) {
                this.announce('designer_announcenotresizable', blocked.name);
            }
            return;
        }

        this.pushHistory();

        targets.forEach(function (id) {
            var element = self.elementById(id);
            var box = self.boxFor(element);

            if (self.keepsSquare(element)) {
                // One side for both, and the step that is not zero is the one
                // that leads, so a square element stays square whichever arrow
                // was pressed — the same rule the resize handles follow.
                box.width = Math.max(MIN_SIZE, Math.min(box.width, box.height) + (dw || dh));
                box.height = box.width;
            } else {
                box.width = Math.max(MIN_SIZE, box.width + dw);
                box.height = Math.max(MIN_SIZE, box.height + dh);
            }

            element.zoneid = null;
            self.applyBox(element, self.clampToPage(box));
        });

        this.renderInspector();
        this.announceGeometry('designer_announcesize');
        this.queueSave();
    };

    // ---------------------------------------------------------- announcements.

    /**
     * Say something in the designer's live region.
     *
     * The canvas answers a keypress by moving a rectangle, which is no answer at
     * all to someone who cannot see it. This is the one place that speaks, and
     * it is deliberately kept to the things a keypress actually changed: what is
     * selected, where it went, what size it now is, what an undo undid.
     *
     * The region is cleared and then filled a moment later, because a live
     * region whose text is set to the value it already holds is not re-announced
     * by most screen readers, and pressing the same arrow key twice is the
     * normal case here.
     *
     * @param {string} text what to say
     * @return {void}
     */
    Designer.prototype.say = function (text) {
        var self = this;

        if (!this.liveregion) {
            return;
        }

        if (this.announceTimer) {
            window.clearTimeout(this.announceTimer);
        }

        this.liveregion.textContent = '';
        this.announceTimer = window.setTimeout(function () {
            self.announceTimer = null;
            self.liveregion.textContent = text;
        }, 60);
    };

    /**
     * Say a language string in the designer's live region.
     *
     * @param {string} key the string identifier
     * @param {Object|string|number} [param] the value for the string's placeholder
     * @return {void}
     */
    Designer.prototype.announce = function (key, param) {
        var self = this;

        if (!this.liveregion) {
            return;
        }

        Str.get_string(key, 'mod_certificatepro', param).then(function (text) {
            self.say(text);
            return text;
        }).catch(Notification.exception);
    };

    /**
     * Describe what is selected now.
     *
     * @return {void}
     */
    Designer.prototype.announceSelection = function () {
        if (!this.selection.length) {
            this.announce('designer_announcenoselection');
            return;
        }

        if (this.selection.length === 1) {
            var element = this.elementById(this.selection[0]);
            this.announce('designer_announceselected', element ? element.name : '');
            return;
        }

        this.announce('designer_announceselectedcount', this.selection.length);
    };

    /**
     * Describe where the selection is, or how big it is, once it stops changing.
     *
     * Holding an arrow key down fires dozens of times a second, and a live
     * region told dozens of times a second says nothing useful. The reading is
     * taken when the author stops.
     *
     * @param {string} key the string to read the geometry into
     * @return {void}
     */
    Designer.prototype.announceGeometry = function (key) {
        var self = this;

        if (this.geometryTimer) {
            window.clearTimeout(this.geometryTimer);
        }

        this.geometryTimer = window.setTimeout(function () {
            self.geometryTimer = null;

            var element = self.selection.length ? self.elementById(self.selection[0]) : null;
            if (!element) {
                return;
            }

            self.announce(key, {
                x: element.posx.toFixed(1),
                y: element.posy.toFixed(1),
                width: (element.width || 0).toFixed(1),
                height: (element.height || 0).toFixed(1)
            });
        }, ANNOUNCE_DELAY);
    };

    /**
     * Say which page is on screen.
     *
     * @return {void}
     */
    Designer.prototype.announcePage = function () {
        this.announce('designer_announcepage', {
            number: this.pageIndex + 1,
            total: (this.template.pages || []).length
        });
    };

    // -------------------------------------------------------- moving about.

    /**
     * The designer's panels, in the order F6 walks them.
     *
     * The page strip is listed before the canvas it sits inside, because the
     * first region that contains the focused node is the one taken to be
     * current, and the strip is the more specific of the two.
     *
     * @return {Array} the panel nodes that are present
     */
    Designer.prototype.regions = function () {
        var self = this;

        return [
            '[data-region="toolbar"]',
            '[data-region="librarybar"]',
            '[data-region="picker"]',
            '[data-region="pagetabs"]',
            '[data-region="canvas"]',
            '[data-region="inspector"]',
            '[data-region="layers"]'
        ].map(function (selector) {
            return self.root.querySelector(selector);
        }).filter(function (node) {
            return !!node;
        });
    };

    /**
     * Move the keyboard to the next panel of the designer.
     *
     * Tabbing from the toolbar to the canvas means passing about thirty buttons
     * in the element picker, and tabbing back again means passing them the other
     * way. F6 is the key browsers and desktop applications already use for this,
     * and it collides with nothing the designer does.
     *
     * @param {number} direction 1 for the next panel, -1 for the previous one
     * @return {void}
     */
    Designer.prototype.cycleRegion = function (direction) {
        var node = document.activeElement;
        var regions = this.regions();
        var current = -1;

        regions.forEach(function (region, index) {
            if (current === -1 && node && region.contains(node)) {
                current = index;
            }
        });

        if (!regions.length) {
            return;
        }

        var next = current === -1
            ? 0
            : (current + direction + regions.length) % regions.length;

        this.focusRegion(regions[next]);
    };

    /**
     * Put the keyboard into a panel, and say which one it is.
     *
     * @param {HTMLElement} region the panel to move to
     * @return {void}
     */
    Designer.prototype.focusRegion = function (region) {
        var target = null;

        if (region === this.canvas) {
            // The canvas means the design, not the strip of page tabs above it,
            // and the selected element is what the author was last working on.
            target = (this.selection.length ? this.nodeFor(this.selection[0]) : null)
                || this.stage.querySelector('.cpro-element[tabindex="0"]');
        }

        // Something to press, before something to type in: the inspector's
        // fields come first in its markup but they are read only, so landing on
        // one puts the keyboard somewhere with nothing to do.
        if (!target) {
            target = region.querySelector(
                'button:not([disabled]):not([tabindex="-1"]), a[href], [tabindex="0"]')
                || region.querySelector('select, input:not([disabled]), textarea');
        }

        (target || region).focus({preventScroll: true});

        var label = region.getAttribute('aria-label');
        if (label) {
            this.say(label);
        }
    };

    /**
     * The toolbar buttons that can take the keyboard, in the order they appear.
     *
     * @return {Array} the enabled toolbar buttons
     */
    Designer.prototype.toolbarButtons = function () {
        var bar = this.root.querySelector('[data-region="toolbar"]');

        if (!bar) {
            return [];
        }

        return Array.prototype.filter.call(
            bar.querySelectorAll('button.certificatepro-toolbar-button'),
            function (node) {
                return !node.disabled;
            });
    };

    /**
     * Make the toolbar one tab stop that arrow keys move around inside.
     *
     * This is what role="toolbar" promises, and the markup has claimed that role
     * all along. Thirty-odd separate tab stops between the top of the designer
     * and the canvas is the other reason: an author who wanted the canvas had to
     * pass every one of them.
     *
     * @return {void}
     */
    Designer.prototype.bindToolbarRoving = function () {
        var self = this;
        var bar = this.root.querySelector('[data-region="toolbar"]');

        if (!bar) {
            return;
        }

        this.each('[data-region="toolbar"] button.certificatepro-toolbar-button',
            function (node) {
                node.setAttribute('tabindex', '-1');
            });

        this.ensureToolbarStop();

        // Whatever the author reaches, by arrow key or by pointer, becomes the
        // stop the next Tab into the toolbar will land on.
        bar.addEventListener('focusin', function (event) {
            var node = event.target.closest('button.certificatepro-toolbar-button');

            if (node) {
                self.setToolbarStop(node);
            }
        });
    };

    /**
     * Make one toolbar button the one Tab reaches.
     *
     * @param {HTMLElement} node the button
     * @return {void}
     */
    Designer.prototype.setToolbarStop = function (node) {
        this.each('[data-region="toolbar"] button.certificatepro-toolbar-button',
            function (other) {
                other.setAttribute('tabindex', other === node ? '0' : '-1');
            });
    };

    /**
     * Keep the toolbar's tab stop on a button that can actually take focus.
     *
     * Undo and redo are the first two buttons and both start out disabled, so
     * without this the toolbar's only tab stop is unreachable and Tab skips the
     * whole toolbar.
     *
     * @return {void}
     */
    Designer.prototype.ensureToolbarStop = function () {
        var buttons = this.toolbarButtons();
        var stop = this.root.querySelector(
            '[data-region="toolbar"] button.certificatepro-toolbar-button[tabindex="0"]');

        if (buttons.length && (!stop || stop.disabled)) {
            this.setToolbarStop(buttons[0]);
        }
    };

    /**
     * Walk the keyboard along the toolbar.
     *
     * @param {number} delta 1 or -1 to step, or +/-Infinity for the far end
     * @param {HTMLElement} [from] the button to count from, when it is not the focused one
     * @return {boolean} whether the keyboard moved
     */
    Designer.prototype.moveToolbarFocus = function (delta, from) {
        var buttons = this.toolbarButtons();

        if (!buttons.length) {
            return false;
        }

        var current = buttons.indexOf(from || document.activeElement);
        var next;

        if (delta === -Infinity) {
            next = 0;
        } else if (delta === Infinity) {
            next = buttons.length - 1;
        } else if (current === -1) {
            next = 0;
        } else {
            next = (current + delta + buttons.length) % buttons.length;
        }

        buttons[next].focus();

        return true;
    };

    /**
     * Enable or disable a control without letting it take the keyboard with it.
     *
     * A disabled button cannot hold focus, so the browser drops focus to BODY
     * and every shortcut stops answering. Pressing Ctrl+Z from the Undo button
     * until the history ran out did exactly that.
     *
     * @param {HTMLElement} node the control
     * @param {boolean} disabled whether it should be disabled
     * @return {void}
     */
    Designer.prototype.setDisabled = function (node, disabled) {
        if (!disabled || node.disabled || node !== document.activeElement) {
            node.disabled = disabled;
            return;
        }

        // Where the keyboard goes is worked out before the button stops being
        // able to hold it, so it lands on a neighbour rather than at the far end
        // of the toolbar: the button before it, or the one after when it was
        // the first. Undoing until the history runs out therefore leaves the
        // keyboard on Redo, which is the only thing left to do.
        var index = this.toolbarButtons().indexOf(node);

        node.disabled = true;

        var remaining = this.toolbarButtons();

        if (remaining.length) {
            remaining[Math.max(0, Math.min(index - 1, remaining.length - 1))].focus();
        } else {
            this.focusFallback();
        }
    };

    // --------------------------------------------------------------- toolbar.

    /**
     * Listen for toolbar actions.
     *
     * @return {void}
     */
    Designer.prototype.bindToolbar = function () {
        var self = this;

        this.root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action]');
            if (!trigger) {
                return;
            }

            var action = trigger.getAttribute('data-action');
            var value = trigger.getAttribute('data-value');

            switch (action) {
                case 'align':
                    self.align(value);
                    break;
                case 'save':
                    self.saveNow();
                    break;
                case 'reset':
                    self.confirmReset(trigger);
                    break;
                case 'orientation':
                    self.setPageGeometry(value, null);
                    break;
                case 'togglesnap':
                    self.snap = !self.snap;
                    self.press('[data-action="togglesnap"]', self.snap);
                    break;
                case 'distribute':
                    self.distribute(value);
                    break;
                case 'zoom':
                    self.setZoom(parseFloat(value));
                    break;
                case 'undo':
                    self.undo();
                    break;
                case 'redo':
                    self.redo();
                    break;
                case 'duplicate':
                    self.duplicateSelection();
                    break;
                case 'delete':
                    self.deleteSelection();
                    break;
                case 'tofront':
                    self.reorder(1000);
                    break;
                case 'toback':
                    self.reorder(-1000);
                    break;
                case 'forward':
                    self.reorder(1);
                    break;
                case 'backward':
                    self.reorder(-1);
                    break;
                case 'togglezones':
                    self.root.classList.toggle('cpro-show-zones');
                    self.press('[data-action="togglezones"]',
                        self.root.classList.contains('cpro-show-zones'));
                    self.previewZones();
                    break;
                case 'selectelement':
                    self.select([parseInt(value, 10)]);
                    break;
                case 'togglevisible':
                    self.toggleVisibility(parseInt(value, 10));
                    break;
                case 'togglelock':
                    self.toggleLock(parseInt(value, 10));
                    break;
                case 'page':
                    self.showPage(parseInt(value, 10));
                    break;
                case 'addpage':
                    self.addPage();
                    break;
                case 'deletepage':
                    self.confirmDeletePage(parseInt(value, 10), trigger);
                    break;
                default:
                    break;
            }

            // The page size control is a select: swallowing its click would stop
            // the list opening in browsers that act on the click rather than the
            // preceding pointerdown.
            if (action !== 'selectelement' && action !== 'pagesize') {
                event.preventDefault();
            }
        });

        this.root.addEventListener('change', function (event) {
            if (!event.target.matches || !event.target.matches('[data-action="pagesize"]')) {
                return;
            }
            self.setPageGeometry(null, event.target.value);
        });
    };

    /**
     * Run a callback over every matching node inside the designer.
     *
     * @param {string} selector the selector to match
     * @param {Function} callback called with each node
     * @return {void}
     */
    Designer.prototype.each = function (selector, callback) {
        Array.prototype.forEach.call(this.root.querySelectorAll(selector), callback);
    };

    /**
     * Set the pressed state of every toggle matching a selector.
     *
     * @param {string} selector the selector to match
     * @param {boolean} pressed whether the control is on
     * @return {void}
     */
    Designer.prototype.press = function (selector, pressed) {
        this.each(selector, function (node) {
            node.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        });
    };

    /**
     * Put the toolbar back in step with the model.
     *
     * The toolbar is built by PHP before anything is known about which page is
     * on screen, so a portrait A5 design would otherwise open showing landscape
     * and A4 as the current choices, which is worse than showing nothing.
     *
     * @return {void}
     */
    Designer.prototype.syncToolbar = function () {
        var self = this;
        var page = this.page();

        this.press('[data-action="togglezones"]', this.root.classList.contains('cpro-show-zones'));
        this.press('[data-action="togglesnap"]', this.snap);

        // Undo and redo used to stay lit whether or not there was anything to
        // undo, so the only way to find out was to press them and watch nothing
        // happen. Greying them tells the author where they are in the history.
        // Redo before undo, deliberately. Undoing the last step disables Undo
        // and enables Redo, and the keyboard has to be handed to a button that
        // is already enabled by the time the handover happens.
        this.each('[data-action="redo"]', function (node) {
            self.setDisabled(node, !self.future.length);
        });
        this.each('[data-action="undo"]', function (node) {
            self.setDisabled(node, !self.history.length);
        });

        this.ensureToolbarStop();

        this.each('[data-action="zoom"]', function (node) {
            node.setAttribute('aria-pressed',
                parseFloat(node.getAttribute('data-value')) === self.zoom ? 'true' : 'false');
        });

        if (!page) {
            return;
        }

        this.each('[data-action="orientation"]', function (node) {
            node.setAttribute('aria-pressed',
                node.getAttribute('data-value') === page.orientation ? 'true' : 'false');
        });

        var pagesize = this.root.querySelector('[data-action="pagesize"]');
        if (pagesize) {
            pagesize.value = page.pagesize;
        }
    };

    /**
     * Change the current page's orientation or paper size.
     *
     * @param {string|null} orientation L, P, or null to keep the current one
     * @param {string|null} pagesize a page size key, or null to keep the current one
     * @return {void}
     */
    Designer.prototype.setPageGeometry = function (orientation, pagesize) {
        var self = this;
        var page = this.page();

        if (!page || this.busy) {
            return;
        }

        orientation = orientation === null ? page.orientation : (orientation === 'P' ? 'P' : 'L');
        pagesize = pagesize || page.pagesize;

        if (orientation === page.orientation && pagesize === page.pagesize) {
            return;
        }

        this.busy = true;
        this.setSaveState('saving');

        // The server re-docks from what it has stored, so the last drag has to
        // land before the page changes shape underneath it.
        this.saveNow().then(function () {
            return Ajax.call([{
                methodname: 'mod_certificatepro_set_page_geometry',
                args: {
                    templateid: self.template.id,
                    pageid: page.id,
                    orientation: orientation,
                    pagesize: pagesize
                }
            }])[0];
        }).then(function (response) {
            self.template.pages[self.pageIndex] = JSON.parse(response.page);
            self.busy = false;
            // Every position was recomputed on the server, so anything already
            // on the undo stack describes a page shape that no longer exists.
            self.history = [];
            self.future = [];
            self.select([], true);
            self.render();
            self.syncToolbar();
            self.setSaveState('saved');
            self.ensureFocus();
            return true;
        }).catch(function (error) {
            self.busy = false;
            // Put the controls back to what the page really is, so the select
            // does not sit showing a size the design never took.
            self.syncToolbar();
            self.setSaveState('savefailed');
            Notification.exception(error);
        });
    };

    /**
     * Ask before throwing an author's work away, then revert to the starter.
     *
     * @param {HTMLElement} trigger the button that asked for this
     * @return {void}
     */
    Designer.prototype.confirmReset = function (trigger) {
        var self = this;

        if (this.busy) {
            return;
        }

        Str.get_strings([
            {key: 'designer_reset', component: 'mod_certificatepro'},
            {key: 'designer_resetconfirm', component: 'mod_certificatepro'}
        ]).then(function (strings) {
            return Notification.saveCancelPromise(strings[0], strings[1], strings[0],
                {triggerElement: trigger});
        }).then(function () {
            return self.resetDesign();
        }).catch(function () {
            // Cancelling, pressing Escape or clicking away rejects with nothing
            // to report, and a real failure has already been shown by the reset.
            return false;
        });
    };

    /**
     * Rebuild the design from its starter and redraw everything.
     *
     * @return {Promise} resolves when the design has been rebuilt
     */
    Designer.prototype.resetDesign = function () {
        var self = this;

        this.busy = true;

        // A queued autosave would write the old elements back over a design that
        // no longer contains them, so drop it rather than let it land.
        if (this.saveTimer) {
            window.clearTimeout(this.saveTimer);
            this.saveTimer = null;
            this.savePage = null;
        }

        this.setSaveState('saving');

        return Ajax.call([{
            methodname: 'mod_certificatepro_reset_design',
            args: {templateid: this.template.id}
        }])[0].then(function (response) {
            self.template = JSON.parse(response.design);
            self.busy = false;
            self.pageIndex = 0;
            self.history = [];
            self.future = [];
            self.select([], true);
            self.render();
            self.syncToolbar();
            self.setSaveState('saved');
            self.ensureFocus();
            return true;
        }).catch(function (error) {
            self.busy = false;
            self.setSaveState('savefailed');
            Notification.exception(error);
        });
    };

    // ------------------------------------------------------------------ pages.

    /**
     * Put a language string onto a node as soon as it arrives.
     *
     * The page strip is redrawn every time the design changes and every label on
     * it carries the page's own number, so these strings cannot be collected
     * once at start-up the way the fixed ones are. core/str keeps what it has
     * already fetched, so only the first draw of a given page number waits on
     * anything.
     *
     * @param {HTMLElement} node the node to label
     * @param {string} key the string identifier
     * @param {number|string} param the value for the string's placeholder
     * @param {Array} attributes attributes to set, or nothing for the text itself
     * @return {void}
     */
    Designer.prototype.label = function (node, key, param, attributes) {
        Str.get_string(key, 'mod_certificatepro', param).then(function (text) {
            if (!attributes || !attributes.length) {
                node.textContent = text;
                return text;
            }
            attributes.forEach(function (name) {
                node.setAttribute(name, text);
            });
            return text;
        }).catch(Notification.exception);
    };

    /**
     * Draw the strip of page tabs and the controls that add and remove pages.
     *
     * The strip is drawn here rather than by PHP because it changes while the
     * author works: a page added or removed has to appear or go without a
     * reload, and the tab for the page on screen has to be the one that looks
     * current. PHP only leaves the empty strip for this to fill.
     *
     * @return {void}
     */
    Designer.prototype.renderPageTabs = function () {
        var self = this;
        var host = this.root.querySelector('[data-region="pagetabs"]');

        if (!host) {
            return;
        }

        var pages = this.template.pages || [];

        this.preservingFocus(function () {
            host.innerHTML = '';

            pages.forEach(function (page, index) {
                host.appendChild(self.buildPageTab(index, pages.length));
            });

            host.appendChild(self.buildAddPageButton());
        });
    };

    /**
     * Build one page's tab, with the control that removes it.
     *
     * @param {number} index the page's position in the design
     * @param {number} total how many pages the design has
     * @return {HTMLElement} the tab
     */
    Designer.prototype.buildPageTab = function (index, total) {
        var group = document.createElement('span');
        var tab = document.createElement('button');
        var remove = document.createElement('button');
        var caption = document.createElement('span');
        var icon = document.createElement('i');

        group.className = 'certificatepro-pagetab';

        tab.type = 'button';
        tab.className = 'btn btn-sm certificatepro-pagetab-select';
        tab.setAttribute('data-action', 'page');
        tab.setAttribute('data-value', String(index));
        tab.setAttribute('aria-pressed', index === this.pageIndex ? 'true' : 'false');
        tab.appendChild(caption);
        this.label(caption, 'pagenumber', index + 1);
        group.appendChild(tab);

        icon.className = 'fa fa-times';
        icon.setAttribute('aria-hidden', 'true');

        remove.type = 'button';
        remove.className = 'btn btn-sm certificatepro-pagetab-remove';
        remove.setAttribute('data-action', 'deletepage');
        remove.setAttribute('data-value', String(index));
        remove.appendChild(icon);

        // The last page cannot go: a design with no pages has nothing to print,
        // and the service refuses it too. Saying so on the control is kinder
        // than letting an author click it and read an error.
        if (total < 2) {
            remove.disabled = true;
            this.label(remove, 'designer_deletepagelast', null, ['title', 'aria-label']);
        } else {
            this.label(remove, 'designer_deletepage', index + 1, ['title', 'aria-label']);
        }

        group.appendChild(remove);

        return group;
    };

    /**
     * Build the control that adds a page.
     *
     * @return {HTMLElement} the button
     */
    Designer.prototype.buildAddPageButton = function () {
        var button = document.createElement('button');
        var icon = document.createElement('i');
        var caption = document.createElement('span');

        icon.className = 'fa fa-plus';
        icon.setAttribute('aria-hidden', 'true');

        button.type = 'button';
        button.className = 'btn btn-sm certificatepro-pagetab-add';
        button.setAttribute('data-action', 'addpage');
        button.appendChild(icon);
        button.appendChild(caption);

        this.label(caption, 'designer_addpage', null);
        this.label(button, 'designer_addpage', null, ['title']);

        return button;
    };

    /**
     * Show one of the design's pages.
     *
     * The undo history deliberately survives this. It describes changes an
     * author made, and an author composing a two page certificate moves between
     * the pages constantly — copying a heading's placement onto the back sheet,
     * checking one against the other. Throwing the history away every time they
     * looked at the other page would make undo useless exactly when it is most
     * wanted, and would be a silent loss of their work as far as they could
     * tell. Each step knows which page it belongs to instead, and undo goes
     * there; see {@see Designer#applyStep}.
     *
     * @param {number} index the page's position in the design
     * @return {void}
     */
    Designer.prototype.showPage = function (index) {
        if (index === this.pageIndex || !this.template.pages[index]) {
            return;
        }

        this.pageIndex = index;
        this.select([], true);
        this.render();
        this.syncToolbar();
        this.announcePage();
    };

    /**
     * Where a page id sits in the design on screen.
     *
     * @param {number} pageid the page to find
     * @return {number} the page's index, or -1 when the design no longer has it
     */
    Designer.prototype.pageIndexOf = function (pageid) {
        var pages = this.template.pages || [];

        for (var i = 0; i < pages.length; i++) {
            if (pages[i].id === pageid) {
                return i;
            }
        }

        return -1;
    };

    /**
     * Add a page to the design and move to it.
     *
     * The new page takes the paper and orientation of the one being worked on,
     * because a certificate whose second sheet is a different shape from its
     * first is a mistake far more often than it is a decision.
     *
     * @return {void}
     */
    Designer.prototype.addPage = function () {
        var self = this;
        var page = this.page();

        if (this.busy || !page) {
            return;
        }

        this.busy = true;
        this.setSaveState('saving');

        // A queued autosave is written from the design on screen, and the server
        // is about to hand back a new one, so the save has to land first rather
        // than after.
        this.saveNow().then(function () {
            return Ajax.call([{
                methodname: 'mod_certificatepro_add_page',
                args: {
                    templateid: self.template.id,
                    orientation: page.orientation,
                    pagesize: page.pagesize
                }
            }])[0];
        }).then(function (response) {
            var design = JSON.parse(response.design);

            self.adoptDesign(design, self.indexOfPageIn(design, response.pageid));
            return true;
        }).catch(function (error) {
            self.busy = false;
            self.setSaveState('savefailed');
            Notification.exception(error);
        });
    };

    /**
     * Where a page id sits in a design that is not on screen yet.
     *
     * @param {Object} design the design to search
     * @param {number} pageid the page to find
     * @return {number} the page's index
     */
    Designer.prototype.indexOfPageIn = function (design, pageid) {
        var pages = design.pages || [];

        for (var i = 0; i < pages.length; i++) {
            if (pages[i].id === pageid) {
                return i;
            }
        }

        return 0;
    };

    /**
     * Ask whether a page really is to go, then remove it.
     *
     * @param {number} index the page's position in the design
     * @param {HTMLElement} trigger the button that asked for this
     * @return {void}
     */
    Designer.prototype.confirmDeletePage = function (index, trigger) {
        var self = this;
        var page = this.template.pages[index];

        if (this.busy || !page) {
            return;
        }

        Str.get_strings([
            {key: 'designer_deletepage', component: 'mod_certificatepro', param: index + 1},
            {key: 'designer_deletepageconfirm', component: 'mod_certificatepro',
             param: index + 1},
            {key: 'designer_remove', component: 'mod_certificatepro'}
        ]).then(function (strings) {
            return Notification.saveCancelPromise(strings[0], strings[1], strings[2],
                {triggerElement: trigger});
        }).then(function () {
            return self.deletePage(page.id, index);
        }).catch(function () {
            // Cancelling, pressing Escape and clicking away all reject with
            // nothing to report, and a real failure has already been shown.
            return false;
        });
    };

    /**
     * Remove a page and everything on it.
     *
     * @param {number} pageid the page to remove
     * @param {number} index the page's position in the design
     * @return {Promise} resolves when the page has gone
     */
    Designer.prototype.deletePage = function (pageid, index) {
        var self = this;

        this.busy = true;

        // The page is about to stop existing, so an autosave holding its
        // elements must not be allowed to land after the delete.
        if (this.saveTimer) {
            window.clearTimeout(this.saveTimer);
            this.saveTimer = null;
            this.savePage = null;
        }

        this.setSaveState('saving');

        return Ajax.call([{
            methodname: 'mod_certificatepro_delete_page',
            args: {
                templateid: this.template.id,
                pageid: pageid
            }
        }])[0].then(function (response) {
            var design = JSON.parse(response.design);

            // The page that took the deleted one's place is the natural one to
            // land on, and the last page in the design when there is no such
            // page because the end was removed.
            self.adoptDesign(design, Math.min(index, design.pages.length - 1));
            return true;
        }).catch(function (error) {
            self.busy = false;
            self.setSaveState('savefailed');
            Notification.exception(error);
        });
    };

    /**
     * Take on a design the server has rebuilt, and draw it.
     *
     * Every undo entry describes elements on pages that may no longer be there,
     * so the history goes with the old design rather than outliving it.
     *
     * @param {Object} design the design as the server now holds it
     * @param {number} index the page to show
     * @return {void}
     */
    Designer.prototype.adoptDesign = function (design, index) {
        this.template = design;
        this.busy = false;
        this.history = [];
        this.future = [];
        this.pageIndex = Math.max(0, Math.min(index, design.pages.length - 1));
        this.select([], true);
        this.render();
        this.syncToolbar();
        this.setSaveState('saved');
        // The dialogue that asked about this handed focus back to the button it
        // was opened from, and that button — a page's remove control — has just
        // stopped existing.
        this.ensureFocus();
        this.announcePage();
    };

    /**
     * Show every zone at once, so an author can see the grid they are working to.
     *
     * @return {void}
     */
    Designer.prototype.previewZones = function () {
        if (!this.root.classList.contains('cpro-show-zones')) {
            this.clearLayer('zones');
            return;
        }

        var self = this;
        var page = this.page();

        this.clearLayer('zones');

        page.zones.forEach(function (zone) {
            if (!zone.snappable) {
                return;
            }
            var node = document.createElement('div');
            node.className = 'cpro-zone cpro-zone-ghost';
            node.style.left = self.px(zone.x) + 'px';
            node.style.top = self.px(zone.y) + 'px';
            node.style.width = self.px(zone.w) + 'px';
            node.style.height = self.px(zone.h) + 'px';

            var label = document.createElement('span');
            label.className = 'cpro-zone-label';
            label.textContent = zone.label;
            node.appendChild(label);

            self.layer('zones').appendChild(node);
        });
    };

    /**
     * Align the selection, or align a single element to the page.
     *
     * @param {string} how left, centre, right, top, middle or bottom
     * @return {void}
     */
    Designer.prototype.align = function (how) {
        var self = this;
        var page = this.page();

        if (!this.selection.length) {
            return;
        }

        this.pushHistory();

        var boxes = this.selection.map(function (id) {
            return {id: id, box: self.boxFor(self.elementById(id))};
        });

        var bounds;
        if (boxes.length === 1) {
            bounds = {left: 0, top: 0, right: page.width, bottom: page.height};
        } else {
            bounds = {
                left: Math.min.apply(null, boxes.map(function (entry) {
                    return entry.box.left;
                })),
                top: Math.min.apply(null, boxes.map(function (entry) {
                    return entry.box.top;
                })),
                right: Math.max.apply(null, boxes.map(function (entry) {
                    return entry.box.left + entry.box.width;
                })),
                bottom: Math.max.apply(null, boxes.map(function (entry) {
                    return entry.box.top + entry.box.height;
                }))
            };
        }

        boxes.forEach(function (entry) {
            var element = self.elementById(entry.id);
            var box = entry.box;

            switch (how) {
                case 'left':
                    box.left = bounds.left;
                    break;
                case 'centre':
                    box.left = bounds.left + (((bounds.right - bounds.left) - box.width) / 2);
                    break;
                case 'right':
                    box.left = bounds.right - box.width;
                    break;
                case 'top':
                    box.top = bounds.top;
                    break;
                case 'middle':
                    box.top = bounds.top + (((bounds.bottom - bounds.top) - box.height) / 2);
                    break;
                case 'bottom':
                    box.top = bounds.bottom - box.height;
                    break;
                default:
                    break;
            }

            element.zoneid = null;
            self.applyBox(element, box);
        });

        this.queueSave();
    };

    /**
     * Space the selection evenly.
     *
     * @param {string} axis horizontal or vertical
     * @return {void}
     */
    Designer.prototype.distribute = function (axis) {
        var self = this;

        if (this.selection.length < 3) {
            return;
        }

        this.pushHistory();

        var entries = this.selection.map(function (id) {
            return {id: id, box: self.boxFor(self.elementById(id))};
        });

        entries.sort(function (a, b) {
            return axis === 'horizontal' ? a.box.left - b.box.left : a.box.top - b.box.top;
        });

        var first = entries[0].box;
        var last = entries[entries.length - 1].box;

        var span = axis === 'horizontal'
            ? (last.left + last.width) - first.left
            : (last.top + last.height) - first.top;

        var used = entries.reduce(function (total, entry) {
            return total + (axis === 'horizontal' ? entry.box.width : entry.box.height);
        }, 0);

        var gap = (span - used) / (entries.length - 1);
        var cursor = axis === 'horizontal' ? first.left : first.top;

        entries.forEach(function (entry) {
            var element = self.elementById(entry.id);
            if (axis === 'horizontal') {
                entry.box.left = cursor;
                cursor += entry.box.width + gap;
            } else {
                entry.box.top = cursor;
                cursor += entry.box.height + gap;
            }
            element.zoneid = null;
            self.applyBox(element, entry.box);
        });

        this.queueSave();
    };

    /**
     * Change the stacking order of the selection.
     *
     * @param {number} delta how far to move, where large values mean front or back
     * @return {void}
     */
    Designer.prototype.reorder = function (delta) {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        this.pushHistory();

        this.selection.forEach(function (id) {
            var element = self.elementById(id);
            if (element) {
                element.zindex = Math.max(0, (element.zindex || 0) + delta);
            }
        });

        this.page().elements.sort(function (a, b) {
            return (a.zindex || 0) - (b.zindex || 0);
        });

        this.page().elements.forEach(function (element, index) {
            element.zindex = index;
        });

        this.renderElements();
        this.renderLayers();
        this.queueSave();
    };

    /**
     * Show or hide one element.
     *
     * @param {number} id the element id
     * @return {void}
     */
    Designer.prototype.toggleVisibility = function (id) {
        var element = this.elementById(id);
        if (!element) {
            return;
        }
        this.pushHistory();
        element.hidden = !element.hidden;
        this.renderElements();
        this.renderLayers();
        this.queueSave();
    };

    /**
     * Lock or unlock one element.
     *
     * @param {number} id the element id
     * @return {void}
     */
    Designer.prototype.toggleLock = function (id) {
        var element = this.elementById(id);
        if (!element) {
            return;
        }
        element.locked = !element.locked;
        this.renderElements();
        this.renderLayers();
        this.queueSave();
    };

    /**
     * Change the zoom level.
     *
     * @param {number} zoom the new zoom factor
     * @return {void}
     */
    Designer.prototype.setZoom = function (zoom) {
        this.zoom = Math.max(0.25, Math.min(4, zoom));
        this.render();
        this.syncToolbar();
    };

    // --------------------------------------------------------------- history.

    /**
     * The current page as a history entry.
     *
     * An entry says what the page looked like, and what has to happen on the
     * server as well as on screen for the page to look like that again:
     * `created` names elements that must be deleted, because the step brought
     * them into being, and `removed` carries the elements that must be put back,
     * because the step took them away. Both are needed because the server holds
     * the elements and the browser only holds a drawing of them.
     *
     * An entry also says which page it describes. The history is one stack for
     * the whole design but a snapshot only ever holds one page's elements, so
     * without the page id an undo applied whatever it was holding to whatever
     * page happened to be on screen: undoing after a page switch emptied the
     * page the author was looking at, or drew the other page's elements onto it.
     *
     * @param {Object} [page] the page to describe, defaulting to the one on screen
     * @return {Object} the entry: the page, the elements, what the step created,
     *                  what it removed
     */
    Designer.prototype.snapshot = function (page) {
        page = page || this.page();

        return {
            pageid: page.id,
            elements: JSON.stringify(page.elements),
            created: [],
            removed: null
        };
    };

    /**
     * Record the current state so it can be undone.
     *
     * @return {void}
     */
    Designer.prototype.pushHistory = function () {
        this.pushStep(this.snapshot());
    };

    /**
     * Put a step on the undo stack, and abandon the redo stack.
     *
     * Anything the author does by hand is a new branch: whatever was waiting to
     * be redone describes a page that no longer exists.
     *
     * @param {Object} entry the step to record
     * @return {void}
     */
    Designer.prototype.pushStep = function (entry) {
        this.history.push(entry);
        if (this.history.length > HISTORY_LIMIT) {
            this.history.shift();
        }
        this.future = [];
        this.syncToolbar();
    };

    /**
     * Note that the step just recorded brought these elements into being.
     *
     * save_layout only ever updates elements the server already has, and
     * silently skips the rest. So dropping a new element from the page is not
     * enough to undo adding it: without deleting it on the server as well it
     * comes back on the next reload.
     *
     * @param {Array} ids the ids the step created
     * @return {void}
     */
    Designer.prototype.markCreated = function (ids) {
        if (this.history.length) {
            this.history[this.history.length - 1].created = ids.slice();
        }
    };

    /**
     * Take elements out of every snapshot that still mentions them.
     *
     * Once an element is gone from the server, an older snapshot holding it
     * would put a ghost back on the canvas: it would draw, it would be saved,
     * the save would skip it, and it would disappear again on the next reload.
     *
     * @param {Array} ids the ids that no longer exist
     * @return {void}
     */
    Designer.prototype.forgetElements = function (ids) {
        var rewrite = function (entry) {
            var kept = JSON.parse(entry.elements).filter(function (element) {
                return ids.indexOf(element.id) === -1;
            });

            return {
                pageid: entry.pageid,
                elements: JSON.stringify(kept),
                created: entry.created.filter(function (id) {
                    return ids.indexOf(id) === -1;
                }),
                // What a step removed is carried through untouched. Those
                // elements are already gone from the server, which is the whole
                // reason the payload is kept, and the ids inside it are dead
                // ones that will never be issued again.
                removed: entry.removed
            };
        };

        this.history = this.history.map(rewrite);
        this.future = this.future.map(rewrite);
    };

    /**
     * Drop anything from the selection that is no longer on the page.
     *
     * Undoing an add takes the element away while it is still selected, and a
     * selection holding an id the design no longer has would send that id to
     * the server on the next delete.
     *
     * @return {void}
     */
    Designer.prototype.pruneSelection = function () {
        var self = this;

        this.selection = this.selection.filter(function (id) {
            return !!self.elementById(id);
        });
    };

    /**
     * Step back one change.
     *
     * @return {void}
     */
    Designer.prototype.undo = function () {
        var entry = this.busy ? null : this.nextStep('history');

        if (!entry) {
            this.syncToolbar();
            return;
        }

        this.applyStep(entry, 'future');
    };

    /**
     * Step forward one change.
     *
     * @return {void}
     */
    Designer.prototype.redo = function () {
        var entry = this.busy ? null : this.nextStep('future');

        if (!entry) {
            this.syncToolbar();
            return;
        }

        this.applyStep(entry, 'history');
    };

    /**
     * The next step on a stack that can still be applied.
     *
     * Every path that removes a page from the design also empties both stacks,
     * so a step for a page that has gone should not arise; a step that describes
     * nothing that exists any more is dropped rather than applied to the wrong
     * page, which is the failure this whole change is about.
     *
     * @param {string} stack history or future
     * @return {Object|null} the step, or null when the stack has nothing usable
     */
    Designer.prototype.nextStep = function (stack) {
        while (this[stack].length) {
            var entry = this[stack].pop();

            if (this.pageIndexOf(entry.pageid) !== -1) {
                return entry;
            }
        }

        return null;
    };

    /**
     * Put the page into the state a step describes, and record the way back.
     *
     * Undo and redo are the same journey in opposite directions, so they are the
     * same code: applying a step also builds the step that returns to where the
     * page was, and pushes it onto the stack the author came from. Whichever
     * direction is being travelled, an element the step adds has to be written on
     * the server and an element it takes away has to be deleted there, because
     * save_layout only ever updates elements the server already holds.
     *
     * The server is asked first and the canvas is redrawn afterwards. Doing it
     * the other way round would leave the page showing ids that no longer exist
     * for as long as the request took, and the autosave would send them.
     *
     * The stacks are named rather than handed over. Deleting on the server
     * calls forgetElements(), which rewrites both stacks into fresh arrays, so
     * a step pushed onto an array captured before that point would be pushed
     * onto an array nothing reads any more: redoing a delete quietly lost the
     * way back to it, and the next undo skipped a change.
     *
     * @param {Object} entry the step to apply
     * @param {string} opposite the stack the way back belongs on, history or future
     * @return {void}
     */
    Designer.prototype.applyStep = function (entry, opposite) {
        var self = this;
        var index = this.pageIndexOf(entry.pageid);
        var target = this.template.pages[index];
        var back = this.snapshot(target);
        var elements = JSON.parse(entry.elements);
        var came = opposite === 'future' ? 'history' : 'future';
        var crossed = index !== this.pageIndex;

        var finish = function () {
            self.busy = false;
            target.elements = elements;
            self[opposite].push(back);

            // A step is applied to the page it was recorded on, and the author
            // is taken there to see it happen. Undoing a change on a page that
            // is not on screen, silently, would be worse than not undoing it:
            // the canvas would not move and the design would be different.
            if (crossed) {
                self.pageIndex = index;
            }

            self.pruneSelection();
            self.render();
            self.syncToolbar();
            self.queueSave();
            self.announceStep(opposite === 'future', crossed);
        };

        var failed = function (error) {
            // The step did not happen, so it belongs back where it came from:
            // the author's next undo should try it again rather than skip it.
            self.busy = false;
            self[came].push(entry);
            self.syncToolbar();
            self.setSaveState('savefailed');
            Notification.exception(error);
        };

        if (entry.removed) {
            // The step puts back elements a delete took away. They come back as
            // new rows under new ids, so the ids in the snapshot are dead and
            // have to be swapped for the ones the server has just issued.
            this.busy = true;

            this.restoreOnServer(entry.removed.payload).then(function (restored) {
                var pairs = self.matchRestored(elements, entry.removed.ids, restored);

                elements = self.reconcile(elements, entry.removed.ids, pairs);
                back.created = restored.map(function (element) {
                    return element.id;
                });

                finish();
                return true;
            }).catch(failed);

            return;
        }

        if (entry.created.length) {
            // The step takes away elements a previous one added or restored.
            // What comes back from the delete is what makes the journey back
            // possible: it is the only copy of those elements there now is.
            this.busy = true;

            this.deleteOnServer(entry.created).then(function (payload) {
                back.removed = {payload: payload, ids: entry.created.slice()};
                finish();
                return true;
            }).catch(failed);

            return;
        }

        finish();
    };

    /**
     * Say that a step was taken, and where it was taken.
     *
     * Naming the page matters only when the step moved the author to one: undo
     * that redraws the page they were already looking at needs no explanation.
     *
     * @param {boolean} undone true for an undo, false for a redo
     * @param {boolean} crossed whether the step changed which page is on screen
     * @return {void}
     */
    Designer.prototype.announceStep = function (undone, crossed) {
        var self = this;
        var key = undone ? 'designer_announceundone' : 'designer_announceredone';

        if (!crossed) {
            this.announce(key);
            return;
        }

        Str.get_strings([
            {key: key, component: 'mod_certificatepro'},
            {key: 'designer_announcepage', component: 'mod_certificatepro',
             param: {number: this.pageIndex + 1, total: (this.template.pages || []).length}}
        ]).then(function (strings) {
            self.say(strings[0] + ' ' + strings[1]);
            return strings;
        }).catch(Notification.exception);
    };

    /**
     * Work out which restored element replaces which deleted one.
     *
     * Restoring writes new rows, so every element comes back wearing a new id
     * while the snapshot still describes it by the old one. The service works
     * through the payload in order and drops only what it cannot trust, so the
     * two lists are walked together and each restored element is matched to the
     * id it was deleted under.
     *
     * @param {Array} elements the snapshot's elements, holding the old ids
     * @param {Array} ids the ids the elements were deleted under, in payload order
     * @param {Array} restored the elements the server has just written
     * @return {Array} objects of fromid and the restored element
     */
    Designer.prototype.matchRestored = function (elements, ids, restored) {
        var types = {};
        var pairs = [];
        var next = 0;

        elements.forEach(function (element) {
            types[element.id] = element.type;
        });

        ids.forEach(function (id) {
            var candidate = restored[next];

            // The type is checked as well as the order, so that a payload the
            // service partly refused cannot quietly shift every element that
            // follows onto the wrong id.
            if (candidate && candidate.type === types[id]) {
                pairs.push({fromid: id, element: candidate});
                next++;
            }
        });

        return pairs;
    };

    /**
     * Swap restored elements into a snapshot in place of the ids they replace.
     *
     * An element the service refused has no pair, so it simply does not come
     * back: the snapshot's copy of it goes rather than being drawn as something
     * the server does not have.
     *
     * @param {Array} elements the snapshot's elements, holding the old ids
     * @param {Array} ids the ids the elements were deleted under
     * @param {Array} pairs the pairing from {@see Designer#matchRestored}
     * @return {Array} the elements as they now are
     */
    Designer.prototype.reconcile = function (elements, ids, pairs) {
        var replacement = {};

        pairs.forEach(function (pair) {
            replacement[pair.fromid] = pair.element;
        });

        return elements.map(function (element) {
            return replacement[element.id] || element;
        }).filter(function (element) {
            return ids.indexOf(element.id) === -1;
        });
    };

    // ------------------------------------------------------------ persistence.

    /**
     * Save shortly after the last change.
     *
     * @return {void}
     */
    Designer.prototype.queueSave = function () {
        var self = this;
        var page = this.page();

        this.flushPending(page);

        this.savePage = page;
        this.setStatus(this.strings.saving || '');
        this.setSaveState('saving');

        this.saveTimer = window.setTimeout(function () {
            self.saveTimer = null;
            self.savePage = null;
            self.save(page);
        }, SAVE_DELAY);
    };

    /**
     * Deal with an autosave that has not gone yet.
     *
     * A save describes one page's rows. Undo now moves between pages, so a save
     * waiting for page one can be followed by a change to page two, and the
     * second is not a newer version of the first: replacing it would drop the
     * change to page one on the floor. A save for another page is sent at once
     * instead of being thrown away.
     *
     * @param {Object|null} page the page about to be queued, or null for any page
     * @return {void}
     */
    Designer.prototype.flushPending = function (page) {
        var pending = this.savePage;

        if (!this.saveTimer) {
            return;
        }

        window.clearTimeout(this.saveTimer);
        this.saveTimer = null;
        this.savePage = null;

        if (pending && (!page || pending.id !== page.id)) {
            this.save(pending);
        }
    };

    /**
     * Save at once, without waiting for the autosave to come round.
     *
     * @return {Promise} resolves when the save has been acknowledged
     */
    Designer.prototype.saveNow = function () {
        if (!this.page()) {
            return Promise.resolve(false);
        }

        this.flushPending(this.page());

        this.setStatus(this.strings.saving || '');
        this.setSaveState('saving');

        return this.save();
    };

    /**
     * Write the current page's geometry back to the server.
     *
     * The promise is returned so that anything which is about to reload the
     * page — the design libraries, for one — can wait for the last drag to
     * land instead of throwing it away.
     *
     * The page is named rather than looked up, because the page an autosave was
     * queued for is not always the page on screen by the time it fires: an undo
     * that steps back to a change on another page takes the author with it.
     *
     * @param {Object} [page] the page to write, defaulting to the one on screen
     * @return {Promise} resolves when the save has been acknowledged
     */
    Designer.prototype.save = function (page) {
        var self = this;

        page = page || this.page();

        var payload = page.elements.map(function (element) {
            return {
                id: element.id,
                posx: element.posx,
                posy: element.posy,
                width: element.width,
                height: element.height,
                rotation: element.rotation || 0,
                refpoint: element.refpoint,
                alignment: element.alignment,
                valignment: element.valignment,
                zoneid: element.zoneid || '',
                zindex: element.zindex || 0,
                hidden: element.hidden ? 1 : 0,
                locked: element.locked ? 1 : 0
            };
        });

        return Ajax.call([{
            methodname: 'mod_certificatepro_save_layout',
            args: {
                templateid: this.template.id,
                pageid: page.id,
                elements: payload
            }
        }])[0].then(function (response) {
            self.applyRedrawn(page, response);
            self.setStatus(self.strings.saved || '');
            self.setSaveState('saved');
            return true;
        }).catch(function (error) {
            self.setStatus(self.strings.savefailed || '');
            self.setSaveState('savefailed');
            Notification.exception(error);
        });
    };

    /**
     * Put the markup the save drew again onto the canvas.
     *
     * The drawing the browser scaled during the drag is a stand in, good enough
     * to keep the box and its contents together while the pointer is moving. The
     * markup that comes back from the save is the real thing: the server has
     * re-fitted the type against the same PDF text metrics the print uses and
     * re-drawn the artwork at the size the print will draw it. Swapping it in is
     * what makes what the author is looking at after a resize identical to what
     * they would see after a reload, and identical to what will print.
     *
     * Markup is only accepted for an element that still has the box it was drawn
     * for. A save is in flight for the length of a request, and an author can
     * start another drag inside that: putting a drawing sized for the old box
     * back onto an element that has since moved on would undo, for a moment,
     * the very thing this is here to fix. There is always a later save queued
     * behind such a change, and its markup is the one that will stick.
     *
     * @param {Object} page the page the save was for
     * @param {Object} response what the save returned
     * @return {void}
     */
    Designer.prototype.applyRedrawn = function (page, response) {
        var self = this;
        var redrawn;

        if (!response || !response.elements) {
            return;
        }

        try {
            redrawn = JSON.parse(response.elements);
        } catch (error) {
            // Nothing here is worth an error dialogue: the canvas keeps the
            // drawing it scaled itself, which is close, and the next save will
            // offer the real one again.
            return;
        }

        if (!Array.isArray(redrawn)) {
            return;
        }

        redrawn.forEach(function (record) {
            var element = null;

            page.elements.forEach(function (candidate) {
                if (candidate.id === record.id) {
                    element = candidate;
                }
            });

            if (!element || !self.fitIsCurrent(element, record.canvasfit)) {
                return;
            }

            element.html = record.html;
            element.canvasfit = record.canvasfit;

            // The node is only found when the author is still looking at this
            // page; the model above is updated either way, so switching back to
            // the page draws the new markup rather than the old.
            var node = self.nodeFor(element.id);
            var content = node ? node.querySelector('.cpro-element-content') : null;

            if (content) {
                content.innerHTML = record.html;
                self.rescaleDrawing(element, node);
            }
        });
    };

    /**
     * Whether markup drawn for a box still describes the box an element has.
     *
     * @param {Object} element the element record
     * @param {Object} fit the fit descriptor the markup was exported with
     * @return {boolean} true when the markup is still the current one
     */
    Designer.prototype.fitIsCurrent = function (element, fit) {
        if (!fit) {
            return false;
        }

        // Millimetres are stored to three decimal places, so anything closer
        // than half of the last of them is the same box.
        var same = function (a, b) {
            return Math.abs((a || 0) - (b || 0)) < 0.0005;
        };

        return same(element.width, fit.boxwidth)
            && same(element.height, fit.boxheight)
            && (element.refpoint || '') === (fit.refpoint || '')
            && (element.alignment || '') === (fit.alignment || '')
            && (element.valignment || '') === (fit.valignment || '');
    };

    /**
     * Show a short message in the status bar.
     *
     * @param {string} message the message to show
     * @return {void}
     */
    Designer.prototype.setStatus = function (message) {
        if (this.statusbar) {
            this.statusbar.textContent = message;
        }
    };

    /**
     * Say whether the design is saved, saving or in trouble.
     *
     * The status bar reports on the drag in progress and is overwritten
     * constantly; this is the one place that only ever talks about saving, which
     * is why it is what the Save button reports into.
     *
     * @param {string} state saving, saved or savefailed
     * @return {void}
     */
    Designer.prototype.setSaveState = function (state) {
        if (!this.saveIndicator) {
            return;
        }

        this.saveIndicator.setAttribute('data-state', state);
        this.saveIndicator.textContent = this.strings[state] || '';
    };

    /**
     * Describe what is happening during a drag.
     *
     * @param {Object|null} zone the zone that would capture on release
     * @return {void}
     */
    Designer.prototype.updateStatus = function (zone) {
        if (!this.statusbar) {
            return;
        }

        if (zone) {
            this.statusbar.textContent = (this.strings.snapped || '') + ' ' + zone.label;
            this.statusbar.classList.add('cpro-status-snapped');
        } else {
            var element = this.drag ? this.drag.element : null;
            this.statusbar.classList.remove('cpro-status-snapped');
            if (element) {
                this.statusbar.textContent = element.posx.toFixed(1) + ' mm, '
                    + element.posy.toFixed(1) + ' mm';
            }
        }
    };

    // ------------------------------------------------------- panels and picker.

    /**
     * Draw the layers panel.
     *
     * @return {void}
     */
    Designer.prototype.renderLayers = function () {
        if (!this.layers) {
            return;
        }

        var self = this;
        var page = this.page();
        var list = document.createElement('ul');

        list.className = 'cpro-layer-list';
        list.setAttribute('role', 'list');

        page.elements.slice().reverse().forEach(function (element) {
            var item = document.createElement('li');
            item.className = 'cpro-layer-item'
                + (self.selection.indexOf(element.id) !== -1 ? ' cpro-layer-selected' : '');

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cpro-layer-name';
            button.setAttribute('data-action', 'selectelement');
            button.setAttribute('data-value', String(element.id));
            // The row says whether it is the selected one. Without it the only
            // sign is a background colour, which a screen reader cannot see.
            button.setAttribute('aria-pressed',
                self.selection.indexOf(element.id) === -1 ? 'false' : 'true');
            // The name is the author's own text and goes in as text, never as
            // markup: an element named with a tag would otherwise run as script
            // in the next person's browser the moment they opened the design.
            var layericon = document.createElement('i');
            layericon.className = 'fa ' + String(element.icon || '').replace(/[^\w-]/g, '');
            layericon.setAttribute('aria-hidden', 'true');
            var layername = document.createElement('span');
            layername.textContent = element.name;
            button.appendChild(layericon);
            button.appendChild(document.createTextNode(' '));
            button.appendChild(layername);
            item.appendChild(button);

            var visibility = document.createElement('button');
            visibility.type = 'button';
            visibility.className = 'cpro-layer-toggle';
            visibility.setAttribute('data-action', 'togglevisible');
            visibility.setAttribute('data-value', String(element.id));
            visibility.innerHTML = '<i class="fa ' + (element.hidden ? 'fa-eye-slash' : 'fa-eye')
                + '" aria-hidden="true"></i>';
            // The icon is hidden from assistive technology, which left these two
            // controls with no name at all: a screen reader read the whole
            // layers panel as "button, button, button".
            self.label(visibility, element.hidden ? 'designer_showelement'
                : 'designer_hideelement', element.name, ['title', 'aria-label']);
            item.appendChild(visibility);

            var lock = document.createElement('button');
            lock.type = 'button';
            lock.className = 'cpro-layer-toggle';
            lock.setAttribute('data-action', 'togglelock');
            lock.setAttribute('data-value', String(element.id));
            lock.innerHTML = '<i class="fa ' + (element.locked ? 'fa-lock' : 'fa-lock-open')
                + '" aria-hidden="true"></i>';
            self.label(lock, element.locked ? 'designer_unlockelement'
                : 'designer_lockelement', element.name, ['title', 'aria-label']);
            item.appendChild(lock);

            list.appendChild(item);
        });

        this.preservingFocus(function () {
            self.layers.innerHTML = '';
            self.layers.appendChild(list);
        });
    };

    /**
     * Draw the inspector for the current selection.
     *
     * @return {void}
     */
    Designer.prototype.renderInspector = function () {
        if (!this.inspector) {
            return;
        }

        var self = this;

        if (this.selection.length !== 1) {
            this.inspector.setAttribute('data-count', String(this.selection.length));

            // The figures below describe one element, so with several selected
            // they described whichever one happened to be selected last — a set
            // of numbers that belonged to nothing on screen. The panel says how
            // many are held instead.
            var multiple = this.inspector.querySelector('[data-field="name"]');
            if (multiple) {
                multiple.textContent = this.selection.length
                    ? (this.strings.selectedcount || '').replace('{$a}', this.selection.length)
                    : '';
            }
            var staleZone = this.inspector.querySelector('[data-field="zone"]');
            if (staleZone) {
                staleZone.textContent = '';
            }
            return;
        }

        var element = this.elementById(this.selection[0]);
        if (!element) {
            return;
        }

        this.inspector.setAttribute('data-count', '1');
        this.inspector.setAttribute('data-element-id', String(element.id));

        ['posx', 'posy', 'width', 'height', 'rotation'].forEach(function (field) {
            var input = self.inspector.querySelector('[data-field="' + field + '"]');
            if (input) {
                input.value = (element[field] || 0).toFixed(2);
            }
        });

        var zoneLabel = this.inspector.querySelector('[data-field="zone"]');
        if (zoneLabel) {
            var zone = null;
            this.page().zones.forEach(function (candidate) {
                if (candidate.id === element.zoneid) {
                    zone = candidate;
                }
            });
            zoneLabel.textContent = zone ? zone.label : (this.strings.free || '');
        }

        var nameField = this.inspector.querySelector('[data-field="name"]');
        if (nameField) {
            nameField.textContent = element.name;
        }
    };

    /**
     * Let the element picker drop new elements onto the canvas.
     *
     * @return {void}
     */
    Designer.prototype.bindPicker = function () {
        var self = this;
        var picker = this.root.querySelector('[data-region="picker"]');

        if (!picker) {
            return;
        }

        picker.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-element-type]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            self.addElement(trigger.getAttribute('data-element-type'),
                trigger.getAttribute('data-zone') || null);
        });
    };

    /**
     * Ask the server for a new element and draw it.
     *
     * @param {string} type the element type identifier
     * @param {string|null} zoneid the zone to dock into
     * @return {void}
     */
    Designer.prototype.addElement = function (type, zoneid) {
        var self = this;

        Ajax.call([{
            methodname: 'mod_certificatepro_add_element',
            args: {
                templateid: this.template.id,
                pageid: this.page().id,
                elementtype: type,
                zoneid: zoneid || ''
            }
        }])[0].then(function (response) {
            var element = JSON.parse(response.element);

            // Recorded here rather than before the call, so that a request that
            // fails does not leave a step on the stack that undoes nothing.
            self.pushHistory();
            self.page().elements.push(element);
            self.markCreated([element.id]);

            self.renderElements();
            self.renderLayers();
            self.select([element.id]);
            return true;
        }).catch(Notification.exception);
    };

    /**
     * Copy the selection, offset slightly so the copy is visible.
     *
     * @return {void}
     */
    Designer.prototype.duplicateSelection = function () {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        Ajax.call([{
            methodname: 'mod_certificatepro_duplicate_elements',
            args: {
                templateid: this.template.id,
                pageid: this.page().id,
                elementids: this.selection
            }
        }])[0].then(function (response) {
            var created = JSON.parse(response.elements);
            var ids = created.map(function (element) {
                return element.id;
            });

            self.pushHistory();
            created.forEach(function (element) {
                self.page().elements.push(element);
            });
            self.markCreated(ids);

            self.renderElements();
            self.renderLayers();
            self.select(ids);
            return true;
        }).catch(Notification.exception);
    };

    /**
     * Remove the selection, once the author has said so.
     *
     * Deleting can be taken back. The delete service hands the elements it
     * removed to the browser on the way out, and restore_elements writes them
     * again from that payload, so undo has everything it needs: the text, the
     * styling, the position and the zone all come back as they were. Only the
     * ids change, because the rows are new ones.
     *
     * An uploaded picture comes back too. The design no longer destroys an
     * element's files when the element goes, so the picture outlives the row
     * that pointed at it and is handed to the new one once the restore has said
     * what that row's id is.
     *
     * Deleting is still worth confirming: an undo lives in this tab and nowhere
     * else, so a reload, a crash or a second author is the end of it.
     *
     * @return {void}
     */
    Designer.prototype.deleteSelection = function () {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        var ids = this.selection.slice();

        this.confirmDelete(ids).then(function () {
            return self.deleteElements(ids);
        }).catch(function () {
            // Cancelling, pressing Escape and clicking away all reject with
            // nothing to report, and a real failure has already been shown.
            return false;
        });
    };

    /**
     * Ask whether the elements really are to go.
     *
     * @param {Array} ids the element ids about to be deleted
     * @return {Promise} resolves when the author confirms, rejects otherwise
     */
    Designer.prototype.confirmDelete = function (ids) {
        var trigger = this.root.querySelector('[data-action="delete"]');
        var carriesfile = this.selectionCarriesFile(ids);

        var wanted = [
            {key: 'designer_delete', component: 'mod_certificatepro'},
            {key: 'designer_deleteconfirm', component: 'mod_certificatepro', param: ids.length}
        ];

        if (carriesfile) {
            wanted.push({key: 'designer_deletefiles', component: 'mod_certificatepro'});
        }

        return Str.get_strings(wanted).then(function (strings) {
            var question = carriesfile ? strings[1] + ' ' + strings[2] : strings[1];

            return Notification.saveCancelPromise(strings[0], question, strings[0],
                {triggerElement: trigger});
        });
    };

    /**
     * Whether any of these elements owns an uploaded file.
     *
     * @param {Array} ids the element ids about to be deleted
     * @return {boolean} true when a picture would be destroyed
     */
    Designer.prototype.selectionCarriesFile = function (ids) {
        var self = this;

        return ids.some(function (id) {
            var element = self.elementById(id);

            return !!element && self.filetypes.indexOf(element.type) !== -1;
        });
    };

    /**
     * Delete elements on the server and take them off the page.
     *
     * @param {Array} ids the element ids to delete
     * @return {Promise} resolves when the elements have gone
     */
    Designer.prototype.deleteElements = function (ids) {
        var self = this;
        var before = this.snapshot();

        return this.deleteOnServer(ids).then(function (payload) {
            var page = self.page();

            // Recorded after the delete rather than before it, because
            // forgetElements() has just taken these ids out of every step on the
            // stacks and this is the one step that has to keep them: they are
            // what the restored elements are matched against.
            before.removed = {payload: payload, ids: ids.slice()};
            self.pushStep(before);

            page.elements = page.elements.filter(function (element) {
                return ids.indexOf(element.id) === -1;
            });
            self.select([], true);
            self.renderElements();
            self.renderLayers();
            self.ensureFocus();
            self.announce('designer_announcedeleted', ids.length);
            return true;
        });
    };

    /**
     * Delete elements on the server, and forget they were ever on the page.
     *
     * What the service returns is the only copy of those elements there now is,
     * so it is handed to the caller rather than dropped: without it an undo has
     * nothing to put back.
     *
     * @param {Array} ids the element ids to delete
     * @return {Promise} resolves with the deleted elements, as JSON
     */
    Designer.prototype.deleteOnServer = function (ids) {
        var self = this;

        return Ajax.call([{
            methodname: 'mod_certificatepro_delete_elements',
            args: {
                templateid: this.template.id,
                elementids: ids
            }
        }])[0].then(function (response) {
            self.forgetElements(ids);
            return response.elements;
        }).catch(function (error) {
            // Shown here, and thrown on: a caller that was about to take these
            // elements off the page must not do it when the server still has
            // them, and the reason has already reached the author.
            Notification.exception(error);
            throw error;
        });
    };

    /**
     * Ask the server to write deleted elements back.
     *
     * @param {string} payload the elements to restore, as delete_elements returned them
     * @return {Promise} resolves with the restored elements, carrying their new ids
     */
    Designer.prototype.restoreOnServer = function (payload) {
        return Ajax.call([{
            methodname: 'mod_certificatepro_restore_elements',
            args: {
                templateid: this.template.id,
                payload: payload
            }
        }])[0].then(function (response) {
            return JSON.parse(response.elements);
        });
    };

    return {
        /**
         * Start the designer.
         *
         * @param {string} selector a selector for the designer root
         * @param {Object} config the bootstrap configuration
         * @return {void}
         */
        init: function (selector) {
            var root = document.querySelector(selector);
            if (root) {
                var config;
                try {
                    config = JSON.parse(root.getAttribute('data-cpro-bootstrap') || '{}');
                } catch (e) {
                    window.console.error('certificatepro: unreadable bootstrap payload', e);
                    config = {};
                }

                // The design libraries need to know which page is on screen and
                // need the chance to flush a pending save before they reload.
                root.cproDesigner = new Designer(root, config);
            }
        }
    };
});

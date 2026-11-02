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
function($, Ajax, Notification, Str) {

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

    /**
     * The designer.
     *
     * @param {HTMLElement} root the designer root element
     * (the bootstrap payload is read from the root element)
     */
    var Designer = function(root, config) {
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
        this.strings = {};

        this.canvas = root.querySelector('[data-region="canvas"]');
        this.stage = root.querySelector('[data-region="stage"]');
        this.overlay = root.querySelector('[data-region="overlay"]');
        this.layers = root.querySelector('[data-region="layers"]');
        this.inspector = root.querySelector('[data-region="inspector"]');
        this.statusbar = root.querySelector('[data-region="status"]');

        this.init();
    };

    /**
     * Wire everything up and draw the first page.
     *
     * @return {void}
     */
    Designer.prototype.init = function() {
        var self = this;

        Str.get_strings([
            {key: 'designer_snapped', component: 'mod_certificatepro'},
            {key: 'designer_saved', component: 'mod_certificatepro'},
            {key: 'designer_saving', component: 'mod_certificatepro'},
            {key: 'designer_savefailed', component: 'mod_certificatepro'},
            {key: 'designer_selected', component: 'mod_certificatepro'},
            {key: 'designer_free', component: 'mod_certificatepro'}
        ]).then(function(strings) {
            self.strings = {
                snapped: strings[0],
                saved: strings[1],
                saving: strings[2],
                savefailed: strings[3],
                selected: strings[4],
                free: strings[5]
            };
            return strings;
        }).catch(Notification.exception);

        this.bindToolbar();
        this.bindCanvas();
        this.bindKeyboard();
        this.bindPicker();
        this.render();
    };

    /**
     * The page currently being edited.
     *
     * @return {Object} the page record
     */
    Designer.prototype.page = function() {
        return this.template.pages[this.pageIndex];
    };

    /**
     * How many screen pixels represent one millimetre at the current zoom.
     *
     * @return {number} pixels per millimetre
     */
    Designer.prototype.scale = function() {
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
    Designer.prototype.px = function(mm) {
        return mm * this.scale();
    };

    /**
     * Convert pixels to millimetres.
     *
     * @param {number} px the value in pixels
     * @return {number} the value in millimetres
     */
    Designer.prototype.mm = function(px) {
        return px / this.scale();
    };

    /**
     * Round a millimetre value to the precision the database stores.
     *
     * @param {number} value the value to round
     * @return {number} the rounded value
     */
    Designer.prototype.round = function(value) {
        return Math.round(value * 1000) / 1000;
    };

    // ---------------------------------------------------------------- drawing.

    /**
     * Draw the whole designer from the current model.
     *
     * @return {void}
     */
    Designer.prototype.render = function() {
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
    Designer.prototype.renderStage = function() {
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
    Designer.prototype.renderElements = function() {
        var self = this;
        var page = this.page();
        var host = this.stage.querySelector('[data-region="elements"]');

        if (!page || !host) {
            return;
        }

        host.innerHTML = '';

        page.elements.forEach(function(element) {
            host.appendChild(self.buildElementNode(element));
        });

        this.refreshSelectionVisuals();
    };

    /**
     * Build the node for one element.
     *
     * @param {Object} element the element record
     * @return {HTMLElement} the node
     */
    Designer.prototype.buildElementNode = function(element) {
        var box = this.boxFor(element);
        var node = document.createElement('div');

        node.className = 'cpro-element';
        node.setAttribute('data-element-id', element.id);
        node.setAttribute('data-type', element.type);
        node.setAttribute('tabindex', '0');
        node.setAttribute('role', 'button');
        node.setAttribute('aria-label', element.name);

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
        }

        var content = document.createElement('div');
        content.className = 'cpro-element-content';
        // Render physical pt/mm units at their CSS reference size, then scale
        // the whole content with its selection box. Scaling only the box
        // clipped fixed-size text whenever the canvas fitted a narrow screen.
        var pixelsPerMm = 96 / 25.4;
        content.style.width = (box.width * pixelsPerMm) + 'px';
        content.style.height = (box.height * pixelsPerMm) + 'px';
        content.style.transformOrigin = 'top left';
        content.style.transform = 'scale(' + (this.scale() / pixelsPerMm) + ')';
        content.innerHTML = element.html || '';
        node.appendChild(content);

        var handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
        handles.forEach(function(position) {
            var handle = document.createElement('span');
            handle.className = 'cpro-handle cpro-handle-' + position;
            handle.setAttribute('data-handle', position);
            node.appendChild(handle);
        });

        var rotate = document.createElement('span');
        rotate.className = 'cpro-handle cpro-handle-rotate';
        rotate.setAttribute('data-handle', 'rotate');
        node.appendChild(rotate);

        return node;
    };

    /**
     * The top left corner and size of an element, in millimetres.
     *
     * @param {Object} element the element record
     * @return {Object} left, top, width and height in millimetres
     */
    Designer.prototype.boxFor = function(element) {
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
    Designer.prototype.anchorFor = function(element, box) {
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
    Designer.prototype.candidateZones = function(element, box) {
        var page = this.page();
        var centreX = box.left + (box.width / 2);
        var centreY = box.top + (box.height / 2);
        var candidates = [];

        page.zones.forEach(function(zone) {
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

        candidates.sort(function(a, b) {
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
    Designer.prototype.paintZones = function(candidates, active) {
        var self = this;

        this.clearLayer('zones');

        candidates.forEach(function(candidate) {
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
    Designer.prototype.applyGuides = function(element, box) {
        var page = this.page();
        var result = {box: {left: box.left, top: box.top, width: box.width, height: box.height},
            vertical: [], horizontal: []};

        var verticals = [];
        var horizontals = [];

        page.guides.vertical.forEach(function(guide) {
            verticals.push({pos: guide.pos, type: guide.type, strength: guide.strength});
        });
        page.guides.horizontal.forEach(function(guide) {
            horizontals.push({pos: guide.pos, type: guide.type, strength: guide.strength});
        });

        // Every other element's edges and centres are guides too.
        var self = this;
        page.elements.forEach(function(other) {
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
        candidatesX.forEach(function(candidate) {
            verticals.forEach(function(guide) {
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
        candidatesY.forEach(function(candidate) {
            horizontals.forEach(function(guide) {
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
    Designer.prototype.paintGuides = function(vertical, horizontal) {
        var self = this;

        this.clearLayer('guides');

        vertical.forEach(function(guide) {
            var node = document.createElement('div');
            node.className = 'cpro-guide cpro-guide-v cpro-guide-' + guide.type;
            node.style.left = self.px(guide.pos) + 'px';
            self.layer('guides').appendChild(node);
        });

        horizontal.forEach(function(guide) {
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
    Designer.prototype.layer = function(name) {
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
    Designer.prototype.clearLayer = function(name) {
        this.layer(name).innerHTML = '';
    };

    // ------------------------------------------------------------- interaction.

    /**
     * Listen for pointer activity on the canvas.
     *
     * @return {void}
     */
    Designer.prototype.bindCanvas = function() {
        var self = this;

        this.stage.addEventListener('pointerdown', function(event) {
            var handle = event.target.closest('[data-handle]');
            var node = event.target.closest('.cpro-element');

            if (!node) {
                if (!event.shiftKey) {
                    self.select([]);
                }
                return;
            }

            var id = parseInt(node.getAttribute('data-element-id'), 10);
            var element = self.elementById(id);

            if (!element || element.locked) {
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

        window.addEventListener('pointermove', function(event) {
            self.continueDrag(event);
        });

        window.addEventListener('pointerup', function(event) {
            self.endDrag(event);
        });

        window.addEventListener('resize', function() {
            self.render();
        });
    };

    /**
     * Start a move, resize or rotate.
     *
     * @param {PointerEvent} event the pointer event
     * @param {Object} element the element under the pointer
     * @param {string} mode move, rotate or a resize handle name
     * @return {void}
     */
    Designer.prototype.beginDrag = function(event, element, mode) {
        var self = this;
        var rect = this.stage.getBoundingClientRect();

        this.pushHistory();

        this.drag = {
            mode: mode,
            element: element,
            startX: event.clientX,
            startY: event.clientY,
            stageRect: rect,
            origins: {}
        };

        this.selection.forEach(function(id) {
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
    Designer.prototype.continueDrag = function(event) {
        if (!this.drag) {
            return;
        }

        var self = this;
        var dx = this.mm(event.clientX - this.drag.startX);
        var dy = this.mm(event.clientY - this.drag.startY);
        var element = this.drag.element;
        var origin = this.drag.origins[element.id];

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
            proposed = this.resizeBox(origin, this.drag.mode, dx, dy, event.shiftKey);
        }

        var guided = event.altKey ? {box: proposed, vertical: [], horizontal: []}
            : this.applyGuides(element, proposed);

        var candidates = this.drag.mode === 'move' && !event.altKey
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

        this.selection.forEach(function(id) {
            var selected = self.elementById(id);
            var selectedOrigin = self.drag.origins[id];
            if (!selected || !selectedOrigin) {
                return;
            }

            var box;
            if (id === element.id) {
                box = guided.box;
            } else if (self.drag.mode === 'move') {
                box = {
                    left: selectedOrigin.left + deltaX,
                    top: selectedOrigin.top + deltaY,
                    width: selectedOrigin.width,
                    height: selectedOrigin.height
                };
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
     * @return {Object} the proposed box
     */
    Designer.prototype.resizeBox = function(origin, handle, dx, dy, keepRatio) {
        var box = {
            left: origin.left,
            top: origin.top,
            width: origin.width,
            height: origin.height
        };

        if (handle.indexOf('w') !== -1) {
            box.left = origin.left + dx;
            box.width = origin.width - dx;
        }
        if (handle.indexOf('e') !== -1) {
            box.width = origin.width + dx;
        }
        if (handle.indexOf('n') !== -1) {
            box.top = origin.top + dy;
            box.height = origin.height - dy;
        }
        if (handle.indexOf('s') !== -1) {
            box.height = origin.height + dy;
        }

        box.width = Math.max(3, box.width);
        box.height = Math.max(3, box.height);

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
    Designer.prototype.applyRotation = function(event, element, origin) {
        var rect = this.drag.stageRect;
        var centreX = rect.left + this.px(origin.left + (origin.width / 2));
        var centreY = rect.top + this.px(origin.top + (origin.height / 2));
        var angle = Math.atan2(event.clientY - centreY, event.clientX - centreX);
        var degrees = (angle * 180 / Math.PI) + 90;

        if (event.shiftKey) {
            degrees = Math.round(degrees / 15) * 15;
        }

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
    Designer.prototype.applyBox = function(element, box) {
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
    };

    /**
     * Finish a drag, docking into a zone if one captured the element.
     *
     * @return {void}
     */
    Designer.prototype.endDrag = function() {
        if (!this.drag) {
            return;
        }

        var element = this.drag.element;
        var result = this.drag.result;

        if (result && result.zone) {
            this.dockIntoZone(element, result.zone);
        } else if (result && result.box) {
            element.zoneid = null;
        }

        this.clearLayer('zones');
        this.clearLayer('guides');
        this.root.classList.remove('cpro-dragging');
        this.drag = null;

        this.renderInspector();
        this.queueSave();
    };

    /**
     * Snap an element into a zone, adopting its anchoring and alignment.
     *
     * @param {Object} element the element to dock
     * @param {Object} zone the zone to dock into
     * @return {void}
     */
    Designer.prototype.dockIntoZone = function(element, zone) {
        var width = Math.min(element.width > 0 ? element.width : zone.w, zone.w);
        var height = Math.min(element.height > 0 ? element.height : zone.h, zone.h);

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

    // ------------------------------------------------------------- selection.

    /**
     * The element with the given id on the current page.
     *
     * @param {number} id the element id
     * @return {Object|null} the element record
     */
    Designer.prototype.elementById = function(id) {
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
    Designer.prototype.nodeFor = function(id) {
        return this.stage.querySelector('[data-element-id="' + id + '"]');
    };

    /**
     * Replace the selection.
     *
     * @param {Array} ids the element ids to select
     * @return {void}
     */
    Designer.prototype.select = function(ids) {
        this.selection = ids.slice();
        this.refreshSelectionVisuals();
        this.renderInspector();
        this.renderLayers();
    };

    /**
     * Add or remove one element from the selection.
     *
     * @param {number} id the element id
     * @return {void}
     */
    Designer.prototype.toggleSelection = function(id) {
        var index = this.selection.indexOf(id);
        if (index === -1) {
            this.selection.push(id);
        } else {
            this.selection.splice(index, 1);
        }
        this.refreshSelectionVisuals();
        this.renderInspector();
    };

    /**
     * Mark the selected nodes.
     *
     * @return {void}
     */
    Designer.prototype.refreshSelectionVisuals = function() {
        var self = this;
        var nodes = this.stage.querySelectorAll('.cpro-element');

        Array.prototype.forEach.call(nodes, function(node) {
            var id = parseInt(node.getAttribute('data-element-id'), 10);
            node.classList.toggle('cpro-selected', self.selection.indexOf(id) !== -1);
        });
    };

    // -------------------------------------------------------------- keyboard.

    /**
     * Listen for keyboard shortcuts.
     *
     * @return {void}
     */
    Designer.prototype.bindKeyboard = function() {
        var self = this;

        this.root.addEventListener('keydown', function(event) {
            if (event.target.matches('input, textarea, select')) {
                return;
            }

            var handled = true;
            var step = event.shiftKey ? NUDGE_LARGE : NUDGE;
            var meta = event.metaKey || event.ctrlKey;

            switch (event.key) {
                case 'ArrowLeft':
                    self.nudge(-step, 0);
                    break;
                case 'ArrowRight':
                    self.nudge(step, 0);
                    break;
                case 'ArrowUp':
                    self.nudge(0, -step);
                    break;
                case 'ArrowDown':
                    self.nudge(0, step);
                    break;
                case 'Delete':
                case 'Backspace':
                    self.deleteSelection();
                    break;
                case 'Escape':
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
                        self.select(self.page().elements.map(function(element) {
                            return element.id;
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
     * Move the selection by a fixed amount.
     *
     * @param {number} dx the horizontal movement in millimetres
     * @param {number} dy the vertical movement in millimetres
     * @return {void}
     */
    Designer.prototype.nudge = function(dx, dy) {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        this.pushHistory();

        this.selection.forEach(function(id) {
            var element = self.elementById(id);
            if (!element || element.locked) {
                return;
            }
            var box = self.boxFor(element);
            box.left += dx;
            box.top += dy;
            element.zoneid = null;
            self.applyBox(element, box);
        });

        this.renderInspector();
        this.queueSave();
    };

    // --------------------------------------------------------------- toolbar.

    /**
     * Listen for toolbar actions.
     *
     * @return {void}
     */
    Designer.prototype.bindToolbar = function() {
        var self = this;

        this.root.addEventListener('click', function(event) {
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
                    self.pageIndex = parseInt(value, 10);
                    self.select([]);
                    self.render();
                    break;
                default:
                    break;
            }

            if (action !== 'selectelement') {
                event.preventDefault();
            }
        });
    };

    /**
     * Show every zone at once, so an author can see the grid they are working to.
     *
     * @return {void}
     */
    Designer.prototype.previewZones = function() {
        if (!this.root.classList.contains('cpro-show-zones')) {
            this.clearLayer('zones');
            return;
        }

        var self = this;
        var page = this.page();

        this.clearLayer('zones');

        page.zones.forEach(function(zone) {
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
    Designer.prototype.align = function(how) {
        var self = this;
        var page = this.page();

        if (!this.selection.length) {
            return;
        }

        this.pushHistory();

        var boxes = this.selection.map(function(id) {
            return {id: id, box: self.boxFor(self.elementById(id))};
        });

        var bounds;
        if (boxes.length === 1) {
            bounds = {left: 0, top: 0, right: page.width, bottom: page.height};
        } else {
            bounds = {
                left: Math.min.apply(null, boxes.map(function(entry) {
                    return entry.box.left;
                })),
                top: Math.min.apply(null, boxes.map(function(entry) {
                    return entry.box.top;
                })),
                right: Math.max.apply(null, boxes.map(function(entry) {
                    return entry.box.left + entry.box.width;
                })),
                bottom: Math.max.apply(null, boxes.map(function(entry) {
                    return entry.box.top + entry.box.height;
                }))
            };
        }

        boxes.forEach(function(entry) {
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
    Designer.prototype.distribute = function(axis) {
        var self = this;

        if (this.selection.length < 3) {
            return;
        }

        this.pushHistory();

        var entries = this.selection.map(function(id) {
            return {id: id, box: self.boxFor(self.elementById(id))};
        });

        entries.sort(function(a, b) {
            return axis === 'horizontal' ? a.box.left - b.box.left : a.box.top - b.box.top;
        });

        var first = entries[0].box;
        var last = entries[entries.length - 1].box;

        var span = axis === 'horizontal'
            ? (last.left + last.width) - first.left
            : (last.top + last.height) - first.top;

        var used = entries.reduce(function(total, entry) {
            return total + (axis === 'horizontal' ? entry.box.width : entry.box.height);
        }, 0);

        var gap = (span - used) / (entries.length - 1);
        var cursor = axis === 'horizontal' ? first.left : first.top;

        entries.forEach(function(entry) {
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
    Designer.prototype.reorder = function(delta) {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        this.pushHistory();

        this.selection.forEach(function(id) {
            var element = self.elementById(id);
            if (element) {
                element.zindex = Math.max(0, (element.zindex || 0) + delta);
            }
        });

        this.page().elements.sort(function(a, b) {
            return (a.zindex || 0) - (b.zindex || 0);
        });

        this.page().elements.forEach(function(element, index) {
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
    Designer.prototype.toggleVisibility = function(id) {
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
    Designer.prototype.toggleLock = function(id) {
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
    Designer.prototype.setZoom = function(zoom) {
        this.zoom = Math.max(0.25, Math.min(4, zoom));
        this.render();
    };

    // --------------------------------------------------------------- history.

    /**
     * Record the current state so it can be undone.
     *
     * @return {void}
     */
    Designer.prototype.pushHistory = function() {
        this.history.push(JSON.stringify(this.page().elements));
        if (this.history.length > HISTORY_LIMIT) {
            this.history.shift();
        }
        this.future = [];
    };

    /**
     * Step back one change.
     *
     * @return {void}
     */
    Designer.prototype.undo = function() {
        if (!this.history.length) {
            return;
        }
        this.future.push(JSON.stringify(this.page().elements));
        this.page().elements = JSON.parse(this.history.pop());
        this.render();
        this.queueSave();
    };

    /**
     * Step forward one change.
     *
     * @return {void}
     */
    Designer.prototype.redo = function() {
        if (!this.future.length) {
            return;
        }
        this.history.push(JSON.stringify(this.page().elements));
        this.page().elements = JSON.parse(this.future.pop());
        this.render();
        this.queueSave();
    };

    // ------------------------------------------------------------ persistence.

    /**
     * Save shortly after the last change.
     *
     * @return {void}
     */
    Designer.prototype.queueSave = function() {
        var self = this;

        if (this.saveTimer) {
            window.clearTimeout(this.saveTimer);
        }

        this.setStatus(this.strings.saving || '');

        this.saveTimer = window.setTimeout(function() {
            self.save();
        }, SAVE_DELAY);
    };

    /**
     * Write the current page's geometry back to the server.
     *
     * The promise is returned so that anything which is about to reload the
     * page — the design libraries, for one — can wait for the last drag to
     * land instead of throwing it away.
     *
     * @return {Promise} resolves when the save has been acknowledged
     */
    Designer.prototype.save = function() {
        var self = this;
        var page = this.page();

        var payload = page.elements.map(function(element) {
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
        }])[0].then(function() {
            self.setStatus(self.strings.saved || '');
            return true;
        }).catch(function(error) {
            self.setStatus(self.strings.savefailed || '');
            Notification.exception(error);
        });
    };

    /**
     * Show a short message in the status bar.
     *
     * @param {string} message the message to show
     * @return {void}
     */
    Designer.prototype.setStatus = function(message) {
        if (this.statusbar) {
            this.statusbar.textContent = message;
        }
    };

    /**
     * Describe what is happening during a drag.
     *
     * @param {Object|null} zone the zone that would capture on release
     * @return {void}
     */
    Designer.prototype.updateStatus = function(zone) {
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
    Designer.prototype.renderLayers = function() {
        if (!this.layers) {
            return;
        }

        var self = this;
        var page = this.page();
        var list = document.createElement('ul');

        list.className = 'cpro-layer-list';

        page.elements.slice().reverse().forEach(function(element) {
            var item = document.createElement('li');
            item.className = 'cpro-layer-item'
                + (self.selection.indexOf(element.id) !== -1 ? ' cpro-layer-selected' : '');

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cpro-layer-name';
            button.setAttribute('data-action', 'selectelement');
            button.setAttribute('data-value', String(element.id));
            var icon = document.createElement('i');
            icon.className = 'fa ' + element.icon;
            icon.setAttribute('aria-hidden', 'true');
            var label = document.createElement('span');
            label.textContent = element.name;
            button.appendChild(icon);
            button.appendChild(document.createTextNode(' '));
            button.appendChild(label);
            item.appendChild(button);

            var visibility = document.createElement('button');
            visibility.type = 'button';
            visibility.className = 'cpro-layer-toggle';
            visibility.setAttribute('data-action', 'togglevisible');
            visibility.setAttribute('data-value', String(element.id));
            visibility.innerHTML = '<i class="fa ' + (element.hidden ? 'fa-eye-slash' : 'fa-eye')
                + '" aria-hidden="true"></i>';
            item.appendChild(visibility);

            var lock = document.createElement('button');
            lock.type = 'button';
            lock.className = 'cpro-layer-toggle';
            lock.setAttribute('data-action', 'togglelock');
            lock.setAttribute('data-value', String(element.id));
            lock.innerHTML = '<i class="fa ' + (element.locked ? 'fa-lock' : 'fa-lock-open')
                + '" aria-hidden="true"></i>';
            item.appendChild(lock);

            list.appendChild(item);
        });

        this.layers.innerHTML = '';
        this.layers.appendChild(list);
    };

    /**
     * Draw the inspector for the current selection.
     *
     * @return {void}
     */
    Designer.prototype.renderInspector = function() {
        if (!this.inspector) {
            return;
        }

        var self = this;

        if (this.selection.length !== 1) {
            this.inspector.setAttribute('data-count', String(this.selection.length));
            return;
        }

        var element = this.elementById(this.selection[0]);
        if (!element) {
            return;
        }

        this.inspector.setAttribute('data-count', '1');
        this.inspector.setAttribute('data-element-id', String(element.id));

        ['posx', 'posy', 'width', 'height', 'rotation'].forEach(function(field) {
            var input = self.inspector.querySelector('[data-field="' + field + '"]');
            if (input) {
                input.value = (element[field] || 0).toFixed(2);
            }
        });

        var zoneLabel = this.inspector.querySelector('[data-field="zone"]');
        if (zoneLabel) {
            var zone = null;
            this.page().zones.forEach(function(candidate) {
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
    Designer.prototype.bindPicker = function() {
        var self = this;
        var picker = this.root.querySelector('[data-region="picker"]');

        if (!picker) {
            return;
        }

        picker.addEventListener('click', function(event) {
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
    Designer.prototype.addElement = function(type, zoneid) {
        var self = this;

        Ajax.call([{
            methodname: 'mod_certificatepro_add_element',
            args: {
                templateid: this.template.id,
                pageid: this.page().id,
                elementtype: type,
                zoneid: zoneid || ''
            }
        }])[0].then(function(response) {
            var element = JSON.parse(response.element);
            self.page().elements.push(element);
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
    Designer.prototype.duplicateSelection = function() {
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
        }])[0].then(function(response) {
            var created = JSON.parse(response.elements);
            created.forEach(function(element) {
                self.page().elements.push(element);
            });
            self.renderElements();
            self.renderLayers();
            self.select(created.map(function(element) {
                return element.id;
            }));
            return true;
        }).catch(Notification.exception);
    };

    /**
     * Remove the selection.
     *
     * @return {void}
     */
    Designer.prototype.deleteSelection = function() {
        var self = this;

        if (!this.selection.length) {
            return;
        }

        var ids = this.selection.slice();

        Ajax.call([{
            methodname: 'mod_certificatepro_delete_elements',
            args: {
                templateid: this.template.id,
                elementids: ids
            }
        }])[0].then(function() {
            var page = self.page();
            page.elements = page.elements.filter(function(element) {
                return ids.indexOf(element.id) === -1;
            });
            self.select([]);
            self.renderElements();
            self.renderLayers();
            return true;
        }).catch(Notification.exception);
    };

    return {
        /**
         * Start the designer.
         *
         * @param {string} selector a selector for the designer root
         * @param {Object} config the bootstrap configuration
         * @return {void}
         */
        init: function(selector) {
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

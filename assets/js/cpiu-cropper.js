/**
 * NDV Product Image Upload — self-contained cropper module (Cropper.js v2).
 *
 * Exposes window.CPIUCropper.open(options) -> Promise that resolves with the
 * cropped result ({ dataUrl, mime, shape }) or null when the user cancels.
 *
 * Owns its own modal DOM (built once, reused), the Cropper.js v2 web-component
 * lifecycle, the shape selector, a full toolbar (zoom / rotate / flip / reset)
 * and the export pipeline (shape masking, PNG-with-alpha vs JPEG-on-white).
 *
 * The engine is the bundled Cropper.js v2.1.1 web components; this module only
 * needs the <cropper-*> custom elements to be defined (cropper.min.js loaded).
 */
(function (window, document) {
    'use strict';

    // Heart silhouette — shared by BOTH the export mask and the CSS live preview
    // so what the user sees is exactly what is produced. viewBox is 480 x 438.82.
    var HEART_PATH = 'M438.82 41.18c-54.9-54.9-143.92-54.9-198.82 0-54.9-54.9-143.92-54.9-198.82 0-54.9 54.9-54.9 143.92 0 198.82L240 438.82 438.82 240c54.9-54.9 54.9-143.92 0-198.82Z';
    var HEART_ASPECT = 480 / 438.82;

    // Inline SVG icons (currentColor) for the toolbar — no external assets.
    var ICONS = {
        zoomIn: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>',
        zoomOut: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>',
        rotateLeft: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
        rotateRight: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.13-9.36L23 10"/></svg>',
        flipH: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M8 7l-4 5 4 5"/><path d="M16 7l4 5-4 5"/></svg>',
        flipV: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/><path d="M7 8l5-4 5 4"/><path d="M7 16l5 4 5-4"/></svg>',
        reset: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-9"/></svg>'
    };

    // Default i18n — every string is overridable by the caller.
    var DEFAULT_I18N = {
        crop_title: 'Crop Image',
        select_shape: 'Select Shape:',
        shape_none: 'None',
        shape_square: 'Square',
        shape_rectangle: 'Rectangle',
        shape_circle: 'Circle',
        shape_heart: 'Heart',
        save_crop: 'Save Crop',
        cancel: 'Cancel',
        restore: 'Restore Original',
        zoom_in: 'Zoom in',
        zoom_out: 'Zoom out',
        rotate_left: 'Rotate left',
        rotate_right: 'Rotate right',
        flip_h: 'Flip horizontal',
        flip_v: 'Flip vertical',
        reset: 'Reset',
        crop_error: 'Could not crop image.'
    };

    var DEFAULT_ACCENT = '#4CAF50';

    // Singleton DOM + state.
    var dom = null;      // cached element references
    var session = null;  // active open() session

    /** 'free' -> NaN, 'W:H' -> W/H float. */
    function getAspect(setting) {
        if (!setting || setting === 'free') {
            return NaN;
        }
        var parts = String(setting).split(':');
        var w = parseFloat(parts[0]);
        var h = parseFloat(parts[1]);
        if (isFinite(w) && isFinite(h) && h !== 0) {
            return w / h;
        }
        return NaN;
    }

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                node.setAttribute(k, attrs[k]);
            });
        }
        return node;
    }

    /** Are the <cropper-*> custom elements available? */
    function cropperReady() {
        return typeof window.customElements !== 'undefined' &&
            !!window.customElements.get('cropper-canvas');
    }

    /**
     * Build the modal once. Static structure; per-open state is applied in open().
     */
    function ensureDOM() {
        if (dom) {
            return dom;
        }

        var modal = el('div', 'cpiu-crx-modal');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var panel = el('div', 'cpiu-crx-panel');

        var title = el('h3', 'cpiu-crx-title');
        panel.appendChild(title);

        // Shape selector row (buttons injected per-open).
        var shapeRow = el('div', 'cpiu-crx-shapes');
        var shapeLabel = el('span', 'cpiu-crx-shapes-label');
        var shapeBtns = el('div', 'cpiu-crx-shapes-btns');
        shapeRow.appendChild(shapeLabel);
        shapeRow.appendChild(shapeBtns);
        panel.appendChild(shapeRow);

        // Toolbar row.
        var toolbar = el('div', 'cpiu-crx-toolbar');
        var toolButtons = {};
        [
            ['zoomIn', 'zoom_in', ICONS.zoomIn],
            ['zoomOut', 'zoom_out', ICONS.zoomOut],
            ['rotateLeft', 'rotate_left', ICONS.rotateLeft],
            ['rotateRight', 'rotate_right', ICONS.rotateRight],
            ['flipH', 'flip_h', ICONS.flipH],
            ['flipV', 'flip_v', ICONS.flipV],
            ['reset', 'reset', ICONS.reset]
        ].forEach(function (spec) {
            var b = el('button', 'cpiu-crx-tool', { type: 'button' });
            b.innerHTML = spec[2];
            b.dataset.i18n = spec[1];
            toolButtons[spec[0]] = b;
            toolbar.appendChild(b);
        });
        panel.appendChild(toolbar);

        // Stage (canvas host). Web components created fresh each open so a stale
        // transform/image can never leak between images.
        var stage = el('div', 'cpiu-crx-stage');
        panel.appendChild(stage);

        // Actions.
        var actions = el('div', 'cpiu-crx-actions');
        var saveBtn = el('button', 'cpiu-crx-btn cpiu-crx-save', { type: 'button' });
        var restoreBtn = el('button', 'cpiu-crx-btn cpiu-crx-restore', { type: 'button' });
        var cancelBtn = el('button', 'cpiu-crx-btn cpiu-crx-cancel', { type: 'button' });
        actions.appendChild(saveBtn);
        actions.appendChild(restoreBtn);
        actions.appendChild(cancelBtn);
        panel.appendChild(actions);

        modal.appendChild(panel);
        document.body.appendChild(modal);

        dom = {
            modal: modal, panel: panel, title: title,
            shapeRow: shapeRow, shapeLabel: shapeLabel, shapeBtns: shapeBtns,
            toolbar: toolbar, toolButtons: toolButtons, stage: stage,
            saveBtn: saveBtn, restoreBtn: restoreBtn, cancelBtn: cancelBtn,
            // Live per-open handles (assigned in buildStage):
            canvas: null, image: null, selection: null, overlay: null
        };

        wireStaticEvents();
        return dom;
    }

    /** Events that never change between opens (bound once). */
    function wireStaticEvents() {
        dom.saveBtn.addEventListener('click', function () { commit(); });
        dom.cancelBtn.addEventListener('click', function () { cancel(); });
        dom.restoreBtn.addEventListener('click', function () { restore(); });

        // Backdrop click cancels (but not clicks inside the panel).
        dom.modal.addEventListener('mousedown', function (e) {
            if (e.target === dom.modal) {
                cancel();
            }
        });

        // Dragging/adjusting the crop box on the stage is a fresh edit.
        dom.stage.addEventListener('pointerdown', markEdited);

        // Toolbar. Every manipulation is a fresh edit (clears the restored flag).
        var t = dom.toolButtons;
        t.zoomIn.addEventListener('click', function () { markEdited(); dom.image && dom.image.$zoom(0.1); });
        t.zoomOut.addEventListener('click', function () { markEdited(); dom.image && dom.image.$zoom(-0.1); });
        t.rotateLeft.addEventListener('click', function () { markEdited(); dom.image && dom.image.$rotate('-90deg'); });
        t.rotateRight.addEventListener('click', function () { markEdited(); dom.image && dom.image.$rotate('90deg'); });
        t.flipH.addEventListener('click', function () { markEdited(); toggleFlip('x'); });
        t.flipV.addEventListener('click', function () { markEdited(); toggleFlip('y'); });
        t.reset.addEventListener('click', function () { markEdited(); resetView(); });
    }

    /** Keyboard: Enter = save, Escape = cancel. Bound only while open. */
    function onKeyDown(e) {
        if (!session) {
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            cancel();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            commit();
        }
    }

    /**
     * (Re)build the <cropper-*> web components inside the stage. Doing this per
     * open guarantees a clean transform and avoids v2 state bleeding across images.
     */
    function buildStage() {
        dom.stage.innerHTML =
            '<cropper-canvas background>' +
            '<cropper-image rotatable scalable translatable initial-center-size="contain"></cropper-image>' +
            '<cropper-shade hidden></cropper-shade>' +
            '<cropper-handle action="select" plain></cropper-handle>' +
            '<cropper-selection initial-coverage="0.8" movable resizable outlined>' +
            '<cropper-grid role="grid" covered></cropper-grid>' +
            '<cropper-crosshair centered></cropper-crosshair>' +
            '<cropper-handle action="move" theme-color="rgba(255,255,255,0.35)"></cropper-handle>' +
            '<cropper-handle action="n-resize"></cropper-handle>' +
            '<cropper-handle action="e-resize"></cropper-handle>' +
            '<cropper-handle action="s-resize"></cropper-handle>' +
            '<cropper-handle action="w-resize"></cropper-handle>' +
            '<cropper-handle action="ne-resize"></cropper-handle>' +
            '<cropper-handle action="nw-resize"></cropper-handle>' +
            '<cropper-handle action="se-resize"></cropper-handle>' +
            '<cropper-handle action="sw-resize"></cropper-handle>' +
            '</cropper-selection>' +
            '</cropper-canvas>';

        dom.canvas = dom.stage.querySelector('cropper-canvas');
        dom.image = dom.stage.querySelector('cropper-image');
        dom.selection = dom.stage.querySelector('cropper-selection');

        // Non-interactive live-preview overlay that fills the selection exactly.
        dom.overlay = el('div', 'cpiu-crx-shape-overlay');
        dom.selection.appendChild(dom.overlay);
    }

    /** Render the shape-selector buttons for this open. */
    function buildShapeButtons(i18n, ratioSetting) {
        var shapes = [
            { id: 'none', label: i18n.shape_none, icon: '⬚', aspect: getAspect(ratioSetting) },
            { id: 'square', label: i18n.shape_square, icon: '■', aspect: 1 },
            { id: 'rectangle', label: i18n.shape_rectangle, icon: '▭', aspect: NaN },
            { id: 'circle', label: i18n.shape_circle, icon: '●', aspect: 1 },
            { id: 'heart', label: i18n.shape_heart, icon: '♥', aspect: HEART_ASPECT }
        ];

        dom.shapeBtns.innerHTML = '';
        shapes.forEach(function (shape) {
            var btn = el('button', 'cpiu-crx-shape', { type: 'button' });
            btn.innerHTML = '<span class="cpiu-crx-shape-ico">' + shape.icon + '</span>' +
                '<span class="cpiu-crx-shape-txt">' + shape.label + '</span>';
            btn.dataset.shape = shape.id;
            btn.addEventListener('click', function () {
                markEdited(); // picking a shape is a fresh crop intent
                selectShape(shape);
            });
            dom.shapeBtns.appendChild(btn);
        });
    }

    /** Apply a shape: set the selection aspect ratio and the live preview mask. */
    function selectShape(shape) {
        if (!session) {
            return;
        }
        session.shape = shape.id;

        // Active button state.
        Array.prototype.forEach.call(dom.shapeBtns.children, function (b) {
            b.classList.toggle('is-active', b.dataset.shape === shape.id);
        });

        // Aspect ratio on the selection.
        var aspect = shape.id === 'none' ? getAspect(session.ratioSetting) : shape.aspect;
        dom.selection.aspectRatio = aspect;

        // Live preview overlay.
        dom.overlay.classList.remove('is-circle', 'is-heart');
        if (shape.id === 'circle') {
            dom.overlay.classList.add('is-circle');
        } else if (shape.id === 'heart') {
            dom.overlay.classList.add('is-heart');
        }
    }

    /**
     * Any deliberate edit (shape pick, drag, zoom/rotate/flip/reset) cancels the
     * "restored to original" state so Save/Cancel will crop again as normal.
     */
    function markEdited() {
        if (session) {
            session.restored = false;
        }
    }

    /** Extract the mime type from a data URL (defaults to image/jpeg). */
    function parseMime(dataUrl) {
        var m = /^data:(image\/[a-z0-9.+-]+)/i.exec(dataUrl || '');
        return m ? m[1] : 'image/jpeg';
    }

    /** Resolve with the pristine original image, untouched (no crop). */
    function resolveOriginal() {
        var src = session.originalSrc || session.src;
        finish({ dataUrl: src, mime: parseMime(src), shape: 'none' });
    }

    /** Cancel: return the restored original if the user restored, else discard. */
    function cancel() {
        if (session && session.restored) {
            resolveOriginal();
        } else {
            finish(null);
        }
    }

    /** Load a source into <cropper-image> and fit it to the canvas. */
    function loadAndFit(src) {
        var applyFit = function () {
            if (typeof dom.image.$resetTransform === 'function') {
                dom.image.$resetTransform();
            }
            if (typeof dom.image.$center === 'function') {
                dom.image.$center('contain');
            }
            if (dom.selection && typeof dom.selection.$reset === 'function') {
                dom.selection.$reset();
            }
            session.flipX = 1;
            session.flipY = 1;
        };

        dom.image.src = src;

        if (typeof dom.image.$ready === 'function') {
            var ready = dom.image.$ready(applyFit);
            if (ready && typeof ready.catch === 'function') {
                ready.catch(function () { /* image load failed; leave as-is */ });
            }
        } else {
            window.requestAnimationFrame(applyFit);
        }
    }

    function toggleFlip(axis) {
        if (!dom.image || !session) {
            return;
        }
        if (axis === 'x') {
            session.flipX *= -1;
            dom.image.$scale(-1, 1);
        } else {
            session.flipY *= -1;
            dom.image.$scale(1, -1);
        }
    }

    function resetView() {
        if (!dom.image) {
            return;
        }
        dom.image.$resetTransform();
        dom.image.$center('contain');
        if (dom.selection && typeof dom.selection.$reset === 'function') {
            dom.selection.$reset();
        }
        session.flipX = 1;
        session.flipY = 1;
    }

    function restore() {
        if (!session) {
            return;
        }
        // Reset shape to 'none' and reload the pristine source.
        selectShape({ id: 'none', aspect: getAspect(session.ratioSetting) });
        loadAndFit(session.originalSrc || session.src);
        // Mark restored LAST: Save/Cancel now return the untouched original
        // unless the user makes a new edit (which clears this via markEdited).
        session.restored = true;
    }

    /** Produce the final canvas honouring the current shape, then resolve. */
    function commit() {
        if (!session || !dom.selection) {
            return;
        }

        // If the user restored and hasn't re-edited, save the untouched original.
        if (session.restored) {
            resolveOriginal();
            return;
        }

        var shape = session.shape;
        var transparent = (shape === 'circle' || shape === 'heart');

        dom.selection.$toCanvas({
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high',
            beforeDraw: function (ctx, cvs) {
                if (!transparent) {
                    // Flatten onto white for opaque (JPEG) output.
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, cvs.width, cvs.height);
                }
            }
        }).then(function (canvas) {
            if (!canvas) {
                throw new Error('empty canvas');
            }

            if (transparent) {
                var masked = document.createElement('canvas');
                masked.width = canvas.width;
                masked.height = canvas.height;
                var ctx = masked.getContext('2d');
                ctx.fillStyle = '#000';

                if (shape === 'circle') {
                    ctx.beginPath();
                    ctx.arc(masked.width / 2, masked.height / 2, masked.width / 2, 0, 2 * Math.PI);
                    ctx.fill();
                } else {
                    var path = new Path2D(HEART_PATH);
                    ctx.save();
                    ctx.scale(masked.width / 480, masked.height / 438.82);
                    ctx.fill(path);
                    ctx.restore();
                }

                ctx.globalCompositeOperation = 'source-in';
                ctx.drawImage(canvas, 0, 0);
                canvas = masked;
            }

            var mime = transparent ? 'image/png' : 'image/jpeg';
            var dataUrl = canvas.toDataURL(mime, 0.9);
            finish({ dataUrl: dataUrl, mime: mime, shape: shape });
        }).catch(function (err) {
            if (window.console && console.error) {
                console.error('CPIU Cropper: export failed.', err);
            }
            window.alert(session.i18n.crop_error);
        });
    }

    /** Close the modal and settle the open() promise. */
    function finish(result) {
        if (!session) {
            return;
        }
        var resolve = session.resolve;
        document.removeEventListener('keydown', onKeyDown, true);
        dom.modal.classList.remove('is-open');
        document.body.classList.remove('cpiu-crx-lock');
        // Free the image so its memory isn't held between opens.
        if (dom.image) {
            dom.image.src = '';
        }
        session = null;
        resolve(result);
    }

    /**
     * Public entry point. Returns a Promise resolving to the crop result, or
     * null if cancelled.
     */
    function open(options) {
        options = options || {};

        return new Promise(function (resolve) {
            if (!cropperReady()) {
                if (window.console && console.error) {
                    console.error('CPIU Cropper: Cropper.js v2 web components not loaded.');
                }
                resolve(null);
                return;
            }

            var i18n = Object.assign({}, DEFAULT_I18N, options.i18n || {});
            var accent = options.accentColor || DEFAULT_ACCENT;

            ensureDOM();
            buildStage();

            session = {
                resolve: resolve,
                src: options.src,
                originalSrc: options.originalSrc || options.src,
                ratioSetting: options.ratioSetting || 'free',
                i18n: i18n,
                shape: 'none',
                flipX: 1,
                flipY: 1,
                restored: false
            };

            // Labels.
            dom.title.textContent = i18n.crop_title;
            dom.shapeLabel.textContent = i18n.select_shape;
            dom.saveBtn.textContent = i18n.save_crop;
            dom.restoreBtn.textContent = i18n.restore;
            dom.cancelBtn.textContent = i18n.cancel;

            // Toolbar tooltips/aria.
            Object.keys(dom.toolButtons).forEach(function (key) {
                var b = dom.toolButtons[key];
                var label = i18n[b.dataset.i18n] || '';
                b.title = label;
                b.setAttribute('aria-label', label);
            });

            // Accent color.
            dom.panel.style.setProperty('--cpiu-crx-accent', accent);

            // Shapes on/off.
            if (options.enableShapes) {
                dom.shapeRow.style.display = '';
                buildShapeButtons(i18n, session.ratioSetting);
            } else {
                dom.shapeRow.style.display = 'none';
            }

            // Show, load, fit, default shape.
            document.body.classList.add('cpiu-crx-lock');
            dom.modal.classList.add('is-open');
            document.addEventListener('keydown', onKeyDown, true);

            loadAndFit(session.src);

            if (options.enableShapes) {
                var noneBtn = dom.shapeBtns.querySelector('[data-shape="none"]');
                if (noneBtn) {
                    selectShape({ id: 'none', aspect: getAspect(session.ratioSetting) });
                }
            } else {
                // No shapes: the ratio setting still governs the crop box.
                dom.selection.aspectRatio = getAspect(session.ratioSetting);
            }
        });
    }

    window.CPIUCropper = { open: open };

})(window, document);

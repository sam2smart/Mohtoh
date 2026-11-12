/*
 * MeritImageViewer — a dependency-free fullscreen image viewer with zoom & pan.
 *
 * Used by the admin dashboard (to inspect vehicle photos & verification
 * documents) and reusable on any page. Builds its own overlay DOM, so it does
 * NOT depend on Bootstrap modals.
 *
 *   MeritImageViewer.open(url, title)   // url = full-res image to inspect
 *   MeritImageViewer.openGallery(items, startIndex)  // items: [{url,title}]
 *   MeritImageViewer.close()
 *
 * Controls: + / - buttons, mouse wheel, double-click to toggle zoom,
 * drag-to-pan when zoomed, reset button, prev/next arrows (or Arrow keys)
 * when viewing a gallery, close via the × button, backdrop click, or Escape.
 */
(function () {
  'use strict';

  if (window.MeritImageViewer) {
    return; // already loaded (guard against double include)
  }

  var MIN_SCALE = 1;
  var MAX_SCALE = 8;
  var STEP = 0.35;

  var overlay = null;
  var imgEl = null;
  var stageEl = null;
  var titleEl = null;
  var spinnerEl = null;
  var scaleLabelEl = null;

  var scale = 1;
  var tx = 0; // translate X (px)
  var ty = 0; // translate Y (px)
  var dragging = false;
  var dragStartX = 0;
  var dragStartY = 0;
  var startTx = 0;
  var startTy = 0;
  var lastPointerType = 'mouse';

  // Gallery state — a list of {url, title} and the current index. A single
  // open() call becomes a one-item gallery so navigation just stays hidden.
  var gallery = [];
  var galleryIndex = 0;

  function clamp(v, lo, hi) {
    return Math.min(hi, Math.max(lo, v));
  }

  function applyTransform() {
    if (!imgEl) return;
    imgEl.style.transform =
      'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
    if (stageEl) {
      stageEl.style.cursor =
        scale > 1 ? (dragging ? 'grabbing' : 'grab') : 'default';
    }
    if (scaleLabelEl) {
      scaleLabelEl.textContent = Math.round(scale * 100) + '%';
    }
  }

  function resetView() {
    scale = 1;
    tx = 0;
    ty = 0;
    applyTransform();
  }

  // Zoom toward a focal point (fx, fy) given in stage-content coordinates
  // relative to the image centre, so the pixel under the cursor stays put.
  function zoomTo(newScale, fx, fy) {
    newScale = clamp(newScale, MIN_SCALE, MAX_SCALE);
    if (newScale === scale) return;
    if (typeof fx === 'number' && typeof fy === 'number') {
      var ratio = newScale / scale;
      tx = fx - (fx - tx) * ratio;
      ty = fy - (fy - ty) * ratio;
    }
    scale = newScale;
    if (scale === 1) {
      tx = 0;
      ty = 0;
    }
    applyTransform();
  }

  function buildDom() {
    overlay = document.createElement('div');
    overlay.className = 'mt-viewer-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = [
      '<div class="mt-viewer-bar">',
      '  <span class="mt-viewer-title"></span>',
      '  <div class="mt-viewer-tools">',
      '    <button type="button" class="mt-viewer-btn" data-act="zoomout" title="Zoom out" aria-label="Zoom out">&minus;</button>',
      '    <span class="mt-viewer-scale">100%</span>',
      '    <button type="button" class="mt-viewer-btn" data-act="zoomin" title="Zoom in" aria-label="Zoom in">+</button>',
      '    <button type="button" class="mt-viewer-btn" data-act="reset" title="Reset" aria-label="Reset zoom">Reset</button>',
      '    <a class="mt-viewer-btn" data-act="open" target="_blank" rel="noopener" title="Open original in new tab" aria-label="Open original in new tab">Original</a>',
      '    <button type="button" class="mt-viewer-btn mt-viewer-close" data-act="close" title="Close" aria-label="Close viewer">&times;</button>',
      '  </div>',
      '</div>',
      '<div class="mt-viewer-stage">',
      '  <button type="button" class="mt-viewer-nav mt-viewer-prev" data-act="prev" title="Previous image" aria-label="Previous image">&#8249;</button>',
      '  <div class="mt-viewer-spinner" aria-hidden="true"></div>',
      '  <img class="mt-viewer-img" alt="" draggable="false" />',
      '  <button type="button" class="mt-viewer-nav mt-viewer-next" data-act="next" title="Next image" aria-label="Next image">&#8250;</button>',
      '</div>'
    ].join('');
    document.body.appendChild(overlay);

    stageEl = overlay.querySelector('.mt-viewer-stage');
    imgEl = overlay.querySelector('.mt-viewer-img');
    titleEl = overlay.querySelector('.mt-viewer-title');
    spinnerEl = overlay.querySelector('.mt-viewer-spinner');
    scaleLabelEl = overlay.querySelector('.mt-viewer-scale');

    // Toolbar actions.
    overlay.addEventListener('click', function (e) {
      var el = e.target.closest('[data-act]');
      if (!el) return;
      var act = el.getAttribute('data-act');
      if (act === 'close') {
        close();
      } else if (act === 'zoomin') {
        zoomTo(scale + STEP * Math.max(1, scale));
      } else if (act === 'zoomout') {
        zoomTo(scale - STEP * Math.max(1, scale));
      } else if (act === 'reset') {
        resetView();
      } else if (act === 'prev') {
        step(-1);
      } else if (act === 'next') {
        step(1);
      }
      // 'open' is a real anchor; let the browser handle it.
    });

    // Backdrop click (only when clicking the empty stage, not the image).
    stageEl.addEventListener('mousedown', function (e) {
      if (e.target === stageEl) {
        // start a potential backdrop-close; confirmed on mouseup w/o drag.
        stageEl._backdropDown = true;
      }
    });
    stageEl.addEventListener('mouseup', function (e) {
      if (e.target === stageEl && stageEl._backdropDown) {
        close();
      }
      stageEl._backdropDown = false;
    });

    // Wheel zoom, focused on the cursor.
    stageEl.addEventListener(
      'wheel',
      function (e) {
        e.preventDefault();
        var rect = imgEl.getBoundingClientRect();
        var cx = rect.left + rect.width / 2;
        var cy = rect.top + rect.height / 2;
        var fx = e.clientX - cx; // offset from image centre in screen px
        var fy = e.clientY - cy;
        var dir = e.deltaY < 0 ? 1 : -1;
        zoomTo(scale + dir * STEP * Math.max(1, scale), fx, fy);
      },
      { passive: false }
    );

    // Double-click toggles between fit and 2.5x at the click point.
    imgEl.addEventListener('dblclick', function (e) {
      var rect = imgEl.getBoundingClientRect();
      var cx = rect.left + rect.width / 2;
      var cy = rect.top + rect.height / 2;
      if (scale > 1) {
        resetView();
      } else {
        zoomTo(2.5, e.clientX - cx, e.clientY - cy);
      }
    });

    // Drag to pan (mouse + touch via pointer events).
    imgEl.addEventListener('pointerdown', function (e) {
      lastPointerType = e.pointerType || 'mouse';
      if (scale <= 1) return;
      dragging = true;
      dragStartX = e.clientX;
      dragStartY = e.clientY;
      startTx = tx;
      startTy = ty;
      try { imgEl.setPointerCapture(e.pointerId); } catch (err) {}
      applyTransform();
      e.preventDefault();
    });
    imgEl.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      tx = startTx + (e.clientX - dragStartX);
      ty = startTy + (e.clientY - dragStartY);
      applyTransform();
    });
    function endDrag(e) {
      if (!dragging) return;
      dragging = false;
      try { imgEl.releasePointerCapture(e.pointerId); } catch (err) {}
      applyTransform();
    }
    imgEl.addEventListener('pointerup', endDrag);
    imgEl.addEventListener('pointercancel', endDrag);

    imgEl.addEventListener('load', function () {
      if (spinnerEl) spinnerEl.style.display = 'none';
      imgEl.style.visibility = 'visible';
    });
    imgEl.addEventListener('error', function () {
      if (spinnerEl) {
        spinnerEl.style.display = 'none';
      }
      if (titleEl) {
        titleEl.textContent = 'Unable to load image';
      }
      imgEl.style.visibility = 'hidden';
    });
  }

  function onKeydown(e) {
    if (!overlay || overlay.style.display === 'none') return;
    if (e.key === 'Escape') {
      close();
    } else if (e.key === '+' || e.key === '=') {
      zoomTo(scale + STEP * Math.max(1, scale));
    } else if (e.key === '-' || e.key === '_') {
      zoomTo(scale - STEP * Math.max(1, scale));
    } else if (e.key === '0') {
      resetView();
    } else if (e.key === 'ArrowLeft') {
      step(-1);
    } else if (e.key === 'ArrowRight') {
      step(1);
    }
  }

  // Reflect the current gallery entry into the DOM. Nav arrows only show when
  // there is more than one image, so single-image opens look unchanged.
  function showCurrent() {
    var item = gallery[galleryIndex] || {};
    var multi = gallery.length > 1;
    resetView();
    if (titleEl) titleEl.textContent = item.title || '';
    var openLink = overlay.querySelector('[data-act="open"]');
    if (openLink) openLink.setAttribute('href', item.url || '');
    if (spinnerEl) spinnerEl.style.display = 'block';
    imgEl.style.visibility = 'hidden';
    imgEl.setAttribute('alt', item.title || 'Image');
    imgEl.src = item.url || '';
    var prevBtn = overlay.querySelector('[data-act="prev"]');
    var nextBtn = overlay.querySelector('[data-act="next"]');
    if (prevBtn) prevBtn.style.display = multi ? 'flex' : 'none';
    if (nextBtn) nextBtn.style.display = multi ? 'flex' : 'none';
  }

  // Move by delta with wrap-around; no-op for single-image galleries.
  function step(delta) {
    if (gallery.length < 2) return;
    galleryIndex = (galleryIndex + delta + gallery.length) % gallery.length;
    showCurrent();
  }

  function open(url, title) {
    if (!url) return;
    openGallery([{ url: url, title: title }], 0);
  }

  function openGallery(items, startIndex) {
    if (!Array.isArray(items) || !items.length) return;
    if (!overlay) {
      buildDom();
      document.addEventListener('keydown', onKeydown);
    }
    gallery = items.filter(function (it) { return it && it.url; });
    if (!gallery.length) return;
    galleryIndex = clamp(startIndex || 0, 0, gallery.length - 1);
    showCurrent();
    overlay.style.display = 'flex';
    document.body.classList.add('mt-viewer-open');
  }

  function close() {
    if (!overlay) return;
    overlay.style.display = 'none';
    document.body.classList.remove('mt-viewer-open');
    // Release the (potentially large) full-res image from memory.
    imgEl.removeAttribute('src');
    gallery = [];
    galleryIndex = 0;
    resetView();
  }

  window.MeritImageViewer = { open: open, openGallery: openGallery, close: close };
})();

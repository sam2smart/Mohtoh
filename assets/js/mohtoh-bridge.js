// assets/js/mohtoh-bridge.js
// MohToh Device Bridge — the ONLY frontend access point for device
// capabilities (Phase 2). Every page/module that needs geolocation,
// notifications, camera/file access, sharing, or local storage goes
// through window.MohToh instead of calling browser APIs directly.
//
// Why this exists: a future .NET MAUI WebView build replaces exactly
// ONE thing — the implementation behind each MohToh.* method (e.g.
// MohToh.location.getCurrentLocation() swaps its browser
// navigator.geolocation call for a native GPS call via a MAUI
// JS-interop bridge). The JavaScript CONTRACT — method names,
// arguments, return shape — never changes, so no page-level code
// changes when that swap happens. See docs/maui-integration.md.
//
// This file is pure device/browser plumbing. It contains NO business
// logic — no fare math, no wallet math, no trip-state rules, no
// permission decisions. Those stay exactly where they already are: the
// PHP backend (includes/functions.php, api/*.php) via
// assets/js/api-client.js's apiGet/apiPost/apiUpload, which this file
// itself uses for camera.upload()/files.upload() rather than
// duplicating fetch logic.
//
// Load order: helper.js, api-client.js, sound-manager.js, THEN this
// file (mohtoh-bridge.js uses MohToh.sound internally for
// notification.playSound()).

(function () {
  'use strict';

  // ---------------------------------------------------------------
  // device — platform/runtime info every other namespace, and pages,
  // can read without touching navigator/window directly.
  // ---------------------------------------------------------------
  const device = {
    // Set to true by a future MAUI WebView host page (e.g. via a
    // <script>window.MOHTOH_RUNTIME='maui';</script> injected before
    // this file loads). Every adapter below checks this FIRST so the
    // MAUI build can override any single method without forking this
    // whole file — see docs/maui-integration.md.
    runtime: (typeof window !== 'undefined' && window.MOHTOH_RUNTIME) || 'web',
    isMaui() { return device.runtime === 'maui'; },
    isStandalone() {
      // "Installed as a PWA" — matches Phase 10's manifest.json
      // display:standalone, and iOS's older navigator.standalone flag.
      return (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches)
        || window.navigator.standalone === true;
    },
    userAgent() { return navigator.userAgent; },
    viewport() { return { width: window.innerWidth, height: window.innerHeight }; },
    isTouch() { return 'ontouchstart' in window || navigator.maxTouchPoints > 0; },
  };

  // ---------------------------------------------------------------
  // network — online/offline state, used by the PWA offline fallback
  // (Phase 10) and any UI that should react to connectivity changes.
  // ---------------------------------------------------------------
  const network = {
    isOnline() { return navigator.onLine; },
    /** fn(isOnline: boolean) called immediately and on every change. Returns an unsubscribe function. */
    onChange(fn) {
      const on = () => fn(true);
      const off = () => fn(false);
      window.addEventListener('online', on);
      window.addEventListener('offline', off);
      fn(navigator.onLine);
      return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
    },
    /** Best-effort connection info (Chrome/Edge only via the Network Information API). Returns null where unsupported — never guess. */
    getConnectionInfo() {
      const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      if (!conn) return null;
      return { effectiveType: conn.effectiveType, downlinkMbps: conn.downlink, saveData: !!conn.saveData };
    },
  };

  // ---------------------------------------------------------------
  // location (Phase 5) — the ONLY place navigator.geolocation is
  // called. Pages must not call it directly.
  // ---------------------------------------------------------------
  const DEFAULT_GEO_OPTIONS = { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 };

  function normalizeGeoError(err) {
    const messages = {
      1: 'Location permission was denied.',
      2: 'Your location is currently unavailable.',
      3: 'Getting your location took too long.',
    };
    return { code: err.code, message: messages[err.code] || err.message || 'Could not get your location.' };
  }
  function normalizePosition(pos) {
    return {
      latitude: pos.coords.latitude,
      longitude: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      speed: pos.coords.speed,
      heading: pos.coords.heading,
      timestamp: pos.timestamp,
    };
  }

  const location = {
    /** Promise<{latitude, longitude, accuracy, speed, heading, timestamp}>, rejects with {code, message}. */
    getCurrentLocation(options = {}) {
      return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
          reject({ code: 0, message: 'Location is not supported on this device.' });
          return;
        }
        navigator.geolocation.getCurrentPosition(
          (pos) => resolve(normalizePosition(pos)),
          (err) => reject(normalizeGeoError(err)),
          { ...DEFAULT_GEO_OPTIONS, ...options }
        );
      });
    },
    /** onUpdate({latitude,...}) on every fix, onError({code,message}) on failure. Returns a watchId for stopWatching(). */
    watchLocation(onUpdate, onError, options = {}) {
      if (!navigator.geolocation) {
        if (onError) onError({ code: 0, message: 'Location is not supported on this device.' });
        return null;
      }
      return navigator.geolocation.watchPosition(
        (pos) => onUpdate(normalizePosition(pos)),
        (err) => { if (onError) onError(normalizeGeoError(err)); },
        { ...DEFAULT_GEO_OPTIONS, ...options }
      );
    },
    stopWatching(watchId) {
      if (watchId !== null && watchId !== undefined && navigator.geolocation) {
        navigator.geolocation.clearWatch(watchId);
      }
    },
  };

  // ---------------------------------------------------------------
  // camera (Phase 6) — web has no direct native camera API; the
  // standard bridge pattern is an <input type="file" capture> that
  // mobile browsers route straight to the camera app instead of a
  // generic file picker. upload() delegates to api-client.js's
  // apiUpload() — never a second fetch/FormData implementation.
  // ---------------------------------------------------------------
  function pickFile({ accept = 'image/*', capture = null, multiple = false } = {}) {
    return new Promise((resolve) => {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = accept;
      if (capture) input.capture = capture; // 'environment' | 'user'
      input.multiple = multiple;
      input.style.display = 'none';
      input.addEventListener('change', () => {
        const files = Array.from(input.files || []);
        document.body.removeChild(input);
        resolve(multiple ? files : (files[0] || null));
      }, { once: true });
      // No 'cancel' event exists cross-browser for file inputs; a
      // window focus check is the closest reliable signal.
      window.addEventListener('focus', function onFocus() {
        window.removeEventListener('focus', onFocus);
        setTimeout(() => { if (input.parentNode && !input.files.length) { document.body.removeChild(input); resolve(multiple ? [] : null); } }, 300);
      }, { once: true });
      document.body.appendChild(input);
      input.click();
    });
  }

  const camera = {
    /** Opens the device camera (mobile) or a file picker (desktop). Resolves File|null. */
    capture(options = {}) {
      return pickFile({ accept: 'image/*', capture: 'environment', ...options, multiple: false });
    },
    /** Uploads a File via api-client.js's apiUpload — same endpoint contract every upload in this app already uses. */
    upload(file, endpoint, extraFields = {}) {
      if (typeof apiUpload !== 'function') {
        return Promise.resolve({ status: 'error', message: 'Upload service unavailable.' });
      }
      const formData = new FormData();
      Object.entries(extraFields).forEach(([key, value]) => formData.append(key, value));
      formData.append('file', file);
      return apiUpload(endpoint, formData);
    },
    /** Best-effort permission state ('granted'|'denied'|'prompt'|'unsupported') — browsers don't universally expose this for camera. */
    async permission() {
      if (!navigator.permissions || !navigator.permissions.query) return 'unsupported';
      try {
        const status = await navigator.permissions.query({ name: 'camera' });
        return status.state;
      } catch (error) {
        return 'unsupported';
      }
    },
  };

  // ---------------------------------------------------------------
  // files (Phase 7) — generic (non-camera) file selection: documents
  // for verification, etc. Shares upload() plumbing with camera.
  // ---------------------------------------------------------------
  const files = {
    /** Opens a normal file picker. options: {accept, multiple}. Resolves File|File[]|null. */
    select(options = {}) {
      return pickFile({ accept: 'image/*,application/pdf', multiple: false, ...options });
    },
    upload(file, endpoint, extraFields = {}) {
      return camera.upload(file, endpoint, extraFields); // identical mechanism, kept as one implementation
    },
    /** Resolves a local object URL for previewing a File before upload. Caller should URL.revokeObjectURL() when done with it. */
    preview(file) {
      return new Promise((resolve, reject) => {
        if (!file) { reject(new Error('No file provided')); return; }
        try { resolve(URL.createObjectURL(file)); } catch (error) { reject(error); }
      });
    },
  };

  // ---------------------------------------------------------------
  // share (Phase 8) — Web Share API with a copy-to-clipboard fallback.
  // ---------------------------------------------------------------
  async function fallbackCopy(text) {
    try {
      await navigator.clipboard.writeText(text);
      if (typeof showToast === 'function') showToast('Copied to clipboard');
      return true;
    } catch (error) {
      // Last-resort fallback for browsers with neither Web Share nor
      // the async Clipboard API (very old WebViews).
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      let copied = false;
      try { copied = document.execCommand('copy'); } catch (e) { /* no-op */ }
      document.body.removeChild(textarea);
      if (copied && typeof showToast === 'function') showToast('Copied to clipboard');
      return copied;
    }
  }

  const share = {
    async shareText(text, title = 'MohToh') {
      if (navigator.share) {
        try { await navigator.share({ title, text }); return true; } catch (error) { return false; } // user cancelled — not an error
      }
      return fallbackCopy(text);
    },
    async shareLink(url, title = 'MohToh', text = '') {
      if (navigator.share) {
        try { await navigator.share({ title, text, url }); return true; } catch (error) { return false; }
      }
      return fallbackCopy(url);
    },
  };

  // ---------------------------------------------------------------
  // storage (Phase 9) — UI preferences / temp state only. Structurally
  // refuses anything that looks like a secret (Phase 15) rather than
  // relying on every caller remembering the rule.
  // ---------------------------------------------------------------
  const SENSITIVE_KEY_PATTERN = /password|token|secret|wallet|card|cvv|pin|auth/i;
  const STORAGE_PREFIX = 'mohtoh_';

  const storage = {
    save(key, value) {
      if (SENSITIVE_KEY_PATTERN.test(key)) {
        console.error(`[MohToh.storage] Refused to save "${key}" — looks like a secret. Session/financial state belongs on the server, not localStorage.`);
        return false;
      }
      try {
        localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value));
        return true;
      } catch (error) {
        console.error('[MohToh.storage] save failed (storage full or unavailable):', error);
        return false;
      }
    },
    get(key, defaultValue = null) {
      try {
        const raw = localStorage.getItem(STORAGE_PREFIX + key);
        return raw === null ? defaultValue : JSON.parse(raw);
      } catch (error) {
        return defaultValue;
      }
    },
    remove(key) {
      try { localStorage.removeItem(STORAGE_PREFIX + key); } catch (error) { /* no-op */ }
    },
  };

  // ---------------------------------------------------------------
  // biometric — stub. No standardized cross-browser biometric API
  // exists (WebAuthn is a different, credential-based flow, not a
  // simple "authenticate" call) — this deliberately always reports
  // unavailable on web rather than faking a capability that doesn't
  // exist. A MAUI build replaces this with real native
  // Face ID/fingerprint calls under the exact same two methods.
  // ---------------------------------------------------------------
  const biometric = {
    async isAvailable() { return false; },
    async authenticate() { return { success: false, reason: 'Biometric authentication is not available in the web app.' }; },
  };

  // ---------------------------------------------------------------
  // notification (Phase 3) — assembled after MohToh.sound exists
  // (sound-manager.js loads before this file).
  // ---------------------------------------------------------------
  const notification = {
    _permission: (typeof Notification !== 'undefined') ? Notification.permission : 'unsupported',

    /** Reads current permission state without prompting. Call once on app load. */
    initialize() {
      notification._permission = (typeof Notification !== 'undefined') ? Notification.permission : 'unsupported';
      return notification._permission;
    },
    async requestPermission() {
      if (typeof Notification === 'undefined') { notification._permission = 'unsupported'; return 'unsupported'; }
      try {
        const result = await Notification.requestPermission();
        notification._permission = result;
        return result;
      } catch (error) {
        return notification._permission;
      }
    },
    /** Shows a system notification if permitted; always safe to call — no-ops silently otherwise (in-app toasts/the notification bell remain the primary in-app channel; this is the OS-level layer on top). */
    show(title, options = {}) {
      if (typeof Notification === 'undefined' || notification._permission !== 'granted') return null;
      try {
        return new Notification(title, { icon: '/Mostmerit/merit_drives/assets/images/icon-192.png', badge: '/Mostmerit/merit_drives/assets/images/icon-192.png', ...options });
      } catch (error) {
        console.warn('[MohToh.notification] show() failed:', error);
        return null;
      }
    },
    playSound(type) {
      if (window.MohToh && window.MohToh.sound) window.MohToh.sound.play(type);
    },
    vibrate(pattern = 200) {
      if (navigator.vibrate) {
        try { navigator.vibrate(pattern); } catch (error) { /* no-op — some browsers throw for disallowed patterns */ }
      }
    },
    /** Sets the app icon badge count (Badging API — Chrome/Edge on Android/desktop only). No-ops elsewhere. */
    setBadge(count) {
      if (navigator.setAppBadge) {
        (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
      }
    },
  };

  // ---------------------------------------------------------------
  // Merge into any existing window.MohToh (sound-manager.js sets
  // MohToh.sound) instead of replacing it outright — load order
  // between this file and sound-manager.js must not matter, and
  // nothing here should ever silently delete another module's
  // namespace.
  window.MohToh = Object.assign(window.MohToh || {}, { device, notification, location, camera, files, share, storage, biometric, network });
})();

(function () {
  'use strict';

  if (window.MohToh && window.MohToh.device && window.MohToh.device.isMaui()) return;
  if (!('serviceWorker' in navigator)) return;

  // Resolve the app root dynamically to support both local subdirectory (XAMPP) and production
  // domain root. This script always lives at <app root>/assets/js/pwa-init.js, so its own URL
  // identifies the app root no matter how deeply nested the current page is (/driver/, /rider/, …).
  // Deriving it from window.location instead would resolve to /driver/service-worker.js and 404.
  function resolveAppRoot() {
    const MARKER = 'assets/js/pwa-init.js';
    let src = (document.currentScript && document.currentScript.src) || '';
    if (src.indexOf(MARKER) === -1) {
      const tag = document.querySelector('script[src*="' + MARKER + '"]');
      src = (tag && tag.src) || '';
    }
    const idx = src.indexOf(MARKER);
    if (idx !== -1) return src.substring(0, idx); // absolute URL, always ends in '/'

    // Last resort (script renamed/inlined): current directory, correct on root-level pages.
    const path = window.location.pathname;
    return path.substring(0, path.lastIndexOf('/') + 1);
  }

  const appRoot = resolveAppRoot();

  window.addEventListener('load', function () {
    // Explicit scope === the service worker's own directory (the app root), so the registration
    // controls every page in the app under both deployments.
    navigator.serviceWorker.register(appRoot + 'service-worker.js', { scope: appRoot })
      .catch(function (err) {
        console.warn('[pwa-init] Service worker registration failed:', err);
      });
  });

  // PWA Install Prompt Logic
  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    // Prevent Chrome 67 and earlier from automatically showing the prompt
    e.preventDefault();
    // Stash the event so it can be triggered later.
    deferredPrompt = e;
    
    // Show the install buttons
    const installBtn = document.getElementById('pwaInstallBtn');
    const sidebarInstallBtn = document.getElementById('pwaSidebarInstallBtn');
    
    if (installBtn) {
      installBtn.classList.remove('u-hidden');
    }
    if (sidebarInstallBtn) {
      sidebarInstallBtn.classList.remove('u-hidden');
    }
  });

  function handleInstallClick() {
    if (!deferredPrompt) return;
    // Show the prompt
    deferredPrompt.prompt();
    // Wait for the user to respond to the prompt
    deferredPrompt.userChoice.then(function (choiceResult) {
      if (choiceResult.outcome === 'accepted') {
        console.log('[pwa-init] User accepted the install prompt');
      } else {
        console.log('[pwa-init] User dismissed the install prompt');
      }
      deferredPrompt = null;
      
      // Hide the install buttons
      const installBtn = document.getElementById('pwaInstallBtn');
      const sidebarInstallBtn = document.getElementById('pwaSidebarInstallBtn');
      if (installBtn) installBtn.classList.add('u-hidden');
      if (sidebarInstallBtn) sidebarInstallBtn.classList.add('u-hidden');
    });
  }

  // Setup click listeners when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initInstallButtons);
  } else {
    initInstallButtons();
  }

  function initInstallButtons() {
    const installBtn = document.getElementById('pwaInstallBtn');
    const sidebarInstallBtn = document.getElementById('pwaSidebarInstallBtn');
    
    if (installBtn) {
      installBtn.addEventListener('click', handleInstallClick);
    }
    if (sidebarInstallBtn) {
      sidebarInstallBtn.addEventListener('click', handleInstallClick);
    }
  }

  window.addEventListener('appinstalled', function (evt) {
    console.log('[pwa-init] App installed successfully');
    deferredPrompt = null;
    const installBtn = document.getElementById('pwaInstallBtn');
    const sidebarInstallBtn = document.getElementById('pwaSidebarInstallBtn');
    if (installBtn) installBtn.classList.add('u-hidden');
    if (sidebarInstallBtn) sidebarInstallBtn.classList.add('u-hidden');
  });
})();

// service-worker.js — MohToh PWA
//
// Dynamically calculate BASE path to support local subdirectories (XAMPP) and production domain root
const BASE = self.location.pathname.substring(0, self.location.pathname.lastIndexOf('/') + 1);

const CACHE_VERSION = 'v4';
const STATIC_CACHE = `mohtoh-static-${CACHE_VERSION}`;
const SHELL_CACHE = `mohtoh-shell-${CACHE_VERSION}`;
const CURRENT_CACHES = [STATIC_CACHE, SHELL_CACHE];

const PRECACHE_URLS = [
  `${BASE}offline.html`,
  `${BASE}assets/css/variables.css`,
  `${BASE}assets/css/reset.css`,
  `${BASE}assets/css/layout.css`,
  `${BASE}assets/css/components.css`,
  `${BASE}assets/css/mobile-shell.css`,
  `${BASE}assets/css/utilities.css`,
  `${BASE}assets/css/animations.css`,
  `${BASE}assets/css/responsive.css`,
  `${BASE}assets/js/helper.js`,
  `${BASE}assets/js/api-client.js`,
  `${BASE}assets/js/state.js`,
  `${BASE}assets/js/mohtoh-bridge.js`,
  `${BASE}assets/js/sound-manager.js`,
  `${BASE}assets/icons/icon-192.png`,
  `${BASE}assets/icons/icon-512.png`,
];

// NEVER cache sensitive pages, logged-in user data, APIs, payments, or bookings.
// These must always be loaded directly from the network.
const NEVER_CACHE_PATTERNS = [
  /\/api\//i,         // API calls
  /wallet/i,          // Wallet balance and transactions
  /paystack/i,        // Payment gateway integration
  /payment/i,         // Core payment forms/handlers
  /profile\.php/i,    // Profile pages (user settings)
  /admin\//i,         // Admin dashboard and controls
  /booking/i,         // Ride booking pages and states
  /book/i,            // Seat booking paths
];

function isNeverCache(url) {
  return NEVER_CACHE_PATTERNS.some((pattern) => pattern.test(url));
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names.filter((name) => !CURRENT_CACHES.includes(name)).map((name) => caches.delete(name))
      ))
      .then(() => self.clients.claim())
  );
});

function isStaticAsset(url) {
  return /\.(css|js|png|jpg|jpeg|svg|webp|woff2?|ttf|wav|mp3)$/i.test(url);
}

const PRECACHE_URL_SET = new Set(PRECACHE_URLS);

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return; // Only cache GET requests — POST/PUT/DELETE always direct to network

  const url = request.url;

  // 1. Bypass cache entirely for sensitive or dynamic patterns
  if (isNeverCache(url)) {
    event.respondWith(fetch(request));
    return;
  }

  // 2. Network-first strategy for page navigations, falling back to offline.html
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match(`${BASE}offline.html`))
    );
    return;
  }

  // 3. Cache-first strategy for versioned static shell files
  if (isStaticAsset(url) && PRECACHE_URL_SET.has(new URL(url).pathname)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
          }
          return response;
        }).catch(() => cached);
      })
    );
    return;
  }

  // 4. Stale-while-revalidate or Network-first fallback for general static assets
  if (isStaticAsset(url)) {
    event.respondWith(
      fetch(request).then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
        }
        return response;
      }).catch(() => caches.match(request))
    );
    return;
  }

  // 5. Default: Network only for safety on any unclassified requests
  event.respondWith(fetch(request));
});

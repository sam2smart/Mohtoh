// assets/js/helper.js
// Shared, framework-free DOM and formatting helpers for the redesigned
// screens. Each new screen should reuse these rather than redefining
// its own escapeHtml/openSheet/showAlert.

/** Shorthand querySelector / querySelectorAll */
function qs(selector, scope = document) { return scope.querySelector(selector); }
function qsa(selector, scope = document) { return Array.from(scope.querySelectorAll(selector)); }

/** Escape untrusted text before inserting via innerHTML. */
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
}

/** POST a JSON body to one of this app's api/*.php endpoints, return the parsed response. */
async function postJson(url, payload) {
  // Mirrors assets/js/api-client.js's _mtApiRequest(): send the page's
  // CSRF token as a header on every POST, harmless on an endpoint that
  // doesn't check it yet, required on any that has adopted require_csrf()
  // (includes/functions.php).
  const csrfToken = (typeof document !== 'undefined' && document.querySelector('meta[name="csrf-token"]')?.content) || '';
  const headers = { 'Content-Type': 'application/json' };
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(payload) });
  return res.json();
}

// Intelligent Trip Engine / Trips module. Never show ride_shares.trip_state
// (or ride_share_bookings.passenger_state) raw — every screen translates
// it through here first, so the driver app and rider app always describe
// the exact same backend state consistently, just phrased for whichever
// side is looking at it (a driver "arriving" is the same moment a rider
// experiences as "driver has arrived").
const MT_TRIP_STATE_LABELS_DRIVER = {
  CREATED: 'Preparing ride',
  PUBLISHED: 'Waiting for passengers to join',
  PASSENGERS_JOINING: 'Passengers are joining',
  PASSENGERS_ASSEMBLED: 'Passengers are ready',
  TRIP_STARTED: 'Trip is currently running',
  PASSENGER_DROPPING: 'Dropping passengers',
  AUTO_COMPLETED_PENDING: 'Awaiting your confirmation',
  TRIP_COMPLETED: 'Trip completed',
  CANCELLED: 'Trip cancelled',
};
const MT_TRIP_STATE_LABELS_RIDER = {
  CREATED: 'Ride being prepared',
  PUBLISHED: 'Waiting for a driver',
  PASSENGERS_JOINING: 'Driver is on the way',
  PASSENGERS_ASSEMBLED: 'Driver has arrived',
  TRIP_STARTED: 'Your trip has started',
  PASSENGER_DROPPING: 'Arriving at destination',
  AUTO_COMPLETED_PENDING: 'Trip wrapping up',
  TRIP_COMPLETED: 'Trip completed',
  CANCELLED: 'Trip cancelled',
};
function formatTripStateLabel(state, viewpoint = 'driver') {
  const map = viewpoint === 'rider' ? MT_TRIP_STATE_LABELS_RIDER : MT_TRIP_STATE_LABELS_DRIVER;
  return map[state] || (state ? state.replace(/_/g, ' ') : '—');
}

// Same idea for a passenger's own granular state.
const MT_PASSENGER_STATE_LABELS = {
  booked: 'Booked',
  navigating: 'Walking to pickup',
  arrived: 'Waiting at pickup',
  boarded: 'Onboard',
  dropped_off: 'Dropped off',
  completed: 'Trip completed',
  no_show: 'Marked as no-show',
  cancelled: 'Cancelled',
};
function formatPassengerStateLabel(state) {
  return MT_PASSENGER_STATE_LABELS[state] || (state ? state.replace(/_/g, ' ') : '—');
}

/** Show/update an inline alert banner. `el` must have the .alert-banner structure. */
function showAlert(el, type, message) {
  if (!el) return;
  el.classList.remove('is-hidden', 'success', 'error');
  el.classList.add(type === 'success' ? 'success' : 'error');
  el.textContent = message;
}

function hideAlert(el) {
  if (!el) return;
  el.classList.add('is-hidden');
}

/** Bottom sheet open/close, paired with a scrim element (data-sheet-scrim). */
function openSheet(sheetEl, scrimEl) {
  if (!sheetEl) return;
  scrimEl?.classList.add('is-open');
  sheetEl.classList.add('is-open');
  document.body.style.overflow = 'hidden';
}

function closeSheet(sheetEl, scrimEl) {
  if (!sheetEl) return;
  scrimEl?.classList.remove('is-open');
  sheetEl.classList.remove('is-open');
  document.body.style.overflow = '';
}

/** Brief, non-blocking toast — used for "coming soon" affordances and quick confirmations. */
let __toastTimer = null;
function showToast(message, tone = 'default') {
  let toastEl = qs('#mtToast');
  if (!toastEl) {
    toastEl = document.createElement('div');
    toastEl.id = 'mtToast';
    toastEl.className = 'mt-toast';
    document.body.appendChild(toastEl);
  }
  toastEl.textContent = message;
  toastEl.dataset.tone = tone;
  toastEl.classList.add('is-visible');
  clearTimeout(__toastTimer);
  __toastTimer = setTimeout(() => toastEl.classList.remove('is-visible'), 2400);
}

/**
 * Intelligent Hybrid Location Search — address suggestion list for a
 * text input, optionally filling hidden lat/lng inputs on selection.
 * Backed by the single api/location-search.php endpoint, which already
 * merges and ranks MohToh's own locations, approved driver-submitted
 * spots, and worldwide external geocoding (Nominatim/OpenStreetMap) —
 * this function only needs to render whatever it returns, debounce
 * keystrokes, and remember recent picks. Every screen that needs
 * pickup/destination autocomplete shares this one implementation.
 */
// Resolved once per page load (not once per field, and not per
// keystroke) so distance-aware ranking is available quickly without
// spamming the geolocation permission prompt or blocking typing.
let __mtSearchPositionPromise = null;
function mtSearchGetPosition() {
  if (__mtSearchPositionPromise) return __mtSearchPositionPromise;
  __mtSearchPositionPromise = (window.MohToh && window.MohToh.location
    ? window.MohToh.location.getCurrentLocation({ timeout: 4000, maximumAge: 5 * 60 * 1000 })
    : Promise.reject(new Error('MohToh bridge unavailable'))
  )
    .then((position) => ({ lat: position.latitude, lng: position.longitude }))
    .catch(() => null);
  return __mtSearchPositionPromise;
}

const MT_RECENT_SEARCH_KEY = 'mohtoh_recent_locations';
const MT_RECENT_SEARCH_MAX = 5;

function mtGetRecentSearches() {
  try {
    return JSON.parse(localStorage.getItem(MT_RECENT_SEARCH_KEY)) || [];
  } catch (error) {
    return [];
  }
}

function mtSaveRecentSearch(result) {
  try {
    const existing = mtGetRecentSearches().filter((r) => r.address !== result.address);
    existing.unshift(result);
    localStorage.setItem(MT_RECENT_SEARCH_KEY, JSON.stringify(existing.slice(0, MT_RECENT_SEARCH_MAX)));
  } catch (error) {
    // Storage full/unavailable — recent-search caching just no-ops.
  }
}

// ---------------------------------------------------------------
// Google Maps / Places — lazy loader, map-centering helper, and
// location-share helper used by the hybrid autocomplete below.
// The Google Maps JS API is loaded dynamically on first search
// keystroke (once per page) via the session-guarded api/maps-key.php
// endpoint so no API key is ever hardcoded in JavaScript.
// ---------------------------------------------------------------

/** Inject share-button styles once, on first use. */
(function () {
  if (document.getElementById('mt-location-share-styles')) return;
  const s = document.createElement('style');
  s.id = 'mt-location-share-styles';
  s.textContent = [
    '.mt-location-share-btn{display:flex;align-items:center;gap:6px;margin-top:6px;',
    'padding:6px 14px;font-size:13px;font-family:inherit;cursor:pointer;border-radius:20px;',
    'color:var(--color-primary,#0F8B8D);background:rgba(15,139,141,.08);',
    'border:1px solid rgba(15,139,141,.25);transition:background .15s;}',
    '.mt-location-share-btn:hover{background:rgba(15,139,141,.15);}',
  ].join('');
  document.head.appendChild(s);
}());

let __mtGoogleMapsPromise = null;

/**
 * Load the Google Maps JS API (Places + Markers libraries) once per page.
 * Key is fetched from the session-guarded api/maps-key.php — never
 * hardcoded here. Rejects (silently) if the key is absent or the
 * network fails, so the Nominatim fallback takes over automatically.
 * Resolves to window.google.maps.
 */
async function mtLoadGoogleMaps() {
  if (__mtGoogleMapsPromise) return __mtGoogleMapsPromise;
  __mtGoogleMapsPromise = (async () => {
    if (window.google && window.google.maps && window.google.maps.places) {
      return window.google.maps;
    }
    let key;
    try {
      const res = await fetch('../api/maps-key.php');
      const json = await res.json();
      key = json && json.key;
    } catch (_) { /* network failure — fall through to Nominatim */ }
    if (!key) throw new Error('mt-no-maps-key');

    await new Promise((resolve, reject) => {
      const cbName = '__mtGoogleMapsReady' + Date.now();
      window[cbName] = () => { resolve(); delete window[cbName]; };
      const script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key='
        + encodeURIComponent(key)
        + '&libraries=places,marker&callback=' + cbName; // marker = AdvancedMarkerElement
      script.async = true;
      script.onerror = () => { reject(new Error('mt-maps-load-fail')); delete window[cbName]; };
      document.head.appendChild(script);
    });
    return window.google.maps;
  })();
  // If loading fails, reset so a later retry is possible.
  __mtGoogleMapsPromise.catch(() => { __mtGoogleMapsPromise = null; });
  return __mtGoogleMapsPromise;
}

/**
 * Center the existing MohToh Google map on the selected coordinates
 * and move the user marker to that point.
 * Safe to call even when the map is absent (other pages without a map).
 */
function mtCenterMapOnSelectedLocation(lat, lng) {
  try {
    if (typeof placeUserMarker === 'function') placeUserMarker({ lat, lng });
    if (typeof mtMap !== 'undefined' && mtMap) {
      if (typeof mtMap.setCenter === 'function') {
        mtMap.setCenter({ lat: Number(lat), lng: Number(lng) });
        mtMap.setZoom(15);
      }
    }
  } catch (_) { /* map not mounted on this page — safe to ignore */ }
}

/**
 * Reverse-geocode a coordinate pair → {address, formatted_address, city,
 * state, country, latitude, longitude} using the Google Geocoding API.
 * Falls back to Nominatim (keyless) if Google is unavailable or returns
 * nothing, so the old behavior is preserved during outages.
 * Resolves to null on total failure.
 */
async function mtReverseGeocode(lat, lng) {
  const fallbackNominatim = async () => {
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
      if (!res.ok) return null;
      const data = await res.json();
      if (!data || !data.display_name) return null;
      return {
        address: String(data.display_name).split(',').slice(0, 2).join(','),
        formatted_address: data.display_name,
        city: data.address?.city || data.address?.town || data.address?.village || null,
        state: data.address?.state || null,
        country: data.address?.country || null,
        latitude: Number(data.lat ?? lat),
        longitude: Number(data.lon ?? lng)
      };
    } catch (_) { return null; }
  };

  try {
    const google = await mtLoadGoogleMaps();
    if (!google || !google.Geocoder) return fallbackNominatim();
    // Geocoder is a constructor — geocode() lives on the instance, not on the
    // class. Calling it statically threw every time, so this always ended up
    // in the Nominatim fallback below and Google was never actually used.
    const geocoder = new google.Geocoder();
    const result = await new Promise((resolve) => {
      geocoder.geocode({ location: { lat: Number(lat), lng: Number(lng) } }, (results, status) => {
        if (status === 'OK' && results && results[0]) resolve(results[0]);
        else resolve(null);
      });
    });
    if (!result) return fallbackNominatim();

    const components = {};
    (result.address_components || []).forEach((comp) => {
      (comp.types || []).forEach((t) => { components[t] = comp.long_name; });
    });
    const formatted = result.formatted_address || '';
    return {
      address: String(formatted).split(',').slice(0, 2).join(','),
      formatted_address: formatted,
      city: components.locality || components.postal_town || components.city || null,
      state: components.administrative_area_level_1 || null,
      country: components.country || null,
      latitude: Number(result.geometry?.location?.lat() ?? lat),
      longitude: Number(result.geometry?.location?.lng() ?? lng)
    };
  } catch (_) {
    return fallbackNominatim();
  }
}

/**
 * Attach (or replace) a share chip below a location search wrapper.
 * Uses native navigator.share on mobile, clipboard copy as fallback.
 * Share content includes place name, full address, and Google Maps URL.
 */
function mtShowLocationShare(wrapperEl, result) {
  wrapperEl.querySelector('.mt-location-share-btn')?.remove();
  if (!result || !result.lat || !result.lng) return;

  const lat = Number(result.lat);
  const lng = Number(result.lng);
  const mapsUrl = `https://maps.google.com/?q=${lat},${lng}`;
  const lines = [];
  if (result.name && result.name !== result.address) lines.push(result.name);
  if (result.address) lines.push(result.address);
  lines.push('');
  lines.push(mapsUrl);
  const shareText = lines.join('\n');

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'mt-location-share-btn';
  btn.innerHTML = '<i class="bi bi-share-fill"></i> Share location';
  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    const shareData = { title: result.name || result.address, text: shareText, url: mapsUrl };
    try {
      if (navigator.share) { await navigator.share(shareData); return; }
      throw new Error('no-native-share');
    } catch (_) {
      try {
        await navigator.clipboard.writeText(shareText);
        if (typeof showToast === 'function') showToast('Location copied to clipboard');
      } catch (_2) {
        if (typeof showToast === 'function') showToast('Share: ' + mapsUrl);
      }
    }
  });
  wrapperEl.appendChild(btn);
}

/**
 * `options.mohtoh` is accepted for backward compatibility with existing
 * call sites but no longer changes behavior: the hybrid engine always
 * decides source mix and ranking server-side now.
 */
function initAddressAutocomplete(inputEl, latInput, lngInput, onSelect, options = {}) {
  if (!inputEl) return;

  const wrapper = document.createElement('div');
  wrapper.className = 'location-autocomplete-wrapper';
  inputEl.parentNode.insertBefore(wrapper, inputEl);
  wrapper.appendChild(inputEl);

  const suggestionList = document.createElement('div');
  suggestionList.className = 'location-autocomplete-list u-hidden';
  wrapper.appendChild(suggestionList);

  let debounceTimer = null;
  let requestToken = 0;
  const hideSuggestions = () => { suggestionList.innerHTML = ''; suggestionList.classList.add('u-hidden'); };
  const showSuggestions = () => suggestionList.classList.remove('u-hidden');

  mtSearchGetPosition(); // fire early so it's usually resolved by the first real search

  const selectSuggestion = (result) => {
    inputEl.value = result.address;
    if (latInput) latInput.value = result.lat;
    if (lngInput) lngInput.value = result.lng;
    hideSuggestions();
    mtSaveRecentSearch(result);
    // Log search to database
    postJson('../api/log-search.php', { location: result.address, lat: result.lat, lng: result.lng });

    // Center the existing Google map and move the marker to the chosen location
    if (result.lat && result.lng) {
      mtCenterMapOnSelectedLocation(Number(result.lat), Number(result.lng));
    }
    // Show a share chip below this input so the driver can share the location
    mtShowLocationShare(wrapper, result);
    if (typeof onSelect === 'function') onSelect(result);
  };

  function renderResultItem(item, extraAttrs) {
    const distanceLabel = (item.distance_km !== null && item.distance_km !== undefined)
      ? `<span class="location-autocomplete-item__distance">${item.distance_km < 1 ? Math.round(item.distance_km * 1000) + ' m' : item.distance_km + ' km'}</span>`
      : '';
    return `
      <button type="button" class="location-autocomplete-item location-autocomplete-item--rich" ${extraAttrs}>
        <i class="bi ${item.category_icon || 'bi-geo-alt'}" style="color:var(--color-primary);margin-right:6px;"></i>
        <span class="location-autocomplete-item__body">
          <span class="location-autocomplete-item__row">
            <strong>${escapeHtml(item.name)}</strong>
            ${item.category ? `<span class="u-text-faint u-text-xs">${escapeHtml(item.category)}</span>` : ''}
          </span>
          ${item.address ? `<span class="u-text-faint u-text-xs" style="display:block;">${escapeHtml(item.address)}</span>` : ''}
        </span>
        ${distanceLabel}
      </button>
    `;
  }

  function renderRecent() {
    const recent = mtGetRecentSearches();
    if (!recent.length) { hideSuggestions(); return; }
    suggestionList.innerHTML = '<div class="location-autocomplete-group">Recent</div>'
      + recent.map((r, i) => renderResultItem(r, `data-recent-index="${i}"`)).join('');
    showSuggestions();
  }

  /**
   * fetchSuggestions — hybrid Google Places → Nominatim fallback.
   *
   * @param {string}  query         The text the user typed or pasted.
   * @param {boolean} preferGeocode True when triggered by a paste event:
   *                                uses the Geocoding API for a full address
   *                                rather than the AutocompleteService.
   */
  async function fetchSuggestions(query, preferGeocode = false) {
    const cleaned = query.trim();
    if (cleaned.length < 2) { hideSuggestions(); return; }

    const myToken = ++requestToken;
    // Resolve GPS position but never block the user for more than 300 ms
    const position = await Promise.race([
      mtSearchGetPosition(),
      new Promise((resolve) => setTimeout(() => resolve(null), 300)),
    ]);
    if (myToken !== requestToken) return;

    // ---- Primary: Google Places ----
    let googleSucceeded = false;
    try {
      const gm = await Promise.race([
        mtLoadGoogleMaps(),
        new Promise((_, reject) => setTimeout(() => reject(new Error('gm-timeout')), 2500)),
      ]);
      if (myToken !== requestToken) return;

      // Bias: driver's GPS if available, otherwise Redemption Camp, Mowe, Ogun State
      const biasLocation = position
        ? new gm.LatLng(position.lat, position.lng)
        : new gm.LatLng(6.9083, 3.3870);

      if (preferGeocode) {
        // Full pasted address → Geocoder returns one or more precise results
        const geocoder = new gm.Geocoder();
        const gcResults = await new Promise((resolve) => {
          geocoder.geocode(
            { address: cleaned, componentRestrictions: { country: 'ng' } },
            (res, status) => resolve(status === 'OK' ? res : [])
          );
        });
        if (myToken !== requestToken) return;

        if (gcResults.length) {
          const mapped = gcResults.slice(0, 6).map((r) => ({
            name: (r.address_components && r.address_components[0])
              ? r.address_components[0].long_name
              : cleaned.split(',')[0],
            category: 'Place',
            category_icon: 'bi-geo-alt',
            address: r.formatted_address,
            latitude: r.geometry.location.lat(),
            longitude: r.geometry.location.lng(),
            place_id: r.place_id,
            type: (r.types || [])[0] || 'place',
            source: 'google',
            _hasCoords: true,
          }));
          __renderGoogleResults(mapped, myToken);
          googleSucceeded = true;
        }
      } else {
        // Normal typing → AutocompleteService with Nigeria restriction + location bias
        const svc = new gm.places.AutocompleteService();
        const predictions = await new Promise((resolve) => {
          svc.getPlacePredictions(
            {
              input: cleaned,
              componentRestrictions: { country: 'ng' },
              location: biasLocation,
              radius: 50000,
            },
            (res, status) => resolve(
              status === gm.places.PlacesServiceStatus.OK && res ? res : []
            )
          );
        });
        if (myToken !== requestToken) return;

        if (predictions.length) {
          const mapped = predictions.map((p) => ({
            name: (p.structured_formatting && p.structured_formatting.main_text)
              || p.description.split(',')[0],
            category: 'Place',
            category_icon: 'bi-geo-alt',
            address: p.description,
            latitude: null,    // resolved on selection via getDetails
            longitude: null,
            place_id: p.place_id,
            type: (p.types || [])[0] || 'place',
            source: 'google',
            _hasCoords: false,
          }));
          __renderGoogleResults(mapped, myToken);
          googleSucceeded = true;
        }
      }
    } catch (_googleErr) {
      // Google Places unavailable, quota exceeded, or network error —
      // fall through silently to the Nominatim fallback below.
    }

    if (googleSucceeded || myToken !== requestToken) return;

    // ---- Fallback: api/location-search.php (Nominatim / MohToh DB) ----
    const params = { q: cleaned };
    if (position) { params.lat = position.lat; params.lng = position.lng; }

    let data;
    try {
      data = await apiGet('../api/location-search.php', params);
    } catch (_) {
      data = null;
    }
    if (myToken !== requestToken) return;

    const results = data && data.status === 'success' ? (data.data.results || []) : [];

    if (!results.length) {
      suggestionList.innerHTML = '<div class="location-autocomplete-item u-text-faint">No matching addresses found.</div>';
      showSuggestions();
      return;
    }

    const didYouMean = data.data.did_you_mean;
    let html = didYouMean
      ? `<div class="location-autocomplete-group">Showing results for "${escapeHtml(didYouMean)}"</div>`
      : '';
    html += results.map((item, i) => renderResultItem(item, `data-result-index="${i}"`)).join('');

    suggestionList.dataset.results = JSON.stringify(results.map((r) => ({
      address: r.name, lat: r.latitude, lng: r.longitude, source: r.source,
      category: r.category, type: r.type, place_id: r.place_id,
    })));
    suggestionList.dataset.googleResults = '[]';
    suggestionList.innerHTML = html;
    showSuggestions();
  }

  /** Render a list of Google Places results into the suggestion dropdown. */
  function __renderGoogleResults(items, myToken) {
    if (myToken !== requestToken) return;
    suggestionList.dataset.googleResults = JSON.stringify(items.map((r) => ({
      name: r.name, address: r.address,
      lat: r.latitude, lng: r.longitude,
      source: 'google', category: r.category,
      type: r.type, place_id: r.place_id,
      _hasCoords: r._hasCoords,
    })));
    suggestionList.dataset.results = '[]';
    suggestionList.innerHTML = items.map((item, i) => renderResultItem(
      { name: item.name, address: item.address, category: item.category, category_icon: item.category_icon },
      `data-google-index="${i}"`
    )).join('');
    showSuggestions();
  }

  // ---- Paste detection: use Geocoding API for full pasted addresses ----
  let __isPaste = false;
  inputEl.addEventListener('paste', () => { __isPaste = true; });

  inputEl.addEventListener('input', () => {
    const wasPaste = __isPaste;
    __isPaste = false;
    if (debounceTimer) clearTimeout(debounceTimer);
    if (!inputEl.value.trim()) { renderRecent(); return; }
    
    // Show loading state
    suggestionList.innerHTML = '<div class="location-autocomplete-item u-text-faint">Searching…</div>';
    showSuggestions();

    // Shorter debounce for paste (text is already complete) + prefer Geocoder
    debounceTimer = setTimeout(
      () => fetchSuggestions(inputEl.value, wasPaste),
      wasPaste ? 100 : 300
    );
  });
  inputEl.addEventListener('focus', () => {
    if (inputEl.value.trim()) fetchSuggestions(inputEl.value);
    else renderRecent();
  });
  inputEl.addEventListener('blur', () => setTimeout(hideSuggestions, 150));
  suggestionList.addEventListener('mousedown', (e) => {
    const btn = e.target.closest('.location-autocomplete-item');
    if (!btn) return;
    e.preventDefault();

    if (btn.dataset.recentIndex !== undefined) {
      const recent = mtGetRecentSearches();
      const picked = recent[Number(btn.dataset.recentIndex)];
      if (picked) selectSuggestion(picked);
      return;
    }

    // ---- Google Places result (Geocoder: coords already known) ----
    if (btn.dataset.googleIndex !== undefined) {
      let googleResults;
      try { googleResults = JSON.parse(suggestionList.dataset.googleResults || '[]'); } catch (_) { return; }
      const picked = googleResults[Number(btn.dataset.googleIndex)];
      if (!picked) return;

      if (picked._hasCoords && picked.lat != null && picked.lng != null) {
        // Geocoder result — coordinates already in hand
        selectSuggestion({
          address: picked.address, lat: picked.lat, lng: picked.lng,
          name: picked.name, source: 'google',
          category: picked.category, type: picked.type, place_id: picked.place_id,
        });
      } else {
        // AutocompleteService prediction — resolve coordinates via getDetails
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading…';
        mtLoadGoogleMaps()
          .then((gm) => {
            const svc = new gm.places.PlacesService(document.createElement('div'));
            svc.getDetails(
              { placeId: picked.place_id, fields: ['name', 'formatted_address', 'geometry', 'place_id'] },
              (place, status) => {
                if (status === gm.places.PlacesServiceStatus.OK && place && place.geometry) {
                  selectSuggestion({
                    address: place.formatted_address || picked.address,
                    lat: place.geometry.location.lat(),
                    lng: place.geometry.location.lng(),
                    name: place.name || picked.name,
                    source: 'google', category: picked.category,
                    type: picked.type, place_id: place.place_id || picked.place_id,
                  });
                } else {
                  // getDetails failed — fall back to Nominatim on the place name
                  btn.innerHTML = origHtml;
                  fetchSuggestions(picked.name || picked.address);
                }
              }
            );
          })
          .catch(() => { btn.innerHTML = origHtml; fetchSuggestions(picked.name || picked.address); });
      }
      return;
    }

    // ---- Nominatim / MohToh DB result ----
    if (btn.dataset.resultIndex !== undefined) {
      try {
        const results = JSON.parse(suggestionList.dataset.results || '[]');
        const picked = results[Number(btn.dataset.resultIndex)];
        if (picked) selectSuggestion(picked);
      } catch (_) { /* no-op */ }
    }
  });
}

/**
 * Format a 24-hour "HH:MM" or "HH:MM:SS" string (what the DB stores
 * and what a native <input type="time"> value always is, regardless
 * of the browser's display locale) as 12-hour "h:MM AM/PM" — MohToh
 * always displays times this way, never 24-hour, per Africa/Lagos
 * ride-time conventions. Storage format is untouched; this is display
 * only. Returns the input unchanged if it doesn't look like a time.
 */
function formatTime12h(timeStr) {
  if (!timeStr) return '';
  const match = String(timeStr).match(/^(\d{1,2}):(\d{2})/);
  if (!match) return timeStr;
  let hours = parseInt(match[1], 10);
  const minutes = match[2];
  const suffix = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12;
  if (hours === 0) hours = 12;
  return `${hours}:${minutes} ${suffix}`;
}

/** ₦-formatted amount, 2 decimal places, locale-grouped. Single shared
 * implementation — was previously duplicated (as money()/fmtNaira()/
 * naira()) across driver.js, driver-trips.js, rider-trips.js and
 * rider-trip.js. */
function formatNaira(amount) {
  return `₦${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** "Today" / "Yesterday" / "12 Jan" relative label for a DATETIME string.
 * Single shared implementation — was previously duplicated across
 * driver.js and rider-trip.js. */
function relativeDateLabel(dateTimeStr) {
  const d = new Date(String(dateTimeStr).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return dateTimeStr;
  const startOfDay = (dt) => new Date(dt.getFullYear(), dt.getMonth(), dt.getDate()).getTime();
  const diffDays = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
  if (diffDays === 0) return 'Today';
  if (diffDays === 1) return 'Yesterday';
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

/** "Wed, Jan 15" label for a DATE-only (YYYY-MM-DD) string. Single
 * shared implementation — was previously duplicated across
 * driver-ride-requests.js and ride-requests.js. */
function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

/** Shared "nothing here yet" trip-list placeholder, optionally with a
 * call-to-action button. Single shared implementation — was previously
 * duplicated across driver-trips.js and rider-trips.js. */
function emptyState(text, buttonLabel, buttonHref) {
  return `
    <div class="trip-empty-state">
      <i class="bi bi-signpost-split"></i>
      <div class="trip-empty-state__text">${escapeHtml(text)}</div>
      ${buttonLabel ? `<a href="${buttonHref}" class="btn btn-primary" style="width:auto;padding-inline:24px;display:inline-flex;">${escapeHtml(buttonLabel)}</a>` : ''}
    </div>`;
}

/** Toggle a submit button into/out of a loading state without losing its label. */
function setButtonLoading(buttonEl, isLoading, loadingLabel = 'Please wait…') {
  if (!buttonEl) return;
  if (isLoading) {
    buttonEl.dataset.originalLabel = buttonEl.innerHTML;
    buttonEl.disabled = true;
    buttonEl.innerHTML = `<span class="btn-spinner" aria-hidden="true"></span><span>${escapeHtml(loadingLabel)}</span>`;
  } else {
    buttonEl.disabled = false;
    if (buttonEl.dataset.originalLabel) buttonEl.innerHTML = buttonEl.dataset.originalLabel;
  }
}

// assets/js/map.js
// Map layer shared by Home, Ride Search, Live Trip and the trip-detail
// mini-maps — now on the Google Maps JavaScript API. Every caller
// (driver.js, search.js, navigation.js, driver-trips.js, rider-trips.js)
// only ever touches mtMap / centerMapOnUser() / placeUserMarker() /
// drawRoute() / clearRoute() for the main map, or mtRenderMiniMap() /
// mtUpdateMiniMapDriver() / mtClearMiniMap() for the trip-detail sheets —
// never a provider's SDK directly — so this file is the ONLY place that
// needs to change if the map provider changes again later. The public
// contract is identical to the old Leaflet version; callers are unchanged.
//
// The Google Maps JS API is loaded on demand by the shared loader in
// helper.js (mtLoadGoogleMaps), which fetches the browser key from the
// session-guarded api/maps-key.php — no key is hardcoded here. That loader
// resolves to the google.maps namespace, which we bind to `g` below.
//
// If the key is missing or the loader fails (offline / quota / referrer),
// every entry point degrades gracefully: dashboards still mount, markers
// simply don't appear, and drawRoute() falls back to a straight line —
// exactly like the old Leaflet behavior — rather than throwing.

let mtMap = null;
let mtUserMarker = null;
let mtRouteLine = null;
let mtRouteMarkers = [];
let mtRouteInfo = null;
const MT_DEFAULT_CENTER = { lat: 6.5244, lng: 3.3792 }; // Lagos, matches the rest of the app's default
// AdvancedMarkerElement only renders on a map created with a mapId. Google's
// documented DEMO_MAP_ID needs no Cloud console setup; swap it for a real
// Cloud-configured Map ID later for custom styling. When the marker library
// is absent we fall back to classic google.maps.Marker (no mapId needed).
const MT_MAP_ID = 'DEMO_MAP_ID';

let mtMapInitStarted = false;

// ---- Trip-detail mini-map state (only one detail sheet is open at a
//      time, so a single instance/marker set is enough). ----
let mtMiniMap = null;
let mtMiniMarkers = [];
let mtMiniRoute = null;
let mtMiniDriverMarker = null;

async function mtInitMapAsync() {
  if (mtMap || mtMapInitStarted || !document.getElementById('mapCanvas')) return;
  mtMapInitStarted = true;

  let g;
  try {
    // Shared loader (helper.js) — fetch key from api/maps-key.php + inject
    // script. Resolves to the google.maps namespace.
    g = await mtLoadGoogleMaps();
  } catch (err) {
    console.warn('Google Maps unavailable, map disabled:', err && err.message ? err.message : err);
    document.dispatchEvent(new CustomEvent('mohtoh:map-ready', { detail: { map: null, error: true } }));
    return;
  }

  const canvas = document.getElementById('mapCanvas');
  mtMap = new g.Map(canvas, {
    center: MT_DEFAULT_CENTER,
    zoom: 13,
    mapId: MT_MAP_ID,
    disableDefaultUI: false,
    mapTypeControl: true,        // roadmap / satellite / terrain
    fullscreenControl: true,
    zoomControl: true,
    streetViewControl: false,
    gestureHandling: 'greedy'    // one-finger pan on mobile
  });

  document.dispatchEvent(new CustomEvent('mohtoh:map-ready', { detail: { map: mtMap } }));

  // Geolocate + drop the "you are here" marker once the map is usable.
  centerMapOnUser();
}

// Old Leaflet versions fired this on DOMContentLoaded; keep the entry point.
function initMohtohMap() {
  if (typeof mtLoadGoogleMaps !== 'function') {
    // helper.js not loaded on this page — map simply won't initialize.
    return;
  }
  mtInitMapAsync();
}

/** Pan/zoom to the browser's current geolocation and drop the "you are here" marker. */
function centerMapOnUser(onDone) {
  if (!mtMap || !window.MohToh || !window.MohToh.location) {
    if (onDone) onDone(null);
    return;
  }
  window.MohToh.location.getCurrentLocation({ enableHighAccuracy: true, timeout: 8000 }).then(
    (position) => {
      if (!mtMap) { if (onDone) onDone(null); return; }
      const latLng = { lat: position.latitude, lng: position.longitude };
      mtMap.setCenter(latLng);
      mtMap.setZoom(15);
      placeUserMarker(latLng);
      if (onDone) onDone(latLng);
    },
    () => { if (onDone) onDone(null); }
  );
}

/**
 * Create a Google marker (AdvancedMarkerElement when the marker library is
 * present, plain Marker otherwise) on `map` with a click-to-open InfoWindow.
 * Returns the marker. Used by every marker in this file so the provider
 * detail lives in one place.
 */
function _mtMakeMarker(map, position, label) {
  const g = window.google && window.google.maps;
  if (!g || !map) return null;
  let marker;
  if (g.marker && typeof g.marker.AdvancedMarkerElement === 'function') {
    marker = new g.marker.AdvancedMarkerElement({ map, position, title: label });
  } else {
    marker = new g.Marker({ map, position, title: label });
  }
  if (label && g.InfoWindow && g.event) {
    const info = new g.InfoWindow({ content: label });
    g.event.addListener(marker, 'click', () => info.open(map, marker));
  }
  return marker;
}

/** Move a marker to a new {lat,lng} regardless of marker flavor. */
function _mtMoveMarker(marker, position) {
  if (!marker) return;
  if ('position' in marker) marker.position = position;        // AdvancedMarkerElement
  else if (typeof marker.setPosition === 'function') marker.setPosition(position); // classic Marker
}

/** Remove a marker from its map regardless of marker flavor. */
function _mtRemoveMarker(marker) {
  if (!marker) return;
  if ('map' in marker) marker.map = null;                      // AdvancedMarkerElement
  else if (typeof marker.setMap === 'function') marker.setMap(null); // classic Marker
}

/** Accepts {lat, lng} — same contract regardless of which provider is underneath. */
function placeUserMarker(latLng) {
  if (!mtMap || !latLng) return;
  const point = { lat: Number(latLng.lat), lng: Number(latLng.lng) };
  if (mtUserMarker) { _mtMoveMarker(mtUserMarker, point); return; }
  mtUserMarker = _mtMakeMarker(mtMap, point, 'Your location');
}

// ---- Shared route/marker helpers (used by Driver Home today; Ride Details
// and Live Trip reuse the same functions rather than re-implementing them). ----

/**
 * Draw a real road route (Google Directions) between two {lat,lng} points on
 * `map`, fit it into view, drop pickup/destination markers, and surface the
 * distance + ETA. Falls back to a straight polyline + haversine distance if
 * Directions is unavailable. Internal — drawRoute() and mtRenderMiniMap()
 * both delegate here so there is a single route-drawing implementation.
 * Returns { route, markers } so the caller can clean them up.
 */
function _mtDrawRouteOn(map, origin, destination, options = {}) {
  const g = window.google && window.google.maps;
  const out = { route: null, markers: [] };
  if (!g || !map || !origin || !destination) return out;

  const originPoint = { lat: Number(origin.lat), lng: Number(origin.lng) };
  const destPoint = { lat: Number(destination.lat), lng: Number(destination.lng) };
  const color = options.color || '#0F8B8D';

  // Markers first so they exist even if Directions is slow/failing.
  out.markers.push(_mtMakeMarker(map, originPoint, options.originLabel || 'Pickup'));
  out.markers.push(_mtMakeMarker(map, destPoint, options.destinationLabel || 'Destination'));

  const fitStraight = () => {
    const bounds = new g.LatLngBounds();
    bounds.extend(originPoint);
    bounds.extend(destPoint);
    map.fitBounds(bounds, 48);
  };

  const drawStraight = () => {
    out.route = new g.Polyline({
      map,
      path: [originPoint, destPoint],
      strokeColor: color,
      strokeWeight: 4,
      strokeOpacity: 0.85
    });
    fitStraight();
    const km = haversineKm(originPoint.lat, originPoint.lng, destPoint.lat, destPoint.lng);
    if (km != null && typeof options.onRouteResult === 'function') {
      options.onRouteResult({ distance_km: km, duration_minutes: km > 0 ? Math.round((km / 40) * 60) : null });
    }
  };

  if (!g.DirectionsService || !g.DirectionsRenderer) { drawStraight(); return out; }

  const service = new g.DirectionsService();
  const renderer = new g.DirectionsRenderer({
    map,
    suppressMarkers: true,     // we draw our own pickup/destination markers
    preserveViewport: false,
    polylineOptions: { strokeColor: color, strokeWeight: 5, strokeOpacity: 0.85 }
  });
  out.route = renderer;

  try {
    service.route(
      { origin: originPoint, destination: destPoint, travelMode: g.TravelMode.DRIVING },
      (result, status) => {
        if (status === g.DirectionsStatus.OK && result) {
          renderer.setDirections(result);
          const leg = result.routes[0] && result.routes[0].legs && result.routes[0].legs[0];
          if (leg && typeof options.onRouteResult === 'function') {
            options.onRouteResult({
              distance_km: leg.distance && leg.distance.value ? leg.distance.value / 1000 : null,
              duration_minutes: leg.duration && leg.duration.value ? Math.round(leg.duration.value / 60) : null
            });
          }
        } else {
          // Directions failed (quota/key/referrer) — degrade to a straight line.
          if (renderer.setMap) renderer.setMap(null);
          drawStraight();
        }
      }
    );
  } catch (err) {
    if (renderer.setMap) renderer.setMap(null);
    drawStraight();
  }

  return out;
}

/** Public route drawer for the MAIN map (mtMap). Signature unchanged. */
function drawRoute(origin, destination, options = {}) {
  if (!mtMap || !origin || !destination) return;
  clearRoute();
  const result = _mtDrawRouteOn(mtMap, origin, destination, options);
  mtRouteLine = result.route;
  mtRouteMarkers = result.markers.filter(Boolean);
  mtRouteInfo = null; // populated asynchronously via options.onRouteResult
}

function clearRoute() {
  if (mtRouteLine) {
    if (typeof mtRouteLine.setMap === 'function') mtRouteLine.setMap(null);
    mtRouteLine = null;
  }
  mtRouteMarkers.forEach(_mtRemoveMarker);
  mtRouteMarkers = [];
  mtRouteInfo = null;
}

// ---- Trip-detail mini-maps (driver-trips.js / rider-trips.js) ----

/**
 * Render a self-contained Google map inside `mapEl` for a trip-detail sheet.
 * Loads the API if needed, drops pickup / destination / driver markers, and
 * draws the real road route between pickup and destination. opts:
 *   { pickup:{lat,lng}, destination:{lat,lng}, driver:{lat,lng},
 *     drawRoute:bool }
 * Resolves to the map instance, or null if Google is unavailable (the sheet
 * still shows the timeline/financials — only the map is absent).
 */
async function mtRenderMiniMap(mapEl, opts = {}) {
  if (!mapEl) return null;
  mtClearMiniMap();

  let g;
  try { g = await mtLoadGoogleMaps(); } catch (err) { return null; }
  if (!mapEl.isConnected) return null; // sheet closed while the API loaded

  const map = new g.Map(mapEl, {
    center: MT_DEFAULT_CENTER,
    zoom: 12,
    mapId: MT_MAP_ID,
    disableDefaultUI: true,      // matches the old Leaflet {zoomControl:false}
    gestureHandling: 'greedy',
    clickableIcons: false
  });
  mtMiniMap = map;

  const pickup = opts.pickup && opts.pickup.lat != null
    ? { lat: Number(opts.pickup.lat), lng: Number(opts.pickup.lng) } : null;
  const destination = opts.destination && opts.destination.lat != null
    ? { lat: Number(opts.destination.lat), lng: Number(opts.destination.lng) } : null;

  const bounds = new g.LatLngBounds();
  let extended = 0;

  if (pickup && destination && opts.drawRoute !== false) {
    // Real road route + its own pickup/destination markers.
    const res = _mtDrawRouteOn(map, pickup, destination, { originLabel: 'Pickup', destinationLabel: 'Destination' });
    mtMiniRoute = res.route;
    mtMiniMarkers.push(...res.markers.filter(Boolean));
    bounds.extend(pickup); bounds.extend(destination); extended += 2;
  } else {
    if (pickup) { mtMiniMarkers.push(_mtMakeMarker(map, pickup, 'Pickup')); bounds.extend(pickup); extended++; }
    if (destination) { mtMiniMarkers.push(_mtMakeMarker(map, destination, 'Destination')); bounds.extend(destination); extended++; }
  }

  if (opts.driver && opts.driver.lat != null) {
    const driverPoint = { lat: Number(opts.driver.lat), lng: Number(opts.driver.lng) };
    mtMiniDriverMarker = _mtMakeMarker(map, driverPoint, opts.driverLabel || 'Driver');
    bounds.extend(driverPoint); extended++;
  }

  if (extended > 1) map.fitBounds(bounds, 32);
  else if (extended === 1) { map.setCenter(bounds.getCenter()); map.setZoom(14); }

  return map;
}

/**
 * Live-driver update for the currently rendered mini-map — moves (or creates)
 * the driver marker. Called by rider-trips.js's get_driver_location poll.
 */
function mtUpdateMiniMapDriver(lat, lng, label) {
  if (!mtMiniMap || lat == null || lng == null) return;
  const point = { lat: Number(lat), lng: Number(lng) };
  if (mtMiniDriverMarker) { _mtMoveMarker(mtMiniDriverMarker, point); return; }
  mtMiniDriverMarker = _mtMakeMarker(mtMiniMap, point, label || 'Driver');
}

/** Tear down the current mini-map's markers/route before re-rendering. */
function mtClearMiniMap() {
  if (mtMiniRoute && typeof mtMiniRoute.setMap === 'function') mtMiniRoute.setMap(null);
  mtMiniRoute = null;
  mtMiniMarkers.forEach(_mtRemoveMarker);
  mtMiniMarkers = [];
  _mtRemoveMarker(mtMiniDriverMarker);
  mtMiniDriverMarker = null;
  mtMiniMap = null; // the Map's DOM element is discarded by the caller
}

/** Small haversine helper used only for the straight-line fallback. */
function haversineKm(lat1, lng1, lat2, lng2) {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLng = ((lng2 - lng1) * Math.PI) / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
    + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180)
    * Math.sin(dLng / 2) * Math.sin(dLng / 2);
  return 2 * R * Math.asin(Math.sqrt(a));
}

// helper.js (which defines the shared mtLoadGoogleMaps loader) is loaded
// before map.js on every page that mounts a map, so by DOMContentLoaded the
// loader is available. initMohtohMap() awaits it internally; pages without a
// #mapCanvas (e.g. the trip lists) simply skip main-map init and use the
// mtRenderMiniMap() entry point instead.
document.addEventListener('DOMContentLoaded', initMohtohMap);

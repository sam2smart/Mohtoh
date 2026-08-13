// assets/js/state.js
// Simple Application State (Rule 8). Vanilla JS, no framework: a plain
// object plus a subscribe list per key. Holds the handful of values more
// than one component on a page needs to read/react to — current user,
// wallet balance, unread notification count, waitlist status for the
// ride currently open, etc. — so they're fetched once and pushed to
// every interested component, instead of each component independently
// re-fetching the same thing.
//
// Deliberately NOT a store for page-local UI state (form values, which
// tab is active, sheet open/closed) — that stays exactly where it
// already lives, as local variables inside each page's own JS file.

const mtState = (() => {
  const values = {};
  const listeners = {}; // key -> Set<fn>

  function get(key) {
    return values[key];
  }

  function set(key, value) {
    values[key] = value;
    (listeners[key] || new Set()).forEach((fn) => {
      try { fn(value); } catch (error) { console.error(`[state] listener for "${key}" threw:`, error); }
    });
  }

  /** Call fn(value) now (if the key already has a value) and again every time it changes. Returns an unsubscribe function. */
  function subscribe(key, fn) {
    if (!listeners[key]) listeners[key] = new Set();
    listeners[key].add(fn);
    if (key in values) fn(values[key]);
    return () => listeners[key]?.delete(fn);
  }

  return { get, set, subscribe };
})();

// ---------------------------------------------------------------
// In-flight request de-duplication (Rule 8 "avoid duplicate API calls" /
// Rule 10 "prevent duplicate requests"). If the same key is already
// loading, every caller gets the SAME in-flight promise instead of firing
// a second identical request — e.g. two components polling unread-count
// on the same page tick don't both hit the network.
// ---------------------------------------------------------------
const _mtInFlight = {};

/** Runs requestFn() for `key`, sharing one in-flight promise across concurrent callers. requestFn is only invoked if `key` isn't already loading. */
function mtDedupeRequest(key, requestFn) {
  if (_mtInFlight[key]) return _mtInFlight[key];
  const promise = Promise.resolve(requestFn()).finally(() => {
    delete _mtInFlight[key];
  });
  _mtInFlight[key] = promise;
  return promise;
}

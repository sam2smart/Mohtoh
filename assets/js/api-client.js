// assets/js/api-client.js
// Frontend Service Layer (Rule 3). Centralizes every call to api/*.php
// behind three functions — apiGet/apiPost/apiUpload — so JS modules stop
// hand-rolling fetch() with their own ad hoc error handling. Every
// api/*.php endpoint already returns the same shape via json_response()
// in includes/functions.php: { status: 'success'|'error', message, data }.
// This file's job is to guarantee that SAME shape comes back even when
// the network/timeout/parsing fails before a real response ever arrives,
// so every caller can handle success/error identically no matter what
// actually went wrong (Rule 9).
//
// helper.js's existing postJson() is left in place and unchanged — many
// modules already call it — but do not add new callers of raw fetch()
// or postJson(); use apiGet/apiPost/apiUpload for anything new, and
// migrate a module here at a time. See assets/js/navigation.js and
// assets/js/search.js for worked migrations.

const API_DEFAULT_TIMEOUT_MS = 15000;

// This app's api/*.php endpoints never send a real HTTP 401 — every
// is_logged_in() check responds 200 OK with {status:'error',
// message:'Unauthorized'} (see api/notifications.php, api/profile.php,
// api/admin-api.php, etc.), so an expired/missing session is detected by
// message content, not status code.
function _mtIsAuthFailure(message) {
  return message === 'Unauthorized';
}

async function _mtApiRequest(url, options = {}) {
  const { method = 'GET', body = null, isFormData = false, timeout = API_DEFAULT_TIMEOUT_MS, onAuthFailure } = options;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);

  let response;
  try {
    const fetchOptions = { method, signal: controller.signal, credentials: 'same-origin' };
    if (body !== null) {
      if (isFormData) {
        fetchOptions.body = body; // FormData sets its own multipart Content-Type/boundary
      } else {
        fetchOptions.headers = { 'Content-Type': 'application/json' };
        fetchOptions.body = JSON.stringify(body);
      }
    }
    response = await fetch(url, fetchOptions);
  } catch (error) {
    clearTimeout(timer);
    if (error.name === 'AbortError') {
      console.error(`[api-client] Timed out after ${timeout}ms: ${method} ${url}`);
      return { status: 'error', message: 'The request took too long. Check your connection and try again.', data: [], _kind: 'timeout' };
    }
    console.error(`[api-client] Network error calling ${method} ${url}:`, error);
    return { status: 'error', message: 'Network error — check your connection and try again.', data: [], _kind: 'network' };
  }
  clearTimeout(timer);

  let json;
  try {
    json = await response.json();
  } catch (error) {
    console.error(`[api-client] Non-JSON response from ${method} ${url} (HTTP ${response.status}):`, error);
    return {
      status: 'error',
      message: response.status >= 500 ? 'Server error — please try again shortly.' : 'Something went wrong. Please try again.',
      data: [], _kind: 'parse', _httpStatus: response.status,
    };
  }

  if (json.status === 'error' && _mtIsAuthFailure(json.message)) {
    console.warn(`[api-client] Auth failure calling ${method} ${url}`);
    if (typeof onAuthFailure === 'function') onAuthFailure();
    else if (typeof window.mtOnAuthFailure === 'function') window.mtOnAuthFailure();
  }

  return json;
}

/** GET an api/*.php endpoint. `params` (optional, plain object) becomes the query string — empty/null/undefined values are skipped. */
function apiGet(url, params = null, options = {}) {
  let fullUrl = url;
  if (params) {
    const qsParams = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') qsParams.set(key, value);
    });
    const qsString = qsParams.toString();
    if (qsString) fullUrl += (url.includes('?') ? '&' : '?') + qsString;
  }
  return _mtApiRequest(fullUrl, { ...options, method: 'GET' });
}

/** POST a JSON body to an api/*.php endpoint. */
function apiPost(url, payload = {}, options = {}) {
  return _mtApiRequest(url, { ...options, method: 'POST', body: payload });
}

/** POST a FormData body (file/photo upload) to an api/*.php endpoint. Longer default timeout since uploads take longer than a plain JSON call. */
function apiUpload(url, formData, options = {}) {
  return _mtApiRequest(url, { ...options, method: 'POST', body: formData, isFormData: true, timeout: options.timeout || 45000 });
}

// Default auth-failure handler: bounce back to the login screen. A page
// that's fine being viewed logged-out (e.g. index.php itself) can
// override window.mtOnAuthFailure before this file's first API call, or
// pass an `onAuthFailure` option to an individual apiGet/apiPost/apiUpload
// call to opt out just for that one request.
if (typeof window !== 'undefined' && typeof window.mtOnAuthFailure !== 'function') {
  window.mtOnAuthFailure = () => {
    const path = window.location.pathname;
    const isNested = path.includes('/driver/') || path.includes('/rider/') || path.includes('/admin/');
    window.location.href = isNested ? '../index.php' : 'index.php';
  };
}

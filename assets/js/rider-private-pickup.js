// assets/js/rider-private-pickup.js
// Private Solo Pickup (SYSTEM 2) — the RIDER side.
//
// A thin client over api/private-pickup.php. It owns no business logic:
// eligibility, the 20 KM match, fare, TTL and driver selection are all
// decided server-side. This module only moves the rider between four
// states and renders what the server returns.
//
//   FORM      -> rider enters pickup + destination
//   SEARCHING -> request created, polling status
//   FOUND     -> a driver accepted (status accepted/in_progress)
//   NO_DRIVER -> nobody was within range, or the TTL expired unclaimed
//
// The active request's public id is persisted to sessionStorage so a
// reload mid-search resumes the SEARCHING state instead of stranding a
// live request the rider can no longer see or cancel.

document.addEventListener('DOMContentLoaded', () => {
  const formSection      = qs('#ppFormSection');
  if (!formSection) return;

  const form             = qs('#ppForm');
  const formAlert        = qs('#ppFormAlert');
  const submitBtn        = qs('#ppSubmitBtn');
  const searchingSection = qs('#ppSearchingSection');
  const foundSection     = qs('#ppFoundSection');
  const noDriverSection  = qs('#ppNoDriverSection');

  const pickupEl   = qs('#ppPickup');
  const destEl     = qs('#ppDestination');
  const pickupLat  = qs('#ppPickupLat');
  const pickupLng  = qs('#ppPickupLng');
  const destLat    = qs('#ppDestinationLat');
  const destLng    = qs('#ppDestinationLng');

  const estimateBox  = qs('#ppEstimate');
  const estimateFare = qs('#ppEstimateFare');
  const estimateMeta = qs('#ppEstimateMeta');

  const STORAGE_KEY = 'mohtoh.pp.activeRequest';
  const POLL_MS = 3000;

  let pollTimer = null;
  let countdownTimer = null;
  let activeRequestId = null;
  let expiresAtMs = null;

  const naira = (n) => '₦' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

  // ---- state switching ----------------------------------------------
  function show(section) {
    [formSection, searchingSection, foundSection, noDriverSection].forEach((s) => {
      if (s) s.classList.toggle('u-hidden', s !== section);
    });
  }

  function stopTimers() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  function clearActive() {
    stopTimers();
    activeRequestId = null;
    expiresAtMs = null;
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* private mode */ }
  }

  // ---- address inputs -------------------------------------------------
  initAddressAutocomplete(pickupEl, pickupLat, pickupLng, refreshEstimate);
  initAddressAutocomplete(destEl, destLat, destLng, refreshEstimate);

  qs('#ppUseLocationBtn')?.addEventListener('click', () => {
    if (!window.MohToh || !window.MohToh.location) {
      showToast('Location is not available on this device.');
      return;
    }
    const btn = qs('#ppUseLocationBtn');
    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
    window.MohToh.location.getCurrentLocation().then(async (position) => {
      const { latitude, longitude } = position;
      pickupLat.value = latitude;
      pickupLng.value = longitude;
      const geo = await mtReverseGeocode(latitude, longitude);
      pickupEl.value = (geo && geo.formatted_address) || `Lat: ${latitude.toFixed(4)}, Lng: ${longitude.toFixed(4)}`;
      btn.innerHTML = '<i class="bi bi-crosshair"></i>';
      refreshEstimate();
    }, (error) => {
      showToast(`Could not get your location: ${error.message}`);
      btn.innerHTML = '<i class="bi bi-crosshair"></i>';
    });
  });

  // ---- server-side fare estimate -------------------------------------
  async function refreshEstimate() {
    if (!pickupLat.value || !pickupLng.value || !destLat.value || !destLng.value) {
      estimateBox.classList.add('u-hidden');
      return;
    }
    const res = await apiGet('../api/private-pickup.php', {
      action: 'estimate',
      pickup_lat: pickupLat.value,
      pickup_lng: pickupLng.value,
      destination_lat: destLat.value,
      destination_lng: destLng.value,
    });
    if (res.status !== 'success') { estimateBox.classList.add('u-hidden'); return; }
    const d = res.data || {};

    // Keke and car are priced separately by the server, and no driver is
    // matched yet, so quote the range the server returned rather than
    // pretending one number covers both. Every figure here comes from the
    // backend fare engine — this file never does fare math.
    const options = Array.isArray(d.fare_options) ? d.fare_options : [];
    const totals = options.map((o) => Number(o.total));
    const low = totals.length ? Math.min(...totals) : Number(d.estimated_fare || 0);
    const high = totals.length ? Math.max(...totals) : low;

    estimateFare.textContent = high > low ? `${naira(low)} – ${naira(high)}` : naira(low);
    const km = `About ${Number(d.distance_km || 0).toFixed(1)} km`;
    estimateMeta.textContent = high > low
      ? `${km} · ${options.map((o) => `${o.label} ${naira(o.total)}`).join(' · ')} — you pay the rate of whichever accepts`
      : `${km} · the whole vehicle to yourself`;
    estimateBox.classList.remove('u-hidden');
  }

  // ---- create ---------------------------------------------------------
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideAlert(formAlert);

    if (!pickupLat.value || !pickupLng.value || !destLat.value || !destLng.value) {
      showAlert(formAlert, 'error', 'Please choose both pickup and destination from the suggestions so we can find drivers near you.');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="btn-spinner"></span>';

    const res = await apiPost('../api/private-pickup.php', {
      action: 'create',
      pickup_location: pickupEl.value,
      pickup_lat: pickupLat.value,
      pickup_lng: pickupLng.value,
      destination: destEl.value,
      destination_lat: destLat.value,
      destination_lng: destLng.value,
      notes: qs('#ppNotes').value,
    });

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-scooter"></i> Request Private Pickup';

    if (res.status !== 'success') {
      showAlert(formAlert, 'error', res.message || 'Could not create your request.');
      return;
    }

    const d = res.data || {};

    // Zero drivers notified is a real, explicit outcome — not a silent
    // wait that can only end in a timeout. Tell the rider immediately.
    if (Number(d.drivers_notified) === 0) {
      enterNoDriver(d.request_public_id, 'We couldn’t find a verified Keke or private car driver online within range of your pickup point right now.');
      return;
    }

    enterSearching(d.request_public_id, {
      fare: d.estimated_fare,
      ttl: d.expires_in_seconds,
      drivers: d.drivers_notified,
      pickup: pickupEl.value,
      destination: destEl.value,
    });
  });

  // ---- searching ------------------------------------------------------
  function enterSearching(requestId, info) {
    activeRequestId = requestId;
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        id: requestId, pickup: info.pickup, destination: info.destination,
      }));
    } catch (e) { /* private mode — polling still works this session */ }

    qs('#ppSearchingPickup').textContent = info.pickup || '';
    qs('#ppSearchingDest').textContent = info.destination || '';
    qs('#ppSearchingFare').textContent = info.fare != null ? naira(info.fare) : '—';
    qs('#ppSearchingSubtext').textContent = info.drivers != null
      ? `${info.drivers} nearby driver${Number(info.drivers) === 1 ? '' : 's'} alerted. Waiting for one to accept…`
      : 'Waiting for a driver to accept…';

    if (info.ttl) {
      expiresAtMs = Date.now() + Number(info.ttl) * 1000;
      startCountdown();
    } else {
      qs('#ppSearchingCountdown').textContent = '—';
    }

    show(searchingSection);
    stopTimers();
    if (expiresAtMs) startCountdown();
    pollStatus();
    pollTimer = setInterval(pollStatus, POLL_MS);
  }

  function startCountdown() {
    if (countdownTimer) clearInterval(countdownTimer);
    const tick = () => {
      const left = Math.max(0, Math.ceil((expiresAtMs - Date.now()) / 1000));
      qs('#ppSearchingCountdown').textContent = left > 0 ? `${left}s left` : 'Timing out…';
    };
    tick();
    countdownTimer = setInterval(tick, 1000);
  }

  async function pollStatus() {
    if (!activeRequestId) return;
    const res = await apiGet('../api/private-pickup.php', {
      action: 'status',
      request_public_id: activeRequestId,
    });
    if (res.status !== 'success') return;   // transient — keep polling
    const d = res.data || {};

    if (d.status === 'accepted' || d.status === 'in_progress') {
      enterFound(d);
      return;
    }
    if (d.status === 'expired') {
      enterNoDriver(activeRequestId, 'No Keke driver accepted in time. They may all be on a trip right now.');
      return;
    }
    if (d.status === 'cancelled') {
      clearActive();
      show(formSection);
      return;
    }
    // still pending — keep waiting
  }

  // ---- driver found ---------------------------------------------------
  function enterFound(d) {
    stopTimers();
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* noop */ }

    if (window.MohToh && window.MohToh.sound) window.MohToh.sound.play('success');

    qs('#ppDriverName').textContent = d.driver_name || 'Your driver';
    const bits = [];
    if (d.driver_rating) bits.push(`★ ${Number(d.driver_rating).toFixed(1)}`);
    // d.price is the server's final fare for THIS driver's vehicle class,
    // re-priced on accept — so the label must name that class, not assume keke.
    if (d.price) bits.push(naira(d.price));
    bits.push(d.vehicle_type_label ? `Private ${d.vehicle_type_label} pickup` : 'Private pickup');
    qs('#ppDriverMeta').textContent = bits.join(' · ');

    // Deep-link the tracking button straight to THIS trip's live detail
    // sheet on the existing Trips page. get_trip_detail re-checks ownership
    // (rb.rider_id = session), so passing the booking id here is safe. Fall
    // back to the plain Trips page if the booking id isn't available yet.
    const openBtn = qs('#ppOpenTripBtn');
    if (openBtn) {
      openBtn.href = d.booking_id
        ? `trips.php?tab=upcoming&open=${encodeURIComponent(d.booking_id)}`
        : 'trips.php';
    }

    show(foundSection);
  }

  // ---- no driver ------------------------------------------------------
  function enterNoDriver(requestId, message) {
    stopTimers();
    // The request may still be sitting pending server-side (nobody was in
    // range). Cancel it so it can't be claimed after the rider has moved
    // on, and so their "one active request" slot is freed.
    if (requestId) {
      apiPost('../api/private-pickup.php', { action: 'cancel', request_public_id: requestId });
    }
    clearActive();
    qs('#ppNoDriverSubtext').textContent = message;
    show(noDriverSection);
  }

  qs('#ppRetryBtn')?.addEventListener('click', () => {
    hideAlert(formAlert);
    show(formSection);
  });

  // ---- cancel ---------------------------------------------------------
  qs('#ppCancelBtn')?.addEventListener('click', async () => {
    if (!activeRequestId) { show(formSection); return; }
    const btn = qs('#ppCancelBtn');
    btn.disabled = true;
    const res = await apiPost('../api/private-pickup.php', {
      action: 'cancel', request_public_id: activeRequestId,
    });
    btn.disabled = false;
    showToast(res.message || 'Request cancelled.');
    clearActive();
    show(formSection);
  });

  // ---- resume an in-flight request across a reload --------------------
  (function resume() {
    let saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { saved = null; }
    if (!saved || !saved.id) { show(formSection); return; }

    activeRequestId = saved.id;
    qs('#ppSearchingPickup').textContent = saved.pickup || '';
    qs('#ppSearchingDest').textContent = saved.destination || '';
    qs('#ppSearchingCountdown').textContent = '—';
    qs('#ppSearchingSubtext').textContent = 'Waiting for a driver to accept…';
    show(searchingSection);
    pollStatus();
    pollTimer = setInterval(pollStatus, POLL_MS);
  })();

  // Don't keep polling a backgrounded tab; re-check on return.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    } else if (activeRequestId && !pollTimer) {
      pollStatus();
      pollTimer = setInterval(pollStatus, POLL_MS);
    }
  });
});

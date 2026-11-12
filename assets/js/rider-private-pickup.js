// assets/js/rider-private-pickup.js
// Private Solo Pickup (SYSTEM 2) — the RIDER side.
//
// A thin client over api/private-pickup.php. It owns no business logic:
// eligibility, the class-restricted match, fare, negotiation, TTL, arrival
// and driver selection are all decided server-side. This module only moves
// the rider between states and renders what the server returns. It never
// does fare math and never decides when a trip may start.
//
//   FORM      -> rider enters pickup + destination + vehicle class
//   SEARCHING -> request created, polling status
//   PROPOSAL  -> a driver proposed a fare that differs from the offer;
//                the rider must consent before it becomes final (#3)
//   FOUND     -> a driver is assigned / trip spawned; en route, then the
//                rider confirms arrival (#11) which unlocks trip start (#12)
//   NO_DRIVER -> nobody was within range, or the TTL expired unclaimed
//
// The active request's public id is persisted to sessionStorage so a reload
// mid-flow resumes by re-polling rather than stranding a live request.

document.addEventListener('DOMContentLoaded', () => {
  const formSection      = qs('#ppFormSection');
  if (!formSection) return;

  const form             = qs('#ppForm');
  const formAlert        = qs('#ppFormAlert');
  const submitBtn        = qs('#ppSubmitBtn');
  const searchingSection = qs('#ppSearchingSection');
  const proposalSection  = qs('#ppProposalSection');
  const foundSection     = qs('#ppFoundSection');
  const noDriverSection  = qs('#ppNoDriverSection');

  const pickupEl   = qs('#ppPickup');
  const destEl     = qs('#ppDestination');
  const pickupLat  = qs('#ppPickupLat');
  const pickupLng  = qs('#ppPickupLng');
  const destLat    = qs('#ppDestinationLat');
  const destLng    = qs('#ppDestinationLng');
  const vehicleType = qs('#ppVehicleType');

  const estimateBox  = qs('#ppEstimate');
  const estimateFare = qs('#ppEstimateFare');
  const estimateMeta = qs('#ppEstimateMeta');

  const STORAGE_KEY = 'mohtoh.pp.activeRequest';
  const POLL_MS = 3000;

  let pollTimer = null;
  let countdownTimer = null;
  let activeRequestId = null;
  let expiresAtMs = null;
  let lastEstimate = null;   // cached server estimate so class switches don't refetch
  let view = 'form';         // current visible state — entry side-effects fire only on change

  const naira = (n) => '₦' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

  // ---- state switching ----------------------------------------------
  function show(section) {
    [formSection, searchingSection, proposalSection, foundSection, noDriverSection].forEach((s) => {
      if (s) s.classList.toggle('u-hidden', s !== section);
    });
  }

  function stopTimers() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  function ensurePolling() {
    if (!pollTimer && activeRequestId) pollTimer = setInterval(pollStatus, POLL_MS);
  }

  function clearActive() {
    stopTimers();
    activeRequestId = null;
    expiresAtMs = null;
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* private mode */ }
  }

  // ---- vehicle class selection (criterion #5) ------------------------
  // Required before a request can be sent; the server re-validates it and
  // matches only drivers of the chosen class, so this is a UX gate, not the
  // enforcement point.
  function selectVehicle(type) {
    vehicleType.value = type;
    document.querySelectorAll('.pp-vehicle-choice').forEach((btn) => {
      const on = btn.getAttribute('data-vehicle-type') === type;
      btn.setAttribute('aria-checked', on ? 'true' : 'false');
      btn.style.borderColor = on ? 'var(--color-primary)' : 'transparent';
      btn.style.background = on ? 'var(--color-surface-alt, rgba(15,139,141,.06))' : '';
    });
    applyEstimateDisplay();
  }

  document.querySelectorAll('.pp-vehicle-choice').forEach((btn) => {
    btn.addEventListener('click', () => selectVehicle(btn.getAttribute('data-vehicle-type')));
  });

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
      lastEstimate = null;
      applyEstimateDisplay();
      return;
    }
    const res = await apiGet('../api/private-pickup.php', {
      action: 'estimate',
      pickup_lat: pickupLat.value,
      pickup_lng: pickupLng.value,
      destination_lat: destLat.value,
      destination_lng: destLng.value,
    });
    lastEstimate = res.status === 'success' ? (res.data || null) : null;
    applyEstimateDisplay();
  }

  // Render the cached estimate. Keke and car are priced separately by the
  // server; every figure here comes from the backend fare engine. Once the
  // rider has picked a class we lock the box to that class's price (the
  // authoritative opening fare); before then we show the honest range.
  function applyEstimateDisplay() {
    const d = lastEstimate;
    const options = d && Array.isArray(d.fare_options) ? d.fare_options : [];
    const byType = {};
    options.forEach((o) => { byType[o.vehicle_type] = o; });

    // Per-class price chips inside the vehicle buttons.
    document.querySelectorAll('[data-vehicle-fare]').forEach((el) => {
      const t = el.getAttribute('data-vehicle-fare');
      el.textContent = (d && byType[t]) ? naira(byType[t].total) : '—';
    });

    if (!d) { estimateBox.classList.add('u-hidden'); return; }

    const km = `About ${Number(d.distance_km || 0).toFixed(1)} km`;
    const sel = vehicleType.value;
    if (sel && byType[sel]) {
      estimateFare.textContent = naira(byType[sel].total);
      estimateMeta.textContent = `${km} · Private ${byType[sel].label} — the whole vehicle to yourself`;
    } else {
      const totals = options.map((o) => Number(o.total));
      const low = totals.length ? Math.min(...totals) : Number(d.estimated_fare || 0);
      const high = totals.length ? Math.max(...totals) : low;
      estimateFare.textContent = high > low ? `${naira(low)} – ${naira(high)}` : naira(low);
      estimateMeta.textContent = `${km} · Choose a vehicle above to set your fare`;
    }
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
    if (!vehicleType.value) {
      showAlert(formAlert, 'error', 'Please choose Private Car or Private Keke for your pickup.');
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
      vehicle_type: vehicleType.value,
      notes: qs('#ppNotes').value,
    });

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-scooter"></i> Request Private Pickup';

    if (res.status !== 'success') {
      showAlert(formAlert, 'error', res.message || 'Could not create your request.');
      return;
    }

    const d = res.data || {};

    // Zero drivers notified is a real, explicit outcome — not a silent wait
    // that can only end in a timeout. Tell the rider immediately.
    if (Number(d.drivers_notified) === 0) {
      const label = d.vehicle_type_label ? d.vehicle_type_label : 'Keke or private car';
      enterNoDriver(d.request_public_id, `We couldn’t find a verified ${label} driver online within range of your pickup point right now.`);
      return;
    }

    enterSearching(d.request_public_id, {
      fare: d.estimated_fare,
      ttl: d.expires_in_seconds,
      drivers: d.drivers_notified,
      pickup: pickupEl.value,
      destination: destEl.value,
      vehicle_type_label: d.vehicle_type_label,
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
    const drvLabel = info.vehicle_type_label ? ` Private ${info.vehicle_type_label}` : '';
    qs('#ppSearchingSubtext').textContent = info.drivers != null
      ? `${info.drivers} nearby${drvLabel} driver${Number(info.drivers) === 1 ? '' : 's'} alerted. Waiting for one to accept…`
      : 'Waiting for a driver to accept…';

    stopTimers();
    if (info.ttl) {
      expiresAtMs = Date.now() + Number(info.ttl) * 1000;
      startCountdown();
    } else {
      qs('#ppSearchingCountdown').textContent = '—';
    }

    view = 'searching';
    show(searchingSection);
    pollStatus();
    pollTimer = setInterval(pollStatus, POLL_MS);
  }

  // Re-open the searching view after a rejected proposal (the request is
  // pending again server-side, with a fresh TTL).
  function resumeSearching(ttlSeconds) {
    if (ttlSeconds) {
      expiresAtMs = Date.now() + Number(ttlSeconds) * 1000;
      startCountdown();
    } else {
      expiresAtMs = null;
      if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
      qs('#ppSearchingCountdown').textContent = '—';
    }
    qs('#ppSearchingSubtext').textContent = 'Waiting for a driver to accept…';
    view = 'searching';
    show(searchingSection);
    ensurePolling();
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

    // A driver counter-offer waiting on the passenger's consent takes
    // priority over every other view (criterion #3).
    if (d.awaiting_consent) { enterProposal(d); return; }

    // Trip spawned (a direct agreement, or a proposal the rider accepted).
    if (d.status === 'in_progress' || d.matched_ride_share_id) { enterFound(d); return; }

    if (d.status === 'expired') {
      enterNoDriver(activeRequestId, 'No driver accepted in time. They may all be on a trip right now.');
      return;
    }
    if (d.status === 'cancelled') {
      clearActive();
      view = 'form';
      show(formSection);
      return;
    }
    // Still pending. If we drifted here from the proposal view (e.g. the
    // rider rejected in another tab), fall back to searching.
    if (view !== 'searching') resumeSearching(null);
  }

  // ---- driver proposed a fare (consent required, criterion #3) --------
  function enterProposal(d) {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    qs('#ppSearchingCountdown') && (qs('#ppSearchingCountdown').textContent = '—');

    qs('#ppProposalOffer').textContent = d.current_fare != null ? naira(d.current_fare) : '—';
    qs('#ppProposalFare').textContent = d.proposed_fare != null ? naira(d.proposed_fare) : '—';

    const drv = d.driver_name || 'A nearby driver';
    const lbl = d.vehicle_type_label ? ` (Private ${d.vehicle_type_label})` : '';
    qs('#ppProposalDriver').textContent = `${drv}${lbl} offered to take your pickup.`;
    // Explain the difference honestly: a keke's fare includes coming to you.
    qs('#ppProposalNote').textContent = d.vehicle_type === 'keke'
      ? 'This fare includes the driver’s trip to your pickup point.'
      : 'Review the driver’s fare before it becomes final.';

    if (view !== 'proposal' && window.MohToh && window.MohToh.sound) window.MohToh.sound.play('info');
    view = 'proposal';
    show(proposalSection);
    ensurePolling();
  }

  qs('#ppAcceptFareBtn')?.addEventListener('click', async () => {
    if (!activeRequestId) return;
    const btn = qs('#ppAcceptFareBtn');
    btn.disabled = true; btn.innerHTML = '<span class="btn-spinner"></span>';
    const res = await apiPost('../api/private-pickup.php', { action: 'accept_fare', request_public_id: activeRequestId });
    btn.disabled = false; btn.textContent = 'Accept fare';
    if (res.status !== 'success') { showToast(res.message || 'Could not confirm the fare.'); return; }
    showToast(res.message || 'Fare confirmed.');
    pollStatus(); // will route to FOUND now that the trip is spawned
  });

  qs('#ppRejectFareBtn')?.addEventListener('click', async () => {
    if (!activeRequestId) return;
    const btn = qs('#ppRejectFareBtn');
    btn.disabled = true;
    const res = await apiPost('../api/private-pickup.php', { action: 'reject_fare', request_public_id: activeRequestId });
    btn.disabled = false;
    if (res.status !== 'success') { showToast(res.message || 'Could not decline the fare.'); return; }
    showToast(res.message || 'Fare declined.');
    resumeSearching(res.data && res.data.expires_in_seconds);
  });

  // ---- driver found ---------------------------------------------------
  function enterFound(d) {
    // Countdown no longer applies once a driver is assigned.
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }

    if (view !== 'found' && window.MohToh && window.MohToh.sound) window.MohToh.sound.play('success');

    qs('#ppDriverName').textContent = d.driver_name || 'Your driver';
    const bits = [];
    if (d.driver_rating) bits.push(`★ ${Number(d.driver_rating).toFixed(1)}`);
    // current_fare is the single authoritative agreed fare (criterion #2),
    // shown prominently in its own row rather than buried in the meta line.
    const fare = d.current_fare != null ? d.current_fare : d.price;
    const agreedEl = qs('#ppAgreedFare');
    if (agreedEl) agreedEl.textContent = fare != null ? naira(fare) : '—';
    if (d.driver_vehicle_make || d.driver_plate) {
      const veh = [d.driver_vehicle_color, d.driver_vehicle_make, d.driver_vehicle_model].filter(Boolean).join(' ');
      bits.push(veh + (d.driver_plate ? ` · ${d.driver_plate}` : ''));
    }
    bits.push(d.vehicle_type_label ? `Private ${d.vehicle_type_label} pickup` : 'Private pickup');
    qs('#ppDriverMeta').textContent = bits.join(' · ');

    renderArrival(d);

    // Deep-link the tracking button straight to THIS trip's live detail
    // sheet on the existing Trips page. get_trip_detail re-checks ownership,
    // so passing the booking id here is safe.
    const openBtn = qs('#ppOpenTripBtn');
    if (openBtn) {
      openBtn.href = d.booking_id
        ? `trips.php?tab=upcoming&open=${encodeURIComponent(d.booking_id)}`
        : 'trips.php';
    }

    view = 'found';
    show(foundSection);

    // Keep polling only until the passenger has confirmed arrival — after
    // that nothing on this screen changes, so we can stop.
    if (d.passenger_confirmed_arrival) {
      stopTimers();
    } else {
      ensurePolling();
    }
  }

  // Show the arrival-confirm control exactly when the driver has marked
  // arrival and the passenger has not yet confirmed (criteria #11/#12).
  function renderArrival(d) {
    const block = qs('#ppArrivalBlock');
    const done  = qs('#ppArrivalConfirmed');
    if (!block || !done) return;
    if (d.passenger_confirmed_arrival) {
      block.classList.add('u-hidden');
      done.classList.remove('u-hidden');
    } else if (d.driver_arrived) {
      block.classList.remove('u-hidden');
      done.classList.add('u-hidden');
    } else {
      block.classList.add('u-hidden');
      done.classList.add('u-hidden');
    }
  }

  qs('#ppConfirmArrivalBtn')?.addEventListener('click', async () => {
    if (!activeRequestId) return;
    const btn = qs('#ppConfirmArrivalBtn');
    btn.disabled = true; btn.innerHTML = '<span class="btn-spinner"></span>';
    const res = await apiPost('../api/private-pickup.php', { action: 'confirm_arrival', request_public_id: activeRequestId });
    if (res.status !== 'success') {
      btn.disabled = false; btn.textContent = 'Confirm my driver has arrived';
      showToast(res.message || 'Could not confirm arrival.');
      return;
    }
    showToast(res.message || 'Arrival confirmed.');
    qs('#ppArrivalBlock').classList.add('u-hidden');
    qs('#ppArrivalConfirmed').classList.remove('u-hidden');
    stopTimers(); // nothing further changes on this screen
  });

  // ---- no driver ------------------------------------------------------
  function enterNoDriver(requestId, message) {
    stopTimers();
    // The request may still be sitting pending server-side (nobody was in
    // range). Cancel it so it can't be claimed after the rider has moved on.
    if (requestId) {
      apiPost('../api/private-pickup.php', { action: 'cancel', request_public_id: requestId });
    }
    clearActive();
    qs('#ppNoDriverSubtext').textContent = message;
    view = 'no_driver';
    show(noDriverSection);
  }

  qs('#ppRetryBtn')?.addEventListener('click', () => {
    hideAlert(formAlert);
    view = 'form';
    show(formSection);
  });

  // ---- cancel ---------------------------------------------------------
  qs('#ppCancelBtn')?.addEventListener('click', async () => {
    if (!activeRequestId) { view = 'form'; show(formSection); return; }
    const btn = qs('#ppCancelBtn');
    btn.disabled = true;
    const res = await apiPost('../api/private-pickup.php', {
      action: 'cancel', request_public_id: activeRequestId,
    });
    btn.disabled = false;
    showToast(res.message || 'Request cancelled.');
    clearActive();
    view = 'form';
    show(formSection);
  });

  // ---- resume an in-flight request across a reload --------------------
  // The server tells us (data-active-pickup-id) whether this rider currently
  // has a live request, so the flow — including the arrival confirmation —
  // resumes even after a full tab close, not only within one sessionStorage
  // session. sessionStorage still supplies the pickup/destination labels for a
  // nicer searching view when it's available.
  (function resume() {
    let saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { saved = null; }
    const serverId = (document.body.getAttribute('data-active-pickup-id') || '').trim();
    const rid = serverId || (saved && saved.id);
    if (!rid) { show(formSection); return; }

    activeRequestId = rid;
    qs('#ppSearchingPickup').textContent = (saved && saved.pickup) || '';
    qs('#ppSearchingDest').textContent = (saved && saved.destination) || '';
    qs('#ppSearchingCountdown').textContent = '—';
    qs('#ppSearchingSubtext').textContent = 'Waiting for a driver to accept…';
    view = 'searching';
    show(searchingSection);
    pollStatus(); // routes to the correct state (searching / proposal / found)
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

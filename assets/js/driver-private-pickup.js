// assets/js/driver-private-pickup.js
// Private Pickup (SYSTEM 2) — the driver side of the solo pickup lane, open
// to verified Keke AND private car drivers. Lives on the SAME Driver Ride
// Requests page as the Join Ride demand feed (driver-ride-requests.js) but is
// a completely separate module that touches its own DOM ids only, so it can
// never break the Join Ride feed.
//
// Everything here is a thin client over api/private-pickup.php and
// api/pickup-availability.php / api/toggle-online.php — no business logic,
// no eligibility decisions, no fare math. The backend is the single source
// of truth for who may operate and which requests are in range; this module
// only renders what the server returns and forwards Accept/Decline.
//
// The audible alert reuses the existing sound system exactly as the rest of
// the app does: window.MohToh.sound.play('warning') (a real WAV that plays
// natively) + a vibration via the device bridge. A beep only fires when a
// NEW request id appears in the feed that we have not already alerted on.

document.addEventListener('DOMContentLoaded', () => {
  const section        = qs('#pickupSection');
  if (!section) return; // page without the pickup section — nothing to do

  const statusBadge    = qs('#pickupStatusBadge');
  const subtext        = qs('#pickupSubtext');
  const prompt         = qs('#pickupPrompt');
  const yesBtn         = qs('#pickupYesBtn');
  const noBtn          = qs('#pickupNoBtn');
  const onlineRow      = qs('#pickupOnlineRow');
  const onlineLabel    = qs('#pickupOnlineLabel');
  const onlineToggle   = qs('#pickupOnlineToggle');
  const listContainer  = qs('#pickupListContainer');
  const emptyEl        = qs('#pickupEmpty');
  const ineligibleEl   = qs('#pickupIneligible');
  const ineligibleMsg  = qs('#pickupIneligibleReason');
  const sheet          = qs('#pickupRequestSheet');
  const sheetContent   = qs('#pickupRequestContent');
  const sheetFooter    = qs('#pickupRequestFooter');
  const scrim          = qs('[data-sheet-scrim]');

  const FEED_POLL_MS = 8000;   // how often we re-check the pickup feed
  const LOC_PING_MS  = 30000;  // keep users.last_location_update fresh while online

  let isOnline = false;
  let feedTimer = null;
  let locTimer = null;
  const alertedIds = new Set();   // request ids we've already beeped for
  // Requests this driver declined. decline_private_pickup_request() is
  // deliberately non-destructive on shared state (another driver may still
  // take the request), so the server keeps returning it in this driver's
  // feed — without this filter the declined card reappeared on the very next
  // 8s poll and Decline looked broken. Session-scoped is sufficient: a
  // pickup request expires after 90 seconds.
  const declinedIds = new Set();
  let firstFeedLoad = true;       // don't beep for the backlog on first load

  // ---- helpers -------------------------------------------------------
  function setBadge(text, kind) {
    statusBadge.textContent = text;
    statusBadge.className = 'pricing-badge ' + (kind || 'is-info');
  }

  function playAlert() {
    // Real audible beep via the shared sound manager (valid 'warning' WAV),
    // plus a vibration. Both are best-effort and no-op safely if blocked.
    if (window.MohToh && window.MohToh.sound) window.MohToh.sound.play('warning');
    if (window.MohToh && window.MohToh.notification) window.MohToh.notification.vibrate([200, 100, 200]);
  }

  // ---- availability / online state ----------------------------------
  async function refreshAvailability() {
    const data = await apiGet('../api/pickup-availability.php', { action: 'get' });
    if (data.status !== 'success') {
      // Not eligible / not a keke driver / error — hide the interactive
      // parts and show why, without touching the Join Ride feed.
      showIneligible(data.message || 'Private Pickup is unavailable.');
      return;
    }
    const d = data.data || {};

    if (!d.eligible_base) {
      // The server says this driver cannot operate Private Pickup (unverified
      // account/vehicle, or a vehicle class that doesn't serve this lane) —
      // show its reason rather than a hardcoded one.
      showIneligible(d.eligibility_reason || 'Private Pickup is unavailable.');
      return;
    }
    if (d.pickup_vehicle_eligible === false) {
      // Vehicle class decided by the SERVER from the driver's own persisted
      // vehicle record (keke or private car), not guessed here. This used to
      // read `!is_keke_driver && vehicle_type !== 'car'`, which was stricter
      // than the server: an eligible car driver stored as 'saloon'/'sedan'/'suv'
      // was shown "not available" and never got today's opt-in prompt, so
      // private_pickup_enabled stayed 0 and no request could ever reach them.
      showIneligible('Private Pickup is available to verified Keke and car drivers only.');
      return;
    }

    ineligibleEl.classList.add('u-hidden');

    if (!d.answered_today) {
      // Show today's prompt; hide feed + online row until answered.
      prompt.classList.remove('u-hidden');
      onlineRow.classList.add('u-hidden');
      listContainer.classList.add('u-hidden');
      emptyEl.classList.add('u-hidden');
      setBadge('Answer today’s prompt', 'is-warning');
      subtext.textContent = 'Confirm whether you want Private Pickup requests today.';
      stopFeed();
      return;
    }

    prompt.classList.add('u-hidden');

    if (!Number(d.available_today)) {
      // Said "no" today — no feed, Join Ride unaffected.
      onlineRow.classList.add('u-hidden');
      listContainer.classList.add('u-hidden');
      emptyEl.classList.add('u-hidden');
      setBadge('Off for today', 'is-neutral');
      subtext.textContent = 'You chose not to receive Private Pickup today. Join Ride is unaffected.';
      stopFeed();
      return;
    }

    // Available today: show the online toggle + (if online) the live feed.
    onlineRow.classList.remove('u-hidden');
    listContainer.classList.remove('u-hidden');
    isOnline = !!Number(d.is_online);
    reflectOnline();
    if (isOnline) startFeed(); else stopFeed();
  }

  function reflectOnline() {
    onlineLabel.textContent = isOnline ? 'Online' : 'Offline';
    onlineToggle.textContent = isOnline ? 'Go offline' : 'Go online';
    onlineToggle.classList.toggle('btn-outline', isOnline);
    onlineToggle.classList.toggle('btn-primary', !isOnline);
    if (isOnline) {
      setBadge('Online — receiving requests', 'is-success');
      subtext.textContent = 'You will hear a beep when a nearby request arrives.';
    } else {
      setBadge('Available today · offline', 'is-warning');
      subtext.textContent = 'Go online to start receiving Private Pickup requests.';
      listContainer.innerHTML = '';
      emptyEl.classList.add('u-hidden');
    }
  }

  function showIneligible(reason) {
    prompt.classList.add('u-hidden');
    onlineRow.classList.add('u-hidden');
    listContainer.classList.add('u-hidden');
    emptyEl.classList.add('u-hidden');
    ineligibleEl.classList.remove('u-hidden');
    ineligibleMsg.textContent = reason;
    setBadge('Unavailable', 'is-neutral');
    subtext.textContent = '';
    stopFeed();
  }

  // ---- daily prompt answers -----------------------------------------
  yesBtn?.addEventListener('click', async () => {
    yesBtn.disabled = true;
    const res = await apiPost('../api/pickup-availability.php', { action: 'set', available: 1 });
    yesBtn.disabled = false;
    showToast(res.message || (res.status === 'success' ? 'You are set for today.' : 'Could not update.'));
    refreshAvailability();
  });

  noBtn?.addEventListener('click', async () => {
    noBtn.disabled = true;
    const res = await apiPost('../api/pickup-availability.php', { action: 'set', available: 0 });
    noBtn.disabled = false;
    showToast(res.message || 'Updated.');
    refreshAvailability();
  });

  // ---- online toggle (Private Pickup discoverability only) ----------
  onlineToggle?.addEventListener('click', async () => {
    const next = isOnline ? 0 : 1;
    onlineToggle.disabled = true;
    const res = await apiPost('../api/toggle-online.php', { is_online: next });
    onlineToggle.disabled = false;
    if (res.status !== 'success') {
      showToast(res.message || 'Could not change status.');
      return;
    }
    isOnline = !!next;
    reflectOnline();
    showToast(res.message);
    if (isOnline) { pingLocation(); startFeed(); } else { stopFeed(); }
  });

  // ---- location freshness while online ------------------------------
  // The 20KM match uses the driver's CURRENT location, and the backend
  // treats a stale GPS ping as "not available" (driver_location_ttl_seconds).
  // driver.js's watcher isn't loaded on this page, so we keep our own
  // lightweight ping alive only while online.
  function pingLocation() {
    if (!window.MohToh || !window.MohToh.location) return;
    window.MohToh.location.getCurrentLocation().then(
      (pos) => { apiPost('../api/update-location.php', { latitude: pos.latitude, longitude: pos.longitude }); },
      () => { /* denied/unavailable — backend will simply not surface us */ }
    );
  }

  // ---- pickup feed ---------------------------------------------------
  function pickupCard(r) {
    const dist = r.distance_to_pickup_km != null ? Number(r.distance_to_pickup_km).toFixed(1) : '—';
    const trip = r.trip_distance_km != null ? Number(r.trip_distance_km).toFixed(1) : '—';
    // your_fare is the exact amount THIS driver is paid on accept: for a keke
    // it already includes the run to the pickup point (criterion #7); for a
    // car it is trip-only (criterion #9). passenger_offer is what the rider is
    // currently looking at. When they differ, accepting cannot silently change
    // the price — it must be PROPOSED and the rider must consent (criteria
    // #3/#4). requires_consent is decided by the server, not here.
    const yourFare = Number(r.your_fare != null ? r.your_fare : r.fare || 0);
    const offer    = Number(r.passenger_offer != null ? r.passenger_offer : yourFare);
    const consent  = !!r.requires_consent;
    const id = r.request_public_id;
    const repo = (r.reposition_km != null && Number(r.reposition_km) > 0)
      ? ` · Repositioning ~${Number(r.reposition_km).toFixed(1)} km` : '';

    // Fare header: a single agreed figure, or the offer-vs-your-fare split.
    const fareHeader = consent
      ? `<div class="u-flex u-justify-between u-items-center u-mb-2">
           <span class="pricing-badge is-success"><i class="bi bi-cash-coin"></i> Your fare ₦${yourFare.toLocaleString()}</span>
           <span class="pricing-badge is-info"><i class="bi bi-signpost-2"></i> ${dist} km to pickup</span>
         </div>
         <div class="u-text-xs u-text-faint u-mb-2">Passenger offered ₦${offer.toLocaleString()} — propose your fare for them to confirm.</div>`
      : `<div class="u-flex u-justify-between u-items-center u-mb-2">
           <span class="pricing-badge is-success"><i class="bi bi-cash-coin"></i> ₦${yourFare.toLocaleString()}</span>
           <span class="pricing-badge is-info"><i class="bi bi-signpost-2"></i> ${dist} km to pickup</span>
         </div>`;

    // The custom-amount row lets a driver name a different price than the
    // one-tap figure — always a PROPOSAL the passenger must confirm, never
    // final (criteria #3/#4). Offered on BOTH lanes: a car driver whose fare
    // already equals the offer can still counter-offer a different amount,
    // exactly as a keke can.
    const customRow =
      `<button type="button" class="btn btn-ghost btn-sm" data-toggle-custom="${id}" style="width:100%;">Propose a different amount</button>
       <div class="u-hidden u-mt-2" data-custom="${id}">
         <div class="field"><i class="bi bi-cash"></i><input type="number" min="1" step="1" inputmode="numeric" placeholder="Enter your fare (₦)" data-custom-input="${id}"></div>
         <button type="button" class="btn btn-primary btn-sm u-mt-2" style="width:100%;" data-custom-send="${id}">Send proposal</button>
       </div>`;

    // Actions: cars (fare == offer) finalise with one tap; keke / any changed
    // fare can only be proposed. Either way the custom-amount row is available
    // for a counter-offer.
    const actions = consent
      ? `<div class="u-flex u-gap-2 u-mb-2">
           <button type="button" class="btn btn-primary btn-sm" style="width:auto;flex:1;" data-pickup-propose="${id}">Propose ₦${yourFare.toLocaleString()}</button>
           <button type="button" class="btn btn-outline btn-sm" style="width:auto;flex:1;" data-pickup-decline="${id}">Decline</button>
         </div>
         ${customRow}`
      : `<div class="u-flex u-gap-2 u-mb-2">
           <button type="button" class="btn btn-primary btn-sm" style="width:auto;flex:1;" data-pickup-accept="${id}">Accept ₦${yourFare.toLocaleString()}</button>
           <button type="button" class="btn btn-outline btn-sm" style="width:auto;flex:1;" data-pickup-decline="${id}">Decline</button>
         </div>
         ${customRow}`;

    return `
      <div class="card u-mb-3 anim-fade-in" data-pickup-card="${id}" style="padding:var(--space-4);border-left:3px solid var(--color-primary);">
        ${fareHeader}
        <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);font-size:.7rem;"></i>${escapeHtml(r.pickup_location)}</div>
        <div class="ride-tile__dest" style="margin:0 0 8px;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);font-size:.7rem;"></i>${escapeHtml(r.destination)}</div>
        <div class="u-text-xs u-text-faint u-mb-3"><i class="bi bi-person"></i> ${escapeHtml(r.rider_name || 'Rider')} · ${escapeHtml(r.vehicle_type_label || 'Private')} · Trip ~${trip} km${repo}</div>
        ${actions}
      </div>`;
  }

  // A request this driver has proposed a fare on and is now waiting for the
  // passenger to accept or decline. Read-only status card (criteria #5/#8):
  // the negotiation is in the passenger's hands, so there is no action here —
  // it clears itself when they accept (→ shows under Trips) or decline (→ the
  // request reopens into the feed below).
  function proposalWaitingCard(p) {
    const proposed = Number(p.proposed_fare || 0);
    const offer    = Number(p.passenger_offer || 0);
    return `
      <div class="card u-mb-3 anim-fade-in" data-pickup-proposal="${p.request_public_id}" style="padding:var(--space-4);border-left:3px solid var(--color-warning,#E8A33D);">
        <div class="u-flex u-justify-between u-items-center u-mb-2">
          <span class="pricing-badge is-info"><i class="bi bi-hourglass-split"></i> Waiting for passenger</span>
          <span class="u-text-sm u-text-strong">You proposed ₦${proposed.toLocaleString()}</span>
        </div>
        <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);font-size:.7rem;"></i>${escapeHtml(p.pickup_location)}</div>
        <div class="ride-tile__dest" style="margin:0 0 8px;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);font-size:.7rem;"></i>${escapeHtml(p.destination)}</div>
        <div class="u-text-xs u-text-faint"><i class="bi bi-person"></i> ${escapeHtml(p.rider_name || 'Rider')} · ${escapeHtml(p.vehicle_type_label || 'Private')} · They offered ₦${offer.toLocaleString()}</div>
      </div>`;
  }

  async function loadPickupFeed() {
    const data = await apiGet('../api/private-pickup.php', { action: 'feed' });
    if (data.status !== 'success') { return; }
    const payload = data.data || {};

    if (payload.eligible === false) {
      // Server revoked eligibility mid-session (e.g. went stale / offline).
      // Re-run the availability sync so the UI reflects the true state.
      refreshAvailability();
      return;
    }

    const rows = (payload.requests || []).filter((r) => !declinedIds.has(r.request_public_id));

    // Fire the beep for any request id we haven't alerted on yet (but never
    // for the initial backlog when the page first loads).
    if (!firstFeedLoad) {
      const hasNew = rows.some((r) => !alertedIds.has(r.request_public_id));
      if (hasNew) playAlert();
    }
    rows.forEach((r) => alertedIds.add(r.request_public_id));
    firstFeedLoad = false;

    // Proposals this driver is awaiting a decision on render first (as
    // read-only "waiting" cards), then the live pending requests below them.
    const proposals = payload.proposals || [];
    listContainer.innerHTML = proposals.map(proposalWaitingCard).join('') + rows.map(pickupCard).join('');
    emptyEl.classList.toggle('u-hidden', (proposals.length + rows.length) > 0);
  }

  function startFeed() {
    if (feedTimer) return;
    // NOTE: firstFeedLoad is deliberately NOT reset here. startFeed() also
    // runs every time the tab becomes visible again, and resetting it there
    // suppressed the beep for requests that arrived while the driver was on
    // another tab — exactly the case the alert exists for. alertedIds already
    // covers the "don't re-beep what we've seen" half, so the flag only needs
    // to suppress the very first load of the page session.
    pingLocation();
    loadPickupFeed();
    feedTimer = setInterval(loadPickupFeed, FEED_POLL_MS);
    locTimer = setInterval(pingLocation, LOC_PING_MS);
  }

  function stopFeed() {
    if (feedTimer) { clearInterval(feedTimer); feedTimer = null; }
    if (locTimer) { clearInterval(locTimer); locTimer = null; }
  }

  // ---- accept / propose / decline -----------------------------------
  // Shared handler for the two ways a driver commits to a fare:
  //   accept  -> finalise at the passenger's offer (only offered for cars /
  //              a keke already at the pickup, where nothing changed).
  //   propose -> send a fare for the passenger to confirm. NEVER final on its
  //              own (criteria #3/#4); the backend records it and waits.
  async function sendProposal(id, amount, triggerBtn) {
    triggerBtn.disabled = true;
    const original = triggerBtn.innerHTML;
    triggerBtn.innerHTML = '<span class="btn-spinner"></span>';
    const payload = { action: 'propose', request_public_id: id };
    if (amount != null) payload.amount = amount;
    const res = await apiPost('../api/private-pickup.php', payload);
    if (res.status !== 'success') {
      triggerBtn.disabled = false;
      triggerBtn.innerHTML = original;
      showToast(res.message || 'Could not send your fare.');
      loadPickupFeed();
      return;
    }
    const outcome = res.data && res.data.outcome;
    if (outcome === 'accepted') {
      // The proposed amount matched the offer exactly, so it finalised — the
      // trip is spawned and this driver is en route.
      if (window.MohToh && window.MohToh.sound) window.MohToh.sound.play('success');
      showToast(res.message || 'Pickup accepted.');
      setTimeout(() => { window.location.href = 'trips.php'; }, 800);
      return;
    }
    // Proposed and waiting on the passenger. The request leaves the pending
    // pool and comes straight back as a "waiting for passenger" card via the
    // feed's proposals list — refresh now so the driver sees that transition
    // immediately rather than an empty gap until the next poll. (No declinedIds
    // entry: if the passenger later rejects, the reopened request should be
    // offered to this driver again.)
    showToast(res.message || 'Fare proposed. Waiting for the passenger to confirm.');
    triggerBtn.closest('[data-pickup-card]')?.remove();
    loadPickupFeed();
  }

  listContainer.addEventListener('click', async (e) => {
    const acceptBtn   = e.target.closest('[data-pickup-accept]');
    const declineBtn  = e.target.closest('[data-pickup-decline]');
    const proposeBtn  = e.target.closest('[data-pickup-propose]');
    const toggleBtn   = e.target.closest('[data-toggle-custom]');
    const customSend  = e.target.closest('[data-custom-send]');

    if (toggleBtn) {
      const id = toggleBtn.dataset.toggleCustom;
      const row = listContainer.querySelector(`[data-custom="${id}"]`);
      if (row) row.classList.toggle('u-hidden');
      return;
    }

    if (declineBtn) {
      const id = declineBtn.dataset.pickupDecline;
      declineBtn.disabled = true;
      await apiPost('../api/private-pickup.php', { action: 'decline', request_public_id: id });
      // Declining is per-driver and non-destructive server-side, so the feed
      // keeps returning this request. Remember it locally as well as removing
      // the card, otherwise the next poll re-renders it.
      declinedIds.add(id);
      declineBtn.closest('[data-pickup-card]')?.remove();
      emptyEl.classList.toggle('u-hidden', listContainer.children.length > 0);
      return;
    }

    if (customSend) {
      const id = customSend.dataset.customSend;
      const input = listContainer.querySelector(`[data-custom-input="${id}"]`);
      const amount = input ? Number(input.value) : NaN;
      if (!amount || amount <= 0) { showToast('Enter a valid fare to propose.'); return; }
      await sendProposal(id, amount, customSend);
      return;
    }

    if (proposeBtn) {
      const id = proposeBtn.dataset.pickupPropose;
      // No amount -> the server proposes this driver's own computed fare.
      await sendProposal(id, null, proposeBtn);
      return;
    }

    if (acceptBtn) {
      const id = acceptBtn.dataset.pickupAccept;
      acceptBtn.disabled = true;
      acceptBtn.innerHTML = '<span class="btn-spinner"></span>';
      const res = await apiPost('../api/private-pickup.php', { action: 'accept', request_public_id: id });
      if (res.status === 'success' && res.data && res.data.outcome === 'accepted') {
        if (window.MohToh && window.MohToh.sound) window.MohToh.sound.play('success');
        showToast(res.message || 'Pickup accepted.');
        loadPickupFeed();
        setTimeout(() => { window.location.href = 'trips.php'; }, 800);
      } else if (res.status === 'success' && res.data && res.data.outcome === 'proposed') {
        // The server converted a fare that no longer matches the offer into a
        // proposal rather than finalising it (criterion #4) — refresh so it
        // shows as a "waiting for passenger" card.
        showToast(res.message || 'Fare proposed. Waiting for the passenger to confirm.');
        acceptBtn.closest('[data-pickup-card]')?.remove();
        loadPickupFeed();
      } else {
        // Lost the race, expired, or eligibility changed — re-enable and
        // refresh so the driver sees the current, true state.
        acceptBtn.disabled = false;
        acceptBtn.textContent = 'Accept';
        showToast(res.message || 'This request is no longer available.');
        loadPickupFeed();
      }
    }
  });

  // Stop polling when the tab is hidden; resume (and re-check) when visible.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { stopFeed(); }
    else if (isOnline) { startFeed(); }
  });

  // ---- boot ----------------------------------------------------------
  refreshAvailability();
});

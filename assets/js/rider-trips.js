// assets/js/rider-trips.js
// Rider Trips module (rider/trips.php). Reuses api/rideshare.php's
// existing my_bookings / get_driver_location / cancel_booking /
// rate_ride actions (the same ones rider/my-trips.php already used)
// plus the new (read-only) get_trip_detail action for the map/timeline
// bottom sheet. No new booking, payment, or rating logic here.

document.addEventListener('DOMContentLoaded', () => {
  const listContainer = qs('#tripListContainer');
  const searchInput = qs('#tripSearchInput');
  const dateFilter = qs('#tripFilterDate');
  const paymentFilter = qs('#tripFilterPayment');
  const scrim = qs('[data-sheet-scrim]');

  const requestedTab = new URLSearchParams(location.search).get('tab');
  let activeTabKey = ['active', 'upcoming', 'completed', 'cancelled'].includes(requestedTab) ? requestedTab : 'active';
  if (requestedTab) {
    qsa('[data-trip-tab]').forEach((t) => t.classList.toggle('is-active', t.dataset.tripTab === activeTabKey));
  }
  let bookings = [];
  let liveMapPollTimer = null;
  let currentRatingBookingId = null;

  async function loadAll() {
    try {
      const data = await apiGet('../api/rideshare.php', { action: 'my_bookings' });
      bookings = data.status === 'success' ? (data.data.rides || []) : [];
      render();
      maybeOpenRequestedTrip();
    } catch (error) {
      listContainer.innerHTML = '<div class="alert-banner error">Could not load your trips.</div>';
    }
  }

  // Deep-link support: rider/private-pickup.php's "Track my trip" button
  // links here with ?open=<booking_id> so the accepted Private Pickup opens
  // straight into the existing tracking sheet. Fired once, after the first
  // load; the server still re-checks ownership in get_trip_detail.
  let requestedOpenHandled = false;
  function maybeOpenRequestedTrip() {
    if (requestedOpenHandled) return;
    const openId = new URLSearchParams(location.search).get('open');
    if (!openId) return;
    requestedOpenHandled = true;
    openTripDetail(openId);
  }

  function matchesFilters(b) {
    const search = (searchInput.value || '').trim().toLowerCase();
    if (search && !String(b.destination || '').toLowerCase().includes(search) && !String(b.start_location || '').toLowerCase().includes(search)) return false;
    if (dateFilter.value && b.departure_date !== dateFilter.value) return false;
    if (paymentFilter.value && b.payment_status !== paymentFilter.value) return false;
    return true;
  }

  function render() {
    if (activeTabKey === 'active') {
      renderActive(bookings.filter((b) => b.ride_status === 'in_progress' && b.status !== 'cancelled' && matchesFilters(b)));
    } else if (activeTabKey === 'upcoming') {
      renderUpcoming(bookings.filter((b) => ['active', 'full'].includes(b.ride_status) && b.status !== 'cancelled' && matchesFilters(b)));
    } else if (activeTabKey === 'completed') {
      renderCompleted(bookings.filter((b) => b.ride_status === 'completed' && b.status !== 'cancelled' && matchesFilters(b)));
    } else {
      renderCancelled(bookings.filter((b) => b.status === 'cancelled' && matchesFilters(b)));
    }
  }

  // A Private Pickup booking reserves the whole vehicle, so the rider is
  // riding alone rather than sharing seats. Same tables, same states —
  // this only labels it so the two products stay tellable apart. Shared
  // Join Ride bookings are the default and stay unlabelled.
  function rideModeBadge(b) {
    if (b.ride_mode !== 'private') return '';
    return '<span class="status-pill pending" style="margin-left:6px;"><i class="bi bi-lock-fill"></i> Private Pickup</span>';
  }

  function driverBlock(b) {
    const img = b.driver_image && b.driver_image !== 'default.png' ? `../img.php?f=${encodeURIComponent(b.driver_image)}&w=160` : `https://ui-avatars.com/api/?name=${encodeURIComponent(b.driver_name)}&background=0F8B8D&color=fff`;
    return `
      <div class="ride-tile__driver">
        <img src="${img}" alt="">
        <div>
          <div class="ride-tile__driver-name">${escapeHtml(b.driver_name)} <span class="rating-chip"><i class="bi bi-star-fill"></i> ${parseFloat(b.driver_rating || 5).toFixed(1)}</span></div>
          <div class="ride-tile__driver-meta">${escapeHtml(b.vehicle_make || '')} ${escapeHtml(b.vehicle_model || '')} ${b.vehicle_plate ? '· ' + escapeHtml(b.vehicle_plate) : ''}</div>
        </div>
      </div>`;
  }

  // ---------------------------------------------------------------
  // Active
  // ---------------------------------------------------------------
  function renderActive(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('No trip in progress right now.', 'Find Ride', 'dashboard.php');
      return;
    }
    listContainer.innerHTML = rides.map((b) => `
      <div class="card ride-tile anim-fade-in">
        <span class="trip-live-badge"><span class="trip-live-dot"></span>Live trip</span>${rideModeBadge(b)}
        <div class="ride-tile__dest u-mt-2"><i class="bi bi-record-circle" style="color:var(--color-primary)"></i>${escapeHtml(b.start_location)}</div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(b.destination)}</div>
        <div class="u-text-sm u-mt-2" style="font-weight:700;color:var(--color-primary);">${escapeHtml(formatTripStateLabel(b.trip_state, 'rider'))}</div>
        <div class="u-text-xs u-text-faint u-mt-1">${escapeHtml(formatPassengerStateLabel(b.passenger_state))} · Seat ${b.seats_booked}</div>
        ${driverBlock(b)}
        <div class="ride-tile__actions">
          <a href="tel:${escapeHtml(b.driver_phone || '')}" class="btn btn-outline btn-sm"><i class="bi bi-telephone"></i> Call</a>
          <button type="button" class="btn btn-outline btn-sm" data-open-ride-chat="${b.ride_share_id}" data-chat-driver-id="${b.driver_id}"><i class="bi bi-chat-dots"></i> Chat</button>
          <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${b.booking_id}"><i class="bi bi-map"></i> Track</button>
        </div>
        <div class="u-flex u-gap-2 u-mt-2">
          <button type="button" class="btn btn-outline btn-sm" style="color:var(--color-danger);border-color:var(--color-danger);" data-soon="Emergency assistance is coming soon"><i class="bi bi-exclamation-triangle"></i> Emergency</button>
        </div>
      </div>
    `).join('');
  }

  // ---------------------------------------------------------------
  // Upcoming
  // ---------------------------------------------------------------
  function renderUpcoming(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('You have not booked any rides yet.', 'Find Ride', 'dashboard.php');
      return;
    }
    listContainer.innerHTML = rides.map((b) => `
      <div class="card ride-tile anim-fade-in">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(b.departure_date)} · ${formatTime12h(b.departure_time)}</div>
            <div class="ride-tile__from">${escapeHtml(b.start_location)}</div>
          </div>
          <span class="status-pill ${b.ride_status}">${escapeHtml(b.ride_status)}</span>${rideModeBadge(b)}
        </div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(b.destination)}</div>
        ${driverBlock(b)}
        <div class="u-flex u-justify-between u-text-xs u-text-soft u-mt-2">
          <span>Seat ${b.seats_booked}</span>
          <span>${escapeHtml(b.payment_method === 'wallet' ? 'MohToh Wallet' : 'Direct to driver')}</span>
        </div>
        <div class="ride-tile__actions">
          <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${b.booking_id}">Details</button>
          <button type="button" class="btn btn-outline btn-sm" data-cancel-booking="${b.booking_id}" style="color:var(--color-danger);border-color:var(--color-danger);">Cancel</button>
        </div>
      </div>
    `).join('');
  }

  // ---------------------------------------------------------------
  // Completed
  // ---------------------------------------------------------------
  function renderCompleted(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('No completed trips yet.', null, null);
      return;
    }
    listContainer.innerHTML = rides.map((b) => `
      <div class="card ride-tile anim-fade-in">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(b.departure_date)}</div>
            <div class="ride-tile__from">${escapeHtml(b.start_location)}</div>
          </div>
          <span class="ride-tile__price">${formatNaira(b.fare_amount)}<small>${escapeHtml(b.payment_method === 'wallet' ? 'Wallet' : 'Direct')}</small></span>
        </div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(b.destination)}</div>
        ${rideModeBadge(b)}
        ${driverBlock(b)}
        <div class="ride-tile__actions">
          ${b.rating_id
            ? `<span class="u-text-xs u-text-faint" style="flex:1;align-self:center;"><i class="bi bi-star-fill" style="color:var(--color-accent);"></i> You rated this trip ${b.rating_given}/5</span>`
            : `<button type="button" class="btn btn-primary btn-sm" data-rate-booking="${b.booking_id}">Rate Driver</button>`}
          <a href="dashboard.php" class="btn btn-outline btn-sm">Book Again</a>
        </div>
      </div>
    `).join('');
  }

  // ---------------------------------------------------------------
  // Cancelled
  // ---------------------------------------------------------------
  function renderCancelled(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('No cancelled trips.', null, null);
      return;
    }
    listContainer.innerHTML = rides.map((b) => `
      <div class="card ride-tile anim-fade-in">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(b.departure_date)}</div>
            <div class="ride-tile__from">${escapeHtml(b.start_location)}</div>
          </div>
          <span class="status-pill rejected">Cancelled</span>${rideModeBadge(b)}
        </div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(b.destination)}</div>
        <div class="ride-tile__actions"><a href="dashboard.php" class="btn btn-outline btn-sm">Book Again</a></div>
      </div>
    `).join('');
  }

  // ---------------------------------------------------------------
  // Tabs + filters
  // ---------------------------------------------------------------
  qsa('[data-trip-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      qsa('[data-trip-tab]').forEach((t) => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      activeTabKey = tab.dataset.tripTab;
      render();
    });
  });
  searchInput?.addEventListener('input', render);
  dateFilter?.addEventListener('change', render);
  paymentFilter?.addEventListener('change', render);

  // ---------------------------------------------------------------
  // Trip detail sheet — map + merged timeline
  // ---------------------------------------------------------------
  const TIMELINE_STEP_LABELS = {
    CREATED: 'Ride created', PUBLISHED: 'Ride published', PASSENGERS_JOINING: 'You joined this ride',
    PASSENGERS_ASSEMBLED: 'Driver reached pickup', TRIP_STARTED: 'Trip started', PASSENGER_DROPPING: 'Arriving at destination',
    AUTO_COMPLETED_PENDING: 'Awaiting confirmation', TRIP_COMPLETED: 'Trip completed', CANCELLED: 'Cancelled',
    booked: 'Booking confirmed', navigating: 'On the way to pickup', arrived: 'Arrived at pickup',
    boarded: 'Boarded the vehicle', dropped_off: 'Dropped off', completed: 'Trip completed', no_show: 'Marked as no-show',
  };

  function renderTimeline(events) {
    if (!events.length) return '<div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No timeline events yet.</div>';
    return '<div class="trip-timeline">' + events.map((e, i) => `
      <div class="trip-timeline-item is-done">
        <div class="trip-timeline-item__rail">
          <div class="trip-timeline-item__dot"><i class="bi bi-check-lg"></i></div>
          ${i < events.length - 1 ? '<div class="trip-timeline-item__line"></div>' : ''}
        </div>
        <div class="trip-timeline-item__body">
          <div class="trip-timeline-item__label">${escapeHtml(TIMELINE_STEP_LABELS[e.to_state] || e.to_state)}</div>
          <div class="trip-timeline-item__time">${new Date(e.created_at.replace(' ', 'T')).toLocaleString()}</div>
        </div>
      </div>
    `).join('') + '</div>';
  }

  function stopLiveMapPolling() {
    if (liveMapPollTimer) { clearInterval(liveMapPollTimer); liveMapPollTimer = null; }
  }

  // Google Maps mini-map with live driver tracking. Provider detail lives
  // in assets/js/map.js (mtRenderMiniMap draws the real road route +
  // pickup/destination markers; mtUpdateMiniMapDriver moves the live driver
  // marker on each poll). Async: the map appears once the Google Maps API
  // finishes loading; the timeline renders immediately regardless.
  async function renderMiniMap(trip) {
    const mapEl = qs('#tripDetailMap');
    if (!mapEl || typeof mtRenderMiniMap !== 'function') return;
    await mtRenderMiniMap(mapEl, {
      pickup: { lat: trip.pickup_lat, lng: trip.pickup_lng },
      destination: { lat: trip.destination_lat, lng: trip.destination_lng },
      drawRoute: true,
    });

    // Live driver tracking — same api/rideshare.php?action=get_driver_location
    // that rider/my-trips.php's track modal already polled every 7s.
    if (['PASSENGERS_JOINING', 'PASSENGERS_ASSEMBLED', 'TRIP_STARTED', 'PASSENGER_DROPPING'].includes(trip.trip_state)) {
      const pollDriver = async () => {
        try {
          const data = await apiGet('../api/rideshare.php', { action: 'get_driver_location', ride_share_id: trip.ride_share_id });
          if (data.status === 'success' && mapEl.isConnected) {
            mtUpdateMiniMapDriver(data.data.latitude, data.data.longitude, 'Driver');
          }
        } catch (error) { /* silent — next poll tries again */ }
      };
      pollDriver();
      stopLiveMapPolling();
      liveMapPollTimer = setInterval(pollDriver, 7000);
    }
  }

  async function openTripDetail(bookingId) {
    const sheet = qs('#tripDetailSheet');
    const content = qs('#tripDetailContent');
    stopLiveMapPolling();
    content.innerHTML = '<div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:20px;height:20px;display:inline-block;"></span></div>';
    openSheet(sheet, scrim);

    try {
      const data = await apiGet('../api/rideshare.php', { action: 'get_trip_detail', booking_id: bookingId });
      if (data.status !== 'success') { content.innerHTML = `<div class="alert-banner error">${escapeHtml(data.message)}</div>`; return; }

      const { trip, timeline } = data.data;
      content.innerHTML = `
        <div class="trip-mini-map" id="tripDetailMap"></div>
        <div class="u-text-sm" style="font-weight:700;margin-bottom:var(--space-2);">${escapeHtml(formatTripStateLabel(trip.trip_state, 'rider'))}</div>
        <div class="info-row"><span class="info-row__label">Driver</span><span class="info-row__value">${escapeHtml(trip.driver_name)}</span></div>
        <div class="info-row"><span class="info-row__label">Vehicle</span><span class="info-row__value">${escapeHtml(trip.vehicle_make || '')} ${escapeHtml(trip.vehicle_model || '')}</span></div>
        <div class="info-row"><span class="info-row__label">Fare</span><span class="info-row__value">${formatNaira(trip.fare_amount)}</span></div>
        <div class="section-label u-mt-4">Trip timeline</div>
        ${renderTimeline(timeline)}
      `;
      renderMiniMap(trip);
    } catch (error) {
      content.innerHTML = '<div class="alert-banner error">Could not load trip details.</div>';
    }
  }

  qs('#tripDetailSheet [data-close-sheet]')?.addEventListener('click', stopLiveMapPolling);

  // ---------------------------------------------------------------
  // Actions: cancel, rate
  // ---------------------------------------------------------------
  document.addEventListener('click', async (e) => {
    const detailBtn = e.target.closest('[data-open-trip-detail]');
    if (detailBtn) { openTripDetail(detailBtn.dataset.openTripDetail); return; }

    const cancelBtn = e.target.closest('[data-cancel-booking]');
    if (cancelBtn) {
      if (!confirm('Cancel this booking? A wallet payment will be refunded automatically.')) return;
      setButtonLoading(cancelBtn, true, 'Cancelling…');
      try {
        const data = await apiPost('../api/rideshare.php', { action: 'cancel_booking', booking_id: Number(cancelBtn.dataset.cancelBooking) });
        showToast(data.message || (data.status === 'success' ? 'Booking cancelled' : 'Could not cancel booking'));
        if (data.status === 'success') loadAll();
      } catch (error) { showToast('Something went wrong.'); }
      finally { setButtonLoading(cancelBtn, false); }
      return;
    }

    const rateBtn = e.target.closest('[data-rate-booking]');
    if (rateBtn) {
      currentRatingBookingId = rateBtn.dataset.rateBooking;
      qs('#ratingBookingId').value = currentRatingBookingId;
      qs('#ratingValue').value = '';
      qsa('.rating-stars i').forEach((star) => star.classList.remove('is-filled'));
      qs('#ratingForm')?.reset();
      openSheet(qs('#ratingSheet'), scrim);
    }
  });

  qsa('.rating-stars i').forEach((star) => {
    star.addEventListener('click', () => {
      const value = Number(star.dataset.value);
      qs('#ratingValue').value = value;
      qsa('.rating-stars i').forEach((s) => s.classList.toggle('is-filled', Number(s.dataset.value) <= value));
    });
  });

  qs('#ratingForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const rating = Number(qs('#ratingValue').value);
    if (!rating) { showToast('Please select a star rating.'); return; }
    const comment = qs('#ratingForm textarea[name="comment"]').value;
    try {
      const data = await apiPost('../api/rideshare.php', { action: 'rate_ride', booking_id: Number(qs('#ratingBookingId').value), rating, comment });
      showToast(data.message || (data.status === 'success' ? 'Thank you!' : 'Could not submit rating'));
      if (data.status === 'success') {
        closeSheet(qs('#ratingSheet'), scrim);
        loadAll();
      }
    } catch (error) {
      showToast('Something went wrong. Please try again.');
    }
  });

  loadAll();
  setInterval(() => { if (activeTabKey === 'active') loadAll(); }, 20000);
});

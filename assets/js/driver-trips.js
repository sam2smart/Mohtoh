// assets/js/driver-trips.js
// Driver Trips module (driver/trips.php). Every figure rendered here
// comes straight from api/rideshare.php's existing my_rides /
// get_ride_history / get_passengers / get_earnings actions, plus the
// new get_trip_detail action (read-only: trip_state_events +
// payments + ride_commission_reservations, all pre-existing tables).
// Mutating actions (start_ride, complete_ride, update, cancel,
// confirm/reject_direct_payment) are the exact same endpoints
// driver/dashboard.php already calls — nothing new is introduced here
// beyond how it's displayed.

document.addEventListener('DOMContentLoaded', () => {
  const listContainer = qs('#tripListContainer');
  const searchInput = qs('#tripSearchInput');
  const dateFilter = qs('#tripFilterDate');
  const scrim = qs('[data-sheet-scrim]');

  const requestedTab = new URLSearchParams(location.search).get('tab');
  let activeTabKey = ['active', 'upcoming', 'completed', 'cancelled'].includes(requestedTab) ? requestedTab : 'active';
  if (requestedTab) {
    qsa('[data-trip-tab]').forEach((t) => t.classList.toggle('is-active', t.dataset.tripTab === activeTabKey));
  }
  let liveTrips = [];      // from my_rides — has trip_state, live GPS, reservation
  let historyTrips = [];   // from get_ride_history — has financials/ratings

  function haversineKm(lat1, lng1, lat2, lng2) {
    if (lat1 == null || lng1 == null || lat2 == null || lng2 == null) return null;
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function gpsStatusLabel(lastUpdate) {
    if (!lastUpdate) return { text: 'No GPS signal yet', cls: 'pending' };
    const ageMs = Date.now() - new Date(lastUpdate.replace(' ', 'T')).getTime();
    if (ageMs < 60000) return { text: 'Live', cls: 'approved' };
    if (ageMs < 5 * 60000) return { text: `Signal ${Math.round(ageMs / 60000)} min ago`, cls: 'pending' };
    return { text: 'Signal lost', cls: 'rejected' };
  }

  function reservationLabel(ride) {
    if (!ride.reservation_status) return 'No reservation';
    if (ride.reservation_status === 'active') return `${formatNaira(ride.reserved_amount)} reserved`;
    if (ride.reservation_status === 'settled') return `${formatNaira(ride.used_amount)} used, ${formatNaira(ride.released_amount)} released`;
    return `${formatNaira(ride.released_amount)} released (cancelled)`;
  }

  // Private Pickup trips live in the same ride_shares table and move
  // through the same trip states, so they legitimately appear in these
  // tabs — but a driver must be able to tell at a glance that the whole
  // vehicle is reserved rather than seats being sold. Shared rides are
  // the default and stay unlabelled so nothing about Join Ride changes.
  function rideModeBadge(ride) {
    if (ride.ride_mode !== 'private') return '';
    return '<span class="status-pill pending" style="margin-left:6px;"><i class="bi bi-lock-fill"></i> Private Pickup</span>';
  }

  // ---------------------------------------------------------------
  // Load
  // ---------------------------------------------------------------
  async function loadAll() {
    try {
      const [ridesRes, historyRes] = await Promise.all([
        apiGet('../api/rideshare.php', { action: 'my_rides' }),
        apiGet('../api/rideshare.php', { action: 'get_ride_history' }),
      ]);
      liveTrips = ridesRes.status === 'success' ? (ridesRes.data.rides || []) : [];
      historyTrips = historyRes.status === 'success' ? (historyRes.data.history || []) : [];
      render();
    } catch (error) {
      listContainer.innerHTML = '<div class="alert-banner error">Could not load your trips.</div>';
    }
  }

  function matchesFilters(ride) {
    const search = (searchInput.value || '').trim().toLowerCase();
    if (search && !String(ride.destination || '').toLowerCase().includes(search) && !String(ride.start_location || '').toLowerCase().includes(search)) {
      return false;
    }
    if (dateFilter.value && ride.departure_date !== dateFilter.value) return false;
    return true;
  }

  function render() {
    if (activeTabKey === 'active') {
      renderActive(liveTrips.filter((r) => r.status === 'in_progress' && matchesFilters(r)));
    } else if (activeTabKey === 'upcoming') {
      renderUpcoming(liveTrips.filter((r) => ['active', 'full'].includes(r.status) && matchesFilters(r)));
    } else if (activeTabKey === 'completed') {
      renderCompleted(historyTrips.filter((r) => r.status === 'completed' && matchesFilters(r)));
    } else {
      renderCancelled(historyTrips.filter((r) => r.status === 'cancelled' && matchesFilters(r)));
    }
  }

  let waitingPollingInterval = null;
  let notifiedFreePeriod = false;

  function stopWaitingPolling() {
    clearInterval(waitingPollingInterval);
    waitingPollingInterval = null;
    notifiedFreePeriod = false;
  }

  function startWaitingPolling(tripId) {
    if (waitingPollingInterval) return;
    waitingPollingInterval = setInterval(async () => {
      const data = await apiGet('../api/waiting-pricing.php', { action: 'get_status', trip_id: tripId });
      if (data.status === 'success') {
        const statusEl = qs('#waitingStatusMonitor');
        if (data.data.waiting) {
          if (!notifiedFreePeriod && data.data.duration > 300) { // 5 min = 300 sec
            showToast('Waiting charges have started.');
            notifiedFreePeriod = true;
          }
          if (statusEl) {
            statusEl.innerHTML = `
                <div class="alert-banner error u-mt-2">
                    <i class="bi bi-clock-fill"></i> Waiting Detected · ${Math.floor(data.data.duration/60)}m ${data.data.duration%60}s<br>
                    <strong>Current Waiting Fee: ₦${Number(data.data.fee).toLocaleString()}</strong>
                </div>
            `;
          }
        } else if (statusEl) {
            statusEl.innerHTML = '<div class="alert-banner success u-mt-2">Moving</div>';
        }
      }
    }, 15000);
  }

  // ---------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------
  function renderActive(rides) {
    if (!rides.length) {
      stopWaitingPolling();
      listContainer.innerHTML = emptyState('No trip in progress right now.', 'Create Ride', 'dashboard.php?open=create-ride');
      return;
    }

    // Start polling for the first active ride. That poll writes into a
    // single #waitingStatusMonitor element, so only the first tile below
    // carries one — otherwise duplicate ids would collect the updates for
    // a trip they don't belong to.
    startWaitingPolling(rides[0].id);

    listContainer.innerHTML = rides.map((ride, index) => {
      const gps = gpsStatusLabel(ride.last_location_update);
      const distanceRemaining = haversineKm(
        ride.current_latitude, ride.current_longitude,
        ride.destination_lat, ride.destination_lng
      );
      // 40 km/h average — the same figure api/rideshare.php's search
      // estimate and includes/google-maps.php already use, so the ETA a
      // driver sees here matches the one quoted everywhere else.
      const etaMinutes = distanceRemaining !== null ? Math.round((distanceRemaining / 40) * 60) : null;
      return `
        <div class="card ride-tile anim-fade-in">
          <div class="u-flex u-justify-between u-items-center u-mb-2">
            <span class="trip-live-badge"><span class="trip-live-dot"></span>Live trip</span>
            <span class="status-pill ${gps.cls}">${escapeHtml(gps.text)}</span>
          </div>
          ${rideModeBadge(ride)}
          ${index === 0 ? '<div id="waitingStatusMonitor"></div>' : ''}
          <div class="ride-tile__dest u-mb-2"><i class="bi bi-record-circle" style="color:var(--color-primary)"></i>${escapeHtml(ride.start_location)}</div>
          <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
          <div class="u-text-sm u-mt-2" style="font-weight:700;color:var(--color-primary);">${escapeHtml(formatTripStateLabel(ride.trip_state, 'driver'))}</div>
          <div class="stat-tile-row u-mt-3">
            <div class="stat-tile"><div class="stat-tile__value">${ride.passengers_joined}</div><div class="stat-tile__label">Passengers</div></div>
            <div class="stat-tile"><div class="stat-tile__value">${ride.available_seats}</div><div class="stat-tile__label">Seats left</div></div>
            <div class="stat-tile"><div class="stat-tile__value">${etaMinutes !== null ? etaMinutes + 'm' : '—'}</div><div class="stat-tile__label">ETA</div></div>
          </div>
          <div class="u-flex u-justify-between u-mt-3 u-text-xs u-text-soft">
            <span>${escapeHtml(ride.vehicle_type || '')} ${escapeHtml((ride.vehicle_info || '').split(';')[0] || '')}</span>
            <span>${distanceRemaining !== null ? distanceRemaining.toFixed(1) + ' km left' : ''}</span>
          </div>
          <div class="u-text-xs u-text-faint u-mt-2"><i class="bi bi-wallet2"></i> ${escapeHtml(reservationLabel(ride))}</div>
          <div class="ride-tile__actions">
            <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${ride.id}">Live Map</button>
            <button type="button" class="btn btn-outline btn-sm" data-open-passengers="${ride.id}">Passengers</button>
            <button type="button" class="btn btn-primary btn-sm" data-end-trip="${ride.id}">End Trip</button>
          </div>
        </div>`;
    }).join('');
  }

  // ---------------------------------------------------------------
  // Upcoming
  // ---------------------------------------------------------------
  function renderUpcoming(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('No upcoming trips scheduled.', 'Create Ride', 'dashboard.php?open=create-ride');
      return;
    }
    listContainer.innerHTML = rides.map((ride) => {
      const expectedEarning = (Number(ride.price_per_passenger) * ride.passengers_joined).toFixed(2);
      return `
        <div class="card ride-tile anim-fade-in">
          <div class="ride-tile__top">
            <div>
              <div class="ride-tile__time">${escapeHtml(ride.departure_date)} · ${formatTime12h(ride.departure_time)}</div>
              <div class="ride-tile__from">${escapeHtml(ride.start_location)}</div>
            </div>
            <span class="status-pill ${ride.status}">${escapeHtml(ride.status)}</span>${rideModeBadge(ride)}
          </div>
          <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
          <div class="stat-tile-row u-mt-3">
            <div class="stat-tile"><div class="stat-tile__value">${ride.passengers_joined}</div><div class="stat-tile__label">Passengers</div></div>
            <div class="stat-tile"><div class="stat-tile__value">${formatNaira(expectedEarning)}</div><div class="stat-tile__label">Expected</div></div>
            <div class="stat-tile"><div class="stat-tile__value">${formatNaira(ride.reserved_amount || 0)}</div><div class="stat-tile__label">Reserved</div></div>
          </div>
          <div class="u-text-xs u-text-soft u-mt-2">${escapeHtml(ride.vehicle_type || '')} ${escapeHtml((ride.vehicle_info || '').split(';')[0] || '')}</div>
          <div class="ride-tile__actions">
            <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${ride.id}">Manage</button>
            <button type="button" class="btn btn-outline btn-sm" data-edit-trip="${ride.id}">Edit</button>
            <button type="button" class="btn btn-outline btn-sm" data-cancel-trip="${ride.id}" style="color:var(--color-danger);border-color:var(--color-danger);">Cancel</button>
          </div>
          <button type="button" class="btn btn-primary u-mt-3" data-start-trip="${ride.id}"><i class="bi bi-play-circle"></i> Start Trip</button>
        </div>`;
    }).join('');
  }

  // ---------------------------------------------------------------
  // Completed
  // ---------------------------------------------------------------
  function renderCompleted(rides) {
    if (!rides.length) {
      listContainer.innerHTML = emptyState('No completed trips yet.', null, null);
      return;
    }
    listContainer.innerHTML = rides.map((ride) => `
      <div class="card ride-tile anim-fade-in">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(ride.departure_date)}</div>
            <div class="ride-tile__from">${escapeHtml(ride.start_location)}</div>
          </div>
          <span class="status-pill ${ride.payment_status === 'settled' ? 'approved' : 'pending'}">${ride.payment_status === 'settled' ? 'Settled' : 'Partial'}</span>${rideModeBadge(ride)}
        </div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
        <div class="trip-financial-row"><span class="trip-financial-row__label">Passenger Revenue</span><span class="trip-financial-row__value">${formatNaira(ride.gross_revenue)}</span></div>
        <div class="trip-financial-row"><span class="trip-financial-row__label">MohToh Commission</span><span class="trip-financial-row__value is-negative">-${formatNaira(ride.total_commission)}</span></div>
        <div class="trip-financial-row is-total"><span class="trip-financial-row__label">Driver Earnings</span><span class="trip-financial-row__value is-positive">${formatNaira(ride.earning)}</span></div>
        ${ride.average_rating ? `<div class="u-mt-2"><span class="rating-chip"><i class="bi bi-star-fill"></i> ${Number(ride.average_rating).toFixed(1)} (${ride.rating_count})</span></div>` : '<div class="u-text-xs u-text-faint u-mt-2">Not rated yet</div>'}
        <div class="ride-tile__actions">
          <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${ride.id}">View details</button>
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
    listContainer.innerHTML = rides.map((ride) => `
      <div class="card ride-tile anim-fade-in">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(ride.departure_date)}</div>
            <div class="ride-tile__from">${escapeHtml(ride.start_location)}</div>
          </div>
          <span class="status-pill rejected">Cancelled</span>${rideModeBadge(ride)}
        </div>
        <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
        <div class="ride-tile__actions">
          <button type="button" class="btn btn-outline btn-sm" data-open-trip-detail="${ride.id}">View details</button>
        </div>
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

  // ---------------------------------------------------------------
  // Trip detail sheet — map + timeline + financials, from the new
  // (read-only) get_trip_detail action.
  // ---------------------------------------------------------------
  const TIMELINE_STEP_LABELS = {
    CREATED: 'Ride created', PUBLISHED: 'Ride published', PASSENGERS_JOINING: 'Passenger joined',
    PASSENGERS_ASSEMBLED: 'Pickup reached', TRIP_STARTED: 'Trip started', PASSENGER_DROPPING: 'Passenger dropping',
    AUTO_COMPLETED_PENDING: 'Awaiting confirmation', TRIP_COMPLETED: 'Completed', CANCELLED: 'Cancelled',
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

  // Google Maps mini-map (provider detail lives in assets/js/map.js —
  // mtRenderMiniMap draws the real road route + pickup/destination markers
  // and drops the driver's current position). Async: the map appears once
  // the Google Maps API finishes loading; the sheet's timeline/financials
  // render immediately regardless.
  async function renderMiniMap(ride) {
    const mapEl = qs('#tripDetailMap');
    if (!mapEl || typeof mtRenderMiniMap !== 'function') return;
    await mtRenderMiniMap(mapEl, {
      pickup: { lat: ride.pickup_lat, lng: ride.pickup_lng },
      destination: { lat: ride.destination_lat, lng: ride.destination_lng },
      driver: (ride.current_latitude != null && ride.current_longitude != null)
        ? { lat: ride.current_latitude, lng: ride.current_longitude } : null,
      driverLabel: 'Driver (you)',
      drawRoute: true,
    });
  }

  async function openTripDetail(rideId) {
    const sheet = qs('#tripDetailSheet');
    const content = qs('#tripDetailContent');
    content.innerHTML = '<div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:20px;height:20px;display:inline-block;"></span></div>';
    openSheet(sheet, scrim);

    try {
      const data = await apiGet('../api/rideshare.php', { action: 'get_trip_detail', ride_id: rideId });
      if (data.status !== 'success') { content.innerHTML = `<div class="alert-banner error">${escapeHtml(data.message)}</div>`; return; }

      const { ride, financials, timeline } = data.data;
      content.innerHTML = `
        <div class="trip-mini-map" id="tripDetailMap"></div>
        <div class="u-text-sm" style="font-weight:700;margin-bottom:var(--space-2);">${escapeHtml(formatTripStateLabel(ride.trip_state, 'driver'))}</div>
        <div class="section-label">Trip timeline</div>
        ${renderTimeline(timeline)}
        <div class="section-label u-mt-4">Financial summary</div>
        <div class="trip-financial-row"><span class="trip-financial-row__label">Fare collected</span><span class="trip-financial-row__value">${formatNaira(financials.fare_collected)}</span></div>
        <div class="trip-financial-row"><span class="trip-financial-row__label">Commission</span><span class="trip-financial-row__value is-negative">-${formatNaira(financials.commission)}</span></div>
        ${financials.reservation ? `
          <div class="trip-financial-row"><span class="trip-financial-row__label">Wallet reservation</span><span class="trip-financial-row__value">${formatNaira(financials.reservation.reserved_amount)}</span></div>
          <div class="trip-financial-row"><span class="trip-financial-row__label">Released amount</span><span class="trip-financial-row__value">${formatNaira(financials.reservation.released_amount)}</span></div>
        ` : ''}
        <div class="trip-financial-row is-total"><span class="trip-financial-row__label">Final earnings</span><span class="trip-financial-row__value is-positive">${formatNaira(financials.driver_earning)}</span></div>
      `;
      renderMiniMap(ride);
    } catch (error) {
      content.innerHTML = '<div class="alert-banner error">Could not load trip details.</div>';
    }
  }

  // ---------------------------------------------------------------
  // Contact / view passengers — reuses api/rideshare.php's existing
  // get_passengers (same data driver/dashboard.php's Passengers sheet
  // already shows); this is a lighter, contact-focused rendering of it.
  // ---------------------------------------------------------------
  async function openContactPassengers(rideId) {
    const sheet = qs('#contactPassengerSheet');
    const list = qs('#contactPassengerList');
    list.innerHTML = '<div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:20px;height:20px;display:inline-block;"></span></div>';
    openSheet(sheet, scrim);
    try {
      const data = await apiGet('../api/rideshare.php', { action: 'get_passengers', ride_id: rideId });
      const passengers = data?.data?.passengers || [];
      list.innerHTML = passengers.length ? passengers.map((p) => `
        <div class="menu-row" style="cursor:default;">
          <i class="bi bi-person-circle"></i>
          <span style="flex:1;">
            <div style="font-weight:600;">${escapeHtml(p.fullname)}</div>
            <div class="u-text-xs u-text-faint">${escapeHtml(formatPassengerStateLabel(p.passenger_state))}</div>
          </span>
          <a href="tel:${escapeHtml(p.phone || '')}" class="btn btn-outline btn-sm" style="width:auto;padding-inline:14px;"><i class="bi bi-telephone"></i> Call</a>
        </div>
      `).join('') : '<div class="u-text-center u-text-faint u-text-sm" style="padding:24px 0;">No passengers on this trip.</div>';
    } catch (error) {
      list.innerHTML = '<div class="alert-banner error">Could not load passengers.</div>';
    }
  }

  // ---------------------------------------------------------------
  // Actions: end trip, start trip, cancel trip, edit trip
  // ---------------------------------------------------------------
  document.addEventListener('click', async (e) => {
    const detailBtn = e.target.closest('[data-open-trip-detail]');
    if (detailBtn) { openTripDetail(detailBtn.dataset.openTripDetail); return; }

    const passengersBtn = e.target.closest('[data-open-passengers]');
    if (passengersBtn) { openContactPassengers(passengersBtn.dataset.openPassengers); return; }

    const endBtn = e.target.closest('[data-end-trip]');
    if (endBtn) {
      if (!confirm('End this trip now? This settles wallet-paid earnings.')) return;
      setButtonLoading(endBtn, true, 'Ending…');
      try {
        const data = await apiPost('../api/rideshare.php', { action: 'complete_ride', ride_id: Number(endBtn.dataset.endTrip) });
        showToast(data.message || (data.status === 'success' ? 'Trip completed' : 'Could not end trip'));
        if (data.status === 'success') loadAll();
      } catch (error) { showToast('Something went wrong.'); }
      finally { setButtonLoading(endBtn, false); }
      return;
    }

    const startBtn = e.target.closest('[data-start-trip]');
    if (startBtn) {
      setButtonLoading(startBtn, true, 'Starting…');
      try {
        const data = await apiPost('../api/rideshare.php', { action: 'start_ride', ride_id: Number(startBtn.dataset.startTrip) });
        showToast(data.message || (data.status === 'success' ? 'Trip started' : 'Could not start trip'));
        if (data.status === 'success') loadAll();
      } catch (error) { showToast('Something went wrong.'); }
      finally { setButtonLoading(startBtn, false); }
      return;
    }

    const cancelBtn = e.target.closest('[data-cancel-trip]');
    if (cancelBtn) {
      if (!confirm('Cancel this trip? All passengers will be notified and refunded where applicable.')) return;
      setButtonLoading(cancelBtn, true, 'Cancelling…');
      try {
        const data = await apiPost('../api/rideshare.php', { action: 'cancel', ride_id: Number(cancelBtn.dataset.cancelTrip) });
        showToast(data.message || (data.status === 'success' ? 'Trip cancelled' : 'Could not cancel trip'));
        if (data.status === 'success') loadAll();
      } catch (error) { showToast('Something went wrong.'); }
      finally { setButtonLoading(cancelBtn, false); }
      return;
    }

    const editBtn = e.target.closest('[data-edit-trip]');
    if (editBtn) {
      const ride = liveTrips.find((r) => String(r.id) === editBtn.dataset.editTrip);
      if (!ride) return;
      qs('#editTripRideId').value = ride.id;
      qs('#editTripPickup').value = ride.start_location;
      qs('#editTripPickupLat').value = ride.pickup_lat;
      qs('#editTripPickupLng').value = ride.pickup_lng;
      qs('#editTripDestination').value = ride.destination;
      qs('#editTripDestinationLat').value = ride.destination_lat;
      qs('#editTripDestinationLng').value = ride.destination_lng;
      qs('#editTripDate').value = ride.departure_date;
      qs('#editTripTime').value = ride.departure_time;
      qs('#editTripPrice').value = ride.price_per_passenger;
      openSheet(qs('#editTripSheet'), scrim);
    }
  });

  initAddressAutocomplete(qs('#editTripPickup'), qs('#editTripPickupLat'), qs('#editTripPickupLng'));
  initAddressAutocomplete(qs('#editTripDestination'), qs('#editTripDestinationLat'), qs('#editTripDestinationLng'));

  qs('#editTripForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertEl = qs('#editTripAlert');
    hideAlert(alertEl);
    const payload = {
      action: 'update',
      ride_id: Number(qs('#editTripRideId').value),
      pickup_location: qs('#editTripPickup').value,
      pickup_lat: qs('#editTripPickupLat').value,
      pickup_lng: qs('#editTripPickupLng').value,
      destination: qs('#editTripDestination').value,
      destination_lat: qs('#editTripDestinationLat').value,
      destination_lng: qs('#editTripDestinationLng').value,
      departure_date: qs('#editTripDate').value,
      departure_time: qs('#editTripTime').value,
      price_per_passenger: qs('#editTripPrice').value,
    };
    const btn = qs('#editTripSubmitBtn');
    setButtonLoading(btn, true, 'Saving…');
    try {
      const data = await apiPost('../api/rideshare.php', payload);
      if (data.status === 'success') {
        showToast('Trip updated');
        closeSheet(qs('#editTripSheet'), scrim);
        loadAll();
      } else {
        showAlert(alertEl, 'error', data.message || 'Could not update trip.');
      }
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  loadAll();
  // Active trips move fast (GPS-driven) — refresh every 20s so the
  // live card's ETA/GPS-status/passenger count stay current without
  // needing a manual pull-to-refresh gesture this shell doesn't have.
  setInterval(() => { if (activeTabKey === 'active') loadAll(); }, 20000);
});

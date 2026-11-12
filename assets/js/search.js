// assets/js/search.js
// Rider Home screen behavior. Every endpoint call, payload shape and
// localStorage key here is a direct port of the previous inline script
// in rider/dashboard.php — only the DOM target markup and the
// modal->bottom-sheet mechanics changed.

document.addEventListener('DOMContentLoaded', () => {
  const searchForm = qs('#searchRidesForm');
  const resultsList = qs('#rideResultsList');
  const resultsTitle = qs('#rideResultsTitle');
  const resultsAlert = qs('#rideResultsAlert');
  const recentSearchesEl = qs('#recentSearches');

  const detailSheet = qs('#rideDetailSheet');
  const detailScrim = qs('[data-sheet-scrim]');
  const detailContent = qs('#rideDetailContent');
  const detailFooter = qs('#rideDetailFooter');

  const ratingSheet = qs('#ratingSheet');

  let bookingMade = false;
  // Which method the current ride-detail booking will submit under —
  // set in renderRideDetail() from the ride's payment_preference, and
  // updated live if the rider picks from the "both" toggle there.
  let selectedPaymentMethod = 'wallet';

  // ---- Rendering ----
  const PRICING_MODE_BADGE_LABEL = { system: 'System Price', driver: 'Driver Price', hybrid: 'Hybrid Price' };

  // When several rides exist for the same route, ordering by price alone
  // would push a cheap-but-poorly-rated driver above a well-rated one a
  // few naira more — so this blends price competitiveness (lower is
  // better, within THIS result set only) with driver rating into one
  // score. Purely a display-order choice; nothing here changes what a
  // rider actually pays.
  function rankRides(rides) {
    // Ride Queue: full rides (available_seats = 0) now come back from
    // api/rideshare.php's search action too — they were previously
    // excluded entirely. They can't be scored on price competitiveness
    // (nothing to book), so they're ranked separately and appended
    // after every bookable ride rather than folded into the same
    // price/rating comparison.
    const bookable = rides.filter((r) => Number(r.available_seats) > 0);
    const full = rides.filter((r) => Number(r.available_seats) <= 0).map((r) => ({ ...r, _full: true }));

    if (bookable.length < 2) {
      return [...bookable.map((r) => ({ ...r, _bestValue: bookable.length === 1 })), ...full];
    }
    const prices = bookable.map((r) => Number(r.price_per_passenger));
    const minPrice = Math.min(...prices);
    const maxPrice = Math.max(...prices);
    const priceSpread = maxPrice - minPrice || 1;

    const scored = bookable.map((ride) => {
      const price = Number(ride.price_per_passenger);
      const priceScore = 1 - (price - minPrice) / priceSpread; // 1 = cheapest, 0 = priciest
      const ratingScore = Math.min(5, Number(ride.driver_rating || 4)) / 5;
      return { ...ride, _score: priceScore * 0.6 + ratingScore * 0.4, _isCheapest: price === minPrice };
    });

    // Part 2 (Premium Driver Features) — "Priority Ride Visibility":
    // api/rideshare.php's search action already puts premium drivers
    // first; this sort must respect that same ordering (premium tier
    // ahead of the price/rating score) instead of re-shuffling it away.
    // Premium tiles get their own "Featured" badge (below), so
    // "Recommended" is reserved for the best-scored non-premium ride.
    scored.forEach((r) => { r._premium = r.driver_tier === 'premium'; });
    scored.sort((a, b) => (b._premium - a._premium) || (b._score - a._score));
    const bestNonPremium = scored.find((r) => !r._premium);
    if (bestNonPremium) bestNonPremium._recommended = true;
    return [...scored, ...full];
  }

  function renderRideResults(rides) {
    const routePopular = rides.length >= 3;
    const ranked = rankRides(rides);

    resultsList.innerHTML = ranked.map((ride) => {
      const driverImage = ride.driver_image && ride.driver_image !== 'default.png'
        ? `../img.php?f=${encodeURIComponent(ride.driver_image)}&w=160`
        : `https://ui-avatars.com/api/?name=${encodeURIComponent(ride.driver_name)}&background=0F8B8D&color=fff`;

      if (ride._full) {
        return `
          <button type="button" class="card ride-tile anim-fade-in" style="opacity:.85;" onclick="mtViewRideDetails(${ride.id})">
            <div class="ride-tile__top">
              <div>
                <div class="ride-tile__time">${formatTime12h(ride.departure_time)}</div>
                <div class="ride-tile__from">${escapeHtml(ride.start_location)}</div>
              </div>
              <span class="pricing-badge" style="background:var(--color-danger-100);color:var(--color-danger);"><i class="bi bi-slash-circle"></i> Full</span>
            </div>
            <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
            <div class="ride-tile__driver">
              <img src="${driverImage}" alt="">
              <div>
                <div class="ride-tile__driver-name">${escapeHtml(ride.driver_name)} <span class="rating-chip"><i class="bi bi-star-fill"></i> ${parseFloat(ride.driver_rating || 5).toFixed(1)}</span></div>
                <div class="ride-tile__driver-meta">₦${Number(ride.price_per_passenger).toLocaleString()}/seat · Ride full — tap to join ride queue</div>
              </div>
            </div>
          </button>
        `;
      }

      const badges = [];
      // Part 2 (Premium Driver Features) — "Featured Driver Profile".
      if (ride._premium) badges.push({ label: 'Featured', cls: 'is-accent', icon: 'bi-award-fill' });
      if (ride._recommended) badges.push({ label: 'Recommended', cls: 'is-value', icon: 'bi-star-fill' });
      if (ride._isCheapest && !ride._recommended) badges.push({ label: 'Best Value', cls: 'is-value', icon: 'bi-piggy-bank' });
      if (routePopular) badges.push({ label: 'Popular Route', cls: 'is-info', icon: 'bi-people' });
      const pricingLabel = PRICING_MODE_BADGE_LABEL[ride.pricing_mode] || 'Hybrid Price';

      return `
        <button type="button" class="card ride-tile anim-fade-in" onclick="mtViewRideDetails(${ride.id})">
          <div class="ride-tile__top">
            <div>
              <div class="ride-tile__time">${formatTime12h(ride.departure_time)}${ride.estimated_arrival_time ? ' → ' + formatTime12h(ride.estimated_arrival_time) : ''}</div>
              <div class="ride-tile__from">${escapeHtml(ride.start_location)}</div>
            </div>
            <div class="ride-tile__price">₦${Number(ride.price_per_passenger).toLocaleString()}<small>per seat</small></div>
          </div>
          <div class="ride-tile__dest"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger)"></i>${escapeHtml(ride.destination)}</div>
          <div class="u-flex u-gap-2 u-mb-2" style="flex-wrap:wrap;">
            <span class="pricing-badge is-info">${escapeHtml(pricingLabel)}</span>
            ${badges.map((b) => `<span class="pricing-badge ${b.cls}"><i class="bi ${b.icon}"></i>${escapeHtml(b.label)}</span>`).join('')}
          </div>
          <div class="ride-tile__driver">
            <img src="${driverImage}" alt="">
            <div>
              <div class="ride-tile__driver-name">${escapeHtml(ride.driver_name)} <span class="rating-chip"><i class="bi bi-star-fill"></i> ${parseFloat(ride.driver_rating || 5).toFixed(1)}</span></div>
              <div class="ride-tile__driver-meta">${escapeHtml(ride.vehicle_make || '')} ${escapeHtml(ride.vehicle_model || '')}</div>
            </div>
            <div class="ride-tile__seats"><strong>${ride.available_seats}</strong>seats left</div>
          </div>
        </button>
      `;
    }).join('');
  }

  async function loadRecommendedRides() {
    resultsTitle.textContent = 'Rides starting soon';
    resultsList.innerHTML = `<div class="u-text-center u-text-faint u-text-sm" style="padding:32px 0;">Finding rides near you…</div>`;
    resultsAlert.innerHTML = '';

    const fetchAndRender = async (coords) => {
      const data = await apiGet('../api/rideshare.php', { action: 'get_recommended_rides', lat: coords?.latitude, lng: coords?.longitude });
      if (data.status === 'success' && data.data.rides.length > 0) {
        renderRideResults(data.data.rides);
      } else if (data.status === 'success') {
        resultsList.innerHTML = `<div class="u-text-center u-text-faint u-text-sm" style="padding:32px 0;">No upcoming rides found nearby yet.</div>`;
      } else {
        resultsAlert.innerHTML = `<div class="alert-banner error">${escapeHtml(data.message || 'Could not load nearby rides.')}</div>`;
      }
    };

    if (window.MohToh && window.MohToh.location) {
      window.MohToh.location.getCurrentLocation()
        .then((position) => fetchAndRender(position))
        .catch(() => fetchAndRender(null));
    } else {
      fetchAndRender(null);
    }
  }

  // ---- Search ----
  searchForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(searchForm);
    const payload = Object.fromEntries(formData.entries());
    saveRecentSearch(payload.destination);
    payload.action = 'search';

    resultsList.innerHTML = `<div class="u-text-center u-text-faint u-text-sm" style="padding:32px 0;">Searching…</div>`;
    resultsAlert.innerHTML = '';

    const data = await apiPost('../api/rideshare.php', payload);

    if (data.status === 'success' && data.data.rides.length > 0) {
      resultsTitle.textContent = 'Search results';
      renderRideResults(data.data.rides);
    } else if (data.status === 'success') {
      resultsList.innerHTML = '';
      resultsAlert.innerHTML = `<div class="alert-banner error">No rides found for that route yet. Try a different date.</div>`;
    } else {
      resultsList.innerHTML = '';
      resultsAlert.innerHTML = `<div class="alert-banner error">${escapeHtml(data.message || 'Something went wrong while searching.')}</div>`;
    }
  });

  // ---- Ride details sheet ----
  window.mtViewRideDetails = async (rideId) => {
    detailContent.innerHTML = `<div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:22px;height:22px;display:inline-block;"></span></div>`;
    detailFooter.innerHTML = '';
    openSheet(detailSheet, detailScrim);

    const data = await apiGet('../api/rideshare.php', { action: 'details', ride_id: rideId });
    if (data.status === 'success') {
      renderRideDetail(data.data.ride);
    } else {
      detailContent.innerHTML = `<div class="alert-banner error">${escapeHtml(data.message || 'Could not load ride details.')}</div>`;
    }
  };

  function renderRideDetail(ride) {
    const driver = {
      name: ride.driver_name,
      profile_image: ride.driver_photo,
      rating: ride.driver_rating,
      completed_trips: ride.completed_trips,
      is_verified: !!ride.driver_verified
    };

    // What the rider will actually be able to pay with, straight from the
    // ride's configured payment_preference (never hard-coded). 'both' means
    // the rider chooses below; the single-method rides state it plainly.
    const paymentLabel = ride.payment_preference === 'direct'
      ? 'Direct / Cash to driver'
      : ride.payment_preference === 'both'
        ? 'Wallet or Direct / Cash — your choice'
        : 'Wallet';

    detailContent.innerHTML = `
      ${TransparencyUI.renderDriverProfileCard(driver)}

      <div class="card p-3 mb-3">
        <h6 class="mb-2">Vehicle Information</h6>
        <div class="u-text-sm u-text-soft u-mb-2">${escapeHtml(ride.vehicle_make)} ${escapeHtml(ride.vehicle_model)} · ${escapeHtml(ride.vehicle_color)}</div>
        <div class="u-text-sm u-text-soft u-mb-2">Plate: ${escapeHtml(ride.vehicle_plate.replace(/(?<=.{3}).(?=.{2})/, '***'))} · Cap: ${ride.passenger_capacity}</div>
        ${TransparencyUI.renderSafetyBadge(ride.verification_status === 'verified', 'Vehicle')}
        <div class="mt-3">
            ${TransparencyUI.renderVehicleGallery(ride.vehicle_photos)}
        </div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="mb-2">Trip Information</h6>
        ${TransparencyUI.renderRideModeBadge(ride.ride_mode)}
        <div class="u-text-sm"><strong>Route:</strong> ${escapeHtml(ride.start_location)} → ${escapeHtml(ride.destination)}</div>
        <div class="u-text-sm"><strong>Seats available:</strong> ${ride.available_seats}</div>
        <div class="u-text-sm"><strong>Fare:</strong> ₦${Number(ride.price_per_passenger).toLocaleString()}</div>
        <div class="u-text-sm"><strong>Payment:</strong> ${paymentLabel}</div>
      </div>
    `;

    // Selected payment method for this booking — 'wallet' unless the
    // rider picks otherwise from the "both" toggle above. Rides that
    // only accept one method use that fixed value (no choice to make).
    selectedPaymentMethod = ride.payment_preference === 'direct' ? 'direct' : 'wallet';

    if (ride.already_joined) {
      detailFooter.innerHTML = `<button class="btn btn-primary" disabled><i class="bi bi-check-circle-fill"></i> Already joined</button>`;
    } else if (ride.remaining_seats > 0 && ride.status === 'active') {
      const total = (1 * parseFloat(ride.price_per_passenger)).toFixed(2);
      // Only "both" rides give the rider a choice; single-method rides use
      // their fixed method (selectedPaymentMethod set above) with no toggle.
      const paymentChoiceMarkup = ride.payment_preference === 'both' ? `
        <div class="u-mb-3">
          <div class="u-text-xs u-text-soft u-mb-2">How would you like to pay?</div>
          <div id="paymentMethodChoice" class="u-flex u-gap-2">
            <button type="button" class="btn btn-outline btn-sm is-active" data-pay-method="wallet" style="flex:1;"><i class="bi bi-wallet2"></i> Wallet</button>
            <button type="button" class="btn btn-outline btn-sm" data-pay-method="direct" style="flex:1;"><i class="bi bi-cash-coin"></i> Direct / Cash</button>
          </div>
        </div>` : '';
      detailFooter.innerHTML = `
        ${paymentChoiceMarkup}
        <div class="u-flex u-gap-3 u-items-center">
          <div class="field" style="width:96px;padding:8px 10px;">
            <input type="number" id="seatsToBook" value="1" min="1" max="${ride.remaining_seats}">
          </div>
          <button class="btn btn-primary" style="flex:1;" onclick="mtBookRide(${ride.id})">
            Join · ₦<span id="totalFare">${Number(total).toLocaleString()}</span>
          </button>
        </div>`;
      const seatsInput = qs('#seatsToBook');
      seatsInput?.addEventListener('input', () => {
        const seats = parseInt(seatsInput.value, 10) || 1;
        qs('#totalFare').textContent = Number((seats * parseFloat(ride.price_per_passenger)).toFixed(2)).toLocaleString();
      });
      qs('#paymentMethodChoice')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-pay-method]');
        if (!btn) return;
        selectedPaymentMethod = btn.dataset.payMethod;
        qsa('#paymentMethodChoice [data-pay-method]').forEach((el) => el.classList.toggle('is-active', el === btn));
      });
    } else if (ride.queue_entry && ride.queue_entry.status === 'NOTIFIED') {
      detailFooter.innerHTML = `
        <div class="alert-banner success u-mb-3"><i class="bi bi-bell-fill"></i> A seat opened up for you! Book now before it's gone.</div>
        <button class="btn btn-primary" onclick="mtBookFromQueue('${ride.queue_entry.public_id}')">
          <i class="bi bi-lightning-fill"></i> Book Now
        </button>`;
    } else if (ride.queue_entry) {
      detailFooter.innerHTML = `
        <div class="alert-banner u-mb-3" style="background:var(--color-primary-50);color:var(--color-text);"><i class="bi bi-hourglass-split"></i> You're in the ride queue for this ride. We'll notify you the moment a seat opens up.</div>
        <button class="btn btn-outline" onclick="mtLeaveQueue('${ride.queue_entry.public_id}')">Leave ride queue</button>`;
    } else {
      detailFooter.innerHTML = `
        <div class="alert-banner error u-mb-3"><i class="bi bi-slash-circle"></i> This ride is full — join the ride queue to be notified if a seat opens up.</div>
        <button class="btn btn-primary" onclick="mtJoinQueue(${ride.id})"><i class="bi bi-person-add"></i> Join Ride Queue</button>`;
    }
  }

  window.mtJoinQueue = async (rideId) => {
    // apiPost never throws (network/timeout/parse failures come back as
    // a normal {status:'error', message} instead) — no try/catch needed.
    const btn = qs('#rideDetailFooter .btn-primary');
    setButtonLoading(btn, true, 'Joining…');
    const res = await apiPost('../api/ride-queue.php', { action: 'join', ride_share_id: rideId, seats_requested: 1 });
    showToast(res.message || (res.status === 'success' ? 'Joined the ride queue.' : 'Could not join the ride queue.'));
    if (res.status === 'success') {
      mtViewRideDetails(rideId);
    } else {
      setButtonLoading(btn, false);
    }
  };

  window.mtLeaveQueue = async (queuePublicId) => {
    const btn = qs('#rideDetailFooter .btn-outline');
    setButtonLoading(btn, true, 'Leaving…');
    const res = await apiPost('../api/ride-queue.php', { action: 'leave', queue_public_id: queuePublicId });
    showToast(res.message || 'Removed from ride queue.');
    if (res.status === 'success') {
      closeSheet(detailSheet, detailScrim);
    } else {
      setButtonLoading(btn, false);
    }
  };

  window.mtBookFromQueue = async (queuePublicId) => {
    const btn = qs('#rideDetailFooter .btn-primary');
    setButtonLoading(btn, true, 'Booking…');
    const res = await apiPost('../api/ride-queue.php', { action: 'book', queue_public_id: queuePublicId, payment_method: selectedPaymentMethod });
    if (res.status === 'success') {
      detailContent.innerHTML = `<div class="alert-banner success"><strong>You're in!</strong> Your seat is booked.</div>`;
      detailFooter.innerHTML = `<button type="button" class="btn btn-outline" data-close-sheet>Done</button>`;
      bookingMade = true;
    } else {
      showToast(res.message || 'Could not complete booking.');
      setButtonLoading(btn, false);
    }
  };

  window.mtBookRide = async (rideId) => {
    const seatsInput = qs('#seatsToBook');
    const seats_booked = parseInt(seatsInput?.value, 10);
    if (!seats_booked || seats_booked < 1) { showToast('Enter a valid number of seats'); return; }

    const bookBtn = qs('#rideDetailFooter .btn-primary');
    setButtonLoading(bookBtn, true, 'Booking…');

    const data = await apiPost('../api/rideshare.php', { action: 'book', ride_share_id: rideId, seats_booked, payment_method: selectedPaymentMethod });

    if (data.status === 'success') {
      detailContent.innerHTML = `<div class="alert-banner success"><strong>You're in!</strong> Your seat is booked.</div>`;
      detailFooter.innerHTML = `<button type="button" class="btn btn-outline" data-close-sheet>Done</button>`;
      bookingMade = true;
    } else {
      showToast(`Booking failed: ${data.message}`);
      setButtonLoading(bookBtn, false);
    }
  };

  // Sheet close wiring local to this screen's dynamically-injected buttons
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-close-sheet]')) {
      closeSheet(detailSheet, detailScrim);
      closeSheet(ratingSheet, detailScrim);
      if (bookingMade) { loadRecommendedRides(); bookingMade = false; }
    }
  });

  // ---- Recent searches (localStorage — unchanged key/shape) ----
  function saveRecentSearch(destination) {
    if (!destination) return;
    let searches = JSON.parse(localStorage.getItem('recentSearches')) || [];
    searches = searches.filter((s) => s.toLowerCase() !== destination.toLowerCase());
    searches.unshift(destination);
    searches = searches.slice(0, 5);
    localStorage.setItem('recentSearches', JSON.stringify(searches));
    renderRecentSearches(searches);
  }

  function renderRecentSearches(searches) {
    if (!recentSearchesEl) return;
    recentSearchesEl.innerHTML = searches.length
      ? searches.map((s) => `<button type="button" class="btn btn-sm btn-outline" onclick="mtQuickSearch('${escapeHtml(s)}')">${escapeHtml(s)}</button>`).join('')
      : '';
  }

  window.mtQuickSearch = (destination) => {
    qs('#destinationLocation').value = destination;
    // Recent-search chips carry text only — drop any coordinates left over
    // from a place the rider picked earlier.
    const dLat = qs('#searchDestinationLat');
    const dLng = qs('#searchDestinationLng');
    if (dLat) dLat.value = '';
    if (dLng) dLng.value = '';
    searchForm.dispatchEvent(new Event('submit', { cancelable: true }));
  };

  // ---- "Use my location" pickup field (Google reverse geocode, Nominatim fallback) ----
  const useLocationBtn = qs('#useLocationBtn');
  const pickupInput = qs('#pickupLocation');
  const destinationInput = qs('#destinationLocation');
  const pickupLat = qs('#searchPickupLat');
  const pickupLng = qs('#searchPickupLng');
  const destinationLat = qs('#searchDestinationLat');
  const destinationLng = qs('#searchDestinationLng');

  useLocationBtn?.addEventListener('click', () => {
    if (!window.MohToh || !window.MohToh.location) { showToast('Geolocation is not supported by your browser.'); return; }
    useLocationBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
    useLocationBtn.disabled = true;
    window.MohToh.location.getCurrentLocation().then(async (position) => {
      const { latitude, longitude } = position;
      const geo = await mtReverseGeocode(latitude, longitude);
      pickupInput.value = (geo && geo.formatted_address) || `Lat: ${latitude.toFixed(4)}, Lng: ${longitude.toFixed(4)}`;
      // The GPS fix is the authoritative pickup point — keep it, don't just
      // keep the label the reverse geocode came back with.
      if (pickupLat) pickupLat.value = (geo && geo.latitude) ?? latitude;
      if (pickupLng) pickupLng.value = (geo && geo.longitude) ?? longitude;
      useLocationBtn.innerHTML = '<i class="bi bi-crosshair"></i>';
      useLocationBtn.disabled = false;
    }, (error) => {
      showToast(`Could not get your location: ${error.message}`);
      useLocationBtn.innerHTML = '<i class="bi bi-crosshair"></i>';
      useLocationBtn.disabled = false;
    });
  });

  // ---- Intelligent location search suggestions on the search-card fields.
  // api/rideshare.php?action=search still matches on the place *text* exactly
  // as before — the algorithm is untouched. The coordinates a picked
  // suggestion resolves to are captured into the hidden inputs alongside it,
  // so the selected place is a real point and not just a string. Typing and
  // submitting without picking a suggestion still works as before, with the
  // coordinates simply left empty.
  initAddressAutocomplete(pickupInput, pickupLat, pickupLng);
  initAddressAutocomplete(destinationInput, destinationLat, destinationLng);

  // Hand-editing the text after picking a place invalidates the coordinates
  // that came with it — clear them rather than submit a mismatched pair.
  const clearCoordsOnEdit = (input, latEl, lngEl) => {
    input?.addEventListener('input', () => {
      if (latEl) latEl.value = '';
      if (lngEl) lngEl.value = '';
    });
  };
  clearCoordsOnEdit(pickupInput, pickupLat, pickupLng);
  clearCoordsOnEdit(destinationInput, destinationLat, destinationLng);

  // ---- Bottom-nav center button: jump straight to the pickup field ----
  document.addEventListener('mohtoh:center-action', (e) => {
    if (e.detail.action === 'focus-search') {
      qs('#pickupLocation')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      qs('#pickupLocation')?.focus();
    }
  });

  // ---- Rating sheet (used from ride results after a completed trip is rated elsewhere too) ----
  qsa('.rating-stars i', ratingSheet).forEach((star) => {
    star.addEventListener('click', () => {
      const value = star.dataset.value;
      qs('#ratingValue').value = value;
      qsa('.rating-stars i', ratingSheet).forEach((s) => s.classList.toggle('is-filled', s.dataset.value <= value));
    });
  });

  qs('#ratingForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const payload = Object.fromEntries(formData.entries());
    payload.action = 'rate_ride';
    if (!payload.rating) { showToast('Please select a star rating.'); return; }

    const data = await apiPost('../api/rideshare.php', payload);
    showToast(data.message || (data.status === 'success' ? 'Rating submitted.' : 'An error occurred while submitting your rating.'));
    if (data.status === 'success') closeSheet(ratingSheet, detailScrim);
  });

  // ---- Init ----
  const dateInput = qs('#departureDate');
  if (dateInput) dateInput.valueAsDate = new Date();
  loadRecommendedRides();
  renderRecentSearches(JSON.parse(localStorage.getItem('recentSearches')) || []);
});

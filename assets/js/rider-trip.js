// assets/js/rider-trip.js
// Intelligent Trip Engine — Phase 3 (Collaborative Driver & Passenger
// Intelligence), rider side. Rider Home previously had no visibility
// into trip_state at all — this adds the passenger half of the engine
// without touching the existing search/booking flow (assets/js/search.js)
// or any existing API contract: it only reads api/trip-collaboration.php
// (new) and reuses api/update-location.php's existing POST contract
// (now also handling rider pings server-side).

document.addEventListener('DOMContentLoaded', () => {
  const liveTripCard = qs('#liveTripCard');
  const liveTripContent = qs('#liveTripContent');
  let currentBookingId = null;
  let trackingStarted = false;
  let pollTimer = null;

  function startPassengerLocationTracking() {
    if (trackingStarted || !window.MohToh || !window.MohToh.location) return;
    trackingStarted = true;
    window.MohToh.location.watchLocation(
      async (position) => {
        const { latitude, longitude, accuracy } = position;
        try {
          await apiPost('../api/update-location.php', { latitude, longitude, accuracy });
        } catch (error) { /* a failed passenger location ping is silent, same as the driver side */ }
      },
      () => { /* GPS unavailable/denied — manual confirmation in the live trip sheet still works */ },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 }
    );
  }

  function renderLiveTripSheet(trip) {
    if (!liveTripContent) return;
    if (!trip) {
      liveTripContent.innerHTML = '<div class="u-text-center u-text-faint u-text-sm" style="padding:24px 0;">No active trip right now.</div>';
      return;
    }

    const stateActions = {
      booked: { label: 'Confirm arrival', action: 'confirm_arrival' },
      navigating: { label: 'Confirm arrival', action: 'confirm_arrival' },
      arrived: { label: 'Confirm boarded', action: 'confirm_boarded' },
      boarded: { label: 'Confirm dropped off', action: 'confirm_dropped_off' },
    };
    const nextAction = stateActions[trip.passenger_state];

    liveTripContent.innerHTML = `
      <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
        <div style="font-weight:700;font-family:var(--font-display);">${escapeHtml(trip.driver_name || 'Your driver')}</div>
        <div class="u-text-sm u-text-soft">${escapeHtml(trip.make || '')} ${escapeHtml(trip.model || '')} ${trip.plate_number ? '· ' + escapeHtml(trip.plate_number) : ''}</div>
        <div class="u-mt-3"><span class="rating-chip"><i class="bi bi-star-fill"></i> ${trip.driver_rating ? Number(trip.driver_rating).toFixed(1) : '—'}</span></div>
      </div>
      <div class="info-row"><span class="info-row__label">Trip status</span><span class="info-row__value">${escapeHtml(formatTripStateLabel(trip.trip_state, 'rider'))}</span></div>
      <div class="info-row"><span class="info-row__label">Your status</span><span class="info-row__value">${escapeHtml(formatPassengerStateLabel(trip.passenger_state))}</span></div>
      <div class="info-row"><span class="info-row__label">Destination</span><span class="info-row__value">${escapeHtml(trip.destination || '—')}</span></div>
      ${trip.passenger_state === 'booked' ? `<button type="button" class="btn btn-outline u-mt-4" id="setNavigatingBtn">I'm on my way</button>` : ''}
      ${nextAction ? `<button type="button" class="btn btn-primary u-mt-4" id="passengerActionBtn" data-action="${nextAction.action}">${nextAction.label}</button>` : ''}
      <p class="u-text-xs u-text-faint u-mt-4">MohToh automatically detects your arrival, boarding, and drop-off using GPS. Use these buttons only if it hasn't updated on its own.</p>
    `;

    qs('#setNavigatingBtn')?.addEventListener('click', () => runPassengerAction('set_navigating'));
    qs('#passengerActionBtn')?.addEventListener('click', () => runPassengerAction(nextAction.action));
  }

  async function runPassengerAction(action) {
    if (!currentBookingId) return;
    try {
      const data = await apiPost('../api/trip-collaboration.php', { action, booking_id: currentBookingId });
      showToast(data.message || (data.status === 'success' ? 'Updated' : 'Could not update'));
      if (data.status === 'success') await refreshLiveTrip();
    } catch (error) {
      showToast('Something went wrong. Please try again.');
    }
  }

  // ---------------------------------------------------------------
  // Wallet — mirrors the driver wallet sheet (assets/js/driver.js) 1:1
  // for shared components/markup; the fields that only ever apply to a
  // driver (commission reservations, payment preference) are simply
  // absent here rather than shown empty, since api/wallet.php never
  // sends a rider anything to reserve or a payment method to prefer.
  // ---------------------------------------------------------------
  const TX_CATEGORY_LABEL = {
    topup: 'Wallet Funding',
    ride_payment: 'Ride Payment',
    ride_refund: 'Refund',
    admin_adjustment: 'Adjustment',
  };
  let walletState = { balance: 0 };

  function renderWalletHero() {
    const amountEl = qs('#walletHeroAmount');
    const updatedEl = qs('#walletHeroUpdated');
    if (amountEl) amountEl.textContent = formatNaira(walletState.balance);
    if (updatedEl) updatedEl.textContent = `Last updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
  }

  function renderTransactionHistory(history) {
    const list = qs('#transactionHistoryList');
    if (!list) return;
    if (!history.length) {
      list.innerHTML = `<div class="u-text-center u-text-faint u-text-sm" style="padding:32px 0;">No transactions yet.</div>`;
      return;
    }
    list.innerHTML = history.map((tx) => {
      const isCredit = tx.type === 'credit';
      const label = TX_CATEGORY_LABEL[tx.category] || (tx.category || 'Transaction');
      return `
        <div class="tx-item">
          <div>
            <div class="tx-item__desc">${escapeHtml(label)}</div>
            <div class="tx-item__meta">${escapeHtml(relativeDateLabel(tx.created_at))} · <span class="status-pill tx-${escapeHtml(tx.status)}">${escapeHtml(tx.status)}</span></div>
          </div>
          <div>
            <div class="tx-item__amount ${isCredit ? 'is-credit' : 'is-debit'}">${isCredit ? '+' : '-'} ₦${Number(tx.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
            ${tx.balance_after !== null ? `<div class="tx-item__balance">Balance: ₦${Number(tx.balance_after).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>` : ''}
          </div>
        </div>`;
    }).join('');
  }

  async function refreshWallet() {
    try {
      const data = await apiGet('../api/wallet.php', { action: 'get_history' });
      if (data.status !== 'success') return null;
      walletState.balance = Number(data.data.balance || 0);
      renderWalletHero();
      renderTransactionHistory(data.data.history || []);
      return data.data;
    } catch (error) {
      return null;
    }
  }

  const topUpForm = qs('#topUpForm');
  const topUpAlert = qs('#topUpAlert');
  const topUpSubmitBtn = qs('#topUpSubmitBtn');

  topUpForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideAlert(topUpAlert);

    const amount = parseFloat(qs('#topUpAmount')?.value);
    if (!amount || amount <= 0) {
      showAlert(topUpAlert, 'error', 'Please enter a valid amount.');
      return;
    }

    setButtonLoading(topUpSubmitBtn, true, 'Redirecting…');
    try {
      const data = await apiPost('../api/wallet.php', { action: 'initialize_topup', amount });
      if (data.status === 'success' && data.data.authorization_url) {
        // Full-page redirect to Paystack's hosted checkout — same pattern
        // as the driver wallet sheet. Paystack redirects back to
        // api/paystack-callback.php, which lands the rider back on this
        // dashboard with ?topup=success|failed|pending.
        window.location.href = data.data.authorization_url;
        return; // leave the button in its loading state through the navigation
      }
      showAlert(topUpAlert, 'error', data.message || 'Could not start payment with Paystack.');
      setButtonLoading(topUpSubmitBtn, false);
    } catch (error) {
      showAlert(topUpAlert, 'error', 'Something went wrong. Please try again.');
      setButtonLoading(topUpSubmitBtn, false);
    }
  });

  async function refreshLiveTrip() {
    try {
      const data = await apiGet('../api/trip-collaboration.php', { action: 'get_live_status' });
      const trip = data?.data?.trip || null;

      currentBookingId = trip ? trip.booking_id : null;

      if (trip) {
        liveTripCard?.classList.remove('u-hidden');
        const driverLine = qs('#liveTripDriverLine');
        const statusLine = qs('#liveTripStatusLine');
        if (driverLine) driverLine.textContent = `Riding with ${trip.driver_name || 'your driver'}`;
        if (statusLine) statusLine.textContent = formatTripStateLabel(trip.trip_state, 'rider');
        startPassengerLocationTracking();
      } else {
        liveTripCard?.classList.add('u-hidden');
      }

      renderLiveTripSheet(trip); // cheap even while the sheet is closed; keeps it current the instant it's opened
    } catch (error) { /* a failed poll just tries again next interval */ }
  }

  qs('[data-open-sheet-id="liveTripSheet"]')?.addEventListener('click', () => refreshLiveTrip());

  refreshLiveTrip();
  pollTimer = setInterval(refreshLiveTrip, 15000);
  window.addEventListener('beforeunload', () => clearInterval(pollTimer));

  refreshWallet();

  // ---- Deep-link the wallet sheet, e.g. rider/profile.php linking to
  // dashboard.php?open=wallet — same pattern as the driver dashboard.
  const openParam = new URLSearchParams(location.search).get('open');
  if (openParam === 'wallet') {
    openSheet(qs('#walletSheet'), qs('[data-sheet-scrim]'));
  }

  // ---- Paystack top-up result — api/paystack-callback.php redirects
  // here with ?topup=success|failed|pending after the hosted checkout.
  // This never credits anything itself; it only asks the backend
  // (already-verified by then) what actually happened, then reflects
  // that truth in the UI. Same pattern as the driver dashboard.
  const topupParam = new URLSearchParams(location.search).get('topup');
  if (topupParam) {
    history.replaceState(null, '', location.pathname); // strip the query so a refresh can't re-trigger this
    if (topupParam === 'success') {
      refreshWallet().then(() => {
        showToast('Wallet credited successfully.');
        openSheet(qs('#walletSheet'), qs('[data-sheet-scrim]'));
      });
    } else if (topupParam === 'pending') {
      showToast("We couldn't confirm your payment yet — check back in a moment.");
    } else {
      showToast('Payment was not completed.');
    }
  }
});

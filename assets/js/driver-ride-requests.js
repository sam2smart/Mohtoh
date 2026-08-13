// assets/js/driver-ride-requests.js
// Driver Ride Requests feed (SYSTEM 1) — "nearby passenger demand".
// Drivers only ever create supply (Create Ride) or dismiss a card from
// their own view (Ignore, session-only — there's no per-driver
// ignore-list table, this is purely a local declutter); they never
// approve/reject a specific passenger. Every read goes through
// api/ride-requests.php via the api-client.js service layer.

document.addEventListener('DOMContentLoaded', () => {
  const listContainer = qs('#rrfListContainer');
  const emptyEl = qs('#rrfEmpty');
  const loadMoreBtn = qs('#rrfLoadMore');
  const detailSheet = qs('#rrfDetailSheet');
  const detailContent = qs('#rrfDetailContent');
  const detailFooter = qs('#rrfDetailFooter');
  const scrim = qs('[data-sheet-scrim]');

  let currentPage = 1;
  let driverCoords = null;
  const ignoredIds = new Set();


  function createRideUrl(r) {
    const params = new URLSearchParams({
      open: 'create-ride',
      prefill_pickup: r.pickup_location,
      prefill_destination: r.destination,
      prefill_date: r.travel_date,
      prefill_pickup_lat: r.pickup_latitude || '',
      prefill_pickup_lng: r.pickup_longitude || '',
      prefill_destination_lat: r.destination_latitude || '',
      prefill_destination_lng: r.destination_longitude || '',
    });
    return `dashboard.php?${params}`;
  }

  function renderCard(r) {
    return `
      <div class="card u-mb-3 anim-fade-in" data-rrf-card="${r.public_id}" style="padding:var(--space-4);">
        <div class="u-flex u-justify-between u-items-center u-mb-2">
          <span class="u-text-xs u-text-faint">${formatDate(r.travel_date)}${r.travel_time ? ' · ' + r.travel_time.slice(0, 5) : ''}</span>
          ${r.distance_km !== null && r.distance_km !== undefined ? `<span class="pricing-badge is-info"><i class="bi bi-signpost-2"></i> ${r.distance_km} km away</span>` : ''}
        </div>
        <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);font-size:.7rem;"></i>${escapeHtml(r.pickup_location)}</div>
        <div class="ride-tile__dest" style="margin:0 0 8px;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);font-size:.7rem;"></i>${escapeHtml(r.destination)}</div>
        <div class="u-text-xs u-text-faint u-mb-3"><i class="bi bi-people"></i> ${r.passenger_count} passenger${r.passenger_count > 1 ? 's' : ''} needed${r.vehicle_preference ? ' · ' + escapeHtml(r.vehicle_preference) : ''}</div>
        <div class="u-flex u-gap-2">
          <button type="button" class="btn btn-outline btn-sm" style="width:auto;flex:1;" data-rrf-view="${r.public_id}">View Details</button>
          <a href="${createRideUrl(r)}" class="btn btn-primary btn-sm" style="width:auto;flex:1;text-decoration:none;">Create Ride</a>
          <button type="button" class="btn-icon btn-ghost" data-rrf-ignore="${r.public_id}" aria-label="Ignore"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>`;
  }

  async function loadFeed(reset) {
    if (reset) { currentPage = 1; listContainer.innerHTML = ''; }
    const params = { action: 'driver_feed', page: currentPage, limit: 20 };
    if (driverCoords) { params.lat = driverCoords.latitude; params.lng = driverCoords.longitude; }
    const data = await apiGet('../api/ride-requests.php', params);
    if (data.status !== 'success') {
      showToast(data.message || 'Could not load ride requests.');
      return;
    }
    const rows = (data.data.requests || []).filter((r) => !ignoredIds.has(r.public_id));
    if (reset) listContainer.innerHTML = '';
    listContainer.insertAdjacentHTML('beforeend', rows.map(renderCard).join(''));

    const totalShown = listContainer.children.length;
    emptyEl.classList.toggle('u-hidden', totalShown > 0);
    const hasMore = data.pagination && data.pagination.current_page < data.pagination.total_pages;
    loadMoreBtn.classList.toggle('u-hidden', !hasMore);
  }

  loadMoreBtn?.addEventListener('click', () => { currentPage++; loadFeed(false); });

  listContainer.addEventListener('click', async (e) => {
    const ignoreBtn = e.target.closest('[data-rrf-ignore]');
    if (ignoreBtn) {
      ignoredIds.add(ignoreBtn.dataset.rrfIgnore);
      ignoreBtn.closest('[data-rrf-card]')?.remove();
      emptyEl.classList.toggle('u-hidden', listContainer.children.length > 0);
      return;
    }

    const viewBtn = e.target.closest('[data-rrf-view]');
    if (viewBtn) {
      const data = await apiGet('../api/ride-requests.php', { action: 'details', public_id: viewBtn.dataset.rrfView });
      if (data.status !== 'success') { showToast(data.message); return; }
      const r = data.data.request;
      detailContent.innerHTML = `
        <div class="u-flex u-items-center u-gap-3 u-mb-4">
          <div style="flex:1;">
            <div style="font-weight:700;">${escapeHtml(r.passenger_name)}</div>
            <div class="u-text-xs u-text-faint">Requested ${new Date(r.created_at).toLocaleDateString()}</div>
          </div>
        </div>
        <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);"></i>${escapeHtml(r.pickup_location)}</div>
        <div class="ride-tile__dest" style="margin:0 0 12px;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);"></i>${escapeHtml(r.destination)}</div>
        <div class="u-flex u-gap-3 u-mb-4">
          <div class="card u-flex-col u-items-center" style="flex:1;padding:12px;"><span class="u-text-xs u-text-faint">Date</span><strong class="u-text-sm">${formatDate(r.travel_date)}</strong></div>
          <div class="card u-flex-col u-items-center" style="flex:1;padding:12px;"><span class="u-text-xs u-text-faint">Time</span><strong class="u-text-sm">${r.travel_time ? r.travel_time.slice(0, 5) : 'Flexible'}</strong></div>
          <div class="card u-flex-col u-items-center" style="flex:1;padding:12px;"><span class="u-text-xs u-text-faint">Passengers</span><strong class="u-text-sm">${r.passenger_count}</strong></div>
        </div>
        ${r.vehicle_preference ? `<p class="u-text-sm u-text-soft u-mb-2"><i class="bi bi-car-front"></i> Prefers: ${escapeHtml(r.vehicle_preference)}</p>` : ''}
        ${r.notes ? `<p class="u-text-sm u-text-soft">${escapeHtml(r.notes)}</p>` : ''}
      `;
      detailFooter.innerHTML = `<a href="${createRideUrl(r)}" class="btn btn-primary" style="text-decoration:none;">Create a matching ride</a>`;
      openSheet(detailSheet, scrim);
    }
  });

  if (window.MohToh && window.MohToh.location) {
    window.MohToh.location.getCurrentLocation().then(
      (position) => { driverCoords = position; loadFeed(true); },
      () => loadFeed(true)
    );
  } else {
    loadFeed(true);
  }
});

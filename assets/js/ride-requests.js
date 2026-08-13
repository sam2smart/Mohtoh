// assets/js/ride-requests.js
// Rider Ride Requests screen (SYSTEM 1 — passenger demand; distinct
// from the Ride Queue on full rides, which lives in assets/js/search.js).
// Every action goes through the api-client.js service layer
// (apiGet/apiPost) and api/ride-requests.php; no direct database access
// from this file. Rule 2: no window.location.reload() anywhere — every
// action only re-renders the list container it affects.

document.addEventListener('DOMContentLoaded', () => {
  const listContainer = qs('#rrListContainer');
  const emptyEl = qs('#rrEmpty');
  const loadMoreBtn = qs('#rrLoadMore');
  const formSheet = qs('#rrFormSheet');
  const formTitle = qs('#rrFormTitle');
  const form = qs('#rrForm');
  const formAlert = qs('#rrFormAlert');
  const submitBtn = qs('#rrFormSubmitBtn');
  const scrim = qs('[data-sheet-scrim]');

  const STATUS_LABEL = {
    OPEN: 'Waiting for driver',
    MATCHED: 'Ride found',
    FULFILLED: 'Completed',
    EXPIRED: 'Expired',
    CANCELLED: 'Cancelled',
  };
  const TAB_STATUSES = { active: 'OPEN,MATCHED', past: 'FULFILLED,EXPIRED,CANCELLED' };

  let currentTab = 'active';
  let currentPage = 1;


  function renderCard(r) {
    const statusLower = r.status.toLowerCase();
    const canEdit = r.status === 'OPEN';
    const canCancel = r.status === 'OPEN' || r.status === 'MATCHED';
    const canDelete = ['CANCELLED', 'EXPIRED', 'FULFILLED'].includes(r.status);

    const matchedInfo = r.status === 'MATCHED' && r.matched_ride_public_id
      ? `<div class="alert-banner u-mb-2" style="background:var(--color-primary-50);color:var(--color-text);"><i class="bi bi-check-circle"></i> Matched with a ride by ${escapeHtml(r.matched_driver_name || 'a driver')} on ${formatDate(r.matched_departure_date)}${r.matched_departure_time ? ' at ' + r.matched_departure_time.slice(0, 5) : ''}.</div>`
      : '';

    const actions = [];
    if (canEdit) actions.push(`<button type="button" class="btn-text" data-rr-edit="${r.public_id}">Edit</button>`);
    if (canCancel) actions.push(`<button type="button" class="btn-text" style="color:var(--color-danger);" data-rr-cancel="${r.public_id}">Cancel</button>`);
    if (canDelete) actions.push(`<button type="button" class="btn-text" style="color:var(--color-danger);" data-rr-delete="${r.public_id}">Delete</button>`);

    return `
      <div class="card u-mb-3 anim-fade-in" style="padding:var(--space-4);">
        <div class="u-flex u-justify-between u-items-center u-mb-2">
          <span class="status-pill ${statusLower}">${escapeHtml(STATUS_LABEL[r.status] || r.status)}</span>
          <span class="u-text-xs u-text-faint">${formatDate(r.travel_date)}${r.travel_time ? ' · ' + r.travel_time.slice(0, 5) : ''}</span>
        </div>
        <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);font-size:.7rem;"></i>${escapeHtml(r.pickup_location)}</div>
        <div class="ride-tile__dest" style="margin:0 0 8px;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);font-size:.7rem;"></i>${escapeHtml(r.destination)}</div>
        <div class="u-text-xs u-text-faint u-mb-2"><i class="bi bi-people"></i> ${r.passenger_count} passenger${r.passenger_count > 1 ? 's' : ''}${r.vehicle_preference ? ' · ' + escapeHtml(r.vehicle_preference) : ''}</div>
        ${matchedInfo}
        ${actions.length ? `<div class="u-flex u-gap-3 u-mt-2">${actions.join('')}</div>` : ''}
      </div>`;
  }

  async function loadList(reset) {
    if (reset) { currentPage = 1; listContainer.innerHTML = ''; }
    const data = await apiGet('../api/ride-requests.php', { action: 'my_requests', status: TAB_STATUSES[currentTab], page: currentPage, limit: 20 });
    if (data.status !== 'success') {
      showToast(data.message || 'Could not load ride requests.');
      return;
    }
    const rows = data.data.requests || [];
    if (reset) listContainer.innerHTML = '';
    listContainer.insertAdjacentHTML('beforeend', rows.map(renderCard).join(''));

    const totalShown = listContainer.children.length;
    emptyEl.classList.toggle('u-hidden', totalShown > 0);
    const hasMore = data.pagination && data.pagination.current_page < data.pagination.total_pages;
    loadMoreBtn.classList.toggle('u-hidden', !hasMore);
  }

  qsa('[data-rr-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      qsa('[data-rr-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
      currentTab = btn.dataset.rrTab;
      loadList(true);
    });
  });

  loadMoreBtn?.addEventListener('click', () => { currentPage++; loadList(false); });

  // Initialize autocomplete
  initAddressAutocomplete(qs('#rrPickup'), qs('#rrPickupLat'), qs('#rrPickupLng'));
  initAddressAutocomplete(qs('#rrDestination'), qs('#rrDestinationLat'), qs('#rrDestinationLng'));

  function openCreateForm() {
    form.reset();
    qs('#rrEditPublicId').value = '';
    qs('#rrPickupLat').value = '';
    qs('#rrPickupLng').value = '';
    qs('#rrDestinationLat').value = '';
    qs('#rrDestinationLng').value = '';
    formTitle.textContent = 'Create Ride Request';
    submitBtn.textContent = 'Create Request';
    hideAlert(formAlert);
    qs('#rrDate').min = new Date().toISOString().slice(0, 10);
    openSheet(formSheet, scrim);
  }

  qs('#rrCreateBtn')?.addEventListener('click', openCreateForm);

  listContainer.addEventListener('click', async (e) => {
    const editBtn = e.target.closest('[data-rr-edit]');
    if (editBtn) {
      const data = await apiGet('../api/ride-requests.php', { action: 'details', public_id: editBtn.dataset.rrEdit });
      if (data.status !== 'success') { showToast(data.message); return; }
      const r = data.data.request;
      form.reset();
      qs('#rrEditPublicId').value = r.public_id;
      qs('#rrPickup').value = r.pickup_location;
      qs('#rrPickupLat').value = r.pickup_latitude || '';
      qs('#rrPickupLng').value = r.pickup_longitude || '';
      qs('#rrDestination').value = r.destination;
      qs('#rrDestinationLat').value = r.destination_latitude || '';
      qs('#rrDestinationLng').value = r.destination_longitude || '';
      qs('#rrDate').value = r.travel_date;
      qs('#rrDate').min = new Date().toISOString().slice(0, 10);
      qs('#rrTime').value = r.travel_time ? r.travel_time.slice(0, 5) : '';
      qs('#rrPassengers').value = r.passenger_count;
      qs('#rrVehiclePreference').value = r.vehicle_preference || '';
      qs('#rrNotes').value = r.notes || '';
      formTitle.textContent = 'Edit Ride Request';
      submitBtn.textContent = 'Save Changes';
      hideAlert(formAlert);
      openSheet(formSheet, scrim);
      return;
    }

    const cancelBtn = e.target.closest('[data-rr-cancel]');
    if (cancelBtn) {
      if (!confirm('Cancel this ride request?')) return;
      const res = await apiPost('../api/ride-requests.php', { action: 'cancel', public_id: cancelBtn.dataset.rrCancel });
      showToast(res.message);
      if (res.status === 'success') loadList(true);
      return;
    }

    const deleteBtn = e.target.closest('[data-rr-delete]');
    if (deleteBtn) {
      if (!confirm('Delete this ride request permanently?')) return;
      const res = await apiPost('../api/ride-requests.php', { action: 'delete', public_id: deleteBtn.dataset.rrDelete });
      showToast(res.message);
      if (res.status === 'success') loadList(true);
    }
  });

  qs('#rrUseLocationBtn')?.addEventListener('click', () => {
    if (!window.MohToh || !window.MohToh.location) { showToast('Location is not available on this device.'); return; }
    const btn = qs('#rrUseLocationBtn');
    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
    window.MohToh.location.getCurrentLocation().then(async (position) => {
      const { latitude, longitude } = position;
      qs('#rrPickupLat').value = latitude;
      qs('#rrPickupLng').value = longitude;
      const geo = await mtReverseGeocode(latitude, longitude);
      qs('#rrPickup').value = (geo && geo.formatted_address) || `Lat: ${latitude.toFixed(4)}, Lng: ${longitude.toFixed(4)}`;
      btn.innerHTML = '<i class="bi bi-crosshair"></i>';
    }, (error) => {
      showToast(`Could not get your location: ${error.message}`);
      btn.innerHTML = '<i class="bi bi-crosshair"></i>';
    });
  });

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideAlert(formAlert);

    const editPublicId = qs('#rrEditPublicId').value;
    const payload = {
      action: editPublicId ? 'update' : 'create',
      pickup_location: qs('#rrPickup').value.trim(),
      pickup_latitude: qs('#rrPickupLat').value || null,
      pickup_longitude: qs('#rrPickupLng').value || null,
      destination: qs('#rrDestination').value.trim(),
      destination_latitude: qs('#rrDestinationLat').value || null,
      destination_longitude: qs('#rrDestinationLng').value || null,
      travel_date: qs('#rrDate').value,
      travel_time: qs('#rrTime').value || null,
      passenger_count: parseInt(qs('#rrPassengers').value, 10) || 1,
      vehicle_preference: qs('#rrVehiclePreference').value || null,
      notes: qs('#rrNotes').value.trim() || null,
    };
    if (editPublicId) payload.public_id = editPublicId;

    setButtonLoading(submitBtn, true, editPublicId ? 'Saving…' : 'Creating…');
    const res = await apiPost('../api/ride-requests.php', payload);
    setButtonLoading(submitBtn, false);

    if (res.status === 'success') {
      closeSheet(formSheet, scrim);
      showToast(res.message);
      loadList(true);
    } else {
      showAlert(formAlert, 'error', res.message);
    }
  });

  loadList(true);
});

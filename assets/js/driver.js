// assets/js/driver.js
// Driver Home screen behavior.
//
// Updated for the seat-locked, commission-aware pricing engine: seats
// are read from the driver's approved vehicle (never client input),
// price per seat defaults to a server-computed recommendation and can
// be adjusted within a server-enforced band via the new
// api/rideshare.php?action=price_preview endpoint, and Publish is
// gated client-side (for UX) on the same wallet check the server
// enforces authoritatively.
//
// Labeling note kept from the first pass: the "View Requests" quick
// action has no backend equivalent — this app has no accept/reject
// request queue, a passenger booking a seat is already confirmed — so
// it's wired to get_passengers and labeled "Passengers" instead.
// "Stops" is still left out of Create Ride: ride_shares has no
// waypoints column.

document.addEventListener('DOMContentLoaded', () => {
  const vehicleWarningCard = qs('#vehicleWarningCard');
  const currentRideCard = qs('#currentRideCard');
  const quickStartBtn = qs('#quickStartRideBtn');
  const statTileRow = qs('#statTileRow');
  const statusVehicleName = qs('#statusVehicleName');
  const statusVehiclePlate = qs('#statusVehiclePlate');
  const statusLocation = qs('#statusLocationValue');

  let currentRide = null; // the single "act on this" ride: in_progress, else next upcoming
  let vehicleStatus = null;
  let isAccountVerified = false;
  window.vehicleSummary = '';
  // Canonical vehicle facts, published on window so driver-ride-creation.js
  // can gate operations on them. Populated by refreshVehicleInfo() from the
  // vehicles row; null/'' until that first request resolves.
  window.vehicleType = '';
  window.isKekeVehicle = false;
  let lastSelection = { pickup: null, destination: null }; // last address chosen per field, for "suggest as MohToh location"
  let vehicleSeats = null;

  async function checkCommunityPricing() {
    const pickup = qs('#createRidePickup')?.value;
    const dest = qs('#createRideDestination')?.value;

    if (!pickup || !dest) return;

    const data = await apiGet('../api/community-fare.php', {
      action: 'get_route_fare',
      community: 'General',
      route: `${pickup} → ${dest}`,
      // Was hardcoded to 'keke', so a car driver was quoted keke community
      // fares. Use the driver's actual approved vehicle type, falling back to
      // 'car' if the vehicle request hasn't resolved yet.
      vehicle_type: window.vehicleType || 'car'
    });

    const container = qs('#pricingAssistantContainer');
    if (!container) return;

    if (data.status === 'success') {
      const fare = data.data.fare;
      container.innerHTML = `
        <div class="card p-3" style="background:var(--color-primary-50);margin-bottom:var(--space-2);">
          <h6 class="mb-2">Community Pricing Assistant</h6>
          <div class="u-text-sm">Recommended: ₦${Number(fare.recommended).toLocaleString()}</div>
          <div class="u-text-sm">Range: ₦${Number(fare.min).toLocaleString()} - ₦${Number(fare.max).toLocaleString()}</div>
          <div class="u-text-sm">Confidence: ${fare.confidence}%</div>
        </div>
      `;
    }
  }
  // driver-ride-creation.js calls this from the ride-mode change handler and
  // lives in a separate file/scope, so it has to be reachable off window —
  // same pattern as window.selectOperation below.
  window.checkCommunityPricing = checkCommunityPricing;

  // ---------------------------------------------------------------
  // Online / offline — same api/toggle-online.php contract. The header
  // pill and the status-card switch are two views of one state; both
  // call this single function so there's one source of truth.
  // ---------------------------------------------------------------
  const availabilityToggle = qs('#availabilityToggle');
  const availabilityLabel = qs('#availabilityLabel');
  const onlineToggleSwitch = qs('#onlineToggleSwitch');

  function reflectOnlineState(isOnline) {
    availabilityToggle?.classList.toggle('is-online', isOnline);
    availabilityToggle?.classList.toggle('is-offline', !isOnline);
    availabilityToggle?.setAttribute('aria-pressed', String(isOnline));
    if (availabilityLabel) availabilityLabel.textContent = isOnline ? 'Online' : 'Offline';
    if (onlineToggleSwitch) onlineToggleSwitch.checked = isOnline;
  }

  async function setOnlineStatus(goingOnline) {
    try {
      const data = await apiPost('../api/toggle-online.php', { is_online: goingOnline ? 1 : 0 });
      if (data.status === 'success') {
        reflectOnlineState(!!data.data.is_online);
      } else {
        showToast(data.message || 'Could not update status');
        reflectOnlineState(!goingOnline); // revert the optimistic UI
      }
    } catch (error) {
      showToast('Could not update status');
      reflectOnlineState(!goingOnline);
    }
  }

  availabilityToggle?.addEventListener('click', () => setOnlineStatus(availabilityToggle.classList.contains('is-offline')));
  onlineToggleSwitch?.addEventListener('change', () => setOnlineStatus(onlineToggleSwitch.checked));

  // ---------------------------------------------------------------
  // Location: continuous watch, mirrors the old startLocationTracking()
  // — updates the shared map marker and posts to api/update-location.php
  // ---------------------------------------------------------------
  function startLocationTracking() {
    if (!window.MohToh || !window.MohToh.location) return;
    window.MohToh.location.watchLocation(
      async (position) => {
        const { latitude, longitude } = position;
        if (mtMap) placeUserMarker({ lat: latitude, lng: longitude });
        try {
          await apiPost('../api/update-location.php', { latitude, longitude });
        } catch (error) { /* same as before: a failed location ping is silent */ }
      },
      () => {},
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  }

  async function showReverseGeocodedLocation() {
    if (!window.MohToh || !window.MohToh.location || !statusLocation) return;
    window.MohToh.location.getCurrentLocation().then(async (position) => {
      // mtReverseGeocode is defined in helper.js, which is loaded before driver.js
      const geo = await mtReverseGeocode(position.latitude, position.longitude);
      statusLocation.textContent = (geo && (geo.address || geo.formatted_address)) || 'Location detected';
    }, () => { statusLocation.textContent = 'Location unavailable'; });
  }

  // ---------------------------------------------------------------
  // Vehicle info + verification — api/driver-api.php. Drives the
  // status-card warning banner AND the mobile Vehicle Verification
  // bottom sheet (#vehicleVerificationSheet) from one shared fetch, so
  // both always agree with the real backend status — no separate,
  // hand-maintained UI states.
  // ---------------------------------------------------------------
  let vehicleInfoCache = null;

  async function refreshVehicleInfo() {
    try {
      const data = await apiGet('../api/driver-api.php', { action: 'get_vehicle_info' });
      vehicleInfoCache = data?.data || null;
      const vehicle = vehicleInfoCache?.vehicle || null;
      vehicleStatus = vehicle?.status || null;
      isAccountVerified = !!vehicleInfoCache?.is_verified;

      if (vehicle) {
        if (statusVehicleName) statusVehicleName.textContent = `${vehicle.make} ${vehicle.model}`;
        if (statusVehiclePlate) statusVehiclePlate.textContent = vehicle.plate_number;
        window.vehicleSummary = `${vehicle.make} ${vehicle.model} · ${vehicle.color} · ${vehicle.plate_number}`;
        vehicleSeats = parseInt(vehicle.seats, 10) || null;
        // Expose the vehicle TYPE as its own fact. Consumers previously had
        // to sniff for "keke" inside the make/model summary string, which
        // never contains the type at all - so keke detection silently always
        // failed. vehicle_type is the canonical value ('keke', 'car', ...)
        // normalised by normalize_vehicle_type() on the server.
        window.vehicleType = (vehicle.vehicle_type || '').toLowerCase();
        window.isKekeVehicle = window.vehicleType === 'keke';
        document.dispatchEvent(new CustomEvent('mohtoh:vehicle-loaded', {
          detail: { vehicleType: window.vehicleType, isKeke: window.isKekeVehicle, seats: vehicleSeats }
        }));
      }

      // Ride creation requires BOTH the vehicle being approved and the
      // driver account being verified. In normal operation these move
      // together (see admin-api.php), but both are still checked here so
      // the banner always reflects reality rather than assuming one
      // implies the other.
      const isEligibleToPublish = vehicleStatus === 'approved' && isAccountVerified;
      qs('[data-quick-action="create-ride"]')?.classList.toggle('is-disabled', !isEligibleToPublish);

      if (isEligibleToPublish) {
        vehicleWarningCard?.classList.add('u-hidden');
      } else {
        vehicleWarningCard?.classList.remove('u-hidden');
        const warningText = qs('#vehicleStatusText');
        if (warningText) warningText.textContent = vehicleStatusMessage();
      }

      renderVehicleVerificationSheet();
    } catch (error) {
      // Leave the warning card in its default (visible) state on failure.
    }
  }

  function vehicleStatusMessage() {
    const vehicle = vehicleInfoCache?.vehicle;
    if (vehicleStatus === 'rejected') {
      const reason = vehicle?.review_remarks;
      return reason
        ? `Your vehicle was rejected: ${reason} Update your details before publishing rides.`
        : 'Your vehicle was rejected. Update your details before publishing rides.';
    }
    if (vehicleStatus === 'pending') {
      return 'Your vehicle verification is still pending. You cannot create a ride until approval is complete.';
    }
    if (vehicleStatus === 'approved' && !isAccountVerified) {
      return 'Your vehicle is approved, but your driver account is still pending verification.';
    }
    return 'Complete your vehicle profile to start publishing rides.';
  }

  const VEHICLE_STATUS_META = {
    pending: { emoji: '🟡', label: 'Pending Review', pillClass: 'pending' },
    verified: { emoji: '🟢', label: 'Verified', pillClass: 'approved' },
    rejected: { emoji: '🔴', label: 'Rejected', pillClass: 'rejected' },
    suspended: { emoji: '⛔', label: 'Suspended', pillClass: 'rejected' }
  };

  // Labels for the canonical vehicle types the backend stores. Mirrors
  // vehicle_type_label() in includes/eligibility.php so the verification
  // sheet can state the vehicle's class — a keke driver has to be able to
  // see that MohToh recorded a keke, not a car.
  const VEHICLE_TYPE_LABELS = { car: 'Car', keke: 'Keke Napep', bus: 'Bus', motorcycle: 'Motorcycle' };

  const DOCUMENT_STATUS_META = {
    approved: { label: 'Approved', pillClass: 'approved', icon: 'bi-check-circle-fill' },
    pending: { label: 'Pending', pillClass: 'pending', icon: 'bi-hourglass-split' },
    rejected: { label: 'Rejected', pillClass: 'rejected', icon: 'bi-x-circle-fill' },
    expired: { label: 'Expired', pillClass: 'rejected', icon: 'bi-clock-history' },
    missing: { label: 'Missing', pillClass: 'pending', icon: 'bi-circle' }
  };

  function renderVehicleFormHtml(vehicle) {
    const v = vehicle || {};
    // Keke and bus manufacturers belong here too — the list was car-only,
    // so a keke's real make (Bajaj, TVS, Piaggio) had no option and was
    // silently blanked whenever the driver reopened this form. An existing
    // make that is not on the list is preserved for the same reason.
    const makes = ['Toyota', 'Honda', 'Ford', 'Bajaj', 'TVS', 'Piaggio', 'Mahindra', 'Toyota Hiace', 'Mercedes-Benz', 'Nissan', 'Other'];
    if (v.make && !makes.includes(v.make)) makes.unshift(v.make);
    // Values must be the CANONICAL types the backend stores
    // (normalize_vehicle_type in includes/eligibility.php), not the
    // lowercased labels. Submitting "keke napep" still normalises fine on
    // write, but it never matches the stored "keke" on read, so a keke
    // driver's own type silently failed to preselect when they reopened
    // the form.
    const types = [
      { value: 'car', label: 'Car' },
      { value: 'bus', label: 'Bus' },
      { value: 'keke', label: 'Keke Napep' },
      { value: 'motorcycle', label: 'Motorcycle' }
    ];
    return `
      <div id="vehicleFormAlert" class="alert-banner is-hidden"></div>
      <form id="vehicleDetailsForm">
        <div class="field-group">
          <label class="field-label">Manufacturer</label>
          <div class="field"><i class="bi bi-car-front"></i>
            <select name="make" required>
              <option value="">Select manufacturer</option>
              ${makes.map((m) => `<option value="${m}" ${v.make === m ? 'selected' : ''}>${m}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label">Vehicle type</label>
          <div class="field"><i class="bi bi-car-front-fill"></i>
            <select name="vehicle_type" required>
              <option value="">Select type</option>
              ${types.map((t) => `<option value="${t.value}" ${v.vehicle_type === t.value ? 'selected' : ''}>${t.label}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label">Model</label>
          <div class="field"><i class="bi bi-car-front-fill"></i><input type="text" name="model" value="${escapeHtml(v.model || '')}" required></div>
        </div>
        <div class="search-card__row">
          <div class="field-group"><label class="field-label">Year</label><div class="field"><i class="bi bi-calendar3"></i><input type="number" name="year" min="1990" max="2030" value="${v.year || ''}" required></div></div>
          <div class="field-group"><label class="field-label">Colour</label><div class="field"><i class="bi bi-palette"></i><input type="text" name="color" value="${escapeHtml(v.color || '')}" required></div></div>
        </div>
        <div class="field-group">
          <label class="field-label">Plate number</label>
          <div class="field"><i class="bi bi-credit-card-2-front"></i><input type="text" name="plate_number" value="${escapeHtml(v.plate_number || '')}" required></div>
        </div>
        <div class="field-group">
          <label class="field-label">Seats</label>
          <div class="field"><i class="bi bi-people"></i><input type="number" name="seats" min="1" value="${v.seats || 4}" required></div>
        </div>
        <div class="field-group">
          <label class="field-label">Ride Mode</label>
          <div class="field"><i class="bi bi-gear"></i>
            <select name="ride_mode" required>
              <option value="shared" ${v.ride_mode === 'shared' ? 'selected' : ''}>Shared Ride</option>
              <option value="private" ${v.ride_mode === 'private' ? 'selected' : ''}>Private Ride (Entire vehicle reservation)</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" id="vehicleDetailsSubmitBtn">${v.id ? 'Save changes' : 'Register vehicle'}</button>
      </form>
    `;
  }

  function wireVehicleDetailsForm() {
    const form = qs('#vehicleDetailsForm');
    const alertEl = qs('#vehicleFormAlert');
    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      hideAlert(alertEl);
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.action = 'setup_vehicle';
      const btn = qs('#vehicleDetailsSubmitBtn');
      setButtonLoading(btn, true, 'Saving…');
      try {
        const data = await apiPost('../api/driver-api.php', payload);
        if (data.status === 'success') {
          showToast(data.message);
          await refreshVehicleInfo();
        } else {
          showAlert(alertEl, 'error', data.message || 'Could not save vehicle details.');
        }
      } catch (error) {
        showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
      } finally {
        setButtonLoading(btn, false);
      }
    });
  }

  function wireVehicleUploadForm(formId, btnId, extraAction) {
    const form = qs(formId);
    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      formData.append('action', extraAction);
      const btn = qs(btnId);
      setButtonLoading(btn, true, 'Uploading…');
      try {
        const data = await apiUpload('../api/driver-api.php', formData);
        showToast(data.message || (data.status === 'success' ? 'Uploaded' : 'Upload failed'));
        if (data.status === 'success') await refreshVehicleInfo();
      } catch (error) {
        showToast('Could not upload right now. Please try again.');
      } finally {
        setButtonLoading(btn, false);
      }
    });
  }

  // The per-document actions on a checklist row.
  //
  // Each required document carries its OWN upload control, because the
  // shared "select a document, then choose a file" form below the list
  // never told a driver that a specific missing document was theirs to
  // send. The control is a button plus a hidden file input rather than a
  // visible input, so the row stays readable and clicking the action
  // opens the device file picker directly.
  //
  // An already-uploaded document is not given a second empty upload box:
  // it offers View, plus Replace only because the existing backend
  // genuinely supports re-submission (a new upload supersedes the old row
  // without deleting it, and get_vehicle_info returns the latest per slot).
  function documentRowActionsHtml(doc, canUpload) {
    const parts = [];
    if (doc.document_path) {
      parts.push(`<a class="btn-text u-text-xs" href="../uploads/${escapeHtml(doc.document_path)}" target="_blank" rel="noopener">View</a>`);
    }
    if (canUpload) {
      const label = doc.status === 'missing' ? 'Upload Document'
        : (doc.status === 'rejected' ? 'Re-upload' : 'Replace');
      parts.push(
        `<button type="button" class="btn-text u-text-xs" style="font-weight:700;" data-doc-upload="${escapeHtml(doc.type)}">${label}</button>`
        + `<input type="file" class="u-hidden" accept=".pdf,.jpg,.jpeg,.png" data-doc-input="${escapeHtml(doc.type)}">`
      );
    }
    return parts.join('');
  }

  // Wires every checklist row's action to the SAME upload endpoint the
  // form below uses. The status pill is never touched here: it is redrawn
  // by refreshVehicleInfo() from the server's response, so a document only
  // reads "Pending" once the backend confirms it actually stored it.
  function wireDocumentRowUploads() {
    qsa('[data-doc-upload]').forEach((btn) => {
      const type = btn.getAttribute('data-doc-upload');
      const input = qs(`[data-doc-input="${type}"]`);
      if (!input) return;
      btn.addEventListener('click', () => input.click());
      input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('action', 'upload_document');
        formData.append('document_type', type);
        formData.append('document', file);
        setButtonLoading(btn, true, 'Uploading…');
        try {
          const data = await apiUpload('../api/driver-api.php', formData);
          showToast(data.message || (data.status === 'success' ? 'Uploaded' : 'Upload failed'));
          if (data.status === 'success') await refreshVehicleInfo();
        } catch (error) {
          showToast('Could not upload right now. Please try again.');
        } finally {
          setButtonLoading(btn, false);
          input.value = '';
        }
      });
    });
  }

  // The single source of truth for what the driver sees in the
  // verification sheet — built entirely from vehicleInfoCache (the real
  // backend response), never a hand-maintained UI state.
  function renderVehicleVerificationSheet() {
    const body = qs('#vehicleVerificationBody');
    if (!body) return;
    const data = vehicleInfoCache;

    if (!data || !data.has_vehicle) {
      body.innerHTML = `<p class="u-text-sm u-text-soft u-mb-4">Register your vehicle to start driving with MohToh.</p>` + renderVehicleFormHtml({});
      wireVehicleDetailsForm();
      return;
    }

    const vehicle = data.vehicle;
    const photos = data.photos || [];
    const documents = data.documents || [];
    // The required-document checklist comes from the backend
    // (vehicle_verification_documents in includes/eligibility.php) so the
    // sheet never holds its own copy of what MohToh requires.
    const requiredDocuments = data.required_documents || [];
    // A verified vehicle's documents have already been accepted, so the
    // sheet stops offering to replace them — matching the existing rule
    // that hides the upload forms once verification is complete.
    const canUploadDocs = vehicle.verification_status !== 'verified';
    const meta = VEHICLE_STATUS_META[vehicle.verification_status] || VEHICLE_STATUS_META.pending;
    const typeLabel = data.vehicle_type_label || VEHICLE_TYPE_LABELS[vehicle.vehicle_type] || vehicle.vehicle_type || 'Not set';

    let html = `
      <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
        <div style="font-weight:700;font-family:var(--font-display);">${escapeHtml(vehicle.make)} ${escapeHtml(vehicle.model)}</div>
        <div class="u-text-sm u-text-soft">${escapeHtml(vehicle.plate_number)}</div>
        <div class="u-text-sm u-text-soft u-mt-1">Vehicle Type: <strong>${escapeHtml(typeLabel)}</strong></div>
        <div class="u-mt-3"><span class="status-pill ${meta.pillClass}">${meta.emoji} ${meta.label}</span></div>
      </div>
    `;

    if (vehicle.verification_status === 'verified') {
      html += `<div class="alert-banner success"><i class="bi bi-check-circle"></i> You can create rides.</div>`;
    } else if (vehicle.verification_status === 'rejected') {
      html += `<div class="alert-banner error"><i class="bi bi-exclamation-triangle"></i><div><strong>Reason:</strong> ${escapeHtml(vehicle.review_remarks || 'No reason provided.')}</div></div>`;
    } else if (vehicle.verification_status === 'suspended') {
      html += `<div class="alert-banner error"><i class="bi bi-exclamation-octagon"></i><div><strong>Status:</strong> Your vehicle has been suspended. Please contact support.</div></div>`;
    }

    if (data.pending_edit_request) {
      html += `<div class="alert-banner" style="background:var(--color-accent-100);color:#a67900;"><i class="bi bi-hourglass-split"></i> Your update request is pending review by admin.</div>`;
    }

    if (vehicle.verification_status !== 'verified') {
      html += `
        <div class="verify-checklist">
          <div class="verify-checklist__item is-done"><i class="bi bi-check-circle-fill"></i> Driver details saved</div>
          <div class="verify-checklist__item ${photos.length ? 'is-done' : ''}"><i class="bi ${photos.length ? 'bi-check-circle-fill' : 'bi-circle'}"></i> Vehicle photo${photos.length ? ` (${photos.length})` : ' — required'}</div>
        </div>
      `;
    }

    // Verification documents, one row per document MohToh requires, each
    // with its own review status. Shown for every vehicle type — this is
    // what a keke driver was missing: the sheet used to offer a single
    // free-text upload box, so nothing said which documents were expected
    // or which had already been reviewed.
    if (requiredDocuments.length) {
      html += `<div class="section-label u-mt-4">Verification documents</div><div class="verify-checklist u-mb-4">`
        + requiredDocuments.map((doc) => {
          const dMeta = DOCUMENT_STATUS_META[doc.status] || DOCUMENT_STATUS_META.missing;
          const reason = doc.status === 'rejected' && doc.rejection_reason
            ? `<div class="u-text-xs" style="color:var(--color-danger);margin-top:2px;">${escapeHtml(doc.rejection_reason)}</div>`
            : '';
          return `
            <div class="verify-checklist__item ${doc.status === 'approved' ? 'is-done' : ''}" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <span><i class="bi ${dMeta.icon}"></i> ${escapeHtml(doc.label)}${reason}</span>
              <span style="display:flex;align-items:center;gap:6px;">
                <span class="status-pill ${dMeta.pillClass}">${dMeta.label}</span>
                ${documentRowActionsHtml(doc, canUploadDocs)}
              </span>
            </div>`;
        }).join('')
        + `</div>`;

      // Anything uploaded outside the required set (an extra document, or
      // an older free-text upload that does not map to a slot) is still
      // the driver's submission and stays visible.
      const extras = documents.filter((d) => d.document_type === 'other');
      if (extras.length) {
        html += `<div class="u-text-xs u-text-soft u-mb-2">Other documents</div><div class="verify-checklist u-mb-4">`
          + extras.map((d) => {
            const dMeta = DOCUMENT_STATUS_META[d.status] || DOCUMENT_STATUS_META.pending;
            return `<div class="verify-checklist__item ${d.status === 'approved' ? 'is-done' : ''}" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span><i class="bi ${dMeta.icon}"></i> ${escapeHtml(d.document_name || 'Document')}</span>
              <span class="status-pill ${dMeta.pillClass}">${dMeta.label}</span>
            </div>`;
          }).join('')
          + `</div>`;
      }
    }

    if (photos.length) {
      html += `<div class="u-text-xs u-text-soft u-mb-2">Uploaded photos</div><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:var(--space-4);">`
        + photos.map((p) => `<img src="../img.php?f=${encodeURIComponent(p.photo_path)}&w=400" loading="lazy" alt="Vehicle photo" style="width:100%;height:64px;object-fit:cover;border-radius:var(--radius-sm);cursor:pointer;" onclick="if(window.MeritImageViewer&&window.MeritImageViewer.open)window.MeritImageViewer.open('../uploads/${escapeHtml(p.photo_path)}','Vehicle photo')">`).join('')
        + `</div>`;
    }

    if (vehicle.verification_status !== 'verified') {
      html += `
        <form id="vehiclePhotoUploadForm" class="u-mb-4">
          <label class="field-label">Upload vehicle photo</label>
          <div class="field"><i class="bi bi-tag"></i>
            <select name="photo_type" required>
              <option value="">Select view type</option>
              <option value="front">Front view</option>
              <option value="side">Side view</option>
              <option value="interior">Interior</option>
              <option value="rear">Rear view</option>
              <option value="driver_side">Driver side</option>
              <option value="boot">Boot/Storage</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="field u-mt-2"><i class="bi bi-image"></i><input type="file" name="photo" accept="image/*" required></div>
          <button type="submit" class="btn btn-outline u-mt-2" id="vehiclePhotoUploadBtn">Upload photo</button>
        </form>
        <form id="vehicleDocUploadForm" class="u-mb-4">
          <label class="field-label">Upload another document</label>
          <div class="field"><i class="bi bi-file-earmark-text"></i>
            <select name="document_type" required>
              <option value="">Select document</option>
              ${requiredDocuments.map((d) => `<option value="${escapeHtml(d.type)}">${escapeHtml(d.label)}</option>`).join('')}
              <option value="other">Other document</option>
            </select>
          </div>
          <div class="field u-mt-2"><i class="bi bi-paperclip"></i><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required></div>
          <button type="submit" class="btn btn-outline u-mt-2" id="vehicleDocUploadBtn">Upload document</button>
        </form>
      `;
    }

    html += `
      <button type="button" class="btn ${vehicle.verification_status === 'rejected' ? 'btn-primary' : 'btn-outline'}" id="toggleVehicleEditBtn">
        ${vehicle.verification_status === 'rejected' ? 'Resubmit vehicle details' : 'Edit vehicle details'}
      </button>
      <div id="vehicleEditFormWrap" class="u-mt-4 u-hidden"></div>
    `;

    body.innerHTML = html;

    qs('#toggleVehicleEditBtn')?.addEventListener('click', () => {
      const wrap = qs('#vehicleEditFormWrap');
      if (!wrap) return;
      if (wrap.classList.contains('u-hidden')) {
        wrap.innerHTML = renderVehicleFormHtml(vehicle);
        wrap.classList.remove('u-hidden');
        wireVehicleDetailsForm();
      } else {
        wrap.classList.add('u-hidden');
        wrap.innerHTML = '';
      }
    });

    wireVehicleUploadForm('#vehiclePhotoUploadForm', '#vehiclePhotoUploadBtn', 'upload_photo');
    wireVehicleUploadForm('#vehicleDocUploadForm', '#vehicleDocUploadBtn', 'upload_document');
    wireDocumentRowUploads();
  }

  qsa('[data-open-sheet-id="vehicleVerificationSheet"]').forEach((el) => {
    el.addEventListener('click', () => refreshVehicleInfo());
  });

  // ---------------------------------------------------------------
  // Part 2 (Premium Driver Features) — dashboard teaser. Links through
  // to profile.php's Premium Center (deep-linked via ?open=premium,
  // same convention this app already uses for wallet/vehicle).
  async function loadPremiumTeaser() {
    try {
      const data = await apiGet('../api/premium.php', { action: 'catalog' });
      if (data.status !== 'success') return;
      const isPremium = data.data.current_plan === 'premium';
      const planEl = qs('#premiumTeaserPlan');
      const textEl = qs('#premiumTeaserText');
      if (planEl) planEl.textContent = isPremium ? 'Premium Plan' : 'Basic Plan';
      if (textEl) textEl.textContent = isPremium ? 'Manage premium features' : 'Upgrade to Premium';

      const visibilityFeatures = ['Priority Ride Visibility', 'More Ride Exposure'];
      const hasVisibilityBoost = (data.data.features || []).some((f) => visibilityFeatures.includes(f.feature_name) && f.unlocked);
      qs('#createRidePremiumBadge')?.classList.toggle('u-hidden', !hasVisibilityBoost);
    } catch (error) { /* teaser just keeps its default text on failure */ }
  }
  loadPremiumTeaser();

  // My rides — api/rideshare.php?action=my_rides. Drives the "current
  // ride" strip, the quick-action Start/End button and the Upcoming
  // Rides sheet.
  // ---------------------------------------------------------------
  async function loadMyRides() {
    try {
      const data = await apiGet('../api/rideshare.php', { action: 'my_rides' });
      if (data.status !== 'success') return;

      const rides = data.data.rides || [];
      renderUpcomingSheet(rides);

      const today = new Date().toISOString().split('T')[0];
      currentRide = rides.find((r) => r.status === 'in_progress')
        || rides.filter((r) => (r.status === 'active' || r.status === 'full') && r.departure_date >= today)
                .sort((a, b) => (a.departure_date + a.departure_time).localeCompare(b.departure_date + b.departure_time))[0]
        || null;

      renderCurrentRideStrip();
    } catch (error) {
      if (currentRideCard) currentRideCard.innerHTML = `<div class="alert-banner error">Could not load your rides.</div>`;
    }
  }

  function renderCurrentRideStrip() {
    if (!currentRideCard) return;

    if (!currentRide) {
      currentRideCard.innerHTML = `
        <div class="u-text-center" style="padding:6px 0;">
          <div class="u-text-sm u-text-soft u-mb-2">No ride in progress. Create one to start earning.</div>
        </div>`;
      if (quickStartBtn) { quickStartBtn.innerHTML = '<i class="bi bi-play-circle"></i><span>Start ride</span>'; quickStartBtn.disabled = true; }
      return;
    }

    const isInProgress = currentRide.status === 'in_progress';
    currentRideCard.innerHTML = `
      <div class="u-flex u-justify-between u-items-center">
        <div>
          <div class="u-text-xs u-text-faint">${isInProgress ? 'In progress' : 'Next ride'}</div>
          <div style="font-weight:700;">${escapeHtml(currentRide.start_location)} → ${escapeHtml(currentRide.destination)}</div>
          <div class="u-text-xs u-text-soft">${escapeHtml(currentRide.departure_date)} · ${formatTime12h(currentRide.departure_time)} · ${currentRide.passengers_joined || 0} joined</div>
          ${currentRide.trip_state ? `<div class="u-text-xs" style="color:var(--color-primary);font-weight:600;margin-top:2px;"><i class="bi bi-broadcast"></i> ${escapeHtml(formatTripStateLabel(currentRide.trip_state))}</div>` : ''}
          <div class="u-text-xs u-text-soft u-mt-2 u-hidden" id="currentRideRouteInfo"></div>
        </div>
        <span class="status-pill ${currentRide.status}">${escapeHtml(currentRide.status.replace('_', ' '))}</span>
      </div>`;

    if (quickStartBtn) {
      quickStartBtn.disabled = false;
      quickStartBtn.innerHTML = isInProgress
        ? '<i class="bi bi-stop-circle"></i><span>End ride</span>'
        : '<i class="bi bi-play-circle"></i><span>Start ride</span>';
    }

    // Draw the route for the current ride once we have its coordinates
    apiGet('../api/rideshare.php', { action: 'details', ride_id: currentRide.id })
      .then((d) => {
        const ride = d?.data?.ride;
        if (ride?.pickup_lat && ride?.destination_lat && typeof drawRoute === 'function') {
          drawRoute(
            { lat: parseFloat(ride.pickup_lat), lng: parseFloat(ride.pickup_lng) },
            { lat: parseFloat(ride.destination_lat), lng: parseFloat(ride.destination_lng) }
          );
        }
        const routeInfo = qs('#currentRideRouteInfo');
        if (routeInfo && ride?.distance_km) {
          routeInfo.innerHTML = `<i class="bi bi-signpost-2"></i> ${ride.distance_km} km · ~${ride.estimated_duration_minutes} min`;
          routeInfo.classList.remove('u-hidden');
        }
      })
      .catch(() => {});
  }

  quickStartBtn?.addEventListener('click', async () => {
    if (!currentRide) return;
    const isInProgress = currentRide.status === 'in_progress';
    const rideId = currentRide.id; // captured before loadMyRides() reassigns currentRide below
    if (!confirm(isInProgress ? 'Complete this ride?' : 'Start this ride? Passengers will be notified.')) return;

    setButtonLoading(quickStartBtn, true, isInProgress ? 'Ending…' : 'Starting…');
    try {
      const data = await apiPost('../api/rideshare.php', { action: isInProgress ? 'complete_ride' : 'start_ride', ride_id: rideId });
      if (data.status === 'success') {
        showToast(isInProgress ? 'Ride completed' : 'Ride started');
        await loadMyRides();
        // Trip Completion: jump straight to Passengers so any direct
        // payments awaiting confirmation are immediately visible —
        // currentRide no longer points at this (now completed) ride
        // once loadMyRides() re-selects the next one.
        if (isInProgress) await openPassengersSheet(rideId);
      } else {
        showToast(data.message);
      }
    } catch (error) {
      showToast('Something went wrong.');
    } finally {
      setButtonLoading(quickStartBtn, false);
      renderCurrentRideStrip();
    }
  });

  // ---------------------------------------------------------------
  // Upcoming Rides sheet — View / Start / Cancel per tile
  // ---------------------------------------------------------------
  function renderUpcomingSheet(rides) {
    const list = qs('#upcomingRidesList');
    if (!list) return;
    const relevant = rides.filter((r) => r.status !== 'completed' && r.status !== 'cancelled');
    if (!relevant.length) {
      list.innerHTML = `<div class="u-text-center u-text-faint u-text-sm" style="padding:32px 0;">No upcoming rides. Publish one to get started.</div>`;
      return;
    }
    list.innerHTML = relevant.map((ride) => `
      <div class="card ride-tile">
        <div class="ride-tile__top">
          <div>
            <div class="ride-tile__time">${escapeHtml(ride.departure_date)} · ${formatTime12h(ride.departure_time)}</div>
            <div class="ride-tile__from">${escapeHtml(ride.start_location)} → ${escapeHtml(ride.destination)}</div>
          </div>
          <span class="status-pill ${ride.status}">${escapeHtml(ride.status.replace('_', ' '))}</span>
        </div>
        <div class="u-text-xs u-text-soft">${ride.passengers_joined || 0} joined · ${ride.available_seats} seats left${Number(ride.waiting_count) > 0 ? ` · <span style="color:var(--color-accent-600);font-weight:600;"><i class="bi bi-hourglass-split"></i> ${ride.waiting_count} waiting</span>` : ''}</div>
        <div class="ride-tile__actions">
          <button type="button" class="btn btn-outline btn-sm" data-ride-view="${ride.id}">View</button>
          ${(ride.status === 'active' || ride.status === 'full') ? `<button type="button" class="btn btn-outline btn-sm" data-ride-start="${ride.id}">Start</button>` : ''}
          ${(ride.status === 'active' || ride.status === 'full') ? `<button type="button" class="btn btn-outline btn-sm" data-ride-cancel="${ride.id}" style="color:var(--color-danger);border-color:var(--color-danger);">Cancel</button>` : ''}
        </div>
      </div>
    `).join('');
  }

  qs('#upcomingRidesList')?.addEventListener('click', async (e) => {
    const viewBtn = e.target.closest('[data-ride-view]');
    const startBtn = e.target.closest('[data-ride-start]');
    const cancelBtn = e.target.closest('[data-ride-cancel]');

    if (viewBtn) return openPassengersSheet(viewBtn.dataset.rideView);

    if (startBtn) {
      if (!confirm('Start this ride? Passengers will be notified.')) return;
      const res = await postRideAction('start_ride', startBtn.dataset.rideStart);
      if (res.status === 'success') { showToast('Ride started'); await loadMyRides(); } else showToast(res.message);
    }

    if (cancelBtn) {
      if (!confirm('Cancel this ride? Booked passengers will be notified and refunded.')) return;
      const res = await postRideAction('cancel', cancelBtn.dataset.rideCancel);
      if (res.status === 'success') { showToast('Ride cancelled'); await loadMyRides(); } else showToast(res.message);
    }
  });

  async function postRideAction(action, ride_id) {
    try {
      return await apiPost('../api/rideshare.php', { action, ride_id: Number(ride_id) });
    } catch (error) {
      return { status: 'error', message: 'Something went wrong.' };
    }
  }

  // ---------------------------------------------------------------
  // Passengers sheet — api/rideshare.php?action=get_passengers
  // (this is what "View Requests" maps to; see note at top of file)
  // ---------------------------------------------------------------
  let passengersSheetRideId = null;

  // Phase 3 (Collaborative Intelligence): passenger_state -> a short
  // driver-facing label + icon, matching ride_share_bookings.passenger_state.
  const PASSENGER_STATE_META = {
    booked: { icon: '⏳', label: 'Booked' },
    navigating: { icon: '⏳', label: 'Walking to Pickup' },
    arrived: { icon: '✓', label: 'Arrived' },
    boarded: { icon: '✓', label: 'Boarded' },
    dropped_off: { icon: '✓', label: 'Dropped Off' },
    completed: { icon: '✓', label: 'Completed' },
    no_show: { icon: '❌', label: 'No-show' },
  };

  async function openPassengersSheet(rideId) {
    const sheet = qs('#passengersSheet');
    const list = qs('#passengersList');
    if (!sheet || !list) return;
    passengersSheetRideId = rideId;
    list.innerHTML = `<div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:20px;height:20px;display:inline-block;"></span></div>`;
    openSheet(sheet, qs('[data-sheet-scrim]'));

    try {
      const data = await apiGet('../api/rideshare.php', { action: 'get_passengers', ride_id: rideId });
      const passengers = data?.data?.passengers || [];
      const summary = data?.data?.summary;

      const summaryHtml = summary ? `
        <div class="stat-tile-row u-mb-4">
          <div class="stat-tile"><div class="stat-tile__value">${summary.passenger_count}</div><div class="stat-tile__label">Passengers</div></div>
          <div class="stat-tile"><div class="stat-tile__value">${summary.seats_filled}</div><div class="stat-tile__label">Seats Filled</div></div>
          <div class="stat-tile"><div class="stat-tile__value">${summary.remaining_count}</div><div class="stat-tile__label">Remaining</div></div>
        </div>` : '';

      list.innerHTML = summaryHtml + (passengers.length
        ? passengers.map((p) => {
            const meta = PASSENGER_STATE_META[p.passenger_state] || { icon: '⏳', label: p.passenger_state };
            return `
            <div style="padding:10px 0;border-bottom:1px solid var(--color-border);">
              <div class="u-flex u-items-center u-gap-3">
                <div style="width:38px;height:38px;border-radius:50%;background:var(--color-primary-100);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-weight:700;">${escapeHtml((p.fullname || '?').charAt(0))}</div>
                <div class="u-flex-col" style="flex:1;">
                  <div class="u-text-sm" style="font-weight:600;">${escapeHtml(p.fullname)}</div>
                  <div class="u-text-xs u-text-faint">${meta.icon} ${escapeHtml(meta.label)}${p.last_confidence_score !== null ? ` · ${p.last_confidence_score}% confidence` : ''}</div>
                </div>
                <div class="u-text-right u-text-xs"><strong>${p.seats_booked}</strong> seat(s)</div>
              </div>
              ${renderPaymentSection(p)}
              ${renderNoShowSection(p)}
              ${renderPassengerOverrideSection(p)}
            </div>
          `; }).join('')
        : '<div class="u-text-center u-text-faint u-text-sm" style="padding:24px 0;">No passengers have joined this ride yet.</div>');
    } catch (error) {
      list.innerHTML = `<div class="alert-banner error">Could not load passengers.</div>`;
    }
  }

  // No-show countdown (mt_start_no_show_countdown, set once the driver's
  // GPS confirms PASSENGERS_ASSEMBLED) has expired for this passenger —
  // the driver decides what happens next; nothing here auto-cancels.
  function renderNoShowSection(p) {
    if (!p.no_show_pending) return '';
    return `
      <div class="payment-status-banner"><i class="bi bi-exclamation-triangle"></i> This passenger hasn't arrived yet</div>
      <div class="payment-status-actions">
        <button type="button" class="btn btn-outline btn-sm" data-no-show-wait="${p.booking_id}">Wait</button>
        <button type="button" class="btn btn-outline btn-sm" data-no-show-skip="${p.booking_id}">Skip</button>
        <button type="button" class="btn btn-outline btn-sm" data-no-show-cancel="${p.booking_id}" style="color:var(--color-danger);border-color:var(--color-danger);">Cancel</button>
      </div>`;
  }

  // Manual override for the automatic boarding/drop-off detection —
  // available whenever GPS hasn't (yet) confirmed it itself.
  function renderPassengerOverrideSection(p) {
    if (['dropped_off', 'completed', 'no_show'].includes(p.passenger_state)) return '';
    if (p.passenger_state === 'boarded') {
      return `<div class="u-mt-2"><button type="button" class="btn-text u-text-xs" data-confirm-dropped="${p.booking_id}">Mark dropped off</button></div>`;
    }
    return `<div class="u-mt-2"><button type="button" class="btn-text u-text-xs" data-confirm-boarded="${p.booking_id}">Mark boarded</button></div>`;
  }

  // Trip Completion, direct-payment case: while PAYMENT_PENDING, the
  // driver must explicitly confirm or reject via the existing
  // confirm_direct_payment / reject_direct_payment actions — nothing
  // here settles money itself.
  function renderPaymentSection(p) {
    if (p.payment_method !== 'direct') return '';
    if (p.payment_status === 'PAYMENT_PENDING') {
      return `
        <div class="payment-status-banner"><i class="bi bi-hourglass-split"></i> Waiting for driver confirmation</div>
        <div class="payment-status-actions">
          <button type="button" class="btn btn-primary btn-sm" data-confirm-payment="${p.booking_id}">Confirm Passenger Payment</button>
          <button type="button" class="btn btn-outline btn-sm" data-reject-payment="${p.booking_id}" style="color:var(--color-danger);border-color:var(--color-danger);">Reject Payment</button>
        </div>`;
    }
    if (p.payment_status === 'PAYMENT_CONFIRMED') {
      return `<div class="u-mt-2"><span class="status-pill PAYMENT_CONFIRMED"><i class="bi bi-check-circle"></i> Payment confirmed</span></div>`;
    }
    if (p.payment_status === 'PAYMENT_FAILED') {
      return `<div class="u-mt-2"><span class="status-pill PAYMENT_FAILED">Payment not received</span></div>`;
    }
    return '';
  }

  qs('#passengersList')?.addEventListener('click', async (e) => {
    const confirmBtn = e.target.closest('[data-confirm-payment]');
    const rejectBtn = e.target.closest('[data-reject-payment]');
    if (!confirmBtn && !rejectBtn) return;

    const btn = confirmBtn || rejectBtn;
    const action = confirmBtn ? 'confirm_direct_payment' : 'reject_direct_payment';
    const bookingId = btn.dataset.confirmPayment || btn.dataset.rejectPayment;

    setButtonLoading(btn, true, confirmBtn ? 'Confirming…' : 'Rejecting…');
    try {
      const data = await apiPost('../api/rideshare.php', { action, booking_id: Number(bookingId) });
      showToast(data.message || (data.status === 'success' ? 'Done' : 'Something went wrong'));
      if (data.status === 'success' && passengersSheetRideId) {
        await openPassengersSheet(passengersSheetRideId);
        await refreshWallet(); // confirming a direct payment debits commission from the driver's wallet
      }
    } catch (error) {
      showToast('Something went wrong.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  // Phase 3: no-show decisions + manual boarding/drop-off overrides —
  // all POST to api/trip-collaboration.php (new), never touching
  // payment or the legacy booking status directly except through that
  // endpoint's own transaction (no_show_cancel).
  qs('#passengersList')?.addEventListener('click', async (e) => {
    const target = e.target.closest('[data-no-show-wait], [data-no-show-skip], [data-no-show-cancel], [data-confirm-boarded], [data-confirm-dropped]');
    if (!target) return;

    let action, bookingId, loadingLabel;
    if (target.dataset.noShowWait) { action = 'no_show_wait'; bookingId = target.dataset.noShowWait; loadingLabel = 'Waiting…'; }
    else if (target.dataset.noShowSkip) { action = 'no_show_skip'; bookingId = target.dataset.noShowSkip; loadingLabel = 'Skipping…'; }
    else if (target.dataset.noShowCancel) { action = 'no_show_cancel'; bookingId = target.dataset.noShowCancel; loadingLabel = 'Cancelling…'; }
    else if (target.dataset.confirmBoarded) { action = 'confirm_passenger_boarded'; bookingId = target.dataset.confirmBoarded; loadingLabel = 'Saving…'; }
    else if (target.dataset.confirmDropped) { action = 'confirm_passenger_dropped'; bookingId = target.dataset.confirmDropped; loadingLabel = 'Saving…'; }
    if (!action) return;

    setButtonLoading(target, true, loadingLabel);
    try {
      const data = await apiPost('../api/trip-collaboration.php', { action, booking_id: Number(bookingId) });
      showToast(data.message || (data.status === 'success' ? 'Done' : 'Something went wrong'));
      if (data.status === 'success' && passengersSheetRideId) {
        await openPassengersSheet(passengersSheetRideId);
      }
    } catch (error) {
      showToast('Something went wrong.');
    } finally {
      setButtonLoading(target, false);
    }
  });

  qs('[data-quick-action="passengers"]')?.addEventListener('click', () => {
    if (!currentRide) { showToast('No active ride to show passengers for'); return; }
    openPassengersSheet(currentRide.id);
  });

  // ---------------------------------------------------------------
  // Today's performance — computed client-side from get_earnings'
  // history (no new backend field needed for the "today" slice).
  // Lives in a sheet, not permanently on screen — only the compact
  // teaser row on the status card and the Wallet quick action open it.
  // ---------------------------------------------------------------
  async function loadTodaySummary() {
    if (!statTileRow) return;
    try {
      const data = await apiGet('../api/rideshare.php', { action: 'get_earnings' });
      const history = data?.data?.history || [];
      const today = new Date().toISOString().split('T')[0];
      const todays = history.filter((h) => h.date === today);

      const earnings = todays.reduce((sum, h) => sum + Number(h.earning || 0), 0);
      const trips = todays.length;
      const passengers = todays.reduce((sum, h) => sum + Number(h.seats_booked || 0), 0);

      statTileRow.innerHTML = `
        <div class="stat-tile"><div class="stat-tile__value">₦${earnings.toLocaleString()}</div><div class="stat-tile__label">Earned today</div></div>
        <div class="stat-tile"><div class="stat-tile__value">${trips}</div><div class="stat-tile__label">Trips today</div></div>
        <div class="stat-tile"><div class="stat-tile__value">${passengers}</div><div class="stat-tile__label">Passengers</div></div>
        <div class="stat-tile is-muted"><div class="stat-tile__value">—</div><div class="stat-tile__label">Distance (not tracked)</div></div>
        <div class="stat-tile"><div class="stat-tile__value">${qs('#driverRatingValue')?.textContent || '—'}</div><div class="stat-tile__label">Rating</div></div>
      `;

      const teaserText = qs('#performanceTeaserText');
      if (teaserText) teaserText.innerHTML = `<i class="bi bi-graph-up"></i> Today: ₦${earnings.toLocaleString()} · ${trips} trip${trips === 1 ? '' : 's'}`;
    } catch (error) {
      statTileRow.innerHTML = `<div class="alert-banner error">Could not load today's performance.</div>`;
    }
  }

  // ---------------------------------------------------------------
  // Ride History sheet (sidebar "Ride History" link opens this) —
  // api/rideshare.php?action=get_ride_history, loaded lazily
  // ---------------------------------------------------------------
  let historyLoaded = false;
  qs('[data-open-sheet-id="rideHistorySheet"]')?.addEventListener('click', async () => {
    if (historyLoaded) return;
    historyLoaded = true;
    const list = qs('#rideHistoryList');
    if (list) {
      try {
        const data = await apiGet('../api/rideshare.php', { action: 'get_ride_history' });
        const history = data?.data?.history || [];
        list.innerHTML = history.length
          ? history.map((ride) => `
              <div class="card ride-tile">
                <div class="ride-tile__top">
                  <div>
                    <div class="ride-tile__time">${escapeHtml(ride.departure_date)}</div>
                    <div class="ride-tile__from">${escapeHtml(ride.start_location)} → ${escapeHtml(ride.destination)}</div>
                  </div>
                  <span class="status-pill ${ride.status}">${escapeHtml(ride.status)}</span>
                </div>
                <div class="u-text-xs u-text-soft">${ride.status === 'completed' ? `Earned ₦${Number(ride.earning).toLocaleString()}` : 'No earning'}</div>
              </div>
            `).join('')
          : '<div class="u-text-center u-text-faint u-text-sm" style="padding:24px 0;">No past rides yet.</div>';
      } catch (error) {
        list.innerHTML = `<div class="alert-banner error">Could not load ride history.</div>`;
      }
    }
  });

  // ---------------------------------------------------------------
  // Create Ride sheet — same vehicle-approval gate and payload shape
  // as the previous implementation
  // ---------------------------------------------------------------
  const createRideSheet = qs('#createRideSheet');
  const createRideForm = qs('#createRideForm');
  const createRideAlert = qs('#createRideAlert');
  const pricePreviewEmpty = qs('#pricePreviewEmpty');
  const pricePreviewContent = qs('#pricePreviewContent');
  const createRidePriceInput = qs('#createRidePrice');
  const walletReserveNote = qs('#walletReserveNote');
  const publishRideBtn = qs('#publishRideBtn');

  let latestPreview = null; // last successful price_preview response, or null

  function openCreateRideSheet() {
    if (vehicleStatus !== 'approved' || !isAccountVerified) {
      refreshVehicleInfo().then(() => {
        showToast(vehicleStatusMessage());
        openSheet(qs('#vehicleVerificationSheet'), qs('[data-sheet-scrim]'));
      });
      return;
    }
    // Instead of directly opening createRideSheet, open operation selector
    openSheet(qs('#rideOperationSheet'), qs('[data-sheet-scrim]'));
  }

  window.selectOperation = (type) => {
    closeSheet(qs('#rideOperationSheet'), qs('[data-sheet-scrim]'));

    const form = qs('#createRideForm');
    form.reset();

    // Keke trips publish through the same ride_shares contract as car trips —
    // api/rideshare.php already stamps vehicle_type from the driver's own
    // vehicles row, so there is nothing extra to send. What the keke branch
    // owes the driver is an honest sheet: label the operation, and state the
    // capacity that came from the approved vehicle instead of leaving the
    // heading generic and the reason for the seat count unexplained.
    const isKeke = type === 'keke';
    const sheetTitle = qs('.sheet-title', createRideSheet);
    if (sheetTitle) sheetTitle.textContent = isKeke ? 'Create keke trip' : 'Create ride';

    let opNote = qs('#createRideOperationNote');
    if (!opNote) {
      opNote = document.createElement('div');
      opNote.id = 'createRideOperationNote';
      opNote.className = 'u-text-xs u-mb-3';
      createRideAlert?.insertAdjacentElement('afterend', opNote);
    }
    opNote.innerHTML = isKeke
      ? '<span class="pricing-badge is-info"><i class="bi bi-scooter"></i> Keke / Maruwa trip</span>'
      : '';
    opNote.classList.toggle('u-hidden', !isKeke);

    qs('#createRideVehicleSummary').textContent = window.vehicleSummary || '—';
    qs('#createRideSeatsDisplay').textContent = vehicleSeats ? `${vehicleSeats} seats` : '—';
    resetPricePreview();
    setChoiceSelection(qs('#rideCollectionList'), walletState.payment_preference);
    openSheet(createRideSheet, qs('[data-sheet-scrim]'));
  }

  qs('[data-quick-action="create-ride"]')?.addEventListener('click', openCreateRideSheet);
  document.addEventListener('mohtoh:center-action', (e) => {
    if (e.detail.action === 'create-ride') openCreateRideSheet();
  });

  function resetPricePreview() {
    latestPreview = null;
    pricePreviewEmpty?.classList.remove('u-hidden');
    pricePreviewContent?.classList.add('u-hidden');
    if (walletReserveNote) walletReserveNote.textContent = '';
    if (publishRideBtn) publishRideBtn.disabled = false;
    lastSelection = { pickup: null, destination: null };
    qs('#suggestPickupBtn')?.classList.add('u-hidden');
    qs('#suggestDestinationBtn')?.classList.add('u-hidden');
    const errorEl = qs('#priceRangeError');
    if (errorEl) errorEl.textContent = '';
    qs('#priceFieldWrap')?.classList.remove('u-hidden');
    qs('#systemModeLockedNote')?.classList.add('u-hidden');
    if (createRidePriceInput) createRidePriceInput.readOnly = false;
  }

  // Fired after BOTH pickup and destination are selected from address
  // suggestions (initAddressAutocomplete's onSelect callback) — free-typed
  // text with no coordinates can't be priced, matching what create/update
  // now require server-side too.
  async function maybeFetchPricePreview() {
    checkCommunityPricing();
    const pickupLat = qs('#createRidePickupLat')?.value;
    const pickupLng = qs('#createRidePickupLng')?.value;
    const destLat = qs('#createRideDestinationLat')?.value;
    const destLng = qs('#createRideDestinationLng')?.value;
    if (!pickupLat || !pickupLng || !destLat || !destLng) return;

    pricePreviewEmpty?.classList.remove('u-hidden');
    pricePreviewEmpty && (pricePreviewEmpty.textContent = 'Calculating price…');
    pricePreviewContent?.classList.add('u-hidden');

    try {
      const data = await apiPost('../api/rideshare.php', {
        action: 'price_preview', pickup_lat: pickupLat, pickup_lng: pickupLng, destination_lat: destLat, destination_lng: destLng,
        // Live recalculation extends to date/time too — these are what
        // the pricing engine's night/peak/holiday multipliers key off,
        // so a preview fetched without them would silently miss those.
        departure_date: qs('#createRideDate')?.value || null,
        departure_time: qs('#createRideTime')?.value || null,
      });
      if (data.status !== 'success') {
        if (pricePreviewEmpty) pricePreviewEmpty.textContent = data.message || 'Could not calculate a price for this route.';
        return;
      }

      latestPreview = data.data;
      pricePreviewEmpty?.classList.add('u-hidden');
      pricePreviewContent?.classList.remove('u-hidden');

      qs('#previewDistance').textContent = latestPreview.distance_km;
      qs('#previewRecommended').textContent = `₦${Number(latestPreview.recommended_price).toLocaleString()}`;
      qs('#previewMin').textContent = Number(latestPreview.min_price).toLocaleString();
      qs('#previewMax').textContent = Number(latestPreview.max_price).toLocaleString();
      const potentialRevenueEl = qs('#previewPotentialRevenue');
      if (potentialRevenueEl) potentialRevenueEl.textContent = `₦${Number(latestPreview.potential_revenue).toLocaleString()} (${latestPreview.total_seats} seats)`;
      const maxCommissionEl = qs('#previewMaxCommission');
      if (maxCommissionEl) maxCommissionEl.textContent = `₦${Number(latestPreview.maximum_trip_commission).toLocaleString()}`;

      renderPricingModeAndBadges(latestPreview);
      applyPricingModeToField(latestPreview);
      updateWalletNote();
    } catch (error) {
      if (pricePreviewEmpty) { pricePreviewEmpty.classList.remove('u-hidden'); pricePreviewEmpty.textContent = 'Could not calculate a price for this route.'; }
      pricePreviewContent?.classList.add('u-hidden');
    }
  }

  const PRICING_MODE_LABEL = { system: 'System', driver: 'Driver', hybrid: 'Hybrid' };

  function renderPricingModeAndBadges(preview) {
    const pill = qs('#pricingModePill');
    if (pill) pill.textContent = `${PRICING_MODE_LABEL[preview.pricing_mode] || 'Hybrid'} Price`;

    // Each badge only appears when its underlying multiplier/region is
    // actually in effect — read straight from the breakdown the pricing
    // engine already returned, never re-derived on the client.
    const b = preview.breakdown || {};
    const badges = [];
    if (b.peak_multiplier && b.peak_multiplier > 1) badges.push({ label: 'Peak Hour', icon: 'bi-graph-up-arrow' });
    if (b.night_multiplier && b.night_multiplier > 1) badges.push({ label: 'Night Fare', icon: 'bi-moon-stars' });
    if (b.holiday_multiplier && b.holiday_multiplier > 1) badges.push({ label: 'Holiday Pricing', icon: 'bi-calendar-heart' });
    if (b.region) badges.push({ label: `${b.region} Pricing`, icon: 'bi-geo-alt', cls: 'is-info' });
    if (!badges.length) badges.push({ label: 'Standard Rate', icon: 'bi-check-circle', cls: 'is-value' });

    const row = qs('#pricingBadgeRow');
    if (row) {
      row.innerHTML = badges.map((badge) => `<span class="pricing-badge ${badge.cls || ''}"><i class="bi ${badge.icon}"></i>${escapeHtml(badge.label)}</span>`).join('');
    }
  }

  // The price field behaves completely differently per admin-configured
  // pricing_mode — this is the one place that branching lives, so the
  // submit-time validation below and this always agree with each other.
  function applyPricingModeToField(preview) {
    const wrap = qs('#priceFieldWrap');
    const lockedNote = qs('#systemModeLockedNote');
    const label = qs('#priceFieldLabel');
    if (!createRidePriceInput) return;

    if (preview.pricing_mode === 'system') {
      wrap?.classList.add('u-hidden');
      lockedNote?.classList.remove('u-hidden');
      createRidePriceInput.value = preview.recommended_price;
      createRidePriceInput.readOnly = true;
    } else if (preview.pricing_mode === 'driver') {
      wrap?.classList.remove('u-hidden');
      lockedNote?.classList.add('u-hidden');
      createRidePriceInput.readOnly = false;
      const min = preview.minimum_fare_per_seat > 0 ? preview.minimum_fare_per_seat : 0;
      const max = preview.maximum_fare_per_seat > 0 ? preview.maximum_fare_per_seat : null;
      createRidePriceInput.min = min;
      if (max !== null) createRidePriceInput.max = max;
      createRidePriceInput.value = preview.recommended_price;
      if (label) {
        label.innerHTML = max !== null
          ? `Price per seat (₦${min.toLocaleString()} – ₦${max.toLocaleString()}, your choice)`
          : `Price per seat (min ₦${min.toLocaleString()}, your choice)`;
      }
    } else {
      // hybrid — the original band-around-the-recommendation behavior
      wrap?.classList.remove('u-hidden');
      lockedNote?.classList.add('u-hidden');
      createRidePriceInput.readOnly = false;
      createRidePriceInput.min = preview.min_price;
      createRidePriceInput.max = preview.max_price;
      createRidePriceInput.value = preview.recommended_price;
      if (label) label.innerHTML = `Price per seat (₦<span id="previewMin">${Number(preview.min_price).toLocaleString()}</span> – ₦<span id="previewMax">${Number(preview.max_price).toLocaleString()}</span>)`;
    }
  }

  // Mirrors resolve_price_per_passenger()'s server-side branching exactly
  // (for instant feedback only — the server call is still the actual
  // authority and re-validates independently on submit).
  function validatePriceAgainstMode(preview, requestedPrice) {
    if (preview.pricing_mode === 'system') {
      return null; // field is locked/readonly, nothing for the driver to get wrong
    }
    if (preview.pricing_mode === 'driver') {
      const min = preview.minimum_fare_per_seat > 0 ? preview.minimum_fare_per_seat : null;
      const max = preview.maximum_fare_per_seat > 0 ? preview.maximum_fare_per_seat : null;
      if (min !== null && requestedPrice < min) return `This fare is below the minimum allowed (₦${min.toLocaleString()}).`;
      if (max !== null && requestedPrice > max) return `This fare exceeds the allowed range (max ₦${max.toLocaleString()}).`;
      return null;
    }
    // hybrid
    if (requestedPrice < preview.min_price) return `This fare is below the minimum allowed (₦${Number(preview.min_price).toLocaleString()}).`;
    if (requestedPrice > preview.max_price) return `This fare exceeds the allowed range (max ₦${Number(preview.max_price).toLocaleString()}).`;
    return null;
  }

  createRidePriceInput?.addEventListener('input', () => {
    const errorEl = qs('#priceRangeError');
    if (!errorEl || !latestPreview) return;
    const requested = parseFloat(createRidePriceInput.value);
    errorEl.textContent = Number.isNaN(requested) ? '' : (validatePriceAgainstMode(latestPreview, requested) || '');
  });

  // ---- "How was this price calculated?" breakdown sheet ----
  qs('#howPriceCalculatedBtn')?.addEventListener('click', () => {
    if (!latestPreview) return;
    const b = latestPreview.breakdown || {};
    const row = (label, value) => `<div class="price-breakdown-row"><span class="price-breakdown-row__label">${escapeHtml(label)}</span><span class="price-breakdown-row__value">${value}</span></div>`;
    const multiplierRow = (label, value) => value && value !== 1 ? row(label, `×${Number(value).toFixed(2)}`) : '';

    const body = qs('#priceBreakdownBody');
    if (!body) return;
    body.innerHTML = [
      row('Distance', `${latestPreview.distance_km} km`),
      b.region ? row('Region', escapeHtml(b.region)) : '',
      row('Base Fare', formatNaira(b.base_fare)),
      row('Price Per KM', formatNaira(b.price_per_km)),
      b.booking_fee ? row('Booking Fee', formatNaira(b.booking_fee)) : '',
      b.service_charge ? row('Service Charge', formatNaira(b.service_charge)) : '',
      multiplierRow('Peak Hour Multiplier', b.peak_multiplier),
      multiplierRow('Night Multiplier', b.night_multiplier),
      multiplierRow('Holiday Multiplier', b.holiday_multiplier),
      row('Recommended Passenger Fare', formatNaira(latestPreview.recommended_price)),
      row('Allowed Price Range', `${formatNaira(latestPreview.min_price)} – ${formatNaira(latestPreview.max_price)}`),
      row('Vehicle Capacity', `${latestPreview.total_seats} seats`),
      row('Potential Revenue', `${formatNaira(latestPreview.recommended_price)} × ${latestPreview.total_seats} seats = ${formatNaira(latestPreview.potential_revenue)}`),
      row('MohToh Commission', `${latestPreview.commission_percentage}%`),
      row('Commission Per Passenger', formatNaira(latestPreview.recommended_price - latestPreview.driver_earning_per_seat)),
      row('Maximum Trip Commission', formatNaira(latestPreview.maximum_trip_commission)),
      row('Driver Earnings Per Seat', formatNaira(latestPreview.driver_earning_per_seat)),
      `<div class="price-breakdown-row is-total"><span class="price-breakdown-row__label">Driver Expected Earnings (all seats)</span><span class="price-breakdown-row__value">${formatNaira(latestPreview.estimated_earnings_if_full)}</span></div>`,
    ].filter(Boolean).join('');

    openSheet(qs('#priceBreakdownSheet'), qs('[data-sheet-scrim]'));
  });

  function updateWalletNote() {
    if (!latestPreview || !walletReserveNote) return;
    const sufficient = latestPreview.wallet_sufficient;
    // "Available" (not total wallet_balance) is what the reserve check
    // actually runs against — a driver with money locked in other active
    // trips' reservations needs to see that, not their total.
    walletReserveNote.innerHTML = sufficient
      ? `<span class="u-text-soft"><i class="bi bi-check-circle"></i> Wallet reserve required: ₦${Number(latestPreview.required_wallet_reserve).toLocaleString()} (₦${Number(latestPreview.available_balance).toLocaleString()} available)</span>`
      : `<span style="color:var(--color-danger);"><i class="bi bi-exclamation-triangle"></i> Your wallet requires ₦${Number(latestPreview.required_wallet_reserve).toLocaleString()} commission reserve before publishing this trip. (₦${Number(latestPreview.available_balance).toLocaleString()} available)</span>`;
    if (publishRideBtn) publishRideBtn.disabled = !sufficient;
  }

  createRideForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideAlert(createRideAlert);

    const formData = new FormData(createRideForm);
    const payload = Object.fromEntries(formData.entries());
    payload.start_location = payload.pickup_location;
    payload.action = payload.ride_id ? 'update' : 'create';

    // Payment Collection reuses the driver's payment_preference setting
    // (already implemented in api/wallet.php) rather than a separate
    // per-ride field: ride creation snapshots whatever the driver's
    // preference is at that moment, so save it here first if changed.
    const ridePaymentPreference = payload.ride_payment_preference;
    delete payload.ride_payment_preference;
    if (ridePaymentPreference && ridePaymentPreference !== walletState.payment_preference) {
      try {
        const prefData = await apiPost('../api/wallet.php', { action: 'set_payment_preference', payment_preference: ridePaymentPreference });
        if (prefData.status === 'success') walletState.payment_preference = ridePaymentPreference;
      } catch (error) { /* if this fails, the ride still creates under the last-saved preference */ }
    }

    const missing = ['pickup_location', 'destination', 'departure_date', 'departure_time']
      .filter((field) => String(payload[field] ?? '').trim() === '');
    if (missing.length) { showAlert(createRideAlert, 'error', 'Please fill in all required fields.'); return; }

    if (!payload.pickup_lat || !payload.destination_lat) {
      showAlert(createRideAlert, 'error', 'Select pickup and destination from the suggestions so the trip can be priced.');
      return;
    }

    if (latestPreview && !latestPreview.wallet_sufficient) {
      showAlert(createRideAlert, 'error', `Your wallet requires ₦${Number(latestPreview.required_wallet_reserve).toLocaleString()} commission reserve before publishing this trip.`);
      return;
    }

    if (latestPreview && latestPreview.pricing_mode !== 'system') {
      const requestedPrice = parseFloat(payload.price_per_passenger);
      const rangeError = Number.isNaN(requestedPrice) ? 'Enter a price per seat.' : validatePriceAgainstMode(latestPreview, requestedPrice);
      if (rangeError) {
        showAlert(createRideAlert, 'error', rangeError);
        return;
      }
    }

    setButtonLoading(publishRideBtn, true, 'Publishing…');

    try {
      const data = await apiPost('../api/rideshare.php', payload);
      if (data.status === 'success') {
        createRideForm.reset();
        resetPricePreview();
        await loadMyRides();

        // Build the shareable link for this specific ride.
        // join.php?ride=PUBLIC_ID → rider dashboard auto-opens the booking
        // sheet for this ride (logged-in riders) or login page (new users).
        const publicId  = data.data?.ride_public_id || '';
        const origin    = window.location.origin;
        const base      = window.location.pathname.split('/').slice(0, -2).join('/'); // up two levels from driver/
        const shareUrl  = publicId ? `${origin}${base}/join.php?ride=${encodeURIComponent(publicId)}` : null;
        const pickup    = payload.pickup_location || 'pickup';
        const dest      = payload.destination    || 'destination';
        const shareText = `I'm offering seats from ${pickup} to ${dest} on MohToh. Book your seat here:`;

        if (shareUrl) {
          // Replace the form with a compact success + share card so the
          // driver sees and can act on the link without another tap.
          createRideAlert.className = 'alert-banner success';
          createRideAlert.innerHTML = `
            <div style="margin-bottom:var(--space-3);">
              <strong><i class="bi bi-check-circle-fill"></i> Ride published!</strong><br>
              <span class="u-text-xs u-text-soft">Share the link below so passengers can find and book your ride.</span>
            </div>
            <div class="field" style="background:var(--color-surface);margin-bottom:var(--space-3);gap:8px;">
              <i class="bi bi-link-45deg" style="flex-shrink:0;"></i>
              <input id="rideShareUrlInput" type="text" value="${escapeHtml(shareUrl)}" readonly
                style="border:none;padding:0;font-size:var(--text-xs);color:var(--color-text-soft);flex:1;min-width:0;">
            </div>
            <div class="u-flex u-gap-3">
              <button type="button" class="btn btn-primary" id="shareRideLinkBtn" style="flex:1;">
                <i class="bi bi-share"></i> Share this ride
              </button>
              <button type="button" class="btn btn-outline" id="copyRideLinkBtn">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>`;

          qs('#shareRideLinkBtn')?.addEventListener('click', () => {
            window.MohToh?.share.shareLink(shareUrl, 'Join my ride on MohToh', shareText);
          });
          qs('#copyRideLinkBtn')?.addEventListener('click', () => {
            navigator.clipboard?.writeText(shareUrl).then(() => showToast('Link copied!')).catch(() => {
              qs('#rideShareUrlInput')?.select();
              document.execCommand('copy');
              showToast('Link copied!');
            });
          });
        } else {
          showAlert(createRideAlert, 'success', data.message || 'Ride published.');
        }

        // Auto-close after a short delay only when there's no share action pending.
        // With the share card visible, let the driver close manually.
        if (!shareUrl) {
          setTimeout(() => closeSheet(createRideSheet, qs('[data-sheet-scrim]')), 700);
        }
      } else {
        showAlert(createRideAlert, 'error', data.message);
      }
    } catch (error) {
      showAlert(createRideAlert, 'error', 'Unable to publish this ride right now.');
    } finally {
      setButtonLoading(publishRideBtn, false);
    }
  });


  // ---------------------------------------------------------------
  // "Suggest as a MohToh location" — only offered when the selected
  // suggestion came from Nominatim (source: 'external'), not when the
  // driver already picked an existing MohToh location.
  // ---------------------------------------------------------------
  async function suggestLocation(kind) {
    const selection = lastSelection[kind];
    if (!selection) return;
    const suggestedName = window.prompt('Give this place a short, recognizable name (e.g. "Berger Bus Stop"):', selection.address.split(',')[0]);
    if (!suggestedName || !suggestedName.trim()) return;

    try {
      const data = await apiPost('../api/locations.php', { action: 'suggest', name: suggestedName.trim(), latitude: selection.lat, longitude: selection.lng, address: selection.address });
      showToast(data.message || (data.status === 'success' ? 'Submitted for review.' : 'Could not submit this location.'));
      if (data.status === 'success') {
        qs(kind === 'pickup' ? '#suggestPickupBtn' : '#suggestDestinationBtn')?.classList.add('u-hidden');
      }
    } catch (error) {
      showToast('Could not submit this location right now.');
    }
  }

  qs('#suggestPickupBtn')?.addEventListener('click', () => suggestLocation('pickup'));
  qs('#suggestDestinationBtn')?.addEventListener('click', () => suggestLocation('destination'));

  function onPickupSelected(selection) {
    lastSelection.pickup = selection;
    qs('#suggestPickupBtn')?.classList.toggle('u-hidden', selection.source !== 'external');
    maybeFetchPricePreview();
  }

  function onDestinationSelected(selection) {
    lastSelection.destination = selection;
    qs('#suggestDestinationBtn')?.classList.toggle('u-hidden', selection.source !== 'external');
    maybeFetchPricePreview();
  }

  // ---------------------------------------------------------------
  // Wallet — Wallet Home, Top Up, Payment Preference, Transaction
  // History. All backed by the api/wallet.php JSON endpoint built in
  // the financial-system stage; nothing here talks to the database
  // directly and no new backend logic is introduced.
  // ---------------------------------------------------------------
  const TX_CATEGORY_LABEL = {
    topup: 'Wallet Funding',
    ride_payment: 'Passenger Payment',
    ride_refund: 'Refund',
    driver_earning: 'Passenger Payment',
    commission: 'Trip Commission',
    admin_adjustment: 'Adjustment',
    COMMISSION_RESERVED: 'Commission Reserved',
    COMMISSION_RELEASED: 'Commission Released',
    COMMISSION_USED: 'Commission Collected',
    TRIP_CANCELLED_RELEASE: 'Reservation Released — Trip Cancelled',
    COMMISSION_SETTLED: 'Commission Settled'
  };

  let walletState = { balance: 0, available_balance: 0, reserved_balance: 0, active_reservations: [], payment_preference: 'wallet' };

  /** Sync a .choice-list's visual is-selected state to whichever radio is checked. */
  function wireChoiceList(container) {
    if (!container) return;
    container.addEventListener('change', () => {
      qsa('.choice-option', container).forEach((opt) => {
        opt.classList.toggle('is-selected', opt.querySelector('input')?.checked);
      });
    });
  }

  function setChoiceSelection(container, value) {
    if (!container) return;
    qsa('.choice-option', container).forEach((opt) => {
      const input = opt.querySelector('input');
      const isMatch = input?.value === value;
      if (input) input.checked = isMatch;
      opt.classList.toggle('is-selected', isMatch);
    });
  }

  function getChoiceSelection(container) {
    return container?.querySelector('input:checked')?.value || null;
  }

  wireChoiceList(qs('#topUpSheet .choice-list'));
  wireChoiceList(qs('#paymentPreferenceList'));
  wireChoiceList(qs('#rideCollectionList'));

  function renderWalletHero() {
    const amountEl = qs('#walletHeroAmount');
    const updatedEl = qs('#walletHeroUpdated');
    // The hero figure is what the driver can actually spend right now —
    // total wallet minus whatever's reserved for active trips.
    if (amountEl) amountEl.textContent = formatNaira(walletState.available_balance);
    if (updatedEl) updatedEl.textContent = `Last updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;

    const totalEl = qs('#walletTotalAmount');
    const reservedEl = qs('#walletReservedAmount');
    if (totalEl) totalEl.textContent = formatNaira(walletState.balance);
    if (reservedEl) reservedEl.textContent = formatNaira(walletState.reserved_balance);

    const listEl = qs('#walletActiveReservationsList');
    if (listEl) {
      const reservations = walletState.active_reservations || [];
      listEl.innerHTML = reservations.length
        ? reservations.map((r) => `
            <div class="info-row">
              <span class="info-row__label">${escapeHtml(r.destination || ('Ride ' + r.ride_public_id))}<br><span class="u-text-xs u-text-faint">Reserved ${formatNaira(r.reserved_amount)}${Number(r.used_amount) > 0 ? ' · used ' + formatNaira(r.used_amount) : ''}</span></span>
              <span class="info-row__value">${formatNaira(r.reserved_amount - r.used_amount - r.released_amount)}</span>
            </div>
          `).join('')
        : '<div class="u-text-center u-text-faint u-text-sm">No active trip reservations.</div>';
    }
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
      walletState.available_balance = Number(data.data.available_balance ?? data.data.balance ?? 0);
      walletState.reserved_balance = Number(data.data.reserved_balance || 0);
      walletState.active_reservations = Array.isArray(data.data.active_reservations) ? data.data.active_reservations : [];
      walletState.payment_preference = data.data.payment_preference || 'wallet';

      renderWalletHero();
      renderTransactionHistory(data.data.history || []);
      setChoiceSelection(qs('#paymentPreferenceList'), walletState.payment_preference);

      return data.data;
    } catch (error) {
      return null;
    }
  }

  qs('#walletReservedInfoBtn')?.addEventListener('click', () => {
    qs('#walletReservedInfo')?.classList.toggle('u-hidden');
  });

  // ---- Top Up ----
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
        // Full-page redirect to Paystack's hosted checkout — this app
        // never renders a card form itself. Paystack redirects back to
        // api/paystack-callback.php when done, which lands the driver
        // back on this dashboard with ?topup=success|failed|pending.
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

  // ---- Payment Preference ----
  const paymentPreferenceAlert = qs('#paymentPreferenceAlert');
  const savePaymentPreferenceBtn = qs('#savePaymentPreferenceBtn');

  savePaymentPreferenceBtn?.addEventListener('click', async () => {
    hideAlert(paymentPreferenceAlert);
    const value = getChoiceSelection(qs('#paymentPreferenceList'));
    if (!value) {
      showAlert(paymentPreferenceAlert, 'error', 'Please choose an option.');
      return;
    }

    setButtonLoading(savePaymentPreferenceBtn, true, 'Saving…');
    try {
      const data = await apiPost('../api/wallet.php', { action: 'set_payment_preference', payment_preference: value });
      if (data.status === 'success') {
        walletState.payment_preference = value;
        showToast('Payment preference saved');
        setTimeout(() => closeSheet(qs('#paymentPreferenceSheet'), qs('[data-sheet-scrim]')), 400);
      } else {
        showAlert(paymentPreferenceAlert, 'error', data.message || 'Could not save your preference.');
      }
    } catch (error) {
      showAlert(paymentPreferenceAlert, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(savePaymentPreferenceBtn, false);
    }
  });

  // ---- Withdraw — no backend withdrawal endpoint exists yet ----
  qs('#walletWithdrawBtn')?.addEventListener('click', () => showToast('Withdrawals are coming soon'));

  // ---------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------
  const dateInput = qs('#createRideDate');
  if (dateInput) dateInput.min = new Date().toISOString().split('T')[0];
  // Departure date affects holiday pricing and (via the day-of-week-
  // agnostic hour check) is part of the same live-recalculation set as
  // pickup/destination/time — only worth refetching once coordinates
  // already exist, same guard maybeFetchPricePreview applies itself.
  dateInput?.addEventListener('change', () => maybeFetchPricePreview());

  // MohToh always shows times as 12-hour AM/PM (Task 7) — the native
  // <input type="time"> value stays 24-hour under the hood (that's
  // what every browser gives you and what the DB stores), but the
  // driver only ever sees the AM/PM confirmation below it.
  const timeInput = qs('#createRideTime');
  const timePreview = qs('#createRideTimePreview');
  timeInput?.addEventListener('input', () => {
    if (timePreview) timePreview.textContent = timeInput.value ? formatTime12h(timeInput.value) : 'Pick a departure time';
  });
  // Departure time drives the night/peak multipliers directly — must
  // trigger a live recalculation, not just the display text above.
  timeInput?.addEventListener('change', () => maybeFetchPricePreview());

  initAddressAutocomplete(qs('#createRidePickup'), qs('#createRidePickupLat'), qs('#createRidePickupLng'), onPickupSelected);
  initAddressAutocomplete(qs('#createRideDestination'), qs('#createRideDestinationLat'), qs('#createRideDestinationLng'), onDestinationSelected);

  refreshVehicleInfo();
  loadMyRides();
  loadTodaySummary();
  refreshWallet();
  showReverseGeocodedLocation();
  startLocationTracking();

  qs('#recenterBtn')?.addEventListener('click', () => centerMapOnUser());

  // ---- Deep-link a sheet from another screen, e.g.
  // driver/profile.php's Vehicle card links to dashboard.php?open=vehicle
  // and its Wallet row links to dashboard.php?open=wallet.
  const openParam = new URLSearchParams(location.search).get('open');
  const OPEN_PARAM_SHEETS = { vehicle: 'vehicleVerificationSheet', wallet: 'walletSheet', 'create-ride': 'createRideSheet' };
  if (openParam && OPEN_PARAM_SHEETS[openParam]) {
    const target = qs(`#${OPEN_PARAM_SHEETS[openParam]}`);
    if (target) openSheet(target, qs('[data-sheet-scrim]'));
  }

  // Ride Requests (SYSTEM 1): driver/ride-requests.php's "Create Ride"
  // button links here with these params to pre-fill the create-ride
  // sheet from a passenger's request — seats/vehicle still come from
  // the driver's own approved vehicle (unchanged below), never from the
  // request, since the driver only creates supply, never approves a
  // specific passenger.
  if (openParam === 'create-ride') {
    const prefillParams = new URLSearchParams(location.search);
    const prefillPickup = prefillParams.get('prefill_pickup');
    const prefillDestination = prefillParams.get('prefill_destination');
    const prefillDate = prefillParams.get('prefill_date');
    const prefillPickupLat = prefillParams.get('prefill_pickup_lat');
    const prefillPickupLng = prefillParams.get('prefill_pickup_lng');
    const prefillDestinationLat = prefillParams.get('prefill_destination_lat');
    const prefillDestinationLng = prefillParams.get('prefill_destination_lng');

    if (prefillPickup) qs('#createRidePickup').value = prefillPickup;
    if (prefillDestination) qs('#createRideDestination').value = prefillDestination;
    if (prefillDate) qs('#createRideDate').value = prefillDate;

    if (prefillPickupLat) qs('#createRidePickupLat').value = prefillPickupLat;
    if (prefillPickupLng) qs('#createRidePickupLng').value = prefillPickupLng;
    if (prefillDestinationLat) qs('#createRideDestinationLat').value = prefillDestinationLat;
    if (prefillDestinationLng) qs('#createRideDestinationLng').value = prefillDestinationLng;
    
    // Trigger price preview automatically if coordinates exist
    if (prefillPickupLat && prefillPickupLng && prefillDestinationLat && prefillDestinationLng) {
      maybeFetchPricePreview();
    }
  }

  // ---- Paystack top-up result — api/paystack-callback.php redirects
  // here with ?topup=success|failed|pending after the hosted checkout.
  // This never credits anything itself; it only asks the backend
  // (already-verified by then) what actually happened, then reflects
  // that truth in the UI.
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

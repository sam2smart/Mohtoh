// assets/js/transparency-components.js - Reusable Transparency UI Components

const TransparencyUI = {
  renderSafetyBadge: (isVerified, label) => {
    return isVerified
      ? `<span class="badge bg-success-light text-success"><i class="bi bi-check-circle"></i> ${label} Verified</span>`
      : `<span class="badge bg-danger-light text-danger"><i class="bi bi-exclamation-triangle"></i> ${label} Unverified</span>`;
  },

  renderVehicleGallery: (photos) => {
    if (!photos || photos.length === 0) return '<div class="text-muted small">No photos available</div>';
    return `
      <div class="gallery-grid">
        ${photos.map(photo => `
          <img src="../img.php?f=${encodeURIComponent(photo)}&w=160" loading="lazy" class="img-thumbnail" style="width: 80px; height: 80px; object-fit: cover;" onclick="openImagePreview('../uploads/${escapeHtml(photo)}')">
        `).join('')}
      </div>
    `;
  },

  renderDriverProfileCard: (driver) => {
    return `
      <div class="card p-3 mb-3">
        <div class="d-flex align-items-center">
          <img src="${driver.profile_image ? '../img.php?f=' + encodeURIComponent(driver.profile_image) + '&w=160' : '../assets/images/default-avatar.png'}" class="rounded-circle" style="width: 60px; height: 60px;">
          <div class="ms-3">
            <h5 class="mb-0">${escapeHtml(driver.name)}</h5>
            <div class="small">⭐ ${driver.rating} · ${driver.completed_trips} trips</div>
            ${TransparencyUI.renderSafetyBadge(driver.is_verified, 'Driver')}
          </div>
        </div>
      </div>
    `;
  },

  renderPassengerRequestCard: (passenger) => {
    return `
      <div class="card p-3">
        <div class="d-flex align-items-center mb-3">
          <img src="${passenger.profile_image ? '../img.php?f=' + encodeURIComponent(passenger.profile_image) + '&w=160' : '../assets/images/default-avatar.png'}" class="rounded-circle" style="width: 50px; height: 50px;">
          <div class="ms-3">
            <h6 class="mb-0">${escapeHtml(passenger.fullname)}</h6>
            <div class="small">⭐ ${passenger.rating} · ${passenger.completed_trips} trips</div>
            ${TransparencyUI.renderSafetyBadge(passenger.is_verified, 'Passenger')}
          </div>
        </div>
        <div class="small mb-2"><strong>Destination:</strong> ${escapeHtml(passenger.destination)}</div>
        <div class="small mb-3"><strong>Payment Status:</strong> ${escapeHtml(passenger.payment_status)}</div>
        <div class="d-flex gap-2">
          <button class="btn btn-success flex-grow-1" onclick="handlePassengerAction(${passenger.id}, 'accept')">Accept</button>
          <button class="btn btn-danger flex-grow-1" onclick="handlePassengerAction(${passenger.id}, 'reject')">Reject</button>
        </div>
      </div>
    `;
  },
    renderFareIntelligence: (data) => {
    const isHigh = data.is_high ? 'text-danger' : 'text-success';
    const warning = data.is_high ? '<div class="small text-danger">Warning: This fare is higher than the average.</div>' : '';
    return `
      <div class="card p-3 mb-3">
        <h6 class="mb-2">Fare Intelligence</h6>
        <div class="d-flex justify-content-between">
          <span>Driver Fare:</span>
          <strong>₦${Number(data.driver_fare).toLocaleString()}</strong>
        </div>
        <div class="d-flex justify-content-between">
          <span>Community Avg:</span>
          <strong>₦${Number(data.community_avg).toLocaleString()}</strong>
        </div>
        <div class="d-flex justify-content-between ${isHigh}">
          <span>Difference:</span>
          <strong>${data.difference > 0 ? '+' : ''}₦${Number(data.difference).toLocaleString()}</strong>
        </div>
        <div class="small mt-2"><strong>Status:</strong> ${data.is_high ? 'High' : 'Normal'}</div>
        ${warning}
      </div>
    `;
  },
  renderRideModeBadge: (mode) => {
    return mode === 'private'
      ? `<span class="badge bg-warning-light text-warning"><i class="bi bi-lock-fill"></i> Private Ride</span>`
      : `<span class="badge bg-info-light text-info"><i class="bi bi-people-fill"></i> Shared Ride</span>`;
  },

  renderWaitingBreakdown: (data) => {
    return `
      <div class="card p-3 mb-3">
        <h6 class="mb-2">Waiting Summary</h6>
        <div class="d-flex justify-content-between">
          <span>Free Waiting Time:</span>
          <strong>${data.free_minutes} minutes</strong>
        </div>
        <div class="d-flex justify-content-between">
          <span>Actual Waiting:</span>
          <strong>${Math.floor(data.actual_seconds / 60)} minutes</strong>
        </div>
        <div class="d-flex justify-content-between">
          <span>Chargeable Waiting:</span>
          <strong>${Math.max(0, Math.floor(data.actual_seconds / 60) - data.free_minutes)} minutes</strong>
        </div>
        <div class="d-flex justify-content-between text-danger">
          <span>Waiting Fee:</span>
          <strong>₦${Number(data.fee).toLocaleString()}</strong>
        </div>
      </div>
    `;
  }
};

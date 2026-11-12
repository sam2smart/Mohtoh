// assets/js/profile.js
// Shared logic for rider/profile.php and driver/profile.php. Both pages
// fetch the same api/profile.php bundle; role-specific render functions
// simply no-op when their section isn't present in the current page's DOM.

const PROFILE_VEHICLE_STATUS_META = {
  pending: { emoji: '🟡', label: 'Pending Review', pillClass: 'pending' },
  approved: { emoji: '🟢', label: 'Verified', pillClass: 'approved' },
  rejected: { emoji: '🔴', label: 'Rejected', pillClass: 'rejected' }
};

document.addEventListener('DOMContentLoaded', () => {
  let profile = null;

  loadProfile();

  async function loadProfile() {
    try {
      const data = await apiGet('../api/profile.php');
      if (data.status !== 'success') throw new Error(data.message || 'Failed to load profile');
      profile = data.data;
      renderCommon(profile);
      if (profile.user_type === 'driver') {
        renderDriver(profile);
      } else {
        renderRider(profile);
        loadSavedLocations();
      }
    } catch (error) {
      showToast('Could not load your profile.');
    }
  }

  function setText(sel, value) {
    const el = qs(sel);
    if (el) el.textContent = (value === null || value === undefined || value === '') ? '—' : value;
  }
  function setValue(sel, value) {
    const el = qs(sel);
    if (el) el.value = value ?? '';
  }
  function avatarUrl(p) {
    return p.profile_image && p.profile_image !== 'default.png'
      ? `../img.php?f=${encodeURIComponent(p.profile_image)}&w=160`
      : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.fullname)}&background=0F8B8D&color=fff`;
  }

  function renderCommon(p) {
    const avatarEl = qs('#profileAvatar');
    if (avatarEl) avatarEl.src = avatarUrl(p);
    const avatarPreview = qs('#editProfileAvatarPreview');
    if (avatarPreview) avatarPreview.src = avatarUrl(p);

    setText('#profileName', p.fullname);
    setText('#profileUserId', p.user_id || '—');
    setText('#profilePhone', p.phone);
    setText('#profileEmail', p.email);
    setText('#profileMemberSince', p.created_at
      ? new Date(p.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
      : '—');
    setText('#profileRating', p.rating !== null ? p.rating.toFixed(1) : '—');
    setText('#trustCompletedTrips', p.completed_rides ?? 0);
    setText('#trustMemberSince', p.created_at
      ? new Date(p.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
      : '—');

    const accountState = p.is_suspended ? 'Suspended' : (p.user_type === 'driver' && !p.is_verified ? 'Pending verification' : 'Active');
    setText('#trustAccountStatus', accountState);

    const statusPill = qs('#profileStatusPill');
    if (statusPill) {
      if (p.is_suspended) {
        statusPill.textContent = 'Suspended';
        statusPill.className = 'status-pill rejected';
      } else if (p.user_type === 'driver' && !p.is_verified) {
        statusPill.textContent = 'Pending verification';
        statusPill.className = 'status-pill pending';
      } else {
        statusPill.textContent = 'Active';
        statusPill.className = 'status-pill approved';
      }
    }

    setValue('#editFullname', p.fullname);
    setValue('#editPhone', p.phone);
    setValue('#editEmail', p.email);

    qsa('[data-notify-toggle]').forEach((el) => {
      el.checked = !!p[el.dataset.notifyToggle];
    });

    // Driver account-settings sheet: prefilled here (rather than only on
    // sheet-open) since both checkboxes and the preference radios are
    // cheap to set from the bundle we already have.
    const notifyBooking = qs('#notifyOnBooking');
    if (notifyBooking) notifyBooking.checked = !!p.notify_on_booking;
    const notifyRating = qs('#notifyOnRating');
    if (notifyRating) notifyRating.checked = !!p.notify_on_rating;
    const prefRadio = qs(`#paymentPreferenceList input[value="${p.payment_preference}"]`);
    if (prefRadio) {
      prefRadio.checked = true;
      prefRadio.closest('.choice-option')?.classList.add('is-selected');
    }
  }

  function renderRider(p) {
    setText('#riderWalletBalance', formatNaira(p.wallet_balance));
  }

  function renderDriver(p) {
    setText('#driverWalletBalance', formatNaira(p.wallet_balance));

    const vehicle = p.vehicle;
    setText('#vehicleCardName', vehicle ? `${vehicle.make} ${vehicle.model}` : 'No vehicle registered');
    setText('#vehicleCardPlate', vehicle ? vehicle.plate_number : 'Tap to set up your vehicle');
    setText('#vehicleCardSeats', vehicle ? `${vehicle.seats} seats · ${vehicle.public_id || '—'}` : '');

    const statusEl = qs('#vehicleCardStatus');
    if (statusEl) {
      if (!vehicle) {
        statusEl.textContent = '';
        statusEl.className = 'status-pill';
      } else {
        const meta = PROFILE_VEHICLE_STATUS_META[vehicle.status] || PROFILE_VEHICLE_STATUS_META.pending;
        statusEl.textContent = meta.label;
        statusEl.className = `status-pill ${meta.pillClass}`;
      }
    }
  }

  // ---------------------------------------------------------------
  // Edit profile sheet — fullname/phone require current_password
  // (server-enforced in api/profile.php; mirrored here so the UI never
  // claims success then fails).
  // ---------------------------------------------------------------
  const editForm = qs('#editProfileForm');
  const editAlert = qs('#editProfileAlert');
  const editPhotoInput = qs('#editProfileImageInput');

  editPhotoInput?.addEventListener('change', () => {
    const file = editPhotoInput.files?.[0];
    const preview = qs('#editProfileAvatarPreview');
    if (file && preview) preview.src = URL.createObjectURL(file);
  });

  editForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideAlert(editAlert);
    if (!profile) return;

    const fullname = qs('#editFullname')?.value.trim() || '';
    const phone = qs('#editPhone')?.value.trim() || '';
    const email = qs('#editEmail')?.value.trim() || '';
    const currentPassword = qs('#editCurrentPassword')?.value || '';
    const hasPhoto = editPhotoInput?.files?.length > 0;

    const nameChanged = fullname !== profile.fullname;
    const phoneChanged = phone !== profile.phone;
    const emailChanged = email !== profile.email;

    if ((nameChanged || phoneChanged) && !currentPassword) {
      showAlert(editAlert, 'error', 'Please confirm your current password to change your name or phone number.');
      return;
    }
    if (!nameChanged && !phoneChanged && !emailChanged && !hasPhoto) {
      showAlert(editAlert, 'error', 'No changes to save.');
      return;
    }

    const formData = new FormData();
    if (nameChanged) formData.append('fullname', fullname);
    if (phoneChanged) formData.append('phone', phone);
    if (emailChanged) formData.append('email', email);
    if (nameChanged || phoneChanged) formData.append('current_password', currentPassword);
    if (hasPhoto) formData.append('profile_image', editPhotoInput.files[0]);

    const submitBtn = qs('#editProfileSubmitBtn');
    setButtonLoading(submitBtn, true, 'Saving…');
    try {
      const data = await apiUpload('../api/profile.php', formData);
      if (data.status !== 'success') {
        showAlert(editAlert, 'error', data.message || 'Could not update profile.');
        return;
      }
      if (qs('#editCurrentPassword')) qs('#editCurrentPassword').value = '';
      await loadProfile();
      closeSheet(qs('#editProfileSheet'), qs('[data-sheet-scrim]'));
      showToast('Profile updated');
    } catch (error) {
      showAlert(editAlert, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(submitBtn, false);
    }
  });

  // ---------------------------------------------------------------
  // Rider notification toggles — instant (matches api/profile.php's
  // existing "not sensitive" treatment of these flags)
  // ---------------------------------------------------------------
  qsa('[data-notify-toggle]').forEach((toggle) => {
    toggle.addEventListener('change', async () => {
      try {
        const data = await apiPost('../api/profile.php', { [toggle.dataset.notifyToggle]: toggle.checked ? 1 : 0 });
        if (data.status !== 'success') throw new Error(data.message);
        showToast('Preference saved');
      } catch (error) {
        toggle.checked = !toggle.checked;
        showToast('Could not save preference');
      }
    });
  });

  // ---------------------------------------------------------------
  // Driver account-settings sheet — explicit Save buttons rather than
  // instant-per-field, since this sheet also holds Payment Preference
  // (already its own settled action on api/wallet.php).
  // ---------------------------------------------------------------
  qs('#savePaymentPreferenceBtn')?.addEventListener('click', async () => {
    const selected = qs('#paymentPreferenceList input[name="payment_preference"]:checked');
    const alertEl = qs('#settingsAlert');
    if (!selected) {
      showAlert(alertEl, 'error', 'Choose a payment preference first.');
      return;
    }
    const btn = qs('#savePaymentPreferenceBtn');
    setButtonLoading(btn, true, 'Saving…');
    try {
      const data = await apiPost('../api/wallet.php', { action: 'set_payment_preference', payment_preference: selected.value });
      if (data.status !== 'success') {
        showAlert(alertEl, 'error', data.message || 'Could not save preference.');
        return;
      }
      showToast('Payment preference saved');
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#saveSettingsBtn')?.addEventListener('click', async () => {
    const alertEl = qs('#settingsAlert');
    const btn = qs('#saveSettingsBtn');
    setButtonLoading(btn, true, 'Saving…');
    try {
      const data = await apiPost('../api/profile.php', {
        notify_on_booking: qs('#notifyOnBooking')?.checked ? 1 : 0,
        notify_on_rating: qs('#notifyOnRating')?.checked ? 1 : 0
      });
      if (data.status !== 'success') {
        showAlert(alertEl, 'error', data.message || 'Could not save settings.');
        return;
      }
      showToast('Settings saved');
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#paymentPreferenceList')?.addEventListener('change', (e) => {
    if (e.target.name !== 'payment_preference') return;
    qsa('.choice-option', qs('#paymentPreferenceList')).forEach((opt) => opt.classList.remove('is-selected'));
    e.target.closest('.choice-option')?.classList.add('is-selected');
  });

  // ---------------------------------------------------------------
  // Driver earnings sheet — lazy-loaded the first time it's opened
  // ---------------------------------------------------------------
  let earningsLoaded = false;
  qs('[data-open-sheet-id="earningsSheet"]')?.addEventListener('click', async () => {
    if (earningsLoaded) return;
    earningsLoaded = true;
    try {
      const data = await apiGet('../api/profile.php', { action: 'earnings' });
      if (data.status !== 'success') throw new Error(data.message);
      setText('#earningsWeekValue', formatNaira(data.data.week));
      setText('#earningsMonthValue', formatNaira(data.data.month));
      setText('#earningsTotalValue', formatNaira(data.data.total));
      const list = qs('#earningsHistoryList');
      if (list) {
        const trips = data.data.recent_trips || [];
        list.innerHTML = trips.length
          ? trips.map((t) => `
              <div class="info-row">
                <span class="info-row__label">${escapeHtml(t.destination)}<br><span class="u-text-xs u-text-faint">${t.confirmed_at ? new Date(t.confirmed_at).toLocaleDateString() : ''}</span></span>
                <span class="info-row__value">+${formatNaira(t.driver_earning_amount)}</span>
              </div>
            `).join('')
          : '<div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No completed trips yet.</div>';
      }
      renderAdvancedEarningsReport(data.data);
    } catch (error) {
      earningsLoaded = false;
      const list = qs('#earningsHistoryList');
      if (list) list.innerHTML = '<div class="alert-banner error">Could not load earnings.</div>';
    }
  });

  // Part 2 (Premium Driver Features) — "Advanced Earnings Report":
  // locked behind the premium catalog unless the driver has an active
  // approved request for it. Reuses the same earnings figures already
  // fetched above rather than a second endpoint.
  async function renderAdvancedEarningsReport(earnings) {
    const container = qs('#advancedEarningsReport');
    if (!container) return;
    try {
      const data = await apiGet('../api/premium.php', { action: 'catalog' });
      if (data.status !== 'success') return;
      const feature = data.data.features.find((f) => f.feature_name === 'Advanced Earnings Report');
      if (!feature) return;

      if (feature.unlocked) {
        const trips = earnings.recent_trips || [];
        const avg = trips.length ? trips.reduce((sum, t) => sum + parseFloat(t.driver_earning_amount || 0), 0) / trips.length : 0;
        container.innerHTML = `
          <div class="card" style="padding:var(--space-4);background:var(--color-primary-50);">
            <div class="u-flex u-items-center u-gap-2 u-mb-2"><i class="bi bi-award-fill" style="color:var(--color-accent-600);"></i><strong class="u-text-sm">Advanced Earnings Report</strong></div>
            <div class="info-row"><span class="info-row__label">Average per completed trip</span><span class="info-row__value">${formatNaira(avg)}</span></div>
            <div class="info-row"><span class="info-row__label">Trips counted</span><span class="info-row__value">${trips.length}</span></div>
          </div>`;
      } else {
        container.innerHTML = `
          <div class="card" style="padding:var(--space-4);">
            <div class="u-flex u-items-center u-justify-between">
              <div><strong class="u-text-sm">Advanced Earnings Report</strong><div class="u-text-xs u-text-faint">Detailed breakdowns and trends — Premium feature</div></div>
              <button type="button" class="btn btn-outline btn-sm" style="width:auto;" data-open-sheet-id="premiumCenterSheet">Upgrade</button>
            </div>
          </div>`;
      }
    } catch (error) { /* leave the section empty on failure — non-critical */ }
  }

  // ---------------------------------------------------------------
  // Premium Center sheet — catalog + current plan + request flow.
  // Reloaded fresh every time the sheet opens (not lazy-loaded once
  // like earnings/ratings above) so locked/unlocked state never goes
  // stale after an admin approves/rejects/disables a request.
  // ---------------------------------------------------------------
  async function loadPremiumCenter() {
    try {
      const data = await apiGet('../api/premium.php', { action: 'catalog' });
      if (data.status !== 'success') throw new Error(data.message);

      const isPremium = data.data.current_plan === 'premium';
      setText('#premiumCurrentPlan', isPremium ? 'Premium' : 'Basic');
      const pill = qs('#profilePremiumPill');
      if (pill) { pill.textContent = isPremium ? 'Premium' : 'Basic'; pill.className = 'status-pill ' + (isPremium ? 'approved' : ''); }

      const walletBalance = Number(data.data.wallet_balance || 0);
      setText('#premiumWalletBalance', `₦${walletBalance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);

      const list = qs('#premiumFeatureList');
      if (list) {
        list.innerHTML = data.data.features.map((f) => {
          const affordable = walletBalance >= Number(f.price);
          let action;
          if (f.unlocked) {
            action = '<span class="status-pill approved">Active</span>';
          } else if (f.my_status === 'pending') {
            action = '<span class="status-pill">Pending</span>';
          } else if (!affordable) {
            action = '<span class="status-pill" style="color:var(--color-danger);">Top up to unlock</span>';
          } else {
            action = `<button type="button" class="btn btn-primary btn-sm" style="width:auto;" data-request-feature="${f.id}">Upgrade</button>`;
          }
          return `
          <div class="card u-mb-2" style="padding:var(--space-4);">
            <div class="u-flex u-items-center u-justify-between u-gap-2">
              <div style="min-width:0;">
                <div class="u-flex u-items-center u-gap-2"><i class="bi ${f.unlocked ? 'bi-check-circle-fill' : 'bi-lock-fill'}" style="color:${f.unlocked ? 'var(--color-success)' : 'var(--color-text-faint)'};"></i><strong class="u-text-sm">${escapeHtml(f.feature_name)}</strong></div>
                <div class="u-text-xs u-text-faint">${escapeHtml(f.description || '')}</div>
                <div class="u-text-xs u-text-soft">₦${Number(f.price).toLocaleString()} · ${f.duration_days} days</div>
              </div>
              ${action}
            </div>
          </div>
        `;
        }).join('');
      }
    } catch (error) {
      showToast('Could not load Premium Center.');
    }
  }

  qs('[data-open-sheet-id="premiumCenterSheet"]')?.addEventListener('click', () => {
    loadPremiumCenter();
  });
  qs('#premiumFeatureList')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-request-feature]');
    if (!btn) return;
    setButtonLoading(btn, true, 'Requesting…');
    try {
      const res = await postJson('../api/premium.php', { action: 'request', feature_id: btn.dataset.requestFeature });
      showToast(res.message || (res.status === 'success' ? 'Request submitted.' : 'Could not submit request.'));
      if (res.status === 'success') loadPremiumCenter();
      else setButtonLoading(btn, false);
    } catch (error) {
      showToast('Something went wrong.');
      setButtonLoading(btn, false);
    }
  });
  // Always keep the profile menu's plan pill current, even before the
  // sheet itself is ever opened. Premium Center is a driver-only
  // feature (api/premium.php returns an "Unauthorized" catalog for any
  // non-driver caller) — only fire this on pages that actually have the
  // pill/sheet, so a rider's profile page never triggers it and never
  // trips api-client.js's global auth-failure redirect for a rider who
  // is, in fact, correctly logged in.
  if (qs('#profilePremiumPill')) loadPremiumCenter();

  // ---------------------------------------------------------------
  // Driver ratings-received sheet — lazy-loaded the first time it's
  // opened. Backed by the live rider→driver ride_share_ratings pipeline.
  // ---------------------------------------------------------------
  let ratingsLoaded = false;
  qs('[data-open-sheet-id="ratingsReceivedSheet"]')?.addEventListener('click', async () => {
    if (ratingsLoaded) return;
    ratingsLoaded = true;
    try {
      const data = await apiGet('../api/profile.php', { action: 'ratings' });
      if (data.status !== 'success') throw new Error(data.message);
      setText('#ratingsAverageValue', data.data.average !== null ? data.data.average.toFixed(1) : '—');
      setText('#ratingsCountValue', data.data.count ?? 0);
      const list = qs('#ratingsReceivedList');
      if (list) {
        const ratings = data.data.ratings || [];
        list.innerHTML = ratings.length
          ? ratings.map((r) => `
              <div class="rating-item">
                <div class="rating-item__stars">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</div>
                ${r.comment ? `<div class="rating-item__comment">${escapeHtml(r.comment)}</div>` : ''}
                <div class="rating-item__meta">${escapeHtml(r.rider_name)} · ${new Date(r.created_at).toLocaleDateString()}</div>
              </div>
            `).join('')
          : '<div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No ratings yet.</div>';
      }
    } catch (error) {
      ratingsLoaded = false;
      const list = qs('#ratingsReceivedList');
      if (list) list.innerHTML = '<div class="alert-banner error">Could not load ratings.</div>';
    }
  });

  // ---------------------------------------------------------------
  // Saved locations (rider only)
  // ---------------------------------------------------------------
  const savedLocationsList = qs('#savedLocationsList');
  const addLocationForm = qs('#addLocationForm');

  async function loadSavedLocations() {
    if (!savedLocationsList) return;
    try {
      const data = await apiGet('../api/saved-locations.php', { action: 'list' });
      const locations = data?.data?.locations || [];
      savedLocationsList.innerHTML = locations.length
        ? locations.map((loc) => `
            <div class="saved-location-item">
              <i class="bi bi-geo-alt-fill" style="color:var(--color-primary);"></i>
              <div class="saved-location-item__body">
                <div class="saved-location-item__label">${escapeHtml(loc.label)}</div>
                <div class="saved-location-item__address">${escapeHtml(loc.address)}</div>
              </div>
              <button type="button" class="saved-location-item__delete" data-delete-location="${escapeHtml(loc.public_id)}" aria-label="Delete"><i class="bi bi-trash"></i></button>
            </div>
          `).join('')
        : '<div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No saved locations yet.</div>';
    } catch (error) {
      savedLocationsList.innerHTML = '<div class="alert-banner error">Could not load saved locations.</div>';
    }
  }

  savedLocationsList?.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-delete-location]');
    if (!btn) return;
    try {
      const data = await apiPost('../api/saved-locations.php', { action: 'delete', public_id: btn.dataset.deleteLocation });
      if (data.status !== 'success') throw new Error(data.message);
      loadSavedLocations();
    } catch (error) {
      showToast('Could not remove location');
    }
  });

  if (addLocationForm) {
    const addressInput = qs('#addLocationAddress');
    const latInput = qs('#addLocationLat');
    const lngInput = qs('#addLocationLng');
    if (typeof initAddressAutocomplete === 'function' && addressInput) {
      initAddressAutocomplete(addressInput, latInput, lngInput, () => {});
    }

    addLocationForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const alertEl = qs('#addLocationAlert');
      hideAlert(alertEl);
      const label = qs('#addLocationLabel')?.value.trim();
      const address = addressInput?.value.trim();
      const lat = latInput?.value;
      const lng = lngInput?.value;
      if (!label || !address || !lat || !lng) {
        showAlert(alertEl, 'error', 'Pick an address from the suggestions and give it a label.');
        return;
      }
      const btn = qs('#addLocationSubmitBtn');
      setButtonLoading(btn, true, 'Saving…');
      try {
        const data = await apiPost('../api/saved-locations.php', { action: 'add', label, address, latitude: lat, longitude: lng });
        if (data.status !== 'success') {
          showAlert(alertEl, 'error', data.message || 'Could not save location.');
          return;
        }
        addLocationForm.reset();
        loadSavedLocations();
        closeSheet(qs('#addLocationSheet'), qs('[data-sheet-scrim]'));
        showToast('Location saved');
      } catch (error) {
        showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
      } finally {
        setButtonLoading(btn, false);
      }
    });
  }

  // ---------------------------------------------------------------
  // Connected accounts (Google / Facebook social login) — present on
  // both rider and driver profile pages. "Connect" is a full-page
  // navigation because the OAuth authorization-code flow requires a
  // top-level redirect (never an XHR). "Disconnect" posts to
  // api/social-accounts.php with the app CSRF token carried in the JSON
  // body — the server also re-checks the token, rate-limits, and refuses
  // to remove the user's only remaining sign-in method.
  // ---------------------------------------------------------------
  const connectedAccountsList = qs('#connectedAccountsList');

  function providerIcon(provider) {
    if (provider === 'google') return '<i class="bi bi-google" style="color:#ea4335;"></i>';
    if (provider === 'facebook') return '<i class="bi bi-facebook" style="color:#1877f2;"></i>';
    return '<i class="bi bi-box-arrow-in-right"></i>';
  }

  async function loadConnectedAccounts() {
    if (!connectedAccountsList) return;
    try {
      const data = await apiGet('../api/social-accounts.php', { action: 'list' });
      if (data.status !== 'success') throw new Error(data.message);
      renderConnectedAccounts(data.data);
    } catch (error) {
      connectedAccountsList.innerHTML = '<div class="alert-banner error">Could not load connected accounts.</div>';
    }
  }

  function renderConnectedAccounts(d) {
    if (!connectedAccountsList) return;
    const providers = (d && d.providers) || [];
    // Show a provider only when it is already connected (so it can be
    // removed) or currently available to connect. A provider that is
    // neither is hidden rather than shown as a dead control.
    const rows = providers.filter((p) => p.connected || p.available);
    if (!rows.length) {
      connectedAccountsList.innerHTML = '<div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No social login options are available right now.</div>';
      return;
    }
    connectedAccountsList.innerHTML = rows.map((p) => {
      let action;
      if (p.connected) {
        action = p.can_unlink
          ? `<button type="button" class="btn btn-outline btn-sm" style="width:auto;" data-unlink-provider="${escapeHtml(p.provider)}">Disconnect</button>`
          : '<span class="u-text-xs u-text-faint" title="Set a password before removing your only way to sign in.">Only sign-in method</span>';
      } else {
        // Anchor, not a button: OAuth linking needs a full-page redirect.
        action = `<a class="btn btn-outline btn-sm" style="width:auto;text-decoration:none;" href="${escapeHtml(p.link_url)}">Connect</a>`;
      }
      const sub = p.connected ? (p.email ? escapeHtml(p.email) : 'Connected') : 'Not connected';
      return `
        <div class="info-row" style="align-items:center;">
          <span class="info-row__label u-flex u-items-center u-gap-2">${providerIcon(p.provider)} <span>${escapeHtml(p.label)}<br><span class="u-text-xs u-text-faint">${sub}</span></span></span>
          <span class="info-row__value">${action}</span>
        </div>`;
    }).join('');
  }

  connectedAccountsList?.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-unlink-provider]');
    if (!btn) return;
    const provider = btn.dataset.unlinkProvider;
    if (!confirm(`Disconnect your ${provider} account from MohToh?`)) return;
    const token = qs('meta[name="csrf-token"]')?.content || '';
    setButtonLoading(btn, true, 'Removing…');
    try {
      const data = await apiPost('../api/social-accounts.php', { action: 'unlink', provider, csrf_token: token });
      if (data.status !== 'success') {
        showToast(data.message || 'Could not disconnect that account.');
        setButtonLoading(btn, false);
        return;
      }
      showToast(data.message || 'Account disconnected.');
      renderConnectedAccounts(data.data); // server returns the fresh list
    } catch (error) {
      showToast('Something went wrong. Please try again.');
      setButtonLoading(btn, false);
    }
  });

  // Kick off the initial fetch here — after `connectedAccountsList` above
  // is initialized — rather than alongside loadProfile() at the top, where
  // that const would still be in its temporal dead zone.
  loadConnectedAccounts();

  // ---- Deep-link a sheet from elsewhere, e.g. partials/sidebar.php's
  // Settings/Ratings links (profile.php?open=settings / ?open=ratings),
  // same convention as driver.js's dashboard.php?open=vehicle/wallet.
  const openParam = new URLSearchParams(location.search).get('open');
  const OPEN_PARAM_SHEETS = { settings: 'accountSettingsSheet', ratings: 'ratingsReceivedSheet', premium: 'premiumCenterSheet' };
  if (openParam && OPEN_PARAM_SHEETS[openParam]) {
    const target = qs(`#${OPEN_PARAM_SHEETS[openParam]}`);
    if (target) { openSheet(target, qs('[data-sheet-scrim]')); if (openParam === 'premium') loadPremiumCenter(); }
  }
});

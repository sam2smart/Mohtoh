// assets/js/network.js
// MOHTOH NETWORK client — replaces assets/js/referrals.js. Built on
// apiGet/apiPost (assets/js/api-client.js) and mtState (assets/js/
// state.js), same as every other sheet client in this app. Owns
// #networkSheet's content only; open/close is the generic
// [data-open-sheet-id] handler already wired in assets/js/navigation.js.
// Loads the qrcodejs library lazily (only once a partner actually opens
// the QR view) so the extra script never slows down the common case.
// davidshimjs/qrcodejs renders INTO a container element (producing its
// own <canvas>/<img> child) rather than a toCanvas()-style static API.

document.addEventListener('DOMContentLoaded', () => {
  const sheet = qs('#networkSheet');
  if (!sheet) return; // page doesn't include the MOHTOH NETWORK sheet

  // CSRF-protected POST — every state-changing call to api/network.php
  // carries the session's token as a `csrf_token` body field, same
  // convention assets/js/profile.js already uses against
  // api/social-accounts.php (includes/functions.php's require_csrf()
  // reads it from there). The page must render
  // <meta name="csrf-token" content="..."> for this to be non-empty —
  // every page that includes this sheet does.
  function csrfToken() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
  function postNetwork(url, payload) { return apiPost(url, { ...payload, csrf_token: csrfToken() }); }

  const announcementEl = qs('#netAnnouncement');
  const joinSection = qs('#netJoinSection');
  const pendingSection = qs('#netPendingSection');
  const suspendedSection = qs('#netSuspendedSection');
  const suspendedReasonEl = qs('#netSuspendedReason');
  const dashboard = qs('#netDashboard');
  const termsTextEl = qs('#netTermsText');
  const acceptTermsBox = qs('#netAcceptTerms');
  const joinBtn = qs('#netJoinBtn');
  const joinAlert = qs('#netJoinAlert');

  const codeEl = qs('#netCode');
  const qrCanvas = qs('#netQrCanvas');
  const copyLinkBtn = qs('#netCopyLinkBtn');
  const whatsappBtn = qs('#netWhatsappBtn');
  const smsBtn = qs('#netSmsBtn');
  const nativeShareBtn = qs('#netNativeShareBtn');
  const showQrBtn = qs('#netShowQrBtn');
  const downloadQrBtn = qs('#netDownloadQrBtn');

  const statTotal = qs('#netStatTotal');
  const statActive = qs('#netStatActive');
  const statConverted = qs('#netStatConverted');
  const statExpired = qs('#netStatExpired');
  const walletPending = qs('#netWalletPending');
  const walletAvailable = qs('#netWalletAvailable');
  const walletWithdrawn = qs('#netWalletWithdrawn');

  const requestWithdrawalBtn = qs('#netRequestWithdrawalBtn');
  const withdrawalForm = qs('#netWithdrawalForm');
  const accountSelect = qs('#netWithdrawalAccountSelect');
  const addAccountToggle = qs('#netAddAccountToggle');
  const addAccountFields = qs('#netAddAccountFields');
  const newBankName = qs('#netNewBankName');
  const newAccountNumber = qs('#netNewAccountNumber');
  const newAccountName = qs('#netNewAccountName');
  const saveAccountBtn = qs('#netSaveAccountBtn');
  const withdrawalAmount = qs('#netWithdrawalAmount');
  const submitWithdrawalBtn = qs('#netSubmitWithdrawalBtn');
  const withdrawalAlert = qs('#netWithdrawalAlert');

  const referralsList = qs('#netReferralsList');
  const referralsEmpty = qs('#netReferralsEmpty');
  const rewardsList = qs('#netRewardsList');
  const rewardsEmpty = qs('#netRewardsEmpty');
  const withdrawalsList = qs('#netWithdrawalsList');
  const withdrawalsEmpty = qs('#netWithdrawalsEmpty');

  let currentJoinUrl = null;
  let qrLibPromise = null;

  function loadQrLib() {
    if (window.QRCode) return Promise.resolve();
    if (qrLibPromise) return qrLibPromise;
    qrLibPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('qrcode-load-failed'));
      document.head.appendChild(s);
    });
    return qrLibPromise;
  }

  function showAlert(el, message, ok) {
    if (!el) return;
    el.textContent = message;
    el.classList.remove('u-hidden');
    el.style.color = ok ? 'var(--color-success)' : 'var(--color-danger)';
  }

  const REFERRAL_STATUS_PILL = {
    registered: 'cr-status-pill--registered', verified: 'cr-status-pill--verified',
    active: 'cr-status-pill--qualified', expired: 'cr-status-pill--cancelled',
    cancelled: 'cr-status-pill--cancelled', legacy_migrated: 'cr-status-pill--registered',
  };
  const REFERRAL_STATUS_LABEL = {
    registered: 'Registered', verified: 'Verified', active: 'Earning', expired: 'Expired',
    cancelled: 'Cancelled', legacy_migrated: 'Legacy',
  };
  const REWARD_STATUS_PILL = {
    pending: 'cr-status-pill--registered', available: 'cr-status-pill--qualified',
    withdrawn: 'cr-status-pill--rewarded', reversed: 'cr-status-pill--cancelled', suspended: 'cr-status-pill--cancelled',
  };
  const WITHDRAWAL_STATUS_PILL = {
    pending: 'cr-status-pill--registered', under_review: 'cr-status-pill--verified', approved: 'cr-status-pill--verified',
    processing: 'cr-status-pill--verified', paid: 'cr-status-pill--rewarded',
    rejected: 'cr-status-pill--cancelled', failed: 'cr-status-pill--cancelled', reversed: 'cr-status-pill--cancelled',
  };

  function renderReferralRow(r) {
    const pill = REFERRAL_STATUS_PILL[r.status] || 'cr-status-pill--registered';
    const label = REFERRAL_STATUS_LABEL[r.status] || r.status;
    const icon = r.user_type === 'driver' ? 'bi-car-front' : 'bi-person';
    return `
      <div class="cr-list-item">
        <span class="cr-list-item__icon"><i class="bi ${icon}"></i></span>
        <span class="cr-list-item__body">
          <span class="cr-list-item__title">${escapeHtml(r.fullname)}</span>
          <span class="cr-list-item__meta">${r.referred_category === 'driver' ? 'Driver' : 'Passenger'} · ${r.qualifying_ride_count} qualifying ride(s) · ₦${Number(r.total_rewards_generated).toLocaleString()} earned</span>
        </span>
        <span class="cr-status-pill ${pill}">${label}</span>
      </div>`;
  }

  function renderRewardRow(r) {
    const pill = REWARD_STATUS_PILL[r.status] || 'cr-status-pill--registered';
    const roleLabel = r.beneficiary_role === 'driver_referrer' ? 'from a referred driver' : 'from a referred passenger';
    return `
      <div class="cr-list-item">
        <span class="cr-list-item__icon"><i class="bi bi-gift"></i></span>
        <span class="cr-list-item__body">
          <span class="cr-list-item__title">₦${Number(r.reward_amount).toLocaleString()}</span>
          <span class="cr-list-item__meta">${r.reward_pct}% of ₦${Number(r.commission_base).toLocaleString()} commission, ${roleLabel} · ${new Date(r.created_at).toLocaleDateString()}</span>
        </span>
        <span class="cr-status-pill ${pill}">${r.status}</span>
      </div>`;
  }

  function renderWithdrawalRow(w) {
    const pill = WITHDRAWAL_STATUS_PILL[w.status] || 'cr-status-pill--registered';
    return `
      <div class="cr-list-item">
        <span class="cr-list-item__icon"><i class="bi bi-bank"></i></span>
        <span class="cr-list-item__body">
          <span class="cr-list-item__title">₦${Number(w.amount).toLocaleString()}</span>
          <span class="cr-list-item__meta">${escapeHtml(w.bank_name)} ••${escapeHtml(w.account_number_last4)} · ${new Date(w.requested_at).toLocaleDateString()}${w.rejection_reason ? ' · ' + escapeHtml(w.rejection_reason) : ''}</span>
        </span>
        <span class="cr-status-pill ${pill}">${w.status.replace('_', ' ')}</span>
      </div>`;
  }

  async function loadDashboardData() {
    const [statsRes, referralsRes, rewardsRes, withdrawalsRes, accountsRes] = await Promise.all([
      mtDedupeRequest('network:stats', () => apiGet('../api/network.php', { action: 'stats' })),
      mtDedupeRequest('network:referrals', () => apiGet('../api/network.php', { action: 'referrals' })),
      mtDedupeRequest('network:reward_history', () => apiGet('../api/network.php', { action: 'reward_history' })),
      mtDedupeRequest('network:withdrawal_history', () => apiGet('../api/network.php', { action: 'withdrawal_history' })),
      mtDedupeRequest('network:withdrawal_accounts', () => apiGet('../api/network.php', { action: 'withdrawal_accounts' })),
    ]);

    if (statsRes.status === 'success') {
      const s = statsRes.data;
      if (statTotal) statTotal.textContent = s.total_referrals;
      if (statActive) statActive.textContent = s.active_referrals;
      if (statConverted) statConverted.textContent = s.converted_first_ride;
      if (statExpired) statExpired.textContent = s.expired_referrals;
      if (walletPending) walletPending.textContent = '₦' + Number(s.pending_balance).toLocaleString();
      if (walletAvailable) walletAvailable.textContent = '₦' + Number(s.available_balance).toLocaleString();
      if (walletWithdrawn) walletWithdrawn.textContent = '₦' + Number(s.withdrawn_balance).toLocaleString();
    }

    if (referralsList && referralsRes.status === 'success') {
      const referrals = referralsRes.data.referrals || [];
      referralsList.innerHTML = referrals.map(renderReferralRow).join('');
      referralsEmpty?.classList.toggle('u-hidden', referrals.length > 0);
    }

    if (rewardsList && rewardsRes.status === 'success') {
      const rewards = rewardsRes.data.rewards || [];
      rewardsList.innerHTML = rewards.map(renderRewardRow).join('');
      rewardsEmpty?.classList.toggle('u-hidden', rewards.length > 0);
    }

    if (withdrawalsList && withdrawalsRes.status === 'success') {
      const withdrawals = withdrawalsRes.data.withdrawals || [];
      withdrawalsList.innerHTML = withdrawals.map(renderWithdrawalRow).join('');
      withdrawalsEmpty?.classList.toggle('u-hidden', withdrawals.length > 0);
    }

    if (accountSelect && accountsRes.status === 'success') {
      const accounts = accountsRes.data.accounts || [];
      accountSelect.innerHTML = accounts.map(a => `<option value="${a.id}">${escapeHtml(a.bank_name)} ••${escapeHtml(a.account_number_last4)} — ${escapeHtml(a.account_name)}</option>`).join('')
        || '<option value="">No saved accounts — add one below</option>';
    }
  }

  async function setupSharing(partner) {
    const res = await postNetwork('../api/network.php', { action: 'share_link' });
    if (res.status !== 'success') return;
    const { join_url, share_text } = res.data;
    currentJoinUrl = join_url;
    mtState.set('networkJoinUrl', join_url);

    copyLinkBtn?.setAttribute('data-join-url', join_url);
    if (whatsappBtn) whatsappBtn.href = 'https://wa.me/?text=' + encodeURIComponent(share_text);
    if (smsBtn) smsBtn.href = 'sms:?body=' + encodeURIComponent(share_text);

    if (navigator.share) {
      nativeShareBtn?.classList.remove('u-hidden');
      nativeShareBtn?.addEventListener('click', () => {
        window.MohToh?.share.shareLink(join_url, 'MOHTOH NETWORK', share_text);
      }, { once: true });
    }
  }

  async function renderQr() {
    if (!currentJoinUrl || !qrCanvas) return;
    try {
      await loadQrLib();
      qrCanvas.innerHTML = '';
      new window.QRCode(qrCanvas, { text: currentJoinUrl, width: 160, height: 160, correctLevel: window.QRCode.CorrectLevel.M });
      qrCanvas.style.display = 'block';
      downloadQrBtn?.classList.remove('u-hidden');
    } catch (e) {
      showToast('Could not load QR code — check your connection.');
    }
  }

  async function loadNetworkSheet() {
    const res = await apiGet('../api/network.php', { action: 'status' });
    if (res.status !== 'success') return;
    const { is_partner, partner, terms_text, announcement } = res.data;

    if (announcement && announcementEl) {
      announcementEl.textContent = announcement;
      announcementEl.classList.remove('u-hidden');
    } else {
      announcementEl?.classList.add('u-hidden');
    }

    joinSection?.classList.add('u-hidden');
    pendingSection?.classList.add('u-hidden');
    suspendedSection?.classList.add('u-hidden');
    dashboard?.classList.add('u-hidden');

    if (!is_partner) {
      if (termsTextEl) termsTextEl.textContent = terms_text || '';
      joinSection?.classList.remove('u-hidden');
      return;
    }
    if (partner.status === 'pending') {
      pendingSection?.classList.remove('u-hidden');
      return;
    }
    if (partner.status === 'suspended') {
      if (suspendedReasonEl) suspendedReasonEl.textContent = partner.suspension_reason || '';
      suspendedSection?.classList.remove('u-hidden');
      return;
    }
    // approved
    dashboard?.classList.remove('u-hidden');
    if (codeEl) codeEl.textContent = partner.referral_code;
    await Promise.all([loadDashboardData(), setupSharing(partner)]);
  }

  joinBtn?.addEventListener('click', async () => {
    if (!acceptTermsBox?.checked) {
      showAlert(joinAlert, 'Please accept the programme terms to continue.', false);
      return;
    }
    joinBtn.disabled = true;
    const res = await postNetwork('../api/network.php', { action: 'join', accept_terms: true });
    joinBtn.disabled = false;
    showAlert(joinAlert, res.message || (res.status === 'success' ? 'Done.' : 'Failed.'), res.status === 'success');
    if (res.status === 'success') loadNetworkSheet();
  });

  copyLinkBtn?.addEventListener('click', async () => {
    const url = copyLinkBtn.getAttribute('data-join-url');
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      showToast('Referral link copied!');
    } catch (error) {
      showToast('Could not copy — long-press to select and copy manually.');
    }
  });

  showQrBtn?.addEventListener('click', renderQr);
  downloadQrBtn?.addEventListener('click', () => {
    // qrcodejs renders either a <canvas> (modern browsers) or an <img>
    // (its own canvas->dataURL fallback) inside the container — grab
    // whichever is present rather than assuming one or the other.
    const canvas = qrCanvas?.querySelector('canvas');
    const img = qrCanvas?.querySelector('img');
    const dataUrl = canvas ? canvas.toDataURL('image/png') : img?.src;
    if (!dataUrl) return;
    const link = document.createElement('a');
    link.download = 'mohtoh-network-qr.png';
    link.href = dataUrl;
    link.click();
  });

  requestWithdrawalBtn?.addEventListener('click', () => {
    withdrawalForm?.classList.toggle('u-hidden');
  });
  addAccountToggle?.addEventListener('click', () => {
    addAccountFields?.classList.toggle('u-hidden');
  });
  saveAccountBtn?.addEventListener('click', async () => {
    const res = await postNetwork('../api/network.php', {
      action: 'add_withdrawal_account',
      bank_name: newBankName?.value.trim(),
      account_number: newAccountNumber?.value.trim(),
      account_name: newAccountName?.value.trim(),
    });
    if (res.status === 'success') {
      showToast('Bank account saved.');
      if (newBankName) newBankName.value = '';
      if (newAccountNumber) newAccountNumber.value = '';
      if (newAccountName) newAccountName.value = '';
      addAccountFields?.classList.add('u-hidden');
      await loadDashboardData();
    } else {
      showAlert(withdrawalAlert, res.message || 'Could not save account.', false);
    }
  });
  submitWithdrawalBtn?.addEventListener('click', async () => {
    const accountId = accountSelect?.value;
    const amount = parseFloat(withdrawalAmount?.value || '0');
    if (!accountId) {
      showAlert(withdrawalAlert, 'Add or select a bank account first.', false);
      return;
    }
    if (!(amount > 0)) {
      showAlert(withdrawalAlert, 'Enter a valid amount.', false);
      return;
    }
    const res = await postNetwork('../api/network.php', { action: 'request_withdrawal', withdrawal_account_id: accountId, amount });
    showAlert(withdrawalAlert, res.message || (res.status === 'success' ? 'Requested.' : 'Failed.'), res.status === 'success');
    if (res.status === 'success') {
      if (withdrawalAmount) withdrawalAmount.value = '';
      withdrawalForm?.classList.add('u-hidden');
      await loadDashboardData();
    }
  });

  // Reload every time the sheet is (re)opened, same "always fresh on
  // open" behavior as the notification/messages/wallet sheets.
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open-sheet-id="networkSheet"]');
    if (!opener) return;
    loadNetworkSheet();
  });

  // Active-menu highlighting, same convention the old Grow My Community
  // link used for the sidebar entry.
  const sidebarLink = qs('#sidebarNetworkLink');
  if (sidebarLink) {
    const networkObserver = new MutationObserver(() => {
      sidebarLink.classList.toggle('is-active', sheet.classList.contains('is-open'));
    });
    networkObserver.observe(sheet, { attributes: true, attributeFilter: ['class'] });
  }
});

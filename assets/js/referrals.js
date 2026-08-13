// assets/js/referrals.js
// MohToh Community Rewards client. Built on apiGet/apiPost
// (assets/js/api-client.js) and mtState (assets/js/state.js) per this
// feature's explicit "use existing frontend service layer" requirement
// — no raw fetch() calls here. Owns #communityRewardsSheet's content
// only; open/close is the generic [data-open-sheet-id] handler already
// wired in assets/js/navigation.js.

document.addEventListener('DOMContentLoaded', () => {
  const sheet = qs('#communityRewardsSheet');
  if (!sheet) return; // page doesn't include the Community Rewards sheet

  const codeEl = qs('#crCode');
  const copyLinkBtn = qs('#crCopyLinkBtn');
  const whatsappBtn = qs('#crWhatsappBtn');
  const smsBtn = qs('#crSmsBtn');
  const nativeShareBtn = qs('#crNativeShareBtn');
  const statInvited = qs('#crStatInvited');
  const statActive = qs('#crStatActive');
  const statTrips = qs('#crStatTrips');
  const statRewards = qs('#crStatRewards');
  const referralsList = qs('#crReferralsList');
  const referralsEmpty = qs('#crReferralsEmpty');
  const rewardsList = qs('#crRewardsList');
  const rewardsEmpty = qs('#crRewardsEmpty');

  const STATUS_LABELS = {
    REGISTERED: 'Registered', VERIFIED: 'Verified', QUALIFIED: 'Qualified',
    REWARDED: 'Rewarded', CANCELLED: 'Cancelled',
  };
  const TYPE_ICON = { RIDER: 'bi-person', DRIVER: 'bi-car-front' };

  function renderReferralRow(r) {
    const statusClass = 'cr-status-pill--' + r.status.toLowerCase();
    return `
      <div class="cr-list-item">
        <span class="cr-list-item__icon"><i class="bi ${TYPE_ICON[r.referral_type] || 'bi-person'}"></i></span>
        <span class="cr-list-item__body">
          <span class="cr-list-item__title">${escapeHtml(r.fullname)}</span>
          <span class="cr-list-item__meta">${r.referral_type === 'DRIVER' ? 'Driver' : 'Rider'} · ${r.trips_generated} trip(s) generated</span>
        </span>
        <span class="cr-status-pill ${statusClass}">${STATUS_LABELS[r.status] || r.status}</span>
      </div>`;
  }

  function renderRewardRow(r) {
    const statusClass = 'cr-status-pill--' + (r.status === 'PAID' ? 'rewarded' : r.status.toLowerCase());
    return `
      <div class="cr-list-item">
        <span class="cr-list-item__icon"><i class="bi bi-gift"></i></span>
        <span class="cr-list-item__body">
          <span class="cr-list-item__title">₦${Number(r.amount).toLocaleString()}</span>
          <span class="cr-list-item__meta">${new Date(r.created_at).toLocaleDateString()}</span>
        </span>
        <span class="cr-status-pill ${statusClass}">${r.status}</span>
      </div>`;
  }

  async function loadCommunityRewards() {
    // mtDedupeRequest (state.js) means a rapid re-open of the sheet
    // never fires the same set of requests twice while one is in flight.
    const [codeRes, statsRes, referralsRes, rewardsRes] = await Promise.all([
      mtDedupeRequest('referrals:my_code', () => apiGet('../api/referrals.php', { action: 'my_code' })),
      mtDedupeRequest('referrals:statistics', () => apiGet('../api/referrals.php', { action: 'statistics' })),
      mtDedupeRequest('referrals:my_referrals', () => apiGet('../api/referrals.php', { action: 'my_referrals' })),
      mtDedupeRequest('referrals:reward_history', () => apiGet('../api/referrals.php', { action: 'reward_history' })),
    ]);

    if (codeEl && codeRes.status === 'success') {
      codeEl.textContent = codeRes.data.referral_code;
      mtState.set('referralJoinUrl', codeRes.data.join_url);
    }

    if (statsRes.status === 'success') {
      const stats = statsRes.data;
      mtState.set('referralStats', stats);
      if (statInvited) statInvited.textContent = stats.invited;
      if (statActive) statActive.textContent = stats.active;
      if (statTrips) statTrips.textContent = stats.trips_generated;
      if (statRewards) statRewards.textContent = '₦' + Number(stats.total_rewards).toLocaleString();
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
  }

  async function setupSharing() {
    const res = await apiPost('../api/referrals.php', { action: 'share_link' });
    if (res.status !== 'success') return;
    const { join_url, share_text, whatsapp_url, sms_url } = res.data;

    copyLinkBtn?.setAttribute('data-join-url', join_url);
    if (whatsappBtn) whatsappBtn.href = whatsapp_url;
    if (smsBtn) smsBtn.href = sms_url;

    if (navigator.share) {
      nativeShareBtn?.classList.remove('u-hidden');
      nativeShareBtn?.addEventListener('click', () => {
        window.MohToh?.share.shareLink(join_url, 'Join MohToh', share_text);
      }, { once: true });
    }
  }

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

  // Reload every time the sheet is (re)opened, same "always fresh on
  // open" behavior as the notification and messages sheets.
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open-sheet-id="communityRewardsSheet"]');
    if (!opener) return;
    loadCommunityRewards();
    setupSharing();
  });

  // Active-menu highlighting: the sidebar's "Grow My Community" link
  // (the single canonical entry point) shows as active while this
  // sheet is open, mirroring the bottom-nav's is-active convention.
  const sidebarLink = qs('#sidebarGrowCommunityLink');
  if (sidebarLink) {
    const growCommunityObserver = new MutationObserver(() => {
      sidebarLink.classList.toggle('is-active', sheet.classList.contains('is-open'));
    });
    growCommunityObserver.observe(sheet, { attributes: true, attributeFilter: ['class'] });
  }
});

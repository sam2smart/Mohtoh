// assets/js/verify-account.js
// verify-account.php — the mandatory gate between login/registration
// and the dashboard. All calls go to api/verification.php (session-
// based) which wraps includes/verification.php's send/verify functions.
//
// Channel awareness: the server renders ONLY the enabled channel's card
// (disabled channels have no input, button, timer, or resend action at
// all — see verify-account.php). This file mirrors that by tracking
// email_channel_enabled / phone_channel_enabled from the status endpoint
// and refusing to render, request, or act on a disabled channel.

document.addEventListener('DOMContentLoaded', () => {
  // Current channel state from api/verification.php?action=status.
  // Starts optimistically true so a pre-status moment never misfires;
  // the first status call corrects it. A disabled channel means the
  // element doesn't even exist in the DOM — handlers stay inert.
  const state = { emailChannelEnabled: true, phoneChannelEnabled: true };

  function markVerified(kind) {
    const icon = qs(`#${kind}StatusIcon`);
    const pill = qs(`#${kind}StatusPill`);
    if (icon) { icon.classList.remove('is-pending'); icon.classList.add('is-done'); icon.innerHTML = '<i class="bi bi-check-lg"></i>'; }
    if (pill) { pill.textContent = 'Verified'; pill.className = 'status-pill approved'; }
  }

  async function refreshStatus(silent = false) {
    try {
      const data = await apiGet('api/verification.php', { action: 'status' });
      if (data.status !== 'success') return;

      // Update channel state from the server — this is the single source
      // of truth for whether a channel is enabled.
      if (typeof data.data.email_channel_enabled === 'boolean') state.emailChannelEnabled = data.data.email_channel_enabled;
      if (typeof data.data.phone_channel_enabled === 'boolean') state.phoneChannelEnabled = data.data.phone_channel_enabled;

      if (data.data.email_verified) markVerified('email');
      if (data.data.phone_verified) markVerified('phone');

      // A disabled channel counts as complete for navigation only — it
      // never changes the verified flag, mirroring verify-account.php's
      // server-side emailComplete/phoneComplete gate.
      const emailComplete = data.data.email_verified || state.emailChannelEnabled === false;
      const phoneComplete = data.data.phone_verified || state.phoneChannelEnabled === false;

      if (emailComplete && phoneComplete) {
        if (!silent) showToast('All set! Taking you to your dashboard…');
        // index.php bounces an already-logged-in session straight to
        // {user_type}/dashboard.php via its own top-of-file redirect.
        setTimeout(() => { window.location.href = 'index.php'; }, 900);
      }
    } catch (error) { /* silent — next poll/manual refresh tries again */ }
  }

  qs('#refreshStatusBtn')?.addEventListener('click', () => refreshStatus());

  // Each handler is inert unless its channel is enabled AND its element
  // exists — a disabled channel has neither, so nothing can be requested.
  qs('#resendEmailBtn')?.addEventListener('click', async () => {
    if (!state.emailChannelEnabled) return;
    const btn = qs('#resendEmailBtn');
    const alertEl = qs('#emailAlert');
    hideAlert(alertEl);
    setButtonLoading(btn, true, 'Sending…');
    try {
      const data = await apiPost('api/verification.php', { action: 'resend_email' });
      showAlert(alertEl, data.status === 'success' ? 'success' : 'error', data.message);
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#resendOtpBtn')?.addEventListener('click', async () => {
    if (!state.phoneChannelEnabled) return;
    const btn = qs('#resendOtpBtn');
    const alertEl = qs('#otpAlert');
    hideAlert(alertEl);
    setButtonLoading(btn, true, 'Sending…');
    try {
      const data = await apiPost('api/verification.php', { action: 'resend_otp' });
      showAlert(alertEl, data.status === 'success' ? 'success' : 'error', data.message);
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#verifyOtpBtn')?.addEventListener('click', async () => {
    if (!state.phoneChannelEnabled) return;
    const btn = qs('#verifyOtpBtn');
    const alertEl = qs('#otpAlert');
    const otp = qs('#otpInput')?.value.trim();
    hideAlert(alertEl);
    if (!otp || otp.length !== 6) {
      showAlert(alertEl, 'error', 'Please enter the 6-digit code.');
      return;
    }
    setButtonLoading(btn, true, 'Verifying…');
    try {
      const data = await apiPost('api/verification.php', { action: 'verify_otp', otp });
      if (data.status === 'success') {
        markVerified('phone');
        showAlert(alertEl, 'success', data.message);
        refreshStatus(true);
      } else {
        showAlert(alertEl, 'error', data.message);
      }
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#logoutBtn')?.addEventListener('click', async () => {
    try {
      const formData = new FormData();
      formData.append('action', 'logout');
      const data = await apiUpload('api/auth.php', formData);
      window.location.href = data?.data?.redirect || 'index.php';
    } catch (error) {
      window.location.href = 'index.php';
    }
  });

  // Catches the case where the user verified email in another tab
  // (clicked the emailed link) without needing a manual refresh here.
  setInterval(() => refreshStatus(true), 8000);
});

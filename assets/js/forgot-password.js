// assets/js/forgot-password.js
// forgot-password.php — Step 1 (identify) -> Step 2 (OTP, phone channel
// only) -> Step 3 (new password) -> Step 4 (success). The email channel
// skips straight from Step 1 to a "check your inbox" Step 4; the rest
// of that path happens on reset-password.php (the emailed link).

document.addEventListener('DOMContentLoaded', () => {
  let identifier = '';
  let isEmailChannel = false;
  let verifiedOtp = '';
  // Populated from api/password-reset.php?action=channels. Default both
  // true so a slow/failed fetch never wrongly blocks a working channel;
  // the server re-checks on submit regardless.
  let channelState = { email_channel_enabled: true, phone_channel_enabled: true };

  (async () => {
    try {
      const data = await apiGet('api/password-reset.php', { action: 'channels' });
      if (data.status === 'success') {
        channelState = data.data;
        renderChannelHint();
      }
    } catch (error) { /* keep defaults — server still enforces on submit */ }
  })();

  // Surfaces which reset methods are available, and only when at least one
  // is disabled (nothing to say when both are on).
  function renderChannelHint() {
    const hint = qs('#resetChannelHint');
    if (!hint) return;
    const emailOn = channelState.email_channel_enabled !== false;
    const phoneOn = channelState.phone_channel_enabled !== false;
    if (emailOn && phoneOn) { hint.classList.add('is-hidden'); return; }
    let msg;
    if (!emailOn && !phoneOn) {
      msg = 'Password reset is currently unavailable. Please contact support.';
    } else if (!emailOn) {
      msg = 'Email reset is currently disabled — use the phone number on your account.';
    } else {
      msg = 'Phone reset is currently disabled — use the email address on your account.';
    }
    hint.textContent = msg;
    hint.classList.remove('is-hidden');
  }

  function showStep(id) {
    ['stepIdentify', 'stepOtp', 'stepNewPassword', 'stepSuccess'].forEach((stepId) => {
      qs(`#${stepId}`)?.classList.toggle('u-hidden', stepId !== id);
    });
  }

  qs('#identifyForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertEl = qs('#identifyAlert');
    hideAlert(alertEl);
    identifier = qs('#identifierInput')?.value.trim() || '';
    isEmailChannel = identifier.includes('@');

    if (!identifier) {
      showAlert(alertEl, 'error', 'Please enter your email or phone number.');
      return;
    }

    // Don't let the user proceed down a disabled channel — the server
    // would reject it anyway, this just gives a clearer, earlier message.
    if (isEmailChannel && channelState.email_channel_enabled === false) {
      showAlert(alertEl, 'error', 'Email verification is currently disabled. Please use the phone number on your account.');
      return;
    }
    if (!isEmailChannel && channelState.phone_channel_enabled === false) {
      showAlert(alertEl, 'error', 'Phone verification is currently disabled. Please use the email address on your account.');
      return;
    }

    const btn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(btn, true, 'Sending…');
    try {
      const data = await apiPost('api/password-reset.php', { action: 'request', identifier });
      if (data.status !== 'success') {
        showAlert(alertEl, 'error', data.message);
        return;
      }

      if (isEmailChannel) {
        qs('#successHeading').textContent = 'Check your inbox';
        qs('#successMessage').textContent = data.message;
        showStep('stepSuccess');
      } else {
        showStep('stepOtp');
      }
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#verifyResetOtpBtn')?.addEventListener('click', async () => {
    const alertEl = qs('#otpAlert');
    hideAlert(alertEl);
    const otp = qs('#resetOtpInput')?.value.trim();
    if (!otp || otp.length !== 6) {
      showAlert(alertEl, 'error', 'Please enter the 6-digit code.');
      return;
    }

    const btn = qs('#verifyResetOtpBtn');
    setButtonLoading(btn, true, 'Verifying…');
    try {
      const data = await apiPost('api/password-reset.php', { action: 'verify_otp', identifier, otp });
      if (data.status === 'success') {
        verifiedOtp = otp;
        showStep('stepNewPassword');
      } else {
        showAlert(alertEl, 'error', data.message);
      }
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });

  qs('#newPasswordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertEl = qs('#newPasswordAlert');
    hideAlert(alertEl);
    const newPassword = qs('#newPasswordInput')?.value || '';
    const confirmPassword = qs('#confirmPasswordInput')?.value || '';

    if (newPassword.length < 6) {
      showAlert(alertEl, 'error', 'Password must be at least 6 characters.');
      return;
    }
    if (newPassword !== confirmPassword) {
      showAlert(alertEl, 'error', 'Passwords do not match.');
      return;
    }

    const btn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(btn, true, 'Saving…');
    try {
      const data = await apiPost('api/password-reset.php', { action: 'reset', identifier, proof: verifiedOtp, new_password: newPassword, confirm_password: confirmPassword });
      if (data.status === 'success') {
        qs('#successHeading').textContent = 'Password reset!';
        qs('#successMessage').textContent = data.message;
        showStep('stepSuccess');
      } else {
        showAlert(alertEl, 'error', data.message);
      }
    } catch (error) {
      showAlert(alertEl, 'error', 'Something went wrong. Please try again.');
    } finally {
      setButtonLoading(btn, false);
    }
  });
});

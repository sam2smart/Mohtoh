// assets/js/reset-password.js
// reset-password.php — the emailed-link landing page. The token in the
// hidden #resetToken field IS the identity proof for this channel; no
// OTP step needed here (that's the phone channel's job, handled
// entirely on forgot-password.php).

document.addEventListener('DOMContentLoaded', () => {
  qs('#newPasswordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertEl = qs('#newPasswordAlert');
    hideAlert(alertEl);

    const newPassword = qs('#newPasswordInput')?.value || '';
    const confirmPassword = qs('#confirmPasswordInput')?.value || '';
    const identifier = qs('#resetIdentifier')?.value || '';
    const token = qs('#resetToken')?.value || '';

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
      const data = await apiPost('api/password-reset.php', { action: 'reset', identifier, proof: token, new_password: newPassword, confirm_password: confirmPassword });
      if (data.status === 'success') {
        qs('#stepNewPassword')?.classList.add('u-hidden');
        qs('#stepSuccess')?.classList.remove('u-hidden');
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

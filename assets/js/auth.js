// assets/js/auth.js
// Redesigned presentation layer for the login/register bottom sheets on
// index.php. The wire contract with the backend is unchanged from the
// previous js/auth.js: POST a FormData body (with an `action` field of
// `login` or `register`) to api/auth.php, and expect
// { status, message, data: { redirect } } back.

document.addEventListener('DOMContentLoaded', () => {
  const scrim = qs('[data-sheet-scrim]');
  const sheets = { login: qs('#sheetLogin'), register: qs('#sheetRegister') };

  // ---- Sheet open/close wiring ----
  qsa('[data-open-sheet]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      const target = sheets[trigger.dataset.openSheet];
      openSheet(target, scrim);
    });
  });

  qsa('[data-close-sheet]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      const target = trigger.closest('.sheet');
      closeSheet(target, scrim);
    });
  });

  scrim?.addEventListener('click', () => {
    closeSheet(sheets.login, scrim);
    closeSheet(sheets.register, scrim);
  });

  // Switch directly from one sheet to the other without closing the scrim
  qsa('[data-switch-sheet]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      const current = trigger.closest('.sheet');
      const target = sheets[trigger.dataset.switchSheet];
      current?.classList.remove('is-open');
      target?.classList.add('is-open');
    });
  });

  // ---- Password visibility toggles ----
  qsa('.field-toggle-visibility').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = btn.previousElementSibling;
      if (!input) return;
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  });

  // ---- MohToh Community Rewards: prefill + validate a ?ref= code ----
  // join.php redirects here with ?ref=CODE preserved; this only ever
  // reads the URL, never blocks registration if the code is missing or
  // invalid (api/referrals.php's validate_code action just reports
  // "not found" and the hidden field is left blank).
  const refCode = new URLSearchParams(window.location.search).get('ref');
  if (refCode) {
    const hiddenInput = qs('#registerReferralCode');
    const banner = qs('#registerReferralBanner');
    if (hiddenInput) hiddenInput.value = refCode;
    apiGet('api/referrals.php', { action: 'validate_code', code: refCode })
      .then((data) => {
        if (data.status === 'success' && banner) {
          banner.textContent = `Invited by ${data.data.inviter_name} — create your account to get started.`;
          banner.classList.remove('is-hidden');
        }
      })
      .catch(() => {});
    // Auto-open the register sheet so the invite link lands directly on
    // the form instead of the landing page behind it.
    openSheet(sheets.register, scrim);
  }

  // ---- Account type (Rider / Driver / Keke Driver) ----
  // "Keke Driver" is a third VISIBLE choice but not a third auth role: the
  // account is an ordinary 'driver' whose declared vehicle class is keke.
  // The select therefore carries the UI choice, and submit-time translation
  // (below) maps keke -> user_type=driver + driver_class=keke, which is the
  // contract api/auth.php already validates. No new backend field.
  const userTypeSelect = qs('#registerUserType');
  const userTypeHint = qs('#registerUserTypeHint');

  const USER_TYPE_HINTS = {
    rider: 'Find and join rides published by drivers.',
    driver: 'Publish rides and fill seats on Join Ride trips.',
    keke: 'Join Ride trips plus Private Solo Pickup, once your keke is verified.',
  };

  function syncUserTypeHint() {
    if (!userTypeHint || !userTypeSelect) return;
    userTypeHint.textContent = USER_TYPE_HINTS[userTypeSelect.value] || '';
  }

  userTypeSelect?.addEventListener('change', syncUserTypeHint);
  syncUserTypeHint();

  // ---- Login ----
  const loginForm = qs('#loginForm');
  loginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(loginForm);
    formData.append('action', 'login');
    const submitBtn = loginForm.querySelector('button[type="submit"]');
    await handleAuthRequest(formData, qs('#loginAlert'), submitBtn, 'Log In');
  });

  // ---- Register ----
  const registerForm = qs('#registerForm');
  registerForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertBox = qs('#registerAlert');

    const fullname = registerForm.fullname.value.trim();
    const phone = registerForm.phone.value.trim();
    const email = registerForm.email.value.trim();
    const password = registerForm.password.value;
    const confirmPassword = registerForm.confirm_password.value;

    if (fullname.length < 3 || !/^[a-zA-Z\s]+$/.test(fullname)) {
      showAlert(alertBox, 'error', 'Full name must be at least 3 letters, no numbers or symbols.');
      return;
    }
    if (!/^[\d+][\d\s]{6,14}$/.test(phone)) {
      showAlert(alertBox, 'error', 'Please enter a valid phone number (e.g. 08012345678 or +2348012345678).');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showAlert(alertBox, 'error', 'Please enter a valid email address.');
      return;
    }
    if (password.length < 6) {
      showAlert(alertBox, 'error', 'Password must be at least 6 characters.');
      return;
    }
    if (password !== confirmPassword) {
      showAlert(alertBox, 'error', 'Password and confirm password do not match.');
      return;
    }

    const formData = new FormData(registerForm);
    formData.append('action', 'register');

    // Translate the UI choice into the roles the backend actually has.
    // 'keke' is a driver account with driver_class=keke; 'driver' is a
    // driver account with driver_class=car. api/auth.php rejects a driver
    // with no driver_class, so this must be set for both driver options.
    const chosenType = formData.get('user_type');
    if (chosenType === 'keke') {
      formData.set('user_type', 'driver');
      formData.set('driver_class', 'keke');
    } else if (chosenType === 'driver') {
      formData.set('driver_class', 'car');
    }

    const submitBtn = registerForm.querySelector('button[type="submit"]');
    await handleAuthRequest(formData, alertBox, submitBtn, 'Create Account');
  });

  async function handleAuthRequest(formData, alertBox, submitBtn, originalLabel) {
    hideAlert(alertBox);
    setButtonLoading(submitBtn, true, 'Please wait…');

    try {
      const result = await apiUpload('api/auth.php', formData);

      if (result.status === 'success') {
        showAlert(alertBox, 'success', result.message);
        setTimeout(() => { window.location.href = result.data.redirect; }, 700);
      } else {
        showAlert(alertBox, 'error', result.message);
        setButtonLoading(submitBtn, false);
        if (submitBtn) submitBtn.textContent = originalLabel;
      }
    } catch (error) {
      showAlert(alertBox, 'error', 'An error occurred. Please try again.');
      setButtonLoading(submitBtn, false);
      if (submitBtn) submitBtn.textContent = originalLabel;
    }
  }
});

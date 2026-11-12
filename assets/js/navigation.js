// assets/js/navigation.js
// Wiring shared by every screen that includes partials/app-header.php,
// partials/sidebar.php and partials/bottom-nav.php: the hamburger
// drawer, the notification bell panel (same api/notifications.php
// polling contract the old sidebar dropdown used), "coming soon"
// affordances, and logout.

document.addEventListener('DOMContentLoaded', () => {
  // ---- Sidebar drawer ----
  const sidebar = qs('#sidebarDrawer');
  const sidebarScrim = qs('#sidebarScrim');
  const hamburgerBtn = qs('#hamburgerBtn');
  const sidebarCloseBtn = qs('#sidebarCloseBtn');

  const openSidebar = () => {
    sidebar?.classList.add('is-open');
    sidebarScrim?.classList.add('is-open');
    sidebar?.setAttribute('aria-hidden', 'false');
    hamburgerBtn?.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  };
  const closeSidebar = () => {
    sidebar?.classList.remove('is-open');
    sidebarScrim?.classList.remove('is-open');
    sidebar?.setAttribute('aria-hidden', 'true');
    hamburgerBtn?.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  };

  hamburgerBtn?.addEventListener('click', openSidebar);
  sidebarCloseBtn?.addEventListener('click', closeSidebar);
  sidebarScrim?.addEventListener('click', closeSidebar);

  // ---- "Coming soon" affordances (Become Driver, Waiting List, etc.) ----
  document.addEventListener('click', (e) => {
    const soonTrigger = e.target.closest('[data-soon]');
    if (!soonTrigger) return;
    e.preventDefault();
    showToast(soonTrigger.dataset.soon || 'Coming soon');
  });

  // ---- Logout (same contract as before: POST action=logout to api/auth.php) ----
  // api/auth.php reads $_POST directly (no JSON body support), so this
  // stays a FormData post — apiUpload sends FormData as-is without
  // JSON-stringifying it, same as apiPost does for everything else.
  qs('#sidebarLogoutBtn')?.addEventListener('click', async () => {
    const formData = new FormData();
    formData.append('action', 'logout');
    const data = await apiUpload('../api/auth.php', formData, { onAuthFailure: () => {} }); // already logging out — no auth-failure redirect loop
    window.location.href = data?.data?.redirect || '../index.php';
  });

  // ---- Notification bell + bottom sheet (partials/notification-sheet.php) ----
  // The bell itself opens via the generic [data-open-sheet-id] handler
  // below (data-open-sheet-id="notificationSheet" in app-header.php);
  // this block only owns the badge count and the sheet's own content —
  // list, filters, mark-read, delete, infinite scroll, pull-to-refresh.
  const notifBtn = qs('#notificationBtn');
  const notifDot = qs('#notificationDot');
  const notifSheet = qs('#notificationSheet');
  const notifSheetList = qs('#notifSheetList');
  const notifSheetEmpty = qs('#notifSheetEmpty');
  const notifSheetCount = qs('#notifSheetCount');
  const notifSheetLoadMore = qs('#notifSheetLoadMore');

  const NOTIF_ICON_BY_TYPE = {
    ride: 'bi-signpost-split', booking: 'bi-ticket-perforated', trip: 'bi-compass',
    wallet: 'bi-wallet2', payment: 'bi-credit-card', verification: 'bi-patch-check',
    vehicle: 'bi-car-front', admin: 'bi-megaphone', promotion: 'bi-gift',
    security: 'bi-shield-exclamation', system: 'bi-info-circle', support: 'bi-headset',
    premium: 'bi-gem', emergency: 'bi-exclamation-triangle-fill', gps: 'bi-geo-alt',
  };
  const NOTIF_ICON_TONE = { security: 'is-danger', emergency: 'is-danger', wallet: 'is-success', payment: 'is-success', promotion: 'is-accent', premium: 'is-accent' };
  // Priority Management System — display ordering/behavior is server-
  // driven (api/notifications.php sorts critical→high→normal→low), this
  // just maps each tier to its visual treatment. Kept in lockstep with
  // includes/functions.php's notification_priority_catalog() badge_class.
  const NOTIF_PRIORITY_BADGE = { critical: 'is-critical', high: 'is-high' }; // normal/low get no badge — subtle by default

  let notifFilter = 'all';
  let notifCursor = null; // created_at of the last row loaded, for infinite scroll
  let notifCursorRank = null; // paired priority rank for that same row — see api/notifications.php's compound cursor
  let notifLoading = false;

  function renderNotifRow(n) {
    const icon = n.icon || NOTIF_ICON_BY_TYPE[n.notification_type] || 'bi-bell';
    const tone = NOTIF_ICON_TONE[n.notification_type] || '';
    const unread = !n.is_read;
    const priority = n.priority || 'normal';
    const priorityClass = priority === 'critical' ? 'is-priority-critical' : priority === 'high' ? 'is-priority-high' : priority === 'low' ? 'is-priority-low' : '';
    const badgeClass = NOTIF_PRIORITY_BADGE[priority];
    const badge = badgeClass ? `<span class="notification-item__priority-badge ${badgeClass}">${priority}</span>` : '';
    return `
      <div class="notification-item ${unread ? 'is-unread' : ''} ${priorityClass}" data-notif-id="${n.public_id}" data-notif-link="${n.action_url || n.link || ''}">
        <span class="notification-item__icon ${tone}"><i class="bi ${icon}"></i></span>
        <div class="notification-item__body">
          <div class="u-flex u-items-center u-justify-between" style="gap:6px;">
            ${n.title ? `<div class="notification-item__title">${escapeHtml(n.title)}</div>` : '<span></span>'}
            ${badge}
          </div>
          <div>${escapeHtml(n.message)}</div>
          <time>${new Date(n.created_at).toLocaleString()}</time>
        </div>
        ${unread ? '<span class="notification-item__unread-dot"></span>' : ''}
        <button type="button" class="notification-item__delete" data-notif-delete="${n.public_id}" aria-label="Delete"><i class="bi bi-x"></i></button>
      </div>`;
  }

  async function loadNotifSheet(reset) {
    if (notifLoading) return;
    notifLoading = true;
    if (reset) { notifCursor = null; notifCursorRank = null; if (notifSheetList) notifSheetList.innerHTML = ''; }

    try {
      const data = await apiGet('../api/notifications.php', {
        action: 'get_all', status: notifFilter, limit: 20,
        before: notifCursor, before_rank: notifCursorRank,
      });
      if (data.status !== 'success') {
        if (data._kind) showToast(data.message); // network/timeout/parse failure — a plain filter-with-no-results stays silent, same as before
        return;
      }

      const rows = data.data.notifications || [];
      if (notifSheetList) notifSheetList.insertAdjacentHTML('beforeend', rows.map(renderNotifRow).join(''));
      notifCursor = data.data.next_before;
      notifCursorRank = data.data.next_before_rank;
      notifSheetLoadMore?.classList.toggle('u-hidden', !data.data.has_more);

      const totalShown = notifSheetList ? notifSheetList.children.length : 0;
      notifSheetEmpty?.classList.toggle('u-hidden', totalShown > 0);
      if (notifSheetCount) notifSheetCount.textContent = totalShown ? `${totalShown} shown` : '';
    } finally {
      notifLoading = false;
    }
  }

  qs('#notifSheetTabs')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-notif-filter]');
    if (!btn) return;
    qsa('#notifSheetTabs button').forEach((b) => b.classList.toggle('is-active', b === btn));
    notifFilter = btn.dataset.notifFilter;
    loadNotifSheet(true);
  });

  notifSheetLoadMore?.addEventListener('click', () => loadNotifSheet(false));

  // Infinite scroll: load the next page once the user nears the bottom
  // of the sheet's own scroll region (not the page — .sheet is fixed).
  notifSheetList?.addEventListener('scroll', () => {
    if (notifSheetList.scrollTop + notifSheetList.clientHeight >= notifSheetList.scrollHeight - 80) {
      if (!notifSheetLoadMore?.classList.contains('u-hidden')) loadNotifSheet(false);
    }
  });

  notifSheetList?.addEventListener('click', async (e) => {
    const deleteBtn = e.target.closest('[data-notif-delete]');
    if (deleteBtn) {
      e.stopPropagation();
      const row = deleteBtn.closest('[data-notif-id]');
      await apiPost('../api/notifications.php', { action: 'delete', public_id: deleteBtn.dataset.notifDelete });
      row?.remove();
      pollNotifications();
      return;
    }

    const row = e.target.closest('[data-notif-id]');
    if (!row) return;
    row.classList.remove('is-unread');
    row.querySelector('.notification-item__unread-dot')?.remove();
    await apiPost('../api/notifications.php', { action: 'mark_one_read', public_id: row.dataset.notifId });
    pollNotifications();
    const link = row.dataset.notifLink;
    if (link) window.location.href = link;
  });

  // Simple pull-to-refresh: only engages when already scrolled to the
  // top of the list (so it never fights normal scrolling further down).
  let pullStartY = null;
  notifSheetList?.addEventListener('touchstart', (e) => {
    pullStartY = notifSheetList.scrollTop === 0 ? e.touches[0].clientY : null;
  }, { passive: true });
  notifSheetList?.addEventListener('touchend', (e) => {
    if (pullStartY === null) return;
    const delta = e.changedTouches[0].clientY - pullStartY;
    pullStartY = null;
    if (delta > 60) loadNotifSheet(true);
  }, { passive: true });

  qs('#notifSheetMarkAllRead')?.addEventListener('click', async () => {
    await apiPost('../api/notifications.php', { action: 'mark_as_read' });
    qsa('#notifSheetList .notification-item.is-unread').forEach((row) => {
      row.classList.remove('is-unread');
      row.querySelector('.notification-item__unread-dot')?.remove();
    });
    pollNotifications();
  });

  notifBtn?.addEventListener('click', () => loadNotifSheet(true));

  async function pollNotifications() {
    // Rule 8/10: mark-all-read, delete, and mark-one-read each also call
    // this right after their own POST resolves, and the 30s interval can
    // land in the same moment — mtDedupeRequest collapses any of those
    // into the single in-flight GET instead of firing several.
    const data = await mtDedupeRequest('notif-unread', () => apiGet('../api/notifications.php', { action: 'get_unread' }));
    if (data.status !== 'success') return; // network/timeout failures keep the badge at its last known value, same as before

    const count = data.data.unread_count;
    const criticalCount = data.data.critical_unread || 0;
    mtState.set('unreadNotifications', count);
    mtState.set('criticalUnreadNotifications', criticalCount);
    if (count > 0) {
      // Priority Management System: an unread critical notification
      // (account locked, payment failure, fraud alert, emergency admin
      // message) shows "!" instead of a plain count, plus a distinct
      // red-pulse style — kept to exactly that one signal, no extra UI.
      if (notifDot) notifDot.textContent = criticalCount > 0 ? '!' : (count > 9 ? '9+' : String(count));
      notifDot?.classList.toggle('is-critical', criticalCount > 0);
      notifDot?.classList.remove('is-hidden');
    } else {
      notifDot?.classList.add('is-hidden');
      notifDot?.classList.remove('is-critical');
    }
  }

  if (notifBtn) {
    pollNotifications();
    setInterval(pollNotifications, 30000);
  }

  // ---- Bottom nav center button: emits an event, screens decide what it does ----
  qs('[data-center-action]')?.addEventListener('click', (e) => {
    document.dispatchEvent(new CustomEvent('mohtoh:center-action', { detail: { action: e.currentTarget.dataset.centerAction } }));
  });

  // ---- Mode switching: Rider <-> Driver <-> Car/Keke ----
  // Sheet markup: partials/mode-switch-sheet.php (included by the drawer).
  // Opening is handled by the generic [data-open-sheet-id] hook below; this
  // block only loads state and posts the switch. Nothing here decides who is
  // allowed to do what — api/switch-mode.php re-checks driver capability,
  // vehicle ownership, vehicle class and verification on every request, so
  // enabling a disabled button in devtools still cannot switch a rider into
  // Keke mode.
  const msSheet = qs('#modeSwitchSheet');

  function msSetDisabled(btn, disabled, reason) {
    if (!btn) return;
    // .btn:disabled already dims and blocks pointer events in components.css.
    btn.disabled = !!disabled;
    if (reason) btn.title = reason; else btn.removeAttribute('title');
  }

  function msRender(d) {
    const isDriverMode = d.current_mode === 'driver';
    const isAdminMode = d.current_mode === 'admin';
    qs('#msCurrentMode') && (qs('#msCurrentMode').textContent = isAdminMode ? 'Admin' : (isDriverMode ? 'Driver' : 'Rider'));
    const detail = isDriverMode && d.vehicle_label ? `${d.vehicle_label} · ${d.driver_ready ? 'verified' : 'verification pending'}` : '';
    qs('#msCurrentDetail') && (qs('#msCurrentDetail').textContent = detail);

    // Rider stays available to any account; hide only when rider is already the
    // current mode, so a driver- or admin-mode multi-role account still sees it.
    qs('#msRiderSection')?.classList.toggle('u-hidden', d.current_mode === 'rider');
    // Driver section for accounts that already hold the capability.
    qs('#msDriverSection')?.classList.toggle('u-hidden', !d.has_driver_capability);
    // Signup path only for riders who have never been a driver.
    qs('#msBecomeSection')?.classList.toggle('u-hidden', !!d.has_driver_capability);
    // Admin section only for accounts with an active admin hat. The server's
    // is_admin already folds in admin_status=active + not suspended, so a
    // suspended admin never sees the option (and the backend re-checks anyway).
    qs('#msAdminSection')?.classList.toggle('u-hidden', !d.is_admin);
    msSetDisabled(qs('#msSwitchAdminBtn'), isAdminMode, isAdminMode ? 'Already in Admin mode' : '');

    msSetDisabled(qs('#msSwitchDriverBtn'), isDriverMode, isDriverMode ? 'Already in Driver mode' : '');
    const hint = qs('#msDriverHint');
    if (hint) {
      hint.textContent = d.driver_ready
        ? 'Create Join Rides and accept Pickup Rides.'
        : (d.driver_blocked_reason || 'Complete your vehicle verification to start operating.');
    }

    (d.classes || []).forEach((c) => {
      const btn = qs(`[data-ms-class="${c.class}"]`);
      if (!btn) return;
      btn.classList.toggle('is-active', d.current_class === c.class && isDriverMode);
      msSetDisabled(btn, !c.available, c.reason);
    });
    const note = qs('#msClassNote');
    if (note) {
      note.textContent = d.vehicle_label
        ? `Your registered vehicle is a ${d.vehicle_label}. To change class, update your vehicle under My Vehicle — the new vehicle goes through verification again.`
        : 'Add a vehicle under My Vehicle to set your operating class.';
    }
    qs('#msStatus') && (qs('#msStatus').textContent = '');
  }

  async function msLoad() {
    const status = qs('#msStatus');
    if (status) status.textContent = 'Loading your modes…';
    const data = await apiGet('../api/switch-mode.php', { action: 'get_modes' });
    if (data.status !== 'success') {
      if (status) status.textContent = data.message || 'Could not load your modes.';
      return;
    }
    msRender(data.data);
  }

  async function msPost(payload, btn) {
    const status = qs('#msStatus');
    const wasDisabled = btn ? btn.disabled : false;
    if (btn) btn.disabled = true;
    if (status) status.textContent = 'Switching…';
    const data = await apiPost('../api/switch-mode.php', payload);
    if (data.status !== 'success') {
      if (btn) btn.disabled = wasDisabled;
      if (status) status.textContent = '';
      showToast(data.message || 'Could not switch mode.', 'error');
      if (data.data) msRender(data.data); // server sent the real state back — resync the sheet
      return;
    }
    showToast(data.message);
    // A mode change alters what every page is allowed to show, so land on the
    // dashboard the server nominates rather than re-rendering in place.
    window.location.href = data.data?.redirect || (payload.mode === 'rider' ? '../rider/dashboard.php' : '../driver/dashboard.php');
  }

  if (msSheet) {
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-open-sheet-id="modeSwitchSheet"]')) { msLoad(); return; }

      const riderBtn = e.target.closest('#msSwitchRiderBtn');
      if (riderBtn) { msPost({ action: 'switch', mode: 'rider' }, riderBtn); return; }

      const driverBtn = e.target.closest('#msSwitchDriverBtn');
      if (driverBtn) { msPost({ action: 'switch', mode: 'driver' }, driverBtn); return; }

      const adminBtn = e.target.closest('#msSwitchAdminBtn');
      if (adminBtn) { msPost({ action: 'switch', mode: 'admin' }, adminBtn); return; }

      const classBtn = e.target.closest('[data-ms-class]');
      if (classBtn) { msPost({ action: 'switch', mode: 'driver', vehicle_class: classBtn.dataset.msClass }, classBtn); return; }

      const becomeBtn = e.target.closest('[data-ms-become]');
      if (becomeBtn) { msPost({ action: 'become_driver', vehicle_class: becomeBtn.dataset.msBecome }, becomeBtn); }
    });
  }

  // ---- Generic named-sheet triggers, for any screen built after Home ----
  // Usage: <button data-open-sheet-id="mySheet"> opens #mySheet; any
  // [data-close-sheet] inside a .sheet closes its own nearest ancestor.
  // Screens with bespoke sheet logic (index.php, rider Home) keep their
  // existing handlers untouched — this is additive, not a replacement.
  //
  // Sidebar-originated triggers: the sidebar drawer (#sidebarDrawer) shares
  // the same z-index tier (--z-sheet = 1000) as every bottom sheet. If the
  // sidebar stays open while a sheet opens, the sidebar paints over the sheet
  // at the same layer — the mode-switch sheet (and any other sheet opened from
  // a sidebar link) appears covered/hidden. Close the sidebar first so the
  // sheet is the only element visible at that tier.
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open-sheet-id]');
    if (opener) {
      e.preventDefault();
      // If the trigger is inside the sidebar, dismiss it before the sheet
      // slides up — prevents the drawer from sitting in front of the sheet.
      if (opener.closest('#sidebarDrawer')) {
        closeSidebar();
      }
      const target = document.getElementById(opener.dataset.openSheetId);
      if (target) openSheet(target, qs('[data-sheet-scrim]'));
      return;
    }
    const closer = e.target.closest('[data-close-sheet]');
    if (closer) {
      const sheetEl = closer.closest('.sheet');
      if (sheetEl) closeSheet(sheetEl, qs('[data-sheet-scrim]'));
    }
  });
});

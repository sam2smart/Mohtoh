<?php
// partials/notification-sheet.php
// The Notification Engine's mobile UI: a true bottom sheet (not the old
// dropdown panel in app-header.php) — same .sheet/.sheet-scrim
// mechanism every other sheet in the app already uses, so it opens
// above the bottom nav, locks background scroll, and behaves
// consistently with Wallet/Trip Details/etc. Included alongside
// partials/bottom-nav.php on every page that renders the notification
// bell (partials/app-header.php).
?>
<div class="sheet" id="notificationSheet" role="dialog" aria-modal="true" aria-labelledby="notificationSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title" id="notificationSheetTitle">Notifications</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="sheet-tabs" id="notifSheetTabs">
        <button type="button" class="is-active" data-notif-filter="all">All</button>
        <button type="button" data-notif-filter="unread">Unread</button>
    </div>

    <div class="u-flex u-justify-between u-items-center u-mb-2">
        <span class="u-text-xs u-text-faint" id="notifSheetCount">—</span>
        <button type="button" class="btn-text u-text-xs" id="notifSheetMarkAllRead" style="font-weight:700;">Mark all read</button>
    </div>

    <div id="notifSheetPullHint" class="u-text-center u-text-xs u-text-faint" style="padding:4px 0;">Pull down to refresh</div>

    <div id="notifSheetList" class="notif-sheet-list"></div>

    <div id="notifSheetEmpty" class="notification-empty u-hidden">No notifications yet.</div>

    <button type="button" class="btn btn-outline u-mt-3 u-hidden" id="notifSheetLoadMore">Load more</button>
</div>

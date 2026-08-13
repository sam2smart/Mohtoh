<?php
// partials/messages-sheet.php
// The MohToh Intelligent Chat System's mobile UI: one .sheet with two
// internal views (conversation list, chat detail) toggled by JS, the
// same .sheet/.sheet-scrim mechanism every other sheet in the app uses
// (partials/notification-sheet.php is the direct model). Included
// alongside it on driver/dashboard.php and rider/dashboard.php.
// assets/js/messages.js owns all behavior here.
?>
<div class="sheet" id="messagesSheet" role="dialog" aria-modal="true" aria-labelledby="messagesSheetTitle">
    <div class="sheet-handle"></div>

    <!-- ================= Conversation list view ================= -->
    <div id="msgListView">
        <div class="sheet-header">
            <h2 class="sheet-title" id="messagesSheetTitle">Messages</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <button type="button" class="btn btn-outline u-mb-3" id="msgStartSupportBtn">
            <i class="bi bi-headset"></i> Contact MohToh Support
        </button>

        <div id="msgConversationList" class="msg-conv-list"></div>
        <div id="msgListEmpty" class="notification-empty u-hidden">No conversations yet. They start automatically once you book or publish a ride.</div>
    </div>

    <!-- ================= Chat detail view ================= -->
    <div id="msgDetailView" class="u-hidden">
        <div class="u-flex u-items-center u-gap-2 u-mb-3">
            <button type="button" class="icon-pill icon-pill--ghost" id="msgBackBtn" aria-label="Back to conversations"><i class="bi bi-arrow-left"></i></button>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;" id="msgDetailTitle">—</div>
                <div class="u-text-xs u-text-faint" id="msgDetailSubtitle">—</div>
            </div>
        </div>

        <div id="msgArchivedBanner" class="u-text-xs u-text-faint u-hidden" style="padding:8px 12px;background:var(--color-surface);border-radius:var(--radius-sm);margin-bottom:8px;">
            This conversation has ended and is now read-only.
        </div>

        <div id="msgBubbleList" class="msg-bubble-list"></div>

        <form id="msgSendForm" class="msg-send-bar">
            <button type="button" class="icon-pill icon-pill--ghost" id="msgLocationBtn" aria-label="Send my location"><i class="bi bi-geo-alt"></i></button>
            <input type="text" id="msgInput" placeholder="Type a message…" autocomplete="off" maxlength="2000">
            <button type="submit" class="icon-pill" id="msgSendBtn" aria-label="Send"><i class="bi bi-send-fill"></i></button>
        </form>
    </div>
</div>

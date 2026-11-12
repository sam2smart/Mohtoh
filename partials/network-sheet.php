<?php
// partials/network-sheet.php
// MOHTOH NETWORK mobile UI — replaces partials/community-rewards-sheet.php
// as the single canonical entry point, reached only via the sidebar
// drawer's "MOHTOH NETWORK" link (partials/sidebar.php). Same .sheet
// pattern as every other sheet this app uses — no new open/close JS
// needed (assets/js/navigation.js's generic [data-open-sheet-id]
// handler already covers it).
?>
<div class="sheet" id="networkSheet" role="dialog" aria-modal="true" aria-labelledby="networkSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <div>
            <h2 class="sheet-title" id="networkSheetTitle">MOHTOH NETWORK</h2>
            <p class="u-text-xs u-text-faint" style="margin:0;">Share the Journey. Create Opportunity.</p>
        </div>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>

    <div id="netAnnouncement" class="u-hidden card u-mb-4" style="padding:var(--space-3);background:var(--color-primary-50);font-size:var(--text-sm);"></div>

    <!-- ================= Not yet a partner ================= -->
    <section id="netJoinSection" class="u-hidden">
        <p class="u-text-sm u-mb-3">MOHTOH NETWORK is MohToh's direct referral &amp; revenue-share programme. Invite people you know — when they complete real transport activity, you earn a share of MohToh's commission for a limited time. Participation is voluntary, free, and never affects your normal MohToh account.</p>
        <div class="card u-mb-3" style="padding:var(--space-3);max-height:160px;overflow-y:auto;font-size:var(--text-xs);color:var(--color-text-faint);" id="netTermsText"></div>
        <label class="u-flex u-gap-2 u-mb-3" style="align-items:flex-start;">
            <input type="checkbox" id="netAcceptTerms" style="margin-top:3px;">
            <span class="u-text-xs">I accept the MOHTOH NETWORK programme terms and conditions.</span>
        </label>
        <button type="button" class="btn btn-primary" id="netJoinBtn">Join MOHTOH NETWORK</button>
        <div id="netJoinAlert" class="u-text-xs u-mt-2 u-hidden"></div>
    </section>

    <section id="netPendingSection" class="u-hidden">
        <div class="notification-empty"><i class="bi bi-hourglass-split"></i> Your MOHTOH NETWORK application is under review. We'll notify you once approved.</div>
    </section>

    <section id="netSuspendedSection" class="u-hidden">
        <div class="notification-empty"><i class="bi bi-exclamation-triangle"></i> Your MOHTOH NETWORK participation is currently suspended. <span id="netSuspendedReason"></span></div>
    </section>

    <!-- ================= Approved partner dashboard ================= -->
    <div id="netDashboard" class="u-hidden">

        <section class="card u-mb-4" style="padding:var(--space-4);text-align:center;background:var(--color-primary-50);">
            <div class="u-text-xs u-text-faint u-mb-1">My Referral Code</div>
            <div id="netCode" style="font-family:var(--font-display);font-weight:700;font-size:var(--text-lg);letter-spacing:0.03em;color:var(--color-primary);">—</div>
            <div id="netQrCanvas" class="u-mt-2" style="display:none;width:160px;height:160px;margin:0 auto;"></div>
        </section>

        <section class="u-mb-4">
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Invite &amp; Earn</h3>
            <div class="u-flex u-gap-2" style="flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm" id="netCopyLinkBtn"><i class="bi bi-link-45deg"></i> Copy Link</button>
                <a class="btn btn-outline btn-sm" id="netWhatsappBtn" href="#" target="_blank" rel="noopener" style="width:auto;"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                <a class="btn btn-outline btn-sm" id="netSmsBtn" href="#" style="width:auto;"><i class="bi bi-chat-dots"></i> SMS</a>
                <button type="button" class="btn btn-outline btn-sm u-hidden" id="netNativeShareBtn"><i class="bi bi-share"></i> Share</button>
                <button type="button" class="btn btn-outline btn-sm" id="netShowQrBtn"><i class="bi bi-qr-code"></i> QR Code</button>
                <button type="button" class="btn btn-outline btn-sm u-hidden" id="netDownloadQrBtn"><i class="bi bi-download"></i> Download QR</button>
            </div>
        </section>

        <section class="u-mb-4">
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Network Growth</h3>
            <div class="row g-2" style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Total Referrals</div><div id="netStatTotal" style="font-weight:700;font-size:var(--text-lg);">0</div></div>
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Earning Now</div><div id="netStatActive" style="font-weight:700;font-size:var(--text-lg);">0</div></div>
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Converted</div><div id="netStatConverted" style="font-weight:700;font-size:var(--text-lg);">0</div></div>
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Expired</div><div id="netStatExpired" style="font-weight:700;font-size:var(--text-lg);">0</div></div>
            </div>
        </section>

        <section class="u-mb-4">
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">My Earnings</h3>
            <div class="row g-2" style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Pending</div><div id="netWalletPending" style="font-weight:700;font-size:var(--text-lg);">₦0</div></div>
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Available</div><div id="netWalletAvailable" style="font-weight:700;font-size:var(--text-lg);color:var(--color-primary);">₦0</div></div>
                <div class="border rounded" style="flex:1;min-width:90px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);"><div class="u-text-xs u-text-faint">Withdrawn</div><div id="netWalletWithdrawn" style="font-weight:700;font-size:var(--text-lg);">₦0</div></div>
            </div>
            <button type="button" class="btn btn-primary btn-sm u-mt-3" id="netRequestWithdrawalBtn">Request Withdrawal</button>

            <div id="netWithdrawalForm" class="card u-mt-3 u-hidden" style="padding:var(--space-3);">
                <div class="u-mb-2">
                    <label class="u-text-xs u-text-faint">Withdraw to</label>
                    <select class="form-select form-select-sm" id="netWithdrawalAccountSelect"></select>
                </div>
                <div id="netAddAccountFields" class="u-hidden u-mb-2">
                    <input type="text" class="form-control form-control-sm u-mb-2" id="netNewBankName" placeholder="Bank name">
                    <input type="text" class="form-control form-control-sm u-mb-2" id="netNewAccountNumber" placeholder="Account number" inputmode="numeric">
                    <input type="text" class="form-control form-control-sm u-mb-2" id="netNewAccountName" placeholder="Account name">
                    <button type="button" class="btn btn-outline btn-sm" id="netSaveAccountBtn">Save Account</button>
                </div>
                <button type="button" class="btn btn-outline btn-sm u-mb-2" id="netAddAccountToggle"><i class="bi bi-plus-lg"></i> Add bank account</button>
                <div class="u-mb-2">
                    <label class="u-text-xs u-text-faint">Amount (₦)</label>
                    <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="netWithdrawalAmount">
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="netSubmitWithdrawalBtn">Submit Request</button>
                <div id="netWithdrawalAlert" class="u-text-xs u-mt-2 u-hidden"></div>
            </div>
        </section>

        <section class="u-mb-4">
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">My Network</h3>
            <div id="netReferralsList"></div>
            <div id="netReferralsEmpty" class="notification-empty u-hidden">No one yet — share your code to start growing your network.</div>
        </section>

        <section class="u-mb-4">
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Rewards History</h3>
            <div id="netRewardsList"></div>
            <div id="netRewardsEmpty" class="notification-empty u-hidden">No rewards yet — rewards are earned once someone you referred completes a real, paid trip.</div>
        </section>

        <section>
            <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Withdrawal History</h3>
            <div id="netWithdrawalsList"></div>
            <div id="netWithdrawalsEmpty" class="notification-empty u-hidden">No withdrawals yet.</div>
        </section>
    </div>
</div>

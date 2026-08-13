<?php
// partials/community-rewards-sheet.php
// MohToh Community Rewards mobile UI — the single canonical destination
// for the feature, reached only via the sidebar drawer's "Grow My
// Community" link (partials/sidebar.php). Do not add a second entry
// point (e.g. a dashboard card) — that duplication was deliberately
// removed. Same .sheet pattern as every other sheet this session
// built — no new open/close JS needed.
?>
<div class="sheet" id="communityRewardsSheet" role="dialog" aria-modal="true" aria-labelledby="communityRewardsSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <div>
            <h2 class="sheet-title" id="communityRewardsSheetTitle">Grow My Community</h2>
            <p class="u-text-xs u-text-faint" style="margin:0;">Invite riders and drivers, grow the MohToh community, track your referrals, and earn rewards.</p>
        </div>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>

    <!-- ================= My Referral Code ================= -->
    <section class="card u-mb-4" style="padding:var(--space-4);text-align:center;background:var(--color-primary-50);">
        <div class="u-text-xs u-text-faint u-mb-1">My Referral Code</div>
        <div id="crCode" style="font-family:var(--font-display);font-weight:700;font-size:var(--text-lg);letter-spacing:0.03em;color:var(--color-primary);">—</div>
    </section>

    <!-- ================= Invite Friends (sharing) ================= -->
    <section class="u-mb-4">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Invite Friends</h3>
        <div class="u-flex u-gap-2" style="flex-wrap:wrap;">
            <button type="button" class="btn btn-outline btn-sm" id="crCopyLinkBtn"><i class="bi bi-link-45deg"></i> Copy Link</button>
            <a class="btn btn-outline btn-sm" id="crWhatsappBtn" href="#" target="_blank" rel="noopener" style="width:auto;"><i class="bi bi-whatsapp"></i> WhatsApp</a>
            <a class="btn btn-outline btn-sm" id="crSmsBtn" href="#" style="width:auto;"><i class="bi bi-chat-dots"></i> SMS</a>
            <button type="button" class="btn btn-outline btn-sm u-hidden" id="crNativeShareBtn"><i class="bi bi-share"></i> Share</button>
        </div>
    </section>

    <!-- ================= Community Growth stats ================= -->
    <section class="u-mb-4">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Community Growth</h3>
        <div class="row g-2" style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
            <div class="border rounded" style="flex:1;min-width:100px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);">
                <div class="u-text-xs u-text-faint">Invited</div>
                <div id="crStatInvited" style="font-weight:700;font-size:var(--text-lg);">0</div>
            </div>
            <div class="border rounded" style="flex:1;min-width:100px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);">
                <div class="u-text-xs u-text-faint">Active</div>
                <div id="crStatActive" style="font-weight:700;font-size:var(--text-lg);">0</div>
            </div>
            <div class="border rounded" style="flex:1;min-width:100px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);">
                <div class="u-text-xs u-text-faint">Trips Generated</div>
                <div id="crStatTrips" style="font-weight:700;font-size:var(--text-lg);">0</div>
            </div>
            <div class="border rounded" style="flex:1;min-width:100px;padding:var(--space-3);text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);">
                <div class="u-text-xs u-text-faint">Rewards</div>
                <div id="crStatRewards" style="font-weight:700;font-size:var(--text-lg);color:var(--color-primary);">₦0</div>
            </div>
        </div>
    </section>

    <!-- ================= My Community (direct referrals only) ================= -->
    <section class="u-mb-4">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">My Community</h3>
        <div id="crReferralsList"></div>
        <div id="crReferralsEmpty" class="notification-empty u-hidden">No one yet — share your code to start growing your MohToh community.</div>
    </section>

    <!-- ================= Rewards History ================= -->
    <section>
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Rewards History</h3>
        <div id="crRewardsList"></div>
        <div id="crRewardsEmpty" class="notification-empty u-hidden">No rewards yet — rewards are paid once an invited friend completes real activity, not just registration.</div>
    </section>
</div>

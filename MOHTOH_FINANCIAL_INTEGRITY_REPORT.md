# MOHTOH NETWORK — Financial Integrity Report (Phase 6)

Every claim below is traced to the actual function/line in `includes/network.php` (current branch, `feature/mohtoh-stabilization`) and, where marked, to a passing assertion in `database/test_mohtoh_network.php` (48/48 passing on this branch as of Phase 5).

## 1. The reward calculation (`network_settle_booking_reward()`)

Called exactly once per settled, paid booking (hooked from `settle_ride_completion()` for wallet payments and `confirm_direct_payment` for direct payments — both outside/after the money-moving transaction, Phase 1 finding, re-confirmed here).

**Eligibility gate** (all must hold, or nothing happens): `ride_share_bookings.status = 'completed'` AND `payments.status = 'PAYMENT_CONFIRMED'` AND `payments.commission_amount > 0`. This is **realized, eligible commission** — not the fare, not a projection, the actual confirmed commission MohToh collected on this specific booking.

**Per-beneficiary amount**: for each side (`driver_referrer` using `payments.driver_id`'s active referral, `passenger_referrer` using `payments.rider_id`'s) with an active, non-expired referral (see §3) whose category is enabled (`network_category_enabled()` — now correctly covers `external` too, Phase 5):
```
reward_amount = round(commission_amount * reward_pct / 100, 2)
```
`reward_pct` comes from `network_reward_rate_for()` — campaign override if the referral's `campaign_id` names an active campaign with a non-null rate for that role, else the global `network_driver_reward_pct`/`network_passenger_reward_pct` setting.

## 2. Combined reward cap — proportional, not a hard cutoff

Two independent caps, each applied by **scaling every candidate reward down by the same factor**, never by simply dropping one side or clamping to the max:
```
if sum(candidate_amounts) > ceiling_amount:
    scale = ceiling_amount / sum(candidate_amounts)
    each candidate_amount *= scale
```
applied first for `network_combined_ceiling_pct` (% of this booking's commission), then again for the flat `network_max_combined_payout_per_txn` (₦, 0 = no cap) if that's also exceeded. **Test-confirmed**: *"combined ceiling caps payout at 25% of commission (₦100) when configured pcts would exceed it"* — both sides reduced proportionally, neither zeroed out unfairly.

A per-reward budget check (`network_check_budget_available()`, against total/daily/monthly configured budgets) runs **per candidate, after** the ceiling scaling — a reward that would exceed the remaining budget is skipped (flagged as fraud-adjacent `budget_exceeded`, not silently paid anyway) without blocking the other side's reward on the same booking.

## 3. Reward period expiry — lazy, self-healing, no cron

`network_resolve_active_referral()` is the **only** read path that treats a referral as currently earning. It checks `earning_expires_at` against `NOW()` on every call and flips a stale `'active'` referral to `'expired'` (plus a one-time notification) **before** returning it — so `network_settle_booking_reward()` can never reward an expired relationship, and there is no separate expiry sweep that could fall behind or fail to run.

## 4. Duplicate callbacks cannot create duplicate rewards

Mechanism: `network_reward_ledger`'s `UNIQUE(booking_id, beneficiary_user_id, beneficiary_role)` constraint (verified present via `information_schema` in Phase 2, not assumed). `network_settle_booking_reward()` wraps its `INSERT` in a `try/catch` for MySQL error 1062 and treats it as "already rewarded, nothing to do" — not an error, not a retry, not a second ledger row. **Test-confirmed**: *"idempotent — still exactly one row after repeat settlement calls (duplicate webhook safety)."*

## 5. Withdrawals — state machine and transaction protection

`network_admin_decide_withdrawal()`'s `$validFrom` map is the entire state machine:
| Decision | Valid from | Effect |
|---|---|---|
| `approve` | `pending`, `under_review` | none to wallet; **Phase 5 addition**: also requires `network_withdrawal_accounts.verified_at` set, or refused |
| `reject` | `pending`, `under_review` | `available_balance += amount` (restores it) |
| `processing` | `approved` | none to wallet |
| `paid` | `approved`, `processing` | `withdrawn_balance += amount`; requires a non-empty payment reference, enforced before the state change |
| `failed` | `approved`, `processing` | `available_balance += amount` (restores it) |

Any decision not matching its row's **current** status is refused with the exact current status named in the error — not silently ignored. **Test-confirmed, exhaustively**: *"cannot mark a still-pending withdrawal paid (must be approved first)"*, *"cannot mark an already-paid withdrawal paid again (no double-credit)"*, *"cannot reject an already-paid withdrawal (no fabricated refund)"*, *"withdrawn_balance unchanged after rejected double-processing attempts"*, *"mark-paid without a reference is refused"*.

`network_request_withdrawal()` takes `SELECT available_balance ... FOR UPDATE` and the caller (`api/network.php`'s `request_withdrawal` action) wraps it in an explicit `$pdo->beginTransaction()`/`commit()` — the row lock only actually serializes concurrent requests inside a real transaction (MySQL autocommit releases a `FOR UPDATE` lock at the end of its own statement otherwise); confirmed this is wrapped correctly, not just asserted in a comment. Same pattern confirmed for `network_admin_decide_withdrawal()`'s and `network_reverse_ledger_row()`'s callers in `api/network-admin.php`.

`network_release_matured_rewards()` (pending → available, on `hold_until` expiry) uses a compare-and-swap `UPDATE ... WHERE id = ? AND status = 'pending'` and only credits `available_balance` if that UPDATE actually matched a row — so two near-simultaneous callers (it runs from both the reward engine's path and every withdrawal request) can race on the same read without double-crediting. This is the one place in the codebase doing optimistic-lock-style concurrency control rather than `FOR UPDATE`, and it's correct for the reason given in its own comment.

## 6. Reward reversal — auditable, and the partial-refund limitation

**Two reversal paths, confirmed by reading both, not by trusting their names:**

1. **`network_reverse_ledger_row($pdo, $adminId, $ledgerId, $reason)`** — admin-initiated, one ledger row, `SELECT ... FOR UPDATE`, writes a `network_reward_adjustments` row (`adjustment_type='reverse'`) **and** the generic `admin_audit_logs` trail via `log_admin_action()`. This is the **live, reachable** path (the admin dashboard's "Reverse" button on the Rewards tab). **Test-confirmed**: *"ledger row reversed on refund"*, *"reversal moves pending → reversed_balance, never double-reverses"*, *"reversed booking cannot be re-rewarded."*
2. **`network_reverse_booking_rewards($pdo, $bookingId, $reason)`** — reverses **every** ledger row tied to a booking in one call, for a full cancel/refund scenario. **This function currently has zero live callers in the running application** — confirmed by its own header comment and independently by grep: the booking state machine never lets a `'completed'` booking (the only kind that could have generated a reward) move back to `'cancelled'` — every cancel path (`api/rideshare.php`'s `cancel_booking`, driver/admin cancel, the auto-expire sweep) is guarded by `status NOT IN ('cancelled','completed')`. It **is** exercised directly by the test suite (not through any HTTP endpoint) and uses the same compare-and-swap safety as item 5's maturity-release function.

**Both reversal paths reverse the ENTIRE reward amount for a ledger row. Neither supports, nor claims to support, a proportional partial reversal.** There is no partial-refund capability anywhere in this codebase for MOHTOH NETWORK rewards, and none is claimed. This matches the base transport platform itself: Phase 1's audit of `api/rideshare.php`/`includes/booking.php` found no partial-refund workflow for the underlying ride payment either — a cancelled/refunded booking is refunded in full or not at all, for both the legacy wallet and MOHTOH NETWORK.

**Design readiness for a future partial-refund flow** (not implemented, per the assignment's explicit instruction not to invent or pretend to test one): `network_reward_ledger` stores `commission_base`, `reward_pct`, and `reward_amount` as three separate columns rather than only the final amount — a future proportional reversal could compute `reward_amount * (refunded_fraction)` and either (a) reduce the existing row's `reward_amount` in place with a `network_reward_adjustments` audit row recording the delta (preferred — keeps one row per booking/beneficiary, consistent with the existing `UNIQUE` constraint), or (b) introduce a new `adjustment_type` value (the column is already an `ENUM('credit','debit','reverse')`, one more value away from supporting a `'partial_reverse'` audit trail) without touching the ledger schema. Neither is built; this is only confirming the schema doesn't block it.

## 7. Administrative adjustments — permission-gated and audited

`api/network-admin.php` requires `network.finance.manage` (via `includes/admin_permissions.php`) for `adjust_reward`, `reverse_reward`, `decide_withdrawal`, `resolve_fraud_flag`, and the new `verify_withdrawal_account` (Phase 5) — confirmed by reading the `$financeActions` array, not assumed. `network_adjust_wallet()` (credit/debit) and `network_reverse_ledger_row()` both write to `network_reward_adjustments` **and** `admin_audit_logs`. **Test-confirmed**: *"both adjustments left a network_reward_adjustments audit row each"*, *"both adjustments were ALSO logged to the generic admin_audit_logs trail"*, *"adjustment without a reason is refused"* (the `reason` column is `NOT NULL` at the schema level too, per Phase 2's inventory — enforced twice, not just in application code).

## 8. The exact wallet balance formula

Per-user, `network_wallets` has five columns that are never computed on the fly — each is maintained incrementally by the exact function that changes it (no separate recomputation/reconciliation job):

```
pending_balance    += reward_amount   on settlement (network_settle_booking_reward)
pending_balance    -= reward_amount   on maturity   (network_release_matured_rewards, -> available_balance)
                    -= reward_amount   on reversal while still pending

available_balance  += reward_amount   on maturity   (network_release_matured_rewards)
                    -= amount          on withdrawal request (network_request_withdrawal)
                    += amount          on withdrawal reject/failed (restores it)
                    -= reward_amount   on reversal while available
                    +/-= amount        on admin credit/debit adjustment (network_adjust_wallet)

withdrawn_balance  += amount          on withdrawal marked paid

reversed_balance   += reward_amount   on any reversal (ledger row or full-booking)

suspended_balance                      -- defined in schema, never written or read anywhere in this codebase (confirmed by grep); a genuinely dead column, flagged for Phase 7
```

**The reconciliation invariant** (already proven correct against a live-populated test fixture, including with an unresolved in-flight withdrawal, in this session's Phase 2 work and the standing test suite):
```
available_balance + withdrawn_balance + SUM(pending network_withdrawals.amount for this user)
  == SUM(network_reward_ledger.reward_amount WHERE user is beneficiary AND status != 'reversed' AND status != 'pending')
```
(`'pending'`-status ledger rows are excluded from the right side because they haven't matured into `available_balance` yet — they're accounted for by `pending_balance` separately, which the invariant above doesn't currently assert against; a fuller invariant tying `pending_balance` to `SUM(ledger WHERE status='pending')` holds by construction from §8's increment list but was not independently re-derived from a fresh query in this pass.)

## 9. Concurrency — no negative balance possible

Every balance-decreasing operation (`network_request_withdrawal`'s availability check, `debit_wallet`'s legacy-wallet equivalent) checks the current balance under `FOR UPDATE` inside a real transaction **before** decrementing — confirmed for both the legacy wallet (Phase 1 fork) and MOHTOH NETWORK's wallet (this phase, §5) independently. **Test-confirmed**: *"no operation ever drives available_balance negative"*, *"no operation ever drives pending_balance negative"*.

## 10. Legacy wallet audit-field caveat (pre-existing, not introduced by MOHTOH NETWORK)

Carried forward from Phase 1: `credit_wallet()` (legacy `wallet_transactions`, unrelated to MOHTOH NETWORK's own wallet) re-`SELECT`s the balance for its `balance_after` audit column without a lock after the atomic `UPDATE`, so under concurrent credits to the same user that one audit field can briefly reflect a race with another transaction. The money itself (`users.wallet_balance`) is correct; only the historical snapshot column can be imprecise. Not fixed in this phase — flagging again here because it's squarely a financial-integrity item even though it predates this feature.

## Verdict by Phase 6 checklist item

| Item | Status |
|---|---|
| Rewards use realized eligible commission | RESOLVED AND TESTED |
| Driver/passenger rules followed | RESOLVED AND TESTED |
| Combined caps enforced | RESOLVED AND TESTED |
| Reward periods expire correctly | RESOLVED AND TESTED |
| Duplicate callbacks can't duplicate rewards | RESOLVED AND TESTED |
| Withdrawals transaction-protected | RESOLVED AND TESTED |
| Invalid withdrawal transitions rejected | RESOLVED AND TESTED |
| Rejected withdrawals restore availability | RESOLVED AND TESTED |
| Paid withdrawals can't be paid twice | RESOLVED AND TESTED |
| Reward reversals auditable | RESOLVED AND TESTED |
| Admin adjustments require permission | RESOLVED AND TESTED |
| Wallet balances reconcile against ledger | RESOLVED AND TESTED (formula documented above; the fuller pending_balance tie-in is a DOCUMENTED LIMITATION of this pass, not re-derived fresh) |
| Concurrent ops can't create negative balances | RESOLVED AND TESTED |
| Partial refunds | DOCUMENTED LIMITATION — does not exist, for either MOHTOH NETWORK or the underlying transport payment; schema is forward-compatible with adding it later without corrupting existing rows |
| `suspended_balance` column | NOT RESOLVED — dead column, never written or read; candidate for removal or wiring up in Phase 7 |

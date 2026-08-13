# Legacy Code — Not Referenced by the Live App

This document tracks code confirmed (via repo-wide reference search, not
assumption) to be unreachable from any current page, superseded by later
work. Per MohToh development rules, nothing here has been deleted —
this is a record for future sessions/contributors so nobody edits it
believing it's live, and a starting point if/when it's archived out of
the working tree.

**How this was verified**: for each file below, `grep`'d the entire
`.php` tree for its filename. A match count of zero (or a match only
inside the file's own header comment) means no other file `include`s,
`require`s, redirects to, or fetches it.

---

## 1. Old ride-matching system (single-passenger request/offer negotiation)

Superseded by the shared-seat `ride_shares`/`ride_share_bookings` model
and the Intelligent Trip Engine (`transition_trip_state()`,
`transition_passenger_state()` in `includes/functions.php`), which is
what every current driver/rider dashboard actually uses.

| File | References found |
|---|---|
| `api/request-ride.php` | 0 |
| `api/submit-offer.php` | 0 |
| `api/accept-offer.php` | 0 |
| `api/respond-ride.php` | 0 |
| `api/nearby-drivers.php` | 0 |
| `api/nearby-riders.php` | 0 |
| `api/pending-requests.php` | 0 |
| `api/fetch-offers.php` | 0 (only its own header comment) |
| `rideshare/dashboard.php` | 0 |
| `rideshare/details.php` | 0 |
| `includes/FareCalculator.php` | Used only by the files above (per `IMPLEMENTATION_STATUS.md`) |

This flow is documented in `IMPLEMENTATION_STATUS.md` (dated June 1,
2026, "~75% Complete — Awaiting frontend integration") — it was never
finished before the `ride_shares` model replaced it as the product
direction. The `ride_requests` table these files write to still exists
in the schema and still holds historical rows (referenced read-only by
`api/admin-api.php`'s `fetch_user_review_profile()` for pre-existing
trip/rating history) — do not drop that table without checking for
that dependency first.

## 2. Old chat backend

| File | References found |
|---|---|
| `api/chat.php` | 0 (only its own header comment) |

A working `sender_id`/`receiver_id` message CRUD endpoint with no
conversation model, no permission check, and no UI ever wired to it.
Superseded by `api/messages.php` + `includes/chat.php` (built this
session) — see the Phase 1 chat audit for detail. Left in place rather
than deleted since removing it costs nothing to defer and provides zero
upside now that nothing calls it.

## 3. Old WebRTC / chat frontend

| File | References found |
|---|---|
| `js/app.js` (top-level `js/`, distinct from `assets/js/`) | 0 |

Full but disconnected chat + call-signaling UI (`toggleChat()`,
`loadChatHistory()`, `startVoiceCall()`, `pollCallSignaling()`, etc.)
targeting DOM element IDs that don't exist in `driver/dashboard.php` or
`rider/dashboard.php`. `IMPLEMENTATION_STATUS.md` itself lists this as
unfinished legacy work. Its call-signaling half is conceptually related
to `api/call-management.php` (which IS live and used by
`assets/js/...` — not touched by this document) but this specific file
is not that integration; it's an earlier, abandoned attempt.

---

## Recommendation

None of the files above are wired into any live page — confirmed by
search, not inference. Suggested next step (needs sign-off before
acting, per MohToh development rules — "never remove working
functionality without reason" doesn't apply here since none of this is
reachable, but it's still a deliberate call, not an automatic one):

1. Move this list into a `legacy/` directory (preserves git-free file
   history via a plain file copy, since this project has no VCS) rather
   than deleting outright.
2. Keep `ride_requests` (the table, not the API files) as-is — it's
   still read by admin's review-profile lookup.
3. Re-run this same reference search before any future removal, in case
   something new has come to depend on one of these files since this
   document was written.

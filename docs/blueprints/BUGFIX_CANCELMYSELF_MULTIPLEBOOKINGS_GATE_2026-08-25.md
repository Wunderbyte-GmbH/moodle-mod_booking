# Bug: `cancelmyself` hides "Undo my booking" right after every multiplebookings booking

**Status: FIXED (2026-08-25).** The block was removed (not polarity-flipped — traced through
`bo_info::get_condition_results()` → `booking_bookit::render_bookit_template_data()`'s
`MOD_BOOKING_BO_BUTTON_CANCEL` handling and confirmed an active `cancelmyself` condition cannot
block a book-again round in the current architecture; it only contributes an additive Cancel
button and explicitly clears `$justmyalert`, the opposite of blocking). Verified: `phpcs` clean,
62 relevant PHPUnit tests (multiplebookings + all cancelmyself/slotbooking cancel-button coverage)
— 61 green, 1 pre-existing unrelated failure (`slot_persistent_calendar_test`, tracked separately
below) unchanged before/after.

**Context:** Found while investigating the CI failures reported 2026-08-25 for the commits merged
into `MOODLE_405_DEV` (GH-1506 waitlist refactor, Mon 2026-08-24 + Tue 2026-08-25). It explains all
4 failing scenarios in `tests/behat/booking_multiple_bookings.feature`.

## The bug

File: [`classes/bo_availability/conditions/cancelmyself.php`](../../classes/bo_availability/conditions/cancelmyself.php),
method `is_available()`, added block (currently lines ~226-233):

```php
// If multiple bookings are enabled and the book-again gate (fixed wait time, or the last
// booked slot having ended) is satisfied for the user's booked answer, a new book-again
// round is legitimately starting - the OLD answer's own cancellability (computed above)
// must not block that round just because it still sits in BOOKED state until the round's
// own commit demotes it. Mirrors alreadybooked::is_available()'s own identical check.
if (!$isavailable) {
    $currentanswer = $bookinganswer->get_users()[$userid] ?? null;
    if (!empty($currentanswer) && multiplebookings::book_again_due((int)$settings->id, $currentanswer)) {
        $isavailable = true;
    }
}
```

**The problem:** in this class, `$isavailable == true` means *"hide the cancel button"* — the
method starts with `$isavailable = false; // Not available to begin with` and every subsequent
branch that blocks cancellation explicitly sets it back to `true`, with comments like
*"True means cancel button is not shown"* (a few lines above, in the existing price/shopping-cart
branch). This is the **opposite** polarity of `alreadybooked::is_available()`, where `true` means
*"show the book-again button"*.

The new block was copied from `alreadybooked::is_available()` ("Mirrors alreadybooked's own
identical check") without flipping the polarity for `cancelmyself`'s inverted semantics. The
result: whenever `multiplebookings::book_again_due()` is satisfied (the default case — mode
`MODE_AFTER_DURATION` with `allowtobookagainafter = 0` returns `true` immediately after booking,
see `classes/option/fields/multiplebookings.php:222-238`), this block sets `$isavailable = true`
and **hides** the "Undo my booking" button, right after the user just booked.

## Why this explains the observed CI failures

All 4 scenarios in `booking_multiple_bookings.feature` that check for "Undo my booking" fail; the
one scenario that does *not* fail (`allowtobookagainafter=2`) is exactly the case where
`book_again_due()` returns `false` immediately after booking (gate not yet due), so this block
never fires. `alreadybooked`'s own "Book again (already booked N times)" text is a *different*
condition class and renders correctly — consistent with the failure reports (the count text always
shows up fine; only the cancel button is missing).

## Suggested direction (for discussion, not yet applied)

The block appears to have no real job to do at all: `cancelmyself` only blocks cancellation
earlier in the same method for specific, narrow cases (`iamreserved` + elective, `cancancelbook`
disabled, `notbooked`, expired `canceluntil`, cooling-off period, locked slot). A freshly booked,
still-cancellable answer never falls into any of those — so removing the block outright (rather
than flipping its polarity) is the simplest candidate fix. Flipping the polarity instead (setting
`$isavailable = false` when the gate is due) would be a no-op given the same reasoning, so it
doesn't obviously buy anything either way; worth asking the author what behavior was actually
intended here before picking one.

## Suggested verification once a direction is agreed

A `@covers cancelmyself::is_available` PHPUnit test with `multiplebookings=1`,
`allowtobookagainafter=0`, one booking, asserting the condition still allows cancellation
immediately after — this exact combination doesn't seem to exist in the current suite yet.

---

## Related, separately confirmed findings from the same investigation (2026-08-25)

Not the same bug as above, but surfaced while chasing the same CI failure report and worth
bringing into the same conversation with the developer.

### Confirmed via existing (currently red) PHPUnit tests — slot capacity purchase family

Running `tests/local/slotbooking/` (102 tests) turns up 8 real, reproducible failures, all one
family: buying additional slot capacity (independent of `multiplebookings`) is broken two ways.

1. **Overwrite instead of insert** (`slot_repeat_purchase_answer_test.php`, 3 failures — **FIXED
   2026-08-25**): buying a second, different slot created only 1 `booking_answers` row instead of
   2 — the second purchase silently overwrote the first. Root cause per that test's own docblock:
   the old `slot_availability::has_remaining_slot_capacity()` mechanism that used to force
   `$currentanswerid = null` (INSERT instead of UPDATE) in `booking_option.php`'s
   `user_submit_response()` was removed on 2026-08-12 (`12cc107c5`/`90ca5b0eb`, "Slot booking does
   define how many slots can be booked at once") when capacity *validation* moved to
   `slotbooking::hard_block()` / `save_slot_selection.php` — but nothing was put back for the
   *DB-write* decision. Two separate concerns, only one got a new home. Fix: restored
   `has_remaining_slot_capacity()` in `slot_availability.php` and the `$hasslotcapacity`-based
   insert-vs-update decision in `booking_option.php`. A third, closely related spot needed the same
   fix to make the *real* browser "Continue" flow work (not just direct `user_submit_response()`
   calls): `alreadybooked::is_available()` already had this exact bypass for `multiplebookings` but
   not for slot capacity, so `bo_info::load_pre_booking_page()`'s "already booked" top-blocker gate
   (`MOD_BOOKING_BO_COND_BOOKED_STATES`) silently swallowed the commit — confirmation page reports
   success, nothing gets booked. Added the same slot-capacity check there too. Verified: `phpcs`
   clean on all 3 changed files, all 3 `slot_repeat_purchase_answer_test.php` tests green, no
   regressions across 40 related tests (multiplebookings + condition_all + slotbooking
   cancel/repeat-purchase suites).
2. **multiplebookings-gate wrongly blocks pure slot-capacity purchases** (`slotbooking_external_test.php`
   + `slotbooking_form_test.php`, 5 failures, error text *"You already have a booking for this
   option and booking again is not currently allowed."*) — **FIXED 2026-08-25**. Root cause: the
   same 2026-08-12 commit added a check to `slotbooking::hard_block()` / `save_slot_selection.php` /
   `slotbooking_form.php::validation()` requiring `multiplebookings` to be enabled AND
   `book_again_due()` before any additional slot can be selected — even just re-selecting a slot
   the user already holds. This directly contradicted the intent documented inline in
   `tests/behat/booking_slotbooking_session.feature:68`: *"slot capacity purchases are independent
   of the 'book again' setting"*.
   Fix: replaced the multiplebookings-based gate with a pure slot-capacity check. New method
   `slot_availability::has_capacity_for_selection($optionid, $userid, $requestedkeys)` — slot keys
   the user already owns among `$requestedkeys` don't count as "new", only genuinely additional keys
   are checked against `max_slots_per_user`. This distinction (found via an actual test failure
   during verification, not anticipated up front) is what lets a re-validation of an
   already-booked/cached selection pass even while at capacity, while a request for a truly new slot
   beyond capacity is still correctly blocked with the (previously dead) `slot_error_max_slots_reached`
   string. Applied across `slot_availability.php` (new method), `slotbooking.php::hard_block()`,
   `save_slot_selection.php::execute()`, and `slotbooking_form.php::validation()` (all three call
   sites). Verified: `phpcs` clean on all 4 files; 62 directly relevant tests green (including the
   regression guard for the re-validation case); broader sweep of 80 tests (slotbooking suite +
   multiplebookings + condition_all + persistent-calendar) green except the pre-existing,
   separately-tracked bug 3 failure below, unchanged.
3. **Not a code bug — the test itself was wrong** (`slot_persistent_calendar_test.php`,
   `test_cancel_button_shown_alongside_inline_calendar_when_capacity_remains`, 1 failure) —
   **RESOLVED 2026-08-25 by rewriting the test**, no production code changed. Traced the failure to
   `booking_bookit.php`'s `MOD_BOOKING_BO_BUTTON_CANCEL` case, which has an
   `&& empty($settings->slotconfig)` guard (added 2026-08-10, "GH-2054 Added possible multi option
   calendar view") that unconditionally suppresses the generic bottom-level cancel button for every
   slot-booking option. The test (added 2026-07-10, unrelated to and predating the GH-1506 refactor
   - already red before any of this investigation's changes) asserted the opposite: that this
   button should appear once capacity remains. Per Georg (2026-08-25): this is deliberate UX, not a
   bug - cancelling a slot option happens via the link inside the user's booked slot in the
   calendar itself (which takes them to the option's own page to cancel there), not via a generic
   bottom button, which would be ambiguous once a user holds more than one booked slot. The guard
   in `booking_bookit.php` already does the right thing and was left unchanged; only the test was
   rewritten (renamed to `test_no_cancel_button_shown_alongside_inline_calendar_when_capacity_remains`,
   asserts the button is absent) to match the actual intended behavior. Verified: phpcs clean, all
   4 tests in the file green.
   Does **not** explain `booking_slotbooking_fixed.feature` scenario 7 ("Undo my booking" missing
   after freeing up a slot) from the original CI report after all - see the fresh investigation and
   fix below.

### Scenario 7: "Undo my booking" missing from the plain option list row - FIXED 2026-08-25

Re-investigated from scratch after bug 3 turned out to be a wrong test rather than a code bug.
Traced `booking_slotbooking_fixed.feature`'s "cancelling a booked slot frees it up for booking
again" scenario to the exact same `booking_bookit.php` slotconfig guard as bug 3 above
(`case MOD_BOOKING_BO_BUTTON_CANCEL: if (... && empty($settings->slotconfig)) { ... }`) - but this
time the guard genuinely is too broad. It suppresses the cancel button for **every** rendering
context of a slot option, not just the persistent-calendar one bug 3 is about. The plain option
list row (`bookingoptions_wbtable::col_booknow()`, e.g. `.allbookingoptionstable_r1`) always calls
`render_bookit_template_data()` with `inlinestartpage=''` - no calendar is ever shown there, so
Georg's "cancel via the link inside the booked slot" rationale for bug 3 doesn't apply; a slot
option must surface "Undo my booking" in the list row exactly like any other option
(`booking_multiple_bookings.feature` scenarios 1-4). Confirmed via a diagnostic PHPUnit test
(written, run, deleted) that with `inlinestartpage=''` the render path already reaches
`output/prepagemodal.php`'s existing `$extrabuttoncondition` merge - the same mechanism that
already correctly surfaces "Undo my booking" for non-slot options - but never gets there because
`$extrabuttoncondition` is kept empty by the guard before that merge is even considered. This guard
has been suppressing the list-row cancel button for every slot option since it was introduced
(2026-08-10, "GH-2054 Added possible multi option calendar view") - 2 days before this exact behat
scenario was even added (2026-08-12) - so it was never GH-1506's doing, a pre-existing dormant bug.

**Fix:** scoped the guard to the persistent-calendar context only
(`inlinestartpage === 'slotbooking'`), leaving bug 3's calendar behaviour untouched:
```php
case MOD_BOOKING_BO_BUTTON_CANCEL:
    if (
        modechecker::use_special_details_page_treatment()
        && (empty($settings->slotconfig) || strcasecmp($inlinestartpage, 'slotbooking') !== 0)
    ) {
        $justmyalert = false;
        $extrabuttoncondition = $result['classname'];
    }
    break;
```
Verified: phpcs clean; the bug-3 persistent-calendar test still green (5/5 in
`slot_persistent_calendar_test.php`, including a new regression guard added for this fix,
`test_cancel_button_shown_in_list_view_with_no_capacity_remaining`); broader sweep of 138 tests
(condition_all + multiplebookings + slotbooking form/external/local suites) green, no regressions.

These three together plausibly explain the CI-reported failures where a *second* slot purchase or
a post-cancel re-render is involved. Not yet fixed; same "propose → agree → developer applies"
approach as the bug above should apply here too.

**Update (2026-08-25, after fixing bug 2) — the previously-dead lang string is now reachable:** with
the multiplebookings-gate replaced, a genuinely exhausted extra slot purchase now correctly surfaces
`slot_error_max_slots_reached` ("You have already reached the maximum number of slots you can book
for this option.") instead of never being reachable at all.

**Update — bug 2 also fully explains the two `booking_slotbooking_userdefined.feature` failures**
(scenarios 9 and 10 from the original CI report), confirmed by code reading, no live repro needed:
`slotbooking_form.php::validation()` (the real server-side check behind the "Continue" submit) and
`save_slot_selection.php` both run the `multiplebookings`/`book_again_due()` "book again not
allowed" gate **first**, and `return`/skip immediately if it fails — *before* ever reaching the
per-slot overlap check (`evaluate_slot_for_user()`) or the max-slots check. So:
- Scenario 9 ("a start time overlapping the student's own booking is rejected") never reaches the
  overlap check at all — the gate fires first (second booking attempt, `multiplebookings` off),
  showing *"You already have a booking for this option and booking again is not currently
  allowed."* instead of the expected *"The selected slot is no longer available."*
- Scenario 10 similarly never reaches a max-slots check. Worth flagging on its own: the lang string
  the test expects, `slot_error_max_slots_reached` = *"You have already reached the maximum number
  of slots you can book for this option."* (`lang/en/booking.php:3642`), **is defined but never
  used anywhere in the codebase** — every actual max-slots code path uses the differently-worded
  `slot_error_selection_toomany` ("Please select no more than N slot(s)") instead, and even that
  one is unreachable here because the book-again gate preempts it.

So of the 10 originally reported CI failures, this investigation now has a concrete explanation for
9 of them: scenarios 1-4 (`cancelmyself` sign flip, this file's main bug) and 5, 7, 9, 10
(the slot-capacity family above). Only 6 and 8 remain open.

### Local environment work (2026-08-25) and its limits

- `moodle_test` (PHPUnit DB) was already current (`2026082401`, matches code) — used it to run the
  above tests and confirm the failures are real, not CI flakiness.
- The `moodle`/`b_`-prefixed Behat DB was stale (`2026082100`, missing the new
  `booking_options.waitlistopenmode` column). Reinitialized with
  `php admin/tool/behat/cli/init.php --disable-composer`; confirmed afterwards (via a fresh DB
  connection using the `b_` prefix — mutating `$CFG->prefix` on the already-connected `$DB` does
  **not** actually switch it, a trap worth remembering) that the Behat DB is now on `2026082401`
  with the column present.
- Also had to add a `127.0.0.1 selenium` entry to `/etc/hosts` — the Selenium/Chromedriver server
  was already running locally, just not reachable under the Docker-style hostname the profile
  config expects.
- **However**, live reproduction of scenarios 6 and 8 in this sandbox hit a wall unrelated to the
  code under investigation: the slot-picker modal fails to open after the "Book now" click for
  *every* slotbooking scenario tried here, including ones the CI run reports as passing (verified
  with `booking_slotbooking_session.feature`'s first scenario and `booking_slotbooking.feature`'s
  first scenario — both fail locally at the exact same "modal never opens" point, no PHP error in
  `apache2/error.log`). This looks like a rendering/timing characteristic of this particular
  sandboxed Chrome/Selenium setup (possibly the fixed `I wait "1" seconds` steps not being enough
  here), not a reproduction of the actual reported bug. Local Behat is now technically correctly
  set up, but isn't currently a reliable way to chase scenarios 6/8 further from here.
- **Scenario 6 (booking count stays at "10" instead of "9" after a successful booking) — FIXED
  2026-08-25.** Re-investigated from scratch: the live fixed→rolling settings-edit step turned out
  to be a red herring (as the earlier generator-only reproduction already suggested — the count
  transition logic itself was never wrong). Root cause: `slot_availability.php` keeps its own
  per-request static cache of booked ranges (`$bookedslotrangecache`, with an explicit docblock
  saying `clear_request_cache()` must be called "between successive bookings in the same PHP
  request" — but nothing in the actual single-user booking commit path ever called it; only the
  unrelated bulk `local/book_all_students.php` action did). Whenever an availability check reads
  and caches booked ranges (e.g. the calendar rendering that runs right when the user clicks "Book
  now") and a booking is then committed within that SAME request, the count subsequently rendered
  in that same response is stale. Confirmed via a diagnostic PHPUnit test (written, run, deleted)
  reproducing exactly that read-then-book-then-read sequence through the real commit path
  (`user_submit_response()`, not a raw DB insert): the same-request re-read gave a different
  (wrong) number than an explicitly cache-cleared read. Fix: added
  `slot_availability::clear_request_cache($optionid);` to
  `booking_option::refresh_answers_for_option()` — the central "answers changed" hook already
  called by every book/cancel/waitlist-promote code path (~17 call sites), so this one addition
  covers all of them, not just the direct-booking case. Verified: phpcs clean; new regression test
  `tests/local/slotbooking/slot_request_cache_test.php` (confirmed to fail without the fix, pass
  with it); broader sweep of 144 tests (full slotbooking suite + condition_all + multiplebookings +
  slotbooking form/external + persistent-calendar) green, no regressions.
- **Scenario 8** (first session-type booking never shows the confirmation modal) — **still open,
  backend verified clean twice now.** Prior session: reproduced the commit path directly
  (`bo_info::is_available()` with hard-block, then `user_submit_response()`) — no block, no
  exception, answer row created correctly with proper slot JSON. Re-investigated 2026-08-25 with a
  second, more complete approach: ran `fixed` and `session` side by side through the actual
  "Continue" handler `bo_info::load_pre_booking_page()` (not just the low-level write function) -
  the one that both performs the real commit AND returns the confirmation-page data the modal
  renders from. Result: byte-for-byte identical response shape for both slot types (same
  `header,condition/confirmation,footer` templates, same `buttontype`, same confirmation data
  structure - session only has its `dates`/`showdateslabel` fields additionally populated, nothing
  missing or different). Also confirmed **no `session`-specific code path exists anywhere** - not in
  any of the 3 submission-handler files, not in any `amd/src/` JS file; `session` is treated
  identically to `fixed` end-to-end on the server. The PHP/business-logic layer is conclusively
  clean; if this is real (not a CI-side flake), it is purely a frontend/browser-timing issue outside
  what further backend investigation can narrow down. Needs live CI video/screenshot artifacts or a
  working local Behat setup to make further progress - deferred, not investigated further per
  Georg's direction (2026-08-25).

---

## Status of the original 10 CI failures

| # | Scenario | Explained by | Status |
|---|---|---|---|
| 1-4 | `booking_multiple_bookings.feature` (Undo my booking / count) | `cancelmyself` sign flip (main bug, top of this file) | **FIXED** |
| 5 | Slotbooking: books one slot (2nd slot confirmation missing) | Overwrite-instead-of-insert (bug 1) | **FIXED** |
| 6 | Slotbooking: rolling type, count stays at "10" | Per-request cache in `slot_availability` never cleared after a booking commit | **FIXED** |
| 7 | Fixed slotbooking: cancel frees up slot, Undo my booking missing | slotconfig guard in `booking_bookit.php` suppressed the cancel button in every rendering context (not just the persistent calendar) | **FIXED** |
| 8 | Session slotbooking: first booking, no confirmation | Still open — see hypothesis above | Open |
| 9 | Userdefined: overlap not rejected with right message | multiplebookings-gate preempts the overlap check (bug 2) | **FIXED** |
| 10 | Userdefined: max-slots not rejected with right message | multiplebookings-gate preempts the max-slots check (bug 2) + dead lang string | **FIXED** |

8 of 10 are now fixed and verified (1-7, 9, 10). Only scenario 8 remains open.

---

## Separate, still-unaddressed finding: the performance/caching concern from the original report

Raised in the original bug report alongside the test failures (paraphrased): *the refactor may
have introduced DB queries that weren't cached before; if a "no uncached queries during a normal
list view" test starts failing because of that, the fix must be actual caching, not adjusting the
test.*

**Checked whether the existing test already catches this:** `tests/caching_db_reads_test.php` is
currently green (7/7) — but only because none of its 6 test methods (all named
`test_*_singleton_is_db_free` / `test_*_muc_hit_is_db_free` / `test_repeated_settings_pass_does_not_requery`)
render an options list containing a user who is actually on a waitlist. It never exercises the code
paths below, so it can't currently catch this. This is a **latent, untested risk**, not a red test
today.

**Two call sites added by the GH-1506 refactor that run unconditionally, without any request-level
or MUC caching:**

1. **`classes/bo_availability/conditions/onwaitinglist.php:141-142`** — inside `is_available()`,
   reached for every user/option combination where the user is on the waiting list of a
   `useprice` + `waitforconfirmation` option:
   ```php
   (new db_waitlist_offer_repository())->is_open_mode_active($bookinganswer->optionid)
       && !(new db_waitlist_offer_repository())->is_actively_declined($bookinganswer->optionid, $userid)
   ```
   Two fresh `db_waitlist_offer_repository` instances, two `$DB->get_field()`/`$DB->record_exists()`
   calls, every time this condition is evaluated for this user/option — which happens once per row
   in a normal "My booking" list render for anyone on that waitlist.

2. **The three new event-observer adapters** (`classes/event/observer/booking_accepted_waitlist_adapter.php`,
   `unconfirm_waitlist_adapter.php`, `freetobookagain_waitlist_adapter.php`), wired into
   `booking_option.php`'s core booking-completion / unconfirm / free-capacity flow. Each does at
   least one uncached `db_waitlist_offer_repository`/`progression`/`capacity_calculator` DB read —
   and they fire for **every** booking, cancellation, and capacity-freeing event on **every**
   option, including ones with no waitlist configured at all, not only when waitlist-progression
   logic is actually relevant.

**Why this matters:** (1) is a per-row list-rendering cost (scales with list size × users on
waitlists), (2) is a per-action cost on the hot booking/cancel path (scales with booking volume
plugin-wide). Neither is behind a cheap "does this option even use a waitlist" short-circuit or a
cache.

## Performance fixes applied (2026-08-25)

Design direction agreed with Georg: prefer using data already loaded on `booking_option_settings`
over adding new caches, and deliberately leave `progression.php` alone (it receives its
`waitlist_offer_repository` via dependency injection, used by tests to inject fakes - reading
settings directly there would bypass that abstraction for comparatively little gain, since
`reconcile()` only runs once per booking/cancel action, not once per list row).

### Fix A: `waitlistopenmode` — FIXED and verified

`booking_option_settings` already loads the *entire* `booking_options` row (`bo.*` via
`get_options_filter_sql()`) but never kept `waitlistopenmode` from it, so
`db_waitlist_offer_repository::is_open_mode_active()` fired a brand-new `$DB->get_field()` call
every time `onwaitinglist::is_available()` ran — once per row in a waitlisted user's booking list.
Fix: exposed `waitlistopenmode` as a real property on `booking_option_settings` (populated for free
from the already-fetched row), and `onwaitinglist.php` now reads `$settings->waitlistopenmode`
instead of querying it fresh.

**Caching pitfall found and fixed during verification** (exactly the same class of bug as scenario
6's fix above): `db_waitlist_offer_repository::activate_open_mode()`/`deactivate_open_mode()` write
`waitlistopenmode` via a raw `$DB->set_field()` call that bypasses `booking_option::update()` (and
therefore its cache purge). This was harmless while the read side always queried fresh - but once
the read side started trusting the cached/singleton settings object, a stale singleton would keep
seeing the pre-activation value. Caught by the existing test
`waitlist_openmode_heartbeat_activation_test.php` (verified to pass without this change and fail
with it, confirming it's a real regression, not a bad test) - fixed by having both
`activate_open_mode()` and `deactivate_open_mode()` purge the `bookingoptionsettings` MUC cache and
destroy the settings singleton for that option right after the write.

Verified: phpcs clean; the regression-catching test now green; full `tests/local/waitlist/` suite
(109 tests) green, 0 failures (only pre-existing, unrelated warnings); `condition_all_test.php` and
`caching_db_reads_test.php` green.

### Fix B (adapters short-circuit) — tried, reverted: the invariant it relied on doesn't hold

Attempted: skip `booking_accepted_waitlist_adapter::accept()` / `unconfirm_waitlist_adapter::decline()`'s
`get_open_offers()` query when `$settings->waitforconfirmation` is empty, on the assumption that
offers under the new mechanism only ever exist when it's set (true for the normal
`progression::offer()`-driven flow). **Wrong as a general invariant**: caught immediately by
`b7_accept_adapter_no_recursion_test.php`, which deliberately creates an option with
`waitforconfirmation` NOT set and an offer via `db_waitlist_offer_repository::create_offer()`
directly (bypassing `progression::offer()`) specifically to unit-test the adapter's own accept
behaviour independent of that setting. Verified the failure is real (test passes without the
change, fails with it). Both adapters were reverted to their original state - phpcs clean,
`git diff` empty for both files, `b7_accept_adapter_no_recursion_test.php` green again. Not worth
pursuing further: these two call sites are lower-impact than Fix A (once per accept/unconfirm
action, not once per list row) and any correct guard would need to be at least as expensive as the
query it's trying to avoid.

### Fix C (`is_actively_declined`/`get_permanently_declined_userids` request cache) — tried, reverted

Attempted the same per-option request-cache pattern as scenario 6's `slot_availability` fix,
routing `is_permanently_declined()`, `is_actively_declined()`, and `get_permanently_declined_userids()`
through a shared cached map, invalidated in the class's own two write methods
(`lock_permanently()`, `reset_expired_locks()`). **Reverted**: 11 test failures across most of the
`tests/local/waitlist/` suite (b1, c3, c5, c6, c7, d1, d2, d3, `progression_test`,
`waitlist_openmode_fresh_candidate_after_reset_test`) - all traced to the same root cause:
`$DB->insert_record('booking_waitlist_declines', ...)` directly in test setup code (bypassing
`lock_permanently()` entirely) is the standard, widespread precondition-setup pattern across this
whole test suite - not an isolated case like `activate_open_mode()`'s raw write in Fix A. Caching
here would require auditing and rewriting a large number of tests to go through the repository's
own write methods instead, which is out of scope for a performance pass. Cleanly reverted via
`git checkout` (verified diff empty, phpcs clean) - **note for next time**: this file also held
Fix A's `activate_open_mode()`/`deactivate_open_mode()` cache-invalidation fix, so the blanket
revert briefly took that out too; caught immediately by
`waitlist_openmode_heartbeat_activation_test.php` failing again, and Fix A's portion was
re-applied on its own. Full suite re-verified green (139 tests, 0 failures) afterwards.

**Conclusion for this performance pass:** Fix A (`waitlistopenmode`) is the only one that landed.
Fix B and Fix C both ran into the same underlying issue - this codebase's test suites (and
`local/book_all_students.php`) routinely write directly to the DB tables these caches would need to
track, bypassing the classes that would otherwise invalidate them - and neither was worth the
invasiveness of fixing every direct-write call site for a per-row (Fix C) or per-action (Fix B)
saving. `is_actively_declined()`'s per-user query remains, unaddressed.

# Multiple Bookings Override Mechanism

## Problem

When `multiplebookings` is enabled on a booking option, a user who is already booked sees
**two** interactive areas inside the single `booking-button-area` container:

- **Top area** (`booking-button-toparea`) — rendered by `cancelmyself`, carries the cancel action.
- **Main area** (`booking-button-mainarea`) — rendered by `bookitbutton`, carries the book-again action.

Both areas share the same parent `div.booking-button-area`, which holds all `data-*` attributes
used by the JS to call the `mod_booking_bookit` webservice. A single click anywhere on the
container therefore reached the webservice with identical payload, making it impossible to
distinguish a cancel intent from a book-again intent purely from the dataset.

Additionally, `cancelmyself (id=105)` blocks the booking path. When the webservice evaluates
`bo_info::is_available()` with `$hardblock = true`, `cancelmyself` wins as the highest
relevant blocking condition (id 105 \> bookit-button id), and the cancel path is always
executed — regardless of which button the user actually clicked.

---

## Solution

### 1. `overrideids` data attribute on book-intent buttons

`bookitbutton.php` and `confirmbookit.php` inject an `overrideids` data attribute onto the
rendered button when `multiplebookings` is set:

```php
// bookitbutton.php and confirmbookit.php — only when multiplebookings is active
$data['overrideids'] = json_encode([
    MOD_BOOKING_BO_COND_CANCELMYSELF,    // 105
    MOD_BOOKING_BO_COND_CONFIRMCANCEL,   // 170
]);
```

The Mustache template renders this as `data-overrideids="[105,170]"` on the outer
`div.booking-button-area`.

### 2. JS strips `overrideids` on cancel clicks

`bookit.js` has a capture-phase listener that intercepts clicks on `.bo-cancel-button`
elements *before* Bootstrap's modal handlers can interfere. This handler now strips
`overrideids` from the dataset copy it sends to the webservice:

```js
// bookit.js — capture-phase cancel handler
const cancelData = {...button.dataset};
delete cancelData.overrideids;
bookit(itemid, area, userid, cancelData);
```

Clicks on the main (book-again) area reach the bubble-phase handler, which sends
`button.dataset` including `overrideids` unchanged.

### 3. Server-side override validation (`bookit_request_overrides.php`)

`booking_bookit::bookit()` instantiates `bookit_request_overrides::from_data($data)` and
calls `consume_option_ignored_condition_ids($settings)`. This helper:

- Parses `overrideids` from the JSON payload.
- **Only acts** when `multiplebookings` is set on the booking option.
- **Narrows** the client-supplied IDs to an explicit server-side allowlist:
  - `MOD_BOOKING_BO_COND_CANCELMYSELF` (105)
  - `MOD_BOOKING_BO_COND_CONFIRMCANCEL` (170)
- Returns the validated IDs to be passed to `bo_info::is_available()` as `$ignoredconditionids`.

The server remains fully authoritative. The client can only request narrowly scoped skips;
arbitrary condition IDs are silently discarded.

### 4. `cancelmyself::hard_block()` always returns `true`

Before this implementation `hard_block()` returned `false` when `multiplebookings` was set,
effectively making `cancelmyself` a soft block that never actually prevented booking. This was
reverted:

```php
// cancelmyself.php
public function hard_block(booking_option_settings $settings, $userid): bool {
    return true;
}
```

The override mechanism (see above) is now the *only* way to bypass `cancelmyself` in a
multiple-bookings scenario — driven by which button the user consciously clicked.

---

## Data flow summary

```
User clicks CANCEL button
  └─ JS capture handler fires
       └─ strips overrideids from dataset copy
       └─ calls bookit(... , cancelData)           // no overrideids
            └─ bookit_request_overrides → []
            └─ bo_info::is_available()             // cancelmyself blocks (id 105)
            └─ → cancel path executed

User clicks BOOK AGAIN button
  └─ JS bubble handler fires
       └─ sends button.dataset incl. overrideids=[105,170]
            └─ bookit_request_overrides → [105, 170]  (validated, multiplebookings set)
            └─ bo_info::is_available()             // cancelmyself + confirmcancel skipped
            └─ → bookitbutton (id 1) is highest blocker → confirm-booking cache set

User clicks CONFIRM BOOKING button  (rendered by confirmbookit)
  └─ JS bubble handler fires
       └─ sends overrideids=[105,170]              (set by confirmbookit.render_button)
            └─ bookit_request_overrides → [105, 170]
            └─ bo_info::is_available()             // stale cancel cache ignored
            └─ → confirmbookit (id -80) triggers → booking executed
```

---

## Files changed

| File | Change |
|---|---|
| `bo_availability/conditions/bookitbutton.php` | Sets `overrideids` on `$data` for book-again context |
| `bo_availability/conditions/confirmbookit.php` | Sets `overrideids` on `$data` for multiplebookings confirm step |
| `bo_availability/conditions/cancelmyself.php` | `hard_block()` reverted to always return `true` |
| `bookit_request_overrides.php` | New helper: parses, validates, one-shot consumes override IDs |
| `bo_availability/bo_info.php` | `is_available()` / `get_condition_results()` accept `$ignoredconditionids` |
| `booking_bookit.php` | Instantiates override helper, passes result to `is_available()` |
| `amd/src/bookit.js` | Cancel capture handler strips `overrideids` before sending |
| `templates/bookit_button.mustache` | Renders `data-overrideids="{{.}}"` via Mustache section |

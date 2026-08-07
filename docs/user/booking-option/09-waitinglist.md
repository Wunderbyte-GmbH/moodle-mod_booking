# Waiting list

How the waiting list of a booking option behaves: when users move up, what happens
when limits are reduced, and which settings change the behaviour.

## Basics

A booking option gets a waiting list through two fields in
[General settings](01-general.md):

| Field | Meaning |
|-------|---------|
| **Max. number of participants** (`maxanswers`) | Confirmed seats. `0` = unlimited (no waiting list needed). |
| **Max. number of waiting list places** (`maxoverbooking`) | `0` = waiting list off, `-1` = unlimited waiting list, any other number = that many waiting places. |

When all seats are taken, new bookings land on the waiting list (in booking order)
until it is full, too. With the *Ranked waiting list* site setting
(`waitinglistshowplaceonwaitinglist`) users see their position on the list.

## Moving up (promotion)

The waiting list is synchronized automatically whenever a seat frees up or the
limits change:

- **When:** a booked user cancels, `maxanswers` is increased, an option becomes
  unlimited, or a booking campaign changes the limits at its start/end.
- **Order:** the user who has been **waiting longest moves up first**.
- **Effects:** the user is booked, enrolled into the linked Moodle course (if
  configured) and receives a *status changed* notification.

**A waiting user is NOT moved up when:**

| Exception | Why |
|-----------|-----|
| The option has a **price** | A seat has to be *bought* — nobody is booked automatically onto a paid option. The seat stays free until someone buys it. |
| **Demand confirmation** is active (`waitforconfirmation`) | The user first needs the required [confirmation](08-confirmation.md); unconfirmed users are skipped. |
| Site setting **Turn off waiting list globally** (`turnoffwaitinglist`) | The whole waiting list feature is disabled (the form field is hidden, too). |
| Site setting **`turnoffwaitinglistaftercoursestart`** | After the option's course start time, no automatic moving up happens anymore. |
| The option has started and booking after start is not allowed on the instance | Same effect as above, controlled per booking instance (*allowupdate*). |

## Reducing the limits: where do users go?

When `maxanswers` is **reduced** on an option that has more booked users than the
new limit, the option is brought back under its limits in two steps:

1. **Booked → waiting list:** the **most recently booked** users lose their seat
   first (the longest-booked users keep theirs). Demoted users are unenrolled from
   the linked course and receive a *status changed* notification.
2. **Waiting list → removed:** if the waiting list now exceeds `maxoverbooking`,
   the **newest** waiting entries are removed entirely. Affected users receive a
   cancellation notification; a `bookinganswer_cancelled` event with the extra info
   `Answer deleted by sync_waiting_list` is triggered (usable in
   [booking rules](../booking_rules/README.md)).

**This reduction only happens if ALL of the following hold:**

| Gate | Behaviour otherwise |
|------|--------------------|
| Site setting **Keep users booked on limit reduction** (`keepusersbookedonreducingmaxanswers`) is **off** | With the setting on, everybody keeps their current status; the option simply stays overbooked. |
| The option does **not** use demand confirmation (`waitforconfirmation`) | Confirmation-mode options are never reduced automatically. |
| The user saving the option holds **`mod/booking:deleteresponses`** | Without the capability the reduction silently does not touch any bookings. |

**Paid options:** users who booked a priced option always **keep their booking**
when limits are reduced — they are neither demoted nor removed.

**Reducing only the waiting list** (`maxoverbooking`) does not remove existing
waiting users: they keep their place, but no new users can join the list beyond
the new limit. The same applies when the waiting list is disabled later — existing
waiters are kept.

## Related pages

- [General settings](01-general.md) — Capacity fields
- [Demand confirmation](08-confirmation.md) — Waiting list with manual confirmation
- [Booking rules](../booking_rules/README.md) — React to `bookinganswer_movedupfromwaitinglist`, `bookingoption_freetobookagain` and cancellation events

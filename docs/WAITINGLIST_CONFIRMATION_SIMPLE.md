# Waitinglist confirmation – simplified flow

```mermaid
flowchart TD
    A[Booking option] -->|User tries to book| D{Wait for confirmation setting}

    D -->|No| G[Any user can book/pay given there are free spots]
    D -->|Yes – always| F[User on waitinglist – waiting for confirmation]
    D -->|Yes – on waitinglist only| H{Free places available?}

    H -->|No| F
    H -->|Yes| I{Waitinglist empty?}

    I -->|Yes| J[User is allowed to book]
    I -->|No| F

    F -->|User unsubscribes before confirmation| Q

    F --> K{Manual confirmation by admin/manager}
    K -->|Yes| J

    F --> L{Interval confirmation enabled and executed}
    L -->|No| K
    L -->|Yes| M{Only the notified user can book}

    M -->|No – all confirmed users can book| J
    M -->|Yes – one at a time| N{Is this user the one just notified?}

    N -->|Yes| J
    N -->|No – wait for next interval| F

    J --> P{User cancels their confirmed WL place}

    P -->|No – user keeps their confirmed place| C{Payment required?}
    C -->|No| O[Enrolled immediately]
    C -->|Yes| B[User can pay for the option]

    P -->|Yes – user withdraws from waitinglist| Q[WL record set to DELETED – WL slot freed]
    Q --> R[No booked slot is freed – freetobookagain event does NOT fire]
    R --> S[Remaining WL users keep their position and still need confirmation]
    S -->|User wants to try again| A
```

## Key points

- **A WL user can unsubscribe at any point – before or after confirmation.**
  Whether still unconfirmed (`F`) or already confirmed/allowed to book (`J`), withdrawing sets the
  WL record to `DELETED` and frees the WL slot. In both cases the path leads to `Q`.

- **Cancelling from the waitinglist frees only a WL slot, not a booked slot.**
  The `bookingoption_freetobookagain` event is **not** triggered when a WL user withdraws.
  Other WL users are therefore not auto-promoted and still need to wait for their own confirmation.

- **`waitforconfirmation = 2` (only on waitinglist):**
  A user books directly when both a free slot *and* an empty waitinglist exist.
  Once the WL is occupied, any new booker is queued on the WL and requires confirmation —
  even if a booked slot later becomes available.

- **The interval confirmation sends one or all users a notification, depending on the setting.**
  With *only notified user can book*, only the most recently notified user holds an active
  confirmation at a time. Others must wait for their own interval turn.

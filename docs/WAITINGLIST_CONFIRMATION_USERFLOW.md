# Waiting list + confirmation user flow

This diagram extends the flow from issue #1050 and includes the waiting-list unsubscribe path.

```mermaid
flowchart TD
    A[User opens booking option] --> B{Free booked place available?}
    B -->|Yes| C{waitforconfirmation}
    B -->|No| D{Waiting list has free slots?}
    D -->|No| Z[Result: Fully booked]
    D -->|Yes| E[User books onto waiting list]

    C -->|0 = no confirmation| F{Price required for user?}
    C -->|1 = always confirmation| E
    C -->|2 = only waiting-list confirmation| G{Waiting list empty?}

    G -->|Yes| F
    G -->|No| E

    F -->|No| H[Booked immediately]
    F -->|Yes| I[User can pay / checkout]
    I --> H
    H --> AB{Booked user cancels later?}
    AB -->|Yes| V[Booked place becomes free -> bookingoption_freetobookagain event]
    AB -->|No| AA[Final status: BOOKED]

    E --> J[Status: ON WAITING LIST]
    J --> K{How does user leave waiting list state?}

    K -->|Manual confirmation by manager| L{Price required?}
    K -->|rule_daysbefore / rule_specifictime task triggers| M{confirmationonnotification}
    K -->|User unsubscribes (Undo my booking)| N[Answer set to DELETED]

    M -->|0 = disabled| J
    M -->|1 = notify/confirm eligible users| O[Confirm JSON set for notified user(s)]
    M -->|2 = one-at-a-time| P[Confirm JSON set only for latest notified user; others unconfirmed]
    O --> L
    P --> L

    L -->|No price (or user price = 0)| Q[Moved from waiting list to BOOKED automatically]
    L -->|Price > 0| R[Notified/confirmed user sees pay button]
    R --> S{User pays in time?}
    S -->|Yes| Q
    S -->|No| T[User stays on waiting list]

    N --> W[No new free-place event]
    V --> X[Rules re-evaluate recipients by waiting-list order (timemodified)]
    W --> AC[Existing interval task run re-evaluates recipients]
    AC --> X
    X --> Y{Next waiting-list user exists and rule still applies?}
    Y -->|Yes| M
    Y -->|No| J

    T --> X
    Q --> AA
```

## Important edge cases covered

- **Waiting-list unsubscribe is skipped safely:** when a user deletes their waiting-list entry, later tasks re-check rule applicability and do not process deleted entries.
- **One-at-a-time notification (`confirmationonnotification = 2`):** only the latest notified waiting-list user remains confirmed; previous waiting-list users are unconfirmed again.
- **Price-aware behavior:** if option price is set but user-specific price is `0`, user can be moved from waiting list to booked automatically.
- **No waiting-list users:** if list is empty and free places exist, booking can proceed directly (subject to `waitforconfirmation` and price flow).

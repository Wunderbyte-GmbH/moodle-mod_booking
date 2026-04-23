# Waiting list + confirmation user flow

This diagram extends the flow from issue #1050 and includes the waiting-list unsubscribe path.

```mermaid
flowchart TD
    A[User opens booking option] --> B{Free booked place available?}
    B -->|Yes| C{waitforconfirmation setting}
    B -->|No| D{Waiting list has free slots?}
    D -->|No| Z[Result: Fully booked]
    D -->|Yes| E[User is placed on waiting list]

    C -->|0 = no confirmation required| F{Price required for user?}
    C -->|1 = always require confirmation| E
    C -->|2 = confirmation only for users already on waiting list| G{Is the waiting list already occupied?}

    G -->|No - waiting list empty| F
    G -->|Yes - others are already waiting| E

    F -->|No price| H[Booked immediately]
    F -->|Price > 0| I[User can pay / checkout]
    I --> H
    H --> AB{Does this booked user cancel later?}
    AB -->|Yes| V[Booked place becomes free → bookingoption_freetobookagain event]
    AB -->|No| AA[Final status: BOOKED]

    E --> J["Status: ON WAITING LIST (unconfirmed)"]

    J --> K1{"While on waiting list:\nhow does confirmation happen?"}
    J --> K2[User unsubscribes — 'Undo my booking']

    K1 -->|Admin / manager manually confirms the user| CONF
    K1 -->|rule_daysbefore or rule_specifictime task runs| M{confirmationonnotification setting}

    K2 --> N["Answer set to DELETED\n(waiting-list slot freed, no booked place freed)"]

    M -->|0 = task confirmation disabled| J
    M -->|1 = confirm all eligible users on waiting list| O["Task sets confirmation JSON\nfor all notified users (still on waiting list)"]
    M -->|2 = one at a time| P["Task sets confirmation JSON only for\nthe next user; others are un-confirmed"]

    O --> CONF["User confirmed on waiting list\n= POSSIBILITY TO BOOK\n(status is still WAITINGLIST)"]
    P --> CONF

    CONF --> L{Price required for this user?}
    L -->|No price, or user-specific price = 0| Q["Task auto-moves user:\nWAITINGLIST → BOOKED"]
    L -->|Price > 0| R["User sees pay button\n(still on waiting list until paid)"]
    R --> S{Does user pay in time?}
    S -->|Yes| Q
    S -->|No| T["User stays on waiting list\n(confirmation JSON may be reset)"]

    N --> X[Rule task re-evaluates remaining waiting-list users in timemodified order]
    V --> X
    T --> X
    X --> Y{Next eligible waiting-list user and rule still applies?}
    Y -->|Yes| M
    Y -->|No| J

    Q --> AA
```

## Key points about the flow

- **The task does NOT place users on the waiting list.**
  Users land on the waiting list first (status `WAITINGLIST`, unconfirmed). Only *after* that does a rule task run *from* that waiting-list status to give the user the *possibility to book*.

- **"Possibility to book" is an intermediate state (still on waiting list).**
  The task sets a confirmation JSON flag on the booking_answers record. The user's waiting-list status only changes to `BOOKED` when they are actually auto-booked (no price) or complete payment (price > 0).

- **Waiting-list unsubscribe does not free a booked place.**
  When a waiting-list user unsubscribes, their answer is set to `DELETED`. No `bookingoption_freetobookagain` event fires. The rule task re-evaluates the remaining waiting-list users on its next run.

- **One-at-a-time notification (`confirmationonnotification = 2`):**
  Only one user at a time is left with a confirmation JSON. All other waiting-list users have their confirmation JSON removed, so only the next in line can pay.

- **Price-aware behavior:**
  If the option has a price but the effective user price is `0`, the task auto-books the user directly (no payment step needed).

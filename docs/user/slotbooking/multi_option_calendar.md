[Back to slot booking](README.md)

# Multi-option calendar

Several slot booking options can be shown **together in one calendar**, so participants compare and pick appointments across options instead of opening each option separately. Typical uses: several examiners, rooms, courts or consultation topics, each modelled as its own booking option, presented as a single schedule.

---

## Switching it on

The merged calendar is created with the shortcode `bookingoptionview` by passing **several option ids, separated by commas**:

```
[bookingoptionview optionid="6,7,8" inlinestartpage="slotbooking"]
```

- The **first id is the primary option**. It drives the booking button and the surrounding booking flow.
- All further ids are merged into the same calendar.
- To merge the calendars but hide the option chooser, add `hidesidebar="1"`:

```
[bookingoptionview optionid="6,7,8" inlinestartpage="slotbooking" hidesidebar="1"]
```

### Requirements

- **All combined options must use the same slot type.** Otherwise nothing is rendered and the page shows:
  > *"These booking options use different slot types (e.g. fixed vs. user-defined) and cannot be shown together in one calendar. Please only combine booking options that share the same slot type."*
- For *fixed*, *rolling* and *sessions*, the options must use the **Calendar view** interface. In List view the options are shown individually, because list entries of different options cannot be told apart reliably.
- Options the current user cannot access are silently left out.

---

## What participants see

### The sidebar

To the left of the calendar (above it on small screens), a panel headed **"Booking options"** lists one row per merged option, each with a coloured bar in the option's own colour.

- **Clicking a row hides or shows that option's slots** in the calendar. A hidden option is greyed out and struck through. Filtering happens instantly — no page reload.
- The button **"Invert selection"** at the top right flips the whole list: everything currently shown is hidden and vice versa. This is the quick way to isolate a single option (invert, then click that one back on) or to clear the view.

### The merged calendar

Each option gets its **own lane** in the day timeline, side by side, sharing one time axis. A 10:00 slot of option A therefore sits at exactly the same height as a 10:00 slot of option B, which makes comparing availability across options straightforward. Each option keeps a stable colour and a stable column position across all days, and the colour is repeated on its sidebar row.

### Booking from the merged view

Participants may **book slots of only one option at a time**. When a slot of a different option is clicked, the previous selection is dropped and a notice appears:

> **"Your previous slot selection was cleared because you can only book slots from one option at a time."**

The limits always follow the option that is currently selected: if option A allows two slots per user and option B only one, selecting a slot of B reduces the allowed selection accordingly.

- Booking a slot of the **primary option** continues in the normal booking flow on the same page.
- Booking a slot of one of the **other options** first asks for confirmation. After confirming, the booking is carried out for that option; the participant is then taken to that option's own page, where free bookings are acknowledged with *"Your slot has been booked successfully."* and paid ones lead to the checkout.

The detour exists because every option can have its own pre-booking steps (custom forms, conditions, prices). Rather than guessing that those steps are identical, the flow hands over to the target option's own page whenever anything beyond the plain slot booking is needed.

Slots that belong to another option and are already booked by the participant are marked **Booked** and link to that option, so the booking can be managed where it belongs.

---

## User-defined options in a merged calendar

For the *user-defined* slot type the sidebar behaves differently: it is a **single-select chooser**, not a multi-toggle filter, and the *Invert selection* button is hidden. Only one option can be active at a time, because each option brings its own opening hours, duration choices and start grid to the free-form editor.

Switching the active option redraws the calendar and rebuilds the editor with that option's own duration list; a start time that was chosen under the previous option's opening hours is reset.

---

## Notes for administrators

- The merged view is purely a **presentation** feature. Capacity, buffers, prices, deadlines and examiners are always evaluated per option, exactly as if the option had been opened on its own.
- Options that share the same **entity** (room, resource) still block each other's slots when the entity is occupied — see [Capacity and availability](capacity_and_availability.md#entities-and-shared-capacity). This is what makes a merged calendar of several options on one room behave sensibly.
- There is no upper limit on the number of merged options, but eight distinct colours are available; beyond that, colours repeat.

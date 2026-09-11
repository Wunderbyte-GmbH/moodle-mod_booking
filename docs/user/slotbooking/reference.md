[Back to slot booking](README.md)

# Reference

---

## Site settings

Found under *Site administration → Plugins → Activity modules → Booking*.

| Setting | Meaning | Default |
|---------|---------|---------|
| **Enable slot booking** (`slotbookingactive`) | Switches slot booking on for the whole site. When off, it is unavailable in the option type list, the booking flow and the AI skill, even with a PRO licence. | on |
| **Slot booking count display in option list** (`slot_bookings_display_mode`) | *Show only slots available to the current user* or *Show booked / available places (legacy)* | available to the user |
| **Move/cancel deadline (relative to slot start)** (`slot_change_deadline_minutes`) | Site-wide default deadline in minutes, relative to each slot start. Positive = before start, 0 = until start, negative = after start. Booking instances and options can override it. | 0 |

All three require a PRO licence; without one, the settings page shows the PRO teaser instead.

---

## Capabilities

| Capability | Default roles | Needed for |
|------------|---------------|-----------|
| `mod/booking:choose` | student, teacher, editing teacher, manager | Booking a slot at all |
| `mod/booking:view` | standard | Viewing the slot calendar |
| `mod/booking:bookforothers` | editing teacher, manager | Booking slots for other people (via the cashier) |
| `mod/booking:updatebooking` | course creator, manager | Editing the option and its slot settings, the slot rule editor, moving slots |
| `mod/booking:moveslots` | course creator, manager | Moving other participants' booked slots |
| `mod/booking:moveslotsself` | user, student, editing teacher, manager | Moving or releasing one's own booked slots |
| `mod/booking:manageslotunavailability` | teacher, editing teacher, manager | Examiner unavailability and student examiner assignments |
| `mod/booking:deleteresponses` | editing teacher, manager | Removing slot bookings |
| `mod/booking:skill_mod_booking_create_slotbooking_option` | editing teacher, manager | Creating slot options with the AI assistant |

---

## Pages

| Page | Purpose |
|------|---------|
| `/mod/booking/slotrules.php` | [Slot rule editor](slot_rules.md) |
| `/mod/booking/slotcalendar.php` | [Slot calendar / occupancy](reporting.md) |
| `/mod/booking/slotteacherassignments.php` | [Student examiner assignments](reporting.md) |
| `/mod/booking/moveslot.php` | Staff move a participant's slot |
| `/mod/booking/rebookslot.php` | Participant moves their own slot |

---

## Web services

| Service | Purpose |
|---------|---------|
| `mod_booking_get_slots` | Returns the selectable slots and the picker configuration of an option; further option ids can be merged in |
| `mod_booking_save_slot_selection` | Validates a slot selection, caches it for the booking and returns the price |
| `mod_booking_get_booked_slots` | Returns the occupancy data for the slot calendar |
| `mod_booking_release_slots` | Releases individual booked slots (self-service) |

The first three require `mod/booking:conditionforms` in addition to access to the booking; the last requires `mod/booking:moveslotsself`.

---

## Where the data lives

| Data | Table |
|------|-------|
| Slot configuration of an option | `booking_slot_config` (one row per option; deleting it disables slot booking for that option) |
| Slot rules and their price entries | `booking_slot_rule`, `booking_slot_rule_price` |
| Student → examiner assignments | `booking_slot_student_teacher` |
| Examiner unavailability | `booking_teacher_unavailability` |
| Pending paid rebookings | `booking_slot_moves` |
| The booked slots themselves | on the booking answer in `booking_answers` |

Slots of the *fixed*, *rolling* and *user-defined* types are **virtual** — they are computed from the configuration whenever they are needed, not stored. Only the slots that somebody actually booked exist as data. Slots of the *sessions* type are the option dates.

---

## Known limitations

- **Self-rebooking was designed for slots of the same price.** Upgrades and downgrades are handled (cart or credit), but the feature is young — check the money side when you allow it on paid options.
- **Cross-option entity capacity requires `local_entities` 0.5.0 or newer.** Without it, slots have no entity constraint.
- **Times in the graphical day timeline are shown in 24-hour format**, while lists and tables follow the language convention. The times are the same, the formatting is not yet unified.
- **The slot rule editor link in the Slot Booking Settings section only appears when examiners are enabled.** Use the identical link in the Price section, or the direct URL.
- **The default lengths for user-defined slots are inconsistent** (maximum 30 minutes, minimum 1 hour). Always set both explicitly.
- **The standalone slot pages check capabilities only**, not the site-wide *Enable slot booking* switch. Turning slot booking off hides it from the option form and the booking flow, but a direct link to a slot page still opens.
- **There is no dedicated slot interface in the Moodle app**; the web picker is used.
- **`Max slots per user` limits how many slots are held at once**, and is applied per booking. How often a participant may start a new booking round is governed by *Allow to book again*.

### Open issues under investigation

The following behaviour is known to be under repair and may not work as documented above. See [issue #1525](https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/1525):

- The booking confirmation in the slot modal may not appear, although the booking is created.
- Cancelling a booked slot may not free it up again immediately.
- A second slot purchase may update the first booking instead of creating a separate one.
- The rejection messages for an overlapping slot and for exceeding *Max slots per user* may not be displayed.
- The cancel button may be missing next to the persistent calendar.

[Back to slot booking](README.md)

# Reporting and management

For slot options, the header of the responses page (**Bookings tracker**) offers three additional links.

---

## Slot calendar

`/mod/booking/slotcalendar.php?id=<cmid>&optionid=<optionid>` — requires `mod/booking:view`.

An occupancy calendar of the option: only days and slots that actually have bookings are shown, each day cell displaying **booked / capacity**; fully booked days are highlighted. Selecting a slot opens a detail panel with

- day and time of the slot,
- **Occupancy** — booked places out of the capacity,
- the price of that slot,
- **Booked users** with each booking's status (*Booked*, *On waiting list*, *Reserved*, *On notification list*, *Previously booked*),
- **Booked examiners**,
- a **Move slot** link when exactly one booking occupies the slot.

Before a slot is selected the panel reads *"Select a slot to view booked students."*; days without bookings show *"No booked slots on this day."*

---

## Student examiner assignments

`/mod/booking/slotteacherassignments.php?id=<cmid>&optionid=<optionid>` — requires `mod/booking:manageslotunavailability` or `mod/booking:updatebooking`, or being a teacher of the option.

Assigns one or more examiners from the option's **examiner pool** to each enrolled student: *"Assign one or more examiners from this option's examiner pool to each enrolled student."*

Every student is listed with name and e-mail and gets a multi-select of pool members. Students who have no assignment yet start with the option's regular teachers preselected. Saving replaces all assignments of the option.

The effect on booking: a student who has assigned examiners can **only** book slots in which those examiners are free, and can only choose from among them.

If the pool is empty the page shows *"No examiners are configured in this option's examiner pool."*; without enrolled students, *"No enrolled students found in this course."*

---

## Examiner unavailability

Blocks times in which an examiner must not be booked — holidays, other duties. Entries can be tied to this option or apply site-wide, and they are respected by every slot option that uses that examiner.

Requires `mod/booking:manageslotunavailability` or `mod/booking:updatebooking`, or being a teacher of the option.

---

## Columns in the responses list

For slot options the responses list and the bookings tracker show extra columns:

| Column | Content |
|--------|---------|
| **Start time** / **End time** | First start and last end of the booking |
| **Booked slots** | Number of slots in the booking |
| **Assigned examiners** | Per slot: date, time and the examiners assigned to it |
| **Slot price paid** | The price stored with the booking at the time of booking (or of the last move) |
| **Move slot** | Link to the move editor — only for users who may move slots |

These columns are also included in downloads (Excel, CSV), using the download date format.

---

## What is not possible on the responses page

Participants cannot be subscribed to a slot option by hand:

> *"Because slot booking is enabled for this option, users cannot be booked here."*
> *"Bookings can be made via the cashier."*
> *"Users can be unenrolled from this option in the list on the responses page."*

The reason is that a slot booking must always carry a concrete slot. Bookings on behalf of somebody else are therefore made through the **cashier** of `local_shopping_cart`, where the slot is chosen in the same picker, or by the participant.

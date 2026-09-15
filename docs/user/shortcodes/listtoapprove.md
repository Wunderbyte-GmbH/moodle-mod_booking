[Back to shortcode index](README.md)

# `[listtoapprove]`

Shows bookings that are currently waiting for **approval / confirmation**.

This shortcode renders the booking approval UI based on the `booked_users` renderer scope `optionstoconfirm` or `optionstoconfirmreduced`.

---

## Syntax

```text
[listtoapprove]
[listtoapprove reduced="1" cfinclude="department,costcenter"]
```

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `reduced="1"` | Use the reduced approval view (`optionstoconfirmreduced`). |
| `cfinclude="shortname1,shortname2"` | Include these custom fields in the approval list. |
| `deputyselect="1"` | Enable deputy selection if the booking extension configuration supports it and the current user has the required capability. |

---

## Behaviour details

- The shortcode validates the global booking-shortcode checks (enabled, password, PRO licence).
- `deputyselect` only has an effect when:
  - the config `bookingextension_confirmation_supervisor/deputy` is set, and
  - the current user has `mod/booking:assigndeputies` in system context.
- Rendering is delegated to the booking renderer through `render_booked_users()`.

---

## Example

```text
[listtoapprove reduced="1" deputyselect="1"]
```

---

## Related shortcodes

- [supervisorteam](supervisorteam.md) — team overview rather than approval queue
- [bulkoperations](bulkoperations.md) — option administration instead of answer approval

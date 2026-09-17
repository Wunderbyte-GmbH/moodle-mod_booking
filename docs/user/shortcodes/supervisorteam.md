[Back to shortcode index](README.md)

# `[supervisorteam]`

Shows bookings belonging to the users in the current supervisor's team.

The shortcode renders the `booked_users` UI in `supervisorteam` or `supervisorteamreduced` scope.

---

## Syntax

```text
[supervisorteam]
[supervisorteam reduced="1" cfinclude="department,costcenter"]
```

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `reduced="1"` | Use the reduced supervisor-team view. |
| `cfinclude="shortname1,shortname2"` | Include these custom fields in the output. |

---

## Behaviour details

- The rendered list includes booked users, users on the waiting list, and reserved answers.
- It does not include notify-list users, deleted users, or booking history.
- Rendering is delegated to `render_booked_users()` in the booking renderer.
- If the current supervisor context does not produce any users, the shortcode simply renders an empty result from that renderer.

---

## Example

```text
[supervisorteam reduced="1" cfinclude="department"]
```

---

## Related shortcodes

- [listtoapprove](listtoapprove.md) — approval queue for managers
- [mycourselist](mycourselist.md) — own bookings rather than team bookings

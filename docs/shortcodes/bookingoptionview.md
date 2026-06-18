[Back to shortcode index](README.md)

# `[bookingoptionview]`

Renders one direct **Book it / booking option action button** for a specific booking option.

Use this when you want a single CTA instead of a full booking options table.

---

## Syntax

```text
[bookingoptionview optionid="123"]
[bookingoptionview optionid="123" inlinestartpage="cart"]
```

---

## Required parameters

| Parameter | Meaning |
|-----------|---------|
| `optionid="123"` | The booking option id, not the booking activity `cmid`. |

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `inlinestartpage="cart"` | Inline start-page identifier forwarded to `booking_bookit::render_bookit_button()`. |

---

## Behaviour details

- The shortcode loads the booking option settings through `singleton_service::get_instance_of_booking_option_settings()`.
- If the option cannot be loaded, it returns the generic booking shortcode error string.
- Rendering is delegated to `booking_bookit::render_bookit_button()` for the current user.
- All usual booking rules, permissions, conditions, and availability checks still apply inside that rendering step.

---

## Example

```text
[bookingoptionview optionid="123"]
```

---

## Related shortcodes

- [courselist](courselist.md) — full option list instead of one button
- [linkbacktocourse](linkbacktocourse.md) — reverse navigation from a linked course

[Back to shortcode index](README.md)

# `[linkbacktocourse]`

Shows one or more links back to booking options that are linked to the **current Moodle course**.

Use this on a Moodle course page when participants should be able to navigate back to the booking option from which they came.

---

## Syntax

```text
[linkbacktocourse]
```

---

## Parameters

The current implementation has no shortcode-specific parameters.

Only the global shortcode password parameter applies when booking shortcodes are password-protected.

---

## Behaviour details

- The shortcode looks up booking options where `booking_options.courseid` matches the current `$COURSE->id`.
- For invisible options, the user must still have `mod/booking:view` in the corresponding booking activity context.
- Each result link points to `/mod/booking/optionview.php` with `optionid`, `cmid`, `userid`, and return URL information.
- In AJAX or webservice requests, the return URL fallback is `/`.
- If no linked booking options are found, the shortcode outputs nothing.

---

## Example

```text
[linkbacktocourse]
```

---

## Related shortcodes

- [bookingoptionview](bookingoptionview.md) — render one booking CTA directly
- [courselist](courselist.md) — show the full list of options from one booking activity

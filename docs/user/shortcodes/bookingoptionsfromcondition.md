[Back to shortcode index](README.md)

# `[bookingoptionsfromcondition]`

Outputs the titles of completed booking options from a **certificate condition context**.

This shortcode is not a general page shortcode. It is intended for text that is rendered while a booking certificate condition is being evaluated.

---

## Syntax

```text
Completed courses: [bookingoptionsfromcondition]
```

---

## Parameters

The current implementation has no shortcode-specific parameters.

---

## Behaviour details

- The shortcode reads its execution context from `singleton_service::get_temp_values_for_certificates()`.
- If no user id is present in that temporary certificate context, it returns the literal placeholder string `PLACEHOLDER`.
- It loads all certificate-condition items with:
  - `area = bookingoption`
  - `component = mod_booking`
- For every referenced booking option, it checks whether the current user completed the activity.
- Output is built from the booking option title, and if teachers are assigned, a teacher list is appended.
- Multiple matching items are joined with the translated delimiter `delimiterbookingoptionsfromcondition`.

---

## Example use case

Use it inside a certificate template or certificate-related text that is evaluated in booking certificate context.

```text
You have completed the following booking options:
[bookingoptionsfromcondition]
```

---

## Related docs

- [Booking conditions](../booking_conditions/README.md)
- [Placeholders](../placeholders/README.md)

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

- The shortcode reads its execution context from `singleton_service::get_temp_values_for_certificates()`
  (user, triggering booking option, certificate condition).
- If no user id is present in that temporary certificate context, it returns the literal placeholder string `PLACEHOLDER`.
- The listed options depend on what issued the certificate:
  - **Booking option**, **Tagged options**, **Booking instance options** conditions: the booking options of the
    condition the user has completed.
  - **Combination** without "Groups must be completed in order": all completed booking options of its building blocks.
  - **Combination** with "Groups must be completed in order": exactly the options that earned the certificate
    (the earliest qualifying completions of each group).
  - Certificate stored on the booking option (no condition): the option itself and the completed options it
    requires ("requires other options").
- The options are listed in the order of their completion.
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

[Back to shortcode index](README.md)

# `[bookingoptionsfromconditionwithdate]`

Outputs the titles of the completed booking options a certificate counts, each followed by the date the
option was completed on. Apart from the date it behaves exactly like
[`[bookingoptionsfromcondition]`](bookingoptionsfromcondition.md).

This shortcode is not a general page shortcode. It is intended for text elements of a certificate template
that is rendered while Booking issues a certificate.

---

## Syntax

```text
Completed courses:
[bookingoptionsfromconditionwithdate]
```

---

## Parameters

None.

---

## Output

One entry per option, in the order of completion, joined with the delimiter string
`delimiterbookingoptionsfromcondition` (default: line break):

```text
Option A (03.02.2026)
Option B1 (17.04.2026)<br>Teachers: Jane Doe, John Roe
```

- The date is the completion date of the booking (`completeddate`, the modification time of the booking as
  fallback). It uses the same format as the *Awarded on* field of the certificate, the language string
  `timeawardedformat` (default `%d.%m.%Y`).
- The wording "title (date)" is the language string `optionwithdatebookingoptionsfromcondition`
  (`{$a->text} ({$a->date})`) and can be changed with language customisation.
- An option whose completion is not stored yet (the option itself when the certificate is stored on the option
  and the completion is written after the evaluation) is listed without a date.
- Teachers are appended after the date, like in `[bookingoptionsfromcondition]`.
- Outside the issue of a certificate (no user in the certificate context) the shortcode returns the literal
  string `PLACEHOLDER`.

Which options are listed depends on what issued the certificate; see the behaviour details of
[`[bookingoptionsfromcondition]`](bookingoptionsfromcondition.md).

---

## Related docs

- [`[bookingoptionsfromcondition]`](bookingoptionsfromcondition.md)
- [Certificates and certificate conditions](../certificates/README.md)

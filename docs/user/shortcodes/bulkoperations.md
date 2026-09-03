[Back to shortcode index](README.md)

# `[bulkoperations]`

Shows the booking **bulk operations** management table.

This shortcode is intended for site administrators and managers who need to edit many booking options, send mail to teachers, or export/filter large sets of options.

---

## Syntax

```text
[bulkoperations]
[bulkoperations customfields="spt1" filter="institution,coursestarttime,spt1" intrangefilter="titleprefix"]
```

---

## Required permissions

The current user must be:

- a **site admin**, or
- a user with `mod/booking:executebulkoperations` in system context

Without that permission, the shortcode returns `nopermissiontoaccesscontent`.

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `perpage="50"` | Rows per page. |
| `customfields="shortname1,shortname2"` | Add selected booking custom fields as visible columns. |
| `columns="bookingopeningtime,courseendtime"` | Add extra plain columns by technical column name. |
| `download="1"` | Show the download button. |
| `filter="institution,coursestarttime,spt1"` | Define filter widgets to expose. Custom field shortnames are supported here too. |
| `intrangefilter="titleprefix"` | Add integer-range filters for the given columns. |
| `customfieldfilter="shortname1,shortname2"` | Add custom-field filters explicitly through the helper. |

---

## Behaviour details

- The shortcode always renders a dedicated `bulkoperations_table`, not the regular booking options table.
- Checkboxes are always enabled so actions can be applied to a selection.
- The table always adds action buttons for:
  - **Edit booking options**
  - **Send mail to teachers**
- Filters are always shown on top and the table is pageable, sticky, and row-count aware.
- The implementation adds a booking-instance filter automatically.
- Booking templates are excluded because the SQL filter is built with `bookingid > 0` in normal option context.

---

## Example

```text
[bulkoperations customfields="spt1" filter="institution,coursestarttime,spt1" intrangefilter="titleprefix" download="1"]
```

---

## Related shortcodes

- [allbookingoptions](allbookingoptions.md) — cross-instance option list for end users or simple overviews
- [listtoapprove](listtoapprove.md) — approval workflow instead of bulk option editing

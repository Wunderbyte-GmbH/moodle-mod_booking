[Back to shortcode index](README.md)

# `[fieldofstudycohortoptions]`

Shows booking options for the user's **field of study**, derived from **cohort enrolment**.

This is the cohort-based variant of `[fieldofstudyoptions]`.

---

## Syntax

```text
[fieldofstudycohortoptions]
[fieldofstudycohortoptions cohort="Cohort 2026"]
```

---

## Required parameters

None.

If `cohort` is omitted, the shortcode uses the current user's cohort memberships.

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `cohort="Cohort name"` | Use the named cohort instead of the current user's cohort memberships. |
| `perpage="20"` | Rows per page. |
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `sortby="coursestarttime"` | Default sort column. |
| `sortorder="asc"` / `sortorder="desc"` | Default sort direction. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `requirelogin="false"` | Disable table-level login requirement. |
| `includecustomfields="shortname1,shortname2"` | Add custom fields to the rendered output. Full syntax: see the [shortcodes README](README.md#custom-field-columns-includecustomfields). |
| `filterbookablenextdays="28"` | Add a toggle filter switch that shows only bookable options starting within the next N days. |

---

## Behaviour details

- The shortcode only supports database drivers `pgsql_native_moodle_database` and `mariadb_native_moodle_database`.
- On unsupported databases it returns the translated "shortcode not supported on your DB" message.
- If no cohort can be resolved, it returns the translated "no field of study found" message.
- The shortcode removes the right-side booking-action area unconditionally.
- Search, filter, sort, reload, and filter activation are hard-enabled in the callback.

---

## Example

```text
[fieldofstudycohortoptions cohort="Cohort 2026" perpage="15"]
```

---

## Related shortcodes

- [fieldofstudyoptions](fieldofstudyoptions.md) — group-based variant
- [recommendedin](recommendedin.md) — course-shortname matching without cohort discovery

[Back to shortcode index](README.md)

# `[fieldofstudyoptions]`

Shows booking options for the user's **field of study**, derived from Moodle **group names**.

The implementation looks at the current user's groups in the current course, then finds other courses with groups of the same names and finally shows booking options whose `recommendedin` field references those course shortnames.

---

## Syntax

```text
[fieldofstudyoptions]
[fieldofstudyoptions group="Business Administration"]
```

---

## Required parameters

None.

If `group` is omitted, the user must belong to at least one group in the current course.

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `group="Group name"` | Override automatic group detection and use this group name directly. |
| `perpage="20"` | Rows per page. |
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `sortby="coursestarttime"` | Default sort column. |
| `sortorder="asc"` / `sortorder="desc"` | Default sort direction. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `requirelogin="false"` | Disable table-level login requirement. |
| `includecustomfields="shortname1,shortname2"` | Add custom fields to the rendered output. |
| `filterbookablenextdays="28"` | Add a toggle filter switch that shows only bookable options starting within the next N days. |

---

## Behaviour details

- Search, filter, sort, reload, and filter activation are enabled directly by the implementation.
- The shortcode always uses the standard list view.
- If the user is not in any matching group and no `group` override is supplied, the shortcode returns the translated "define field of study" message.
- Matching is based on **group name equality**, not on a separate dedicated study-program table.

---

## Example

```text
[fieldofstudyoptions sortby="coursestarttime" sortorder="asc"]
```

---

## Related shortcodes

- [fieldofstudycohortoptions](fieldofstudycohortoptions.md) — similar concept via cohort enrolment
- [recommendedin](recommendedin.md) — direct course-shortname based matching

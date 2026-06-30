[Back to shortcode index](README.md)

# `[recommendedin]`

Shows booking options whose booking custom field **`recommendedin`** matches the current course shortname.

This shortcode is intended for course pages where you want to surface booking options that are recommended for that course.

---

## Syntax

```text
[recommendedin]
[recommendedin perpage="5" search="1"]
```

---

## Required parameters

None.

The current course context comes from `$PAGE->course`.

---

## Optional parameters

| Parameter | Meaning |
|-----------|---------|
| `perpage="5"` | Rows per page. |
| `search="1"` | Show full-text search. |
| `sort="1"` | Show sort controls. |
| `filter="1"` | Show standard filters. |
| `sortby="coursestarttime"` | Default sort column. |
| `sortorder="asc"` / `sortorder="desc"` | Default sort direction. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `requirelogin="false"` | Disable table-level login requirement. |
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `includecustomfields="shortname1,shortname2"` | Add custom fields to the rendered output. |
| `all="true"` | Include past options. |
| `all="past"` | Show only past options. |

---

## Behaviour details

- The callback builds SQL that matches the current course shortname against the `recommendedin` booking custom field.
- Matching is not limited to exact equality; it also accepts comma-separated lists where the shortname appears at the beginning, middle, or end.
- The shortcode always uses the standard list renderer, not the `type` view switch used by `[courselist]` and `[allbookingoptions]`.

---

## Example

```text
[recommendedin perpage="5" search="1"]
```

---

## Related shortcodes

- [fieldofstudyoptions](fieldofstudyoptions.md) — programme-like matching via Moodle groups
- [courselist](courselist.md) — explicit list for one booking activity

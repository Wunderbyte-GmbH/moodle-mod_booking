[Back to shortcode index](README.md)

# `[courselist]`

Shows booking options from **one specific booking activity**.

This is the main shortcode for embedding the option list of a single booking instance on another Moodle page.

---

## Syntax

```text
[courselist cmid="42"]
[courselist cmid="42" perpage="10" search="1" sort="1"]
```

---

## Required parameters

| Parameter | Meaning |
|-----------|---------|
| `cmid="42"` | The course module id of the booking activity. |

---

## Optional parameters

### Table display

| Parameter | Meaning |
|-----------|---------|
| `perpage="10"` | Rows per page. |
| `type="cards"` | Use `list`, `cards`, `imageleft`, `imageright`, or `imagelefthalf`. |
| `search="1"` | Show full-text search. |
| `fulltextsearchcolumns="description,shortname1"` | Add booking option fields and/or booking custom field shortnames to the full-text search. Implicitly enables the search box. |
| `sort="1"` | Show sort controls. |
| `filter="1"` | Show standard filters. |
| `sortby="coursestarttime"` | Default sort column. |
| `sortorder="asc"` / `sortorder="desc"` | Default sort direction. |
| `filterontop="true"` | Move filters to the top. |
| `filteronloadactive="true"` | Start with filters active/visible. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `requirelogin="false"` | Disable table-level login requirement. |

### Display shaping

| Parameter | Meaning |
|-----------|---------|
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `includecustomfields="shortname1,shortname2"` | Add booking custom fields to the output. |
| `customfieldfilter="shortname1,shortname2"` | Add filter widgets for those custom fields. |

### Data filters

| Parameter / pattern | Meaning |
|---------------------|---------|
| `all="true"` | Include past options. |
| `all="past"` | Show only past options. |
| `<customfieldshortname>="value"` | Filter by booking option custom field. |
| `<customfieldshortname>-not="value"` | Exclude matching custom field values. |
| `columnfilter_competencies="..."` | Use the currently implemented competencies column filter. |

---

## Behaviour details

- The shortcode validates that the `cmid` exists and belongs to a booking activity.
- Past options are hidden by default unless `all` changes that behaviour.
- The current implementation can also show option filters based on custom fields and on the competencies column.
- If `exclude` contains `rightside`, the action area with the booking button is removed.

---

## Typical examples

### Standard embedded list

```text
[courselist cmid="42" perpage="10"]
```

### Card view with search and filters

```text
[courselist cmid="42" type="cards" search="1" sort="1" filter="1"]
```

### Include custom fields in the rendered cards/list

```text
[courselist cmid="42" includecustomfields="cfspt1|leftside|fas|fa-running,cffrm1|leftside|far|fa-futbol|text-gray"]
```

### Filter by a booking custom field shortname

```text
[courselist cmid="42" sporttype="tennis"]
```

### Search over additional columns

```text
[courselist cmid="42" fulltextsearchcolumns="description,sporttype,organizer"]
```

Adds the `description` field and the values of the booking custom fields with the shortnames `sporttype` and `organizer` to the full-text search. The search box is shown even without `search="1"`.

---

## Related shortcodes

- [allbookingoptions](allbookingoptions.md) — same idea across several booking instances
- [recommendedin](recommendedin.md) — list based on the current course shortname

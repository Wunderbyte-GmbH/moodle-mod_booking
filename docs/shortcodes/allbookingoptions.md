[Back to shortcode index](README.md)

# `[allbookingoptions]`

Shows booking options across booking activities.

Use this shortcode when you want one embedded list that can aggregate options from:

- all booking activities on the site
- one or more booking activities selected by `cmid`
- all booking activities in one Moodle course selected by `courseid`

---

## Syntax

```text
[allbookingoptions]
[allbookingoptions cmid="42,56" perpage="20" search="1" sort="1"]
[allbookingoptions courseid="17" all="true" type="cards"]
```

---

## Required parameters

None.

---

## Optional parameters

### Scope and source selection

| Parameter | Meaning |
|-----------|---------|
| `cmid="42"` | Restrict the list to one booking activity. |
| `cmid="42,56"` | Restrict the list to several booking activities. |
| `id="42"` | Alias that is normalised to `cmid` in the current implementation. |
| `courseid="17"` | Include all booking activities inside the given Moodle course. |
| `all="true"` | Include past booking options as well. |
| `all="past"` | Show only past booking options. |

### Table display

| Parameter | Meaning |
|-----------|---------|
| `perpage="20"` | Rows per page. |
| `type="cards"` | Use `list`, `cards`, `imageleft`, `imageright`, or `imagelefthalf`. |
| `search="1"` | Show full-text search. |
| `fulltextsearchcolumns="description,shortname1"` | Add booking option fields and/or booking custom field shortnames to the full-text search. Implicitly enables the search box. |
| `sort="1"` | Show sort controls. |
| `filter="1"` | Show standard filters. |
| `filterbookablenextdays="28"` | Add a toggle filter switch that shows only bookable options starting within the next N days (requires `filter="1"`). |
| `sortby="coursestarttime"` | Default sort column. |
| `sortorder="desc"` | Default sort direction. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `filterontop="true"` | Show filters at the top. |
| `filteronloadactive="true"` | Keep filters active/visible on first render. |
| `inlinestartpage="cart"` | Passes an inline start-page identifier to the "Book now" rendering. |
| `requirelogin="false"` | Disable table-level login requirement. |

### Content shaping

| Parameter | Meaning |
|-----------|---------|
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `includecustomfields="shortname1,shortname2"` | Add custom fields to the rendered card/list output. |
| `customfieldfilter="shortname1,shortname2"` | Add filter widgets for these custom fields. |

### Data filters

| Parameter / pattern | Meaning |
|---------------------|---------|
| `<customfieldshortname>="value"` | Filter by booking custom field. |
| `<customfieldshortname>-not="value"` | Exclude matching custom field values. |
| `cfinclude="1"` | Switches matching custom-field restrictions into the current OR-style include mode used by the implementation. |
| `columnfilter_competencies="..."` | Apply the currently supported column filter for competencies. |

---

## Behaviour details

- If both `courseid` and `cmid` are supplied, the implementation merges them.
- Without `all="true"`, past options are filtered out by default.
- When a cashier or another allowed role renders the shortcode for a different user, table initialisation can resolve that user through helper arguments such as `urlparamforuserid`.
- If `cmid` or `courseid` points to a non-existing activity/course, the shortcode returns a translated error message.

---

## Typical examples

### Show all current options

```text
[allbookingoptions perpage="20"]
```

### Show all options from one booking instance in card view

```text
[allbookingoptions cmid="42" type="cards" search="1" sort="1"]
```

### Show options from one Moodle course, including past items

```text
[allbookingoptions courseid="17" all="true" sortby="coursestarttime"]
```

### Cashier-style embedded list for another user

```text
[allbookingoptions search="1" perpage="3" all="true" urlparamforuserid="userid"]
```

---

## Related shortcodes

- [courselist](courselist.md) — same table style, but fixed to one booking activity
- [mycourselist](mycourselist.md) — current user's own bookings only
- [bookingoptionview](bookingoptionview.md) — one direct booking button instead of a full list

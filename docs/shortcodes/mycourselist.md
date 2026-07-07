[Back to shortcode index](README.md)

# `[mycourselist]`

Shows booking options that are already related to one user.

By default, that user is the **current logged-in user**. In manager-style scenarios the shortcode can also render another user's bookings.

---

## Syntax

```text
[mycourselist]
[mycourselist statuswaitinglist="1" futureonly="1"]
[mycourselist userid="123" cmid="42"]
```

---

## Required parameters

None.

---

## Optional parameters

### Target user and scope

| Parameter | Meaning |
|-----------|---------|
| `userid="123"` | Render bookings for another user. |
| `cmid="42"` | Restrict the result to one booking activity. |
| `completed="1"` | Only include completed booking answers. |
| `statuswaitinglist="1"` | Include waiting-list bookings in addition to booked places. |
| `futureonly="1"` | Keep only items whose `courseendtime` is still in the future. |

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
| `filterontop="true"` | Move filters to the top. |
| `countlabel="false"` | Hide the result counter. |
| `progress="1"` | Add the progress subcolumn. |
| `requirelogin="false"` | Disable table-level login requirement. |

### Display shaping and custom fields

| Parameter | Meaning |
|-----------|---------|
| `exclude="description,teacher,rightside"` | Hide selected display fragments. |
| `includecustomfields="shortname1,shortname2"` | Add custom fields to the rendered output. Full syntax: see the [shortcodes README](README.md#custom-field-columns-includecustomfields). |
| `customfieldfilter="shortname1,shortname2"` | Add filter widgets for selected booking custom fields. |
| `<customfieldshortname>="value"` | Filter by booking custom field value. |
| `<customfieldshortname>-not="value"` | Exclude a booking custom field value. |

---

## Behaviour details

- If `userid` is omitted, the shortcode uses the current logged-in user.
- `statuswaitinglist="1"` extends the internal status array with waiting-list entries.
- `futureonly="1"` adds an additional time-based SQL restriction after the table query is built.
- The shortcode defines its own cache bucket (`mybookingoptionstable`).

---

## Typical examples

### Current user's upcoming bookings

```text
[mycourselist futureonly="1" perpage="10"]
```

### Include waiting-list entries too

```text
[mycourselist statuswaitinglist="1" search="1" sort="1"]
```

### Show one user's bookings for one booking activity

```text
[mycourselist userid="123" cmid="42"]
```

---

## Related shortcodes

- [courselist](courselist.md) — available options in a booking activity
- [supervisorteam](supervisorteam.md) — bookings of supervised users

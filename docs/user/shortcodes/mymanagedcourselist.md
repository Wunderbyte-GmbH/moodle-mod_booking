[Back to shortcode index](README.md)

# `[mymanagedcourselist]`

Shows the booking options in which one user is assigned as **teacher**. If the site setting "Allow responsible contacts to edit" (`responsiblecontactcanedit`) is active, it also shows the options in which that user is **responsible contact**.

Formerly `[mytaughtcourselist]`. The old name is no longer registered: replace it on existing pages.

By default, that user is the **current logged-in user**. In manager-style scenarios the shortcode can also render another teacher's options.

---

## Syntax

```text
[mymanagedcourselist]
[mymanagedcourselist futureonly="1" perpage="10"]
[mymanagedcourselist userid="123" cmid="42"]
```

---

## Required parameters

None.

---

## Optional parameters

### Target user and scope

| Parameter | Meaning |
|-----------|---------|
| `userid="123"` | Render the options taught (or, with the setting above, managed as responsible contact) by another user. |
| `cmid="42"` | Restrict the result to one booking activity. Invisible options are included for users holding `mod/booking:canseeinvisibleoptions` in that activity. |
| `futureonly="1"` | Keep only items whose `courseendtime` is still in the future. |

### Table display

| Parameter | Meaning |
|-----------|---------|
| `perpage="20"` | Rows per page. |
| `showpagination="false"` | Hide the pagination controls. |
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
- The list is built from the teacher assignments of the booking options (`booking_teachers`), not from bookings. A teacher who has also booked an option sees it here as well as in `[mycourselist]`.
- Without `cmid`, only visible options are shown. With `cmid`, the capability `mod/booking:canseeinvisibleoptions` of that booking activity decides whether invisible options are included.
- `futureonly="1"` adds an additional time-based SQL restriction after the table query is built.

---

## Typical examples

### Current teacher's upcoming options

```text
[mymanagedcourselist futureonly="1" perpage="10"]
```

### Cards with search and sort

```text
[mymanagedcourselist type="cards" search="1" sort="1"]
```

### Show one teacher's options for one booking activity

```text
[mymanagedcourselist userid="123" cmid="42"]
```

---

## Related shortcodes

- [mycourselist](mycourselist.md) — the current user's own bookings
- [courselist](courselist.md) — available options in a booking activity

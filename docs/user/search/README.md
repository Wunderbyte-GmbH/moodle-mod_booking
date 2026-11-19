[Back to user documentation](../README.md)

# Search in the booking options — columns and order of the results

> **Primary page** for: the search box of the booking options table, the instance settings
> **"Columns to add to the full text search"** and **"Priority of the search results"**, and the
> shortcode arguments `search`, `fulltextsearchcolumns` and `priority`.

The table of booking options has a search box. This page explains **where** the search looks
(the search columns) and **in which order** the results are shown (the priority).

These are two separate things:

| | Decides | Configured with |
|---|---|---|
| **Search columns** | whether a booking option is found at all | instance setting "Columns to add to the full text search", shortcode argument `fulltextsearchcolumns` |
| **Priority** | in which order the found options are listed | instance setting "Priority of the search results", shortcode argument `priority` |

The priority never finds anything by itself. A column that should have priority must also be searched.

---

## Which columns are searched

The view of a booking instance and the booking list shortcodes search these columns:

| Column | Searched |
|--------|----------|
| Prefix (`titleprefix`) | always |
| Booking option name (`text`) | always |
| Description (`description`) | when the description is shown in the table |
| Location (`location`) | when the location is shown in the table |
| Institution (`institution`) | when the institution is shown in the table |
| Teachers (`teachernames`) | when the teachers are shown in the table |

Which fields are shown is set in the booking instance (section **Configure fields and columns**) or,
in a shortcode, with the argument `exclude`.

Teachers are found by their **names** only: first name and last name, in any order
("Pia Berger", "Berger Pia" and "Berger, Pia" all work).

A search text with several words finds the options that contain **every** word somewhere in the
searched columns. Upper and lower case do not matter.

### Adding columns to the search

**In the booking instance:** *Settings → Configure fields and columns → Columns to add to the full text search*.
Choose fields of the booking options (description, location, institution, unique identifier) and
booking custom fields. The setting applies to the view of the booking instance.

**In a shortcode:** `fulltextsearchcolumns`, a comma separated list of booking option fields and
shortnames of booking custom fields. The argument shows the search box even without `search="1"`.

```text
[courselist cmid="42" fulltextsearchcolumns="description,keywords"]
```

A typical use is a custom field like `keywords` that is not displayed but holds search terms.
For select custom fields the search finds the label users see, not the stored number.

---

## Priority of the search results

Without a priority, the results of a search keep the sorting of the table (for example by name).
With a priority, they are shown in groups, one after the other:

1. options where the **first** priority column **equals** the search text,
2. options where the first priority column **contains every word** of the search text,
3. the same two groups for the second priority column, then the third, and so on,
4. all other results.

Inside a group the sorting of the table applies. Upper and lower case do not matter. Select custom
fields compare the label users see.

### Example

Priority `titleprefix,text`, a user searches for **424**:

| Group | Why | Example (prefix – name) |
|-------|-----|-------------------------|
| 1 | The prefix is "424" | 424 – Pilates |
| 2 | The prefix contains "424" | 4240 – Dance |
| 3 | The name is "424" | A3 – 424 |
| 4 | The name contains "424" | A4 – Course 424 Yoga |
| 5 | All other results, e.g. "424" only in the description | A5 – Yoga |

### Setting the priority in the booking instance

*Settings → Configure fields and columns → Priority of the search results*, directly below
"Columns to add to the full text search".

The setting is an ordered list. The first entry has the highest priority.

- **Add a column:** choose it in the select box and press **Add column**.
- **Change the order:** use the arrow buttons of an entry (move up / move down), or drag the entry
  by its handle on the left. A click on the handle opens a dialogue to choose the new position.
- **Remove a column:** use the bin button of the entry.

The select box offers the prefix, the booking option name, the teachers, description, location,
institution and all booking custom fields. Only these columns can be chosen.

| Booking instance | Priority |
|------------------|----------|
| New instance (created in the form) | Preset with `titleprefix,text`. Change or empty it before saving if you want something else. |
| Existing instance | Unchanged: no priority until you set one. |
| Instance created by an import, a web service or other code | No priority until you set one. |

### Setting the priority in a shortcode

```text
[courselist cmid="42" search="1" priority="titleprefix,text"]
[allbookingoptions search="1" fulltextsearchcolumns="keywords" priority="titleprefix,text,keywords"]
```

`priority` is a comma separated list, the most important column first. Invalid column names are ignored.

### Which priority applies

| Where | Priority used |
|-------|---------------|
| View of the booking instance | The instance setting. |
| Shortcode with the argument `priority` | The argument. It **replaces** the instance setting, its columns are not added to it. |
| Shortcode of exactly one booking instance (`cmid="42"`) without `priority` | The instance setting of that booking instance. |
| Shortcode of several booking instances (`cmid="42,43"`) or without `cmid`, without `priority` | No priority. |

### Column names

| Name | Meaning |
|------|---------|
| `titleprefix` | Prefix (course number) |
| `text` | Booking option name |
| `teachernames` | Names of the teachers. `teacher` and `teachers` are accepted as other spellings. |
| `description`, `location`, `institution`, `identifier`, … | Fields of the booking option |
| a shortname, e.g. `keywords` | Booking custom field with this shortname |

If a booking custom field has the shortname `teacher` or `teachers`, that name means the custom
field, not the names of the teachers. Use `teachernames` in this case.

---

## Troubleshooting

| What you see | Reason |
|--------------|--------|
| A column has priority, but its hits are not on top | The column is not searched. Add it to "Columns to add to the full text search" or to `fulltextsearchcolumns`. The priority only orders what the search finds. |
| The instance setting has no effect in a shortcode | The shortcode has its own `priority` argument, addresses several booking instances, or uses no `cmid`. |
| A changed shortcode argument has no effect | The table of a shortcode is cached. Purge the caches or wait until the cache is rebuilt. |
| An existing booking instance does not sort the results | Existing instances have no priority until it is set in their settings. |
| Searching for a number lists unexpected options | The number is part of another searched column, e.g. the description. The priority puts such results at the end. |

---

## Related pages

- [Shortcodes — overview](../shortcodes/README.md)
- [`[courselist]`](../shortcodes/courselist.md)
- [Global search](../globalsearch/README.md) — the search box of the Moodle site, not of the booking options table

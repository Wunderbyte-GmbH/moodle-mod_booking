[Back to parent section](../README.md)

# Shortcodes — Overview and Index

> **Primary page** for: embedded booking lists, shortcode syntax, `[courselist]`, `[allbookingoptions]`, `[mycourselist]`, and `[bookingoptionview]`.

mod_booking currently registers **14 booking-specific shortcodes** in `/db/shortcodes.php`. This folder documents the current implementation as it exists in `classes/shortcodes.php`.

Use this section when you want to:

- embed booking options on a Moodle page, label, block, or plugin setting that supports shortcodes
- show a participant's own bookings
- show approval or supervisor views outside the booking activity itself
- render one direct booking button or the inline AI assistant
- understand which parameters the current shortcode implementation really supports

---

## Quick setup path

1. Enable the **Shortcodes** text filter in Moodle.
2. Confirm that a **Booking PRO licence** is active.
3. Open a Moodle text field that is filtered for shortcodes (for example a label, page resource, section summary, or compatible plugin setting).
4. Insert one shortcode from this reference.
5. Save and open the page with a user who has the required permissions.

---

## Prerequisites and global rules

### 1. Moodle shortcode filter must be enabled

Booking shortcodes are rendered through the Moodle shortcode filter setup used in this installation.

- Moodle administration path: **Site administration → Plugins → Filters → Manage filters**
- The booking CI setup installs `branchup/moodle-filter_shortcodes`.

### 2. Booking shortcodes can be disabled globally

If the booking setting `shortcodesoff` is enabled, all booking shortcodes return a warning instead of content.

### 3. Booking shortcodes can be password-protected globally

If the booking setting `shortcodespassword` is filled, every booking shortcode must include a matching `password="..."` argument.

Example:

```text
[courselist cmid="42" password="top_secret123"]
```

### 4. Current implementation requires an active PRO licence

All registered booking shortcodes pass through `shortcodes_handler::validatecondition()`, which currently checks the PRO licence before rendering.

### 5. Unknown arguments are usually ignored

The shortcode parser can pass many attributes, but each callback only uses the arguments implemented in `classes/shortcodes.php`. This documentation lists the parameters that are currently handled by the code.

---

## Supported shortcodes

| Shortcode | Detailed page | Main use case | Implementation callback |
|-----------|---------------|---------------|-------------------------|
| `[allbookingoptions]` | [allbookingoptions.md](allbookingoptions.md) | Show booking options across booking activities | `mod_booking\shortcodes::allbookingoptions()` |
| `[courselist]` | [courselist.md](courselist.md) | Show options from one specific booking activity | `mod_booking\shortcodes::courselist()` |
| `[mycourselist]` | [mycourselist.md](mycourselist.md) | Show the current user's own bookings | `mod_booking\shortcodes::mycourselist()` |
| `[recommendedin]` | [recommendedin.md](recommendedin.md) | Show options recommended for the current course | `mod_booking\shortcodes::recommendedin()` |
| `[fieldofstudyoptions]` | [fieldofstudyoptions.md](fieldofstudyoptions.md) | Show options matched through Moodle groups | `mod_booking\shortcodes::fieldofstudyoptions()` |
| `[fieldofstudycohortoptions]` | [fieldofstudycohortoptions.md](fieldofstudycohortoptions.md) | Show options matched through cohort enrolment | `mod_booking\shortcodes::fieldofstudycohortoptions()` |
| `[bulkoperations]` | [bulkoperations.md](bulkoperations.md) | Show the bulk operations table for managers/admins | `mod_booking\shortcodes::bulkoperations()` |
| `[linkbacktocourse]` | [linkbacktocourse.md](linkbacktocourse.md) | Show links back to booking options from a linked Moodle course | `mod_booking\shortcodes::linkbacktocourse()` |
| `[listtoapprove]` | [listtoapprove.md](listtoapprove.md) | Show bookings waiting for approval | `mod_booking\shortcodes::listtoapprove()` |
| `[supervisorteam]` | [supervisorteam.md](supervisorteam.md) | Show bookings of users in a supervisor's team | `mod_booking\shortcodes::supervisorteam()` |
| `[executeservice]` | [executeservice.md](executeservice.md) | Run an internal service class | `mod_booking\shortcodes::executeservice()` |
| `[bookingoptionsfromcondition]` | [bookingoptionsfromcondition.md](bookingoptionsfromcondition.md) | Output completed options inside certificate-condition context | `mod_booking\shortcodes::bookingoptionsfromcondition()` |
| `[bookingoptionview]` | [bookingoptionview.md](bookingoptionview.md) | Render one direct booking button / booking option CTA | `mod_booking\shortcodes::bookingoptionview()` |

---

## Shared parameter patterns

Not every shortcode supports every parameter below, but these patterns appear repeatedly in the current implementation.

### Table rendering parameters

These parameters are used by the table-based shortcodes that render booking options through `bookingoptions_wbtable`.

| Parameter | Meaning | Notes |
|-----------|---------|-------|
| `perpage="25"` | Number of rows per page | If omitted or `0`/`false`, most table shortcodes switch to infinite scrolling. |
| `infinitescrollpage="30"` | Number of rows loaded per infinite-scroll step | Only relevant when `perpage` is not set or is `0`/`false`. |
| `sort="1"` | Show sort controls | Only in shortcodes that expose optional sort UI. |
| `search="1"` | Show full-text search | Only in shortcodes that expose optional search UI. |
| `fulltextsearchcolumns="description,shortname1"` | Add columns to the full-text search | Comma-separated list of booking option fields and/or booking custom field shortnames. Setting this implicitly enables the search box. Invalid column names are ignored. |
| `filter="1"` | Show standard filters | In some shortcodes this is a boolean toggle; in `[bulkoperations]` it is a comma-separated filter definition. |
| `sortby="coursestarttime"` | Default sort column | Sanitised to alphanumeric / underscore / dash characters. |
| `sortorder="asc"` / `sortorder="desc"` | Default sort direction | Default is ascending. |
| `countlabel="false"` | Hide the result counter | Default is visible. |
| `progress="1"` | Add the progress subcolumn | Only where the table renderer supports it. |
| `requirelogin="false"` | Disable table-level login enforcement | Use carefully; this does not bypass Moodle capability checks elsewhere. |

### View style parameters

Some booking option list shortcodes support a `type` argument:

| Value | Result |
|-------|--------|
| `list` | Standard list view |
| `cards` | Card view |
| `imageleft` | List with header image on the left |
| `imageright` | List with header image on the right |
| `imagelefthalf` | List with half-width header image on the left |

### Display shaping parameters

| Parameter | Meaning |
|-----------|---------|
| `exclude="description,teacher,rightside"` | Removes selected standard sub-sections from the rendered booking option list. `rightside` hides the right-side action area. |
| `includecustomfields="shortname1,shortname2"` | Adds booking option custom fields into the rendered output when the table renderer is used. |
| `includecustomfields="shortname\|region\|iconprefix\|iconname\|classes"` | Extended custom-field syntax, for example `cfspt1\|leftside\|fas\|fa-running\|text-primary`. |
| `customfieldfilter="shortname1,shortname2"` | Adds filter widgets for selected booking custom fields where the shortcode supports it. |
| `filterbookablenextdays="28"` | Adds a toggle filter switch that shows only booking options which are currently bookable and start within the next N days. Requires the filter UI (`filter="1"`). |
| `filterontop="true"` | Moves filters to the top in the shortcodes that implement this flag. |
| `filteronloadactive="true"` | Shows filters immediately instead of starting collapsed/inactive. |

### Data restriction parameters

| Pattern | Meaning |
|---------|---------|
| `<customfieldshortname>="value"` | Filter by a booking option custom field shortname in the shortcodes that call `set_customfield_wherearray()`. |
| `<customfieldshortname>-not="value"` | Exclude booking options with a matching custom field value. |
| `columnfilter_competencies="..."` | Special column filter currently implemented for the `competencies` field. |
| `all="true"` | Include past options in the shortcodes that call `applyallarg()`. |
| `all="past"` | Show only past options in the shortcodes that call `applyallarg()`. |

### Acting for another user

When the surrounding integration and permissions allow it (for example cashier scenarios), the table initialisation can resolve another target user via `actforuser::get_foruserid()`. A tested example is `urlparamforuserid=userid` in shortcode-enabled cashier content.

---

## Which shortcodes are table-based?

### Booking option table shortcodes

These shortcodes render booking options through the booking options table renderer:

- [allbookingoptions](allbookingoptions.md)
- [courselist](courselist.md)
- [mycourselist](mycourselist.md)
- [recommendedin](recommendedin.md)
- [fieldofstudyoptions](fieldofstudyoptions.md)
- [fieldofstudycohortoptions](fieldofstudycohortoptions.md)

### Administrative / user-list shortcodes

These shortcodes render other booking UI blocks instead of the booking options table:

- [bulkoperations](bulkoperations.md)
- [listtoapprove](listtoapprove.md)
- [supervisorteam](supervisorteam.md)
- [linkbacktocourse](linkbacktocourse.md)
- [bookingoptionview](bookingoptionview.md)
- [executeservice](executeservice.md)
- [bookingoptionsfromcondition](bookingoptionsfromcondition.md)

---

## Troubleshooting checklist

### Nothing is rendered

Check these first:

1. The Moodle shortcode filter is enabled.
2. Booking shortcodes are not disabled globally.
3. The PRO licence is active.
4. The shortcode password is present when password protection is enabled.
5. The current user has the required capabilities.
6. Required parameters such as `cmid`, `optionid`, or `service` are present.

### The shortcode renders an error about `cmid`

`[courselist]` requires a valid **course module id** of a booking activity.

### The shortcode shows no rows

Typical reasons:

- all matching booking options are in the past and the shortcode defaults to future/current items only
- a custom field filter excludes all options
- the current user is not in the required group/cohort/supervisor context
- the current course is not linked to any matching booking option

### `fieldofstudycohortoptions` does not work on MySQL

That is expected. The implementation currently only supports **PostgreSQL** and **MariaDB**.

---

## See also

- [Placeholders](../placeholders/README.md) — token replacement in texts and emails
- [Booking conditions](../booking_conditions/README.md) — who can book and when
- [Booking option guide](../booking-option/README.md) — where booking options are configured
- [Capabilities](../capabilities/README.md) — permission model for booking features

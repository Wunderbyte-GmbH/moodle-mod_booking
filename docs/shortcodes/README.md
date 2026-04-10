# Shortcodes — Reference

mod_booking provides a set of Moodle shortcodes that you can embed in any page, course section, or label to display booking-related tables and components. Shortcodes are processed by the **local_shortcodes** filter (or Moodle's built-in shortcode filter where available).

---

## Prerequisites

- The **Shortcodes** text filter must be enabled in Moodle:
  *Site administration → Plugins → Filters → Manage filters → Shortcodes: Enabled*
- Shortcodes are placed in a Moodle text area (label, page resource, course summary, etc.) in the format:
  ```
  [shortcodename param1="value1" param2="value2"]
  ```
- The user viewing the page must have the required capability for the shortcode to render (otherwise a "no permission" message is shown).

---

## Overview

| Shortcode | Displays | Required capability |
|-----------|---------|---------------------|
| [`allbookingoptions`](#allbookingoptions) | All booking options from all booking activities site-wide | `mod/booking:view` |
| [`courselist`](#courselist) | Options from a specific booking activity (by `cmid`) | `mod/booking:view` |
| [`mycourselist`](#mycourselist) | The current user's own bookings | `mod/booking:view` |
| [`recommendedin`](#recommendedin) | Options recommended via a booking custom field | `mod/booking:view` |
| [`fieldofstudyoptions`](#fieldofstudyoptions) | Options filtered by the user's Moodle group (field-of-study) | `mod/booking:view` |
| [`fieldofstudycohortoptions`](#fieldofstudycohortoptions) | Options filtered by the user's cohort (field-of-study variant) | `mod/booking:view` |
| [`bulkoperations`](#bulkoperations) | Admin/manager bulk-operations panel across all options | `mod/booking:executebulkoperations` |
| [`linkbacktocourse`](#linkbacktocourse) | Back-navigation link from a Moodle course to its booking option | `mod/booking:view` |
| [`listtoapprove`](#listtoapprove) | Pending bookings waiting for manager approval | `mod/booking:view` |
| [`supervisorteam`](#supervisorteam) | Bookings of all users a supervisor is responsible for | `mod/booking:view` |
| [`executeservice`](#executeservice) | Trigger an internal service class (admin only) | Site admin only |
| [`bookingoptionsfromcondition`](#bookingoptionsfromcondition) | Options tied to a certificate condition | Certificate context |

---

## Common optional parameters

Many shortcodes share these optional parameters:

| Parameter | Description |
|-----------|-------------|
| `perpage="N"` | Number of results per page (default: site-wide setting). Example: `perpage="25"` |
| `cfinclude="field1,field2"` | Include only options where custom field values match (comma-separated shortnames) |
| `cfexclude="field1,field2"` | Exclude options where custom field values match |
| `view="card"` | Switch table to card view. Default is list view. |

---

## `allbookingoptions`

Displays a **filterable, sortable table** of all booking options from all booking activities on the site.

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters) plus:

| Parameter | Description |
|-----------|-------------|
| `bofilter_<column>="value"` | Pre-filter the table on a column. Example: `bofilter_location="Room A"` |

**Example:**
```
[allbookingoptions perpage="20"]
```

---

## `courselist`

Displays booking options from **one specific booking activity**, identified by its course module ID.

**Required parameters:**

| Parameter | Description |
|-----------|-------------|
| `cmid="<id>"` | Course module ID of the booking activity. Find it in the URL of the booking activity: `?id=<cmid>` |

**Optional parameters:** all [common parameters](#common-optional-parameters)

**Example:**
```
[courselist cmid="42" perpage="10"]
```

---

## `mycourselist`

Displays a table of the **current user's own bookings** (all statuses: booked, waiting list, etc.).

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters) plus:

| Parameter | Description |
|-----------|-------------|
| `userid="<id>"` | Show bookings for a specific user ID instead of the current user. Requires manager permissions. |

**Example:**
```
[mycourselist]
```

---

## `recommendedin`

Displays booking options that are **recommended** for the current user, based on a booking custom field. This shortcode is typically used on a course page to show options that the course has been tagged for.

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters)

**Example:**
```
[recommendedin perpage="5"]
```

---

## `fieldofstudyoptions`

Displays booking options filtered by the **Moodle group** the current user belongs to in the current course. Designed for study-program or field-of-study scenarios where each group represents a programme of study.

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters) plus:

| Parameter | Description |
|-----------|-------------|
| `group="<groupname>"` | Override the auto-detected group. If not provided, the user's current course group is used. |

**Example:**
```
[fieldofstudyoptions]
```

> **Note:** This shortcode requires the user to be a member of at least one group in the current course.

---

## `fieldofstudycohortoptions`

Similar to `fieldofstudyoptions` but filters by **cohort membership** instead of Moodle groups. Also uses a field-of-study pattern.

> **Database support:** This shortcode only works on **PostgreSQL** and **MariaDB**. It is not available on MySQL.

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters)

**Example:**
```
[fieldofstudycohortoptions]
```

---

## `bulkoperations`

Renders the **admin bulk-operations panel** — a management interface that lets site admins and managers perform batch actions on multiple booking options at once (e.g., delete, export, send bulk messages).

**Required capability:** `mod/booking:executebulkoperations` at the system context, or site admin.

**Required parameters:** none

**Optional parameters:** all [common parameters](#common-optional-parameters)

**Example:**
```
[bulkoperations]
```

---

## `linkbacktocourse`

Renders a **back-navigation link** or button on a Moodle course page that links back to the booking option(s) associated with that course. Useful when participants are enrolled into a Moodle course via a booking option and need a way to navigate back.

The link is only displayed if:
- The current Moodle course has at least one booking option linked to it.
- The current user has `mod/booking:view` on that booking activity.

**Required parameters:** none

**Example:**
```
[linkbacktocourse]
```

---

## `listtoapprove`

Renders a **list of bookings awaiting manager approval**. This is the equivalent of the confirmation workflow management interface. Managers see all pending bookings they are responsible for approving.

Users with `mod/booking:seealllisttoapprove` see all pending bookings across the whole site; others see only bookings in instances where they have the relevant role.

**Required parameters:** none

**Optional parameters:**

| Parameter | Description |
|-----------|-------------|
| `reduced="1"` | Show a reduced/simplified version of the list |
| `cfinclude="field1,field2"` | Filter by custom field values (comma-separated) |

**Example:**
```
[listtoapprove]
```

---

## `supervisorteam`

Renders a table of **all bookings belonging to the users that the current supervisor is responsible for**. Designed for management hierarchies where a supervisor needs oversight of their team's training registrations.

**Required parameters:** none

**Optional parameters:**

| Parameter | Description |
|-----------|-------------|
| `reduced="1"` | Show a simplified view of the team's bookings |
| `cfinclude="field1,field2"` | Include custom fields in the display |

**Example:**
```
[supervisorteam]
```

---

## `executeservice`

Triggers an **internal service class** directly from a page. This is a low-level administrative shortcode and requires the user to be a site administrator.

**Required parameters:**

| Parameter | Description |
|-----------|-------------|
| `service="<classname>"` | Fully qualified PHP class name of the service to execute |

> ⚠️ **Warning:** This shortcode is intended for developers and site administrators only. It can trigger any registered service class. Use with caution.

**Example:**
```
[executeservice service="mod_booking\service\myservice" param1="value1"]
```

---

## `bookingoptionsfromcondition`

Returns the **names of booking options** associated with a certificate condition. This shortcode is only meaningful inside a certificate template that is evaluated in the context of a booking option certificate condition (`mod_booking` certificate condition area).

It outputs a `<br>`-separated list of booking option names where the current user has completed the activity.

**Required parameters:** none (context is derived from the certificate evaluation environment)

**Example (inside a certificate template):**
```
Completed courses: [bookingoptionsfromcondition]
```

---

## See also

- [Booking option — Availability conditions](../booking_conditions/README.md) — Control who can book
- [Booking rules](../booking_rules/README.md) — Automate emails and actions
- [Capabilities](../capabilities/README.md) — Permission requirements for each shortcode

[Back to documentation index](../README.md)

# Bookings Tracker (report2.php)

The **Bookings Tracker** is the central place to view and manage bookings — across the whole site, per course, per booking instance, per booking option, and per single session date. It is the modern replacement for the legacy report page (`report.php`).

Since version 9.7.0 the Bookings Tracker is a **regular feature**: it no longer needs to be enabled through an experimental config setting. The legacy `report.php` still works but shows a deprecation warning that links to the corresponding Bookings Tracker page. New workflows should use the Bookings Tracker.

---

## Opening the Bookings Tracker

| Scope | URL | Required capability |
|-------|-----|---------------------|
| **System** (all bookings on the site) | `/mod/booking/report2.php` | `mod/booking:managebookedusers` (system context) |
| **Course** (all bookings in one course) | `/mod/booking/report2.php?courseid=<courseid>` | `mod/booking:managebookedusers` (course context) |
| **Instance** (one booking activity) | `/mod/booking/report2.php?cmid=<cmid>` | `mod/booking:managebookedusers` (module context) |
| **Option** (one booking option) | `/mod/booking/report2.php?optionid=<optionid>` | `mod/booking:viewreports`, `mod/booking:readresponses` or `mod/booking:updatebooking` |
| **Session date** (one option date) | `/mod/booking/report2.php?optiondateid=<optiondateid>` | as option scope |

Old links keep working: `report2.php?id=<cmid>` is accepted as an alias for `cmid`, so a legacy `report.php?id=X&optionid=Y` link can simply be rewritten to `report2.php?id=X&optionid=Y`.

A Bootstrap-style navigation header links the scopes, so you can drill down from system to course to instance to option.

## The two view types

Each scope offers two views (`viewtype` parameter):

- **Options view** (`viewtype=options`, default): one row per booking option with its occupancy figures.
- **Answers view** (`viewtype=answers`, "View all bookings separately"): one row per booking (answer). In the **system and course scopes**, this view additionally shows the **booking instance** of each booking as a sortable column, with a filter, and the instance name is included in the fulltext search.

## Start and end of the booking option

The scopes that list several booking options (system, course and booking instance) show two columns for every row, in both view types:

| Column | Meaning |
|---|---|
| **Start time of the course** | Start of the first date of the booking option. |
| **End time of the course** | End of the last date of the booking option. |

Both are shown with date and time in the timezone of the user, can be sorted and are empty for booking options without dates (e.g. self-paced courses). They are part of the download of these tables; the download of "View all bookings separately" of a booking instance keeps the columns chosen in the instance settings ("Customize columns and fields"), where start and end can be selected as well. The scope of a single booking option does not show them: its dates are listed in the info box above the table.

## Link to the user profile

Wherever a table of the Bookings Tracker shows the **first name** and the **last name** of a user, both are links to the Moodle profile of this user, like the name on the legacy `report.php`. The profile opens in a new tab, so the table keeps its page, its filters and the checked rows. Downloads contain the plain names.

## Times of a booking and date filters

The answers view ("View all bookings separately") of the system, course and instance scope shows three times for every booking. All three can be sorted.

| Column | Meaning |
|---|---|
| **Time created** | When the booking answer was created, i.e. the first contact of the user with the booking option. This can be before the booking: when the user was put on the waiting list, reserved a place in the shopping cart or asked to be notified. |
| **Booking date** | When the user got the status "booked". For a direct booking it equals the time created. For a user who moved up from the waiting list or was confirmed later, it is the moment of moving up or of the confirmation. Empty as long as the user has never been booked (e.g. in the waiting list table). A cancelled booking keeps its booking date. |
| **Time modified** | The last change of the booking answer: booking, moving up from the waiting list, cancellation, change of the completion status. |

All times are shown in the timezone of the user.

Each of the three columns has a **date filter** in the filter area above the table. Tick "Apply filter", choose start and end (date and time): the table then only shows the bookings whose time lies within this period, both ends included. The filters can be combined, e.g. bookings created in October that were booked in November. A booking without a value in the filtered column (no booking date on the waiting list) is not part of any period. The filters also apply to the download, so they are the way to cut a very big table down to a size that can be downloaded.

## Downloading a table

The table of booked users can be downloaded (CSV, Excel and other formats) with the download button of the table, by users with the capability `mod/booking:downloadresponses`. The search and the filters of the table also apply to the download.

A download reads all rows of the table at once, while the page only reads the rows it shows. On very big tables (for example the answers view of a booking instance with tens of thousands of bookings) the download can therefore fail although the page works. In that case no file is delivered. You are taken back to the table, and a notice asks you to **narrow the table down with the search or the filters** (see the date filters above) and to start the download again.

The notice is shown in two cases:

- PHP ran out of memory or time while the download was prepared.
- The database could not read the rows of the table, and the table (with the current search and filters) has **at least 5000 rows**. The notice then names the number of rows.

All other errors show the regular error page, because the amount of data is not a plausible cause: a failed query of a table with fewer than 5000 rows or of a table whose rows cannot even be counted, or an error in one of the columns. The technical cause is always written to the PHP error log of the server.

This requires a version of local_wunderbyte_table that delivers the file only once all rows are ready and reports a failed query of the table as such. With older versions a failed download delivers an empty or invalid file instead.

## What you can do in the option scope

The option scope of the Bookings Tracker has reached feature parity with the legacy `report.php` (same columns) and adds modern replacements for its actions:

- **Manage booked users**: see booked users, waiting list, reservations; confirm users on a waiting list that requires confirmation; presence status and completion handling (including a completion button).
- **Sign-in sheet**: configure and download the sign-in sheet directly from the tracker via a dynamic form modal. The download uses the identical endpoint as the legacy inline form on `report.php`, so the generated sheets are the same. Sign-in sheets got additional info texts, and the dates placeholder works in Word downloads.
- **Delete the booking option**: available as a modern confirmation modal (webservice-based) instead of the legacy inline action.
- **E-mail buttons**: contact booked users directly from the tracker.
- **CSV export**: the export of booked users now includes the `timebooked` column (date/time the booking was made).
- **Enrol link tracking**: for options that distribute enrol links (`{enrollink}` in rule mails), the tracker and `report.php` show all users who consumed an enrol link, including a column with the **booker who sent the link** to them — so you can tell which enrolled user belongs to which booker.
- **Edit form values (custom form)**: correct the values a participant entered in the custom booking form, one answer at a time (booked users and waiting list tables). PRO feature, gated by `mod/booking:changecustomformofotherusers` (default: manager only); every change is logged to the booking history. See [Custom Form](../booking_conditions/custom_form.md#editing-submitted-values-bookings-tracker) for details and the list of element types that stay read-only.

Users with `mod/booking:updatebooking` see an info hint in the option scope that the **visible columns are configured in the booking instance settings** (Bookings Tracker section of the instance form).

## Booking other users (subscribeusers.php)

When booking other users into an option, the user selector now labels users who already hold a booking with "(is already booked)" or "(booked N times)". If the option allows booking the same user multiple times, already-booked users can be selected and booked again; otherwise they are excluded as before. Teachers/trainers of the option are treated like regular users here and can be booked (again) too.

## Restricted visibility (supervisors)

Booking extensions can restrict **whose** bookings a user is allowed to see (see the [answers restriction API](../booking_extensions/developer-api.md#8-restricting-visible-booking-answers-answersrestriction)). A typical use case is a supervisor who may only see the bookings of their own team members.

When such a restriction applies to the current user, it is enforced consistently in:

- all Bookings Tracker scopes (system, course, instance, option — both view types),
- the legacy report page (`report.php`),
- the "book other users" page (`subscribeusers.php`),
- the booking history, and
- the list of sent messages.

Unrestricted users (e.g. admins, regular managers) see all bookings as usual.

---

## See also

- [Capabilities](../capabilities/README.md) — who may see and manage what
- [Booking extensions](../booking_extensions/README.md) — extension-provided restrictions and features
- [Sub-bookings](../subbookings/README.md), [Booking conditions](../booking_conditions/README.md)

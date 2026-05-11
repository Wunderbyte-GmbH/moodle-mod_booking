# Capabilities — Reference

Moodle capabilities define what each role is allowed to do inside **mod_booking**. They are defined in `db/access.php` and can be customised per-role using Moodle's standard role management (*Site administration → Users → Permissions → Define roles*).

---

## Table of Contents

1. [How capabilities work in mod_booking](#1-how-capabilities-work-in-mod_booking)
2. [Typical role setup](#2-typical-role-setup)
3. [Viewing and booking](#3-viewing-and-booking)
4. [Option management](#4-option-management)
5. [User and response management](#5-user-and-response-management)
6. [Communication](#6-communication)
7. [Rating](#7-rating)
8. [Booking rules and availability conditions](#8-booking-rules-and-availability-conditions)
9. [Pricing and custom fields](#9-pricing-and-custom-fields)
10. [Teacher management](#10-teacher-management)
11. [Approvals and confirmation workflow](#11-approvals-and-confirmation-workflow)
12. [Administration and reporting](#12-administration-and-reporting)
13. [Developer / performance capabilities](#13-developer--performance-capabilities)

---

## 1. How capabilities work in mod_booking

- **Context levels:** Most booking capabilities are checked at the **module context** (the individual booking activity). A few are checked at the **system context** (e.g., `executebulkoperations`, `editbookingrules` for site-wide rules).
- **PRO capabilities:** Some capabilities (like `overrideboconditions`) are listed in the access file but their full effect may require a PRO licence from Wunderbyte.
- **Capability overrides:** A course teacher or manager with `mod/booking:updatebooking` can override specific per-user availability conditions.

---

## 2. Typical role setup

| Role | Typical capabilities |
|------|----------------------|
| **Guest** | `view` only |
| **Student / Authenticated user** | `view`, `choose` (book), `comment`, `viewrating`, `conditionforms` |
| **Non-editing teacher** | All student caps + `readresponses`, `deleteresponses`, `downloadresponses`, `communicate`, `subscribeusers`, `readallinstitutionusers`, `canoverbook`, `manageoptiondates`, `downloadchecklist`, `updatenotes` |
| **Editing teacher** | All non-editing teacher caps + `addeditownoption`, `limitededitownoption`, `expertoptionform`, `bookforothers`, `managebookedusers`, `cansendmessages`, `canreviewsubstitutions` |
| **Manager** | Effectively all capabilities except site-admin-only ones |
| **Admin** | All capabilities |

> The exact default archetype assignments are listed per capability below. Administrators can always override these defaults.

---

## 3. Viewing and booking

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:view` | See the booking activity and its options | guest, student, teacher, editingteacher, manager |
| `mod/booking:choose` | Book (or cancel) a booking option | student, teacher, editingteacher, manager |
| `mod/booking:canseeinvisibleoptions` | See booking options that are marked as invisible | editingteacher, manager |
| `mod/booking:canoverbook` | Book into a fully-booked option (bypass capacity limit) | editingteacher, manager |
| `mod/booking:comment` | Post comments on booking options | student, teacher, editingteacher, manager |
| `mod/booking:managecomments` | Edit or delete any comment | teacher, editingteacher, manager |

---

## 4. Option management

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:addoption` | Create new booking options (via CSV import or form) | *(No default — typically granted to editingteacher + manager via `addeditownoption`)* |
| `mod/booking:addeditownoption` | Create and edit booking options the user created themselves | editingteacher, manager |
| `mod/booking:limitededitownoption` | Edit a subset of fields on options the user owns (restricted form) | editingteacher, manager |
| `mod/booking:updatebooking` | Edit all settings of any booking option (full edit) | coursecreator, manager |
| `mod/booking:manageoptiontemplates` | Create and manage booking option templates | manager |
| `mod/booking:manageoptiondates` | Add, edit, and delete session dates on any booking option | manager, editingteacher |
| `mod/booking:editoptionformconfig` | Configure which sections appear in the booking option form | manager |
| `mod/booking:expertoptionform` | See the full "expert" option form (all fields visible) | editingteacher, manager |
| `mod/booking:reducedoptionform1` | Access reduced form variant 1 (customisable field set) | *(No default)* |
| `mod/booking:reducedoptionform2` | Access reduced form variant 2 | *(No default)* |
| `mod/booking:reducedoptionform3` | Access reduced form variant 3 | *(No default)* |
| `mod/booking:reducedoptionform4` | Access reduced form variant 4 | *(No default)* |
| `mod/booking:reducedoptionform5` | Access reduced form variant 5 | *(No default)* |
| `mod/booking:importoptions` | Import booking options from CSV | coursecreator, manager |

> **Reduced forms:** `reducedoptionform1–5` are five configurable "light" versions of the option form. Each can be mapped to a specific set of fields via the *Option form configuration* admin setting. Assigning a teacher `reducedoptionform2` (for example) means they see only the fields defined for form variant 2.

---

## 5. User and response management

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:readresponses` | See the list of who has booked an option | teacher, editingteacher, manager |
| `mod/booking:deleteresponses` | Remove a user's booking answer (cancel on their behalf) | teacher, editingteacher, manager |
| `mod/booking:downloadresponses` | Export the participant list as CSV/Excel | teacher, editingteacher, manager |
| `mod/booking:subscribeusers` | Enrol other users into a booking option | teacher, editingteacher, manager |
| `mod/booking:bookforothers` | Book on behalf of another user (full booking flow) | editingteacher, manager |
| `mod/booking:bookanyone` | Book any user regardless of availability conditions | manager |
| `mod/booking:managebookedusers` | Manage the participant list (move between statuses, etc.) | manager, editingteacher |
| `mod/booking:executebulkoperations` | Perform bulk operations on booking options via the bulk panel | manager |
| `mod/booking:readallinstitutionusers` | See users from any institution, not just the current one | teacher, editingteacher, manager |
| `mod/booking:downloadchecklist` | Download attendance/checklist for an option | teacher, editingteacher, manager |
| `mod/booking:updatenotes` | Add or edit notes on booking answers | teacher, editingteacher, manager |

---

## 6. Communication

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:communicate` | Send messages to participants from the booking activity | teacher, editingteacher, manager |
| `mod/booking:cansendmessages` | Send custom messages via the booking messaging interface | editingteacher, manager |

---

## 7. Rating

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:viewrating` | See the aggregate rating of a booking option | student, teacher, editingteacher, manager |
| `mod/booking:viewanyrating` | See individual user ratings | teacher, editingteacher, manager |
| `mod/booking:viewallratings` | See all ratings including from other contexts | teacher, editingteacher, manager |
| `mod/booking:rate` | Rate a booking option | teacher, editingteacher, manager |

---

## 8. Booking rules and availability conditions

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:editbookingrules` | Create, edit, and delete booking rules | manager |
| `mod/booking:overrideboconditions` | Bypass all blocking availability conditions when booking | manager |
| `mod/booking:conditionforms` | Fill in custom booking condition forms (e.g., accept terms) | user (all authenticated users) |
| `mod/booking:editcertificateconditions` | Manage certificate-related conditions | manager |

---

## 9. Pricing and custom fields

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:calculateprices` | Recalculate prices for booking options (e.g., apply price formula) | manager |
| `mod/booking:changelockedcustomfields` | Edit custom booking fields that are locked for regular editors | manager |

---

## 10. Teacher management

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:seepersonalteacherinformation` | View personal/contact information of teachers on booking options | manager |
| `mod/booking:editteacherdescription` | Edit the teacher description text on a booking option | manager |
| `mod/booking:assigndeputies` | Assign deputies (substitute teachers) for an option | manager |
| `mod/booking:canreviewsubstitutions` | Review and approve teacher substitution requests | manager |

---

## 11. Approvals and confirmation workflow

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:seealllisttoapprove` | See the global approval list (all pending bookings site-wide) | manager |
| `mod/booking:alwayscanapprove` | Approve bookings regardless of the instance-level approval setting | manager |

> See [Booking option — Demand confirmation](../booking-option/08-confirmation.md) for full details on the confirmation workflow.

---

## 12. Administration and reporting

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:addinstance` | Add a new Booking activity to a course | editingteacher, manager |
| `mod/booking:viewreports` | Access the booking reports page | manager |
| `mod/booking:viewscheduledmails` | View the queue of scheduled/pending rule emails | *(see note)* |
| `mod/booking:editscheduledmails` | Edit or cancel scheduled rule emails | manager |

> **`viewscheduledmails`** — The access.php entry uses the key `viewscheduledmails` but the capability may appear as `viewperformance` or `editperformance` in some installations depending on the version. Check `db/access.php` for the exact capability name in your version.

---

## 13. Developer / performance capabilities

| Capability | What it allows | Default roles |
|------------|---------------|---------------|
| `mod/booking:viewperformance` | View performance and timing metrics (developer tooling) | manager |
| `mod/booking:editperformance` | Edit performance settings | manager |

---

## See also

- [Booking rules](../booking_rules/README.md) — `editbookingrules` capability
- [Shortcodes](../shortcodes/README.md) — Capability requirements for each shortcode
- [Availability conditions](../booking_conditions/README.md) — `overrideboconditions` capability

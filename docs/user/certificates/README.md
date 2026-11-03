[Back to parent section](../README.md)

# Certificates and certificate conditions

Booking can issue a certificate when a participant completes booking options. The certificate itself is a
template of the Moodle plugin **Certificate manager** (`tool_certificate`); Booking decides *when* a
participant receives it and fills it with booking data.

There are two ways to do this:

| Mode | Where it is configured | Typical use |
|------|------------------------|-------------|
| **Simple certificate option** | In the form of a single booking option | "Whoever completes this course gets this certificate." |
| **Certificate conditions** (PRO) | A central list of conditions, site-wide or per booking instance | "Whoever completes A, one of B1/B2, one of C1/C2 and one of D1/D2 gets this certificate." |

This guide covers both, with the focus on certificate conditions. Entry tickets are **not** certificates,
see [Entry tickets](../ticketing/README.md).

---

## Table of contents

1. [Prerequisites and site settings](#1-prerequisites-and-site-settings)
2. [Quick setup path](#2-quick-setup-path)
3. [Anatomy of a certificate condition](#3-anatomy-of-a-certificate-condition)
4. [Condition types](#4-condition-types)
5. [Combinations (AND / OR)](#5-combinations-and--or)
6. [Worked example: one of each course, plus one more](#6-worked-example-one-of-each-course-plus-one-more)
7. [When certificates are issued](#7-when-certificates-are-issued)
8. [Data on the certificate](#8-data-on-the-certificate)
9. [Changing conditions later](#9-changing-conditions-later)
10. [Simple certificate option](#10-simple-certificate-option)
11. [Where issued certificates are visible](#11-where-issued-certificates-are-visible)
12. [Permissions](#12-permissions)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Prerequisites and site settings

- The **Certificate manager** plugin (`tool_certificate`) is installed and at least one certificate template
  exists under *Site administration → Certificates → Manage certificate templates*.
- Booking **PRO** is licensed. Certificate creation is a PRO feature.

Site administration → Plugins → Activity modules → Booking:

| Setting | Meaning |
|---------|---------|
| **Activate certificate creation** (`certificateon`) | Master switch. Without it no certificate is issued and the certificate fields do not appear in the option form. |
| **Certificate options** (`certificateoptions`) | *Simple certificate option* or *Certificate Conditions*. The two modes do not run side by side: in conditions mode the certificate fields of the option form are replaced by the list of conditions that target the option. |
| **Manual certificate trigger only** (`certificatemanualtrigger`) | Completions no longer issue certificates automatically; a manager issues them from the list of booked users (see [section 7](#7-when-certificates-are-issued)). |
| **Issue multiple certificates** (`issuemultiplecertificates`, conditions mode only) | Site default for what happens when a condition is fulfilled again for a user who already holds its certificate. Off: one certificate per user and condition. On: a new certificate every time. Each "Create certificate" action can override this. |
| **Certification issue with presence status** (`presencestatustoissuecertificate`, simple mode only) | The certificate is issued when the chosen presence status is set, instead of on completion. |

Once *Certificate Conditions* is selected, the list of conditions is reachable from

- **Site administration → Plugins → Booking → Certificate Conditions** (`/mod/booking/edit_certificateconditions.php`), for site-wide conditions, and
- the navigation of every booking activity, entry **Certificate Conditions (name of the instance)**, for conditions of that instance.

---

## 2. Quick setup path

1. Create or pick a certificate template in *Site administration → Certificates → Manage certificate templates*.
2. Switch on **Activate certificate creation** and set **Certificate options** to *Certificate Conditions*.
3. Open **Certificate Conditions** (site-wide or inside the booking activity) and click *Add condition*.
4. Give the condition a name, leave the filter on *No restriction*, choose a **Condition** type and fill in
   its fields (see [section 4](#4-condition-types)).
5. Under **Action** choose **Create certificate**, select the template under *Certificate ID*, set the expiry
   date and whether one or several certificates may be issued. Save.
6. Test with a test user: book the user into the option(s), mark the booking as completed in the list of
   booked users, and check *Profile → My certificates* of that user.

For an AND/OR rule across several conditions, first save each part as its own condition with the action
**No action (use only as building block of a combination)**, then create a condition of type
**Combination of conditions (AND / OR)**, see [section 5](#5-combinations-and--or).

---

## 3. Anatomy of a certificate condition

Every saved condition has three parts:

| Part | Options | Notes |
|------|---------|-------|
| **Filter** (optional) | *No restriction*, or **User profile field**: a custom profile field and the required value. | A user who does not match the filter never receives the certificate of this condition. |
| **Condition** | One of the four types in [section 4](#4-condition-types). | What the user must have completed. |
| **Action** (optional) | **Create certificate** with *Certificate ID* (the template), expiry date (*Never*, a fixed date, or a period after issue) and *Issue multiple certificates* (*Use global setting*, *prevent*, *allow*). Or **No action**. | A condition with *No action* issues nothing itself. It exists to be used as a building block of a combination. |

A condition also has:

- a **name**, shown in the list and stored with every certificate it issues,
- an **active** switch; inactive conditions are skipped,
- a **context**: site-wide conditions apply to options of every booking instance, conditions created inside a
  booking activity only to options of that instance. The context cannot be changed afterwards.

The list of conditions shows a short summary of every condition (for example `1 of 2 selected booking options`
or `(Leaf A ≥ 1) AND (Leaf B ≥ 1 OR Leaf C ≥ 1) [each option counts once]`) and badges: **Combination**,
**Building block** (no own action), **Also used as building block** (has an action and is used by a
combination), **Used in** with the combinations that use a condition, and **Not used in any combination** for
building blocks that nothing references yet.

---

## 4. Condition types

### Booking option

| Field | Meaning |
|-------|---------|
| **Booking option ID** | One or several booking options, chosen from a search box. |
| **Required completed options count** | How many of them the user must have completed. Default 1. |

Examples: one specific course (one option, count 1); "any one of the three dates of this course" (three options,
count 1); "two of these five workshops" (five options, count 2). Because of the required count, a booking
option condition with several options and count 1 is the way to express **OR** between options.

### Booking instance options

| Field | Meaning |
|-------|---------|
| **Booking instance** | One booking activity. |
| **Required completed options count** | How many options of that instance the user must have completed. |

Covers options that are created later in the same instance automatically.

### Tagged options

| Field | Meaning |
|-------|---------|
| **Required completed options count** | How many of the tagged options the user must have completed. |

The options are not chosen in the condition. Instead, every booking option form has a field
**Certificate conditions** that lists the tagged-options conditions of the site and of the option's
instance; tick the conditions the option belongs to. Use this when the set of options is maintained by the
people who edit the options, not by the person who maintains the conditions.

### Combination of conditions (AND / OR)

Combines other saved conditions. See the next section.

---

## 5. Combinations (AND / OR)

A combination references other saved conditions, its **building blocks** (also called leaves), and arranges
them in up to five **groups**.

| Field | Meaning |
|-------|---------|
| **Combine the groups with** | AND: every used group must be fulfilled. OR: one fulfilled group suffices. |
| **Group 1 … Group 5**, each with **Combine the conditions of this group with** and **Conditions** | Set the operator of a group to *Not used*, *AND* or *OR*, and pick its conditions. Within a group the conditions are combined with the group operator. |
| **Each completed booking option counts only once** | A booking option that was counted for one building block cannot be counted again for another building block of the same combination. Needed whenever the same option appears in several building blocks and the rule means "another option". |
| **Each building block counts only once** | A building block that counted for a fulfilled group is skipped in all later groups. Needed when the building blocks stand for courses with several dates and the rule means "another course": another date of an already counted course then does not count. |
| **Groups must be completed in order** | Only with the outer operator AND. A completion counts for a group only if it happened after the previous group became complete. Within a group the order does not matter. |

Rules and limits:

- Building blocks must be saved in the **same context** as the combination: site-wide conditions for a
  site-wide combination, conditions of the instance for a combination inside that instance.
- A combination **cannot be a building block** of another combination. Two levels, groups and the conditions
  inside them, are all there is. See [section 6](#6-worked-example-one-of-each-course-plus-one-more) for
  how far that gets you.
- The filter, the action and the active switch of a building block are **ignored** inside a combination.
  Only the combination's own filter and action count. A building block may still have its own action; then
  it issues its own certificate as well, independently of the combination.
- Deleting a building block removes it from every combination that uses it. A combination left without any
  condition in a group drops that group.
- The combination is checked whenever the user completes an option that one of its building blocks observes.
  Completions of unrelated options do not trigger it.

How the evaluation picks options when "each option counts once" is on: the groups are evaluated in order, each
building block takes the options it needs and these options are no longer available to later building blocks.
Options that several building blocks observe are taken last, so that they stay available for the others. A
group that fails leaves nothing taken. With "each building block counts once" the building block itself is
taken as well and is skipped by every later group. With "Groups must be completed in order" each building block
takes its earliest qualifying completions, so that the next group may start as early as possible.

---

## 6. Worked example: one of each course, plus one more

Rule: the participant must complete course A, one course from each of the areas B, C and D, and then one
more course from any of these areas. Every course is offered on several dates, and every date is a booking
option. Completing a second date of a course that already counted must **not** count as the additional course.

The two levels of this rule map onto the two levels of a combination:

- **A course** is a *Booking option* condition holding **all dates** of that course, required count 1, action
  *No action*. Completing any date fulfils it, and it is the unit that must not count twice. With many courses,
  a *Tagged options* condition per course is less clicking: tick the condition on every date's option form.
  When every unit is its own booking activity (all dates of a course in one instance), use a *Booking instance
  options* condition per instance instead; the building block is then the instance.
- **An area** is a group with operator OR over the courses of the area.

Building blocks (all required count 1, action *No action*):

| Name | Booking options |
|------|-----------------|
| Course A | all dates of A |
| Course B1, Course B2, … | all dates of the respective course of area B |
| Course C1, Course C2, … | same for area C |
| Course D1, Course D2, … | same for area D |

Combination (action *Create certificate*):

| Setting | Value |
|---------|-------|
| Combine the groups with | AND |
| Group 1 | AND: Course A |
| Group 2 | OR: the courses of area B |
| Group 3 | OR: the courses of area C |
| Group 4 | OR: the courses of area D |
| Group 5 | OR: all courses of the areas B, C and D |
| Each completed booking option counts only once | on |
| Each building block counts only once | **on** |
| Groups must be completed in order | off |

Why the second checkbox is needed: "Each completed booking option counts only once" works per booking option.
A participant who completed two dates of Course B1 would fulfil group 5 with the second date. "Each building
block counts only once" skips Course B1 in group 5 because it already counted for group 2, so group 5 needs a
course that groups 2 to 4 did not use. Consequently the areas must offer at least four courses in total,
otherwise group 5 can never be fulfilled.

If the rule were "A, then the three areas, then one more": switch on **Groups must be completed in order**.

The general recipe:

- **OR between dates of the same course** → one *Booking option* or *Tagged options* condition with all dates,
  count 1 (or a *Booking instance options* condition when the course is its own booking activity).
- **AND / OR between courses** → groups of a combination.
- **"Another option"** → "Each completed booking option counts only once".
- **"Another course"** → "Each building block counts only once".
- Any yes/no rule can be written as "OR of ANDs" or "AND of ORs", so two levels are enough. If you run out of
  the five groups, split the rule into two combinations with their own certificates, or ask for the limit to be
  raised.
- Not expressible: a sequence inside an OR branch ("(A then B) or (C then D)") and a "counts once" setting that
  applies to only part of the rule. These would need nested combinations, which do not exist.

---

## 7. When certificates are issued

1. A booking is marked as **completed** (by a manager in the list of booked users, by the course completion
   sync of a linked course, or by an import).
2. Booking collects every active condition that observes the completed option: conditions listing the option,
   the condition of its booking instance, tagged-options conditions the option is assigned to, and the
   combinations built on any of these.
3. For each of them the filter and the condition are evaluated for that user. When both pass and the condition
   has the action *Create certificate*, the certificate is issued, unless the user already holds a certificate
   of this condition and multiple certificates are not allowed.

Consequences worth knowing:

- **No retroactive evaluation.** Creating or changing a condition does not look at past completions. Users
  who already fulfil the rule receive the certificate at their next completion of an observed option, or when
  a manager triggers it manually.
- **Manual issue.** In the list of booked users (Bookings tracker) select bookings and choose **Issue
  certificate**. Booking builds a completion event for each selected booking and runs the same evaluation,
  so the conditions are respected; nothing is issued for a user who does not fulfil them. This is also the
  only way to issue certificates when **Manual certificate trigger only** is on.
- **Issue multiple certificates.** With the site setting off and the action on *Use global setting*, a user
  receives the certificate of a condition once; later completions that fulfil the condition again issue
  nothing. With *allow*, every fulfilling completion issues a new certificate. The check is per condition and
  per template: the same user can hold certificates from different conditions.
- **Awarded on.** The date on the certificate (field *Awarded on*) is the completion date of the latest
  option the certificate counts, not the moment the evaluation ran. Backdated completions, imports and manual
  triggers therefore show the real completion date. The format is the language string `timeawardedformat`
  (default `%d.%m.%Y`) and can be changed in *Site administration → Language → Language customisation*.
- **Expiry.** The expiry date comes from the action: never, a fixed date, or a period after the issue date.

---

## 8. Data on the certificate

When Booking issues a certificate it stores booking data with the issue, as custom fields of the certificate
issue, so the template elements can print them. Fields marked with an asterisk take the data of the booking
option whose completion issued the certificate.

| Field | Content |
|-------|---------|
| `bookingoptionname` *, `bookingoptionid` * | Title (with prefix) and id of the booking option |
| `bookinginstancename` * | Name of the booking activity |
| `bookingoptiondescription` * | Description, tags stripped |
| `teachers` *, `teacherscommaseparated` * | Teachers, one per line or comma separated |
| `sessions` *, `daterange` *, `duration` *, `dateswithduration` * | Dates of the option in different formats |
| `location` *, `institution` * | Location and institution |
| `timeawarded` | Awarded on, see [section 7](#7-when-certificates-are-issued) |
| `competencies` * | Competencies of the option, if competencies are used |
| `bookingnotes` * | Notes stored on the booking of this user |
| `cf<shortname>` * | Every text or textarea custom field of the option, for example `cfroom` |
| `conditionid`, `conditionname` | The condition that issued the certificate (conditions mode only) |

For certificates issued by a condition that spans several options, use the shortcodes in a text element of
the template. They list **all** options the certificate counts, in the order of their completion:

| Shortcode | Output per option |
|-----------|-------------------|
| `[bookingoptionsfromcondition]` | Title, followed by the teachers when the option has any |
| `[bookingoptionsfromconditionwithdate]` | Title with the completion date in brackets, same date format as *Awarded on*, followed by the teachers |

Which options are listed: for booking option, booking instance and tagged-options conditions the completed
options of the condition; for a combination without "Groups must be completed in order" all completed options
of its building blocks; for a combination with that setting exactly the options that earned the certificate.
See [`[bookingoptionsfromcondition]`](../shortcodes/bookingoptionsfromcondition.md) and
[`[bookingoptionsfromconditionwithdate]`](../shortcodes/bookingoptionsfromconditionwithdate.md).

---

## 9. Changing conditions later

**Issued certificates are never changed or revoked by Booking.** Editing, deactivating or deleting a
condition leaves every certificate it issued in place. Revoking is done in the Certificate manager.

**New completions follow the current definition.** Conditions are read at every evaluation, so a change is
effective immediately for everything that happens afterwards.

Typical changes:

- **A course gets a new date.** Add the new booking option to the condition of that course (*Course B1* in the
  example). The combination references the condition by id and needs no change. Do not create a separate
  condition for the new date: that would break "all dates count as one course" and force you to edit every
  group.
- **Tightening a rule.** Users certified under the old rule keep their certificate. Users who were close to
  fulfilling the old rule now have to meet the new one.
- **Loosening a rule.** Users who already fulfil the new rule through past completions are not evaluated
  automatically, see [section 7](#7-when-certificates-are-issued). Trigger them manually from the list of
  booked users, or wait for their next completion.
- **Users who already hold the certificate.** Whether they receive it again after a later completion depends
  on the "Issue multiple certificates" settings. The duplicate check uses the condition id, so editing a
  condition keeps it working; deleting and recreating the condition gives it a new id and old certificates
  no longer count as "already issued".
- **Deleting a building block** removes it from the combinations using it. Check their summaries in the list
  afterwards; a combination may have become easier to fulfil.

---

## 10. Simple certificate option

With **Certificate options** set to *Simple certificate option*, the certificate is configured in the
booking option form:

| Field | Meaning |
|-------|---------|
| **Certificate** | The template to issue when this option is completed. |
| **Expiry date** | Never, a fixed date, or a period after issue. |
| **Requires completion of these other booking options** | Other options the user must have completed as well. |
| **Only one of the selected options needs to be completed** | Unticked: all selected options are required. Ticked: one of them suffices. |

Issued on completion of the option, or, if **Certification issue with presence status** is set, when that
presence status is recorded for the booking. The *Awarded on* date and the data fields of
[section 8](#8-data-on-the-certificate) apply here too; the shortcodes list the option itself and the
completed required options.

---

## 11. Where issued certificates are visible

- Participants: *Profile → My certificates* (`/admin/tool/certificate/my.php`), where they can download
  and share them. The certificate carries a verification code.
- Managers: the list of booked users has a column **Latest certificate** with issue and expiry date.
- Site administration → Certificates → *Manage certificate templates* → the list of issued certificates of a
  template shows every issue with its data, and allows revoking.
- Every issue logs the event *Certificate issued* (`\mod_booking\event\certificate_issued`), visible in the
  Moodle logs and usable as trigger of a booking rule, for example to send a mail with the certificate link
  (`{certificateurl}` placeholder).

---

## 12. Permissions

| Capability | Purpose | Default |
|------------|---------|---------|
| `mod/booking:editcertificateconditions` | Create, edit and delete certificate conditions, site-wide and per instance. Assign it in a **system-wide role**; it is checked in the course context and in the booking activity navigation. | Manager |
| `tool/certificate:*` | Managing templates, viewing and revoking issued certificates, verifying codes. | See the Certificate manager documentation |

Marking bookings as completed and using *Issue certificate* in the list of booked users needs the capability
to manage booked users of the instance.

---

## 13. Troubleshooting

| Symptom | Check |
|---------|-------|
| No certificate after completion | Is *Activate certificate creation* on and the right *Certificate options* mode chosen? Is the condition active? Does it have the action *Create certificate*? Is *Manual certificate trigger only* off? Does the user match the filter? |
| Certificate issued once, not again | Expected with *Issue multiple certificates* off. Switch the site setting or the action to *allow* if repeated certificates are wanted. |
| A building block issues its own certificate | It has the action *Create certificate*. Set it to *No action* if only the combination should issue. |
| Combination never fulfilled | Open its summary in the list. With "each option counts once", check that there are enough different options for all building blocks; with "each building block counts once", that there are enough building blocks for all groups. With "Groups must be completed in order", check the completion dates of the user. |
| "References a condition of another context" | The combination uses a condition from another context. Recreate the building block in the combination's context and replace it. |
| Shortcode prints `PLACEHOLDER` | The shortcode was rendered outside the issue of a certificate, for example in the template preview. On a real certificate it lists the options. |
| Users who already fulfil a new rule get nothing | Conditions are not evaluated retroactively. Use *Issue certificate* in the list of booked users. |

---

## Related docs

- [`[bookingoptionsfromcondition]`](../shortcodes/bookingoptionsfromcondition.md) and
  [`[bookingoptionsfromconditionwithdate]`](../shortcodes/bookingoptionsfromconditionwithdate.md)
- [Placeholders](../placeholders/README.md), section "Certificates and QR codes"
- [Entry tickets](../ticketing/README.md), which are not certificates
- [Bookings tracker](../reports/README.md), where bookings are completed and certificates triggered
- Developer notes: `classes/local/certificate_conditions/README.md` in the plugin

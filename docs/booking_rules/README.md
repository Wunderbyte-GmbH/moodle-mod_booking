# Booking Rules — Overview

Booking rules let you automate actions inside the **mod_booking** plugin.
A rule watches for a specific trigger (a date-based schedule or a Moodle event) and — when the trigger fires and the optional conditions are met — runs an action such as sending an email.

---

## Table of Contents

1. [How it works — the three-part model](#1-how-it-works--the-three-part-model)
2. [Where to manage rules](#2-where-to-manage-rules)
3. [Free vs. PRO version limits](#3-free-vs-pro-version-limits)
4. [Skipping rules on individual options](#4-skipping-rules-on-individual-options)
5. [Context levels — system-wide vs. per-instance rules](#5-context-levels--system-wide-vs-per-instance-rules)
6. [Rule templates (quick start)](#6-rule-templates-quick-start)
7. [Further reading](#7-further-reading)

---

## 1. How it works — the three-part model

Every booking rule consists of exactly three parts chosen in the rule editor:

```
[Rule type / Trigger]  →  [Condition]  →  [Action]
       WHEN                   WHO              WHAT
```

| Part | Question it answers | Examples |
|------|---------------------|---------|
| **Rule type** | *When* should this rule run? | 3 days before course start; immediately when a booking is confirmed |
| **Condition** | *Who* should be affected? | All booked students; only the teacher(s); a specific set of users |
| **Action** | *What* should happen? | Send an email; confirm a booking answer; remove conditions |

Detailed pages for each part:

- [Rule types (triggers)](rule-types.md)
- [Conditions](conditions.md)
- [Actions](actions.md)

---

## 2. Where to manage rules

Rules are managed on the **Booking Rules** administration page, which is accessible in two ways:

- **Site-wide** (applies to all booking instances):
  *Site administration → Plugins → Activity modules → Booking → Edit booking rules*
  Direct URL: `/mod/booking/edit_rules.php?contextid=1`

- **Per booking instance** (applies only to that instance, requires PRO):
  Inside a booking activity → *Settings → Edit booking rules*
  Direct URL: `/mod/booking/edit_rules.php?cmid=<cmid>`

![Booking Rules page](pix/edit_rules_overview.png)
<!-- Screenshot placeholder: the booking rules list page showing existing rules with add/edit/delete buttons -->

The page shows all saved rules with their name, rule type, status (active/inactive), and action buttons.

---

## 3. Free vs. PRO version limits

| Feature | Free | PRO |
|---------|------|-----|
| Maximum rules at system level | 3 | Unlimited |
| Rules per booking instance (cmid) | ✗ Not available | ✓ |
| All rule types, conditions, and actions | ✓ | ✓ |

---

## 4. Skipping rules on individual options

Individual booking options can opt out of selected rules (or opt in to only specific rules) using the **Skip booking rules** field in the option editor.

| CSV column | What it does |
|------------|-------------|
| `skipbookingrules` | Comma-separated IDs of rules to skip |
| `skipbookingrulesmode` | `0` = exclude listed rules; `1` = apply only listed rules |

---

## 5. Context levels — system-wide vs. per-instance rules

A rule is always saved with a **context ID**:

- `contextid = 1` (system context): The rule applies to **all** booking options across the whole Moodle site.
- `contextid = <module context>`: The rule applies only to booking options inside that specific booking instance.

When a rule is executed, the system walks up the **context path** of the booking option and executes all matching rules it finds along the way — so a system-level rule and an instance-level rule can both run for the same option.

---

## 6. Rule templates (quick start)

Several pre-built templates are available directly from the rule editor:

| Template name | What it does |
|---------------|-------------|
| Notification n days before start | Email reminder N days before `coursestarttime` |
| Reminder before each session (date) | Per-session reminder using `optiondatestarttime` |
| Updates | Notification when a booking option is updated |
| Confirm booking | Confirmation email when a booking answer is confirmed |
| Confirm waiting list | Confirmation email when placed on the waiting list |
| Payment for booking is confirmed | Email after successful payment via shopping cart |
| Booking option completed with poll | Completion notification including poll URL |
| Booking option completion undone | Notification that completion was reversed |
| Booking option cancellation – Mail to teachers | Cancellation notification sent to teachers |

See [Templates](templates.md) for the full subject lines, message bodies, and configuration details.

---

## 7. Further reading

| Page | Content |
|------|---------|
| [rule-types.md](rule-types.md) | Detailed description of all three rule triggers |
| [conditions.md](conditions.md) | All available conditions and their configuration options |
| [actions.md](actions.md) | All available actions and their configuration options |
| [templates.md](templates.md) | Pre-built rule templates and how to load them |
| [examples.md](examples.md) | Practical end-to-end examples |

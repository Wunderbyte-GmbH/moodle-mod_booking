# Architecture — mod_booking Plugin

This document describes the high-level architecture of the mod_booking Moodle plugin, its key class hierarchy, and how data flows through the main use cases.

---

## Table of Contents

1. [Plugin directory structure](#1-plugin-directory-structure)
2. [Core data model](#2-core-data-model)
3. [Key class hierarchy](#3-key-class-hierarchy)
4. [Data flow: booking a participant](#4-data-flow-booking-a-participant)
5. [Data flow: sending a booking rule email](#5-data-flow-sending-a-booking-rule-email)
6. [Extension points](#6-extension-points)

---

## 1. Plugin directory structure

```
mod/booking/
├── db/
│   ├── install.xml         # DB schema
│   ├── upgrade.php         # DB upgrade steps
│   ├── access.php          # Capabilities
│   ├── tasks.php           # Scheduled tasks
│   ├── shortcodes.php      # Shortcode registrations
│   └── events.php          # Event observers
├── classes/
│   ├── booking.php                  # Booking instance (activity-level)
│   ├── booking_option.php           # Booking option (single bookable item)
│   ├── booking_option_settings.php  # Option settings/cache object
│   ├── booking_answers/             # Booking answer management
│   ├── bo_availability/             # Availability condition pipeline
│   │   ├── bo_info.php              # Orchestrator: evaluates all conditions
│   │   ├── bo_condition.php         # Base interface for conditions
│   │   └── conditions/              # 48 condition implementations
│   ├── booking_rules/               # Booking rules system
│   │   ├── booking_rules.php        # Rule management
│   │   ├── booking_rule.php         # Single rule interface
│   │   ├── rules/                   # Rule types (triggers)
│   │   ├── conditions/              # Rule conditions (who)
│   │   └── actions/                 # Rule actions (what)
│   ├── booking_campaigns/           # Campaign system
│   ├── bo_actions/                  # Actions after booking
│   ├── subbookings/                 # Sub-booking system
│   ├── placeholders/                # Email placeholder system
│   ├── plugininfo/                  # Subplugin info classes
│   ├── option/fields/               # Booking option form field classes
│   ├── task/                        # Scheduled and ad-hoc tasks
│   └── local/                       # Utility and helper classes
├── bookingextension/        # Subplugins directory (booking extensions)
├── docs/                    # This documentation
└── templates/               # Mustache templates
```

---

## 2. Core data model

```
booking (activity instance)
  └── booking_options (one bookable item per row)
        ├── booking_optiondates (session dates per option)
        ├── booking_answers (one row per user booking)
        │     └── booking_subbooking_answers (sub-booking choices)
        └── booking_rules → booking_rule_actions/conditions
```

Key database tables:

| Table | Description |
|-------|-------------|
| `booking` | One row per booking activity instance |
| `booking_options` | One row per bookable option; contains title, capacity, JSON for availability conditions and bo_actions |
| `booking_optiondates` | Session dates linked to a booking option |
| `booking_answers` | One row per user/option booking answer (status: booked, waiting list, notification list, etc.) |
| `booking_rules` | Booking rule definitions |
| `booking_campaigns` | Campaign definitions |
| `booking_subbookings` | Sub-booking definitions linked to options |
| `booking_subbooking_answers` | User choices for sub-bookings |

---

## 3. Key class hierarchy

### Booking option settings (read layer)

```
booking_option_settings     ← Cached settings object for one option
    uses: singleton_service ← Static object registry (prevents re-querying DB)
    uses: bo_info           ← Evaluates all availability conditions
```

### Availability condition pipeline

```
bo_info::get_condition_results($optionid, $userid)
  └── iterates: classes/bo_availability/conditions/*.php
        each implements: bo_condition interface
            → is_available($optionid, $userid, $not, $context)
            → get_description($optionid, $context)
```

Conditions are evaluated in order of their `get_sortorder()` value. The first blocking condition (returning `false` from `is_available()`) stops the pipeline and its description is shown to the user.

### Booking rules pipeline

```
booking_rules::get_all_rules()
  └── booking_rule (one rule)
        ├── rule_type (trigger): rule_daysbefore / rule_specifictime / rule_react_on_event
        ├── rule_condition (who): select_student_in_bo / select_teacher_in_bo / ...
        └── rule_action (what): send_mail / confirm_bookinganswer / ...
```

---

## 4. Data flow: booking a participant

1. User clicks "Book it" on `optionview.php`.
2. `booking_option::user_submit_response()` is called.
3. `bo_info::is_available()` is checked — if any condition blocks, the booking is rejected.
4. A new `booking_answers` record is created with the appropriate status.
5. `message_controller` is triggered to send the confirmation email (uses the legacy system or booking rules, depending on settings).
6. Any `bo_actions` (actions after booking) on the option are executed in order.
7. If sub-bookings are attached and blocking, the user is redirected to the sub-booking step before the booking is finalised.

---

## 5. Data flow: sending a booking rule email

1. `send_reminder_mails` scheduled task (for `rule_daysbefore`) or an event observer (for `rule_react_on_event`) triggers the rule evaluation.
2. `booking_rules::get_matching_rules()` finds all active rules whose trigger criteria match.
3. For each matching rule, the condition class narrows down the set of target users.
4. The action class (`send_mail`, `send_mail_interval`, etc.) queues an ad-hoc task (`send_mail_by_rule_adhoc`) for each user.
5. The ad-hoc task executes, calls `message_controller` with the rule's subject/body templates.
6. `placeholders_info::replace_placeholders_in_text()` substitutes all `{token}` placeholders with live values before sending.

---

## 6. Extension points

mod_booking provides multiple extension points for customisation:

| Extension point | How to extend |
|-----------------|--------------|
| Availability conditions | Add a new class to `classes/bo_availability/conditions/` implementing `bo_condition` |
| Booking rule types | Add a class to `classes/booking_rules/rules/` implementing `booking_rule` |
| Booking rule conditions | Add a class to `classes/booking_rules/conditions/` implementing `booking_rule_condition` |
| Booking rule actions | Add a class to `classes/booking_rules/actions/` implementing `booking_rule_action` |
| Actions after booking | Add a class to `classes/bo_actions/action_types/` extending `booking_action` |
| Campaigns | Add a class to `classes/booking_campaigns/campaigns/` implementing `booking_campaign` |
| Sub-bookings | Add a class to `classes/subbookings/sb_types/` implementing `booking_subbooking` |
| Placeholders | Add a class to `classes/placeholders/placeholders/` extending `placeholder_base` |
| Booking extensions (subplugins) | Create a `bookingextension_*` subplugin under `bookingextension/` |

---

## See also

- [Booking rules API](BOOKING_RULES_API.md)
- [Availability conditions API](AVAILABILITY_CONDITIONS_API.md)
- [Campaigns API](CAMPAIGNS_API.md)
- [Sub-bookings API](SUBBOOKINGS_API.md)
- [Placeholders API](PLACEHOLDERS_API.md)
- [Booking extensions API](BOOKING_EXTENSIONS_API.md)

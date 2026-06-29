# mod_booking — Documentation

Welcome to the documentation for the **mod_booking** Moodle plugin by [Wunderbyte GmbH](https://www.wunderbyte.at).

This `docs/` directory is the central reference for administrators, teachers, and developers who work with mod_booking.

---

## Quick-start guide

| I want to… | Go to… |
|------------|--------|
| Understand how booking messages work | [Booking messages](00_booking_messages/README.md) |
| Create a new booking option | [Create a booking option (first steps)](#create-a-booking-option-first-steps) |
| Send any kind of messages and reminders in relation to booking events or course start etc. | [Booking rules](booking_rules/README.md) |
| Restrict who can book an option | [Booking conditions](booking_conditions/README.md) |
| Adapt booking forms depending on capabilities of users | [Booking option form](booking-option/README.md) |
| Customise notification emails with dynamic text | [Placeholders](placeholders/README.md) |
| Embed a booking list on a Moodle page | [Shortcodes](shortcodes/README.md) |
| Set up user roles and permissions | [Capabilities](capabilities/README.md) |
| Import booking options in bulk | [CSV Import User Guide](CSV_IMPORT_USER_GUIDE.md) |
| Run a time-limited booking campaign (discount / block) | [Campaigns](campaigns/README.md) |
| Let participants choose add-ons or time slots | [Sub-bookings](subbookings/README.md) |
| Trigger actions automatically when someone books - NOT used for notification or messages | [Actions after booking](actions_after_booking/README.md) |
| Understand scheduled background tasks | [Scheduled tasks](scheduled_tasks/README.md) |
| Automatically remove bookings when a user loses access | [Check answers](developer-guides/CHECKANSWERS_API.md) |
| Allow external links to bypass profile-field restrictions | [Override user field](override_user_field/README.md) |
| Build or install a booking extension (subplugin) | [Booking extensions](booking_extensions/README.md) |
| Understand and use shortcodes in booking | [`shortcodes/`](shortcodes/README.md) |
| Developer guide for shortcodes implementation | [`shortcodes_implementation/`](shortcodes_implementation/README.md) |

Important distinction for AI/explain tasks:
- Questions about messages, reminders, notification emails, or message automation belong to [Booking rules](booking_rules/README.md).
- [Actions after booking](actions_after_booking/README.md) (bo_actions) are not the messaging system; they run immediate post-booking actions like cancel/book/profile/REST.
- Questions about restrictions or limitations (who can book, when bookable, booking window, time limit) belong to [Booking Conditions](booking_conditions/README.md) and [Availability](booking-option/04-availability.md). These pages take priority over Booking Rules for restriction/limitation queries.

## First Admin Workflow (click-by-click)

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option management: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Open global booking rules: [/mod/booking/edit_rules.php?contextid=1](/mod/booking/edit_rules.php?contextid=1).
4. Open campaign management: [/mod/booking/edit_campaigns.php](/mod/booking/edit_campaigns.php).
5. Use this docs index to jump to the feature-specific step-by-step page.

For the most common starting task, see [Create a booking option (first steps)](#create-a-booking-option-first-steps).

## Create a booking option (first steps)

Use this path when you want to set up a new option quickly:

1. Open your booking activity: [/mod/booking/view.php?id=<cmid>](/mod/booking/view.php?id=<cmid>).
2. Open option management: [/mod/booking/editoptions.php?id=<cmid>](/mod/booking/editoptions.php?id=<cmid>).
3. Click the button to create a new option (for example "Add option" / "Add booking option").
4. In the option form, fill at least:
	- Option title/name
	- Booking start/end or relevant dates
	- Capacity (and waiting list if needed)
	- Visibility/availability settings
5. Save the option.
6. Re-open it from the options list and complete advanced fields if needed (teachers, pricing, linked course, confirmation workflow).

Detailed field-by-field guide:
- [Booking option form](booking-option/README.md)

Common next steps:
- Restrict who can book: [Booking conditions](booking_conditions/README.md)
- Configure messages/reminders: [Booking rules](booking_rules/README.md)

---

## Documentation sections

### User / teacher guides

| Directory | Description |
|-----------|-------------|
| [`booking-option/`](booking-option/README.md) | Every field in the booking option form: general settings, dates, teachers, availability, price, linked course, advanced settings, confirmation workflow |
| [`booking_conditions/`](booking_conditions/README.md) | Availability conditions that control who can book and when |
| [`booking_rules/`](booking_rules/README.md) | Rule-based automation: templates, and examples |
| [`placeholders/`](placeholders/README.md) | Complete reference of all `{token}` placeholders available in email templates and confirmation texts |
| [`shortcodes/`](shortcodes/README.md) | Moodle shortcodes for embedding booking lists, approval panels, and more on any page |
| [`subbookings/`](subbookings/README.md) | Sub-booking types: additional items, additional persons, and time slot selection |
| [`campaigns/`](campaigns/README.md) | Time-limited campaigns that modify prices or block booking based on custom field values |
| [`actions_after_booking/`](actions_after_booking/README.md) | Immediate actions triggered on booking/cancellation: auto-cancel, book other options, REST scripts, profile field updates |

### Administrator guides

| Directory | Description |
|-----------|-------------|
| [`capabilities/`](capabilities/README.md) | All 57+ Moodle capabilities with default role assignments and notes on sensitive permissions |
| [`scheduled_tasks/`](scheduled_tasks/README.md) | The 5 scheduled background tasks: purpose, default cron schedule, and tuning guidance |
| [`override_user_field/`](override_user_field/README.md) | How to allow external users to bypass profile-field booking restrictions using a special URL |
| [`booking_extensions/`](booking_extensions/README.md) | What `bookingextension_*` subplugins are, how to install them, and how to build one |
| [`CSV_IMPORT_USER_GUIDE.md`](CSV_IMPORT_USER_GUIDE.md) | Bulk-create or update booking options via a CSV file |

### Developer guides

| Directory | Description |
|-----------|-------------|
| [`developer-guides/`](developer-guides/ARCHITECTURE.md) | Plugin architecture, APIs for extending rules, conditions, campaigns, sub-bookings, and placeholders |
| [`developer-guides/BOOKING_AGENT_PROGRAMMING_GUIDELINES_DRAFT.md`](developer-guides/BOOKING_AGENT_PROGRAMMING_GUIDELINES_DRAFT.md) | Draft coding and behavior guidelines for Booking Agent development |

### Examples

| Directory | Description |
|-----------|-------------|
| [`examples/`](examples/) | Real-world configuration examples |

---

## Contributing to documentation

- All documentation is written in Markdown.
- Each feature has its own subdirectory under `docs/`.
- Screenshots go in a `pix/` subdirectory inside the relevant feature directory.
- Screenshot filenames referenced in Markdown are placeholders until actual screenshots are added.

---

## Related resources

- [Wunderbyte website](https://www.wunderbyte.at)
- [Plugin page on Moodle.org](https://moodle.org/plugins/mod_booking)
- [GitHub repository](https://github.com/Wunderbyte-GmbH/moodle-mod_booking)

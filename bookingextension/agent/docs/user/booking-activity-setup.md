# Configuring the Booking activity

The wizard can change the settings of the Booking activity itself — the instance settings that control how the whole activity behaves, such as whether participants may cancel, how many options one person may book, and which e-mails go out. This page shows you how to ask what can be configured and how to change it.

Changing an activity setting is a change, so the wizard shows you exactly what it will set before applying it, and waits for your confirmation. Nothing is saved until you say yes.

## Asking what can be configured

If you're not sure what's adjustable, ask. The wizard lists the settings it can change on this Booking activity, with their current values, so you can pick what to change.

Example requests:

- "What can I configure on this Booking activity?"
- "Which settings does this activity have?"
- "What options can I change here?"
- "Can participants cancel their own bookings here?"
- "Show me the settings for this Booking activity."

The wizard replies with the available settings. Use that list to phrase your next request in the right words.

The wizard can change these settings: the activity's name and description, the organizer name, the event type, the maximum number of bookings per person, whether participants may change or cancel their own booking, the confirmation e-mails (to participants and to the person who books), the reminder e-mails to participants and to teachers (days before the start), the booking policy, the poll links for participants and teachers, the number of options per page, the duration and points, whether the activity is shown in the API, the default view, and the template switcher. Anything else — for example a cancellation deadline, or default values for new options — is set in the activity's settings form in Moodle, or on each option.

## Changing a setting

Tell the wizard what you want the activity to do. It works out which setting that maps to, shows you the change, and asks you to confirm.

Example requests:

- "Allow participants to cancel their own booking."
- "Turn off self-cancellation for this activity."
- "Set the maximum number of bookings per user to 2."
- "Send reminder e-mails to participants 3 days before the start."
- "Let participants change their booking after it is confirmed."
- "Rename the activity to Spring Wellness Bookings."

> **You:** Allow participants to cancel their own booking.
>
> **Wizard:** Here's the change to *Spring Wellness Bookings*:
> - Allow cancellation: **no → yes**
>
> Apply this?
>
> **You:** Yes.
>
> **Wizard:** Done — participants can now cancel their own booking.

## You always see the change first

Because instance settings affect the whole activity, the wizard never changes one silently. It shows you the setting and its new value (next to the old one where that helps), and waits for your yes. You can answer "no" or rephrase, and nothing is saved. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Which activity it changes

The wizard changes the Booking activity you're currently in. If you opened it from inside an activity, it already knows which one. If you opened it elsewhere, name the activity or course — "configure the Spring Wellness booking activity" — or see [choosing the right item](choosing-the-right-item.md) to point it at the right one.

## Permissions

The wizard acts with **your** Moodle permissions. If you're allowed to edit this Booking activity's settings, it can make the change for you. If you're not, it tells you instead of changing anything.

## Tips

- Not sure of the exact setting name? Ask "what can I configure here?" first, then phrase your change from the list.
- You can describe the outcome you want ("let people cancel their booking") rather than the exact field name — the wizard maps it for you and shows you what it'll set.
- To change notifications and automated emails, that's a different area — see [booking rules](booking-rules.md).

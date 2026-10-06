# Configuring the Booking activity

The assistant can change the settings of the Booking activity itself — the instance settings that control how the whole activity behaves, such as cancellation rules and default values. This page shows you how to ask what can be configured and how to change it.

Changing an activity setting is a change, so the assistant shows you exactly what it will set before applying it, and waits for your confirmation. Nothing is saved until you say yes.

## Asking what can be configured

If you're not sure what's adjustable, ask. The assistant lists the settings the Booking activity has so you can pick what to change.

Example requests:

- "What can I configure on this Booking activity?"
- "Which settings does this activity have?"
- "What options can I change here?"
- "Can I set a cancellation deadline on this activity?"
- "Show me the settings for this Booking activity."

The assistant replies with the available settings. Use that list to phrase your next request in the right words.

## Changing a setting

Tell the assistant what you want the activity to do. It works out which setting that maps to, shows you the change, and asks you to confirm.

Example requests:

- "Allow cancellation up to 2 days before the start."
- "Turn off self-cancellation for this activity."
- "Set the default place to Studio B for new options."
- "Change the default maximum number of participants to 20."
- "Let people book more than one option here."
- "Set the activity name to Spring Wellness Bookings."

> **You:** Allow cancellation up to 2 days before the start.
>
> **Assistant:** Here's the change to *Spring Wellness Bookings*:
> - Allow users to cancel their booking: **yes**
> - Cancellation possible until: **2 days before the start**
>
> Apply this?
>
> **You:** Yes.
>
> **Assistant:** Done — cancellation is now allowed up to 2 days before the start.

## You always see the change first

Because instance settings affect the whole activity, the assistant never changes one silently. It shows you the setting and its new value (next to the old one where that helps), and waits for your yes. You can answer "no" or rephrase, and nothing is saved. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Setting default values for new options

Some activity settings act as **defaults** for the options you create later — for example a default place, a default price, or a default participant limit. Changing a default doesn't touch options that already exist; it applies to ones you make afterwards.

Example requests:

- "Set the default price for new options to 30."
- "Make 15 the default number of places for new options here."
- "Use 'Main Hall' as the default place for new options."

If you want to change an option that already exists, that's a different request — name the option and the field you want to change, and the assistant edits that option (again, with a preview and confirmation). See [finding options](finding-options.md) for reading an option's fields first.

## Which activity it changes

The assistant changes the Booking activity you're currently in. If you opened it from inside an activity, it already knows which one. If you opened it elsewhere, name the activity or course — "configure the Spring Wellness booking activity" — or see [choosing the right item](choosing-the-right-item.md) to point it at the right one.

## Permissions

The assistant acts with **your** Moodle permissions. If you're allowed to edit this Booking activity's settings, it can make the change for you. If you're not, it tells you instead of changing anything.

## Tips

- Not sure of the exact setting name? Ask "what can I configure here?" first, then phrase your change from the list.
- You can describe the outcome you want ("let people cancel up to 2 days before") rather than the exact field name — the assistant maps it for you and shows you what it'll set.
- To change notifications and automated emails, that's a different area — see [booking rules](booking-rules.md).

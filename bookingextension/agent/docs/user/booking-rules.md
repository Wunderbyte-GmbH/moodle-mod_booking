# Booking rules and notifications

Booking rules are the automated behaviours of a Booking activity — most often notifications, like a reminder email that goes out a day before a session starts. The assistant can create a rule from a ready-made template, update or switch an existing rule on or off, and give you a read-only overview of the rules and notifications you already have.

Creating or changing a rule is a change, so the assistant shows you what it will do and waits for your confirmation. Reading the overview never changes anything, so it answers right away.

## Getting an overview of your rules and notifications

Start by asking what's already in place. This is read-only — the assistant just reads out the current rules and the notifications they send.

Example requests:

- "What booking rules are set up here?"
- "Show me the notifications for this activity."
- "Which reminder emails go out, and when?"
- "Is there a rule that emails people before a session?"
- "List the active rules on this Booking activity."

The assistant replies with the rules it finds, whether each is active, and what notification each one sends. Use this overview to decide what to add or change next.

## Creating a rule from a template

You don't build a rule from scratch — you ask for the kind of rule you want, and the assistant creates it from a matching template, then fills in the details you gave.

Example requests:

- "Create a rule that sends a reminder email one day before the session."
- "Add a notification that emails participants two hours before the start."
- "Set up a confirmation email when someone books."
- "Create a reminder that goes out a week before each option starts."

> **You:** Create a rule that sends a reminder email one day before the session.
>
> **Assistant:** I'll create a rule on *Spring Wellness Bookings*:
> - Type: reminder notification
> - Sends: 1 day before the option starts
> - To: booked participants
>
> Shall I create it?
>
> **You:** Yes.
>
> **Assistant:** Done — the reminder rule is set up and active.

If the kind of rule you describe doesn't match an available template, the assistant tells you which kinds it can create so you can pick one.

## Updating an existing rule

You can change a rule that already exists — for example its timing or its message. Name the rule the way it appears in the overview, then say what you want changed.

Example requests:

- "Change the reminder to go out two days before instead of one."
- "Update the confirmation email's subject line."
- "Make the reminder rule send to teachers as well."
- "Change the 'one day before' rule to send three hours before."

As with creating, the assistant shows you the change before applying it and waits for your yes.

## Activating and deactivating a rule

You can switch a rule on or off without deleting it. A deactivated rule stops sending until you turn it back on.

Example requests:

- "Deactivate the one-day-before reminder."
- "Turn off the confirmation email for now."
- "Activate the reminder rule again."
- "Switch the week-before notification back on."

> **You:** Deactivate the one-day-before reminder.
>
> **Assistant:** I'll deactivate the rule *Reminder — 1 day before* on Spring Wellness Bookings. Confirm?
>
> **You:** Yes.
>
> **Assistant:** Done — that reminder is now off. Ask me to activate it again whenever you like.

## You always see the change first

Creating, updating, activating and deactivating all change how the activity behaves, so the assistant never does them silently. It shows you the rule and the change, and waits for your confirmation. You can answer "no" or rephrase, and nothing is saved. See [confirmations and safety](confirmations-and-safety.md).

## Which activity it works on

The assistant works on the Booking activity you're currently in. If you opened it elsewhere, name the activity or course, or see [choosing the right item](choosing-the-right-item.md).

## Permissions

The assistant acts with **your** Moodle permissions. If you're allowed to manage rules on this activity, it can create, update and switch them for you. If you're not, it can still give you the read-only overview where your account allows, and it tells you when a change isn't permitted.

## Tips

- Ask for the overview first ("what rules are set up here?"), then refer to a rule by name when you want to change it.
- Describe the outcome — "remind people a day before" — rather than worrying about exact template names; the assistant picks the matching template.
- If a reminder didn't reach someone, that's a diagnosis question, not a rule change — ask "why didn't this student get the reminder?" See [diagnosing bookings](diagnosing-bookings.md).

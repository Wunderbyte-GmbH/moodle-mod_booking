# Calendar events, messages and preferences

The assistant can create, update and delete calendar events, send a message to a user, and set a user preference for you. These are **manager-level actions** — you need the right to do them in Moodle. If you don't have those rights, the assistant tells you instead of making the change.

Because each of these **changes something** — a calendar entry, a message that gets delivered, or a saved setting — the assistant shows you what it's about to do and waits for your explicit confirmation first. Nothing is sent or saved until you say yes. See [confirmations and safety](confirmations-and-safety.md) for how previews work, and [privacy](privacy.md) for how personal data is handled.

## Creating a calendar event

Tell the assistant what the event is, when it happens, and where it should appear.

Example requests:

- "Create a calendar event 'Staff meeting' on 5 March at 10:00."
- "Add an event to this course's calendar: First Aid refresher, 12 March 14:00–16:00."
- "Put 'Registration closes' on the calendar for next Friday."
- "Schedule a site event 'Maintenance window' tonight from 22:00."

The assistant shows you a preview of the event before creating it.

> **You:** Create a calendar event "Staff meeting" on 5 March at 10:00.
>
> **Assistant:** I'll create this calendar event:
> - **Title:** Staff meeting
> - **When:** 5 March, 10:00
> - **Where it appears:** Spring Wellness (course calendar)
>
> Shall I go ahead?
>
> **You:** Yes.
>
> **Assistant:** Done — "Staff meeting" is on the calendar for 5 March at 10:00.

## Updating a calendar event

You can change an event you've already got — its time, title or details. This is a change too, so it's previewed and confirmed.

Example requests:

- "Move the Staff meeting to 6 March at 11:00."
- "Rename 'First Aid refresher' to 'First Aid update'."
- "Change the time of the registration deadline event to 17:00."

If it isn't clear which event you mean, the assistant asks you which one before changing anything.

## Deleting a calendar event

You can remove an event. The assistant is clear about what it's about to delete and waits for your confirmation.

Example requests:

- "Delete the Staff meeting event."
- "Remove the 'Maintenance window' event from the calendar."
- "Cancel the First Aid refresher on 12 March."

> **You:** Delete the Staff meeting event.
>
> **Assistant:** I'll delete the calendar event **Staff meeting** (5 March, 10:00). This can't be undone from here.
>
> Shall I go ahead?

## Sending a message to a user

The assistant can send a Moodle message to a person on your behalf. Because the message actually gets delivered, it shows you the recipient and the text first and waits for your yes.

Example requests:

- "Send Maria Huber a message: 'Your place on First Aid Basics is confirmed.'"
- "Message Tom Berger to remind him the deadline is Friday."
- "Tell Sarah Klein that the room has changed to Studio B."

> **You:** Send Maria Huber a message: "Your place is confirmed."
>
> **Assistant:** I'll send this message:
> - **To:** Maria Huber
> - **Message:** "Your place is confirmed."
>
> Shall I send it?
>
> **You:** Yes.
>
> **Assistant:** Sent — Maria Huber has been messaged.

To find the right recipient first, see [finding people](finding-people.md), then refer back: "message that user."

## Setting a user preference

The assistant can set a user preference — a saved setting for an account. This is a change, so it's previewed and confirmed.

Example requests:

- "Set Maria's email digest preference to daily."
- "Change Tom Berger's forum tracking preference on."
- "Update my own time-zone preference."

The assistant shows you the preference and its new value before saving it. Because preferences belong to a person's account, you can only set them where your permissions allow.

## Permissions

The assistant always acts with **your** Moodle permissions:

- Creating, updating and deleting calendar events needs the right to manage that calendar (course or site).
- Sending a message needs to be allowed for your account and the recipient.
- Setting another person's preference needs the right to manage their account.

If you're allowed to do these things, the assistant does them for you after you confirm. If you're not, it tells you plainly instead of making the change.

## Tips

- For a change you didn't mean, just answer "no" or rephrase — nothing is sent or saved until you confirm.
- Name the date and time the way you'd say them out loud; the assistant works out the exact calendar entry and shows it back to you in the preview.
- If an event or recipient is ambiguous, the assistant asks which one you mean before doing anything.
- For read-only questions about people and courses instead of changes, see [reports and lookups](reports-and-lookups.md).

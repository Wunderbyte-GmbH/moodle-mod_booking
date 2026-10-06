# Diagnosing booking problems

When a booking doesn't behave the way someone expects, you can ask the assistant to work out what's going on. It looks at the booking option, the person, and their booking record, and explains the situation in plain words — for example why someone can't book a session, why they can't cancel, or the full booking status of one person.

All of this is **read-only**: the assistant investigates and reports, but it never changes a booking while diagnosing. So it answers right away, with no confirmation step. It only ever uses data **you're allowed to see** — see [privacy](privacy.md).

## Why can't this person book?

When someone can't sign up for a session and you're not sure why, ask the assistant to check. It looks at the booking option and the person's situation and tells you what's blocking them — for example the option is full, booking has closed, they're already booked, there's a waiting list, or a condition isn't met.

Example requests:

- "Why can't Maria book the Tuesday yoga session?"
- "Why can't this student sign up for the First Aid course?"
- "Tom says he can't book the Friday workshop — what's stopping him?"
- "Is the Tuesday session full, or is there another reason Maria can't join?"
- "Why is the 'Beginner Swimming' option not bookable for Jonas Weber?"

The assistant names the option and the person so you're sure it checked the right ones, then explains the reason.

> **You:** Why can't Maria book the Tuesday yoga session?
>
> **Assistant:** Maria Huber can't book **Tuesday Yoga (10:00)** because the option is full — all 12 places are taken and there's no waiting list. She isn't currently booked on it.

If your request could match more than one option or person, the assistant asks you to be more specific. See [choosing the right item](choosing-the-right-item.md) and [finding people](finding-people.md).

## Why can't this person cancel?

Cancelling can be blocked for several reasons — the cancellation deadline has passed, cancelling is switched off for that option, or the person isn't actually booked. Ask the assistant to work out which it is.

Example requests:

- "Why can't Tom cancel his booking on the Friday workshop?"
- "Maria wants to cancel the yoga session but the button's greyed out — why?"
- "Can this student still cancel the First Aid course, or is it too late?"
- "Why won't the system let Jonas cancel his place?"

> **You:** Why can't Tom cancel his booking on the Friday workshop?
>
> **Assistant:** Tom Berger can't cancel **Friday Workshop** because the cancellation deadline passed two days ago (it was set to 24 hours before the start). His booking is still active.

## A full booking status for one person

You can ask for a complete picture of where one person stands with a booking option: when they booked, whether they completed the process, any earlier or cancelled bookings, what they submitted, and which messages they were sent. This is the report to reach for when you need the whole story rather than a single fact.

Example requests:

- "What's Maria's booking status for the Tuesday yoga session?"
- "Give me a full booking report for Tom on the First Aid course."
- "When did Maria book, did she complete it, and has she cancelled anything before?"
- "Show me everything about Jonas Weber's booking on the Friday workshop — including what he submitted and the messages he got."
- "Has this student booked the swimming course before? When, and did it go through?"

The assistant pulls together what it can see for that person and option, which may include:

- **When they booked** and whether the booking is active, on a waiting list, completed, or cancelled.
- **Earlier or cancelled bookings** for the same option, if any.
- **What they submitted** — the details they entered when booking, where those exist.
- **Which messages they were sent** — for example a booking confirmation or a reminder.

> **You:** What's Maria's booking status for the Tuesday yoga session, with the full history?
>
> **Assistant:** Here's where Maria Huber stands on **Tuesday Yoga (10:00)**:
> - **Booked:** 14 May, 09:12 — booking is active and confirmed.
> - **Completed:** yes, the booking went through fully.
> - **Earlier bookings:** she booked this option once before (2 April) and cancelled it on 5 April.
> - **Submitted:** dietary note "no nuts", emergency contact provided.
> - **Messages sent:** booking confirmation (14 May), reminder (20 May).

If part of this is information your account isn't allowed to see, the assistant leaves that part out and tells you, rather than revealing it.

## What you'll need to give it

To diagnose a booking, the assistant needs to know **who** and **which option**. You can give the person by name, email or username, and the option by its name. If either is ambiguous, it asks a short follow-up question.

Example requests that include both:

- "Why can't maria.huber@example.com book 'Beginner Swimming'?"
- "Full booking report for username jweber on the 'First Aid Programme'."

## What it won't do here

Diagnosing is investigation only. The assistant won't book, cancel, or change anyone's place as part of a diagnosis — it just explains. When you want to act on what you've learned, that's a separate request, and any change is previewed and confirmed first. See [confirmations and safety](confirmations-and-safety.md).

## Related pages

- [Diagnosing access and progress](diagnosing-access-and-progress.md) — why a user can't see or do an activity, notification problems, grades, and course progress.
- [Finding people](finding-people.md) — looking someone up by name, email or username.
- [Choosing the right item](choosing-the-right-item.md) — naming an option clearly when several could match.
- [Privacy](privacy.md) — the assistant only shows data you're allowed to see.

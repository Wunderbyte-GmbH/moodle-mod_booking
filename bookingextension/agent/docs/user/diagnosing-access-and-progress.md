# Diagnosing access and progress

When a user can't see or do something in a course, isn't getting the emails they should, or you're trying to understand their grades or how far they've got, you can ask the assistant to investigate. It checks the relevant settings and the person's situation and explains, in plain words, what's going on.

All of this is **read-only**: the assistant looks and reports, but changes nothing. So it answers right away, with no confirmation step. It only ever uses data **you're allowed to see** — see [privacy](privacy.md).

## Why can't this user see or do an activity?

When someone can't open an activity, or can't take part in it, ask the assistant why. It looks at permissions and access — for example whether the person is enrolled, whether their role grants access, whether the activity is hidden or restricted, or whether an access condition isn't met — and tells you what's blocking them.

Example requests:

- "Why can't Maria see the First Aid quiz?"
- "Why can't this student open the Week 3 assignment?"
- "Tom says the booking activity is greyed out for him — why?"
- "Why isn't Jonas able to submit the workshop task?"
- "Is this user even enrolled in the course? They say they can't see anything."

The assistant names the user and the activity so you know it checked the right ones, then explains the reason.

> **You:** Why can't Maria see the First Aid quiz?
>
> **Assistant:** Maria Huber can't see the **First Aid quiz** because it's restricted to the group "Cohort B", and she isn't a member of that group. She is enrolled in the course and her role would otherwise allow access.

If your request could match more than one user or activity, the assistant asks you to be more specific. See [finding people](finding-people.md) and [choosing the right item](choosing-the-right-item.md).

## Why isn't this user getting notifications?

When someone says they aren't receiving emails or messages they expect — a booking confirmation, a reminder, a course announcement — ask the assistant to look into it. It checks the relevant notification settings and the user's situation and explains why the messages may not be arriving.

Example requests:

- "Why isn't Tom getting booking confirmation emails?"
- "Maria says she never gets reminders — can you find out why?"
- "Why didn't this student receive the cancellation notice?"
- "Is something stopping Jonas from getting course announcements?"

> **You:** Why isn't Tom getting booking confirmation emails?
>
> **Assistant:** Tom Berger isn't receiving booking confirmations because his notification preference for this type of message is switched off in his profile. The booking option itself is set to send confirmations.

## A user's grades

You can ask about a person's grades in a course — what they've scored, and how that stands overall — as far as your account is allowed to see.

Example requests:

- "What are Maria's grades in the First Aid course?"
- "Show me Tom's grade on the Week 3 assignment."
- "How is Jonas doing grade-wise in this course?"
- "Has this student been graded on the final quiz yet?"

> **You:** What are Maria's grades in the First Aid course?
>
> **Assistant:** Maria Huber's grades in **First Aid Programme**:
> - **Week 1 quiz** — 18 / 20
> - **Practical assessment** — passed
> - **Final quiz** — not attempted yet
> - **Course total** — 82%

## A user's progress and completion

You can ask how far someone has got in a course: which activities are done, which aren't, and why something isn't counted as complete. This is useful when a learner thinks they've finished but the course doesn't agree.

Example requests:

- "How far has Maria got in the First Aid course?"
- "Which activities has Tom completed, and which are still open?"
- "Why isn't the Week 3 assignment showing as complete for Jonas?"
- "Show me this student's completion across the course."
- "What's left for Maria to finish before the course counts as complete?"

The assistant lists the activities and their completion state, and where something isn't complete it explains what's missing — for example a required activity not yet done, or a passing grade not yet reached.

> **You:** Why isn't the First Aid course showing as complete for Maria?
>
> **Assistant:** Maria Huber has completed 4 of the 5 required activities in **First Aid Programme**. The course isn't marked complete because the **Final quiz** still needs a passing grade — she hasn't attempted it yet. Everything else is done.

## What you'll need to give it

For these checks the assistant needs to know **who**, and usually **which course or activity**. Give the person by name, email or username, and name the activity or course where it applies. If anything is ambiguous, it asks a short follow-up.

## What it won't do here

These are investigations only. The assistant won't enrol the user, change their permissions, adjust a grade, or alter notification settings as part of a diagnosis — it just explains. When you want to act on what you've learned, that's a separate request, and any change is previewed and confirmed first. See [confirmations and safety](confirmations-and-safety.md).

## Related pages

- [Diagnosing booking problems](diagnosing-bookings.md) — why a person can't book or cancel, and a full per-person booking status.
- [Finding people](finding-people.md) — looking someone up by name, email or username.
- [Choosing the right item](choosing-the-right-item.md) — naming a course or activity clearly when several could match.
- [Privacy](privacy.md) — the assistant only shows data you're allowed to see.

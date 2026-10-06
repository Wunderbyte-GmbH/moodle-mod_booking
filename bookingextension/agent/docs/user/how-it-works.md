# How the Booking Assistant works

The Booking Assistant turns plain language into action inside Moodle. You don't fill in forms or learn menus — you describe what you want, and it works out the rest. This page explains the mental model so you know what to expect.

## You write naturally

There is no special syntax. You type a request the way you'd say it out loud, and the assistant understands free text. All of these are fine:

- "Make a new booking option for the Saturday hike, 8am, meet at the trailhead."
- "Bump the price of the pottery class to 30 and change the trainer to Lena."
- "Who's signed up for next week's session?"
- "Set up the standard reminder rules on this Booking activity."

You can be terse or wordy, use full sentences or short phrases, and write in any language (see [languages](languages.md)). The assistant reads the whole message and figures out your intent.

## It figures out what to do

Behind the chat, the assistant decides which action fits your request and which item you mean — a booking option, a course, an activity, a user, and so on. It uses the context you're in: if you opened the chat inside a course or a Booking activity, it assumes you mean that one unless you say otherwise.

If your request could match several things — say there are three options with similar names — it doesn't guess. It asks you to pick the right one. The same goes for missing details: if it needs a date, a price, or which course you mean, it asks. See [choosing the right item](choosing-the-right-item.md).

## Read-only vs. change — what it means for you

Every request falls into one of two kinds, and the assistant treats them differently:

- **Read-only (a question).** You're asking to see or understand something — "list the options here", "show this user's grades", "why can't this student book?". Nothing is altered, so the assistant just answers immediately.
- **A change.** You're asking it to create, edit, book, enrol, configure, or send something. Before it touches anything, it shows you a **preview** of exactly what it will do and waits for your **confirmation**. Nothing is saved until you say yes.

This is the most important thing to understand: **read-only questions are answered straight away; changes always pause for your OK.** You stay in control of everything that modifies your courses. See [confirmations and safety](confirmations-and-safety.md) for the details.

## It shows a preview, then acts

For a change, a typical flow looks like this:

> **You:** Create a booking option called Night Hike on 12 April at 20:00, place is North Gate.
>
> **Assistant:** I'll create *Night Hike*, 12 April 20:00, place North Gate, in this activity. Go ahead?
>
> **You:** Yes.
>
> **Assistant:** Done.

The preview names the exact item and the exact changes, including which course or activity it lands in. Read it before you confirm. If it's not right, say "no" or just describe the correction, and the assistant tries again — nothing was saved.

## What it can help with

In plain terms, the assistant can:

- **Booking options:** create and edit them (standard, self-paced, and slot/appointment types); set dates, places, prices, the trainer, descriptions, and a header image; update many options at once; book or enrol people; search options and read their details.
- **Booking setup:** configure a Booking activity and set up booking rules from ready-made templates.
- **Courses:** build out a course — add a page, link, label, folder, forum, a Booking activity, or a quiz; and generate quiz questions from a PDF.
- **People and groups:** find users and courses; manage groups, enrolments and calendar events, and send messages (if you have manager-level permissions).
- **Reports and lookups:** look up profiles, roles, grades, completion, and enrolment reports.
- **Diagnosis:** explain why something isn't working — "why can't this student book / cancel / see this?", and problems with permissions, notifications, or grades.
- **Help and memory:** answer "how do I…" questions from its own documentation, and remember your preferences and your earlier conversation so you can refer back.

It always acts with **your** Moodle permissions, so anything you can't do yourself, it can't do for you. Some abilities (like sending messages or managing enrolments) need higher permissions — where that's the case, it applies only "if you're allowed to".

There is **no** action to delete a booking option, and **no** action to cancel a person's booking. Those aren't things the assistant does.

## In short

You write naturally → it understands → it works out the right action and item, asking you to pick or to fill in gaps when needed → for anything that changes data, it previews and waits for your yes → then it acts. Questions are answered on the spot. Nothing happens without you.

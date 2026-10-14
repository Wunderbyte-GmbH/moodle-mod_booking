# How the Booking Wizard works

The Booking Wizard turns plain language into action inside Moodle. You don't fill in forms or learn menus — you describe what you want, and it works out the rest. This page explains the mental model so you know what to expect.

## You write naturally

There is no special syntax. You type a request the way you'd say it out loud, and the wizard understands free text. All of these are fine:

- "Add a forum called Q&A to this course."
- "Which activities has Tom completed here?"
- "Make a new booking option for the Saturday hike, 8am, meet at the trailhead." *(requires mod_booking)*
- "Bump the price of the pottery class to 30 and change the trainer to Lena." *(requires mod_booking)*

You can be terse or wordy, use full sentences or short phrases, and write in any language (see [languages](languages.md)). The wizard reads the whole message and figures out your intent.

## It figures out what to do

Behind the chat, the wizard decides which action fits your request and which item you mean — a booking option, a course, an activity, a user, and so on. It uses the context you're in: if you opened the chat inside a course or a Booking activity, it assumes you mean that one unless you say otherwise.

If your request could match several things — say there are three options with similar names — it doesn't guess. It asks you to pick the right one. The same goes for missing details: if it needs a date, a price, or which course you mean, it asks. See [choosing the right item](choosing-the-right-item.md).

## Read-only vs. change — what it means for you

Every request falls into one of two kinds, and the wizard treats them differently:

- **Read-only (a question).** You're asking to see or understand something — "list the options here", "show this user's grades", "why can't this student book?". Nothing is altered, so the wizard just answers immediately.
- **A change.** You're asking it to create, edit, book, enrol, configure, or schedule something. Before it touches anything, it shows you a **preview** of exactly what it will do and waits for your **confirmation**. Nothing is saved until you say yes.

This is the most important thing to understand: **read-only questions are answered straight away; changes pause for your OK.** (Two exceptions — saving a preference you asked it to remember, and an optional confirm-for-a-few-minutes button some sites offer — are explained there.) You stay in control of everything that modifies your courses. See [confirmations and safety](confirmations-and-safety.md) for the details.

## It shows a preview, then acts

For a change, a typical flow looks like this:

> **You:** Create a booking option called Night Hike on 12 April at 20:00, place is North Gate.
>
> **Wizard:** I'll create *Night Hike*, 12 April 20:00, place North Gate, in this activity. Go ahead?
>
> **You:** Yes.
>
> **Wizard:** Done.

The preview names the exact item and the exact changes, including which course or activity it lands in. Read it before you confirm. If it's not right, say "no" or just describe the correction, and the wizard tries again — nothing was saved.

## What it can help with

In plain terms, the wizard can:

- **Courses:** create a course, or fill one with content about a topic in one go; add a page, link, label, book, folder or forum, and rename, hide, show or move activities; add and change quizzes; generate quiz questions from text, a PDF or the course PDFs.
- **People:** find users and courses, look up a person's profile, roles and courses, and enrol one person in a course.
- **Look-ups for one person:** access, enrolment, progress and grades in a course.
- **Reports:** find, create, change, share and schedule custom reports in the Report Builder (managers by default).
- **Diagnosis:** explain why something isn't working — "why can't this student see this?", and problems with permissions, notifications, or grades.
- **Search:** find content across the site by topic (if your administrator has switched it on).
- **Help and memory:** answer "how do I…" questions from its documentation, and remember your preferences and your earlier conversation so you can refer back.

With the Booking plugin (mod_booking) it can also:

- **Booking options:** create and edit them (standard, self-paced, and slot/appointment types); set dates, places, prices, the trainer, descriptions, a header image and entry tickets; update many options at once; book people into an option; search options and read their details.
- **Booking setup:** change the settings of a Booking activity, set up booking rules from ready-made templates, and manage the site's booking option fields.
- **Booking diagnosis:** "why can't this person book / cancel?", a person's full booking status, and why a waiting list did or didn't move.

It always acts with **your** Moodle permissions, so anything you can't do yourself, it can't do for you. Some abilities (like enrolling people, creating courses or working with reports) need particular rights — where that's the case, it applies only "if you're allowed to". Tasks that change data are also off until your administrator enables them.

There is **no** action to delete a booking option, **no** action to cancel a person's booking, and **no** action to unenrol someone or manage groups. Those aren't things the wizard does.

## In short

You write naturally → it understands → it works out the right action and item, asking you to pick or to fill in gaps when needed → for anything that changes data, it previews and waits for your yes → then it acts. Questions are answered on the spot.

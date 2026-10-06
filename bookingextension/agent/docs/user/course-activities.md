# Adding activities to a course

The assistant can add an activity to a course for you when you ask in plain language. It can add a **page**, a **link (URL)**, a **label**, a **folder**, a **forum**, or a **Booking activity**. You describe what you want and where it should go, and the assistant adds it.

Adding an activity is a change, so the assistant shows you exactly what it will create — the type, the name, and the section it goes in — and waits for your confirmation. Nothing is added to the course until you say yes.

## What you can add

You can ask for any of these:

- A **page** — a single content page inside the course.
- A **link (URL)** — a link out to a web address or document.
- A **label** — a piece of text or a heading shown directly on the course page.
- A **folder** — a place to group files together.
- A **forum** — a discussion area for the course.
- A **Booking activity** — a new booking activity in the course.

## Adding a page

Tell the assistant the name of the page and, if you like, which section it belongs in.

Example requests:

- "Add a page called Course info to the Materials section."
- "Create a page named Welcome at the top of the course."
- "Add a page called Reading list to week 2."
- "Make a new page titled Assessment overview."

## Adding a link or URL

Give the assistant the link text and the web address.

Example requests:

- "Add a link to the handbook."
- "Add a URL called Lecture recording pointing to https://example.com/video."
- "Put a link to the library catalogue in the Resources section."
- "Add a link named Syllabus that goes to our shared drive document."

If you have the address as a file or in an attachment, you can mention it — see [attachments](attachments.md).

## Adding a label

A label shows text or a heading straight on the course page. Tell the assistant the text you want.

Example requests:

- "Add a label that says Week 1: Getting started."
- "Put a heading called Resources above the Materials section."
- "Add a label with the text Office hours: Tuesdays 14:00–16:00."

## Adding a folder

Example requests:

- "Add a folder called Slides to the Materials section."
- "Create a folder named Handouts in week 3."
- "Add a folder for the lecture notes."

## Adding a forum

Example requests:

- "Add a forum called Q&A to this course."
- "Create a discussion forum named Announcements at the top."
- "Add a forum for student introductions in the General section."

## Adding a Booking activity

Example requests:

- "Add a Booking activity called Spring workshops."
- "Create a new booking activity named Lab sessions in week 1."
- "Add a Booking activity for the swimming courses."

Once the Booking activity exists, you can configure it and add booking options to it — see [configuring the Booking activity](booking-activity-setup.md).

## Choosing the section

You can tell the assistant which section the new activity should go in — by name ("the Materials section"), by week or topic number ("week 2", "topic 3"), or by position ("at the top of the course"). If you don't say, the assistant asks or places it sensibly and shows you where, so you can confirm or move it.

Example requests:

- "Add a page called Glossary — put it in the General section."
- "Add a folder called Slides to topic 4."
- "Create a label at the very top of the course."

## You always see it before it's added

> **You:** Add a page called Course info to the Materials section.
>
> **Assistant:** Here's what I'll add to *Introduction to Photography*:
> - Type: **Page**
> - Name: **Course info**
> - Section: **Materials**
>
> Add this?
>
> **You:** Yes.
>
> **Assistant:** Done — the page *Course info* is now in the Materials section.

You can answer "no" or rephrase, and nothing is added. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Which course it adds to

The assistant adds the activity to the course you're currently in. If it isn't clear which course you mean, name it — "add a page to the Photography course" — and the assistant uses that one.

## Permissions

The assistant acts with **your** Moodle permissions. If you're allowed to add activities to this course, it can add them for you. If you're not, it tells you instead of changing anything.

## Tips

- You can describe what you want without knowing Moodle's exact activity names — "a discussion area" gets you a forum, "a page of text" gets you a page.
- Add several things in a row: "add a page called Welcome, then a folder called Slides, both in the General section."
- To see what a course already contains before you add to it, ask for an overview first — see [getting a course overview](course-overview.md).

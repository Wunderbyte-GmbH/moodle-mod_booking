# Adding and changing activities in a course

The wizard can add an activity or resource to a course for you when you ask in plain language. It can add a **page**, a **link (URL)**, a **label**, a **book**, a **folder**, or a **forum**. It can also change an existing activity — rename it, hide or show it, move it, or change its link or page text. For quizzes, see [quizzes and questions](quizzes-and-questions.md).

Adding or changing an activity is a change, so the wizard shows you exactly what it will do — the type, the name, and the section it goes in — and waits for your confirmation. Nothing is added or changed until you say yes.

## What you can add

You can ask for any of these:

- A **page** — a single content page inside the course.
- A **link (URL)** — a link out to a web address or document.
- A **label** — a piece of text or a heading shown directly on the course page.
- A **book** — a multi-page resource with chapters.
- A **folder** — a place to group files together.
- A **forum** — a discussion area for the course.

Only these six types can be added, and only those your role may add in this course and that are enabled on your site. If you don't name a type, the wizard lists the ones you can add here.

## Adding a page

Tell the wizard the name of the page, what it should say, and, if you like, which section it belongs in.

Example requests:

- "Add a page called Course info to the Materials section."
- "Create a page named Welcome at the top of the course that says: Welcome to the course!"
- "Add a page called Reading list to week 2."
- "Make a new page titled Assessment overview."

## Adding a link or URL

Give the wizard the link text and the web address.

Example requests:

- "Add a URL called Lecture recording pointing to https://example.com/video."
- "Put a link to https://library.example.com in the Resources section, named Library catalogue."
- "Add a link named Syllabus that goes to https://example.com/syllabus.pdf."

If you don't give the address, the wizard asks for it.

## Adding a label

A label shows text or a heading straight on the course page. Tell the wizard the text you want.

Example requests:

- "Add a label that says Week 1: Getting started."
- "Put a heading called Resources at the top of the Materials section."
- "Add a label with the text Office hours: Tuesdays 14:00–16:00."

## Adding a book, a folder or a forum

Example requests:

- "Add a book called Lab handbook to week 1."
- "Add a folder called Slides to the Materials section."
- "Create a folder named Handouts in week 3."
- "Add a forum called Q&A to this course."
- "Add a forum for student introductions in the General section."

## Choosing the section

You can tell the wizard which section the new activity should go in — by name ("the Materials section"), by number ("week 2", "section 3"), or by position ("at the top of the course"). The preview shows where it will go, so you can confirm or correct it.

Example requests:

- "Add a page called Glossary — put it in the General section."
- "Add a folder called Slides to section 4."
- "Create a label at the very top of the course."

## You always see it before it's added

> **You:** Add a page called Course info to the Materials section.
>
> **Wizard:** Here's what I'll add to *Introduction to Photography*:
> - Type: **Page**
> - Name: **Course info**
> - Section: **Materials**
>
> Add this?
>
> **You:** Yes.
>
> **Wizard:** Done — the page *Course info* is now in the Materials section.

You can answer "no" or rephrase, and nothing is added. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Changing an existing activity

Name the activity and say what should change. Only what you mention is changed.

Example requests:

- "Rename the page Course info to Course information."
- "Hide the Q&A forum."
- "Change the link of Lecture recording to https://example.com/new-video."
- "Replace the text of the Welcome page with: Welcome, everyone!"
- "Move the Slides folder to section 2."
- "Move the label one section down."
- "Move the Glossary page to the top of its section."

The wizard shows you the change and waits for your yes. Quizzes are changed through their own request — see [quizzes and questions](quizzes-and-questions.md); the settings of a Booking activity through [configuring the Booking activity](booking-activity-setup.md) *(requires mod_booking)*.

## Creating a new course

If you are allowed to create courses (by default only managers), the wizard can create a new, empty course for you. Give it a name and, if you like, the course category; if you don't name a category and more than one is available, it asks which one.

Example requests:

- "Create a course called Introduction to Statistics."
- "Create a course about the Vikings in the category History."

To see which categories exist, ask "Which course categories are there?" — see [course overview](course-overview.md).

## Filling a course with content in one go

For a course that already exists — the one you are in, one you name, or one you just created — the wizard can create a complete set of content about a topic in a single step: a welcome section, several chapter pages (four unless you say otherwise, at most eight) and a closing section, and on request a practice quiz per chapter and a graded final quiz.

Example requests:

- "Fill this course with content about first aid."
- "Create six chapters about the life of the Vikings in the Vikings course, with a practice quiz per chapter and a final quiz."

If you ask for quizzes but don't say how many questions each should have, the wizard asks. As always, you see the plan first and confirm it.

## Which course it works on

The wizard adds to the course you're currently in. If it isn't clear which course you mean, name it — "add a page to the Photography course" — and the wizard uses that one.

## Permissions

The wizard acts with **your** Moodle permissions. If you're allowed to add or edit activities in this course, it can do it for you. If you're not, it tells you instead of changing anything.

## Tips

- You can describe what you want without knowing Moodle's exact activity names — "a discussion area" gets you a forum, "a page of text" gets you a page.
- Add several things in a row: "add a page called Welcome, then a folder called Slides, both in the General section." Each one is previewed and confirmed on its own.
- To see what a course already contains before you add to it, ask for an overview first — see [getting a course overview](course-overview.md).

# Quizzes and quiz questions

The assistant can add a **quiz** to a course, **update** an existing quiz, and **generate quiz questions from a PDF** straight into the course question bank. You ask in plain language; for question generation you attach the PDF and the assistant imports the questions for you.

Adding or changing a quiz, and importing questions, are changes — so the assistant shows you what it will do and waits for your confirmation first. Nothing is saved until you say yes.

## Adding a quiz

Tell the assistant the name of the quiz and, if you like, which section of the course it should go in.

Example requests:

- "Add a quiz called Week 1 review to this course."
- "Create a quiz named Final assessment in the Assessment section."
- "Add a quiz called Practice test at the top of the course."
- "Make a new quiz titled Safety knowledge check in week 3."

The assistant shows you the quiz it will create — the name and the section — and waits for your yes.

## Updating a quiz

Name the quiz and tell the assistant what you'd like to change.

Example requests:

- "Rename the Week 1 review quiz to Week 1 recap."
- "Move the Final assessment quiz to the Assessment section."
- "Change the name of the practice quiz to Mock exam."
- "Update the Safety knowledge check quiz."

As with any change, the assistant shows you what it will set and asks you to confirm before applying it.

## Generating questions from a PDF

You can turn a document into quiz questions. Attach a PDF and tell the assistant how many questions you want and what kind. The assistant reads the PDF, writes the questions, and **imports them into the course question bank** for you, ready to use in a quiz.

To do this, attach the PDF to your request — see [attachments](attachments.md) — and describe what you want.

Example requests:

- "Generate 10 multiple-choice questions from this PDF."
- "Make 5 questions from the attached document for the question bank."
- "Create 8 multiple-choice questions based on this handout."
- "Generate 15 questions from this PDF about the safety procedures."
- "Turn the attached reading into 6 quiz questions."

> **You:** *(attaches lecture-notes.pdf)* Generate 10 multiple-choice questions from this PDF.
>
> **Assistant:** I've drafted 10 multiple-choice questions from *lecture-notes.pdf*. Here's a preview of the first few:
> 1. Which of the following best describes…
> 2. What is the main purpose of…
>
> Import all 10 into the question bank for *Workplace Safety*?
>
> **You:** Yes.
>
> **Assistant:** Done — 10 questions are now in the course question bank.

The questions go into the **course question bank**, so you can review, edit, and add them to any quiz in that course afterwards. The assistant shows you a preview before importing, and you confirm before anything is saved. If you'd like the questions placed in a particular category of the question bank, just say which one — and if it's not clear, the assistant asks.

## Putting it together

A common flow is to add a quiz, generate questions from your material, and then build the quiz from those questions:

- "Add a quiz called Week 2 test."
- "Generate 10 multiple-choice questions from this PDF." *(with the PDF attached)*

The first two steps the assistant can do for you; you can then add the imported questions to your quiz.

## Which course it works in

The quiz is added to, and the questions are imported into, the course you're currently in. If it isn't clear which course you mean, name it — "add a quiz to the Photography course."

## Permissions

The assistant acts with **your** Moodle permissions. If you're allowed to add a quiz and manage the question bank in this course, it can do it for you. If you're not, it tells you instead of changing anything.

## Tips

- Multiple-choice is the most common question type to ask for; say how many you want ("10 multiple-choice questions").
- If a PDF is long, the questions cover the material you point the assistant at — you can say "focus on the chapter about first aid."
- You always get a preview of the questions before they're imported, so you can say "no" or ask for changes first. See [confirmations and safety](confirmations-and-safety.md).
- To see what's already in a course before adding a quiz, ask for an overview — see [getting a course overview](course-overview.md).

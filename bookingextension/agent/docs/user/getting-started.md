# Getting started with the Booking Wizard

The Booking Wizard is an AI helper built into Moodle. You tell it what you want in plain words, and it helps you create and edit booking options, set up courses, find people, answer questions, and diagnose problems — all using your own Moodle permissions.

This page shows you how to open it, what the chat looks like, and the first things to try.

## Opening the wizard

There are two ways to start a conversation.

- **From anywhere — the wand icon (managers).** Managers see a small magic-wand icon in the top navigation bar on every page, if the administrator has it switched on. Click it and a chat panel opens, so you can ask for help wherever you are.
- **From a Booking activity (teachers and managers).** When you are inside a Booking activity, you can open the wizard there. It will already know which Booking activity you are looking at, so you don't have to name it.

To use the wizard at all, your role needs the permission *Use the Booking Wizard* — by default editing teachers and managers have it, non-editing teachers and students don't. The site also needs an active AI provider, and AI must be allowed in the course and the activity. If one of these is missing, the chat panel shows a readiness check that tells you which; see [troubleshooting](troubleshooting.md).

Wherever you open it, the wizard works in the context you are in. If you opened it inside a course or a Booking activity, it assumes you mean that course or activity unless you tell it otherwise. See [choosing the right item](choosing-the-right-item.md) for how to point it somewhere else.

## The chat box

The wizard is a simple chat. There is a message box at the bottom where you type your request, and the conversation appears above it. You write a message, the wizard replies, and you keep going from there.

You don't need any special syntax or commands. Write the way you would explain a task to a colleague. Full sentences, short phrases, follow-up questions — all of it works.

## Sending your first request

Type what you want and send it. For example:

- "What is in this course?"
- "Which courses is Maria Huber enrolled in?"
- "Show me the booking options in this activity." *(requires mod_booking)*
- "Create a booking option called Beginner Yoga on Mondays at 6pm." *(requires mod_booking)*
- "Why can't this student book the Tuesday class?" *(requires mod_booking)*

The wizard reads your message, works out what you mean, and replies. If your request would **change** something, it first shows you a preview and waits for your confirmation before doing anything. If your request is just a **question**, it answers right away. See [how it works](how-it-works.md) and [confirmations and safety](confirmations-and-safety.md) for the full picture.

## What a typical exchange looks like

Here is a normal back-and-forth *(requires mod_booking)*:

> **You:** Create a booking option called Pottery Workshop on 5 March from 10am to 1pm, place is Studio B, price 25.
>
> **Wizard:** Here's what I'll create: *Pottery Workshop*, 5 March 10:00–13:00, place Studio B, price 25.00. Shall I go ahead?
>
> **You:** Yes.
>
> **Wizard:** Done — I've created Pottery Workshop in this activity.

Notice the pattern: you describe it, the wizard shows you a preview, you say yes, and only then does it make the change. You can always say "no", or just rephrase your request, and nothing is saved.

## The first things to try

If you're new, these are good warm-up requests:

- **Look around:** "What is in this course?" or, with mod_booking, "List the booking options here."
- **Make something:** "Add a page called Welcome to the General section" or, with mod_booking, "Create a booking option named Intro Session next Monday at 9am."
- **Find someone:** "Find the user Maria Sanchez" or "Which courses is Maria Sanchez enrolled in?"
- **Ask a how-to question:** "How do I add a header image to a booking option?" The wizard can answer from its documentation, if your administrator has switched that on.
- **Diagnose a problem:** "Why can't Tom see this activity?" or "Why didn't this student get the confirmation email?"

## Writing in your own language

You can write in any language, and the wizard replies in that language. Start a new request in another language whenever you like — it follows your lead. See [languages](languages.md).

## A few things to know

- The wizard always acts with **your** Moodle permissions. It can only do and show what your account is allowed to. If you're not allowed to do something, it won't.
- It **never does more than you asked**. It won't make extra changes or tidy things up on its own.
- It can attach meaning to files you upload — for example an image for a header, or a PDF to read or to turn into quiz questions. It will tell you when it expects a file. See [attachments](attachments.md).
- Your earlier messages stay in the conversation, so you can refer back ("change the one we just made") and it remembers. Depending on your site's privacy setting, names and e-mail addresses are replaced by placeholders before they reach the AI provider — see [privacy](privacy.md).
- What the wizard can do on your site also depends on your administrator: tasks that change data are switched off until they are enabled.

When you're ready, just open the chat and type your first request.

# Troubleshooting

Most of the time the wizard does just what you asked. When it doesn't, it's usually because a request was a little ambiguous, something was missing, or you don't have the rights for it. None of these are dead ends — the wizard tells you what it needs in plain words, and you adjust and try again. Nothing changes unless you've confirmed it, so there's no harm in a request that doesn't land the first time.

This page covers the common situations and what to do about each.

## The wizard doesn't open, or says it isn't ready

Before the wizard can work, a few things must be in place. When something is missing, the chat panel shows a readiness check that names it:

- **An AI provider is configured and active** on the site. That is an administrator task.
- **AI is allowed in this course and in this course module.** This is switched on in the course and activity settings. Managers are not affected by these switches.
- **Your role may use the Booking Wizard.** By default editing teachers and managers may; non-editing teachers and students may not.

The wand icon in the top navigation bar is shown to managers only (and only if the administrator has it switched on). Teachers open the wizard from inside a Booking activity.

## It says it can't do that

Some tasks may simply not be switched on at your site. Tasks that only read are on by default; **tasks that change data are off until an administrator enables them** in the wizard's *Skill governance* page. Answering "how does X work?" questions from the documentation also has to be switched on separately. If the wizard says it has no way to do something you expected, ask your administrator.

If your site restricts the wizard to its supported tasks, it also politely declines requests that have nothing to do with them.

## Your message was rejected as too long

A single message may be at most **2,000 characters** by default (your administrator can change the limit). A longer message is rejected with "Your input is too long". Split the request into shorter messages, or attach a long text as a PDF.

## It picked the wrong option or course

If you asked about "the yoga session" or "the course" and the wizard worked on a different one than you meant, your description matched more than one thing — or matched something other than what you had in mind.

What to do: **name it more specifically.** Add anything that sets it apart — the exact title, the date or time, the course it belongs to, or an ID if you have one.

Example requests:

- "No, I meant the **Tuesday** yoga session, not Thursday."
- "Use the 'First Aid Programme' in the **Spring 2026** course."
- "I meant the option titled 'Beginner Swimming (evening)'."
- "The one starting on 14 May."

When several things match, the wizard usually shows you the candidates and asks you to pick — so you can just choose from its list. See [choosing the right item](choosing-the-right-item.md).

## It asks for a detail you didn't give

If the wizard needs something to carry on — a title, which person, which option, which course — it asks you a short question rather than guessing. This is a good sign: it's making sure it does the right thing.

What to do: **answer the question**, and it continues from where it was. You don't have to start over.

> **You:** Add a link to the Resources section.
>
> **Wizard:** Happy to. What should the link be called, and which web address should it point to?
>
> **You:** Library catalogue, https://library.example.com.

If you'd rather avoid being asked the same thing repeatedly, you can tell the wizard a default to remember — see [remembering preferences](remembering-and-history.md).

## It says you don't have permission

The wizard always acts with **your** Moodle permissions. If a request needs rights your account doesn't have, it tells you plainly instead of doing it — and it won't show you data you're not allowed to see either.

What to do: this isn't an error you can retry your way around. If you believe you should have access, the usual route is to ask whoever manages your courses or your site for the right role. The wizard can still help with anything that *is* within your rights.

Example of what you'll see:

> **Wizard:** I can't do that — your account doesn't have permission to enrol users in this course. If you need that, ask a manager to grant you the right role.

See [privacy](privacy.md) for more on how permissions shape what the wizard can do and show.

## It expected a file you didn't attach

Some requests need a file to work from — for example setting a header image on a booking option. If you ask for one of these but don't attach the file, the wizard tells you it needs it.

What to do: **attach the file and ask again.** Once it's there, the wizard carries on.

Quiz questions don't need a file: they can come from text you type, an attached PDF, or the PDFs already in the course. See [quizzes and questions](quizzes-and-questions.md).

## It couldn't complete something

Sometimes a request can't go through — a value isn't allowed, something it depends on is missing, or a setting conflicts with what you asked. When that happens, the wizard doesn't fail silently or pretend it worked. It tells you, in plain words, **why** it couldn't, so you know what to change.

What to do: read the reason, **adjust your request, and try again.** The fix is usually small — a different value, a missing detail, or a clashing setting to resolve.

Example of what you'll see:

> **Wizard:** I couldn't set the price to a negative number — prices have to be zero or more. Tell me a valid amount and I'll set it.

Because changes are previewed and confirmed before they happen, a request that couldn't complete leaves your data as it was. See [confirmations and safety](confirmations-and-safety.md).

## General tips

- **Be a bit more specific** when something is ambiguous — an exact name, a date, a course, an email.
- **Answer its questions** rather than restarting; it keeps the thread of what you were doing.
- **Read the reason** when it can't do something — it's telling you the one thing to change.
- **Nothing changes without your yes**, so there's no risk in trying a request, seeing what it says, and refining it.
- If you're stuck on how a feature works, just ask — see [getting help](getting-help.md).
- If a long request stops partway, ask for the rest in a new message — see [multi-step requests and limits](multi-step-and-limits.md).

## Related pages

- [Choosing the right item](choosing-the-right-item.md) — naming the option, course or activity you mean.
- [Confirmations and safety](confirmations-and-safety.md) — how changes are previewed and confirmed.
- [Privacy](privacy.md) — how the wizard works within your permissions.
- [Getting help](getting-help.md) — asking how a feature works and what the wizard can do.
- [Remembering preferences](remembering-and-history.md) — defaults the wizard keeps so it asks you less.

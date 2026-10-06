# Troubleshooting

Most of the time the assistant does just what you asked. When it doesn't, it's usually because a request was a little ambiguous, something was missing, or you don't have the rights for it. None of these are dead ends — the assistant tells you what it needs in plain words, and you adjust and try again. Nothing changes unless you've confirmed it, so there's no harm in a request that doesn't land the first time.

This page covers the common situations and what to do about each.

## It picked the wrong option or course

If you asked about "the yoga session" or "the course" and the assistant worked on a different one than you meant, your description matched more than one thing — or matched something other than what you had in mind.

What to do: **name it more specifically.** Add anything that sets it apart — the exact title, the date or time, the course it belongs to, or an ID if you have one.

Example requests:

- "No, I meant the **Tuesday** yoga session, not Thursday."
- "Use the 'First Aid Programme' in the **Spring 2026** course."
- "I meant the option titled 'Beginner Swimming (evening)'."
- "The one starting on 14 May."

When several things match, the assistant usually shows you the candidates and asks you to pick — so you can just choose from its list. See [choosing the right item](choosing-the-right-item.md).

## It asks for a detail you didn't give

If the assistant needs something to carry on — a number of places, a date, which person, which course — it asks you a short question rather than guessing. This is a good sign: it's making sure it does the right thing.

What to do: **answer the question**, and it continues from where it was. You don't have to start over.

> **You:** Create a yoga option.
>
> **Assistant:** Happy to. How many places should it have, and what start date?
>
> **You:** 20 places, starting 14 May at 10:00.

If you'd rather avoid being asked the same thing repeatedly, you can tell the assistant a default to remember — see [remembering preferences](remembering-and-history.md).

## It says you don't have permission

The assistant always acts with **your** Moodle permissions. If a request needs rights your account doesn't have, it tells you plainly instead of doing it — and it won't show you data you're not allowed to see either.

What to do: this isn't an error you can retry your way around. If you believe you should have access, the usual route is to ask whoever manages your courses or your site for the right role. The assistant can still help with anything that *is* within your rights.

Example of what you'll see:

> **Assistant:** I can't do that — your account doesn't have permission to enrol users in this course. If you need that, ask a manager to grant you the right role.

See [privacy](privacy.md) for more on how permissions shape what the assistant can do and show.

## It expected a file you didn't attach

Some requests need a file to work from — for example creating questions from a document. If you ask for one of these but don't attach the file, the assistant tells you it needs it.

What to do: **attach the file and ask again.** Once it's there, the assistant carries on.

> **You:** Make quiz questions from the handout.
>
> **Assistant:** I can do that, but I don't see a file attached. Please add the document and send the request again.

## It couldn't complete something

Sometimes a request can't go through — a value isn't allowed, something it depends on is missing, or a setting conflicts with what you asked. When that happens, the assistant doesn't fail silently or pretend it worked. It tells you, in plain words, **why** it couldn't, so you know what to change.

What to do: read the reason, **adjust your request, and try again.** The fix is usually small — a different value, a missing detail, or a clashing setting to resolve.

Example of what you'll see:

> **Assistant:** I couldn't set the price to a negative number — prices have to be zero or more. Tell me a valid amount and I'll set it.

Because changes are previewed and confirmed before they happen, a request that couldn't complete leaves your data as it was. See [confirmations and safety](confirmations-and-safety.md).

## General tips

- **Be a bit more specific** when something is ambiguous — an exact name, a date, a course, an email.
- **Answer its questions** rather than restarting; it keeps the thread of what you were doing.
- **Read the reason** when it can't do something — it's telling you the one thing to change.
- **Nothing changes without your yes**, so there's no risk in trying a request, seeing what it says, and refining it.
- If you're stuck on how a feature works, just ask — see [getting help](getting-help.md).

## Related pages

- [Choosing the right item](choosing-the-right-item.md) — naming the option, course or activity you mean.
- [Confirmations and safety](confirmations-and-safety.md) — how changes are previewed and confirmed.
- [Privacy](privacy.md) — how the assistant works within your permissions.
- [Getting help](getting-help.md) — asking how a feature works and what the assistant can do.
- [Remembering preferences](remembering-and-history.md) — defaults the assistant keeps so it asks you less.

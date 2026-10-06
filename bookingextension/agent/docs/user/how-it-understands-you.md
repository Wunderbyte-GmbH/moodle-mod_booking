# How the assistant understands you

You don't need to learn any special commands, keywords, or menu paths to use the Booking Assistant. You write the way you'd speak, and it works out what you mean. This page explains how that understanding works — and, just as importantly, what the assistant does when your message could mean more than one thing.

## It understands meaning, not exact words

The assistant reads your message for its **meaning**, not for particular magic words. There's no fixed phrasing you have to get right. All of these get you to the same place:

- "Who's signed up for the Saturday hike?"
- "Show me the participants of the Saturday hike."
- "List people booked on Saturday's hike."

You can be brief or detailed, formal or casual, write full sentences or just a few words. Because it's working from meaning, it copes with the way real people actually write — including small typos and unusual phrasing.

## You can write in any language

You don't have to switch to English to talk to the assistant. Write in the language you're comfortable with, and it understands you and answers in the same language. You can even switch languages mid-conversation. See [languages](languages.md) for more on how this works.

## It decides "what to do" and "with what" as two separate steps

When you ask for something, the assistant deliberately separates two questions:

1. **Which action do you want?** — for example, *edit a booking option* rather than *create one* or *look one up*.
2. **With what exact details?** — for example, *which* option, and the new *price* of 30.

It settles the first question before the second. Keeping them apart matters: it stops the assistant from mixing up what you want to do with the specifics of how to do it. Take "raise the pottery price to 30" — first it recognises this is a change-the-price action, and only then does it pin down that it's the *Pottery Class* option and the new value is *30*. That two-step habit is a big part of why the assistant lands on the right thing so reliably.

## When something's unclear, it asks instead of guessing

The assistant would rather ask a quick question than make a risky assumption. So whenever your request is ambiguous or missing a key detail, it stops and checks with you.

That happens in two common situations:

- **Your words could mean several things.** If you say "edit the hike option" and there are three options with "hike" in the name, it won't pick one at random — it lists them and asks which you mean.

  > **You:** Change the trainer on the hike to Lena.
  >
  > **Assistant:** I found three options with "hike" — *Morning Hike*, *Night Hike*, *Saturday Hike*. Which one?

- **A needed detail is missing.** If you ask to create an option but didn't give a date, or to enrol someone but didn't say in what, it asks for the missing piece rather than filling in a guess.

This is on purpose. Guessing might be faster, but it risks changing the wrong thing. A short clarifying question keeps you in control and avoids mistakes. See [choosing the right item](choosing-the-right-item.md) for more on how it narrows things down.

## It remembers the conversation

The assistant keeps track of what you've already said in the chat, so you don't have to repeat yourself. That means short, natural follow-ups just work:

> **You:** Show me the options in this activity.
>
> **Assistant:** *(lists Morning Hike, Night Hike, Saturday Hike)*
>
> **You:** Edit the second one — move it to 9am.
>
> **Assistant:** I'll update *Night Hike*: start time → 09:00. Confirm?

Replies like "yes", "no", "the second one", "make it 25 instead", or "the Saturday one" all make sense to it, because it remembers what you were just talking about. You can build up a request step by step, the way a normal conversation flows.

## What this means for you

Put together, this is what you can count on:

- **Phrase things your own way**, in your own language — there's nothing to memorise.
- The assistant **pins down the right action and the right details separately**, so it's less likely to mix them up.
- When in doubt, it **asks a short question** rather than guessing wrong.
- It **remembers the conversation**, so brief replies and follow-ups work naturally.

For what happens after it understands you — how it checks, previews, and acts — see [behind the scenes](behind-the-scenes.md). For how it keeps changes safe, see [confirmations and safety](confirmations-and-safety.md).

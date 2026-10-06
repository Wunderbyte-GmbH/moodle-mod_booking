# Behind the scenes: what the assistant does with every message

When you send the Booking Assistant a message, it doesn't just react on impulse. It works through the same careful sequence every single time — for a quick question and for a big change alike. Knowing this sequence helps you understand why the assistant is so steady and predictable: it follows rules rather than guessing, and it never quietly does more than you asked.

Here is the whole journey, in plain steps.

## Step 1: It works out what you want

First the assistant reads your message and figures out what you're trying to achieve. It pays attention to the meaning of what you wrote, not just the words, so you can phrase things however feels natural (see [how it understands you](how-it-understands-you.md)). It also looks at where you are — if you opened the chat inside a course or a Booking activity, it assumes you probably mean that one.

From there it gathers the abilities that could fit your request — the handful of things it knows how to do that are relevant. If you asked "who's signed up?", it pulls together its ways of looking people up; if you asked to change a price, it pulls together its ways of editing an option.

## Step 2: It picks exactly one action

Out of everything it could do, the assistant chooses a single action to take next. Not two, not a bundle — one. This is deliberate. By committing to one clear action at a time, it stays easy to follow and hard to surprise. You always know what it's about to do, because there's only ever one thing on the table.

If your whole request actually needs several actions in a row, that's fine — it handles them one after another (more on that in step 7).

## Step 3: It works out the exact details

Once it knows *which* action, it works out the *details* for that action — the specific values it will use. For a new booking option that might be the title, the date, the time, the place, the price, the trainer. For a lookup it might be which course and which person you mean.

Deciding the action and deciding the details are kept as two separate steps on purpose, so the assistant doesn't muddle "what to do" with "what to do it with." If a needed detail is missing or your message could mean more than one thing, this is where it stops and asks you a short question rather than inventing an answer.

## Step 4: It checks the action is allowed and safe

Before anything happens, the assistant double-checks two things:

- **Are you allowed to do this?** Everything runs under your own Moodle account and your own permissions. If you couldn't do something yourself, the assistant won't do it for you — it'll say so plainly instead.
- **Will it cause a problem?** It looks the action over to make sure it makes sense and won't run into an obvious obstacle. If it spots a reason the action can't go ahead, it tells you what's in the way.

This is the assistant's safety check. It happens for every action, every time.

## Step 5: For a change, it shows a preview and waits

If the action would **change** something — create, edit, book, enrol, configure, send — the assistant pauses and shows you a preview of exactly what it intends to do, then waits for your OK. Nothing is saved until you say yes. Read-only questions skip this step, because there's nothing to change, so they're answered straight away.

This pause is the heart of how the assistant keeps you in control. The full details — how previews look, how to say no or rephrase, what's never done — are on the [confirmations and safety](confirmations-and-safety.md) page.

## Step 6: It carries the action out

After your OK (or immediately, for a read-only question), the assistant performs the action and then confirms what it did. For a change, it doesn't just claim success — it checks that the change really took effect before telling you it's done, so a "Done" actually means done.

## Step 7: If your request needs more steps, it continues

Some requests are really several things in one — for example, "create the Saturday hike option and then enrol Maria in it." When that happens, the assistant doesn't try to do it all at once. It finishes the first action, then loops back to the top of this sequence for the next one: work out what's next, pick one action, work out its details, check it's allowed and safe, preview it if it's a change, carry it out. It keeps going, in order, until your whole request is complete.

See [multi-step requests and limits](multi-step-and-limits.md) for more on how longer requests are handled.

## Why this makes the assistant predictable

Because the assistant runs this same sequence every time, you get a few guarantees:

- It only ever does **one clear action at a time**, so you can always follow along.
- It **never changes anything without showing you first** and waiting for your yes.
- It works **strictly within your permissions** — no shortcuts, no extra powers.
- It **asks instead of guessing** when something is unclear.
- It **does exactly what you asked**, and no more — no surprise tidy-ups or extra edits.

The assistant isn't magic, and it doesn't pretend to be. It's careful, rule-following, and consistent — which is precisely what you want from something that can touch your courses.

## In short

Every message goes through the same path: understand what you want → pick one action → work out its details → check it's allowed and safe → preview any change and wait → carry it out → move on to the next step if needed. Questions come back on the spot; changes always pause for your OK. For the related ideas, see [how it understands you](how-it-understands-you.md) and [confirmations and safety](confirmations-and-safety.md).

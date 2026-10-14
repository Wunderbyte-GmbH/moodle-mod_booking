# Multi-step requests and honest limits

Sometimes one request is really several things at once. The Booking Wizard can handle that — it works through the steps in order. This page explains how it manages longer requests, and it's also honest about what the wizard won't or can't do, so you know what to expect.

## How it handles a request with several steps

You can ask for more than one thing in a single message. For example:

> **You:** Create a booking option called Saturday Hike on 12 April at 9am, then book Maria Klein into it. *(requires mod_booking)*

That's two actions: first create the option, then book someone into it. The wizard doesn't try to do everything in one go. Instead it takes the steps **one at a time, in sensible order**:

1. It works out and previews the first action — creating *Saturday Hike* — and waits for your OK.
2. Once that's done, it moves on to the next action — booking Maria Klein — and, because that's also a change, previews it and waits for your OK again.

So a multi-step request becomes a short, clear sequence. Each change still gets its own preview and its own confirmation; nothing is bundled together and slipped past you. If you'd rather not continue partway through, you simply don't confirm the next step, and the rest stops there.

This step-by-step approach means even a longer request stays easy to follow. You can see exactly where you are and what's coming next, and you keep the same control over every individual change. For the full picture of the sequence behind each step, see [behind the scenes](behind-the-scenes.md).

There is a limit to how long one chain can get: the wizard takes at most **8 steps** in one go, and at most **18** for one message of yours (including the steps after your confirmations). If a request needs more, the wizard stops and tells you; ask for the rest in a new message.

## The honest limits

The wizard is genuinely useful, but it's careful and rule-following rather than all-powerful. It's worth knowing where the edges are.

### It only does what you're allowed to do

Everything runs under your own Moodle account and your own permissions. The wizard can't do anything you couldn't do yourself. If a particular action needs higher-level rights that you don't have, it won't attempt it — it tells you plainly that you're not allowed, rather than failing in a confusing way. Some abilities (like enrolling people, creating courses or working with reports) simply need the right permissions to work. Tasks that change data are also off until your administrator enables them.

### It asks when a detail is missing or unclear

If your request is missing something it needs, or could be read more than one way, the wizard asks a short question instead of guessing. This is a feature, not a stumble — it's how it avoids changing the wrong thing. See [how it understands you](how-it-understands-you.md) for more on this.

### Messages have a maximum length

One message may be at most **2,000 characters** by default; your administrator can change this. A longer message is rejected with a short notice. Split long requests into several messages.

If your site restricts the wizard to its supported tasks, it politely declines requests that have nothing to do with them.

### It tells you plainly when it can't do something

If something is outside what the wizard can do, it says so directly. It won't pretend, and it won't make up a result. For safety, a few actions are deliberately off the table — for instance, there's no action to delete a booking option and no action to cancel another person's booking. For those, you'll use Moodle's normal screens.

### On a brief glitch, it retries — but never repeats a real change

Now and then a momentary hiccup happens — a brief connection wobble, a service that needs a second attempt. When that's clearly just a temporary glitch, the wizard quietly tries again on its own, so you usually won't even notice.

But it draws a firm line: it **never silently repeats an actual change**. If a change to your data has already gone through, the wizard won't quietly do it a second time and risk a duplicate. Retrying is only for harmless re-attempts of something that didn't complete — never for re-running a change that already happened. That's a deliberate safeguard, so you never end up with two of something you only asked for once.

## In short

- Multi-step requests are handled **one action at a time, in order**, with each change previewed and confirmed on its own.
- The wizard works **only within your permissions**, **asks** when something's missing or ambiguous, and **tells you honestly** when it can't help.
- It **retries a brief glitch by itself**, but **never silently repeats a real change**.

For how each change is previewed and confirmed, see [confirmations and safety](confirmations-and-safety.md). For how the wizard reads your request in the first place, see [how it understands you](how-it-understands-you.md).

# Confirmations and safety

The Booking Wizard is built so that nothing in your courses changes without your explicit OK. This page explains exactly how that works — including the two exceptions — so you can use it with confidence.

## Questions are answered immediately

If you're just asking to see or understand something, the wizard answers right away. There's nothing to confirm, because nothing is being changed. For example:

- "What is in this course?"
- "Which courses is Maria Huber enrolled in?"
- "Which activities has this student completed here?"
- "List the booking options in this activity." *(requires mod_booking)*
- "Why can't this student book the Tuesday class?" *(requires mod_booking)*

These read-only requests just return an answer. You can ask as many as you like.

## Before any change, it shows a preview

The moment your request would **change** something — create, edit, book, enrol, configure, schedule a report, and so on — the wizard stops and shows you a **preview** first. The preview spells out exactly what it intends to do: the item it will create or edit, the specific values it will set, and which course or activity it lands in.

> **You:** Set the price of the pottery class to 30 and change the trainer to Lena Berg.
>
> **Wizard:** I'll update *Pottery Class* in this activity: price → 30.00, trainer → Lena Berg. Confirm?

Read the preview before you answer. It's your chance to catch a wrong date, the wrong option, or a typo before anything is saved.

## Nothing happens until you confirm

The wizard **waits** for your confirmation. Until you say yes, no change is made — your data is untouched. A simple "yes", "go ahead", or "confirm" lets it proceed. Then it makes the change and tells you it's done.

This applies to changes of every size, including bigger ones like updating many options at once. If it's about to update several items, the preview summarises that too, and it still waits for your OK.

## Two exceptions

- **Saving a preference you asked it to remember** happens immediately. It only affects your own notes, and forgetting a note does ask for confirmation. See [remembering and history](remembering-and-history.md).
- **Confirming for a few minutes at once.** If your site grants you the permission *Suspend agent action confirmations for the current session* (by default only administrators have it), the confirmation offers a second button, *Confirm & execute for the next … minutes*. If you use it, later changes in that activity run without a separate confirmation until the time is up. You still see what was done.

## You can always say no or rephrase

If the preview isn't right, you have two easy options:

- **Say no.** "No", "cancel", "stop" — the wizard drops the change and nothing is saved.
- **Rephrase.** Just describe the correction: "no, make it 25, not 30" or "I meant the Saturday class, not Friday." The wizard adjusts and shows you a fresh preview. You confirm that one instead.

You're never locked in. As long as you haven't confirmed, you can change your mind freely.

## It never does more than you asked

The wizard sticks to your request. It won't make extra edits, won't "tidy up" related items, and won't take initiative beyond what you described. If you ask it to change one field, it changes that field — not others. If you want more done, you ask for more.

This means the preview is the whole story: what it shows is what it will do, no hidden extras.

## It only acts with your permissions

Everything the wizard does runs under **your** Moodle account and **your** permissions. It can't do anything you couldn't do yourself. If you're not allowed to make a particular change, the wizard won't make it either — it'll tell you instead of trying. Some actions (like enrolling people, creating courses or working with reports) need higher-level permissions; those work only if you're allowed to use them. Tasks that change data are also switched off until your administrator enables them.

## Things it doesn't do

For safety, some destructive actions simply aren't available:

- There is **no** action to delete a booking option.
- There is **no** action to cancel another person's booking.

If you need to do those, you'll handle them through Moodle's normal screens. The wizard won't do them and won't pretend it can.

## Privacy in the saved conversation

Your conversation is saved so you can refer back to it. Depending on your site's privacy setting, names and e-mail addresses are replaced by placeholders before your messages reach the AI provider, and the wizard only ever surfaces information you're already allowed to see. See [privacy](privacy.md) for how that works.

## The short version

Questions get answered on the spot. Anything that changes data is **previewed first and waits for your yes** (except saving a preference, and the optional confirm-for-a-few-minutes button). You can say no or rephrase at any time, nothing is saved without your confirmation, and the wizard never does more than you asked — all within your own permissions. For the bigger picture of how requests flow, see [how it works](how-it-works.md).

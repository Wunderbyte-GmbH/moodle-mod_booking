# Booking people into an option

The wizard can book other people into a booking option for you, and it can tell you how many places are booked and how many are still free. This page shows you how to ask for both.

Because booking someone in **changes their booking state**, the wizard always shows you what it is about to do and waits for your explicit confirmation first. Nothing happens to anyone's booking until you say yes.

## Booking one or more people in

Tell the wizard who to book and into which option. Name the option the way you would say it out loud — by its title, its day, or its time.

Example requests:

- "Book Maria Huber and Tom Berger into the Tuesday session."
- "Add Sarah Klein to the First Aid Basics option."
- "Enrol Jonas Weber in the 5 March pottery workshop."
- "Put Lena, Markus and Priya on the morning yoga course."
- "Book the user maria.huber@example.com into Beginner Swimming."

The wizard works out which option and which people you mean. If a name or an option is unclear — for example two people share a name, or there are several options on Tuesday — it asks you which one before going any further.

## Confirmation is always required

Booking other people in is a change to their records, so the wizard never does it silently. It first shows you a short summary — who it will book, into which option, and in which course — and asks you to confirm.

> **You:** Book Maria Huber and Tom Berger into the Tuesday session.
>
> **Wizard:** I'll book 2 people into *Tuesday Evening Yoga* (Course: Spring Wellness):
> - Maria Huber
> - Tom Berger
>
> Shall I go ahead?
>
> **You:** Yes.
>
> **Wizard:** Done — Maria Huber and Tom Berger are now booked into Tuesday Evening Yoga.

You can answer "no" or simply rephrase, and nothing is saved. See [confirmations and safety](confirmations-and-safety.md) for more on how previews work.

## Checking how many places are left

You don't have to book anyone to ask about an option. The wizard can read out how many places are booked, how many remain, and the maximum.

Example requests:

- "How many people are booked into the Tuesday session?"
- "How many places are left in First Aid Basics?"
- "Is the pottery workshop full?"
- "How many free spots does the morning yoga course have?"

The wizard replies with the numbers, not with the names of the people booked. To see who is booked, open the option's booking list in the Booking activity, or use a Report Builder report on booking answers — see [Reports in the Report Builder](reports.md). For a fuller picture of one option — its dates, price, trainer and capacity — see [finding options](finding-options.md). To check one person's booking, see [diagnosing bookings](diagnosing-bookings.md).

## What the wizard cannot do here

The wizard can **book** people in and it can **diagnose** booking problems, but it cannot cancel a booking for someone else. There is no "cancel this person's booking" action.

If someone can't book or something looks wrong, ask the wizard to look into it instead:

- "Why can't Tom Berger book the Tuesday session?"
- "Why is Maria not able to get a place on First Aid Basics?"

See [diagnosing bookings](diagnosing-bookings.md) for what those checks cover.

## Permissions

The wizard always acts with **your** Moodle permissions. If you're allowed to book people into options in this activity, it can do it for you. If you're not, it tells you instead of making the change. The same applies to reading an option's details — you see what your account is allowed to see.

## Tips

- You can name several people in one request — "book Maria, Tom and Lena into…" — and the wizard books them together after one confirmation.
- If you're not sure which option you mean, ask the wizard to list them first ("find all yoga options") and then refer to the right one. See [finding options](finding-options.md).
- You can refer back to an option you just looked at — "book Sarah into that one too" — and the wizard remembers which one you mean.

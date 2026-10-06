# Finding and reading booking options

The assistant can search your booking options, show you the full details of a single one, and tell you which fields an option has. All of this is read-only — asking these questions never changes anything, so the assistant answers right away.

## Searching for options by topic

Describe what you're looking for in your own words. The assistant searches the booking options and lists the matches.

Example requests:

- "Find all first-aid options."
- "Show me the yoga courses."
- "List the booking options in this activity."
- "Are there any swimming options for beginners?"
- "Find options about Excel."

The assistant replies with the matching options. If there are many, it gives you a list you can narrow down — just ask a follow-up like "only the ones on Mondays" or "which of those still have free places?"

## Searching by date or time window

You can search by when something happens, not just what it's about.

Example requests:

- "What's on next week?"
- "Show me the options happening in March."
- "Find all sessions on Tuesday."
- "Which options start in the next 7 days?"
- "What's coming up this month in this activity?"

You can combine a topic with a time window in one request:

- "Find first-aid options happening next week."
- "Are there any yoga sessions on Monday evenings?"

## Getting the details of one option

Once you know which option you mean, ask for its details. The assistant reads out the key information about it.

Example requests:

- "Show me the details of the Tuesday session."
- "Tell me everything about First Aid Basics."
- "What's the price and start date of the pottery workshop?"
- "Who is the trainer for Beginner Swimming, and how many places are left?"
- "Give me the full details of the 5 March option, including who is booked."

A details view can include things like the dates, the place, the price, the trainer, the capacity and remaining places, and — if you ask — who is currently booked.

> **You:** Tell me everything about the Friday pottery workshop.
>
> **Assistant:** *Pottery Workshop* (Course: Spring Crafts)
> - When: Friday 5 March, 10:00–13:00
> - Place: Studio B
> - Price: 25.00
> - Trainer: Anna Vogel
> - Places: 8 of 12 booked, 4 free

For more on reading the participant list and remaining places, see [booking people](booking-people.md).

## Asking which fields an option has

If you want to know what information an option can hold — for example before you ask to change something — you can ask the assistant to list its fields.

Example requests:

- "What fields does this option have?"
- "Which details can I set on a booking option?"
- "Does this option have a field for the meeting link?"
- "List the fields of the Tuesday session."

This is helpful when you're about to ask for a change and want to use the right wording. Editing an option's fields is a change, so when you do ask to edit one, the assistant shows you the change and waits for your confirmation first — see [confirmations and safety](confirmations-and-safety.md).

## Tips

- Searching and reading are always safe — they never change anything, so there's no confirmation step.
- If your search returns nothing, try a broader word ("first aid" instead of an exact title) or drop the date filter.
- The assistant searches within the options you're allowed to see. If you're allowed to view more by switching context, see [choosing the right item](choosing-the-right-item.md).
- You can chain requests: "find all first-aid options" → "show me the details of the second one" → "who's booked into it?"

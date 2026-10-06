# Prices and bulk changes

Two related jobs are covered here: setting up **prices** (including different prices for
different groups of people), and making the **same change to many options at once**. The
assistant handles both from plain-language requests.

As always, before it changes anything the assistant shows you a preview and waits for your
confirmation. With bulk changes this matters even more: it previews the full set of options that
will be affected, so you can check the list before anything is applied. See
[confirmations](confirmations-and-safety.md).

## Price categories

A **price category** is a named group that gets its own price — for example *student*, *staff*,
or *member*. Once a category exists, an option can have a different price for each category, and
people are charged the price for the category they belong to.

You can create a price category and give it a default value:

- "Create a price category *student* with a default price of 10 €."
- "Add a price category *staff*, default 0 €."
- "Set up a *member* category with a default of 15 €."

The default value is what's used for that category unless an individual option says otherwise.

## Setting prices on an option

Once you have categories, you can set per-option prices — and you can set several in one request:

- "Set the price of *Intro to First Aid* to 20 €, students 10 €."
- "Make *Excel for Beginners* cost 25 € for staff and 40 € for everyone else."
- "Give *First Aid Refresher* a student price of 12 €."

You can also set a plain single price with no categories at all:

- "Set the price of *Intro to First Aid* to 20 €."

## Changing many options at once

When you want the same change applied across a group of options, just describe the group and the
change. The assistant finds the matching options and prepares the change for all of them.

Examples:

- "Set all March sessions to 12 places."
- "Add a 10 € student price to every first-aid option."
- "Make all *Excel* sessions cost 25 €."
- "Move every Tuesday session to start at 15:00."
- "Set the trainer of all first-aid options to Maria Huber."

Bulk changes aren't limited to prices — you can bulk-change places, dates and times, trainers and
more, the same way you would for a single option.

## It previews the whole set before applying

This is the important part of bulk changes. Before anything is saved, the assistant shows you:

- **Which options matched** your description (the full list), and
- **What will change** for them.

Read the list. If it caught options you didn't mean — or missed some — tell the assistant to
narrow or widen it ("only the ones in March", "also include the Saturday session"), and it
re-previews. Only when you confirm does it apply the change to every option in the set.

If the description is ambiguous and the assistant can't be sure which options you mean, it asks
you to clarify before previewing. See [choosing the right item](choosing-the-right-item.md).

## What you're allowed to do

The assistant can only set prices and change options you're permitted to manage. If you're
allowed to, it does it; if not, it tells you. It can change and update options in bulk, but it
**cannot delete** options.

## Related

- [Creating a booking option](booking-options-create.md)
- [Editing an existing option](booking-options-edit.md)
- [The kinds of option you can create](booking-options-types.md)
- [Choosing the right item](choosing-the-right-item.md)
- [Confirmations and safety](confirmations-and-safety.md)

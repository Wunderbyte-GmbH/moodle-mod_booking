# Privacy

The Booking Wizard is careful with personal data. It only ever shows you what you're already allowed to see, and — depending on how your site is set up — it replaces names and e-mail addresses with placeholders before your messages reach the AI. This page explains what that means for you in practice.

## Your messages go to an AI provider

The wizard is powered by a language model that runs at an **AI provider** outside your Moodle site. Your messages, and the information the wizard looks up to answer them, are sent to that provider. Your administrator chooses the provider when setting up the wizard. If your site uses the Wunderbyte trial service, the administrator is told during setup who processes the requests and where.

## It only surfaces what you're already allowed to see

The wizard acts with **your** Moodle permissions — nothing more. When you ask it about users, courses, grades, enrolments, or anything else, it can only return information your own account is allowed to access.

So:

- If you can't see a user's grades in Moodle, the wizard can't show them to you either.
- If you have no access to a course, the wizard won't reveal its contents.
- If a report is restricted to managers and you're not one, you won't get it through the wizard.

The wizard isn't a back door. It's a faster way to reach the same information you're already entitled to — and only that.

## It won't expose contact details of users you have no relationship with

The wizard won't hand over personal contact details — like email addresses — for people you have no teaching or management relationship with. If you ask about someone who isn't in your courses or under your responsibility, it won't surface their private details.

When you do work with someone — for example a student enrolled in your course — the wizard can help you look up their profile, roles, grades, completion, and enrolment status, because those are things your role already lets you see.

## Names and e-mail addresses are replaced before they reach the AI

Before a message reaches the AI provider, the wizard can replace first names, last names and e-mail addresses with placeholders, so the language model works with a placeholder instead of the real person. In your own chat the placeholders are shown resolved again, so you still see the real names and details you're entitled to see, and you can keep referring to "that student" or "the user we just looked at" — the wizard follows along.

How much is replaced depends on your site's **AI privacy mode**, which your administrator sets:

- **Strict** (the default): names and e-mail addresses that come from the site, and those you type yourself, are replaced.
- **Soft:** only names and e-mail addresses the wizard looks up on the site are replaced; what you type yourself is sent as written.
- **Off:** nothing is replaced.

Your administrator can also keep a list of protected words that are never replaced (for example the names of places or products that look like personal names).

## What this looks like in practice

- **Looking up someone in your course is fine.** "Show me Maria Sanchez's completion in this course" works, because Maria is your student and completion is something you can see.
- **Diagnosing a problem is fine.** "Why didn't this student get the confirmation email?" works — it's about a user you're responsible for, and you're allowed to investigate.
- **Fishing for strangers' details won't work.** Asking for the email address of someone outside your courses won't return their private contact information.
- **Restricted reports stay restricted.** If a report needs permissions you don't have, you'll be told you can't access it rather than shown the data.

## You stay in control

Because the wizard mirrors your permissions, and — depending on the privacy mode — keeps names and e-mail addresses away from the AI provider, you can use it for everyday tasks — looking up enrolments, checking grades, diagnosing why a booking didn't work — without it exposing data you shouldn't see. If you're unsure how your site is set up, ask your administrator which AI provider and which privacy mode it uses.

## Related pages

- [Confirmations and safety](confirmations-and-safety.md) — how changes are previewed and confirmed, and the actions the wizard deliberately doesn't perform.
- [How it works](how-it-works.md) — the difference between read-only questions and changes, and how the wizard always acts with your permissions.

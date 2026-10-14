# Look-ups for one person or one course

The wizard can pull together read-only information about **one person** — their profile, roles and courses, and their access, enrolment, progress and grades in a course — and about the **structure of one course**. Because these are lookups, nothing changes: the wizard answers right away with no confirmation step.

The wizard only ever shows you what your account is allowed to see. If you can't view something, it tells you instead of revealing it. See [privacy](privacy.md).

For **lists across a whole course or the whole site** — everyone enrolled, everyone who completed, all bookings — use a report: see [Reports in the Report Builder](reports.md).

## A person's profile, roles and courses

Ask the wizard about a person, and it shows the profile details, the courses they are enrolled in and the roles they hold, as far as you may see them.

Example requests:

- "Show me Maria Huber's profile."
- "Which courses is Tom Berger enrolled in?"
- "What roles does Sarah Klein have in this course?"
- "May Jonas Weber add booking options here?"

For a role or a single permission in one place, the wizard checks the chain from the site down to the course or activity, including overrides, and tells you what applies. To find the person first, see [finding people](finding-people.md).

## Your own account

Ask about yourself and the wizard reads back your own account: who you are, your e-mail address, your roles and the courses you are enrolled in.

Example requests:

- "Who am I?"
- "Which courses am I enrolled in?"

## One person in one course

Within a course you can ask about one person's **access**, **enrolment**, **progress** (activity completion) or **grades**.

Example requests:

- "Can Maria Huber open the Week 3 quiz?"
- "Is Sarah's enrolment in this course active or suspended?"
- "Which activities has Tom Berger completed here?"
- "What grades does Tom have in this course?"
- "Why is Maria's grade for Assignment 1 missing?"

> **You:** Is Sarah's enrolment in Spring Wellness active?
>
> **Wizard:** Sarah Klein is enrolled in **Spring Wellness** through manual enrolment, as *Student*. Her enrolment is active and has no end date.

These read from the course's records and respect your permissions — for example you'll only see grades if your role lets you view them. More on these checks: [diagnosing access and progress](diagnosing-access-and-progress.md).

## A course's structure

Ask what a course contains, and the wizard lists its sections and the activities in them, including which are hidden or restricted. See [course overview](course-overview.md).

Example requests:

- "What is in this course?"
- "Show me the sections of the Spring Wellness course."

## What it doesn't do here

There is no look-up for a whole course's participants, a course-wide completion report, a search across all enrolments, or a site summary. For those, build or open a report — see [Reports in the Report Builder](reports.md). By default only managers can use the report tasks.

## Permissions

Everything on this page is read-only — the wizard never changes anything here, so there's no confirmation step. The wizard acts with **your** permissions and shows only what you're allowed to see. If you ask for something out of reach, it says so plainly.

## Tips

- Chain a lookup after a search: "find Maria Huber" → "which courses is she in?" → "and her grades in Spring Wellness."
- For a booking option (how many places are booked or free), see [finding options](finding-options.md) *(requires mod_booking)*.
- When a change is what you actually want — enrolling someone — see [enrolling a person in a course](enrolment-and-groups.md); that step is previewed and confirmed.

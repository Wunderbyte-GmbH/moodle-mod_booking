# Reports and lookups

The assistant can pull together read-only information about people and courses: a user's profile and roles, their grades, completion and enrolments in a course, the participants of a course, a course overview, and a short summary of the whole site. Because these are lookups, nothing changes — the assistant answers right away with no confirmation step.

Most of these are **manager-level**: you typically need teacher or manager rights in the course (or site-level rights for the site summary). The assistant only ever shows you what your account is allowed to see. If you can't view something, it tells you instead of revealing it. See [privacy](privacy.md).

## Looking up a user's profile and roles

Ask the assistant to read out a person's profile details or the roles they hold.

Example requests:

- "Show me Maria Huber's profile."
- "What's Tom Berger's email and full name?"
- "What roles does Sarah Klein have in this course?"
- "Is Jonas Weber a teacher or a student here?"

The assistant shows the profile details and roles you're allowed to see. To find the person first, see [finding people](finding-people.md).

## Grades, completion and enrolments in a course

Within a course you can ask about a person's grades, their completion progress, and their enrolment details.

Example requests for **grades**:

- "What grades does Tom have here?"
- "Show me Maria Huber's grades in this course."
- "How did Sarah Klein do on the assignments?"

Example requests for **completion**:

- "Show the completion report for this course."
- "Has Tom Berger completed this course?"
- "Who hasn't finished the activities yet?"

Example requests for **enrolment details**:

- "When was Maria enrolled in this course?"
- "Show me Tom's enrolment in Spring Wellness."
- "Is Sarah's enrolment active or suspended?"

> **You:** Show the completion report for this course.
>
> **Assistant:** Completion in **Spring Wellness**:
> - 18 of 24 participants have completed all required activities.
> - 6 are still in progress.
> Would you like the list of who hasn't finished?

These all read from the course's records and respect your permissions — for example you'll only see grades if your role lets you view them.

## Searching course enrolments

You can search the enrolments in a course rather than looking up one person at a time — for example to find who's enrolled, or to filter the list.

Example requests:

- "Who's enrolled in this course?"
- "Search the enrolments in Spring Wellness for anyone called Klein."
- "List the active enrolments in First Aid Programme."
- "Are there any suspended enrolments here?"

## Course overview and participants

For a picture of a whole course rather than one person, ask for an overview or the participants list.

Example requests for a **participants list**:

- "Show me the participants of this course."
- "Who's in Spring Wellness?"
- "List everyone enrolled here and their roles."

Example requests for a **course overview**:

- "Give me an overview of this course."
- "Summarise Spring Wellness for me."
- "How many participants and activities does this course have?"

The overview gives you a short summary of the course; the participants list gives you the enrolled people and their roles, as far as you're allowed to see them.

## Site summary

If you have site-level rights, you can ask for a short summary of the whole site.

Example requests:

- "Give me a summary of the site."
- "How many courses and users are on this site?"
- "Show me an overview of the whole platform."

This is the broadest lookup, so it needs the most permissions. If your account doesn't have site-level rights, the assistant tells you it can't show this.

## Permissions

Everything on this page is read-only — the assistant never changes anything here, so there's no confirmation step. But these lookups are permission-dependent:

- Profiles, roles, grades, completion, enrolments and participants generally need teacher or manager rights in the course.
- The site summary needs site-level rights.

The assistant acts with **your** permissions and shows only what you're allowed to see. If you ask for something out of reach, it says so plainly.

## Tips

- Chain a lookup after a search: "find Maria Huber" → "show her grades in this course" → "and her completion."
- For the people-facing side of a booking option (who's booked, free places), see [finding options](finding-options.md) and [booking people](booking-people.md).
- When a change is what you actually want — enrolling, grouping, messaging — see [enrolment and groups](enrolment-and-groups.md) and [calendar and messages](calendar-and-messages.md); those steps are previewed and confirmed.

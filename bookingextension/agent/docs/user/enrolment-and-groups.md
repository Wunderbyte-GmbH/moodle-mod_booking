# Enrolling people and managing groups

The assistant can enrol and unenrol people in a course, and it can create, update and delete groups and tell you who's in them. These are **manager-level actions** — you need the right to manage enrolments and groups in the course. If you don't have those rights, the assistant tells you instead of making the change.

Because enrolling, unenrolling and changing groups **change who has access to a course and how they're organised**, the assistant always shows you what it's about to do and waits for your explicit confirmation first. Nothing is saved until you say yes. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Enrolling someone in a course

Tell the assistant who to enrol and into which course. You can name several people in one request.

Example requests:

- "Enrol Maria Huber in the Spring Wellness course."
- "Add Tom Berger and Sarah Klein to First Aid Programme."
- "Manually enrol jonas.weber@example.com in this course."
- "Put Lena, Markus and Priya into the Pottery course as students."

The assistant works out who you mean and which course, then shows you a short preview before doing anything.

> **You:** Enrol Maria Huber and Tom Berger in Spring Wellness.
>
> **Assistant:** I'll enrol 2 people in **Spring Wellness**:
> - Maria Huber
> - Tom Berger
>
> Shall I go ahead?
>
> **You:** Yes.
>
> **Assistant:** Done — Maria Huber and Tom Berger are now enrolled in Spring Wellness.

If a name matches more than one person, the assistant asks you which one before continuing.

## Unenrolling someone from a course

You can also remove a person's enrolment from a course. This is a change too, so it's previewed and confirmed.

Example requests:

- "Unenrol Maria Huber from Spring Wellness."
- "Remove Tom Berger from the First Aid Programme."
- "Take Sarah Klein out of this course."

> **You:** Unenrol Maria Huber from Spring Wellness.
>
> **Assistant:** I'll remove **Maria Huber** from **Spring Wellness**. This ends her enrolment in the course.
>
> Shall I go ahead?

Answer "no" or rephrase and nothing changes.

## Creating, updating and deleting groups

The assistant can manage the groups inside a course. You can create a new group, change an existing one's name or description, or delete a group.

Example requests for **creating** a group:

- "Create a group called Monday Cohort in this course."
- "Make a new group named Beginners in Spring Wellness."
- "Add a group 'Team A' with the description 'morning session'."

Example requests for **updating** a group:

- "Rename the Monday Cohort group to Monday Morning."
- "Change the description of Team A to 'afternoon session'."

Example requests for **deleting** a group:

- "Delete the Beginners group from this course."
- "Remove the Team A group."

Each of these is previewed first. Deleting a group is a change you can't undo from here, so the assistant is especially clear about what it's about to remove and waits for your confirmation.

> **You:** Delete the Beginners group from Spring Wellness.
>
> **Assistant:** I'll delete the group **Beginners** in **Spring Wellness**. This removes the group; the people in it stay enrolled in the course.
>
> Shall I go ahead?

## Seeing who's in a group

Asking who's in a group is read-only, so the assistant answers right away without a confirmation step.

Example requests:

- "Who's in the Monday Cohort group?"
- "List the members of Team A."
- "Show me the people in the Beginners group."
- "How many people are in the Monday Cohort?"

The assistant lists the members of the group you name, as far as your permissions allow.

## Permissions

The assistant always acts with **your** Moodle permissions:

- Enrolling and unenrolling need the right to manage enrolments in the course.
- Creating, updating and deleting groups need the right to manage that course's groups.
- Listing group members shows you only what your account is allowed to see.

If you're allowed to do these things, the assistant does them for you after you confirm. If you're not, it tells you plainly instead of making the change.

## Tips

- You can name several people in one enrolment request and the assistant handles them together after a single confirmation.
- To find the right person first, see [finding people](finding-people.md) — then refer back: "enrol that user in this course."
- For a read-only picture of who's already enrolled, ask for the course participants — see [reports and lookups](reports-and-lookups.md).
- If you're not sure you're in the right course, ask the assistant to confirm the course first; see [choosing the right item](choosing-the-right-item.md).

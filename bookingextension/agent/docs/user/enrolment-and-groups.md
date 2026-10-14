# Enrolling a person in a course

The wizard can enrol **one existing user** in a course for you. It uses manual enrolment and gives the person a course role: the course's default role (usually student), or the role you name, such as teacher. You can also enrol yourself.

Because enrolling someone **changes who has access to a course**, the wizard always shows you what it's about to do and waits for your explicit confirmation first. Nothing is saved until you say yes. See [confirmations and safety](confirmations-and-safety.md) for how previews work.

## Enrolling someone

Tell the wizard who to enrol and into which course. Give the person by name, e-mail address or user id.

Example requests:

- "Enrol Maria Huber in the Spring Wellness course."
- "Manually enrol jonas.weber@example.com in this course."
- "Add Tom Berger to First Aid Programme as a teacher."
- "Enrol me in the Pottery course."

The wizard works out who you mean and which course, then shows you a short preview before doing anything.

> **You:** Enrol Maria Huber in Spring Wellness.
>
> **Wizard:** I'll enrol **Maria Huber** in **Spring Wellness** with the role *Student*.
>
> Shall I go ahead?
>
> **You:** Yes.
>
> **Wizard:** Done — Maria Huber is now enrolled in Spring Wellness.

If a name matches more than one person, or the role you named is unclear, the wizard asks you which one before continuing. If you don't name a course, it uses the course you are in.

## One person per request

Each enrolment request handles one person. To enrol several people, ask for them one after another — "now enrol Tom Berger too" — and confirm each one. For many people at once, use Moodle's own enrolment page or a cohort.

The person must already have an account; the wizard doesn't create accounts.

## What it doesn't do

The wizard only adds enrolments. It can't unenrol anyone, change or suspend an existing enrolment, or create, change or delete groups. Use Moodle's participants and groups pages for those.

Booking someone into a **booking option** is something different from enrolling them in a course — see [booking people](booking-people.md) *(requires mod_booking)*.

## Permissions

The wizard always acts with **your** Moodle permissions:

- You need the right to enrol users manually in the course (`enrol/manual:enrol`), and the course must have manual enrolment.
- Your role must also be allowed to use the wizard's enrolment task (*Enrol a user into a course*). Teachers and managers have it by default, and an administrator can withdraw it.

If you're allowed to do this, the wizard enrols the person after you confirm. If you're not, it tells you plainly instead of making the change.

## Tips

- To find the right person first, see [finding people](finding-people.md) — then refer back: "enrol that user in this course."
- To check someone's enrolment afterwards — active, suspended, or which courses they are in — see [look-ups for one person or one course](reports-and-lookups.md).
- If you're not sure you're in the right course, name it; see [choosing the right item](choosing-the-right-item.md).

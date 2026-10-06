# Finding people and asking about yourself

The assistant can look people up by name and show you what it's allowed to show — for example which courses someone is in and what roles they hold. It can also tell you about your own account: who you're signed in as and what roles you have. All of this is read-only, so the assistant answers right away without a confirmation step.

Throughout, the assistant only ever shows you people and details **you are allowed to see**. If your account can't view a person or one of their details, the assistant tells you that instead of showing it. See [privacy](privacy.md) for more on how personal data is handled.

## Finding a user by name

Tell the assistant who you're looking for. You can use a full name, a partial name, an email address, or a username.

Example requests:

- "Find the user Maria Huber."
- "Look up Tom Berger."
- "Is there a user with the email maria.huber@example.com?"
- "Search for users called Klein."
- "Find the account for username jweber."

The assistant searches the people you're allowed to see and lists the matches. If your search matches several people — for example two users share a name — it shows you the candidates so you can pick the right one.

> **You:** Find the user Maria Huber.
>
> **Assistant:** I found one matching user:
> - **Maria Huber** — maria.huber@example.com
>
> Would you like to see her courses and roles?

## Seeing someone's courses and roles

Once you've found a person, you can ask what they're enrolled in and which roles they hold. This is useful for a quick "where does this person appear and as what" overview.

Example requests:

- "Which courses is Maria Huber in?"
- "What roles does Tom Berger have?"
- "Show me Maria's courses and her role in each."
- "Is Sarah Klein a teacher anywhere?"
- "List the courses Jonas Weber is enrolled in."

The assistant replies with the courses the person is in and the role they hold in each, as far as your permissions allow. If you only have rights in one course, you'll see what applies there.

> **You:** What roles does Maria Huber have?
>
> **Assistant:** Maria Huber holds these roles:
> - **Spring Wellness** — Student
> - **First Aid Programme** — Teacher

For a fuller profile, including grades, completion or enrolment details in a specific course, see [reports and lookups](reports-and-lookups.md).

## Asking about yourself

You can ask the assistant who you're currently signed in as and what roles your own account has. This is handy when you're not sure which account you're using or why you can or can't do something.

Example requests:

- "Who am I?"
- "What's my username and email?"
- "What roles do I have?"
- "Am I a teacher in this course?"
- "Which account am I signed in as right now?"

The assistant reads back your own account details and roles.

> **You:** Who am I and what are my roles?
>
> **Assistant:** You're signed in as **Georg Maisser** (georg.maisser@example.com).
> Your roles:
> - **Site** — Manager
> - **Spring Wellness** — Teacher

## What you're allowed to see

The assistant always acts with **your** Moodle permissions. It only finds people and shows details that your account is allowed to view. If you ask about a user or a detail you can't see, it tells you plainly rather than revealing it.

Finding people and asking about yourself never changes anything, so there's no confirmation step. When you move on to actions that *do* change records — such as enrolling someone or putting them in a group — the assistant previews the change and waits for your yes first. See [enrolment and groups](enrolment-and-groups.md) and [confirmations and safety](confirmations-and-safety.md).

## Tips

- You can chain requests: "find Maria Huber" → "which courses is she in?" → "what's her role in Spring Wellness?"
- If a name matches several people, give the assistant something extra — an email, a username, or the course they're in — to narrow it down.
- If a search returns nobody, try a shorter or different spelling, or search by email instead of name.
- To see a person's full profile, grades or completion in one course, head to [reports and lookups](reports-and-lookups.md).

# Reports in the Report Builder

The assistant can show you the custom reports that exist on your site and tell you what the Moodle Report Builder can report on. Every report is built on a **report source**: a data set such as users, courses, badges, or, when the Booking plugin is installed, booking answers and booking options. Which sources exist depends on the plugins your site has installed; the assistant always shows the current state of your site.

Looking things up changes nothing. You see only the reports you are allowed to see; exploring sources needs the permission to create or edit custom reports.

## Finding existing reports

Ask for all reports, for reports by name, or for those built on one source.

Example requests:

- "Which custom reports exist on this site?"
- "Find the report about course completions."
- "Which reports can I edit?"
- "Is there already a report built on the users source?"

The side panel shows one card per report with a link to open it. If several reports match a name, the assistant asks which one you mean.

## Looking into one report

Name the report or give its id.

Example requests:

- "What does the completion report contain?"
- "Who can see the booking answers report?"
- "When is the weekly report sent, and to whom?"
- "How many rows does report 12 currently have?"

The assistant lists the columns, the conditions with their values, the filters, the audiences and the schedules as they are stored. The side panel shows the report itself, live: you can page, sort and use its filters right there.

## What you can ask

- Which report sources exist, and which plugin provides each of them.
- What one source offers: its columns, the filters a reader can use, the conditions an author can set, and the operators each filter understands.
- Which columns and filters a new report on that source would start with.

## Creating a report

Name the report and the data it should be built on. You can also name columns, conditions and filters; without columns the assistant takes the defaults of the source. It shows you what it will create and waits for your confirmation. A new report is visible only to you until you give it an audience.

Example requests:

- "Create a report of all users with their e-mail addresses."
- "Build a report on course completions, only completed ones, sorted by date."
- "Make a report of booking answers with the participant, the option and the booking date."
- "Set up a report of badges awarded in the last three months."

If a column or condition you named does not exist in that source, the assistant lists what the source offers and asks you to pick. After the report is created the side panel shows it live.

## Changing a report

Name the report and what should change.

Example requests:

- "Add the e-mail column to the users report."
- "Remove the city column from that report."
- "Sort the completion report by date, newest first."
- "Only show completed entries in the report."
- "Rename the report to Quarterly overview."

The assistant shows the planned changes, waits for your confirmation, and then shows the changed report in the side panel.

## Deciding who sees a report

A new report is visible only to you. Tell the assistant who else should see it: everyone, people with a role, the members of a cohort, or named persons. You can also take an audience away again.

Example requests:

- "Make the report visible to all managers."
- "Share the completion report with the members of the cohort Trainers."
- "Let everyone on the site see this report."
- "Give Anna Muster access to the users report."
- "Remove the all-users audience from the report."

The assistant shows the planned change and waits for your confirmation. Afterwards it tells you how many people each audience covers.

## Sending a report on a schedule

Once a report has an audience, the assistant can send it to those people automatically: choose how often, from when, and in which file format. You can also pause, resume or change a schedule, or have the report sent right away.

Example requests:

- "Send the completion report every Monday as an Excel file."
- "E-mail that report to its audience once a month, starting on the first of next month."
- "Do not send the report when it is empty."
- "Pause the weekly report schedule."
- "Send the report now."

The assistant shows the planned schedule and waits for your confirmation. A report without an audience cannot be scheduled; the assistant asks you to set one first.

## Counting and looking at the data

Ask how many rows a report returns, with or without filter values. The assistant tells you the number and the columns, and shows the report itself, with its rows, in the side panel. The rows are never part of the assistant's answer.

Example requests:

- "How many rows does the completion report have?"
- "How many people are in the radiation protection report?"
- "Run the users report filtered to suspended accounts."
- "Show me the enrolment report."

Filter values you ask for are applied to your own view of the report, exactly as if you had set them in the report's filter bar.

## Why someone does not receive a report

Ask the assistant about a specific person and it checks the facts: whether the person is in the report's audience and in the schedule's recipients, whether the schedule is active and when it sends next, and whether the account can receive e-mail.

Example requests:

- "Why does Maria not get the weekly report?"
- "Is anna@example.com among the recipients of the completion report?"

## Listing the report sources

Ask for the sources in general, or for those of one plugin.

Example requests:

- "Which report sources does the Report Builder offer here?"
- "What can I build reports on?"
- "Show me the report sources of the Booking plugin."
- "Which plugins provide report builder data sources?"

The side panel shows one card per source, grouped by plugin, with the exact source identifier the assistant uses when it builds a report.

## Describing one source

Name the source as it appears in the list.

Example requests:

- "Which columns does the Users source have?"
- "What can I filter on in the booking answers source?"
- "Show me the conditions and their operators for the Courses source."
- "Which aggregations are possible for the columns of the Badges source?"

If the assistant cannot tell which source you mean, it shows the available sources and asks you to pick one. The side panel lists the columns, filters and conditions grouped by the entity they belong to, with the exact identifier of each element.

# Skills catalog

> **Scope.** Every skill the agent ships with — and the skill families that plugins contribute —
> with what it does, its risk class, and its key parameters.

A **skill** is one capability the agent can invoke. The engine-provided skills are split by
responsibility across five namespaces (all registered by
`bookingextension_agent\local\wizard\skill_provider` and discovered from the matching
`classes/local/wizard/<namespace>/skills/` directory):

- **`wizard.*`** — agent-specific engine skills (memory, doc-Q&A, skill discovery, skill
  scaffolding), in `classes/local/wizard/wizard/skills/`.
- **`core.*`** — Moodle-core skills (users, permissions, notifications, site content), in
  `classes/local/wizard/core/skills/`. `core` is reserved here for genuine Moodle-core domain,
  matching its meaning in Moodle itself.
- **`course.*`** — Moodle-course skills (courses, categories, activities, quizzes, enrolment), in
  `classes/local/wizard/course/skills/`.
- **`question.*`** — Moodle question-bank skills, in `classes/local/wizard/question/skills/`.
- **`report.*`** — Moodle Report Builder skills, in `classes/local/wizard/report/skills/`
  (reserved namespace; base class `report_skill_base`). Plugin-agnostic: every datasource of
  every installed plugin appears automatically through core's own discovery.

Plugin skill families are discovered from their component the same way:

- **`mod_booking.*`** — booking-domain skills (base class `booking_skill_base`). They ship with
  mod_booking and exist only on sites where mod_booking is installed.
- **`local_taskflow.*`** — taskflow skills. They ship with local_taskflow (a version that
  contains agent skills) and exist only where it is installed.

Other plugins can contribute their own skill families the same way (see
`wizard.scaffold_skill`).

**Gating.** Every skill is gated at run time by its per-skill capability
`<component>:skill_<normalized skill name>`, where the skill name is lower-cased and every run
of other characters becomes `_` — for example `bookingextension/agent:skill_core_search_users`,
`mod/booking:skill_mod_booking_book_users`, `local/taskflow:skill_local_taskflow_search_rules`.
On top of that come the skill's native Moodle capabilities and the activation toggle
`aiskillenabled_<normalized skill name>` (Skill governance). **Default activation:** read-only
skills are enabled out of the box; mutating skills stay disabled until an administrator enables
them (or uses the one-shot *enable all*); `wizard.explain_docs` is read-only but requires
explicit activation because it needs a docs index.

**Default roles of the engine skill capabilities** (`db/access.php`): teacher, editing teacher
and manager for most `core.*`, `course.*`, `question.*` and `wizard.*` skills; manager only for
`course.create_course`, all `report.*` skills and `wizard.recreate_skill_catalog`; every
authenticated user for `wizard.remember`, `wizard.forget`, `wizard.list_memories` and
`wizard.search_skills`.

**Risk classes.** R0 `read_only` (runs without confirmation), R1 `scoped_write`, R2
`broad_write`, R3 `irreversible_or_external`. R1–R3 stop for the user's confirmation; with
`bookingextension/agent:confirmforsession` (admin-only by default) the user can confirm for a
limited time, during which R1 passes preflight directly and the panel submits R2/R3
confirmations automatically. R3 is never retried automatically. In the tables, `*` marks a required input and `a`/`b` means
"one of".

The abstract base class `core_skill_base` stays in `core/skills/` and is extended by all
engine skills regardless of namespace.

---

## Agent engine skills (`wizard.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `wizard.explain_docs` | R0 | ✓ | Search the documentation corpora and return the passage that answers a question, or a whole page (any language). Requires explicit activation | `question`*, `search_queries`, `corpus_id`, `doc_path`, `doc_path_candidates`, `whole_page`, `line_start`, `outputlang` |
| `wizard.list_skills` | R0 | ✓ | List everything the agent can do | `question`, `scope` (all/readonly/mutating), `detail` (names/full), `outputlang` |
| `wizard.search_skills` | R0 | ✓ | RAG fallback — search the registry for capabilities discovery missed | `query`* |
| `wizard.recall_memory` | R0 | ✓ | Recall the user's own earlier conversation (last thread or a date window) | `mode`* (last_thread/date_window), `date_from`, `date_to` (ISO dates), `query`, `include_structured` |
| `wizard.remember` | R0 | ✓ | Store a user-stated fact, preference or standing instruction (no confirmation) | `memory`*, `relevant_for` |
| `wizard.list_memories` | R0 | ✓ | List the user's stored memories | — |
| `wizard.forget` | **R2** | ✗ | Delete one stored memory, or all of them; always confirmed | `query`/`id`/`all` |
| `wizard.scaffold_skill` | R0 | ✓ | Generate a downloadable starter template for a new skill in a third-party plugin (does not implement or run it) | `component`*, `description`*, `skillname`, `risk_class`, `is`, `not`, `example_utterances`, `properties`, `capabilities`, `context_scopes` |
| `wizard.recreate_skill_catalog` | **R2** | ✗ | Rebuild the skill-catalog embeddings | `force`, `model`, `dimensions` |

`wizard.explain_docs` and `wizard.scaffold_skill` are preview-capable (`get_result_preview`).
All are R0 **except** `wizard.forget` (deletes user memories) and
`wizard.recreate_skill_catalog` (mutates the embeddings index), both R2. Memory limits: 15
memories per user, 500 characters each, 4096 in total (`user_memory_service`).

## Moodle-core skills (`core.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `core.get_current_user` | R0 | ✓ | The requester's own account: identity, e-mail, roles, enrolled courses | `outputlang` |
| `core.search_users` | R0 | ✓ | Find other users with profile data, enrolled courses, roles and profile URL | `query`*, `limit`, `outputlang` |
| `core.diagnose_permissions` | R0 | ✓ | One person's roles and capabilities along the context chain, incl. overrides | `userquery`/`userid`, `coursequery`/`courseid`, `capability` |
| `core.diagnose_notifications` | R0 | ✓ | Why a person receives no e-mails or notifications (account blockers; site mail switches for admins) | `userquery`/`userid` |
| `core.find_content` | R0 | ✓ | Semantic search across indexed site content, access-checked per hit (needs the DB embeddings store and enabled areas) | `query`*, `areas`, `courseid`, `limit`, `includecontent`, `outputlang` |

All five are preview-capable (`get_result_preview`).

## Moodle-course skills (`course.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `course.search_courses` | R0 | ✓ | Find courses matching a query | `query` (aliases `coursequery`, `coursename`, `course`, `searchterm`), `limit`, `outputlang` |
| `course.list_categories` | R0 | ✓ | List the visible course categories with path, course count and hidden flag | `query` (alias `categoryquery`), `limit`, `outputlang` |
| `course.analyze_course_structure` | R0 | ✓ | Sections and activities of a course, incl. hidden, restricted and locked state | `coursequery`/`courseid`, `include_descriptions` |
| `course.diagnose_user_in_course` | R0 | ✓ | One person in one course: access, enrolment, progress or grades | `aspect` (access/enrolment/progress/grades), `userquery`/`userid`, `coursequery`/`courseid`, `activityquery`/`activityid`, `itemquery` |
| `course.create_course` | **R2** | ✗ | Create a new, empty course (manager-only capability; asks for the category) | `fullname`*, `summary`, `categoryquery`, `shortname`, `visible`, `override`, `outputlang` |
| `course.scaffold_course_content` | **R2** | ✗ | Fill an existing course in one step: welcome section, chapter pages (default 4, max 8), closing section, optional practice and final quizzes | `topic`*, `chapters`, `practicequizzes`, `finalquiz`, `quizquestions`, `coursequery`/`courseid`, `outputlang`, `override` |
| `course.add_activity` | **R2** | ✗ | Add an activity or resource; whitelist `page`, `url`, `label`, `book`, `folder`, `forum` (enabled site-wide and addable by the user) | `modname`, `name`, `intro`, `section`, `externalurl` (URL), `content` (Page text), `settings`, `coursequery`/`courseid` |
| `course.update_activity` | **R2** | ✗ | Rename, re-describe, show/hide, move (section, relative or within the section) an activity, or change its link, page text or another module setting | `activityquery`/`cmid`, `name`, `intro`, `visible`, `externalurl`, `content`, `settings`, `section`, `sectiondelta`, `position`, `coursequery`/`courseid` |
| `course.add_quiz` | **R2** | ✗ | Create a quiz, empty or with generated, existing or random questions | `name`*, `intro`, `section`, `addquestions`, `content`, `usecoursepdfs`, `resourcecmid`, `count`, `qtypes`, `difficulty`, `questionids`, `category`, `randomcount`, `coursequery`/`courseid` |
| `course.update_quiz` | **R2** | ✗ | Rename, re-describe or show/hide a quiz and/or add questions to it | `activityquery`/`cmid`, `name`, `intro`, `visible`, `addquestions`, `content`, `usecoursepdfs`, `resourcecmid`, `count`, `qtypes`, `difficulty`, `questionids`, `category`, `randomcount`, `coursequery`/`courseid` |
| `course.enrol_user` | **R2** | ✗ | Enrol one existing user via manual enrolment with the default or a named course role (native `enrol/manual:enrol`) | `userquery`/`userid`, `role`, `coursequery`/`courseid` |

`add_activity`, `add_quiz`, `analyze_course_structure`, `diagnose_user_in_course`,
`update_activity` and `update_quiz` are preview-capable. The flat `externalurl` and `content`
fields carry the link of a URL and the text of a Page; `settings` is only for other
module-specific fields.

## Moodle question-bank skills (`question.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `question.generate_questions` | **R2** | ✗ | Generate questions (multichoice, truefalse, shortanswer; max 50) from chat text, a dictated question, an upload or the course PDFs, and import them into the course question bank | `content`, `count`, `qtypes`, `difficulty`, `target_category`/`target_categoryid`, `resourcecmid`, `usecoursepdfs`, `coursequery`/`courseid`, `outputlang` |

The upload is optional. Preview-capable.

## Moodle Report Builder skills (`report.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `report.list_report_sources` | R0 | ✓ | List the datasources the Report Builder offers here: identifier, name, plugin, entities, counts | `component`, `include_entities`, `limit` |
| `report.describe_report_source` | R0 | ✓ | Columns, filters and conditions of one source with exact identifiers, types, aggregations and operator enums | `source`*, `section`, `entity` |
| `report.search_reports` | R0 | ✓ | Existing custom reports the user may view or edit (id, name, source, audiences, schedules, links); hidden reports are counted, not listed | `reportquery`, `source`, `editable_only`, `mine_only`, `limit` |
| `report.query_report` | R0 | ✓ | Row count of a report, optionally with filter values (stored as the user's own filter values, as the report view does); the observation carries count, headers and filters only, the rows are shown in the side panel | `reportid`/`reportquery`, `filters` |
| `report.get_report_details` | R0 | ✓ | One report as stored: columns with aggregation/sorting, conditions with values, filters, audiences (covered users as counts), schedules, row count; optional delivery diagnosis for a named person (audience membership, schedule state, account state, as facts); live report view in the side panel | `reportid`/`reportquery`, `include_row_count`, `diagnose_userquery` |
| `report.create_report` | **R2** | ✗ | Create a custom report: name, source, optional columns (heading, aggregation, sort), conditions with values, filters, unique rows, tags; source defaults when no columns are given; confirmable on a duplicate name | `name`*, `source`*, `use_defaults`, `columns`, `conditions`, `filters`, `uniquerows`, `tags`, `override` |
| `report.update_report` | **R2** | ✗ | Change a report: add/remove/replace columns, heading, aggregation, sorting, position; conditions with values; filters; rename; unique rows | `reportid`/`reportquery`, `name`, `uniquerows`, `add_columns`, `remove_columns`, `set_columns`, `replace_columns`, `add_conditions`, `set_condition_values`, `remove_conditions`, `add_filters`, `remove_filters` |
| `report.set_report_audience` | **R2** | ✗ | Add or remove an audience of any registered type (all users, admins, system role, cohort, named persons, plugin types); persons resolved by id/address/single name, reported as a count only | `reportid`/`reportquery`, `action`, `audience_type`, `roles`, `cohorts`, `userqueries`, `config`, `heading`, `audienceid` |
| `report.schedule_report` | **R2** | ✗ | Create, change, enable, disable or send now a schedule: recipient audiences, format (enabled dataformats), recurrence, start time (ISO 8601), view-as, empty-report policy, subject, message | `reportid`/`reportquery`, `action`, `scheduleid`, `name`, `audiences`, `format`, `recurrence`, `starttime`, `viewas`/`viewas_userquery`, `subject`, `message`, `if_empty`, `enabled` |

All run in the system context and decide access like core (`core_reportbuilder\permission`): the
discovery and authoring skills require the authoring capabilities (`moodle/reportbuilder:edit` or
`:editall`, plus core's per-report edit rule for changes), the lookup skills apply core's per-report
visibility (`can_view_report`). The skill capabilities themselves are manager-only by default. All are
preview-capable: source cards, a per-entity table, report
cards, and — for details, create and update — the real Report Builder view rendered with its
render-time JS (`replace` preview, paging/sorting/filters work in the panel). Every recoverable
input problem (unknown source, report, column, condition, filter, operator, aggregation) is a
clarification with the alternatives as options; the authoring skills validate the whole definition
against the datasource in preflight (`report_definition_service`, `filter_value_codec`) and write
in one transaction. Report rows never reach the language model: `report.query_report` returns the count, the headers and the
filters, and the rows appear in the side panel only (decision D9 of Wunderbyte-GmbH/Wunderbyte-GmbH#2471).

---

## Booking skills (`mod_booking.*`)

> **Requires mod_booking.** These skills are provided by mod_booking, not by the agent engine.
> Without mod_booking installed they are not available. Capabilities:
> `mod/booking:skill_mod_booking_<name>` in mod_booking's `db/access.php`.

### Read-only (R0)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.search_options` | Search/list options in the current instance, optionally by date window | `query`, `when`, `whenuntil`, `limit`, `cmid`/`activityquery` |
| `mod_booking.get_option_details` | Details of one or a few options: title, teachers, sessions, price, availability (booked/free/max counts, no participant names), location, image; optional custom fields | `optionid`/`optionids`/`optionquery`, `includesessions`, `requested_fields`, `include_customfields`, `customfield_keys`, `maxitems` |
| `mod_booking.list_option_properties` | List the option create/update schema fields | `question`, `scope`, `cmid`/`activityquery` |
| `mod_booking.list_instance_settings` | Configurable settings of a booking activity with their current values (native `mod/booking:updatebooking`) | `cmid`/`activityquery` |
| `mod_booking.list_option_fields` | The site's booking option custom fields: type, category, configuration, usage count (manager-only capability) | `shortname`/`fieldid`, `includeusage`, `question` |
| `mod_booking.analyze_rules` | Read-only analysis of booking rules / notifications | `query`, `active_only`, `include_templates`, `limit`, `cmid`/`activityquery` |
| `mod_booking.diagnose_booking_issue` | Why a user can't book / isn't booked | `optionquery`/`optionid`, `userquery`/`targetuserid`, `issue`, `question`, `activityquery` |
| `mod_booking.diagnose_cancellation_issue` | Why a user can't cancel | `optionquery`/`optionid`, `userquery`/`targetuserid`, `question`, `activityquery` |
| `mod_booking.diagnose_user_booking` | Verbose status report for one person — status, when booked, completion, previous/cancelled bookings, submitted form data, and received messages. Option-scoped when an option is named, else an instance-wide overview (e.g. "how many options has X completed") | `userquery`/`userid`, `optionquery`/`optionid` (optional), `includemessages` |
| `mod_booking.diagnose_waitinglist` | Why the waiting list of an option did or did not move (no promotion after a cancellation; nobody moved to the list after reducing seats) | `optionquery`/`optionid` |

### Broad write (R2)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.create_option` | Create a standard booking option | `text`*, option fields (`description`, `maxanswers`, `optiondates`, `coursestarttime`, `prices`, `teacherquery`, `headerimage_token`, …), `override`, `cmid`/`activityquery` |
| `mod_booking.create_selflearning_option` | Create a self-learning option | `text`*, `duration`, `selflearningcourse`, `maxanswers`, teacher fields, `override` |
| `mod_booking.create_slotbooking_option` | Create a slot/appointment option | `text`*, `slot_*` fields, `override` |
| `mod_booking.update_option` | Update an existing option (incl. availability conditions, custom form, entry tickets `ticket*`, header image) | `optionid`/`optionquery`, `optionwhen`, `shiftdays`, mutation fields |
| `mod_booking.update_option_trainer` | Assign/replace trainer(s) of an option | `optionid`/`optionquery`, `optionwhen`, `teacherquery`/`teacheremail`/`teacherids` |
| `mod_booking.bulk_update_options` | Update many options at once | `optionids`/`optionquery`/`apply_to_all`, mutation fields |
| `mod_booking.add_price_category` | Create a price category | `identifier`, `name`, `defaultvalue`, `pricecatsortorder`, `override` |
| `mod_booking.configure_booking_instance` | Change settings of the booking activity (the fields listed by `list_instance_settings`); `action=list_fields` only redirects there | `action`* (`update`), `changes`, `cmid`/`activityquery` |
| `mod_booking.create_rule_from_template` | Create a booking rule from a template; timing in whole days | `templateid`/`templatequery`, `rulename`, `isactive`, `days`, `question`, `cmid`/`activityquery` |
| `mod_booking.update_rule_from_template` | Change an existing rule: days, on/off, name, mail subject and body, or reapply a template | `ruleid`/`rulequery`, `templateid`/`templatequery`, `rulename`, `isactive`, `days`, `mailsubject`, `mailbody`, `activityquery` |
| `mod_booking.create_option_field` | Create a booking option custom field (manager-only capability) | `shortname`*, `name`*, `type`*, `categoryname`, `description`, `options`, `defaultvalue`, `required`, `uniquevalues`, `configdata` |
| `mod_booking.update_option_field` | Change a booking option custom field (manager-only capability) | `currentshortname`*/`fieldid`, `shortname`, `name`, `categoryname`, `description`, `options`, `defaultvalue`, `required`, `uniquevalues`, `configdata` |

There is no R1 booking skill.

### Irreversible / external (R3)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.book_users` | Book one or more users into an option via the standard bookit flow | `optionid`/`optionquery`, `optionwhen`, `activityquery`, `bookusersquery`, `bookuserstimebooked`, `bookuserscompleted`, `bookusersupdateexisting`, `confirmed` |

`book_users` is the only R3 booking skill: it changes other users' booking state, so it
always requires manual confirmation and never auto-retries. The resolved user ids are filled by
preflight, not passed as input.

---

## Taskflow skills (`local_taskflow.*`)

> **Requires local_taskflow** in a version that ships agent skills. Capabilities:
> `local/taskflow:skill_local_taskflow_<name>`.

| Skill | Risk | Purpose | Key inputs |
|-------|:---:|---------|-----------|
| `local_taskflow.search_assignments` | R0 | Search assignments (overdue, due soon) by person, unit, rule, status, dates | `userquery`/`userid`, `unitquery`/`unitid`, `ruleid`, `status`, `duebefore`, `dueafter`, `overdueonly`, `activeonly`, `self`, `limit` |
| `local_taskflow.get_assignment_details` | R0 | Facts of one assignment: status, due date, targets, requests, history | `assignmentid` or `userquery`/`userid` + `rulequery`/`ruleid`, `status`, `historylimit` |
| `local_taskflow.diagnose_assignment_status` | R0 | Why an assignment has its status and what blocks progress | `assignmentid` or `userquery`/`userid` + `rulequery`/`ruleid` |
| `local_taskflow.diagnose_user_assignments` | R0 | Why a person has or lacks an assignment for a rule | `rulequery`/`ruleid`, `userquery`/`userid` |
| `local_taskflow.diagnose_message_delivery` | R0 | Why a taskflow message was or was not delivered | `messagequery`/`messageid`, `class`, `assignmentid`, `rulequery`/`ruleid`, `userquery`/`userid`, `requesttype` |
| `local_taskflow.diagnose_permissions` | R0 | A person's taskflow capabilities, supervisor role, HR lists, deputies, visible tabs | `userquery`/`userid` |
| `local_taskflow.diagnose_import` | R0 | State of the HR data import: adapter, last run, errors since a date | `since` |
| `local_taskflow.get_user_taskflow_profile` | R0 | A person's units, supervisor, deputies, contract end, long leave, counts per status | `userquery`/`userid` |
| `local_taskflow.supervisor_overview` | R0 | Team overview of a supervisor | `supervisorquery`/`supervisorid` |
| `local_taskflow.search_rules` | R0 | Search rules by name, unit, active flag, target type | `query`, `unitquery`/`unitid`, `isactive`, `targettype`, `limit` |
| `local_taskflow.get_rule_details` | R0 | Full configuration of one rule | `rulequery`/`ruleid` |
| `local_taskflow.list_rule_properties` | R0 | Building blocks of a rule from the code (fields, operators, targets, statuses, timings, placeholders) | — |
| `local_taskflow.list_units` | R0 | Organisational units with hierarchy, members and rules | `query`, `parentid` |
| `local_taskflow.list_requests` | R0 | Requests visible to the acting user | `userquery`/`userid`, `type`, `treated`, `all`, `self`, `createdafter`, `createdbefore`, `limit` |
| `local_taskflow.list_settings` | R0 | Taskflow configuration and the active import adapter | `filter`, `includeadapter` |
| `local_taskflow.search_message_templates` | R0 | Search message templates by recipient, timing, type, package | `query`, `recipient`, `senddirection`, `sendstart`, `senddays`, `package`, `type`, `limit` |
| `local_taskflow.preview_message` | R0 | Render one message template for one assignment | `assignmentid`*, `messagequery`/`messageid`, `class` |
| `local_taskflow.change_assignment_status` | R1 | Change the status of one assignment with a reason | `assignmentid`*, `status`*, `reason`*, `comment`, `keepchanges` |
| `local_taskflow.create_request` | R1 | Create a not-relevant or extension request for one's own assignment | `assignmentid`*, `type`*, `comment` |
| `local_taskflow.extend_assignment_duedate` | R1 | Move a due date or decide on an extension request | `assignmentid`*, `newduedate`/`extenddays`, `decision`, `comment` |
| `local_taskflow.post_internal_message` | R1 | Post into the internal chat of one assignment | `assignmentid`*, `text`* |
| `local_taskflow.recheck_assignments` | R1 | Re-run the assignment logic for one person | `userquery`/`userid`, `assignmentid` |
| `local_taskflow.reschedule_rule` | R1 | Re-run one rule now (queued for the next cron run) | `ruleid`* |
| `local_taskflow.treat_request` | R1 | Confirm or decline one request addressed to the acting user | `requestid`*, `decision`*, `comment`, `newduedate` |
| `local_taskflow.create_rule` | R2 | Create a rule assigning courses, booking options or competencies | `name`*, `ruletype`*, `duedatetype`*, `unitid`/`userid`, `duration`/`fixeddate`, `filters`, `targets`, `messageids`, `requests`, `enabled`, … |
| `local_taskflow.update_rule` | R2 | Change an existing rule | `ruleid`*, rule fields, `addtargets`/`removetargetids`, `addmessageids`/`removemessageids`, `isactive` |
| `local_taskflow.create_message_template` | R2 | Create a message template | `name`*, `subject`*, `body`*, recipient and timing fields |
| `local_taskflow.update_message_template` | R2 | Change a message template | `messageid`*, template fields |
| `local_taskflow.manage_unit_membership` | R2 | Add one user to a unit or remove them | `unitid`*, `action`*, `userquery`/`userid` |
| `local_taskflow.pause_or_resume_assignments` | R2 | Pause or resume all assignments of one person (long leave) | `action`*, `userquery`/`userid` |
| `local_taskflow.review_evidence` | R2 | Approve or reject one uploaded competency evidence | `decision`*, `assgincompid` or `assignmentid` + `competencyid`, `validuntil`, `comment` |
| `local_taskflow.send_message_now` | R2 | Send one message template immediately for a list of assignments | `messageid`*, `assignmentids`* |
| `local_taskflow.delete_rule` | R3 | Delete a rule with all its assignments (queued; cannot be undone) | `ruleid`*, `override` |
| `local_taskflow.trigger_import` | R3 | Run the HR data import from a JSON payload, or a dry run | `payload`, `dryrun`, `override` |

All taskflow skills also accept `outputlang`.

---

## Notes for skill authors

- **Discovery is semantic-only.** Skills are retrieved purely by embedding similarity (the
  description + `example_utterances` anchors). There is **no** lexical "always-include" tier — the
  `always_available` governance flag, `MANDATORY_SKILL_KEYWORDS` and `mandatory_on_trigger` /
  `intent_triggers` are all removed. If a skill is not retrieved, fix its anchors (utterances),
  never add a keyword. The single exception is `wizard.search_skills` (the RAG fallback), force-added
  to the catalog by `discovery_phase_service::ensure_search_skills_fallback()`.
- **`override`** appears on several mutating skills (`mod_booking.create_*_option`,
  `add_price_category`, `course.create_course`, `course.scaffold_course_content`,
  `report.create_report`, some taskflow skills): it is how the agent confirms past a soft block
  (e.g. a duplicate-title `DOMAIN_CONFLICT`). `mod_booking.update_option` and
  `bulk_update_options` have no `override` input.
- **Option mutations** go through `mod_booking`'s `booking_option::update()` with form-style
  params; the executor and skills stay free of option-write internals.
- **Scaffolding.** `wizard.scaffold_skill` produces a starter template that already fills the
  contract (name, schema, risk class, capability, discovery anchors) for a third-party component.

_(Skill names, risk classes, and inputs were read from the skill classes; verify a specific
skill's full schema in its `get_schema()` before relying on an exact field name.)_

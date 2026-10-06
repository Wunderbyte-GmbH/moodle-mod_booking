# Skills catalog

> **Scope.** Every skill the agent ships with: what it does, its risk class, and its key
> parameters.

A **skill** is one capability the agent can invoke. The engine-provided skills are split by
responsibility across four namespaces (all registered by
`bookingextension_agent\local\wizard\skill_provider` and discovered from the matching
`classes/local/wizard/<namespace>/skills/` directory):

- **`wizard.*`** — agent-specific engine skills (memory, doc-Q&A, skill discovery), in
  `classes/local/wizard/wizard/skills/`. This namespace is the engine's always-on discovery
  baseline.
- **`core.*`** — Moodle-core (user) skills, in `classes/local/wizard/core/skills/`. `core` is
  reserved here for genuine Moodle-core domain, matching its meaning in Moodle itself.
- **`course.*`** — Moodle-course skills, in `classes/local/wizard/course/skills/`.
- **`question.*`** — Moodle question-bank skills, in `classes/local/wizard/question/skills/`.
- **`report.*`** — Moodle Report Builder skills, in `classes/local/wizard/report/skills/`
  (reserved namespace; base class `report_skill_base`). Plugin-agnostic: every datasource of
  every installed plugin appears automatically through core's own discovery.

Booking-domain skills live under **`mod_booking.*`** (discovered from the `mod_booking`
component, base class `booking_skill_base`).

Every skill is gated at run time by its per-skill capability
`bookingextension/agent:skill_<name>` and by the activation toggle
`aiskillenabled_<name>`.

The abstract base class `core_skill_base` stays in `core/skills/` and is extended by all
engine skills regardless of namespace.

---

## Agent engine skills (`wizard.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `wizard.explain_docs` | R0 | ✓ | Search the documentation corpora and return a relevant excerpt (any language) | `question`, `outputlang`, `doc_path`, `corpus_id`, `line_start` |
| `wizard.list_skills` | R0 | ✓ | List the agent's capabilities / skill names | `question`, `scope`, `outputlang` |
| `wizard.recall_memory` | R0 | ✓ | Recall the user's own earlier conversation (last thread / date window) | `mode`, `date_hint`, `query` |
| `wizard.remember` | R0 | ✓ | Store a user-stated fact/preference | `memory`, `scopes` |
| `wizard.forget` | R0 | ✓ | Remove a stored user memory | `query` |
| `wizard.list_memories` | R0 | ✓ | List the user's stored memories | `outputlang` |
| `wizard.search_skills` | R0 | ✓ | RAG fallback — search the registry for capabilities discovery missed | `query` |
| `wizard.recreate_skill_catalog` | **R2** | ✗ | Rebuild the skill-catalog embeddings CSV | `force`, `model`, `dimensions` |

`wizard.explain_docs` is preview-capable (`get_result_preview`). All are R0 **except**
`wizard.recreate_skill_catalog`, which mutates the embeddings index (R2).

## Moodle-core skills (`core.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `core.get_current_user` | R0 | ✓ | Return info about the current user | `outputlang` |
| `core.search_users` | R0 | ✓ | Find users with profile/courses/roles | `query`, `limit`, `outputlang` |

Both are preview-capable (`get_result_preview`).

## Moodle-course skills (`course.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `course.search_courses` | R0 | ✓ | Find courses matching a query | `query`, `limit`, `outputlang` |

## Moodle question-bank skills (`question.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `question.generate_questions` | **R2** | ✗ | Generate questions (optionally from an upload) and import them into the course question bank | `topic`, `count`, `qtype`, `courseid` |

## Moodle Report Builder skills (`report.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `report.list_report_sources` | R0 | ✓ | List the datasources the Report Builder offers here: identifier, name, plugin, entities, counts | `component`, `include_entities`, `limit` |
| `report.describe_report_source` | R0 | ✓ | Columns, filters and conditions of one source with exact identifiers, types, aggregations and operator enums | `source`, `section`, `entity` |
| `report.search_reports` | R0 | ✓ | Existing custom reports the user may view or edit (id, name, source, audiences, schedules, links); hidden reports are counted, not listed | `reportquery`, `source`, `editable_only`, `mine_only` |
| `report.query_report` | R0 | ✓ | Row count of a report, optionally with filter values (stored as the user's own filter values, as the report view does); the observation carries count, headers and filters only, the rows are shown in the side panel | `reportid` or `reportquery`, `filters` |
| `report.get_report_details` | R0 | ✓ | One report as stored: columns with aggregation/sorting, conditions with values, filters, audiences (covered users as counts), schedules, row count; optional delivery diagnosis for a named person (audience membership, schedule state, account state, as facts); live report view in the side panel | `reportid` or `reportquery`, `include_row_count`, `diagnose_userquery` |
| `report.create_report` | **R2** | ✗ | Create a custom report: name, source, optional columns (heading, aggregation, sort), conditions with values, filters, unique rows, tags; source defaults when no columns are given; confirmable on a duplicate name | `name`, `source`, `columns`, `conditions`, `filters`, `override` |
| `report.update_report` | **R2** | ✗ | Change a report: add/remove/replace columns, heading, aggregation, sorting, position; conditions with values; filters; rename; unique rows | `reportid` or `reportquery`, `add_columns`, `set_columns`, `add_conditions`, … |
| `report.set_report_audience` | **R2** | ✗ | Add or remove an audience of any registered type (all users, admins, system role, cohort, named persons, plugin types); persons resolved by id/address/single name, reported as a count only | `reportid` or `reportquery`, `action`, `audience_type`, `roles`, `cohorts`, `userqueries`, `audienceid` |
| `report.schedule_report` | **R2** | ✗ | Create, change, enable, disable or send now a schedule: recipient audiences, format (enabled dataformats), recurrence, start time (ISO 8601), view-as, empty-report policy, subject, message | `reportid` or `reportquery`, `action`, `scheduleid`, `format`, `recurrence`, `starttime`, `viewas`, `if_empty` |

All run in the system context and decide access like core (`core_reportbuilder\permission`): the
discovery and authoring skills require the authoring capabilities (`moodle/reportbuilder:edit` or
`:editall`, plus core's per-report edit rule for changes), the lookup skills apply core's per-report
visibility (`can_view_report`). All are preview-capable: source cards, a per-entity table, report
cards, and — for details, create and update — the real Report Builder view rendered with its
render-time JS (`replace` preview, paging/sorting/filters work in the panel). Every recoverable
input problem (unknown source, report, column, condition, filter, operator, aggregation) is a
clarification with the alternatives as options; the authoring skills validate the whole definition
against the datasource in preflight (`report_definition_service`, `filter_value_codec`) and write
in one transaction. Report rows never reach the language model: `report.query_report` returns the count, the headers and the
filters, and the rows appear in the side panel only (decision D9 of Wunderbyte-GmbH/Wunderbyte-GmbH#2471).

---

## Booking skills (`mod_booking.*`)

### Read-only (R0)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.search_options` | Search/list options in the current instance | `query`, `when`, `limit` |
| `mod_booking.get_option_details` | Detailed info for one/more options | `optionid`, `optionids`, `optionquery`, `fields` |
| `mod_booking.list_option_properties` | List the option create/update schema fields | `question`, `scope` |
| `mod_booking.analyze_rules` | Read-only analysis of booking rules / notifications | `query`, `active_only`, `include_templates` |
| `mod_booking.diagnose_booking_issue` | Why a user can't book / isn't booked | `optionquery`, `userquery`, `issue` |
| `mod_booking.diagnose_cancellation_issue` | Why a user can't cancel | `optionquery`, `userquery` |
| `mod_booking.diagnose_user_booking` | Verbose status report for one person — status, when booked, completion, previous/cancelled bookings, submitted form data, and received messages. Option-scoped when an option is named, else an instance-wide overview (e.g. "how many options has X completed") | `userquery`/`userid`, `optionquery`/`optionid` (optional), `includemessages` |

### Scoped write (R1)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.configure_booking_instance` | Configure the booking activity instance (`action=list_fields`/`update`) | `action`, `changes` |

### Broad write (R2)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.create_option` | Create a standard booking option | `text`, option fields, `override` |
| `mod_booking.create_selflearning_option` | Create a self-learning option | `text`, `duration`, `maxanswers`, teacher fields |
| `mod_booking.create_slotbooking_option` | Create a slot/appointment option | `text`, `slot_*` fields |
| `mod_booking.update_option` | Update an existing option | `optionid`/`optionquery`, mutation fields, `override` |
| `mod_booking.update_option_trainer` | Assign/replace trainer(s) | `optionid`/`optionquery`, `teacherids`/`teacherquery`, `mode` |
| `mod_booking.bulk_update_options` | Update many options at once | `optionids`/`optionquery`/`apply_to_all`, mutation fields |
| `mod_booking.add_price_category` | Create a price category | `identifier`, `name`, `defaultvalue` |
| `mod_booking.create_rule_from_template` | Create a booking rule from a template | `templateid`/`templatequery`, `rulename`, `optionids` |
| `mod_booking.update_rule_from_template` | Update an existing rule | `ruleid`/`rulequery`, `templateid`, `active` |

### Irreversible / external (R3)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.book_users` | Book one or more users into an option via the standard bookit flow | `optionid`/`optionquery`, `bookusersquery`/`resolvedbookuserids`, `bookusersupdateexisting` |

`book_users` is the only R3 booking skill: it changes other users' booking state, so it
always requires manual confirmation and never auto-retries.

---

## Notes for skill authors

- **Discovery is semantic-only.** Skills are retrieved purely by embedding similarity (the
  description + `example_utterances` anchors). There is **no** lexical "always-include" tier — the
  `always_available` governance flag, `MANDATORY_SKILL_KEYWORDS` and `mandatory_on_trigger` /
  `intent_triggers` are all removed. If a skill is not retrieved, fix its anchors (utterances),
  never add a keyword. The single exception is `wizard.search_skills` (the RAG fallback), force-added
  to the catalog by `discovery_phase_service::ensure_search_skills_fallback()`.
- **`override`** appears on most mutating booking skills: it is how the agent confirms past a
  soft block (e.g. a duplicate-title `DOMAIN_CONFLICT`).
- **Option mutations** go through `mod_booking`'s `booking_option::update()` with form-style
  params; the executor and skills stay free of option-write internals.

_(Skill names, risk classes, and inputs were read from the skill classes; verify a specific
skill's full schema in its `get_schema()` before relying on an exact field name.)_

# Agent Conversations — Coverage Index

This file is the **single place to look** to understand which agent conversations are tested
and where to find or add more.

## How to Read This File

Each row in the index describes one concrete **conversation** between a user and the booking agent.
A conversation has:

- **Turn-by-turn flow** – what the user types and what the agent is expected to do
- **PHP test** – exact file + method that covers it
- **Status** – ✅ exists today / 🔲 planned / ❌ missing

### Two-Conversation Principle

Every task has **exactly two** target conversations:

| Type | What it tests |
|------|---------------|
| **Happy path** | User provides all required information → agent executes on first turn |
| **Verification loop** | User input is incomplete or ambiguous → agent asks a follow-up question → user answers → agent executes |

---

## Activation

Real-LLM tests are skipped by default.
Enable them with:

```bash
export BOOKING_AI_REAL_LLM=1
export BOOKING_TEST_AI_KEY=sk-...
export BOOKING_TEST_AI_MODEL=gpt-4o
export BOOKING_TEST_AI_ENDPOINT=https://api.openai.com/v1/chat/completions
```

Run a single task file:

```bash
BOOKING_AI_REAL_LLM=1 \
  vendor/bin/phpunit public/mod/booking/tests/agent/create_option_real_llm_test.php
```

Run all per-task files:

```bash
BOOKING_AI_REAL_LLM=1 \
  vendor/bin/phpunit public/mod/booking/tests/agent/ \
  --filter real_llm
```

---

## Conversation Index

### Task: `booking.create_option`
**File:** `create_option_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-01 | Happy path | "Create Piano Workshop, 12 spots, 2045-11-01 14:00" | `confirmation_request` → execute | DB: option exists with `maxanswers=12` |
| CONV-02 | Verification loop | Turn 1: "Create a new option" (no details)<br>Turn 2: "Piano Loop Test, 8 spots, 2045-11-02 10:00" | Turn 1: `clarification`<br>Turn 2: `confirmation_request` → execute | DB: option exists |
| CONV-15 | Confirmation gate | "Create 'Confirmation Gate Test CONV15', 5 spots, 2045-12-01 14:00" | **never** `execution_result` (must be `confirmation_request` or `clarification`) | DB: option does NOT exist before user confirms |

---

### Task: `booking.update_option`
**File:** `update_option_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-03 | Happy path | "Increase capacity of Piano Update Test to 20 spots" (option pre-created) | `confirmation_request` → execute | DB: `maxanswers=20` |
| CONV-04 | Verification loop | Turn 1: "Update the capacity to 25" (no option name)<br>Turn 2: option name provided | Turn 1: `clarification`<br>Turn 2: `confirmation_request` → execute | DB: `maxanswers=25` |
| CONV-16 | Multi-step workflow | (Setup: option pre-created) "Increase capacity of 'LLM Workflow CONV16' to 30 spots." | `confirmation_request` → execute | DB: `maxanswers=30`, title unchanged |
| CONV-17 | Tool failure | "Change the seats of 'This Option Does Not Exist XYZ999' to 99." | `clarification` or `error` with non-empty message | No crash, non-empty message |

---

### Task: `booking.book_users`
**File:** `book_users_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-05 | Happy path | "Book Valentina Booker (userid: X) into option Y" | `confirmation_request` → execute | `booking_answers`: row exists with `waitinglist=0` (booked) |
| CONV-06 | Verification loop | Turn 1: "Book a user into an option" (no specifics)<br>Turn 2: userid + optionid provided | Turn 1: `clarification`<br>Turn 2: `confirmation_request` → execute | `booking_answers`: row exists |

---

### Task: `booking.diagnose_booking_issue`
**File:** `diagnose_booking_issue_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-07 | Happy path (can book) | "Can user X book option Y?" (option has free spots, user enrolled) | auto-execute `diagnose_booking_issue` | `diagnosis.userstatus = 'notbooked'`, no hard blockers |
| CONV-08 | Loop (cannot book) | Turn 1: "Why can't someone book?" (no user/option)<br>Turn 2: userid + optionid for fully-booked option | Turn 1: `clarification`<br>Turn 2: auto-execute | `diagnosis` mentions `fullybooked` |

---

### Task: `booking.diagnose_cancellation_issue`
**File:** `diagnose_cancellation_issue_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-09 | Happy path | "Can user X cancel their booking for option Y?" (user is booked) | auto-execute `diagnose_cancellation_issue` | Response is `executed`, `diagnosis` returned |
| CONV-10 | Verification loop | Turn 1: "Why can the user not cancel?" (no specifics)<br>Turn 2: userid + optionid | Turn 1: `clarification`<br>Turn 2: auto-execute | `diagnosis` returned with reasons |

---

### Task: `booking.bulk_update_options`
**File:** `bulk_update_options_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-11 | Happy path | "Set all 'Bulk Piano' options to 8 seats" (3 options pre-created) | `confirmation_request` → execute all commands | DB: all 3 options have `maxanswers=8` |
| CONV-12 | Verification loop | Turn 1: "Update all options" (no filter, no value)<br>Turn 2: specific filter + value | Turn 1: `clarification`<br>Turn 2: `confirmation_request` → execute | DB: matched options updated |

---

### Task: `booking.search_options`
**File:** `search_options_real_llm_test.php`

| ID | Type | User Says | Agent Does | Verified |
|----|------|-----------|-----------|---------|
| CONV-13 | Happy path (auto-execute) | "Show me all Search Test Kurs options" | auto-execute (read-only → no confirmation) → `execution_result` | Response contains both options |
| CONV-14 | Multi-turn follow-up | Turn 1: search for "Search Multi Kurs"<br>Turn 2: "Which of those have more than 5 free spots?" | Turn 1: `execution_result`<br>Turn 2: non-empty response referencing free spots | Turn 2 message is non-empty |

---

## HTTP API Smoke Tests

These tests live in **`agent_real_llm_test.php`** and test a **different layer** from the
AgentRuntime tests above. They call the Moodle webservice endpoints directly
(`ai_send_message`, `ai_confirm_run`, `ai_poll_run_status`) and verify that the HTTP layer
routes, serialises, and responds correctly.

They are intentionally **not** merged into the per-task files: they don't test conversation
logic but infrastructure plumbing.

| ID | Method | What it covers |
|----|--------|----------------|
| SMOKE-01 | `test_real_llm_create_prompt_smoke` | HTTP endpoint accepts a create prompt and returns a valid JSON response |
| SMOKE-02 | `test_real_llm_confirm_run_smoke` | `ai_confirm_run` accepts a run-id and confirms execution |
| SMOKE-03 | `test_real_llm_search_prompt_smoke` | HTTP endpoint accepts a search prompt and returns a valid JSON response |

---

## Unit Tests (no LLM required)

**`agent_runtime_unit_test.php`** contains pure unit tests for value objects that run in
every CI pass without any environment variables:

| Class under test | Methods tested |
|-----------------|----------------|
| `task_result` | `test_task_result_ok_is_success`, `test_task_result_failure_has_error` |
| `slot_booking_normalizer` | `test_slot_booking_normalizer_skips_non_slot_tasks`, `test_slot_booking_normalizer_sets_slot_fields`, `test_slot_booking_normalizer_selflearning_no_limit` |

---

## Task Coverage Summary

| Task | Covered CONVs | Status |
|------|---------------|--------|
| `booking.create_option` | CONV-01 (happy path), CONV-02 (verification loop), CONV-15 (confirmation gate) | ✅ |
| `booking.update_option` | CONV-03 (happy path), CONV-04 (verification loop), CONV-16 (workflow), CONV-17 (tool failure) | ✅ |
| `booking.book_users` | CONV-05 (happy path), CONV-06 (verification loop) | ✅ |
| `booking.diagnose_booking_issue` | CONV-07 (happy path), CONV-08 (verification loop) | ✅ |
| `booking.diagnose_cancellation_issue` | CONV-09 (happy path), CONV-10 (verification loop) | ✅ |
| `booking.bulk_update_options` | CONV-11 (happy path), CONV-12 (verification loop) | ✅ |
| `booking.search_options` | CONV-13 (auto-execute), CONV-14 (multi-turn follow-up) | ✅ |
| `booking.search_users` | — | 🔲 planned |
| `booking.get_current_user` | — | 🔲 planned (trivial) |
| `booking.list_actions` | — | 🔲 planned (trivial) |
| `booking.explain_docs_topic` | — | 🔲 planned |
| `booking.add_price_category` | — | 🔲 planned |
| `booking.create_user` | — | 🔲 planned |
| `booking.list_option_properties` | — | 🔲 planned |
| `booking.search_courses` | — | 🔲 planned |

---

## Adding a New Conversation

1. Pick the next free CONV-ID from this file.
2. Create (or open) the per-task file: `{taskname}_real_llm_test.php`.
3. Add the PHP test method following the boilerplate in any existing per-task file.
4. Update the table above (both the task section and the summary).
5. Run the new test with `BOOKING_AI_REAL_LLM=1 vendor/bin/phpunit {file}` to verify it works.

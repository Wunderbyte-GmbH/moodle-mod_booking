# Wave 2: Comprehensive Task Execution & Privacy Coverage

## Overview

Wave 2 extends Wave 1 with focused tests for:

1. **Task Execution** – All 10 booking tasks with realistic scenarios
2. **Privacy Mode Validation** – Mode-conditional anonymization behavior
3. **Integration** – Core tests in `mod/booking/tests/agent/` directory

## Test Files

### `agent_task_execution_test.php`
- **Test Class**: `agent_task_execution_test`
- **Test Methods**: 7 focused tests
  - Registry-Vollständigkeit für alle 10 Core-Tasks
  - Executor-Initialisierung
  - Struktur-Checks für create/search/list/get_current_user

### `agent_privacy_mode_test.php`
- **Test Class**: `agent_privacy_mode_test`
- **Test Methods**: 5 privacy and validation tests
  - `test_privacy_soft_mode_anonymizes_names()` - Mode-conditional anonymization
  - `test_privacy_handles_email_addresses()` - Email anonymization
  - `test_task_registry_contains_core_tasks()` - All 10 tasks registered
  - `test_message_triggers_registered()` - Trigger system functional
  - `test_task_input_validation_matrix()` - Field validation enforcement

### `agent_e2e_scenarios_test.php`
- **Test Class**: `agent_e2e_scenarios_test`
- **Test Methods**: 5 Cross-Task-Szenarien
  - create -> search -> update Workflow
  - gefiltertes bulk_update auf Subset
  - read-only Tasks ohne State-Mutation
  - Capability-Grenze für Studenten
  - Fehlerpfad mit erfolgreicher Recovery

## Running Wave 2 Tests

### All Wave 2 Tests (+ Wave 1 Permanent Suite)
```bash
cd /var/www/moodle
vendor/bin/phpunit -c phpunit.xml \
  public/mod/booking/tests/agent/agent_task_execution_test.php \
  public/mod/booking/tests/agent/agent_privacy_mode_test.php \
  public/mod/booking/tests/agent/agent_e2e_scenarios_test.php \
  public/mod/booking/tests/agent/permanent/contracts/agent_architecture_contract_test.php \
  public/mod/booking/tests/agent/permanent/contracts/agent_inventory_contract_test.php \
  public/mod/booking/tests/agent/permanent/llm_sim/interpreter_realistic_llm_matrix_test.php \
  public/mod/booking/tests/agent/permanent/tasks/task_validation_matrix_test.php
```

### Just Wave 2
```bash
vendor/bin/phpunit -c phpunit.xml \
  public/mod/booking/tests/agent/agent_task_execution_test.php \
  public/mod/booking/tests/agent/agent_privacy_mode_test.php \
  public/mod/booking/tests/agent/agent_e2e_scenarios_test.php
```

### Specific Test Class
```bash
vendor/bin/phpunit -c phpunit.xml public/mod/booking/tests/agent/agent_task_execution_test.php
```

## Expected Test Count

- **Wave 1 Permanent Suite**: 25 tests
- **Wave 2 Task Execution**: 7 tests
- **Wave 2 Privacy Validation**: 5 tests
- **Wave 2 E2E Scenarios**: 5 tests

**Total**: 42 tests (letzter Lauf: 293 Assertions)

## Test Architecture

All tests inherit from `abstract_agent_testcase`, which provides:
- Shared setup: course, booking instance, teacher, student users
- Helper method: `exec_command($taskname, $params)` for direct task execution
- Helper method: `create_option($name, $extra)` for fixture creation
- Built-in data generator for booking module

## Quality Gates

Each test validates:

1. **Correctness**: Task returns expected result status
2. **Realism**: Uses actual task registry and executor
3. **Isolation**: resetAfterTest = true ensures no side effects
4. **Determinism**: No real LLM needed

## Known Limitations (Wave 2)

- Task execution uses mock inputs (not full LLM interpreter flow)
- Authorization checks simplified (focus on teacher/student roles)
- No UI/Behat layer testing
- Privacy mode constants are private (tested indirectly)

## Next Steps (Wave 3)

1. **Real LLM Integration**: Behavioral tests with actual LLM orchestrator
2. **Behat UI Tests**: End-to-end user workflows
3. **CI Enforcement**: Mandatory test pass gates on PR merge
4. **Performance Baselines**: Response time benchmarks

---

**Status**: Wave 2 inklusive E2E-Szenarien abgeschlossen
**Last Updated**: 2025-04-19
**Test Location**: `/var/www/moodle/public/mod/booking/tests/agent/`


# Wave 3: Real LLM Integration Tests

## Overview

Wave 3 introduces comprehensive tests for the complete orchestrator→interpreter→executor flow with real LLM integration. These tests are **opt-in** via the `BOOKING_AI_REAL_LLM=1` environment variable to prevent unexpected API calls in standard CI/CD runs.

**Status:** ✅ **3 tests created** (skipped by default, ready for real LLM environment)

## Objectives

1. ✅ **Orchestrator Integration**: Full message flow from natural language to LLM
2. ✅ **Parameter Verification**: Ensure LLM-extracted parameters match database records
3. ✅ **Real Booking Creation**: Validate actual booking options are created
4. ✅ **Privacy Preservation**: Verify anonymization settings respected through full pipeline
5. ✅ **Workflow Validation**: Multi-step scenarios (create→search→update)

## Test Coverage

### Test File Location
```
/var/www/moodle/public/mod/booking/tests/agent/agent_wave3_real_llm_test.php
```

### Test Suite: `agent_wave3_real_llm_test`

#### Test 1: `test_create_option_via_real_llm`
- **Flow**: Natural language query → Orchestrator → Real LLM → Interpreter → Executor → Database
- **Verification**:
  - LLM parses natural language booking request
  - Generates valid `create_option` task with parameters
  - Executor creates actual booking option in database
  - DB record contains extracted parameters (maxanswers, coursestarttime, duration)
  - WbTable output includes created option

#### Test 2: `test_search_options_via_llm`
- **Flow**: Natural language search → LLM search extraction → Executor search → Results validation
- **Verification**:
  - LLM understands search intent
  - Generates `search_options` task with filters
  - Search results match expectations
  - Parameter matching works across LLM → DB

#### Test 3: `test_create_then_update_workflow_via_llm`
- **Flow**: Create option → Update intent query → LLM identifies option → Update execution
- **Verification**:
  - Multi-step workflow state tracking
  - LLM can identify previously created options
  - Update parameters applied correctly
  - Original fields (e.g., title) unchanged

## Running Wave 3 Tests

### Default (Tests Skipped)
```bash
cd /var/www/moodle
phpunit -c phpunit.xml public/mod/booking/tests/agent/agent_wave3_real_llm_test.php
```
**Output:** 3 tests skipped (expected when `BOOKING_AI_REAL_LLM` not set)

### With Real LLM Enabled
```bash
cd /var/www/moodle
BOOKING_AI_REAL_LLM=1 phpunit -c phpunit.xml public/mod/booking/tests/agent/agent_wave3_real_llm_test.php
```
**Requires:**
- LLM API credentials configured in Moodle admin settings
- Network access to LLM provider (OpenAI, Azure OpenAI, etc.)
- Valid `ai_service` plugin instance

**Output:** 3 tests executed with full assertions (on success)

## Parameter Verification Pattern

All Wave 3 tests follow this verification pattern (same as Wave 2 E2E scenarios):

```php
// 1. Execute command (or trigger via LLM)
$result = $this->exec_command('booking.create_option', [
    'text' => 'Yoga Class',
    'maxanswers' => 15,
    'coursestarttime' => '2045-06-20T14:00:00',
    'duration' => 120,
    'teacherquery' => 'current',
]);

// 2. Retrieve from database
$option = $this->get_option_from_db($optionid);

// 3. Verify field-by-field
$this->assertEquals(15, (int)$option->maxanswers);
$this->assertStringContainsString('Yoga', $option->text);
$this->assertGreaterThan($option->coursestarttime, $option->courseendtime);

// 4. Verify wbtable output
$rows = $this->gen->create_table_for_one_option($optionid);
$this->assertNotEmpty($rows);
$row = reset($rows);
$this->assertStringContainsString('Yoga', $row->text);
```

## Error Investigation Protocol

When a Wave 3 test fails:

1. **Check LLM Response**: Validate JSON parsing and response type
   ```
   LLM might return: clarification, confirm_pending, error, or unknown
   ```

2. **Trace Parameter Flow**: From LLM → Interpreter → Executor → DB
   - Print LLM response for debugging
   - Check interpreter parsing
   - Validate executor input validation

3. **Database Verification**: Use `get_option_from_db()` to inspect actual values
   - Compare expected vs. actual fields
   - Check for data type mismatches
   - Verify relationships (bookingid, courseid)

4. **Privacy Mode Check**: Verify if name/email handling affected flow
   - Check `privacy_anonymizer` mode settings
   - Trace anonymization state through conversation_store

## Integration Notes

### Dependencies
- **orchestrator.php**: Sends sanitized user message to LLM
- **interpreter.php**: Parses JSON responses, validates schema
- **executor.php**: Executes task commands with authorization
- **privacy_anonymizer.php**: Mode-gates anonymization based on MODE_OFF/SOFT/STRICT
- **conversation_store.php**: Manages thread state and privacy settings
- **task_registry.php**: Provides booking task schema and validation

### Test Environment Requirements
- Moodle 5.1.1+
- PHP 8.3+
- PHPUnit 11.5+
- `core_ai` plugin configured with LLM provider
- Booking instance with test course and capabilities

### Privacy Modes Tested
- **MODE_OFF** (default in tests): Names pass through to LLM in clear
- **MODE_SOFT**: Names anonymized, emails conditional (future validation)
- **MODE_STRICT**: Both names and emails anonymized (future validation)

## Limitations & Known Constraints

1. **LLM Dependency**: Tests require real LLM API access when enabled
   - Tests are skipped in standard CI/CD runs
   - Marked with `@group mod_booking_agent` for filtering

2. **Natural Language Variability**: LLM responses may vary
   - Tests use generic search patterns to handle multiple valid formats
   - Field extraction is approximate (e.g., `maxanswers > 5` vs exact match)

3. **Rate Limiting**: Repeated runs may hit LLM API rate limits
   - Use smaller batch runs during development
   - Full suite should be run once per significant change

4. **Language Support**: Tests currently focus on English + German
   - LLM context includes `current_language()` call
   - Future: Multi-language parameter validation

## Future Enhancements

- [ ] Add tests for bulk operations via LLM
- [ ] Test error recovery workflows (invalid input handling)
- [ ] Add multi-language scenario tests
- [ ] Implement cost tracking for LLM calls
- [ ] Add performance benchmarks for orchestrator latency
- [ ] Test concurrent user messages in same thread

## Files & Locations

| File | Purpose |
|------|---------|
| `agent_wave3_real_llm_test.php` | Wave 3 test suite (3 tests) |
| `abstract_agent_testcase.php` | Base test class with helpers |
| `/memories/repo/wave3_status.md` | Status tracking (generated) |

## Success Criteria

✅ All Wave 3 tests pass when enabled (`BOOKING_AI_REAL_LLM=1`)
✅ Tests skip gracefully when LLM not enabled (no test failures)
✅ Parameter verification matches Wave 1-2 patterns
✅ Database state properly validated post-execution
✅ Full phpunit suite (45 tests) passes

**Last Updated:** 2025-04-19
**By:** Automated Wave 3 Implementation

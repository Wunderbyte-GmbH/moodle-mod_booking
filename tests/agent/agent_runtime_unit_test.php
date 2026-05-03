<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for AgentRuntime value objects: TaskResult and SlotBookingNormalizer.
 *
 * These tests do NOT require a live LLM and run in every CI pass without any
 * environment variables.
 *
 * Real-LLM conversation tests have been consolidated into per-task files
 * (see AGENT_CONVERSATIONS.md for the full index).
 *
 * @package   mod_booking
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\booking\support\slot_booking_normalizer;
use mod_booking\local\wbagent\task_result;

/**
 * Unit tests for TaskResult and SlotBookingNormalizer value objects.
 *
 * No LLM required — runs always.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @covers \mod_booking\local\wbagent\task_result
 * @covers \mod_booking\local\wbagent\booking\support\slot_booking_normalizer
 */
final class agent_runtime_unit_test extends abstract_agent_testcase {

    // No LLM skip in setUp — these unit tests run in every CI pass.

    // -------------------------------------------------------------------------
    // TaskResult unit tests.

    /**
     * task_result::ok() produces a success result with data.
     *
     * @runInSeparateProcess
     */
    public function test_task_result_ok_is_success(): void {
        $this->resetAfterTest();
        $result = task_result::ok(['optionid' => 42, 'status' => 'executed']);

        $this->assertTrue($result->is_success());
        $this->assertEquals(42, $result->get_data()['optionid']);
        $this->assertNull($result->get_error());
        $this->assertEquals('', $result->get_error_code());
        $this->assertEquals('', $result->get_error_message());

        $legacy = $result->to_legacy_array();
        $this->assertEquals('executed', $legacy['status']);
        $this->assertEquals(42, $legacy['optionid']);
    }

    /**
     * task_result::failure() produces a structured error.
     *
     * @runInSeparateProcess
     */
    public function test_task_result_failure_has_error(): void {
        $this->resetAfterTest();
        $result = task_result::failure('OPTION_NOT_FOUND', 'No option matched "Yoga"', ['optionquery' => 'Yoga']);

        $this->assertFalse($result->is_success());
        $this->assertEmpty($result->get_data());
        $this->assertNotNull($result->get_error());
        $this->assertEquals('OPTION_NOT_FOUND', $result->get_error_code());
        $this->assertEquals('No option matched "Yoga"', $result->get_error_message());
        $this->assertEquals('Yoga', $result->get_error()['metadata']['optionquery']);

        $legacy = $result->to_legacy_array();
        $this->assertEquals('error', $legacy['status']);
        $this->assertStringContainsString('Yoga', $legacy['detail']);
    }

    // -------------------------------------------------------------------------
    // SlotBookingNormalizer unit tests.

    /**
     * Non-slot tasks are returned unchanged.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_skips_non_slot_tasks(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = ['text' => 'test', 'optiontype' => '0'];
        $result = $normalizer->normalize('booking.search_options', $input);
        $this->assertSame($input, $result, 'Non-slot tasks must be returned unchanged');

        $result2 = $normalizer->normalize('booking.create_option', $input);
        $this->assertSame($input, $result2, 'create_option without slot signals must be returned unchanged');
    }

    /**
     * Slot-booking input is normalized: slot_enabled, slot_type, day flags.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_sets_slot_fields(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = [
            'optiontype'                     => 'slot',
            'slot_duration_minutes'          => 30,
            'slot_max_participants_per_slot' => 3,
            'slot_valid_from'                => '2045-01-01',
            'slot_valid_until'               => '2045-12-31',
            'slot_day_1'                     => 1,
            'slot_day_3'                     => 1,
        ];

        $result = $normalizer->normalize('booking.create_option', $input);

        $this->assertTrue((bool)$result['slot_enabled'], 'slot_enabled must be true');
        $this->assertNotEmpty($result['slot_type'], 'slot_type must be set');
        $this->assertEquals(1, $result['slot_day_1']);
        $this->assertEquals(0, $result['slot_day_2']);
        $this->assertEquals(1, $result['slot_day_3']);
        $this->assertEquals(3, $result['slot_max_participants_per_slot']);
        $this->assertEquals(30, $result['slot_duration_minutes']);
        $this->assertIsInt($result['slot_valid_from'], 'slot_valid_from should be unix timestamp');
        $this->assertGreaterThan(0, $result['slot_valid_from']);
    }

    /**
     * Self-learning "no limit" phrase sets maxanswers to 999999.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_selflearning_no_limit(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = [
            'optiontype' => 'selflearning',
            'text'       => 'Ein Kurs ohne limit an Teilnehmern',
        ];

        $result = $normalizer->normalize('booking.create_option', $input);
        $this->assertEquals(999999, $result['maxanswers']);
    }
}

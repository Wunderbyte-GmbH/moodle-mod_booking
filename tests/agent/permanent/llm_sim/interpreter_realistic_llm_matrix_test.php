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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Realistic simulated LLM response matrix tests for interpreter.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\task_registry;

/**
 * Deterministic tests with realistic (but simulated) LLM outputs.
 *
 * @coversNothing
 */
final class interpreter_realistic_llm_matrix_test extends advanced_testcase {
    /** @var int */
    private int $cmid;

    /** @var interpreter */
    private interpreter $interpreter;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'LLM Matrix Booking',
        ]);

        $this->cmid = (int)$booking->cmid;
        $this->interpreter = new interpreter(task_registry::make_default());
    }

    /**
     * Simulated realistic LLM payloads should map to stable response types.
     *
     * @dataProvider provide_realistic_llm_payloads
     * @param string $raw
     * @param string $expectedtype
     */
    public function test_realistic_simulated_llm_payloads(string $raw, string $expectedtype): void {
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);

        $this->assertSame($expectedtype, (string)($result['response_type'] ?? ''));
        $this->assertArrayHasKey('commands', $result);
        $this->assertArrayHasKey('ambiguities', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * Realistic simulated payload matrix.
     *
     * @return array
     */
    public static function provide_realistic_llm_payloads(): array {
        $fence = chr(96) . chr(96) . chr(96);

        return [
            'clarification_german' => [
                json_encode([
                    'response_type' => 'clarification',
                    'message' => 'Bitte gib mir noch die maximale Teilnehmerzahl.',
                    'used_triggers' => ['booking.collect_missing_fields'],
                ]),
                'clarification',
            ],
            'markdown_fenced_json' => [
                $fence . "json\n" . json_encode([
                    'response_type' => 'clarification',
                    'message' => 'Please clarify the teacher.',
                ]) . "\n" . $fence,
                'clarification',
            ],
            'unknown_response_type' => [
                json_encode([
                    'response_type' => 'proceed_now',
                    'message' => 'Execute directly.',
                ]),
                'error',
            ],
            'valid_confirmation_create_option' => [
                json_encode([
                    'response_type' => 'confirmation_request',
                    'message' => 'Please confirm creating this option.',
                    'commands' => [[
                        'task' => 'booking.create_option',
                        'version' => 1,
                        'input' => [
                            'text' => 'Yoga Abend',
                            'maxanswers' => 20,
                            'coursestarttime' => '2036-09-01T20:00:00',
                            'duration' => 7200,
                            'location' => 'Studio A',
                            'teacherquery' => 'the current user',
                        ],
                    ]],
                ]),
                'confirmation_request',
            ],
            'missing_title_required' => [
                json_encode([
                    'response_type' => 'confirmation_request',
                    'message' => 'Create option quickly.',
                    'commands' => [[
                        'task' => 'booking.create_option',
                        'version' => 1,
                        'input' => [],
                    ]],
                ]),
                'clarification',
            ],
            'disallowed_task_in_command' => [
                json_encode([
                    'response_type' => 'confirmation_request',
                    'message' => 'Run dangerous task.',
                    'commands' => [[
                        'task' => 'system.shell_exec',
                        'version' => 1,
                        'input' => [],
                    ]],
                ]),
                'error',
            ],
        ];
    }
}

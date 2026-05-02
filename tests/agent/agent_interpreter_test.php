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
 * Tests for the AI agent interpreter pipeline.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;
use mod_booking\local\wbagent\task_registry;

/**
 * Tests for the AI agent interpreter.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_interpreter_test extends advanced_testcase {
    /** @var task_registry */
    private task_registry $registry;

    /** @var interpreter */
    private interpreter $interpreter;

    /** @var int */
    private int $cmid;

    /**
     * Set up test registry and interpreter.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Interpreter Test Booking',
        ]);
        $this->cmid = (int)$booking->cmid;

        $this->registry    = task_registry::make_default();
        $this->interpreter = new interpreter($this->registry);
    }

    /**
     * Test that invalid JSON produces an error response.
     */
    public function test_invalid_json_returns_error(): void {
        $result = $this->interpreter->interpret('This is not JSON at all.', $this->cmid, 1);
        $this->assertEquals('error', $result['response_type']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test that a clarification response is passed through.
     */
    public function test_clarification_passthrough(): void {
        $raw = json_encode([
            'response_type' => 'clarification',
            'message'       => 'Could you clarify the date?',
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('clarification', $result['response_type']);
        $this->assertStringContainsString('clarify', $result['message']);
        $this->assertEmpty($result['commands']);
    }

    /**
     * Test that an unknown response_type is rejected.
     */
    public function test_unknown_response_type_is_rejected(): void {
        $raw = json_encode([
            'response_type' => 'do_something_evil',
            'message'       => 'hacked',
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('error', $result['response_type']);
    }

    /**
     * Test that a confirmation_request with a disallowed task is rejected.
     */
    public function test_disallowed_task_is_rejected(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message'       => 'Create option.',
            'commands'      => [
                ['task' => 'system.delete_all', 'version' => 1, 'input' => []],
            ],
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('error', $result['response_type']);
    }

    /**
     * Test that a valid create_option confirmation request is accepted.
     */
    public function test_valid_create_option_confirmation_request(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message'       => 'I will create "My Option".',
            'commands'      => [
                ['task' => 'booking.create_option', 'version' => 1, 'input' => [
                    'text' => 'My Option',
                    'maxanswers' => 20,
                    'coursestarttime' => '2036-06-01T09:00:00',
                    'duration' => 3600,
                    'location' => 'Room A',
                    'teacherquery' => 'admin',
                ]],
            ],
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('confirmation_request', $result['response_type']);
        $this->assertCount(1, $result['commands']);
        $this->assertEquals('booking.create_option', $result['commands'][0]['task']);
    }

    /**
     * Test that a create_option missing required "text" field raises an error.
     */
    public function test_create_option_missing_text_raises_error(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message'       => 'Create.',
            'commands'      => [
                ['task' => 'booking.create_option', 'version' => 1, 'input' => []],
            ],
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('clarification', $result['response_type']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Test that an update_option without optionid produces an ambiguity.
     */
    public function test_update_option_without_optionid_produces_ambiguity(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message'       => 'Update option.',
            'commands'      => [
                ['task' => 'booking.update_option', 'version' => 1, 'input' => ['location' => 'Room 1']],
            ],
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        // Should become a clarification because of the ambiguity.
        $this->assertEquals('clarification', $result['response_type']);
        $this->assertNotEmpty($result['ambiguities']);
    }

    /**
     * explain_docs_topic should hydrate missing question from parsed message heuristics.
     */
    public function test_explain_docs_topic_hydrates_missing_question_from_message(): void {
        // LLM returns a trigger-ID as response_type (a known LLM misbehavior) with no input.
        // The orchestrator passes the last user message text as the 4th parameter.
        // The interpreter must: (a) map the trigger-ID to the correct task name, and
        // (b) hydrate the missing 'question' field from the user message.
        $raw = json_encode([
            'response_type' => 'booking.explain_docs_topic_feature_help',
            'lang' => 'en',
            'used_triggers' => ['booking.explain_docs_topic_feature_help'],
            'message' => 'Executing.',
            'input' => [],
        ]);

        $lastusermessage = 'How do I send messages in the booking assistant?';
        $result = $this->interpreter->interpret($raw, $this->cmid, 1, $lastusermessage);

        $this->assertSame('task_call', $result['response_type']);
        $this->assertNotEmpty($result['commands']);
        $this->assertSame('booking.explain_docs_topic', (string)($result['commands'][0]['task'] ?? ''));
        $this->assertSame(
            $lastusermessage,
            (string)($result['commands'][0]['input']['question'] ?? '')
        );
    }

    /**
     * Structured task ambiguity options should be propagated to interpreter output.
     *
     * Uses a fake task that returns both ambiguities and ambiguity_options from validate()
     * to verify the interpreter correctly propagates them to a clarification response.
     */
    public function test_ambiguity_options_are_propagated_from_task_validation(): void {
        // Build a fake task whose validate() returns ambiguities + ambiguity_options.
        $faketask = new class implements task_interface {
            /**
             * Get task name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'test.ambiguous_docs';
            }

            /**
             * Get schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['name' => 'test.ambiguous_docs', 'description' => 'Fake ambiguous task.', 'input_schema' => []];
            }

            /**
             * Validate — always returns ambiguities with structured ambiguity_options.
             *
             * @param array $input
             * @param int $cmid
             * @return array
             */
            public function validate(array $input, int $cmid): array {
                return [
                    'valid'            => false,
                    'errors'           => [],
                    'ambiguities'      => ['Please select one of the matching topics.'],
                    'ambiguity_options' => [
                        ['id' => 'opt1', 'label' => 'Booking Options Overview', 'query' => 'bookotheroptions'],
                        ['id' => 'opt2', 'label' => 'Cancel Booking Action', 'query' => 'cancelbooking'],
                    ],
                ];
            }

            /**
             * Execute.
             *
             * @param array $input
             * @param int $cmid
             * @param int $userid
             * @return array
             */
            public function execute(array $input, int $cmid, int $userid): array {
                return ['status' => 'executed', 'detail' => '', 'resultid' => null];
            }

            /**
             * Is read only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return true;
            }
        };

        $fakeprovider = new class ($faketask) implements task_provider_interface {
            /** @var task_interface */
            private task_interface $task;

            /**
             * Constructor.
             *
             * @param task_interface $task
             */
            public function __construct(task_interface $task) {
                $this->task = $task;
            }

            /**
             * Get component.
             *
             * @return string
             */
            public function get_component(): string {
                return 'test_ambiguous_provider';
            }

            /**
             * Get tasks.
             *
             * @return array
             */
            public function get_tasks(): array {
                return [$this->task];
            }

            /**
             * Get contextual prompt packs.
             *
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }
        };

        $registry = new task_registry();
        $registry->register($fakeprovider);
        $interpreter = new interpreter($registry);

        $raw = json_encode([
            'response_type' => 'task_call',
            'message'       => 'Let me check the docs topic.',
            'commands'      => [
                [
                    'task'    => 'test.ambiguous_docs',
                    'version' => 1,
                    'input'   => ['question' => 'What is bookotheroptions?'],
                ],
            ],
        ]);

        $result = $interpreter->interpret($raw, $this->cmid, 1);

        $this->assertEquals('clarification', $result['response_type']);
        $this->assertNotEmpty($result['ambiguities']);
        $this->assertArrayHasKey('ambiguity_options', $result);
        $this->assertNotEmpty($result['ambiguity_options']);

        $first = $result['ambiguity_options'][0] ?? [];
        $this->assertNotSame('', trim((string)($first['label'] ?? '')));
        $this->assertNotSame('', trim((string)($first['query'] ?? '')));
        $this->assertSame('test.ambiguous_docs', (string)($first['task'] ?? ''));
    }

    /**
     * Test that JSON wrapped in markdown fences is parsed correctly.
     */
    public function test_markdown_fence_is_stripped(): void {
        $fence = chr(96) . chr(96) . chr(96);
        $raw = $fence . "json\n" . json_encode([
            'response_type' => 'clarification',
            'message'       => 'Please clarify.',
        ]) . "\n" . $fence;
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('clarification', $result['response_type']);
    }

    /**
     * Test that HTML tags in the message are stripped.
     */
    public function test_html_tags_stripped_from_message(): void {
        $raw = json_encode([
            'response_type' => 'clarification',
            'message'       => '<script>alert("xss")</script>Please clarify.',
        ]);
        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertStringNotContainsString('<script>', $result['message']);
    }

    /**
     * Self-reference teacherquery values are canonicalized by the interpreter.
     */
    public function test_teacherquery_self_reference_is_canonicalized(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message' => 'Create option with me as teacher.',
            'commands' => [
                [
                    'task' => 'booking.create_option',
                    'version' => 1,
                    'input' => [
                        'text' => 'My Option',
                        'maxanswers' => 20,
                        'coursestarttime' => '2036-06-01T09:00:00',
                        'duration' => 3600,
                        'location' => 'Room A',
                        'teacherquery' => 'the current user',
                    ],
                ],
            ],
        ]);

        $result = $this->interpreter->interpret($raw, $this->cmid, 1);
        $this->assertEquals('confirmation_request', $result['response_type']);
        $this->assertEquals('__current_user__', $result['commands'][0]['input']['teacherquery'] ?? null);
    }

    /**
     * Confirmable task issues must stay confirmation_request so pending-intent flow can continue.
     */
    public function test_confirmable_issue_returns_confirmation_request_with_commands(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message' => 'Please confirm creating this option if location is missing.',
            'commands' => [
                [
                    'task' => 'booking.create_option',
                    'version' => 1,
                    'input' => [
                        'text' => 'Ort ggf. anlegen',
                        'maxanswers' => 20,
                        'coursestarttime' => '2036-06-04T12:00:00',
                        'duration' => 3600,
                        'location' => 'Nicht sicherer Raumname',
                        'teacherquery' => 'admin',
                    ],
                ],
            ],
        ]);

        $result = $this->interpreter->interpret($raw, $this->cmid, 1);

        $this->assertEquals('confirmation_request', $result['response_type']);
        $this->assertCount(1, $result['commands']);
        $this->assertEquals('booking.create_option', $result['commands'][0]['task'] ?? '');
    }

    /**
     * Missing location should become a confirmation_request with location/address overrides.
     */
    public function test_missing_location_becomes_confirmable_with_override(): void {
        $raw = json_encode([
            'response_type' => 'confirmation_request',
            'message' => 'Create option without location.',
            'commands' => [
                [
                    'task' => 'booking.create_option',
                    'version' => 1,
                    'input' => [
                        'text' => 'Meine Veranstaltung um 12',
                        'maxanswers' => 20,
                        'coursestarttime' => '2036-06-04T12:00:00',
                        'duration' => 3600,
                        'teacherquery' => 'the current user',
                    ],
                ],
            ],
        ]);

        $result = $this->interpreter->interpret($raw, $this->cmid, 1);

        $this->assertEquals('confirmation_request', $result['response_type']);
        $this->assertCount(1, $result['commands']);
        $overrides = $result['commands'][0]['input']['override'] ?? [];
        $this->assertContains('location', $overrides);
        $this->assertContains('address', $overrides);
    }

    /**
     * Teacher guidance should map self references to teacherquery instead of asking for e-mail.
     */
    public function test_create_option_teacher_guidance_mentions_self_reference_mapping(): void {
        $task = new create_option_task();
        $packs = $task->get_contextual_prompt_packs();

        $teacherpack = array_values(array_filter($packs, static function (array $pack): bool {
            return ($pack['id'] ?? '') === 'booking.course_teacher';
        }));

        $this->assertCount(1, $teacherpack);
        $guidance = implode("\n", $teacherpack[0]['guidance'] ?? []);

        $this->assertStringContainsString('assign themselves as teacher', $guidance);
        $this->assertStringContainsString('instead of asking for an e-mail address', $guidance);
    }
}

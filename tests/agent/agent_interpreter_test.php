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
use mod_booking\agent\interpreter;
use mod_booking\agent\task_registry;
use mod_booking\agent\booking\booking_task_provider;

/**
 * Tests for the AI agent interpreter.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_interpreter_test extends advanced_testcase {

    /** @var task_registry */
    private task_registry $registry;

    /** @var interpreter */
    private interpreter $interpreter;

    /**
     * Set up test registry and interpreter.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->registry    = new task_registry();
        $this->registry->register(new booking_task_provider());
        $this->interpreter = new interpreter($this->registry);
    }

    /**
     * Test that invalid JSON produces an error response.
     */
    public function test_invalid_json_returns_error(): void {
        $result = $this->interpreter->interpret('This is not JSON at all.', 1, 1);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
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
                ['task' => 'booking.create_option', 'version' => 1, 'input' => ['text' => 'My Option']],
            ],
        ]);
        $result = $this->interpreter->interpret($raw, 1, 1);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertEquals('error', $result['response_type']);
        $this->assertNotEmpty($result['errors']);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
        // Should become a clarification because of the ambiguity.
        $this->assertEquals('clarification', $result['response_type']);
        $this->assertNotEmpty($result['ambiguities']);
    }

    /**
     * Test that JSON wrapped in markdown fences is parsed correctly.
     */
    public function test_markdown_fence_is_stripped(): void {
        $raw = "```json\n" . json_encode([
            'response_type' => 'clarification',
            'message'       => 'Please clarify.',
        ]) . "\n```";
        $result = $this->interpreter->interpret($raw, 1, 1);
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
        $result = $this->interpreter->interpret($raw, 1, 1);
        $this->assertStringNotContainsString('<script>', $result['message']);
    }
}

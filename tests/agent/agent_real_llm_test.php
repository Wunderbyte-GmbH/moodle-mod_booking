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
 * Optional real-LLM smoke tests for booking AI endpoints.
 *
 * Enabled only when BOOKING_AI_REAL_LLM=1.
 *
 * @package    mod_booking
 * @category   test
 * @group      real_llm
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use core_ai\aiactions\generate_text;
use mod_booking\external\ai_confirm_run;
use mod_booking\external\ai_poll_run_status;
use mod_booking\external\ai_send_message;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\conversation_store;

/**
 * Real-LLM smoke tests (opt-in).
 *
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class agent_real_llm_test extends advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $booking;

    /** @var \stdClass */
    private $teacher;

    /**
     * Shared setup for real-LLM tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->maybe_register_live_ai_provider();

        $this->course = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'name' => 'Real LLM Test Booking',
            'eventtype' => 'Webinar',
            'bookingmanager' => 'admin',
        ]);

        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Skip test unless explicit opt-in env is enabled.
     */
    private function require_real_llm_opt_in(): void {
        $explicitoptin = (string)getenv('BOOKING_AI_REAL_LLM') === '1';
        $hascredentials = (string)getenv('BOOKING_TEST_AI_KEY') !== ''
            && (string)getenv('BOOKING_TEST_AI_MODEL') !== ''
            && (string)getenv('BOOKING_TEST_AI_ENDPOINT') !== '';

        if (!$explicitoptin && !$hascredentials) {
            $this->markTestSkipped(
                'Set BOOKING_AI_REAL_LLM=1 or provide BOOKING_TEST_AI_KEY/BOOKING_TEST_AI_MODEL/BOOKING_TEST_AI_ENDPOINT.'
            );
        }
    }

    /**
     * Register a real OpenAI-compatible provider from BOOKING_TEST_AI_* env vars.
     *
     * Uses the Moodle core_ai manager API directly so smoke tests can run in an
     * isolated PHPUnit context without manual provider setup.
     */
    private function maybe_register_live_ai_provider(): void {
        $apikey = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $model = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $endpoint = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));

        if ($apikey === '' || $model === '' || $endpoint === '') {
            return;
        }

        $parsedendpoint = parse_url($endpoint);
        $path = (string)($parsedendpoint['path'] ?? '');
        if ($path === '' || $path === '/') {
            $endpoint = rtrim($endpoint, '/') . '/v1/chat/completions';
        }

        $manager = \core\di::get(\core_ai\manager::class);
        $manager->create_provider_instance(
            classname: '\\aiprovider_openai\\provider',
            name: 'booking-real-llm-smoke',
            enabled: true,
            config: ['apikey' => $apikey],
            actionconfig: [
                generate_text::class => [
                    'enabled' => true,
                    'settings' => [
                        'model' => $model,
                        'endpoint' => $endpoint,
                        'systeminstruction' => '',
                    ],
                ],
            ],
        );
    }

    /**
     * Skip test unless a provider is configured for this context.
     */
    private function require_provider_available(): void {
        $registry = task_registry::make_default();
        $store = new conversation_store();
        $orc = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orc->is_provider_available((int)$this->booking->cmid, (int)$this->teacher->id)) {
            $this->markTestSkipped('No core_ai text provider configured/enabled for this context.');
        }
    }

    /**
     * Smoke: create prompt should not return hard error and should provide a run context.
     */
    public function test_real_llm_create_prompt_smoke(): void {
        $this->require_real_llm_opt_in();
        $this->require_provider_available();

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Erstelle eine Buchungsoption namens LLM Smoke Option mit 7 Plaetzen.'
        );

        $this->assertNotEquals('error', (string)$response['response_type']);
        $this->assertGreaterThan(0, (int)$response['threadid']);
    }

    /**
     * Smoke: confirming a generated command run should create a run with a non-failure state.
     */
    public function test_real_llm_confirm_run_smoke(): void {
        $this->require_real_llm_opt_in();
        $this->require_provider_available();

        $response = [];
        $commandsjson = '[]';
        $commands = [];
        $prompts = [
            'Erstelle eine neue Buchungsoption mit folgenden festen Angaben: Titel "LLM Confirm Option", maxanswers 5, coursestarttime 2045-03-15T09:00:00, courseendtime 2045-03-15T17:00:00, teacherquery "current". Gib das als bestaetigbaren Befehl aus.',
            'Erstelle eine neue Buchungsoption mit Titel "LLM Confirm Option Retry", maxanswers 6, coursestarttime 2045-03-16T09:00:00, courseendtime 2045-03-16T17:00:00 und teacherquery "current". Gib nur einen bestaetigbaren Befehl aus.',
        ];

        foreach ($prompts as $prompt) {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute((int)$this->booking->cmid, $prompt);

            $commandsjson = (string)($response['commands'] ?? '[]');
            $commands = json_decode($commandsjson, true);
            if (is_array($commands) && !empty($commands)) {
                break;
            }
        }

        $this->assertIsArray($commands, 'commands must decode to an array.');
        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['confirm_pending', 'confirmation_request'],
            'Confirm smoke expects a confirmation response type from ai_send_message.'
        );
        $this->assertNotEmpty(
            $commands,
            'Confirm smoke expects non-empty commands for ai_confirm_run execution.'
        );

        $_POST['sesskey'] = sesskey();
        $confirm = ai_confirm_run::execute(
            (int)$this->booking->cmid,
            (int)$response['threadid'],
            $commandsjson
        );

        $this->assertTrue((bool)$confirm['success']);
        $this->assertGreaterThan(0, (int)$confirm['runid']);

        $runstatus = ai_poll_run_status::execute((int)$this->booking->cmid, (int)$confirm['runid']);
        $this->assertContains((string)$runstatus['status'], ['queued', 'running', 'completed']);
    }

    /**
     * Smoke: read-only search should not return hard errors.
     */
    public function test_real_llm_search_prompt_smoke(): void {
        $this->require_real_llm_opt_in();
        $this->require_provider_available();

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Zeige mir die vorhandenen Buchungsoptionen.'
        );

        $this->assertNotEquals('error', (string)$response['response_type']);
    }
}

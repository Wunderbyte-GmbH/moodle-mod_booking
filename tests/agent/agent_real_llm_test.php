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
use mod_booking\external\ai_confirm_run;
use mod_booking\external\ai_poll_run_status;
use mod_booking\external\ai_send_message;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\conversation_store;

/**
 * Real-LLM smoke tests (opt-in).
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
        if ((string)getenv('BOOKING_AI_REAL_LLM') !== '1') {
            $this->markTestSkipped('Set BOOKING_AI_REAL_LLM=1 to run real-LLM smoke tests.');
        }
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

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Erstelle eine Buchungsoption namens LLM Confirm Option mit 5 Plaetzen.'
        );

        $commandsjson = (string)($response['commands'] ?? '[]');
        $commands = json_decode($commandsjson, true);
        if (!is_array($commands) || empty($commands)) {
            $this->markTestSkipped('Model did not return confirmable commands in this run.');
        }

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

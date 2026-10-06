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
 * The constructor never sees an example value for a query field, and the rule stands next to the fields.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\planner_phase_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * F79 (baseline runs 25-32): for "Nur anschauen, nicht schicken: die Abschlussbestätigung für Zuweisung 4037"
 * the constructor built messagequery "completion confirmation" three times - verbatim the example value of
 * preview_message; for "rappel à 7 jours" it built "reminder 7 days", the example of diagnose_message_delivery;
 * "Data protection" (an example) stood in for "Datenschutz-Unterweisung" thirteen times. The examples stood next
 * to the fields, the TARGET NAMES rule 6000 characters earlier. Query fields carry the user's own words: their
 * example values are not shown, and the rule stands directly before the input fields.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class constructor_sees_no_query_example_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The filter keeps every non-query example and drops every query one.
     */
    public function test_query_examples_are_filtered(): void {
        $kept = planner_phase_service::without_query_field_examples([
            'userquery' => 'anna.muster@example.com',
            'role' => 'student',
            'coursequery' => 'First Aid',
            'query' => 'Informatik',
            'limit' => 5,
        ]);
        $this->assertSame(['role' => 'student', 'limit' => 5], $kept);
    }

    /**
     * The live constructor prompt of a skill with query examples carries none of them, and the rule
     * stands directly before its input fields.
     */
    public function test_the_live_constructor_prompt_has_no_query_example(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        // The example of course.enrol_user is {userquery: anna.muster (at) example.com, role: student, coursequery: First Aid}.
        $this->install_scripted_planner([
            $this->selector_skill_call('course.enrol_user'),
            $this->constructor_clarification('In welchen Kurs?'),
        ]);

        $this->chat('Schreib Jorinde in den Statistikkurs ein.', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedplannerprompts);
        $prompt = (string)$this->scriptedplannerprompts[1];
        $this->assertStringNotContainsString('First Aid', $prompt, 'the coursequery example is bait');
        $this->assertStringNotContainsString('anna.muster@example.com', $prompt, 'the userquery example is bait');
        $this->assertStringContainsString('"role":"student"', $prompt, 'non-query examples stay');

        // Wave 32 (frozen prompt spec): the target-name rule stands once, in the constructor template (rule 3); the
        // catalog entry no longer carries a restated copy next to the fields.
        $this->assertSame(1, substr_count($prompt, 'TARGET NAMES.'));
        $this->assertStringNotContainsString('exactly as the user wrote it - same language', $prompt);
    }
}

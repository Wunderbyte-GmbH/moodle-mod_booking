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
 * A course name that fits several courses is decided by the activity the request names.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Replay of UA-1 (baseline L34-L48, thread 16750): "That 'Untitled page' in Winter School is embarrassing - call it
 * 'Programme Overview' and move it to the top section." Two courses are called "Winter School 2026" and "Winter School
 * 2027"; only 2027 has the page. The constructor sets coursequery "Winter School" in about half the runs, the course
 * search finds both courses and the turn ends with "which Winter School?" - although the page already decides it.
 *
 * The course is resolved before the skill's own preflight, so the skill never got the chance to look for its activity.
 * A skill may now decide between the candidates of an ambiguous target from its own input
 * (decide_ambiguous_target(), a declarative hook); the engine accepts only one of the candidates and asks otherwise.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver
 * @covers \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 */
final class ambiguous_course_narrowed_by_activity_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** @var \stdClass Winter School 2026. */
    private \stdClass $ws26;

    /** @var \stdClass Winter School 2027. */
    private \stdClass $ws27;

    /**
     * Provider, capabilities and the two Winter School courses of the baseline fixture.
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
        $this->ws26 = $this->getDataGenerator()->create_course(['fullname' => 'Winter School 2026', 'shortname' => 'WS26']);
        $this->ws27 = $this->getDataGenerator()->create_course(['fullname' => 'Winter School 2027', 'shortname' => 'WS27']);
        foreach ([$this->ws26, $this->ws27] as $course) {
            $this->getDataGenerator()->enrol_user((int)$this->teacher->id, (int)$course->id, 'editingteacher');
        }
        $this->getDataGenerator()->create_module('page', ['course' => $this->ws26->id, 'name' => 'Welcome']);
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The planner outputs of thread 16750 (selector, then the constructor with the course the user named).
     *
     * @return void
     */
    private function script_ua1(): void {
        $this->install_scripted_planner([
            $this->selector_skill_call('course.update_activity'),
            $this->constructor_confirmation_request('course.update_activity', [
                'activityquery' => 'Untitled page',
                'name' => 'Programme Overview',
                'section' => 0,
                'coursequery' => 'Winter School',
            ]),
        ]);
    }

    /**
     * Run the UA-1 turn.
     *
     * @return array [result, threadid, store]
     */
    private function run_ua1(): array {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->script_ua1();
        $result = $this->chat(
            'That "Untitled page" in Winter School is embarrassing — call it "Programme Overview" and move it to the top '
                . 'section.',
            (int)$threadid,
            $store,
            $runtime
        );
        return [$result, (int)$threadid, $store];
    }

    /**
     * The page exists in one of the two courses: that course is the target, no question, and the confirmed change
     * lands on that page.
     */
    public function test_the_named_activity_decides_between_equally_named_courses(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->ws27->id, 'name' => 'Untitled page']);

        [$result, $threadid, $store] = $this->run_ua1();

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'The page decides the course, nothing to ask: ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($result['issue_codes'] ?? []));

        $this->confirm_pending_result($result, $threadid, $store);
        $this->assertSame('Programme Overview', (string)$this->get_page_name((int)$page->cmid));
    }

    /**
     * Non-success path: the page exists in both courses - still genuinely ambiguous, so the user is asked with both
     * courses to choose from (a clarification, never an error), and the text carries no issue code.
     */
    public function test_an_activity_in_both_courses_still_asks(): void {
        $this->getDataGenerator()->create_module('page', ['course' => $this->ws26->id, 'name' => 'Untitled page']);
        $this->getDataGenerator()->create_module('page', ['course' => $this->ws27->id, 'name' => 'Untitled page']);

        [$result] = $this->run_ua1();

        $this->assert_asks_for_the_course($result);
    }

    /**
     * Non-success path: the page exists in neither course - nothing to decide with, the course question stays.
     */
    public function test_an_activity_in_no_candidate_still_asks(): void {
        [$result] = $this->run_ua1();

        $this->assert_asks_for_the_course($result);
    }

    /**
     * A course the user cannot open never decides: the page also sits in a third "Winter School" course the teacher
     * is not enrolled in, and the one accessible course with the page is still the target.
     */
    public function test_a_course_without_access_does_not_decide(): void {
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Winter School 2025', 'shortname' => 'WS25']);
        $this->getDataGenerator()->create_module('page', ['course' => $other->id, 'name' => 'Untitled page']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->ws27->id, 'name' => 'Untitled page']);

        [$result, $threadid, $store] = $this->run_ua1();

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'Only the accessible course has a say: ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->confirm_pending_result($result, $threadid, $store);
        $this->assertSame('Programme Overview', (string)$this->get_page_name((int)$page->cmid));
    }

    /**
     * The turn asks which course is meant: a clarification with the course candidates, no issue code in the text.
     *
     * @param array $result
     * @return void
     */
    private function assert_asks_for_the_course(array $result): void {
        $this->assertNotSame('error', (string)($result['response_type'] ?? ''));
        $this->assertContains('CONTEXT_TARGET_UNRESOLVED', (array)($result['issue_codes'] ?? []));
        $message = (string)($result['message'] ?? '');
        $this->assertStringNotContainsString('CONTEXT_TARGET_UNRESOLVED', $message);
        $this->assertSame([], $this->pages_named('Programme Overview'), 'Nothing may be renamed while the course is open.');
    }

    /**
     * Current name of a page by its course module id.
     *
     * @param int $cmid
     * @return string
     */
    private function get_page_name(int $cmid): string {
        global $DB;
        $cm = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
        return (string)$DB->get_field('page', 'name', ['id' => $cm->instance]);
    }

    /**
     * Ids of pages carrying a name.
     *
     * @param string $name
     * @return int[]
     */
    private function pages_named(string $name): array {
        global $DB;
        return array_map('intval', array_keys($DB->get_records('page', ['name' => $name], '', 'id')));
    }
}

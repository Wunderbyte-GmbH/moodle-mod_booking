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

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\create_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A plan step with more than one session offers the sessions as a choice instead of creating them all.
 *
 * #2762 (2026-11-09, threads 9024/9078): in a plan of five numbered events the constructor gave every option all
 * five weekdays. Which date belongs to which step is not engine state, so the skill asks, with the sessions as
 * candidates and "all sessions" as a way out (thread 9104 slipped four of five dates past an equality check, so any
 * number above one asks). Structural checks only: status, issue code, field, candidate count and shape, the question
 * from the language pack; nothing asked outside a plan, with a single session, or with the override.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class wizard_create_option_plan_step_sessions_test extends booking_advanced_testcase {
    /** @var string Issue code of the choice. */
    private const CODE = 'OPTIONDATES_PLAN_STEP_CHOICE';

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Plan Step Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->contextid = (int)context_module::instance((int)$booking->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => (int)$booking->cmid]);
    }

    /**
     * A create request with the given number of weekday sessions.
     *
     * @param int $sessions
     * @param array $extra
     * @return array
     */
    private function input(int $sessions, array $extra = []): array {
        $monday = strtotime('next monday');
        $dates = [];
        for ($i = 0; $i < $sessions; $i++) {
            $day = date('Y-m-d', $monday + $i * DAYSECS);
            $dates[] = ['coursestarttime' => $day . 'T11:00:00', 'courseendtime' => $day . 'T13:00:00'];
        }
        return array_merge(['text' => 'MooDuell 2', 'maxanswers' => 40, 'optiondates' => $dates, 'outputlang' => 'de'], $extra);
    }

    /**
     * Preflight with the given plan step set by the engine hook.
     *
     * @param array $input
     * @param array $planstep
     * @return object
     */
    private function preflight(array $input, array $planstep): object {
        $skill = new create_option_skill();
        $skill->set_plan_step($planstep);
        return $skill->preflight($input, $this->contextid, (int)get_admin()->id);
    }

    /**
     * The step context of step 2 of 5 as the queue hands it over.
     *
     * @return array
     */
    private function step_two_of_five(): array {
        return ['index' => 2, 'total' => 5, 'current' => 'create_option_MooDuell_2',
            'remaining' => ['create_option_MooDuell_3', 'create_option_MooDuell_4', 'create_option_MooDuell_5'],
            'completed' => ['create_option_MooDuell_1']];
    }

    /**
     * One session per plan step on distinct ascending days: step n keeps session n, nothing is asked
     * (thread 9232: the re-planned construction repeated the same five dates, so the question always reached the
     * user although the step number is engine state and the confirmation card shows the date).
     */
    public function test_one_session_per_plan_step_keeps_the_session_of_the_step(): void {
        $input = $this->input(5);
        $dto = $this->preflight($input, $this->step_two_of_five());
        $this->assertNotContains(self::CODE, array_column($dto->issues, 'code'), json_encode($dto->issues));
        $this->assertSame([$input['optiondates'][1]], array_values((array)$dto->preparedinput['optiondates']));

        $first = $this->step_two_of_five();
        $first['index'] = 1;
        $first['current'] = '';
        $dto = $this->preflight($input, $first);
        $this->assertNotContains(self::CODE, array_column($dto->issues, 'code'), json_encode($dto->issues));
        $this->assertSame([$input['optiondates'][0]], array_values((array)$dto->preparedinput['optiondates']));
    }

    /**
     * One session per plan step, but not one day each (two sessions share a day): the order says nothing about
     * the steps, so the five sessions and "all" are offered as candidates.
     */
    public function test_sessions_sharing_a_day_offer_the_sessions_as_a_choice(): void {
        $input = $this->input(5);
        $input['optiondates'][4] = [
            'coursestarttime' => substr($input['optiondates'][3]['coursestarttime'], 0, 10) . 'T15:00:00',
            'courseendtime' => substr($input['optiondates'][3]['coursestarttime'], 0, 10) . 'T17:00:00',
        ];
        $dto = $this->preflight($input, $this->step_two_of_five());
        $this->assertNotSame('pass', $dto->status);
        $codes = array_column($dto->issues, 'code');
        $this->assertContains(self::CODE, $codes, json_encode($dto->issues));
        $issue = $dto->issues[array_search(self::CODE, $codes, true)];
        $this->assertSame('needs_clarification', $issue['severity']);
        $this->assertSame('optiondates', $issue['field']);
        $this->assertCount(6, $issue['candidates'], 'five sessions and the way out');
        foreach (array_slice($issue['candidates'], 0, 5) as $candidate) {
            $this->assertNotSame('', (string)$candidate['id']);
            $this->assertNotSame('', (string)$candidate['coursestarttime']);
            $this->assertNotSame('', (string)$candidate['courseendtime']);
        }
        $this->assertSame('all_sessions', $issue['candidates'][5]['id']);
        $expected = get_string_manager()->get_string(
            'agent_booking_create_option_plan_step_sessions_question',
            'booking',
            (object)['index' => 2, 'total' => 5, 'count' => 5],
            'de'
        );
        $this->assertSame($expected, $issue['user_question']);
        $this->assertStringNotContainsString('{$a', $issue['user_question']);
    }

    /**
     * Fewer sessions than steps still ask: four of five dates are as wrong for one event as five (thread 9104).
     */
    public function test_fewer_sessions_than_steps_still_offer_the_choice(): void {
        $dto = $this->preflight($this->input(4), $this->step_two_of_five());
        $codes = array_column($dto->issues, 'code');
        $this->assertContains(self::CODE, $codes, json_encode($dto->issues));
        $this->assertCount(5, $dto->issues[array_search(self::CODE, $codes, true)]['candidates'], 'four sessions and the way out');
    }

    /**
     * Nothing is asked outside a plan, with a single session, or with the override.
     */
    public function test_no_choice_outside_a_plan_with_a_single_session_or_with_the_override(): void {
        $cases = [
            'no plan' => [$this->input(5), []],
            'one session' => [$this->input(1), $this->step_two_of_five()],
            'override' => [$this->input(5, ['override' => ['all_sessions']]), $this->step_two_of_five()],
        ];
        foreach ($cases as $name => [$input, $planstep]) {
            $dto = $this->preflight($input, $planstep);
            $this->assertNotContains(self::CODE, array_column($dto->issues, 'code'), $name);
        }
    }
}

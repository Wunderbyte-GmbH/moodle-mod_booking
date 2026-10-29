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
 * Tests for the certificate condition "combination" (AND / OR of other conditions).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\event\bookingoption_completed;
use mod_booking\local\certificate_conditions\certificate_conditions;
use mod_booking\local\certificate_conditions\conditions\combination;
use mod_booking\local\certificate_conditions\conditions\taggedoptions;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the certificate condition "combination".
 *
 * Scenario: booking instances A, B, C (two options each) and D (one option).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class certificate_combination_test extends booking_advanced_testcase {
    /** @var array users keyed by name */
    private array $users = [];

    /** @var object course */
    private ?object $course = null;

    /** @var array booking instances keyed by A, B, C, D */
    private array $bookings = [];

    /** @var array option ids keyed by A1, A2, B1, B2, C1, C2, D1 */
    private array $options = [];

    /** @var int certificate template id */
    private int $certid = 0;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        certificate_conditions::reset_caches();
    }

    /**
     * One option in each of A, B, C, then one other option in any of them.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::evaluate
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::is_fulfilled_for_user
     */
    public function test_abc_then_one_more(): void {
        $this->base_scenario();
        $combinationid = $this->insert_abc_combination();
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->complete('B1', $userid);
        $this->complete('C1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid), 'Step 2 not fulfilled yet');

        $this->complete('A2', $userid);
        $this->assertEquals(1, $this->count_certificates($userid), 'Issued after the fourth option');

        // Multiple issuing is prevented, so a further completion issues nothing more.
        $this->complete('B2', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));

        // Another user is unaffected.
        $this->assertEquals(0, $this->count_certificates($this->users['student2']->id));
        $this->assertNotEmpty($combinationid);
    }

    /**
     * The evaluation is state based: the order of the completions does not matter.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::evaluate
     */
    public function test_order_of_completions_does_not_matter(): void {
        $this->base_scenario();
        $this->insert_abc_combination();
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->complete('A2', $userid);
        $this->complete('B1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid));

        $this->complete('C1', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));
    }

    /**
     * Without unique options the same option counts for both groups.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::evaluate
     */
    public function test_uniqueoptions_off(): void {
        $this->base_scenario();
        $this->insert_abc_combination(0);
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->complete('B1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid));

        $this->complete('C1', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));
    }

    /**
     * OR between groups: one fulfilled group suffices, and a failed group consumes nothing.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::is_fulfilled_for_user
     */
    public function test_or_between_groups(): void {
        global $DB;
        $this->base_scenario();
        $leafa = $this->insert_instance_leaf('A');
        $leafb = $this->insert_instance_leaf('B');
        $leafc = $this->insert_instance_leaf('C');

        // (A AND B) OR (A OR C): completing only A1 fails group 1 (A consumed, B missing) and must
        // leave A1 available for group 2.
        $first = $this->insert_combination(
            [
                ['operator' => 'AND', 'conditionids' => [$leafa, $leafb]],
                ['operator' => 'OR', 'conditionids' => [$leafa, $leafc]],
            ],
            'OR'
        );
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));

        // (A AND B) OR (C) is fulfilled by C alone for another user (first combination switched off).
        $DB->set_field('booking_cert_cond', 'isactive', 0, ['id' => $first]);
        $this->insert_combination(
            [
                ['operator' => 'AND', 'conditionids' => [$leafa, $leafb]],
                ['operator' => 'AND', 'conditionids' => [$leafc]],
            ],
            'OR'
        );
        $userid2 = $this->users['student2']->id;
        $this->complete('C1', $userid2);
        $this->assertEquals(1, $this->count_certificates($userid2));
    }

    /**
     * Leaves saved with "No action" issue nothing on their own.
     *
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::evaluate_certificate_conditions
     */
    public function test_leaf_without_action_issues_nothing(): void {
        $this->base_scenario();
        $this->insert_instance_leaf('A');
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->complete('A2', $userid);
        $this->assertEquals(0, $this->count_certificates($userid));
    }

    /**
     * Only the completion of an option observed by one of the leaves triggers the combination.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::evaluate
     */
    public function test_completion_in_unrelated_instance_does_not_trigger(): void {
        $this->base_scenario();
        // Allow multiple certificates for this combination, so a re-trigger would be visible.
        $this->insert_abc_combination(1, 2);
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->complete('B1', $userid);
        $this->complete('C1', $userid);
        $this->complete('A2', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));

        // D is not observed by any leaf: no evaluation, no certificate.
        $this->complete('D1', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));

        // B2 is observed and the combination is still fulfilled: with multiple issuing allowed it fires again.
        $this->complete('B2', $userid);
        $this->assertEquals(2, $this->count_certificates($userid));
    }

    /**
     * With unique options, options observed by several leaves are consumed last.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::is_fulfilled_for_user
     */
    public function test_overlapping_leaves_prefer_exclusive_options(): void {
        $this->base_scenario();
        $leafa = $this->insert_instance_leaf('A');
        $leafa1 = $this->insert_bookingoption_leaf([$this->options['A1']]);

        // Instance A (any option) AND option A1: needs two different options of A, one of them A1.
        $this->insert_combination([['operator' => 'AND', 'conditionids' => [$leafa, $leafa1]]], 'AND');
        $userid = $this->users['student1']->id;

        $this->complete('A1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid), 'A1 cannot count for both leaves');

        $this->complete('A2', $userid);
        $this->assertEquals(1, $this->count_certificates($userid), 'Instance leaf takes A2, option leaf takes A1');
    }

    /**
     * Deleting a leaf removes it from the combinations using it.
     *
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::delete_condition
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::remove_leaf_from_combinations
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::get_combinations_using
     */
    public function test_delete_leaf_prunes_combination(): void {
        global $DB;
        $this->base_scenario();
        $combinationid = $this->insert_abc_combination();
        $leafc = $this->leafids['C'];

        $this->assertEquals([$combinationid], array_keys(combination::get_combinations_using($leafc)));
        $this->assertEquals(3, $DB->count_records('booking_cert_cond_item', ['conditionid' => $combinationid]));

        certificate_conditions::delete_condition($leafc);

        $this->assertEmpty(combination::get_combinations_using($leafc));
        $this->assertEquals(2, $DB->count_records('booking_cert_cond_item', ['conditionid' => $combinationid]));
        $record = $DB->get_record('booking_cert_cond', ['id' => $combinationid]);
        $json = json_decode($record->logicjson);
        $this->assertCount(2, $json->groups);
        foreach ($json->groups as $group) {
            $this->assertNotContains($leafc, $group->conditionids);
            $this->assertCount(2, $group->conditionids);
        }

        // (A AND B) AND (A OR B) is now fulfilled by A1, B1, A2.
        $userid = $this->users['student1']->id;
        $this->complete('A1', $userid);
        $this->complete('B1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid));
        $this->complete('A2', $userid);
        $this->assertEquals(1, $this->count_certificates($userid));
    }

    /**
     * The required count of tagged options is saved and read back from its own form field.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions::save_condition
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions::set_defaults
     */
    public function test_taggedoptions_requiredcount_roundtrip(): void {
        $condition = new taggedoptions();
        $data = (object)['condition_taggedoptions_requiredcount' => 2];
        $condition->save_condition($data);
        $this->assertEquals(2, json_decode($data->conditionjson)->requiredcount);

        $defaults = new stdClass();
        $condition->set_defaults($defaults, (object)['id' => 0, 'logicjson' => $data->conditionjson]);
        $this->assertEquals(2, $defaults->condition_taggedoptions_requiredcount);
    }

    /**
     * Form validation and eligibility of building blocks.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::validate
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::get_eligible_leaf_conditions
     */
    public function test_validate_and_eligibility(): void {
        $this->base_scenario();
        $combinationid = $this->insert_abc_combination();
        $leafa = $this->leafids['A'];
        $systemcontextid = context_system::instance()->id;

        // Combinations are never eligible, leaves are.
        $eligible = combination::get_eligible_leaf_conditions($systemcontextid, $combinationid);
        $this->assertArrayHasKey($leafa, $eligible);
        $this->assertArrayNotHasKey($combinationid, $eligible);

        // A module context only sees system conditions and its own.
        $modulecontext = context_module::instance($this->bookings['A']->cmid);
        $instanceleaf = $this->insert_instance_leaf('A', 1, null, $modulecontext->id);
        $othermodulecontext = context_module::instance($this->bookings['B']->cmid);
        $this->assertArrayHasKey($instanceleaf, combination::get_eligible_leaf_conditions($modulecontext->id));
        $this->assertArrayNotHasKey($instanceleaf, combination::get_eligible_leaf_conditions($othermodulecontext->id));
        $this->assertArrayHasKey($instanceleaf, combination::get_eligible_leaf_conditions($systemcontextid));

        $condition = new combination();
        $base = ['contextid' => $systemcontextid, 'id' => $combinationid];

        // No group used.
        $errors = $condition->validate($base + ['condition_combination_group_1_operator' => '0']);
        $this->assertArrayHasKey('condition_combination_group_1_operator', $errors);

        // Group without conditions.
        $errors = $condition->validate($base + [
            'condition_combination_group_1_operator' => 'AND',
            'condition_combination_group_1_conditionids' => [],
        ]);
        $this->assertArrayHasKey('condition_combination_group_1_conditionids', $errors);

        // Self reference and combination as leaf are rejected.
        $errors = $condition->validate($base + [
            'condition_combination_group_1_operator' => 'AND',
            'condition_combination_group_1_conditionids' => [$leafa, $combinationid],
        ]);
        $this->assertArrayHasKey('condition_combination_group_1_conditionids', $errors);

        // Valid.
        $errors = $condition->validate($base + [
            'condition_combination_group_1_operator' => 'AND',
            'condition_combination_group_1_conditionids' => [$leafa],
            'condition_combination_group_2_operator' => '0',
        ]);
        $this->assertEmpty($errors);
    }

    /**
     * The list of conditions shows the groups of a combination and "No action" for leaves.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\combination::get_description
     * @covers \mod_booking\output\certificateconditionslist
     */
    public function test_rendered_list_shows_description(): void {
        $this->base_scenario();
        $this->insert_abc_combination();

        $list = certificate_conditions::get_rendered_list_of_saved_conditions(context_system::instance()->id);
        $this->assertStringContainsString('Leaf A ≥ 1 AND Leaf B ≥ 1 AND Leaf C ≥ 1', $list);
        $this->assertStringContainsString('Leaf A ≥ 1 OR Leaf B ≥ 1 OR Leaf C ≥ 1', $list);
        $this->assertStringContainsString(get_string('certificateaction_none', 'mod_booking'), $list);
        $this->assertStringContainsString(get_string('condition_combination_uniqueoptionsshort', 'mod_booking'), $list);
    }

    /**
     * The number of queries of an evaluation does not grow with the number of options of the instance.
     *
     * @covers \mod_booking\local\certificate_conditions\completion_counter_trait::get_completed_optionids_for_user
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::get_candidate_condition_ids
     */
    public function test_queries_do_not_grow_with_instance_size(): void {
        $this->base_scenario();
        $this->create_instance('E', 20);
        // Required count 2 and one completion each: evaluated but never fulfilled, so no issuing is measured.
        $this->insert_instance_leaf('A', 2, $this->certid);
        $this->insert_instance_leaf('E', 2, $this->certid);
        $userid = $this->users['student1']->id;
        $this->complete('A1', $userid);
        $this->complete('E1', $userid);
        $this->assertEquals(0, $this->count_certificates($userid));

        $readssmall = $this->measure_evaluation_reads('A1', $userid);
        $readsbig = $this->measure_evaluation_reads('E1', $userid);
        $this->assertLessThanOrEqual($readssmall, $readsbig);
    }

    /**
     * The completion state follows the answer precedence of booking_answers: the newest answer of an option
     * wins, but a previously booked answer never shadows an active one.
     *
     * @covers \mod_booking\local\certificate_conditions\completion_counter_trait::completed_optionids_from_answers
     */
    public function test_completion_follows_answer_precedence(): void {
        global $DB;
        $this->base_scenario();
        $this->insert_instance_leaf('A', 1, $this->certid);
        $userid = $this->users['student1']->id;
        $optionid = $this->options['A1'];

        // Old, completed, previously booked answer and a newer active answer that is not completed.
        $this->insert_answer($optionid, $userid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, 1, time() - 100);
        $activeid = $this->insert_answer($optionid, $userid, MOD_BOOKING_STATUSPARAM_BOOKED, 0, time() - 50);
        $this->assertFalse($this->evaluate('A1', $userid));
        $this->assertEquals(0, $this->count_certificates($userid));

        // Once the active answer is completed the condition is fulfilled.
        $DB->set_field('booking_answers', 'completed', 1, ['id' => $activeid]);
        $this->assertTrue($this->evaluate('A1', $userid));
        $this->assertEquals(1, $this->count_certificates($userid));

        // A later previously booked answer does not shadow the active completed one either.
        $this->insert_answer($optionid, $userid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, 0, time());
        $this->assertTrue($this->evaluate('A1', $userid));
    }

    /**
     * Evaluate the conditions for a synthetic completion event of the option (as the manual trigger does).
     *
     * @param string $key
     * @param int $userid
     * @return bool
     */
    private function evaluate(string $key, int $userid): bool {
        $optionid = $this->options[$key];
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $event = bookingoption_completed::create([
            'objectid' => $optionid,
            'context' => context_module::instance((int)$settings->cmid),
            'userid' => get_admin()->id,
            'relateduserid' => $userid,
            'other' => ['cmid' => (int)$settings->cmid],
        ]);
        return certificate_conditions::evaluate_certificate_conditions_with_result($event, $userid, $optionid);
    }

    /**
     * Number of DB reads of one evaluation (after a warm up run).
     *
     * @param string $key
     * @param int $userid
     * @return int
     */
    private function measure_evaluation_reads(string $key, int $userid): int {
        global $DB;
        $this->evaluate($key, $userid);
        $before = $DB->perf_get_reads();
        $this->evaluate($key, $userid);
        return $DB->perf_get_reads() - $before;
    }

    /**
     * Insert a booking answer directly.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $waitinglist
     * @param int $completed
     * @param int $timemodified
     * @return int answer id
     */
    private function insert_answer(int $optionid, int $userid, int $waitinglist, int $completed, int $timemodified): int {
        global $DB;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $id = $DB->insert_record('booking_answers', (object)[
            'bookingid' => (int)$settings->bookingid,
            'userid' => $userid,
            'optionid' => $optionid,
            'waitinglist' => $waitinglist,
            'completed' => $completed,
            'timemodified' => $timemodified,
            'timecreated' => $timemodified,
            'timebooked' => $timemodified,
            'status' => 0,
            'places' => 1,
        ]);
        singleton_service::destroy_booking_answers($optionid);
        return $id;
    }

    /** @var array leaf condition ids keyed by instance key */
    private array $leafids = [];

    /**
     * Insert the A/B/C scenario: leaves A, B, C and the combination AND(A,B,C) AND OR(A,B,C).
     *
     * @param int $uniqueoptions
     * @param int $issuemultiple 0 = global setting, 1 = prevent, 2 = allow
     * @return int combination id
     */
    private function insert_abc_combination(int $uniqueoptions = 1, int $issuemultiple = 0): int {
        $this->leafids = [];
        foreach (['A', 'B', 'C'] as $key) {
            $this->leafids[$key] = $this->insert_instance_leaf($key);
        }
        $ids = array_values($this->leafids);
        return $this->insert_combination(
            [
                ['operator' => 'AND', 'conditionids' => $ids],
                ['operator' => 'OR', 'conditionids' => $ids],
            ],
            'AND',
            $uniqueoptions,
            $issuemultiple
        );
    }

    /**
     * Insert an instance leaf (no action unless a certificate id is given).
     *
     * @param string $key A, B, C or D
     * @param int $requiredcount
     * @param int|null $certid
     * @param int $contextid
     * @return int condition id
     */
    private function insert_instance_leaf(string $key, int $requiredcount = 1, ?int $certid = null, int $contextid = 1): int {
        global $DB;
        $bookingid = (int)$this->bookings[$key]->id;
        $id = $DB->insert_record('booking_cert_cond', $this->condition_record(
            "Leaf {$key}",
            ['conditionname' => 'instance', 'bookingid' => $bookingid, 'requiredcount' => $requiredcount],
            $certid,
            0,
            $contextid
        ));
        $DB->insert_record('booking_cert_cond_item', (object)[
            'conditionid' => $id,
            'component' => 'mod_booking',
            'area' => 'bookinginstance',
            'itemid' => $bookingid,
            'sortorder' => 0,
            'configjson' => json_encode([]),
        ]);
        return $id;
    }

    /**
     * Insert a bookingoption leaf (no action).
     *
     * @param array $optionids
     * @param int $requiredcount
     * @return int condition id
     */
    private function insert_bookingoption_leaf(array $optionids, int $requiredcount = 1): int {
        global $DB;
        $id = $DB->insert_record('booking_cert_cond', $this->condition_record(
            'Leaf options',
            ['conditionname' => 'bookingoption', 'requiredcount' => $requiredcount]
        ));
        foreach ($optionids as $optionid) {
            $DB->insert_record('booking_cert_cond_item', (object)[
                'conditionid' => $id,
                'component' => 'mod_booking',
                'area' => 'bookingoption',
                'itemid' => $optionid,
                'sortorder' => 0,
                'configjson' => json_encode([]),
            ]);
        }
        return $id;
    }

    /**
     * Insert a combination issuing the test certificate, including its lookup items.
     *
     * @param array $groups
     * @param string $operator
     * @param int $uniqueoptions
     * @param int $issuemultiple
     * @return int condition id
     */
    private function insert_combination(array $groups, string $operator, int $uniqueoptions = 1, int $issuemultiple = 0): int {
        global $DB;
        $logic = [
            'conditionname' => 'combination',
            'operator' => $operator,
            'uniqueoptions' => $uniqueoptions,
            'groups' => $groups,
        ];
        $id = $DB->insert_record(
            'booking_cert_cond',
            $this->condition_record('Combination', $logic, $this->certid, $issuemultiple)
        );
        (new combination())->save_items($id, (object)['conditionjson' => json_encode($logic)]);
        certificate_conditions::reset_caches();
        return $id;
    }

    /**
     * Build a condition record.
     *
     * @param string $name
     * @param array $logic
     * @param int|null $certid null = no action
     * @param int $issuemultiple
     * @param int $contextid
     * @return stdClass
     */
    private function condition_record(
        string $name,
        array $logic,
        ?int $certid = null,
        int $issuemultiple = 0,
        int $contextid = 1
    ): stdClass {
        $record = new stdClass();
        $record->contextid = $contextid;
        $record->name = $name;
        $record->filterjson = json_encode([]);
        $record->logicjson = json_encode($logic);
        $record->actionjson = $certid === null ? json_encode([]) : json_encode([
            'actionname' => 'createcertificate',
            'certid' => $certid,
            'expirydatetype' => 0,
            'expirydateabsolute' => 0,
            'expirydaterelative' => 0,
            'issuemultiplecertificates' => $issuemultiple,
        ]);
        $record->isactive = 1;
        $record->useastemplate = 0;
        $record->timecreated = time();
        $record->timemodified = time();
        return $record;
    }

    /**
     * Book a user into an option and mark it as completed.
     *
     * @param string $key option key (A1, B2, ...)
     * @param int $userid
     * @return void
     */
    private function complete(string $key, int $userid): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($this->options[$key]);
        booking_bookit::bookit('option', $settings->id, $userid);
        booking_bookit::bookit('option', $settings->id, $userid);

        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($userid);
        singleton_service::destroy_booking_answers($option->id);
    }

    /**
     * Number of certificates issued to the user.
     *
     * @param int $userid
     * @return int
     */
    private function count_certificates(int $userid): int {
        global $DB;
        return $DB->count_records('tool_certificate_issues', ['userid' => $userid]);
    }

    /**
     * Create users, course, the booking instances A, B, C (two options) and D (one option) and a certificate template.
     *
     * @return void
     */
    private function base_scenario(): void {
        $this->setAdminUser();

        set_config('certificateon', 1, 'booking');
        set_config('certificateoptions', 1, 'booking');
        set_config('issuemultiplecertificates', 0, 'booking');

        foreach (['student1', 'student2', 'bookingmanager'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
        }

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        foreach ($this->users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        }

        foreach (['A' => 2, 'B' => 2, 'C' => 2, 'D' => 1] as $key => $optioncount) {
            $this->create_instance($key, $optioncount);
        }

        $certificate = $this->getDataGenerator()->get_plugin_generator('tool_certificate')
            ->create_template((object)['name' => 'Certificate 1']);
        $this->certid = (int)$certificate->get_id();
    }

    /**
     * Create a booking instance with the given number of options (keys like A1, A2, ...).
     *
     * @param string $key
     * @param int $optioncount
     * @return void
     */
    private function create_instance(string $key, int $optioncount): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $bdata = [
            'name' => "Instance {$key}",
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'course' => $this->course->id,
            'bookingmanager' => $this->users['bookingmanager']->username,
        ];
        $this->bookings[$key] = $this->getDataGenerator()->create_module('booking', $bdata);

        for ($i = 1; $i <= $optioncount; $i++) {
            $option = $plugingenerator->create_option((object)[
                'bookingid' => $this->bookings[$key]->id,
                'courseid' => $this->course->id,
                'text' => "Option {$key}{$i}",
                'coursestarttime_0' => strtotime('now + 1 day'),
                'courseendtime_0' => strtotime('now + 2 day'),
                'importing' => 1,
                'useprice' => 0,
            ]);
            $this->options["{$key}{$i}"] = (int)$option->id;
        }
    }
}

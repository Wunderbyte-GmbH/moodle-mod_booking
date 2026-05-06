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
 * PHPUnit tests for the booking_enrolment sync service.
 *
 * Covers:
 *  - save_single_rule()  : insert / update / duplicate / invalid-source / no-actions
 *  - get_rule_for_option(): cohort label, group label, missing rule
 *  - delete_rule()       : manualize / keep-orphan / unenrol-soft-delete / invalid id / no answers
 *  - enrol_user_by_rule(): booking answer created, sync_attempts logged, booking_history metadata
 *  - unenrol_user_by_rule(): soft-delete, ownership guard, booking_history metadata
 *  - process_source_membership(): event-driven enrol and unenrol paths
 *  - disable_rules_for_option(): bulk disable
 *  - apply_rule_to_current_members(): full cohort enrolment sweep
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\booking_bookit;
use mod_booking\local\sync\booking_enrolment;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking_enrolment sync service.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\sync\booking_enrolment
 */
final class booking_enrolment_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Create a minimal test environment: course, booking module, booking option,
     * two enrolled users, one cohort (with both users), one group (with both users).
     *
     */
    private function setup_sync_environment(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name'   => 'Test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->text = 'Test option';
        $optionrecord->courseid = $course->id;
        $optionrecord->chooseorcreatecourse = 1;
        $optionrecord->maxanswers = 20;
        $option = $gen->create_option($optionrecord);

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Test cohort']);
        cohort_add_member($cohort->id, $user1->id);
        cohort_add_member($cohort->id, $user2->id);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Test group']);
        groups_add_member($group->id, $user1->id);
        groups_add_member($group->id, $user2->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        return [
            'course'   => $course,
            'booking'  => $booking,
            'option'   => $option,
            'settings' => $settings,
            'user1'    => $user1,
            'user2'    => $user2,
            'cohort'   => $cohort,
            'group'    => $group,
        ];
    }

    /**
     * Insert a sync rule directly into the DB and return the record.
     *
     * @param int    $optionid    Booking option id.
     * @param string $sourcetype  'cohort' or 'group'.
     * @param int    $sourceid    Cohort or group id.
     * @param array  $overrides   Optional field overrides.
     * @return stdClass           Inserted record (with id set).
     */
    private function insert_sync_rule(
        int $optionid,
        string $sourcetype,
        int $sourceid,
        array $overrides = []
    ): stdClass {
        global $DB, $USER;

        $rule = new stdClass();
        $rule->bookingoptionid = $optionid;
        $rule->sourcetype      = $sourcetype;
        $rule->sourceid        = $sourceid;
        $rule->syncenrol       = $overrides['syncenrol'] ?? 1;
        $rule->syncunenrol     = $overrides['syncunenrol'] ?? 1;
        $rule->conditionpolicy = $overrides['conditionpolicy'] ?? booking_enrolment::CONDITION_POLICY_OVERRIDE;
        $rule->isenabled       = $overrides['isenabled'] ?? 1;
        $rule->timecreated     = time();
        $rule->timemodified    = time();
        $rule->usercreated     = $USER->id;
        $rule->usermodified    = $USER->id;
        $rule->id              = $DB->insert_record('booking_sync_rules', $rule);
        return $rule;
    }

    /**
     * Insert a booking_answers row with a given syncruleid (bypasses booking stack).
     *
     * @param int $optionid    Booking option id.
     * @param int $bookingid   Booking module id.
     * @param int $userid      User id.
     * @param int $syncruleid  Rule id (0 = manual).
     * @param int $waitinglist Booking status constant.
     * @return stdClass        Inserted record (with id set).
     */
    private function insert_booking_answer(
        int $optionid,
        int $bookingid,
        int $userid,
        int $syncruleid,
        int $waitinglist = MOD_BOOKING_STATUSPARAM_BOOKED
    ): stdClass {
        global $DB;

        $answer = new stdClass();
        $answer->bookingid    = $bookingid;
        $answer->optionid     = $optionid;
        $answer->userid       = $userid;
        $answer->waitinglist  = $waitinglist;
        $answer->syncruleid   = $syncruleid;
        $answer->timecreated  = time();
        $answer->timemodified = time();
        $answer->id           = $DB->insert_record('booking_answers', $answer);
        return $answer;
    }

    /**
     * Book a user into an option via the full booking stack (two bookit calls = confirm).
     *
     * @param int $optionid Booking option id.
     * @param int $userid   User id.
     */
    private function bookit_user(int $optionid, int $userid): void {
        $this->setUser($userid);
        booking_bookit::bookit('option', $optionid, $userid);
        booking_bookit::bookit('option', $optionid, $userid);
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Return the most-recent booking_history record for a user+option, or null.
     *
     * @param int $userid    User id.
     * @param int $optionid  Booking option id.
     * @return stdClass|null
     */
    private function last_history_record(int $userid, int $optionid): ?stdClass {
        global $DB;
        $records = $DB->get_records(
            'booking_history',
            ['userid' => $userid, 'optionid' => $optionid],
            'id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * A new rule is persisted with all correct field values.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::save_single_rule
     */
    public function test_save_single_rule_creates_new_rule(): void {
        global $DB;
        $env = $this->setup_sync_environment();

        $data = new stdClass();
        $data->sourcetype          = 'cohort';
        $data->sourceid            = $env['cohort']->id;
        $data->syncenrolaction     = 1;
        $data->syncunenrolaction   = 1;
        $data->syncconditionpolicy = booking_enrolment::CONDITION_POLICY_OVERRIDE;

        $ruleid = booking_enrolment::save_single_rule($env['option']->id, $data);

        $this->assertGreaterThan(0, $ruleid);
        $rule = $DB->get_record('booking_sync_rules', ['id' => $ruleid]);
        $this->assertNotEmpty($rule);
        $this->assertEquals($env['option']->id, $rule->bookingoptionid);
        $this->assertEquals('cohort', $rule->sourcetype);
        $this->assertEquals($env['cohort']->id, $rule->sourceid);
        $this->assertEquals(1, $rule->syncenrol);
        $this->assertEquals(1, $rule->syncunenrol);
        $this->assertEquals(booking_enrolment::CONDITION_POLICY_OVERRIDE, $rule->conditionpolicy);
        $this->assertEquals(1, $rule->isenabled);
    }

    /**
     * Updating an existing rule changes only the supplied fields; no duplicate row is created.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::save_single_rule
     */
    public function test_save_single_rule_updates_existing_rule(): void {
        global $DB;
        $env = $this->setup_sync_environment();

        // Insert a rule with syncunenrol=0.
        $original = $this->insert_sync_rule(
            $env['option']->id,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0, 'conditionpolicy' => booking_enrolment::CONDITION_POLICY_RESPECT]
        );

        // Update via save_single_rule — flip syncunenrol and change conditionpolicy.
        $data = new stdClass();
        $data->ruleid              = $original->id;
        $data->sourcetype          = 'cohort';
        $data->sourceid            = $env['cohort']->id;
        $data->syncenrolaction     = 1;
        $data->syncunenrolaction   = 1;
        $data->syncconditionpolicy = booking_enrolment::CONDITION_POLICY_OVERRIDE;

        $returnedid = booking_enrolment::save_single_rule($env['option']->id, $data);

        $this->assertEquals($original->id, $returnedid);
        $updated = $DB->get_record('booking_sync_rules', ['id' => $original->id]);
        $this->assertEquals(1, $updated->syncunenrol);
        $this->assertEquals(booking_enrolment::CONDITION_POLICY_OVERRIDE, $updated->conditionpolicy);

        // Exactly one record for this option+source.
        $this->assertEquals(
            1,
            $DB->count_records('booking_sync_rules', [
                'bookingoptionid' => $env['option']->id,
                'sourceid'        => $env['cohort']->id,
            ])
        );
    }

    /**
     * Trying to move a rule to a source already owned by a different rule throws.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::save_single_rule
     */
    public function test_save_single_rule_rejects_duplicate_source(): void {
        $env = $this->setup_sync_environment();

        $this->insert_sync_rule($env['option']->id, 'cohort', $env['cohort']->id);
        $cohort2 = $this->getDataGenerator()->create_cohort();
        $rule2 = $this->insert_sync_rule($env['option']->id, 'cohort', $cohort2->id);

        $data = new stdClass();
        $data->ruleid            = $rule2->id;
        $data->sourcetype        = 'cohort';
        $data->sourceid          = $env['cohort']->id; // Already owned.
        $data->syncenrolaction   = 1;
        $data->syncunenrolaction = 1;

        $this->expectException(\moodle_exception::class);
        booking_enrolment::save_single_rule($env['option']->id, $data);
    }

    /**
     * An unknown source type is rejected.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::save_single_rule
     */
    public function test_save_single_rule_rejects_invalid_sourcetype(): void {
        $env = $this->setup_sync_environment();

        $data = new stdClass();
        $data->sourcetype        = 'unknown';
        $data->sourceid          = 1;
        $data->syncenrolaction   = 1;
        $data->syncunenrolaction = 1;

        $this->expectException(\moodle_exception::class);
        booking_enrolment::save_single_rule($env['option']->id, $data);
    }

    /**
     * A rule with neither enrol nor unenrol action enabled is rejected.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::save_single_rule
     */
    public function test_save_single_rule_rejects_no_actions(): void {
        $env = $this->setup_sync_environment();

        $data = new stdClass();
        $data->sourcetype        = 'cohort';
        $data->sourceid          = $env['cohort']->id;
        $data->syncenrolaction   = 0;
        $data->syncunenrolaction = 0;

        $this->expectException(\moodle_exception::class);
        booking_enrolment::save_single_rule($env['option']->id, $data);
    }

    /**
     * Returns rule record enriched with cohort source labels.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::get_rule_for_option
     */
    public function test_get_rule_for_option_returns_cohort_label(): void {
        $env  = $this->setup_sync_environment();
        $rule = $this->insert_sync_rule($env['option']->id, 'cohort', $env['cohort']->id);

        $fetched = booking_enrolment::get_rule_for_option($env['option']->id, $rule->id);

        $this->assertNotNull($fetched);
        $this->assertEquals('Test cohort', $fetched->sourcename);
        $this->assertNotEmpty($fetched->sourcetypelabel);
    }

    /**
     * Returns rule record enriched with group source labels.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::get_rule_for_option
     */
    public function test_get_rule_for_option_returns_group_label(): void {
        $env  = $this->setup_sync_environment();
        $rule = $this->insert_sync_rule($env['option']->id, 'group', $env['group']->id);

        $fetched = booking_enrolment::get_rule_for_option($env['option']->id, $rule->id);

        $this->assertNotNull($fetched);
        $this->assertEquals('Test group', $fetched->sourcename);
    }

    /**
     * Returns null when the rule id does not exist.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::get_rule_for_option
     */
    public function test_get_rule_for_option_returns_null_for_missing_rule(): void {
        $env    = $this->setup_sync_environment();
        $result = booking_enrolment::get_rule_for_option($env['option']->id, 9999999);
        $this->assertNull($result);
    }

    /**
     * Passing a non-existent ruleid throws a moodle_exception.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_throws_for_invalid_ruleid(): void {
        $env = $this->setup_sync_environment();
        $this->expectException(\moodle_exception::class);
        booking_enrolment::delete_rule($env['option']->id, 9999999);
    }

    /**
     * Deleting a rule with no linked answers returns affected=0 and removes the rule.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_no_answers_returns_zero_affected(): void {
        global $DB;
        $env  = $this->setup_sync_environment();
        $rule = $this->insert_sync_rule($env['option']->id, 'cohort', $env['cohort']->id);

        $result = booking_enrolment::delete_rule($env['option']->id, $rule->id);

        $this->assertEquals(0, $result['affected']);
        $this->assertFalse($DB->record_exists('booking_sync_rules', ['id' => $rule->id]));
    }

    /**
     * Manualize mode: rule is deleted, answers are kept, syncruleid is reset to 0.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_manualize_removes_rule_and_resets_answers(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;
        $bookingid = $env['booking']->id;

        $rule    = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $answer1 = $this->insert_booking_answer($optionid, $bookingid, $env['user1']->id, $rule->id);
        $answer2 = $this->insert_booking_answer($optionid, $bookingid, $env['user2']->id, $rule->id);

        $result = booking_enrolment::delete_rule($optionid, $rule->id, booking_enrolment::DELETE_MODE_MANUALIZE);

        $this->assertEquals(2, $result['affected']);
        $this->assertFalse($DB->record_exists('booking_sync_rules', ['id' => $rule->id]));

        // Rows must survive with syncruleid reset to 0.
        $updated1 = $DB->get_record('booking_answers', ['id' => $answer1->id]);
        $this->assertEquals(0, $updated1->syncruleid);
        $updated2 = $DB->get_record('booking_answers', ['id' => $answer2->id]);
        $this->assertEquals(0, $updated2->syncruleid);

        // Original booking status must be preserved.
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $updated1->waitinglist);
    }

    /**
     * Manualize mode: a booking_history entry with syncaction=rule_deleted_manualize is written.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_manualize_writes_history_with_sync_metadata(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;
        $userid   = (int)$env['user1']->id;

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $this->insert_booking_answer($optionid, $env['booking']->id, $userid, $rule->id);

        $before = $DB->count_records('booking_history', ['userid' => $userid, 'optionid' => $optionid]);
        booking_enrolment::delete_rule($optionid, $rule->id, booking_enrolment::DELETE_MODE_MANUALIZE);
        $after = $DB->count_records('booking_history', ['userid' => $userid, 'optionid' => $optionid]);

        $this->assertGreaterThan($before, $after, 'Expected a new booking_history record');

        $latest = $this->last_history_record($userid, $optionid);
        $this->assertNotNull($latest);
        $json = json_decode($latest->json ?? '{}', true);
        $this->assertEquals($rule->id, $json['syncruleid'] ?? null);
        $this->assertEquals('rule_deleted_manualize', $json['syncaction'] ?? null);
    }

    /**
     * Keep-orphan mode: rule is deleted, answers survive untouched (syncruleid unchanged).
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_keep_orphan_leaves_answers_with_original_syncruleid(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $rule   = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $answer = $this->insert_booking_answer($optionid, $env['booking']->id, $env['user1']->id, $rule->id);

        $result = booking_enrolment::delete_rule($optionid, $rule->id, booking_enrolment::DELETE_MODE_KEEP_ORPHAN);

        $this->assertEquals(1, $result['affected']);
        $this->assertFalse($DB->record_exists('booking_sync_rules', ['id' => $rule->id]));

        $kept = $DB->get_record('booking_answers', ['id' => $answer->id]);
        $this->assertNotEmpty($kept);
        $this->assertEquals($rule->id, $kept->syncruleid, 'syncruleid must remain (orphan)');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $kept->waitinglist, 'Status must be unchanged');
    }

    /**
     * Keep-orphan mode: booking_history entry with syncaction=rule_deleted_orphan is written.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_keep_orphan_writes_history_with_sync_metadata(): void {
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;
        $userid   = (int)$env['user1']->id;

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $this->insert_booking_answer($optionid, $env['booking']->id, $userid, $rule->id);

        booking_enrolment::delete_rule($optionid, $rule->id, booking_enrolment::DELETE_MODE_KEEP_ORPHAN);

        $latest = $this->last_history_record($userid, $optionid);
        $this->assertNotNull($latest);
        $json = json_decode($latest->json ?? '{}', true);
        $this->assertEquals($rule->id, $json['syncruleid'] ?? null);
        $this->assertEquals('rule_deleted_orphan', $json['syncaction'] ?? null);
    }

    /**
     * Unenrol-soft-delete mode: rule is deleted and the answer is soft-deleted.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_unenrolsoftdelete_soft_deletes_answers(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        // Use full booking stack so user_delete_response() has a real booking answer.
        $this->bookit_user($optionid, (int)$env['user1']->id);

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        singleton_service::destroy_booking_option_singleton($optionid);

        $result = booking_enrolment::delete_rule(
            $optionid,
            $rule->id,
            booking_enrolment::DELETE_MODE_UNENROL_SOFT_DELETE
        );

        $this->assertEquals(1, $result['affected']);
        $this->assertFalse($DB->record_exists('booking_sync_rules', ['id' => $rule->id]));

        // Row must exist but be soft-deleted.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);
    }

    /**
     * Unenrol-soft-delete mode: a sync_attempts entry is logged for each affected user.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::delete_rule
     */
    public function test_delete_rule_unenrolsoftdelete_logs_sync_attempt(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->bookit_user($optionid, (int)$env['user1']->id);

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        singleton_service::destroy_booking_option_singleton($optionid);

        booking_enrolment::delete_rule(
            $optionid,
            $rule->id,
            booking_enrolment::DELETE_MODE_UNENROL_SOFT_DELETE
        );

        $this->assertTrue(
            $DB->record_exists('booking_sync_attempts', [
                'syncruleid' => $rule->id,
                'userid'     => $env['user1']->id,
                'action'     => booking_enrolment::ACTION_UNENROL,
                'reasoncode' => booking_enrolment::REASON_OK,
            ]),
            'Expected a REASON_OK unenrol attempt in booking_sync_attempts'
        );
    }

    /**
     * Enrolment creates a booking_answers row owned by the rule.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::enrol_user_by_rule
     */
    public function test_enrol_user_by_rule_creates_booking_answer(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        booking_enrolment::enrol_user_by_rule($rule, (int)$env['user1']->id);

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertEquals($rule->id, $answer->syncruleid);
        $this->assertContains(
            (int)$answer->waitinglist,
            [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST, MOD_BOOKING_STATUSPARAM_RESERVED],
            'Answer should be in an active status'
        );
    }

    /**
     * Successful enrolment is logged in booking_sync_attempts with REASON_OK.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::enrol_user_by_rule
     */
    public function test_enrol_user_by_rule_logs_ok_attempt(): void {
        global $DB;
        $env  = $this->setup_sync_environment();
        $rule = $this->insert_sync_rule($env['option']->id, 'cohort', $env['cohort']->id);

        booking_enrolment::enrol_user_by_rule($rule, (int)$env['user1']->id);

        $this->assertTrue(
            $DB->record_exists('booking_sync_attempts', [
                'syncruleid' => $rule->id,
                'userid'     => $env['user1']->id,
                'action'     => booking_enrolment::ACTION_ENROL,
                'reasoncode' => booking_enrolment::REASON_OK,
            ])
        );
    }

    /**
     * Successful enrolment writes a booking_history entry with syncaction=enrol metadata.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::enrol_user_by_rule
     */
    public function test_enrol_user_by_rule_writes_history_with_sync_metadata(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;
        $userid   = (int)$env['user1']->id;

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        booking_enrolment::enrol_user_by_rule($rule, $userid);

        // Find the history entry that carries sync metadata.
        $found = false;
        $records = $DB->get_records(
            'booking_history',
            ['userid' => $userid, 'optionid' => $optionid],
            'id DESC'
        );
        foreach ($records as $r) {
            $decoded = json_decode($r->json ?? '{}', true);
            if (($decoded['syncaction'] ?? null) === booking_enrolment::ACTION_ENROL) {
                $this->assertEquals($rule->id, $decoded['syncruleid'] ?? null);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected booking_history entry with syncaction=enrol');
    }

    /**
     * Unenrol soft-deletes the booking answer (row kept, status = DELETED).
     *
     * @covers \mod_booking\local\sync\booking_enrolment::unenrol_user_by_rule
     */
    public function test_unenrol_user_by_rule_soft_deletes_answer(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->bookit_user($optionid, (int)$env['user1']->id);

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        singleton_service::destroy_booking_option_singleton($optionid);

        booking_enrolment::unenrol_user_by_rule($rule, (int)$env['user1']->id);

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer, 'Answer row must still exist after soft-delete');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);
    }

    /**
     * Unenrol of an answer not owned by the rule is silently blocked (ownership guard).
     * A REASON_BLOCKED_NOT_SYNC_OWNED attempt is logged; the booking status is unchanged.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::unenrol_user_by_rule
     */
    public function test_unenrol_user_by_rule_blocked_for_manual_booking(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        // Manual booking — syncruleid stays 0.
        $this->bookit_user($optionid, (int)$env['user1']->id);
        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);

        booking_enrolment::unenrol_user_by_rule($rule, (int)$env['user1']->id);

        // Status must be unchanged.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertNotEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);

        // Blocked attempt must be logged.
        $this->assertTrue(
            $DB->record_exists('booking_sync_attempts', [
                'syncruleid' => $rule->id,
                'userid'     => $env['user1']->id,
                'action'     => booking_enrolment::ACTION_UNENROL,
                'reasoncode' => booking_enrolment::REASON_BLOCKED_NOT_SYNC_OWNED,
            ])
        );
    }

    /**
     * Successful unenrol writes a booking_history entry with syncaction=unenrol metadata.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::unenrol_user_by_rule
     */
    public function test_unenrol_user_by_rule_writes_history_with_sync_metadata(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;
        $userid   = (int)$env['user1']->id;

        $this->bookit_user($optionid, $userid);

        $rule = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $userid,
        ]);
        singleton_service::destroy_booking_option_singleton($optionid);

        booking_enrolment::unenrol_user_by_rule($rule, $userid);

        $found = false;
        $records = $DB->get_records(
            'booking_history',
            ['userid' => $userid, 'optionid' => $optionid],
            'id DESC'
        );
        foreach ($records as $r) {
            $decoded = json_decode($r->json ?? '{}', true);
            if (($decoded['syncaction'] ?? null) === booking_enrolment::ACTION_UNENROL) {
                $this->assertEquals($rule->id, $decoded['syncruleid'] ?? null);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected booking_history entry with syncaction=unenrol');
    }

    /**
     * Adding a user to a cohort source triggers enrolment.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::process_source_membership
     */
    public function test_process_source_membership_enrols_on_add(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        // Enrol-only rule.
        $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0]
        );

        booking_enrolment::process_source_membership(
            'cohort',
            $env['cohort']->id,
            $env['user1']->id,
            true
        );

        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid'   => $env['user1']->id,
            ])
        );
    }

    /**
     * Removing a user from a cohort source triggers unenrol (soft-delete).
     *
     * @covers \mod_booking\local\sync\booking_enrolment::process_source_membership
     */
    public function test_process_source_membership_unenrols_on_remove(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->bookit_user($optionid, (int)$env['user1']->id);

        // Unenrol-only rule.
        $rule = $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 0, 'syncunenrol' => 1]
        );
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        singleton_service::destroy_booking_option_singleton($optionid);

        booking_enrolment::process_source_membership(
            'cohort',
            $env['cohort']->id,
            $env['user1']->id,
            false
        );

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);
    }

    /**
     * A disabled rule is ignored by process_source_membership.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::process_source_membership
     */
    public function test_process_source_membership_ignores_disabled_rules(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0, 'isenabled' => 0]
        );

        booking_enrolment::process_source_membership(
            'cohort',
            $env['cohort']->id,
            $env['user1']->id,
            true
        );

        $this->assertFalse(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid'   => $env['user1']->id,
            ]),
            'Disabled rule must not trigger enrolment'
        );
    }

    /**
     * Queueing source membership sync creates an adhoc task and does not run enrolment inline.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::queue_source_membership_sync
     */
    public function test_queue_source_membership_sync_queues_task_without_inline_processing(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0]
        );

        booking_enrolment::queue_source_membership_sync(
            'cohort',
            (int)$env['cohort']->id,
            (int)$env['user1']->id,
            true
        );

        $tasks = \core\task\manager::get_adhoc_tasks('\\mod_booking\\task\\process_source_membership_adhoc');
        $this->assertCount(1, $tasks);
        $this->assertFalse(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => (int)$env['user1']->id,
            ]),
            'Booking sync should be queued, not executed inline in the observer request'
        );
    }

    /**
     * The queued source membership adhoc task executes the same enrolment sync logic.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::queue_source_membership_sync
     * @covers \mod_booking\task\process_source_membership_adhoc::execute
     */
    public function test_queue_source_membership_sync_executes_via_adhoc_task(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0]
        );

        booking_enrolment::queue_source_membership_sync(
            'cohort',
            (int)$env['cohort']->id,
            (int)$env['user1']->id,
            true
        );

        $this->runAdhocTasks();

        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => (int)$env['user1']->id,
            ]),
            'Queued membership sync task should eventually create the booking answer'
        );
    }

    /**
     * All enabled rules for an option are flipped to isenabled=0.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::disable_rules_for_option
     */
    public function test_disable_rules_for_option_disables_all_enabled_rules(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $cohort2 = $this->getDataGenerator()->create_cohort();
        $rule1   = $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id);
        $rule2   = $this->insert_sync_rule($optionid, 'cohort', $cohort2->id);

        $count = booking_enrolment::disable_rules_for_option($optionid);

        $this->assertEquals(2, $count);
        $this->assertEquals(0, $DB->get_field('booking_sync_rules', 'isenabled', ['id' => $rule1->id]));
        $this->assertEquals(0, $DB->get_field('booking_sync_rules', 'isenabled', ['id' => $rule2->id]));
    }

    /**
     * Already-disabled rules are not counted and remain unchanged.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::disable_rules_for_option
     */
    public function test_disable_rules_for_option_skips_already_disabled(): void {
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $this->insert_sync_rule($optionid, 'cohort', $env['cohort']->id, ['isenabled' => 0]);

        $count = booking_enrolment::disable_rules_for_option($optionid);

        $this->assertEquals(0, $count);
    }

    /**
     * Applying a rule immediately enrols all cohort members.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::apply_rule_to_current_members
     */
    public function test_apply_rule_to_current_members_enrols_all_cohort_members(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        $rule = $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 1, 'syncunenrol' => 0]
        );

        $result = booking_enrolment::apply_rule_to_current_members($rule->id);

        $this->assertEquals(2, $result['enrolattempted']);
        $this->assertEquals(0, $result['unenrolattempted']);
        $this->assertTrue($DB->record_exists('booking_answers', ['optionid' => $optionid, 'userid' => $env['user1']->id]));
        $this->assertTrue($DB->record_exists('booking_answers', ['optionid' => $optionid, 'userid' => $env['user2']->id]));
    }

    /**
     * Applying a rule unenrols any rule-owned answers for users no longer in the source.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::apply_rule_to_current_members
     */
    public function test_apply_rule_to_current_members_unenrols_departed_users(): void {
        global $DB;
        $env      = $this->setup_sync_environment();
        $optionid = $env['option']->id;

        // Book user1 as rule-owned.
        $this->bookit_user($optionid, (int)$env['user1']->id);
        $rule = $this->insert_sync_rule(
            $optionid,
            'cohort',
            $env['cohort']->id,
            ['syncenrol' => 0, 'syncunenrol' => 1]
        );
        $DB->set_field('booking_answers', 'syncruleid', $rule->id, [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);

        // Remove user1 from the cohort (simulating departure).
        cohort_remove_member($env['cohort']->id, $env['user1']->id);
        singleton_service::destroy_booking_option_singleton($optionid);

        $result = booking_enrolment::apply_rule_to_current_members($rule->id);

        // User1 is no longer a member, so unenrol is attempted.
        $this->assertGreaterThanOrEqual(1, $result['unenrolattempted']);

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid'   => $env['user1']->id,
        ]);
        $this->assertNotEmpty($answer);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);
    }

    /**
     * apply_rule_to_current_members() returns zeros when the rule is disabled or missing.
     *
     * @covers \mod_booking\local\sync\booking_enrolment::apply_rule_to_current_members
     */
    public function test_apply_rule_to_current_members_returns_zeros_for_disabled_rule(): void {
        $env  = $this->setup_sync_environment();
        $rule = $this->insert_sync_rule(
            $env['option']->id,
            'cohort',
            $env['cohort']->id,
            ['isenabled' => 0]
        );

        $result = booking_enrolment::apply_rule_to_current_members($rule->id);

        $this->assertEquals(0, $result['enrolattempted']);
        $this->assertEquals(0, $result['unenrolattempted']);
    }
}

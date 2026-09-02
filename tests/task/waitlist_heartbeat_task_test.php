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
 * Tests for waitlist_heartbeat_task (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.2, T7).
 * find_stalled_options()'s narrow query scope (all 4 shapes: stalled/no-candidates/full/
 * already-offered) is already exhaustively covered by the target-behaviour
 * waitlist_target_b5_heartbeat_test.php - this file focuses on what B5 does NOT test: execute()'s
 * own throttle logic (the configurable effective interval, clamped to the 5-minute floor), using
 * $this->mock_clock_with_frozen() throughout.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\progression_factory;
use mod_booking\singleton_service;
use mod_booking\task\waitlist_heartbeat_task;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * §4.2/T7 tests for waitlist_heartbeat_task.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 */
final class waitlist_heartbeat_task_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Creates a course + booking + one option with a paid candidate on the waiting list and
     * exactly one free seat, plus an ALWAYS send_mail_interval rule - reconcile() is deliberately
     * never called, simulating a genuinely lost trigger. Paid (not free) so the candidate ends up
     * with an OPEN OFFER rather than being autobooked - simplifies assertions to get_open_offers().
     *
     * @param string $optiontext unique per test to avoid any fixture collisions
     * @param int $waitlistrecycling 0 (default, matches the DB default) = end, 1 = recycle
     * @return array [int $optionid, \stdClass $candidate]
     */
    private function create_stalled_option(string $optiontext, int $waitlistrecycling = 0): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        if (!$DB->record_exists('user_info_field', ['shortname' => 'pricecat'])) {
            $this->getDataGenerator()->create_custom_profile_field([
                'datatype' => 'text',
                'shortname' => 'pricecat',
                'name' => 'pricecat',
            ]);
        }
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Heartbeat Test',
            'eventtype' => 'Test',
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
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        if (!$DB->record_exists('booking_pricecategories', ['identifier' => 'heartbeatpaidcat'])) {
            $plugingenerator->create_pricecategory((object) [
                'ordernum' => 1,
                'name' => 'heartbeatpaidcat',
                'identifier' => 'heartbeatpaidcat',
                'defaultvalue' => 80,
                'pricecatsortorder' => 1,
            ]);
        }

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optiontext;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        $optionid = (int) $option->id;
        $DB->set_field('booking_options', 'waitlistrecycling', $waitlistrecycling, ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $actstr = json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']);
        $plugingenerator->create_rule([
            'name' => 'heartbeat-rule-' . $optiontext,
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $candidate = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'heartbeatpaidcat']);
        $this->getDataGenerator()->enrol_user($candidate->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $candidate->id,
            'optionid' => $optionid,
            'timemodified' => 100,
            'timecreated' => 100,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);

        return [$optionid, $candidate];
    }

    /**
     * T7: execute() must self-heal a genuinely stalled option (reconcile() never called).
     */
    public function test_execute_reconciles_a_stalled_option(): void {
        [$optionid] = $this->create_stalled_option('heartbeat-basic');

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();
        $this->assertCount(0, $repository->get_open_offers($optionid), 'Precondition: no offer yet.');

        (new waitlist_heartbeat_task())->execute();

        $this->assertCount(
            1,
            $repository->get_open_offers($optionid),
            'T7: the stalled option must be self-healed by execute().'
        );
    }

    /**
     * T7: a second execute() call within the configured interval must be a no-op - a stalled
     * option that only became stalled AFTER the first run must not be picked up yet.
     */
    public function test_execute_is_throttled_within_the_configured_interval(): void {
        $clock = $this->mock_clock_with_frozen(2000000000);
        set_config('waitlistheartbeatinterval', 900, 'booking'); // 15 minutes.

        $this->setAdminUser();
        (new waitlist_heartbeat_task())->execute(); // First run: nothing stalled yet, just primes lastrun.

        [$optionid] = $this->create_stalled_option('heartbeat-throttled');
        $repository = new db_waitlist_offer_repository();

        $clock->bump(600); // 10 minutes later - still within the 15-minute interval.
        (new waitlist_heartbeat_task())->execute();

        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'T7: within the configured interval, execute() must not run its work again.'
        );

        $clock->bump(400); // Now 1000s (~16.7min) since the first run - past the interval.
        (new waitlist_heartbeat_task())->execute();

        $this->assertCount(
            1,
            $repository->get_open_offers($optionid),
            'T7: once the interval has elapsed, execute() must self-heal the now-stalled option.'
        );
    }

    /**
     * T7: the 5-minute floor must hold even if the admin configures a shorter interval.
     */
    public function test_execute_never_reruns_more_often_than_the_five_minute_floor(): void {
        $clock = $this->mock_clock_with_frozen(3000000000);
        set_config('waitlistheartbeatinterval', 60, 'booking'); // Deliberately below the floor.

        $this->setAdminUser();
        (new waitlist_heartbeat_task())->execute();

        [$optionid] = $this->create_stalled_option('heartbeat-floor');
        $repository = new db_waitlist_offer_repository();

        $clock->bump(120); // 2 minutes later - past the configured 60s, but under the 300s floor.
        (new waitlist_heartbeat_task())->execute();

        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'T7: the 5-minute floor must be enforced even when a shorter interval is configured.'
        );

        $clock->bump(200); // Now 320s since the first run - past the 300s floor.
        (new waitlist_heartbeat_task())->execute();

        $this->assertCount(
            1,
            $repository->get_open_offers($optionid),
            'T7: once the 5-minute floor has genuinely elapsed, execute() must do its work.'
        );
    }

    /**
     * Waitlist-recycling: the sole candidate lets their offer expire (locked out, K4), which makes
     * the option fully flagged. With waitlistrecycling=1, the next heartbeat must reset that lock
     * and re-offer to the very same candidate.
     */
    public function test_execute_recycles_a_fully_flagged_option_when_recycling_enabled(): void {
        $clock = $this->mock_clock_with_frozen(4000000000);
        [$optionid, $candidate] = $this->create_stalled_option('heartbeat-recycle', 1);

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();

        // Produce the first offer directly via the reconciler (not via the heartbeat, which would
        // also be a valid path, but reconcile() is more direct here).
        progression_factory::get()->reconcile($optionid, 'test-setup');
        $firstoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $firstoffers, 'Precondition: the candidate must have an open offer.');

        $repository->transition($firstoffers[0], new expired());
        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'Precondition: the expired offer must lock the candidate out (K4).'
        );

        $clock->bump(1000); // Distinct roundid from the setup reconcile() above.
        (new waitlist_heartbeat_task())->execute();

        $this->assertFalse(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'waitlistrecycling=1: the heartbeat must reset the K4 lock once fully flagged.'
        );
        $this->assertCount(
            1,
            $repository->get_open_offers($optionid),
            'waitlistrecycling=1: the heartbeat must re-offer to the same candidate after resetting.'
        );
    }

    /**
     * Same fully-flagged scenario, but waitlistrecycling=0 (the default) - the heartbeat must
     * leave the candidate locked out and must not create a new offer.
     */
    public function test_execute_does_not_recycle_when_recycling_disabled(): void {
        $clock = $this->mock_clock_with_frozen(4100000000);
        [$optionid, $candidate] = $this->create_stalled_option('heartbeat-norecycle', 0);

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();

        progression_factory::get()->reconcile($optionid, 'test-setup');
        $firstoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $firstoffers, 'Precondition: the candidate must have an open offer.');

        $repository->transition($firstoffers[0], new expired());

        $clock->bump(1000);
        (new waitlist_heartbeat_task())->execute();

        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'waitlistrecycling=0: the K4 lock must stay in place - this is the existing K4=K7 default.'
        );
        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'waitlistrecycling=0: the heartbeat must not create a new offer for a locked-out candidate.'
        );
    }

    /**
     * A candidate who actively declined (K7) must stay locked out forever, even with
     * waitlistrecycling=1 - recycling only ever resets K4 (expired) locks, never K7 (declined).
     */
    public function test_execute_never_recycles_an_actively_declined_candidate(): void {
        $clock = $this->mock_clock_with_frozen(4200000000);
        [$optionid, $candidate] = $this->create_stalled_option('heartbeat-declinelock', 1);

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();

        progression_factory::get()->reconcile($optionid, 'test-setup');
        $firstoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $firstoffers, 'Precondition: the candidate must have an open offer.');

        $repository->transition($firstoffers[0], new \mod_booking\local\waitlist\offer_statuses\declined());

        $clock->bump(1000);
        (new waitlist_heartbeat_task())->execute();

        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'K7: an active decline must stay locked out even with waitlistrecycling=1.'
        );
        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'K7: a declined candidate must never be re-offered, regardless of waitlistrecycling.'
        );
    }
}

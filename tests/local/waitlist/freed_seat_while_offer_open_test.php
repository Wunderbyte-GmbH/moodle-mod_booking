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
 * A seat that becomes free while another seat of the same option is still on offer must be offered
 * too - right away when it is freed, and at the latest on the next heartbeat if that trigger got lost.
 *
 * Background: an open offer is not a booked place, so booking_answers::is_fully_booked() already
 * reports "not full" after the first freed seat was offered. A second cancellation then used to skip
 * the reconcile (check_if_free_to_book_again() only reacted when the option was full before), and the
 * heartbeat skipped every option with an open offer - the second seat stayed empty until the first
 * offer was resolved, up to the whole payment deadline.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking\task\waitlist_heartbeat_task;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * Freed seats are offered even while another offer of the same option is open.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::check_if_free_to_book_again
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_stalled_options
 */
final class freed_seat_while_offer_open_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * A full option with $seats booked places and two paying people (anna, ben) on the waiting list,
     * with a 24 hour interval rule.
     *
     * @param int $seats
     * @return array [int $optionid, stdClass[] $booked, stdClass $anna, stdClass $ben]
     */
    private function create_full_option(int $seats): array {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking('Freed seat while offer open');
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, $seats, 5);
        $this->create_interval_rule(0, 'Ein Platz ist frei', 'Bitte innerhalb von 24 Stunden bezahlen', 24 * 60);

        $booked = [];
        for ($i = 0; $i < $seats; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $DB->insert_record('booking_answers', (object) [
                'bookingid' => 0,
                'userid' => $user->id,
                'optionid' => $optionid,
                'timemodified' => 50 + $i,
                'timecreated' => 50 + $i,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'status' => 0,
            ]);
            $booked[] = $user;
        }
        $anna = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $ben = $this->waitlist_user($course, $optionid, 'paidcat', 101);

        booking_option::purge_cache_for_answers($optionid);
        $this->setAdminUser();

        return [$optionid, $booked, $anna, $ben];
    }

    /**
     * Cancels the user's booking the way the UI does it.
     *
     * @param int $optionid
     * @param stdClass $user
     * @return void
     */
    private function cancel(int $optionid, stdClass $user): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
        booking_option::purge_cache_for_answers($optionid);
        $option->user_delete_response((int) $user->id);
        booking_option::purge_cache_for_answers($optionid);
        $this->setAdminUser();
    }

    /**
     * User ids with an open offer on the option.
     *
     * @param int $optionid
     * @return int[]
     */
    private function offered_userids(int $optionid): array {
        $userids = array_map(fn($offer) => $offer->userid, (new db_waitlist_offer_repository())->get_open_offers($optionid));
        sort($userids);
        return $userids;
    }

    /**
     * Two seats become free one after the other while only paying people wait: both must be offered
     * immediately - the second one although the first seat is still on offer.
     */
    public function test_second_freed_seat_is_offered_immediately_while_first_offer_is_open(): void {
        [$optionid, $booked, $anna, $ben] = $this->create_full_option(2);
        $sink = $this->redirectMessages();

        $this->cancel($optionid, $booked[0]);
        $this->assertSame([(int) $anna->id], $this->offered_userids($optionid), 'Precondition: Anna gets the first seat.');

        $this->cancel($optionid, $booked[1]);
        $sink->close();

        $expected = [(int) $anna->id, (int) $ben->id];
        sort($expected);
        $this->assertSame(
            $expected,
            $this->offered_userids($optionid),
            'The second freed seat must be offered to Ben right away, although Anna\'s offer is still open.'
        );
    }

    /**
     * Safety net: if a seat becomes free without any trigger (e.g. a lost event), the heartbeat must
     * offer it even though another offer of the same option is still open.
     */
    public function test_heartbeat_offers_a_free_seat_while_another_offer_is_open(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(4500000000);
        [$optionid, $booked, $anna, $ben] = $this->create_full_option(2);
        $sink = $this->redirectMessages();

        $this->cancel($optionid, $booked[0]);
        $this->assertSame([(int) $anna->id], $this->offered_userids($optionid), 'Precondition: Anna gets the first seat.');

        // The second seat becomes free without any trigger.
        $DB->set_field(
            'booking_answers',
            'waitinglist',
            MOD_BOOKING_STATUSPARAM_DELETED,
            ['optionid' => $optionid, 'userid' => $booked[1]->id]
        );
        booking_option::purge_cache_for_answers($optionid);
        $this->assertSame([(int) $anna->id], $this->offered_userids($optionid), 'Precondition: no trigger, no new offer yet.');

        $clock->bump(1000);
        (new waitlist_heartbeat_task())->execute();
        $sink->close();

        $expected = [(int) $anna->id, (int) $ben->id];
        sort($expected);
        $this->assertSame(
            $expected,
            $this->offered_userids($optionid),
            'The heartbeat must offer the free seat to Ben, although Anna\'s offer is still open.'
        );
    }

    /**
     * Guard: an open offer occupies its seat. With no seat left beyond the one on offer, neither a
     * heartbeat nor anything else may hand out a second offer.
     */
    public function test_no_second_offer_when_no_seat_is_left(): void {
        $clock = $this->mock_clock_with_frozen(4600000000);
        [$optionid, $booked, $anna] = $this->create_full_option(1);
        $sink = $this->redirectMessages();

        $this->cancel($optionid, $booked[0]);
        $this->assertSame([(int) $anna->id], $this->offered_userids($optionid), 'Precondition: Anna gets the only seat.');

        $clock->bump(1000);
        (new waitlist_heartbeat_task())->execute();
        $sink->close();

        $this->assertSame(
            [(int) $anna->id],
            $this->offered_userids($optionid),
            'The only seat is on offer to Anna - Ben must not get an offer as well.'
        );
    }
}

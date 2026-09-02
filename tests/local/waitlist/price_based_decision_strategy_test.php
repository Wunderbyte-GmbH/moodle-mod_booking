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
 * Tests for price_based_decision_strategy (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.1):
 * K3/K4/P1/P2. No booking_bookit()/waiting-list choreography is needed - decide() is a pure
 * function of an option's price configuration and a user, it never touches booking_answers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * K3/K4/P1/P2 tests for price_based_decision_strategy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\price_based_decision_strategy::decide
 */
final class price_based_decision_strategy_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Note: singleton_service caches price categories per user id statically across the
        // whole PHPUnit process - resetAfterTest() only resets the DB, and auto-increment ids
        // restart fresh each time, so a stale cache entry from an EARLIER test can silently leak
        // onto a DIFFERENT test's same-numbered user (the exact A9 finding). Force a reset here.
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Creates a course + booking (no option yet) with a custom 'pricecat' profile field wired
     * up as the price-category selector.
     *
     * @return array [\stdClass $course, \stdClass $teacher, \stdClass $booking]
     */
    private function prepare_course_and_booking(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Price Strategy Test',
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

        return [$course, $teacher, $booking];
    }

    /**
     * Creates one useprice=1 option. Must be called AFTER all needed price categories already
     * exist - a category created after the option would not retroactively get a price row on
     * it.
     *
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param \stdClass $booking
     * @return int the new option's id
     */
    private function create_priced_option(\stdClass $course, \stdClass $teacher, \stdClass $booking): int {
        $this->setAdminUser();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'priced-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
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
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * K3: a candidate whose resolved price is 0 must be autobooked.
     */
    public function test_k3_zero_price_returns_autobook(): void {
        [$course, $teacher, $booking] = $this->prepare_course_and_booking();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'freecat',
            'identifier' => 'freecat',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);

        $optionid = $this->create_priced_option($course, $teacher, $booking);

        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'freecat']);
        $freshuser = singleton_service::get_instance_of_user($user->id);
        $candidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, $freshuser);

        $strategy = new price_based_decision_strategy();
        $this->assertEquals(booking_decision::AUTOBOOK, $strategy->decide($candidate));
    }

    /**
     * K4: a candidate whose resolved price is greater than 0 must be offered, not autobooked.
     */
    public function test_k4_nonzero_price_returns_offer(): void {
        [$course, $teacher, $booking] = $this->prepare_course_and_booking();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        $optionid = $this->create_priced_option($course, $teacher, $booking);

        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $freshuser = singleton_service::get_instance_of_user($user->id);
        $candidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, $freshuser);

        $strategy = new price_based_decision_strategy();
        $this->assertEquals(booking_decision::OFFER, $strategy->decide($candidate));
    }

    /**
     * P1: the price must be looked up fresh on every decide() call, reflecting a profile-field
     * change immediately - not a value cached from an earlier lookup for the same user. Direct
     * application of the A9 finding (singleton_service::get_pricecategory_for_user()'s
     * per-instance cache requires a full singleton_service::destroy_instance() to see a change).
     */
    public function test_p1_price_is_looked_up_live_not_cached(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'freecat',
            'identifier' => 'freecat',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 2,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 2,
        ]);

        $optionid = $this->create_priced_option($course, $teacher, $booking);

        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $strategy = new price_based_decision_strategy();

        $firstuser = singleton_service::get_instance_of_user($user->id);
        $firstcandidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, $firstuser);
        $this->assertEquals(
            booking_decision::OFFER,
            $strategy->decide($firstcandidate),
            'Precondition: starts out paid -> OFFER.'
        );

        $updateduser = new \stdClass();
        $updateduser->id = $user->id;
        $updateduser->profile_field_pricecat = 'freecat';
        profile_save_data($updateduser);
        // See A9: only a full instance reset picks up the DB change here.
        singleton_service::destroy_instance();

        $freshuser = singleton_service::get_instance_of_user($user->id);
        $freshcandidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, $freshuser);
        $this->assertEquals(
            booking_decision::AUTOBOOK,
            $strategy->decide($freshcandidate),
            'P1: the SAME strategy instance must reflect the new price immediately - live ' .
            'lookup, not a value cached from the earlier decide() call for this user.'
        );
    }

    /**
     * P2: a price lookup with no resolvable 'price' key at all (pricecategoryfallback=2, no
     * matching category) must be treated exactly like price 0 (AUTOBOOK), with zero PHP
     * warnings/notices - same technique as A8 (custom error handler, restore_error_handler()
     * rather than set_error_handler($previous) to avoid a "did not remove its own error
     * handlers" risky-test flag).
     */
    public function test_p2_missing_price_key_treated_as_free_no_warning(): void {
        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        set_config('pricecategoryfallback', 2, 'booking');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'somecat',
            'identifier' => 'somecat',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ]);

        $optionid = $this->create_priced_option($course, $teacher, $booking);

        // This user's profile field matches NOTHING configured - with fallback=2 (no default),
        // price::get_price() returns [] entirely (no 'price' key at all), not price 0.
        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'nomatch']);
        $freshuser = singleton_service::get_instance_of_user($user->id);
        $candidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, $freshuser);

        $warningtriggered = false;
        set_error_handler(function () use (&$warningtriggered) {
            $warningtriggered = true;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        $strategy = new price_based_decision_strategy();
        $decision = $strategy->decide($candidate);

        restore_error_handler();

        $this->assertFalse($warningtriggered, 'P2: no PHP warning/notice may be triggered.');
        $this->assertEquals(
            booking_decision::AUTOBOOK,
            $decision,
            'P2: a missing price key must be treated exactly like price 0.'
        );
    }
}

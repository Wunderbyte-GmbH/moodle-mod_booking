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
 * Tests the two guards between an enrollink bundle and the cancellation of its booking.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\bo_availability\conditions\confirmcancel;
use mod_booking\local\mobile\customformstore;
use mod_booking\shopping_cart\service_provider;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the two guards between an enrollink bundle and the cancellation of its booking.
 *
 * 1. A booker whose enrollink has been used by somebody else cannot cancel the booking anymore
 *    (cancel button replaced by an explanation, bookit() refuses, shopping cart callback denies).
 * 2. An enrollink whose booking has been cancelled is dead: nobody can book via it anymore.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrollink_cancellation_guard_test extends booking_advanced_testcase {
    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        enrollink::destroy_instances();
    }

    /**
     * Once somebody is booked via the enrollink, the booker cannot cancel anymore - until that
     * user is cancelled again.
     *
     * @covers \mod_booking\enrollink::cancellation_blocked_by_used_enrollink
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::render_button
     * @covers \mod_booking\bo_availability\conditions\confirmcancel::is_available
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\shopping_cart\service_provider::allowed_to_cancel
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_booker_cannot_cancel_while_enrollink_is_used(array $bdata): void {
        global $DB;

        ['settings' => $settings, 'booker' => $booker, 'students' => $students, 'erlid' => $erlid]
            = $this->create_option_with_bundle($bdata, 3);
        [$student1] = $students;
        $boinfo = new bo_info($settings);
        $cancelmyself = new cancelmyself();

        // Before anybody used the link, the booker gets a real cancel button and may cancel.
        $this->setUser($booker);
        $this->assertFalse(enrollink::cancellation_blocked_by_used_enrollink($booker->id, $settings->id));
        $this->assertFalse($cancelmyself->is_available($settings, $booker->id), 'Cancel condition must offer cancelling');
        [, $buttondata] = $cancelmyself->render_button($settings, $booker->id);
        $this->assertStringContainsString('bo-cancel-button', $buttondata['main']['class']);
        $this->assertTrue(service_provider::allowed_to_cancel('option', $settings->id, $booker->id));

        // Student1 books via the enrollink.
        $this->setUser($student1);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($erlid);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_SUCCESS, $enrollinkobj->enrol_user($student1->id));
        $this->assertEquals(1, $enrollinkobj->free_places_left());
        singleton_service::destroy_booking_answers($settings->id);

        // Now the booker is blocked: the cancel slot shows the explanation instead of a button...
        $this->setUser($booker);
        $this->assertTrue(enrollink::cancellation_blocked_by_used_enrollink($booker->id, $settings->id));
        $this->assertFalse(
            $cancelmyself->is_available($settings, $booker->id),
            'Cancel condition must stay in the results so the explanation is rendered'
        );
        [, $buttondata] = $cancelmyself->render_button($settings, $booker->id);
        $this->assertSame(
            get_string('bocondcancelmyselfenrollinkused', 'mod_booking'),
            $buttondata['main']['label']
        );
        $this->assertStringNotContainsString('bo-cancel-button', $buttondata['main']['class']);
        $this->assertStringNotContainsString('shopping-cart-cancel-button', $buttondata['main']['class']);
        $this->assertSame('', $buttondata['main']['role'], 'The explanation must not be a clickable button');

        // The fully rendered button area (booked state + cancel slot) carries the explanation and no cancel button.
        $html = booking_bookit::render_bookit_button($settings, $booker->id);
        $this->assertStringContainsString(get_string('bocondcancelmyselfenrollinkused', 'mod_booking'), $html);
        $this->assertStringNotContainsString('bo-cancel-button', $html);
        $this->assertStringContainsString('booking-button-mainarea', $html);

        // ...and the link user is not affected at all.
        $this->assertFalse(enrollink::cancellation_blocked_by_used_enrollink($student1->id, $settings->id));
        $this->assertTrue(service_provider::allowed_to_cancel('option', $settings->id, $student1->id));

        // The shopping cart callback denies the booker's cancellation (explicit and current user).
        $this->assertFalse(service_provider::allowed_to_cancel('option', $settings->id, $booker->id));
        $this->assertFalse(service_provider::allowed_to_cancel('option', $settings->id));

        // The cancel flow of bookit() does nothing: no cancellation mark, no confirm step, still booked.
        booking_bookit::bookit('option', $settings->id, $booker->id);
        $cache = cache::make('mod_booking', 'confirmbooking');
        $cachedata = $cache->get($booker->id);
        $this->assertTrue(
            empty($cachedata[$booker->id . '_' . $settings->id . '_cancel']),
            'A blocked booking must not be marked for cancellation'
        );
        $this->assertTrue((new confirmcancel())->is_available($settings, $booker->id));
        booking_bookit::bookit('option', $settings->id, $booker->id);
        [$id] = $boinfo->is_available($settings->id, $booker->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        $this->assert_answer_state($settings->id, $booker->id, MOD_BOOKING_STATUSPARAM_BOOKED);

        // Even a stale "confirm cancellation" (marked before the link was used, e.g. a second tab)
        // is refused by the confirm step and by bookit().
        $cache->set($booker->id, [$booker->id . '_' . $settings->id . '_cancel' => time()]);
        $this->assertTrue(
            (new confirmcancel())->is_available($settings, $booker->id),
            'A marked cancellation must not be confirmable while the enrollink is used'
        );
        booking_bookit::bookit('option', $settings->id, $booker->id);
        $this->assert_answer_state($settings->id, $booker->id, MOD_BOOKING_STATUSPARAM_BOOKED);
        $cache->delete($booker->id);

        // Student1 cancels again: the booker's places are free again, so the booker may cancel.
        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $this->assertTrue($option->user_delete_response($student1->id));
        singleton_service::destroy_booking_answers($settings->id);

        $this->setUser($booker);
        $this->assertFalse(enrollink::cancellation_blocked_by_used_enrollink($booker->id, $settings->id));
        $this->assertTrue(service_provider::allowed_to_cancel('option', $settings->id, $booker->id));
        [, $buttondata] = $cancelmyself->render_button($settings, $booker->id);
        $this->assertStringContainsString('bo-cancel-button', $buttondata['main']['class']);
        $html = booking_bookit::render_bookit_button($settings, $booker->id);
        $this->assertStringContainsString('bo-cancel-button', $html);
        $this->assertStringNotContainsString(get_string('bocondcancelmyselfenrollinkused', 'mod_booking'), $html);

        // The regular two-click cancel flow works again.
        booking_bookit::bookit('option', $settings->id, $booker->id);
        [$id] = $boinfo->is_available($settings->id, $booker->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $response = booking_bookit::bookit('option', $settings->id, $booker->id);
        $this->assertEquals(1, $response['status']);
        $this->assertEquals('cancelled', $response['message']);
        $this->assert_answer_state($settings->id, $booker->id, MOD_BOOKING_STATUSPARAM_DELETED);
    }

    /**
     * Once the booker cancelled, the enrollink is dead: remaining places cannot be booked anymore.
     *
     * @covers \mod_booking\enrollink::enrolment_blocking
     * @covers \mod_booking\enrollink::bundle_booking_is_active
     * @covers \mod_booking\enrollink::enrol_user
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_is_dead_after_booker_cancelled(array $bdata): void {
        global $DB;

        ['settings' => $settings, 'booker' => $booker, 'students' => $students, 'erlid' => $erlid]
            = $this->create_option_with_bundle($bdata, 3);
        [$student1, $student2] = $students;

        // The link works while the booking is active.
        $this->setUser($student1);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($erlid);
        $this->assertTrue($enrollinkobj->bundle_booking_is_active());
        $this->assertEquals(0, $enrollinkobj->enrolment_blocking());
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_SUCCESS, $enrollinkobj->enrol_user($student1->id));
        $this->assertEquals(1, $enrollinkobj->free_places_left(), 'One place must be left for a third user');
        singleton_service::destroy_booking_answers($settings->id);

        // The booker is cancelled (by an admin - the booker cannot, see the other test).
        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $this->assertTrue($option->user_delete_response($booker->id));
        singleton_service::destroy_booking_answers($settings->id);
        $this->assert_answer_state($settings->id, $booker->id, MOD_BOOKING_STATUSPARAM_DELETED);

        // Student1, booked via the link before, stays booked.
        $this->assert_answer_state($settings->id, $student1->id, MOD_BOOKING_STATUSPARAM_BOOKED);

        // The link is dead now, although one place would still be free in the bundle.
        $this->setUser($student2);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($erlid);
        $this->assertEquals(1, $enrollinkobj->free_places_left());
        $this->assertFalse($enrollinkobj->bundle_booking_is_active());
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_BUNDLE_CANCELLED, $enrollinkobj->enrolment_blocking());
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_BUNDLE_CANCELLED, $enrollinkobj->enrol_user($student2->id));
        $this->assertSame(
            get_string('enrollink:8', 'mod_booking'),
            $enrollinkobj->get_readable_info(MOD_BOOKING_AUTOENROL_STATUS_BUNDLE_CANCELLED)
        );
        $this->assertFalse(
            $DB->record_exists('booking_answers', ['optionid' => $settings->id, 'userid' => $student2->id]),
            'Nobody must be booked via a dead enrollink'
        );
        $this->assertEquals(1, $enrollinkobj->free_places_left(), 'No bundle place may be consumed via a dead link');

        // A bundle pointing to a hard-deleted answer is dead as well.
        $DB->delete_records('booking_answers', ['optionid' => $settings->id, 'userid' => $booker->id]);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($erlid);
        $this->assertFalse($enrollinkobj->bundle_booking_is_active());
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_BUNDLE_CANCELLED, $enrollinkobj->enrolment_blocking());

        // Legacy bundles without answer reference cannot be verified and keep working.
        $DB->set_field('booking_enrollink_bundles', 'baid', 0, ['erlid' => $erlid]);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($erlid);
        $this->assertTrue($enrollinkobj->bundle_booking_is_active());
        $this->assertEquals(0, $enrollinkobj->enrolment_blocking());
    }

    /**
     * Creates a booking option with an "enrol multiple users" form element, books $places places
     * for the booker (who is enrolled themselves) and returns the created enrollink bundle.
     *
     * @param array $bdata
     * @param int $places
     * @return array settings, booker, students (2, enrolled in the course), erlid
     */
    private function create_option_with_bundle(array $bdata, int $places): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booker = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['autoenrol'] = 1;
        // Users may cancel themselves - that is what the guard has to block.
        $bdata['cancancelbook'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($booker->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (enrollink cancellation guard)';
        $record->importing = 1;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->maxanswers = 10;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;

        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        enrollink::destroy_instances();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // The booker books $places places including themselves.
        $this->setUser($booker);
        singleton_service::destroy_user($booker->id);
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $booker->id,
            'customform_enrolusersaction_1' => $places,
            'customform_enroluserwhobookedcheckbox_enrolusersaction_1' => 1,
        ];
        $customformstore = new customformstore($booker->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        booking_bookit::bookit('option', $settings->id, $booker->id);
        booking_bookit::bookit('option', $settings->id, $booker->id);
        [$id] = $boinfo->is_available($settings->id, $booker->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // The booking created the enrollink bundle on the booker's answer.
        $bundle = $DB->get_record('booking_enrollink_bundles', ['optionid' => $option->id], '*', MUST_EXIST);
        $this->assertEquals($places, (int)$bundle->places);
        $bookeranswer = $DB->get_record('booking_answers', ['id' => $bundle->baid], '*', MUST_EXIST);
        $this->assertEquals($booker->id, (int)$bookeranswer->userid);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$bookeranswer->waitinglist);

        enrollink::destroy_instances();
        $this->assertEquals($places - 1, enrollink::get_instance($bundle->erlid)->free_places_left());

        return [
            'settings' => $settings,
            'booker' => $booker,
            'students' => [$student1, $student2],
            'erlid' => $bundle->erlid,
        ];
    }

    /**
     * Asserts the state of the single booking answer of the user for the option.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $expectedstate
     * @return void
     */
    private function assert_answer_state(int $optionid, int $userid, int $expectedstate): void {
        global $DB;

        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid]);
        $this->assertCount(1, $answers);
        $this->assertEquals($expectedstate, (int)reset($answers)->waitinglist);
    }

    /**
     * Data provider with minimal booking instance settings.
     *
     * @return array
     */
    public static function booking_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking 1',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}

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
 * Tests for the bulk rating modal of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\modal_set_rating;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->dirroot/rating/lib.php");

/**
 * The modal was migrated from the old report.php bulk action postratingsubmit
 * and writes through booking_rate() (standard Moodle rating API with
 * component mod_booking, ratingarea bookingoption, itemid = booking_answers.id).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_set_rating_test extends booking_advanced_testcase {
    /**
     * Submitting a rating for checked answers writes one {rating} row per
     * answer; the rater's own booking answer is skipped silently.
     *
     * @covers \mod_booking\form\modal_set_rating::process_dynamic_submission
     */
    public function test_process_writes_ratings_and_skips_own_answer(): void {
        global $DB, $USER;

        $this->setAdminUser();

        [$settings, $answerids, $context] = $this->create_booked_option();

        // The admin (= rater) books the option too - their own answer must be skipped.
        booking_bookit::bookit('option', $settings->id, (int)$USER->id);
        booking_bookit::bookit('option', $settings->id, (int)$USER->id);
        $ownanswerid = (int)$DB->get_field(
            'booking_answers',
            'id',
            ['optionid' => $settings->id, 'userid' => $USER->id]
        );
        $this->assertNotEmpty($ownanswerid);

        $checkedids = array_merge($answerids, [$ownanswerid]);
        $this->submit_rating($settings, $checkedids, 7);

        foreach ($answerids as $answerid) {
            $rating = $DB->get_record('rating', [
                'component' => 'mod_booking',
                'ratingarea' => 'bookingoption',
                'contextid' => $context->id,
                'itemid' => $answerid,
            ]);
            $this->assertNotEmpty($rating, "A rating row for answer $answerid must exist.");
            $this->assertEquals(7, (int)$rating->rating);
            $this->assertEquals((int)$USER->id, (int)$rating->userid);
        }

        // No rating for the rater's own answer.
        $this->assertFalse(
            $DB->record_exists('rating', [
                'component' => 'mod_booking',
                'ratingarea' => 'bookingoption',
                'itemid' => $ownanswerid,
            ])
        );
    }

    /**
     * Submitting the RATING_UNSET_RATING entry removes existing ratings.
     *
     * @covers \mod_booking\form\modal_set_rating::process_dynamic_submission
     */
    public function test_unset_rating_removes_existing_ratings(): void {
        global $DB;

        $this->setAdminUser();

        [$settings, $answerids] = $this->create_booked_option();

        $this->submit_rating($settings, $answerids, 5);
        $this->assertEquals(
            count($answerids),
            $DB->count_records('rating', ['component' => 'mod_booking', 'ratingarea' => 'bookingoption'])
        );

        $this->submit_rating($settings, $answerids, RATING_UNSET_RATING);
        $this->assertEquals(
            0,
            $DB->count_records('rating', ['component' => 'mod_booking', 'ratingarea' => 'bookingoption'])
        );
    }

    /**
     * A rating outside the numeric scale never reaches the rating API: the
     * select element discards values that are not among its options
     * (exportValue cleaning), so nothing is written.
     *
     * @covers \mod_booking\form\modal_set_rating::process_dynamic_submission
     */
    public function test_rating_outside_scale_writes_nothing(): void {
        global $DB;

        $this->setAdminUser();

        [$settings, $answerids] = $this->create_booked_option();

        $form = $this->build_form($settings, $answerids, 99);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();

        $this->assertEquals(
            0,
            $DB->count_records('rating', ['component' => 'mod_booking', 'ratingarea' => 'bookingoption'])
        );
    }

    /**
     * The rating select offers the unset entry plus the numeric scale values,
     * matching the instance scale.
     *
     * @covers \mod_booking\form\modal_set_rating::rating_choices
     */
    public function test_rating_choices_for_numeric_scale(): void {
        $this->setAdminUser();

        [$settings] = $this->create_booked_option();

        $choices = modal_set_rating::rating_choices((int)$settings->cmid);

        $this->assertArrayHasKey(RATING_UNSET_RATING, $choices);
        $this->assertArrayHasKey(0, $choices);
        $this->assertArrayHasKey(10, $choices);
        $this->assertArrayNotHasKey(11, $choices);
    }

    /**
     * The effective permission gate is the plugin rating permission
     * (mod/booking:rate, enforced inside booking_rate): students pass the
     * moodle/rating:rate pre-check (allowed for the student archetype, same
     * as on the old report.php) but are rejected when actually rating.
     *
     * @covers \mod_booking\form\modal_set_rating::process_dynamic_submission
     */
    public function test_student_without_plugin_rate_permission_is_rejected(): void {
        $this->setAdminUser();

        [$settings, $answerids, , $students] = $this->create_booked_option();

        $this->setUser($students[0]);

        $this->expectException(moodle_exception::class);
        $this->submit_rating($settings, $answerids, 5);
    }

    /**
     * With ratings disabled on the instance (assessed = 0) the access check
     * rejects everyone - even admins.
     *
     * @covers \mod_booking\form\modal_set_rating::check_access_for_dynamic_submission
     */
    public function test_check_access_requires_assessed_instance(): void {
        global $DB;

        $this->setAdminUser();

        [$settings, $answerids] = $this->create_booked_option();

        $DB->set_field('booking', 'assessed', 0, ['id' => $settings->bookingid]);
        // The instance settings cache uses static acceleration, so purge via
        // its invalidation event (like booking_update_instance does).
        \cache_helper::purge_by_event('setbackbookinginstances');
        singleton_service::destroy_instance();

        // The dynamic_form constructor runs check_access for ajax submissions,
        // exactly like a real modal submit.
        $this->expectException(moodle_exception::class);
        $this->build_form($settings, $answerids, 5);
    }

    /**
     * Helper: build the dynamic form with mocked ajax submit data.
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param array $checkedids
     * @param int $rating
     * @return modal_set_rating
     */
    private function build_form($settings, array $checkedids, int $rating): modal_set_rating {
        $ajaxargs = [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'checkedids' => implode(',', $checkedids),
            'rating' => $rating,
        ];
        $submitdata = modal_set_rating::mock_ajax_submit($ajaxargs);
        return new modal_set_rating(null, null, 'post', '', [], true, $submitdata, true);
    }

    /**
     * Helper: submit the rating form for the given answers.
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param array $checkedids
     * @param int $rating
     * @return void
     */
    private function submit_rating($settings, array $checkedids, int $rating): void {
        $form = $this->build_form($settings, $checkedids, $rating);
        $this->assertTrue($form->is_validated(), 'The rating dynamic form should validate.');
        $form->process_dynamic_submission();
    }

    /**
     * Helper: booking instance with ratings enabled (numeric scale 0-10) and
     * one option with two booked students.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int[], 2: \context_module, 3: stdClass[]}
     */
    private function create_booked_option(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Rating modal test booking',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'assessed' => 1,
            'scale' => 10,
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for rating modal';
        $record->useprice = 0;
        $record->maxanswers = 5;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);

        $answerids = array_keys(
            $DB->get_records('booking_answers', ['optionid' => $option->id], '', 'id')
        );
        $this->assertCount(2, $answerids, 'Precondition: both students must be booked.');

        $context = \context_module::instance($settings->cmid);

        return [$settings, $answerids, $context, [$student1, $student2]];
    }
}

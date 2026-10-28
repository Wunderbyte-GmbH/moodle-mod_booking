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

use mod_booking\bo_availability\conditions\previouslybooked;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_url;
use stdClass;

/**
 * The "previously booked" condition text names completion when the condition requires it, and tells a user who
 * booked but did not complete the required option exactly that (Wunderbyte-GmbH#2623).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\bo_availability\conditions\previouslybooked
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_previouslybooked_completion_description_test extends booking_advanced_testcase {
    /** @var stdClass */
    private stdClass $course;
    /** @var stdClass */
    private stdClass $booking;
    /** @var stdClass */
    private stdClass $student;
    /** @var mod_booking_generator */
    private mod_booking_generator $plugingenerator;

    /**
     * Course, booking instance, student.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'bookingmanager' => $teacher->username,
        ]);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
    }

    /**
     * Create an option in the instance.
     *
     * @param string $text
     * @return stdClass
     */
    private function create_option(string $text): stdClass {
        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $this->course->id;
        $record->maxanswers = 10;
        return $this->plugingenerator->create_option($record);
    }

    /**
     * Write the condition JSON on an option.
     *
     * @param int $optionid
     * @param array $condition
     */
    private function write_availability_json(int $optionid, array $condition): void {
        global $DB;
        $entry = array_merge([
            'id' => MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED,
            'name' => 'previouslybooked',
            'class' => previouslybooked::class,
        ], $condition);
        $DB->set_field('booking_options', 'availability', json_encode([$entry]), ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * The link object the strings are rendered with.
     *
     * @param stdClass $option
     * @return stdClass
     */
    private function link(stdClass $option): stdClass {
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        return (object)[
            'url' => (new moodle_url(
                '/mod/booking/optionview.php',
                ['optionid' => (int)$option->id, 'cmid' => $settings->cmid]
            ))->out(false),
            'title' => format_string($settings->text, true, ['context' => \context_module::instance($settings->cmid)]),
        ];
    }

    /**
     * The condition on an option, with its settings, as the description code reads it.
     *
     * @param stdClass $target
     * @return array{0:previouslybooked,1:booking_option_settings}
     */
    private function condition(stdClass $target): array {
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$target->id);
        $condition = previouslybooked::instance();
        $condition->customsettings = json_decode($settings->availability)[0];
        return [$condition, $settings];
    }

    /**
     * Completion required, one option: the text names completion, not "previously booked".
     */
    public function test_completion_required_names_completion(): void {
        $optiona = $this->create_option('Prerequisite A');
        $target = $this->create_option('Target');
        $this->write_availability_json((int)$target->id, ['optionids' => [(int)$optiona->id], 'requirecompletion' => 1]);
        [$condition, $settings] = $this->condition($target);

        $this->assertSame(
            get_string('bocondpreviouslybookedcompletednotavailable', 'mod_booking', $this->link($optiona)),
            $condition->get_description_string(false, false, $settings)
        );
        $this->assertSame(
            get_string('bocondpreviouslybookedcompletedfullnotavailable', 'mod_booking', $this->link($optiona)),
            $condition->get_description_string(false, true, $settings)
        );
    }

    /**
     * A user who booked the required option but did not complete it is told that, by the description the core
     * renders for that user; a user who never booked it gets the completion text.
     */
    public function test_booked_but_not_completed_user_is_told_so(): void {
        $optiona = $this->create_option('Prerequisite A');
        $target = $this->create_option('Target');
        $this->write_availability_json((int)$target->id, ['optionids' => [(int)$optiona->id], 'requirecompletion' => 1]);
        $this->plugingenerator->create_answer(['optionid' => (int)$optiona->id, 'userid' => (int)$this->student->id]);
        booking_option::purge_cache_for_answers((int)$optiona->id);
        [$condition, $settings] = $this->condition($target);

        [$available, $description] = $condition->get_description($settings, (int)$this->student->id, false);
        $this->assertFalse($available);
        $this->assertSame(
            get_string('bocondpreviouslybookedbookednotcompleted', 'mod_booking', $this->link($optiona)),
            $description
        );

        $other = $this->getDataGenerator()->create_user();
        [$available, $description] = $condition->get_description($settings, (int)$other->id, false);
        $this->assertFalse($available);
        $this->assertSame(
            get_string('bocondpreviouslybookedcompletednotavailable', 'mod_booking', $this->link($optiona)),
            $description
        );
    }

    /**
     * Without the completion requirement the text is the "previously booked" one, as before; several options with
     * completion use the completion variant of the any/all texts.
     */
    public function test_without_completion_and_with_several_options(): void {
        $optiona = $this->create_option('Prerequisite A');
        $optionb = $this->create_option('Prerequisite B');
        $target = $this->create_option('Target');

        $this->write_availability_json((int)$target->id, ['optionids' => [(int)$optiona->id]]);
        [$condition, $settings] = $this->condition($target);
        $this->assertSame(
            get_string('bocondpreviouslybookednotavailable', 'mod_booking', $this->link($optiona)),
            $condition->get_description_string(false, false, $settings)
        );

        $this->write_availability_json((int)$target->id, [
            'optionids' => [(int)$optiona->id, (int)$optionb->id],
            'optionidsoperator' => 'OR',
            'requirecompletion' => 1,
        ]);
        [$condition, $settings] = $this->condition($target);
        $description = $condition->get_description_string(false, false, $settings);
        $this->assertStringStartsWith(
            explode('{$a}', get_string('bocondpreviouslybookedcompletednotavailableany', 'mod_booking', '{$a}'))[0],
            $description
        );
        $this->assertStringContainsString('Prerequisite A', $description);
        $this->assertStringContainsString('Prerequisite B', $description);
    }

    /**
     * The eight new strings exist in en, de and de_gs.
     */
    public function test_strings_exist_in_all_three_languages(): void {
        $manager = get_string_manager();
        foreach (['bookednotcompleted', 'bookednotcompletedfull', 'completednotavailable', 'completedfullnotavailable',
            'completednotavailableany', 'completedfullnotavailableany', 'completednotavailableall',
            'completedfullnotavailableall'] as $suffix) {
            foreach (['en', 'de', 'de_gs'] as $lang) {
                $this->assertTrue(
                    $manager->string_exists('bocondpreviouslybooked' . $suffix, 'mod_booking'),
                    $suffix
                );
                $this->assertNotSame('', $manager->get_string('bocondpreviouslybooked' . $suffix, 'mod_booking', null, $lang));
            }
        }
    }
}

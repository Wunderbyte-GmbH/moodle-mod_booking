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
 * Tests for the visibility of the booking option type select.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\booking_option_settings;
use mod_booking\option\fields\optiontype;
use mod_booking\utils\wb_payment;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * PHPUnit tests for the option type select: it is only rendered when there really is
 * more than one selectable type, and the default type is persisted otherwise.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\option\fields\optiontype
 */
final class optiontype_visibility_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Tear down: drop the PRO override, it is a static and survives resetAfterTest.
     */
    public function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        singleton_service::destroy_instance();
        parent::tearDown();
    }

    /**
     * Apply one licence/settings combination.
     *
     * @param bool $pro
     * @param int $slotbookingactive
     * @param int $selflearningcourseactive
     * @return void
     */
    private function set_feature_state(bool $pro, int $slotbookingactive, int $selflearningcourseactive): void {
        wb_payment::override_pro_version_for_tests($pro);
        set_config('slotbookingactive', $slotbookingactive, 'booking');
        set_config('selflearningcourseactive', $selflearningcourseactive, 'booking');
    }

    /**
     * Render the option type field into a fresh form.
     *
     * @param int $optionid 0 for a new option
     * @return \MoodleQuickForm
     */
    private function render_optiontype_field(int $optionid = 0): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('optiontypetestform' . uniqid(), 'post', '');
        $formdata = ['id' => $optionid];
        optiontype::instance_form_definition($mform, $formdata, [], [], false);
        return $mform;
    }

    /**
     * Values offered by the rendered optiontype element.
     *
     * @param \MoodleQuickForm $mform
     * @return int[]
     */
    private function get_offered_types(\MoodleQuickForm $mform): array {
        $element = $mform->getElement('optiontype');
        $values = [];
        foreach ($element->_options as $option) {
            $values[] = (int)$option['attr']['value'];
        }
        return $values;
    }

    /**
     * Licence and settings combinations from the issue, with the types the form must offer.
     *
     * @return array[]
     */
    public static function optiontype_matrix_provider(): array {
        return [
            'no pro, both settings on' => [false, 1, 1, []],
            'pro, both settings off' => [true, 0, 0, []],
            'pro, slot booking only' => [true, 1, 0, [
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            ]],
            'pro, self-learning only' => [true, 0, 1, [
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
            ]],
            'pro, both settings on' => [true, 1, 1, [
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
                MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            ]],
        ];
    }

    /**
     * The select is only rendered when a second type is actually selectable, and it then
     * offers exactly the available types. Otherwise the default type is stored silently.
     *
     * @param bool $pro
     * @param int $slotbookingactive
     * @param int $selflearningcourseactive
     * @param int[] $expectedtypes empty when no select must be rendered
     * @return void
     *
     * @dataProvider optiontype_matrix_provider
     */
    public function test_option_type_select_visibility(
        bool $pro,
        int $slotbookingactive,
        int $selflearningcourseactive,
        array $expectedtypes
    ): void {
        $this->set_feature_state($pro, $slotbookingactive, $selflearningcourseactive);

        $mform = $this->render_optiontype_field();

        // The element always exists, so that the type is always submitted.
        $this->assertTrue($mform->elementExists('optiontype'));
        $element = $mform->getElement('optiontype');

        if (empty($expectedtypes)) {
            $this->assertSame(
                'hidden',
                $element->getType(),
                'Without a second selectable type, no option type select must be shown.'
            );
            $this->assertSame(
                MOD_BOOKING_OPTIONTYPE_DEFAULT,
                (int)$element->getValue(),
                'The hidden option type must carry the default type.'
            );
            $this->assertFalse(
                $mform->elementExists('btn_optiontype'),
                'The no-submit button belongs to the select and must not be rendered without it.'
            );
            return;
        }

        $this->assertSame('select', $element->getType());
        $this->assertSame($expectedtypes, $this->get_offered_types($mform));
        $this->assertTrue($mform->elementExists('btn_optiontype'));
    }

    /**
     * Self-learning courses are a PRO feature: without a licence the type must not be
     * offered, even when the (PRO gated) setting still carries a value of 1.
     *
     * @return void
     */
    public function test_self_learning_course_is_not_offered_without_pro(): void {
        $this->set_feature_state(false, 1, 1);

        $mform = $this->render_optiontype_field();

        $this->assertSame('hidden', $mform->getElement('optiontype')->getType());
        $this->assertNotContains(
            MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
            [(int)$mform->getElement('optiontype')->getValue()]
        );
    }

    /**
     * Without any selectable second type, an option is stored with type = 0.
     *
     * @return void
     */
    public function test_default_type_is_persisted_when_select_is_hidden(): void {
        global $DB;

        $this->set_feature_state(false, 1, 1);

        [$booking, $course] = $this->create_booking_instance();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Plain option',
            'course' => $course->id,
            'maxanswers' => 5,
            'coursestarttime' => strtotime('2050-01-01 09:00:00'),
            'courseendtime' => strtotime('2050-01-01 10:00:00'),
        ]);

        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_DEFAULT,
            (int)$DB->get_field('booking_options', 'type', ['id' => $option->id])
        );
    }

    /**
     * prepare_save_field falls back to the default type when the form did not submit one.
     *
     * @return void
     */
    public function test_prepare_save_field_defaults_to_zero(): void {
        $this->set_feature_state(false, 0, 0);

        $formdata = new stdClass();
        $newoption = new stdClass();
        optiontype::prepare_save_field($formdata, $newoption, 0);

        $this->assertSame(MOD_BOOKING_OPTIONTYPE_DEFAULT, (int)$newoption->type);
    }

    /**
     * Switching a new option to self-learning is rejected while the feature is unavailable.
     *
     * @return void
     */
    public function test_validation_rejects_self_learning_without_feature(): void {
        $this->set_feature_state(true, 0, 0);

        $errors = [];
        optiontype::validation(
            ['id' => 0, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE],
            [],
            $errors
        );

        $this->assertArrayHasKey('optiontype', $errors);
    }

    /**
     * An option that already is a self-learning course keeps its type after the feature has
     * been switched off: the select still offers it and validation does not block a save.
     *
     * @return void
     */
    public function test_existing_self_learning_option_keeps_its_type(): void {
        global $DB;

        $this->set_feature_state(true, 0, 1);

        [$booking, $course] = $this->create_booking_instance();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Self-learning option',
            'course' => $course->id,
            'maxanswers' => 5,
            'selflearningcourse' => 1,
            'duration' => 2592000,
        ]);

        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
            (int)$DB->get_field('booking_options', 'type', ['id' => $option->id]),
            'Precondition: the option must be stored as a self-learning course.'
        );

        // Now the admin switches the feature off.
        $this->set_feature_state(true, 0, 0);
        singleton_service::destroy_instance();

        $mform = $this->render_optiontype_field((int)$option->id);
        $this->assertSame('select', $mform->getElement('optiontype')->getType());
        $this->assertSame(
            [MOD_BOOKING_OPTIONTYPE_DEFAULT, MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE],
            $this->get_offered_types($mform)
        );

        $errors = [];
        optiontype::validation(
            ['id' => (int)$option->id, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE],
            [],
            $errors
        );
        $this->assertArrayNotHasKey('optiontype', $errors);
    }

    /**
     * An option that already is a slot option keeps its type after the admin toggle has been
     * switched off: the select still offers it and validation does not block a save.
     *
     * @return void
     */
    public function test_existing_slot_option_keeps_its_type_when_setting_is_off(): void {
        $option = $this->create_slot_option();

        // Now the admin switches slot booking off.
        $this->set_feature_state(true, 0, 0);
        singleton_service::destroy_instance();

        $mform = $this->render_optiontype_field((int)$option->id);
        $this->assertSame('select', $mform->getElement('optiontype')->getType());
        $this->assertSame(
            [MOD_BOOKING_OPTIONTYPE_DEFAULT, MOD_BOOKING_OPTIONTYPE_SLOTBOOKING],
            $this->get_offered_types($mform)
        );

        $errors = [];
        optiontype::validation(
            ['id' => (int)$option->id, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING],
            [],
            $errors
        );
        $this->assertArrayNotHasKey('optiontype', $errors);
    }

    /**
     * The same holds when the PRO licence expired instead of the toggle being switched off.
     *
     * @return void
     */
    public function test_existing_slot_option_keeps_its_type_when_pro_expired(): void {
        $option = $this->create_slot_option();

        // The licence expires.
        $this->set_feature_state(false, 1, 0);
        singleton_service::destroy_instance();

        $mform = $this->render_optiontype_field((int)$option->id);
        $this->assertSame(
            [MOD_BOOKING_OPTIONTYPE_DEFAULT, MOD_BOOKING_OPTIONTYPE_SLOTBOOKING],
            $this->get_offered_types($mform)
        );

        $errors = [];
        optiontype::validation(
            ['id' => (int)$option->id, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING],
            [],
            $errors
        );
        $this->assertArrayNotHasKey('optiontype', $errors);
    }

    /**
     * set_data must not reset the stored slot type once the feature became unavailable, and
     * saving such an option again keeps type = 2 in the database.
     *
     * @return void
     */
    public function test_existing_slot_option_type_survives_set_data_and_save(): void {
        global $DB;

        $option = $this->create_slot_option();

        $this->set_feature_state(true, 0, 0);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        $data = (object)['id' => (int)$option->id];
        optiontype::set_data($data, $settings);

        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            (int)$data->optiontype,
            'set_data must not downgrade the stored slot type.'
        );
        $this->assertSame(1, (int)$data->slot_enabled);

        $newoption = (object)['id' => (int)$option->id];
        optiontype::prepare_save_field($data, $newoption, MOD_BOOKING_UPDATE_OPTIONS_PARAM_DEFAULT);

        $this->assertSame(MOD_BOOKING_OPTIONTYPE_SLOTBOOKING, (int)$newoption->type);
        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            (int)$DB->get_field('booking_options', 'type', ['id' => $option->id]),
            'The stored option must still be a slot option.'
        );
    }

    /**
     * A NEW option must not become a slot option while the feature is unavailable, even when
     * the submitted data asks for it (import, webservice, crafted request).
     *
     * @return void
     */
    public function test_new_option_cannot_become_slot_type_without_feature(): void {
        $this->set_feature_state(true, 0, 0);

        $settings = new booking_option_settings(0);
        $data = (object)['id' => 0, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING];
        optiontype::set_data($data, $settings);

        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_DEFAULT,
            (int)$data->optiontype,
            'A new option must not be pulled into the slot type while the feature is off.'
        );

        $errors = [];
        optiontype::validation(
            ['id' => 0, 'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING],
            [],
            $errors
        );
        $this->assertArrayHasKey('optiontype', $errors);
    }

    /**
     * With slot booking unavailable and no existing slot option, the type is not offered.
     *
     * @return void
     */
    public function test_slot_type_is_not_offered_for_a_non_slot_option(): void {
        $this->set_feature_state(true, 1, 0);

        [$booking, $course] = $this->create_booking_instance();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Plain option',
            'course' => $course->id,
            'maxanswers' => 5,
            'coursestarttime' => strtotime('2050-01-01 09:00:00'),
            'courseendtime' => strtotime('2050-01-01 10:00:00'),
        ]);

        $this->set_feature_state(true, 0, 0);
        singleton_service::destroy_instance();

        $mform = $this->render_optiontype_field((int)$option->id);
        $this->assertSame(
            'hidden',
            $mform->getElement('optiontype')->getType(),
            'A dates option must not be offered the slot type just because another option has it.'
        );
    }

    /**
     * Create a slot booking option while the feature is available.
     *
     * @return stdClass the option
     */
    private function create_slot_option(): stdClass {
        global $DB;

        $this->set_feature_state(true, 1, 0);

        [$booking, $course] = $this->create_booking_instance();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object)$record);

        $this->assertSame(
            MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            (int)$DB->get_field('booking_options', 'type', ['id' => $option->id]),
            'Precondition: the option must be stored as a slot option.'
        );

        return $option;
    }

    /**
     * Create a course with a booking instance.
     *
     * @return array{0:stdClass, 1:stdClass} booking instance and course
     */
    private function create_booking_instance(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        return [$booking, $course];
    }
}

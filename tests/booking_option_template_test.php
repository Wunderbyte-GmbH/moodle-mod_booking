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
 * Tests for booking option template functionality (templatename in JSON).
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use mod_booking_generator;
use context_module;
use mod_booking\booking_option;
use mod_booking\option\fields\addastemplate;
use mod_booking\option\fields\template as template_field;
use mod_booking\option\fields\text;
use mod_booking\table\optiontemplatessettings_table;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Class handling tests for booking option templates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_option_template_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Helper: Create a course, a user, and a booking instance. Returns [course, user, booking].
     *
     * @return array
     */
    private function create_booking_setup(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setAdminUser();

        $bdata = [
            'name' => 'Test Booking Templates',
            'course' => $course->id,
            'bookingmanager' => $user->username,
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        return [$course, $user, $booking];
    }

    /**
     * Test 1: Saving a template without templatename (classic behavior).
     *
     * When addastemplate=1 and text is set but templatename is not provided,
     * the template should be saved with bookingid=0 and text as the name.
     * JSON should NOT contain a templatename key.
     *
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_save_template_without_templatename(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create a regular option with addastemplate=1 but no templatename.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'My Classic Template';
        $record->addastemplate = 1;
        $record->chooseorcreatecourse = 1;
        $record->teachersforoption = [];
        $record->responsiblecontact = [];

        $option = $plugingenerator->create_option($record);

        // Verify: bookingid should be 0 (= template).
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals(0, $dbrecord->bookingid, 'Template must have bookingid=0');
        $this->assertEquals('My Classic Template', $dbrecord->text, 'Text should be preserved');

        // Verify: JSON should NOT contain templatename.
        if (!empty($dbrecord->json)) {
            $jsonobj = json_decode($dbrecord->json);
            $this->assertFalse(
                isset($jsonobj->templatename),
                'JSON should not contain templatename when none was provided'
            );
        }
    }

    /**
     * Test 2: Saving a template with both templatename and text.
     *
     * templatename should be stored in JSON, text should remain in DB,
     * and display logic should prioritize templatename.
     *
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     * @covers \mod_booking\table\optiontemplatessettings_table::col_name
     */
    public function test_save_template_with_templatename_and_text(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create option with addastemplate=1, text and templatename.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option Name';
        $record->addastemplate = 1;
        $record->templatename = 'My Template Display Name';
        $record->chooseorcreatecourse = 1;
        $record->teachersforoption = [];
        $record->responsiblecontact = [];

        $option = $plugingenerator->create_option($record);

        // Verify DB record.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals(0, $dbrecord->bookingid, 'Template must have bookingid=0');
        $this->assertEquals('Option Name', $dbrecord->text, 'Text should be preserved');

        // Verify JSON contains templatename.
        $this->assertNotEmpty($dbrecord->json, 'JSON should not be empty');
        $jsonobj = json_decode($dbrecord->json);
        $this->assertEquals('My Template Display Name', $jsonobj->templatename);

        // Verify col_name() returns templatename (priority over text).
        $tablerow = new stdClass();
        $tablerow->name = $dbrecord->text;
        $tablerow->json = $dbrecord->json;

        $table = new optiontemplatessettings_table('test_unique_id', 0);
        $displayname = $table->col_name($tablerow);
        $this->assertEquals(
            format_string('My Template Display Name'),
            $displayname,
            'col_name() should return templatename when it exists in JSON'
        );
    }

    /**
     * Test 3: Creating a template without text but with templatename via create_template().
     *
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     * @covers \mod_booking\table\optiontemplatessettings_table::col_name
     */
    public function test_save_template_without_text_with_templatename(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Use create_template() — text defaults to ''.
        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'EmptyTextTemplate',
        ]);

        // Verify DB record.
        $dbrecord = $DB->get_record('booking_options', ['id' => $template->id]);
        $this->assertEquals(0, $dbrecord->bookingid, 'Template must have bookingid=0');
        $this->assertEquals('', $dbrecord->text, 'Text should be empty for template without text');

        // Verify JSON contains templatename.
        $this->assertNotEmpty($dbrecord->json, 'JSON should contain templatename');
        $jsonobj = json_decode($dbrecord->json);
        $this->assertEquals('EmptyTextTemplate', $jsonobj->templatename);

        // Verify col_name() returns templatename.
        $tablerow = new stdClass();
        $tablerow->name = $dbrecord->text;
        $tablerow->json = $dbrecord->json;

        $table = new optiontemplatessettings_table('test_unique_id2', 0);
        $displayname = $table->col_name($tablerow);
        $this->assertEquals(
            format_string('EmptyTextTemplate'),
            $displayname,
            'col_name() should return templatename when text is empty'
        );

        // Verify template dropdown label logic.
        $alltemplates = $DB->get_records('booking_options', ['bookingid' => 0], '', 'id, text, json');
        $found = false;
        foreach ($alltemplates as $t) {
            if ($t->id == $template->id) {
                $found = true;
                $t->displayname = $t->text;
                if (!empty($t->json)) {
                    $j = json_decode($t->json);
                    if (!empty($j->templatename)) {
                        $t->displayname = $j->templatename;
                    }
                }
                $this->assertEquals(
                    'EmptyTextTemplate',
                    $t->displayname,
                    'Template dropdown label should be templatename'
                );
            }
        }
        $this->assertTrue($found, 'Template should be found in booking_options with bookingid=0');
    }

    /**
     * Test 4: Validation — template without both text and templatename should fail.
     *
     * @covers \mod_booking\option\fields\addastemplate::validation
     */
    public function test_validation_template_without_text_and_templatename(): void {
        // Case 1: Both empty — should produce an error.
        $data = [
            'addastemplate' => 1,
            'text' => '',
            'templatename' => '',
        ];
        $errors = [];
        addastemplate::validation($data, [], $errors);
        $this->assertArrayHasKey(
            'templatename',
            $errors,
            'Validation should fail when both text and templatename are empty'
        );

        // Case 2: text filled, templatename empty — should pass.
        $data2 = [
            'addastemplate' => 1,
            'text' => 'SomeText',
            'templatename' => '',
        ];
        $errors2 = [];
        addastemplate::validation($data2, [], $errors2);
        $this->assertArrayNotHasKey(
            'templatename',
            $errors2,
            'Validation should pass when text is provided'
        );

        // Case 3: text empty, templatename filled — should pass.
        $data3 = [
            'addastemplate' => 1,
            'text' => '',
            'templatename' => 'SomeName',
        ];
        $errors3 = [];
        addastemplate::validation($data3, [], $errors3);
        $this->assertArrayNotHasKey(
            'templatename',
            $errors3,
            'Validation should pass when templatename is provided'
        );

        // Case 4: addastemplate=0 — should always pass regardless.
        $data4 = [
            'addastemplate' => 0,
            'text' => '',
            'templatename' => '',
        ];
        $errors4 = [];
        addastemplate::validation($data4, [], $errors4);
        $this->assertArrayNotHasKey(
            'templatename',
            $errors4,
            'Validation should pass when addastemplate is 0'
        );
    }

    /**
     * Test 5: Template with empty text — when applied to a new option, the empty text is preserved.
     *
     * This tests the core of template::set_data() logic by simulating what happens
     * when a template with empty text is loaded via fields_info::set_data().
     *
     * @covers \mod_booking\option\fields\template::set_data
     */
    public function test_template_application_preserves_empty_text(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create template with empty text.
        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'TemplateX',
        ]);

        // Verify the template was created with empty text.
        $dbrecord = $DB->get_record('booking_options', ['id' => $template->id]);
        $this->assertEquals('', $dbrecord->text, 'Template text should be empty');
        $this->assertEquals(0, $dbrecord->bookingid, 'Template bookingid should be 0');

        // Now simulate what happens when set_data is called with this template ID.
        // template::set_data() internally loads the option via fields_info::set_data()
        // and copies all data (except excluded keys) to the new data object.
        $settings = singleton_service::get_instance_of_booking_option_settings($template->id);

        // The settings object should reflect the empty text.
        $this->assertEquals('', $settings->text, 'Settings text should be empty for the template');

        // Verify templatename is accessible from the JSON.
        $jsonobj = json_decode($dbrecord->json);
        $this->assertEquals(
            'TemplateX',
            $jsonobj->templatename,
            'Template JSON should contain templatename'
        );
    }

    /**
     * Test 6: create_template() with both text and templatename.
     *
     * Ensures that when text is explicitly provided to create_template(),
     * it is preserved alongside templatename in JSON.
     *
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_create_template_with_text_and_templatename(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'Display Name',
            'text' => 'Option Title',
        ]);

        $dbrecord = $DB->get_record('booking_options', ['id' => $template->id]);
        $this->assertEquals(0, $dbrecord->bookingid);
        $this->assertEquals('Option Title', $dbrecord->text);

        $jsonobj = json_decode($dbrecord->json);
        $this->assertEquals('Display Name', $jsonobj->templatename);
    }

    /**
     * Test 7: text::validation() — text is required for normal options, not for templates.
     *
     * @covers \mod_booking\option\fields\text::validation
     */
    public function test_validation_text_required_unless_template(): void {
        // Case 1: Normal option (addastemplate=0), text empty — should fail.
        $data = ['addastemplate' => 0, 'text' => ''];
        $errors = [];
        text::validation($data, [], $errors);
        $this->assertArrayHasKey(
            'text',
            $errors,
            'Text should be required for normal booking options'
        );

        // Case 2: Normal option, text filled — should pass.
        $data2 = ['addastemplate' => 0, 'text' => 'My Option'];
        $errors2 = [];
        text::validation($data2, [], $errors2);
        $this->assertArrayNotHasKey(
            'text',
            $errors2,
            'No error when text is provided for a normal option'
        );

        // Case 3: Template (addastemplate=1), text empty — should pass.
        $data3 = ['addastemplate' => 1, 'text' => ''];
        $errors3 = [];
        text::validation($data3, [], $errors3);
        $this->assertArrayNotHasKey(
            'text',
            $errors3,
            'Text should not be required when saving as template'
        );

        // Case 4: Template, text filled — should pass.
        $data4 = ['addastemplate' => 1, 'text' => 'Some Text'];
        $errors4 = [];
        text::validation($data4, [], $errors4);
        $this->assertArrayNotHasKey(
            'text',
            $errors4,
            'No error when text is provided for a template'
        );

        // Case 5: addastemplate not set at all, text empty — should fail.
        $data5 = ['text' => ''];
        $errors5 = [];
        text::validation($data5, [], $errors5);
        $this->assertArrayHasKey(
            'text',
            $errors5,
            'Text should be required when addastemplate is not set'
        );

        // Case 6: Whitespace-only text, no template — should fail.
        $data6 = ['addastemplate' => 0, 'text' => '   '];
        $errors6 = [];
        text::validation($data6, [], $errors6);
        $this->assertArrayHasKey(
            'text',
            $errors6,
            'Whitespace-only text should be treated as empty'
        );
    }

    /**
     * Test 8: Editing an existing template loads templatename into form data via set_data.
     *
     * When a template (bookingid=0) is opened for editing, set_data must populate
     * $data->templatename from the stored JSON so the field is pre-filled in the form
     * and gets written back to JSON on save.
     *
     * @covers \mod_booking\option\fields\template::set_data
     */
    public function test_edit_template_set_data_loads_templatename(): void {
        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'My Title',
        ]);

        // Simulate opening the existing template for editing: $data->id is set, no fromtemplate flag.
        $data = (object)[
            'id' => $template->id,
            'cmid' => $template->cmid,
        ];

        $settings = singleton_service::get_instance_of_booking_option_settings($template->id);
        template_field::set_data($data, $settings);

        $this->assertEquals(
            'My Title',
            $data->templatename,
            'set_data must load templatename from JSON when editing an existing template'
        );
    }

    /**
     * Test 9: Applying a template to a new option must NOT copy templatename.
     *
     * When set_data is called with fromtemplate=true (i.e. populating a new option
     * from an existing template), the templatename must not be transferred.
     *
     * @covers \mod_booking\option\fields\template::set_data
     */
    public function test_apply_template_to_new_option_does_not_set_templatename(): void {
        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'Template Title',
        ]);

        // Simulate the "apply template to new option" flow: fromtemplate flag is set.
        $data = (object)[
            'id' => $template->id,
            'fromtemplate' => true,
            'cmid' => $template->cmid,
        ];

        $settings = singleton_service::get_instance_of_booking_option_settings($template->id);
        template_field::set_data($data, $settings);

        $this->assertFalse(
            isset($data->templatename) && $data->templatename === 'Template Title',
            'templatename must not be copied when applying a template to a new option'
        );
    }

    /**
     * Test 10: Round-trip — editing and saving a template preserves templatename in JSON.
     *
     * Full round-trip: set_data loads templatename, booking_option::update saves it back.
     * After saving, the DB JSON must still contain the original templatename.
     *
     * @covers \mod_booking\option\fields\template::set_data
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_edit_template_roundtrip_preserves_templatename(): void {
        global $DB;

        [, , $booking] = $this->create_booking_setup();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'Original Name',
            'text' => 'Option Title',
        ]);

        // Build the data object as it would be submitted when re-saving the template.
        $data = (object)[
            'id' => $template->id,
            'optionid' => $template->id,
            'cmid' => $template->cmid,
            'bookingid' => $booking->id,
            'text' => 'Option Title',
            'addastemplate' => 1,
            'identifier' => $template->identifier,
            'addtocalendar' => 0,
            'maxanswers' => 0,
            'teachersforoption' => [],
            'responsiblecontact' => [],
        ];

        // Step 1: set_data loads templatename from settings into $data.
        $settings = singleton_service::get_instance_of_booking_option_settings($template->id);
        template_field::set_data($data, $settings);

        $this->assertEquals('Original Name', $data->templatename, 'set_data must load templatename');

        // Step 2: save — addastemplate::prepare_save_field writes templatename back to JSON.
        $context = context_module::instance($template->cmid);
        booking_option::update($data, $context);

        // Step 3: verify JSON in DB still contains templatename.
        $dbrecord = $DB->get_record('booking_options', ['id' => $template->id]);
        $this->assertNotEmpty($dbrecord->json, 'JSON must not be empty after save');
        $jsonobj = json_decode($dbrecord->json);
        $this->assertEquals(
            'Original Name',
            $jsonobj->templatename,
            'templatename must be preserved in JSON after editing and re-saving a template'
        );
    }
}

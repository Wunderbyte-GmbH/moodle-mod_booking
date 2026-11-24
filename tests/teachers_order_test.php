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
 * Tests for the order of the teachers of a booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use core\url;
use mod_booking\form\option_form;
use mod_booking\output\col_teacher;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\utils\wb_payment;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the order of the teachers of a booking option (PRO feature of the option form).
 *
 * The teachers are Carl Adler, Bea Maier and Anna Zimmer: sorted by name (last name first) this is
 * Adler, Maier, Zimmer. The tests set the order Zimmer, Adler, Maier, which is neither the order by name
 * nor its reverse nor the order in which the teachers were added.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\teachers_handler
 * @covers \mod_booking\booking_option_settings
 */
final class teachers_order_test extends booking_advanced_testcase {
    /** @var stdClass the booking instance */
    private stdClass $booking;

    /** @var stdClass teacher Carl Adler */
    private stdClass $adler;

    /** @var stdClass teacher Bea Maier */
    private stdClass $maier;

    /** @var stdClass teacher Anna Zimmer */
    private stdClass $zimmer;

    /**
     * Tests set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Teachers order booking',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        // Created in an order that is neither the order by name nor the order of the tests.
        $this->maier = $this->getDataGenerator()->create_user(['firstname' => 'Bea', 'lastname' => 'Maier']);
        $this->zimmer = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Zimmer']);
        $this->adler = $this->getDataGenerator()->create_user(['firstname' => 'Carl', 'lastname' => 'Adler']);
    }

    /**
     * Mandatory clean-up after each test.
     */
    protected function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * Creates a booking option with teachers.
     *
     * @param stdClass[] $teachers
     * @return stdClass the record, with id and cmid set for later updates
     */
    private function create_option(array $teachers): stdClass {
        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = 'Option with teachers';
        $record->teachersforoption = implode(',', array_map(fn($teacher) => $teacher->username, $teachers));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $record->id = $option->id;
        $record->cmid = singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;
        return $record;
    }

    /**
     * Saves the option like its form does: with the chosen teachers and (optionally) their order.
     *
     * @param stdClass $record
     * @param stdClass[] $teachers the teachers of the option
     * @param stdClass[]|null $order the teachers in the order to save, null if no order is submitted
     */
    private function update_option(stdClass $record, array $teachers, ?array $order = null): void {
        $record->teachersforoption = array_map(fn($teacher) => (int)$teacher->id, $teachers);
        unset($record->{teachers_handler::FORM_TEACHERSORDER});
        if ($order !== null) {
            $record->{teachers_handler::FORM_TEACHERSORDER} = array_map(fn($teacher) => (string)$teacher->id, $order);
        }
        booking_option::update($record);
    }

    /**
     * The last names of the teachers of the option in the order of the settings (loaded anew from the database).
     *
     * @param int $optionid
     * @return string[]
     */
    private function get_lastnames(int $optionid): array {
        booking_option::purge_cache_for_option($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return array_values(array_map(fn($teacher) => $teacher->lastname, $settings->teachers));
    }

    /**
     * Asserts that the texts appear in the haystack in the given order.
     *
     * @param string[] $texts
     * @param string $haystack
     * @param string $message
     */
    private function assert_text_order(array $texts, string $haystack, string $message): void {
        $offset = 0;
        foreach ($texts as $text) {
            $position = strpos($haystack, $text, $offset);
            $this->assertNotFalse($position, "$message: '$text' is missing or out of order in: $haystack");
            $offset = $position + strlen($text);
        }
    }

    /**
     * Without an order, the teachers are sorted by name. This is the behaviour before the feature.
     */
    public function test_teachers_are_sorted_by_name_without_order(): void {
        global $DB;

        $record = $this->create_option([$this->maier, $this->zimmer, $this->adler]);

        $this->assertSame(['Adler', 'Maier', 'Zimmer'], $this->get_lastnames($record->id));
        $this->assertSame(
            0,
            $DB->count_records_select('booking_teachers', 'optionid = ? AND sortorder <> 0', [$record->id]),
            'No positions are stored without an order.'
        );

        // Saving the form without changing the order (it submits the order by name) stores no positions either.
        $this->update_option(
            $record,
            [$this->maier, $this->zimmer, $this->adler],
            [$this->adler, $this->maier, $this->zimmer]
        );
        $this->assertSame(
            0,
            $DB->count_records_select('booking_teachers', 'optionid = ? AND sortorder <> 0', [$record->id])
        );
    }

    /**
     * The order set in the form is used by everything that displays the teachers of a booking option.
     *
     * @covers \mod_booking\output\col_teacher
     * @covers \mod_booking\booking_option::get_teachers
     * @covers \mod_booking\booking_utils::prepare_teachernames_arrays_for_optionids
     * @covers \mod_booking\placeholders\placeholders\teachers
     */
    public function test_order_is_used_by_every_display(): void {
        global $DB, $USER;

        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        $optionid = (int)$record->id;
        $expected = ['Zimmer', 'Adler', 'Maier'];

        // The settings of the option: the source of the tables, the shortcodes, the option view and the webservices.
        $this->assertSame($expected, $this->get_lastnames($optionid));
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertSame(
            [(int)$this->zimmer->id, (int)$this->adler->id, (int)$this->maier->id],
            array_map('intval', array_values($settings->teacherids))
        );

        // The column "teacher" of the tables (view.php, [courselist], [allekurseliste], [unifiedview]) ...
        // ... and the description of the option (optionview.php).
        $colteacher = new col_teacher($optionid, $settings);
        $this->assertSame($expected, array_column($colteacher->teachers, 'lastname'));

        // The placeholder {teachers}.
        $this->assert_text_order($expected, $settings->render_list_of_teachers(), 'List of teachers');
        $this->assert_text_order(
            $expected,
            placeholders_info::render_text('{teachers}', (int)$record->cmid, $optionid, (int)$USER->id),
            'Placeholder {teachers}'
        );

        // The teachers of the booking option object (mobile app).
        $option = singleton_service::get_instance_of_booking_option((int)$record->cmid, $optionid);
        $this->assertSame($expected, array_values(array_map(fn($teacher) => $teacher->lastname, $option->get_teachers())));

        // The names of the teachers in the simple table of booking options.
        $names = booking_utils::prepare_teachernames_arrays_for_optionids([(object)['optionid' => $optionid]]);
        $this->assertSame(['Anna Zimmer', 'Carl Adler', 'Bea Maier'], $names[$optionid]);

        // The columns of the teachers in the SQL of the booking options.
        [$select, $from] = booking_option_settings::return_sql_for_teachers();
        $row = $DB->get_record_sql("SELECT bo.id, $select FROM {booking_options} bo $from WHERE bo.id = ?", [$optionid]);
        $this->assertSame('Zimmer, Anna; Adler, Carl; Maier, Bea', $row->teachernames);
        $this->assert_text_order($expected, $row->teacherobjects, 'Column teacherobjects');
    }

    /**
     * A teacher that is added later is placed at the end of an order, and sorted in by name without an order.
     */
    public function test_new_teacher_follows_the_order(): void {
        // With an order: the new teacher is added at the end, although "Adler" is the first by name.
        $record = $this->create_option([$this->maier, $this->zimmer]);
        $this->update_option($record, [$this->maier, $this->zimmer], [$this->zimmer, $this->maier]);
        $this->assertSame(['Zimmer', 'Maier'], $this->get_lastnames($record->id));

        // The form still submits the order of the teachers it knew when it was rendered.
        $this->update_option($record, [$this->maier, $this->zimmer, $this->adler], [$this->zimmer, $this->maier]);
        $this->assertSame(['Zimmer', 'Maier', 'Adler'], $this->get_lastnames($record->id));

        // The same without a submitted order (e.g. an import or a form without the teachers).
        $record2 = $this->create_option([$this->maier, $this->zimmer]);
        $this->update_option($record2, [$this->maier, $this->zimmer], [$this->zimmer, $this->maier]);
        $this->update_option($record2, [$this->maier, $this->zimmer, $this->adler]);
        $this->assertSame(['Zimmer', 'Maier', 'Adler'], $this->get_lastnames($record2->id));

        // Without an order: the new teacher is sorted in by name.
        $record3 = $this->create_option([$this->maier, $this->zimmer]);
        $this->update_option($record3, [$this->maier, $this->zimmer, $this->adler], [$this->maier, $this->zimmer]);
        $this->assertSame(['Adler', 'Maier', 'Zimmer'], $this->get_lastnames($record3->id));
    }

    /**
     * Removing a teacher keeps the order of the others, and the order cannot add or remove teachers.
     */
    public function test_order_does_not_change_who_is_teacher(): void {
        global $DB;

        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);

        // A user who is no teacher of the option is ignored, a missing teacher (Adler) follows at the end.
        $stranger = $this->getDataGenerator()->create_user(['firstname' => 'Dora', 'lastname' => 'Stranger']);
        $this->update_option($record, $teachers, [$stranger, $this->maier, $this->zimmer]);
        $this->assertSame(['Maier', 'Zimmer', 'Adler'], $this->get_lastnames($record->id));
        $this->assertFalse($DB->record_exists('booking_teachers', ['optionid' => $record->id, 'userid' => $stranger->id]));

        // Zimmer is removed in the autocomplete, the order still lists all three.
        $this->update_option($record, [$this->maier, $this->adler], [$this->maier, $this->zimmer, $this->adler]);
        $this->assertSame(['Maier', 'Adler'], $this->get_lastnames($record->id));
    }

    /**
     * An order that equals the order by name removes the stored positions.
     */
    public function test_order_by_name_removes_the_positions(): void {
        global $DB;

        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        $positions = array_map('intval', $DB->get_fieldset_select('booking_teachers', 'sortorder', 'optionid = ?', [$record->id]));
        sort($positions);
        $this->assertSame([1, 2, 3], $positions);

        $this->update_option($record, $teachers, [$this->adler, $this->maier, $this->zimmer]);
        $this->assertSame(['Adler', 'Maier', 'Zimmer'], $this->get_lastnames($record->id));
        $this->assertSame(
            0,
            $DB->count_records_select('booking_teachers', 'optionid = ? AND sortorder <> 0', [$record->id])
        );
    }

    /**
     * The order is a PRO feature: without Booking PRO a submitted order is not saved and the form has no field for it.
     */
    public function test_order_needs_pro(): void {
        global $PAGE;

        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);

        wb_payment::override_pro_version_for_tests(false);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        $this->assertSame(['Adler', 'Maier', 'Zimmer'], $this->get_lastnames($record->id));

        $PAGE->set_url(new url('/mod/booking/tests/teachers_order_test.php'));
        $this->assertStringNotContainsString(teachers_handler::FORM_TEACHERSORDER, $this->render_option_form($record));
    }

    /**
     * The option form shows the saved teachers in their order. They can only be ordered, not added or removed.
     *
     * @covers \mod_booking\teachers_handler::add_to_mform
     * @covers \mod_booking\form\element\sortableselect
     */
    public function test_form_lists_saved_teachers_in_their_order(): void {
        global $PAGE;

        $PAGE->set_url(new url('/mod/booking/tests/teachers_order_test.php'));
        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        booking_option::purge_cache_for_option($record->id);

        $html = $this->render_option_form($record);

        preg_match_all('/<input type="hidden" name="teachersorder\[\]" value="(\d+)"/', $html, $matches);
        $this->assertSame(
            [(string)$this->zimmer->id, (string)$this->adler->id, (string)$this->maier->id],
            $matches[1],
            'The form submits the teachers in their stored order.'
        );
        $this->assert_text_order(['Anna Zimmer', 'Carl Adler', 'Bea Maier'], $html, 'Entries of the sortable select');
        $this->assertStringContainsString('data-action="up"', $html);
        $this->assertStringNotContainsString('data-action="remove"', $html, 'Teachers cannot be removed here.');
        $this->assertStringNotContainsString('data-action="sortableselect-add"', $html, 'Teachers cannot be added here.');
        $this->assertStringNotContainsString(get_string('teachersorder:savefirst', 'mod_booking'), $html);
    }

    /**
     * Submitting the option form saves the order of the sortable select.
     *
     * @covers \mod_booking\form\option_form::process_dynamic_submission
     * @covers \mod_booking\teachers_handler::save_from_form
     */
    public function test_submitted_form_saves_the_order(): void {
        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $settings = singleton_service::get_instance_of_booking_option_settings($record->id);

        // The identifier has a "required" rule and the autocompletes do not export their stored values when
        // they are not submitted, so these fields are submitted like the browser does.
        $submitted = [
            'cmid' => (int)$record->cmid,
            'id' => (int)$record->id,
            'optionid' => (int)$record->id,
            'bookingid' => (int)$this->booking->id,
            'identifier' => $settings->identifier,
            'text' => $settings->text,
            'institution' => '',
            'teachersforoption' => array_map(fn($teacher) => (int)$teacher->id, $teachers),
            // The browser submits the hidden input of the element and one input per entry, in the order of the list.
            teachers_handler::FORM_TEACHERSORDER => [(string)$this->zimmer->id, (string)$this->adler->id, (string)$this->maier->id],
        ];
        if (class_exists('local_entities\\entitiesrelation_handler')) {
            $submitted[LOCAL_ENTITIES_FORM_ENTITYID . '0'] = '';
        }
        $form = new option_form(null, null, 'post', '', [], true, option_form::mock_ajax_submit($submitted), true);
        $form->set_data_for_dynamic_submission();
        $this->assertTrue($form->is_validated(), 'The form with the changed order must validate.');
        $form->process_dynamic_submission();

        $this->assertSame(['Zimmer', 'Adler', 'Maier'], $this->get_lastnames($record->id));
    }

    /**
     * With less than two saved teachers there is nothing to order: the form shows a hint instead.
     *
     * @covers \mod_booking\teachers_handler::add_to_mform
     */
    public function test_form_shows_hint_until_teachers_are_saved(): void {
        global $PAGE;

        $PAGE->set_url(new url('/mod/booking/tests/teachers_order_test.php'));
        $hint = get_string('teachersorder:savefirst', 'mod_booking');

        // A new booking option.
        $new = (object)['id' => 0, 'cmid' => $this->booking->cmid];
        $html = $this->render_option_form($new);
        $this->assertStringContainsString($hint, $html);
        $this->assertStringNotContainsString('data-region="sortableselect"', $html);

        // A saved booking option with one teacher.
        $record = $this->create_option([$this->maier]);
        $html = $this->render_option_form($record);
        $this->assertStringContainsString($hint, $html);
        $this->assertStringNotContainsString('data-region="sortableselect"', $html);
    }

    /**
     * A copy of a booking option keeps the order of its teachers.
     *
     * @covers \mod_booking\booking_option::copytotemplate
     * @covers \mod_booking\teachers_handler::set_data
     */
    public function test_copy_keeps_the_order(): void {
        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        booking_option::purge_cache_for_option($record->id);

        $option = singleton_service::get_instance_of_booking_option((int)$record->cmid, (int)$record->id);
        $templateid = $option->copytotemplate();

        $this->assertNotEquals($record->id, $templateid);
        $this->assertSame(['Zimmer', 'Adler', 'Maier'], $this->get_lastnames($templateid));
    }

    /**
     * The form to duplicate a booking option passes the order on: the new option has no saved teachers yet,
     * so a hidden field holds the order of the duplicated option.
     *
     * @covers \mod_booking\option\fields\duplication::set_data
     * @covers \mod_booking\teachers_handler::add_to_mform
     */
    public function test_duplication_form_passes_the_order_on(): void {
        global $PAGE;

        $PAGE->set_url(new url('/mod/booking/tests/teachers_order_test.php'));
        $teachers = [$this->maier, $this->zimmer, $this->adler];
        $record = $this->create_option($teachers);
        $this->update_option($record, $teachers, [$this->zimmer, $this->adler, $this->maier]);
        booking_option::purge_cache_for_option($record->id);

        $html = $this->render_option_form((object)['id' => 0, 'cmid' => $record->cmid], (int)$record->id);

        $order = implode(',', [$this->zimmer->id, $this->adler->id, $this->maier->id]);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="teachersorder"[^>]*value="' . $order . '"/',
            $html,
            'The hidden field holds the order of the duplicated option.'
        );
    }

    /**
     * Renders the form of a booking option like editoptions.php does.
     *
     * @param stdClass $record the option: id (0 for a new option) and cmid
     * @param int $copyoptionid id of the option to duplicate, 0 if nothing is duplicated
     * @return string
     */
    private function render_option_form(stdClass $record, int $copyoptionid = 0): string {
        $form = new option_form(null, null, 'post', '', [], true, [
            'cmid' => (int)$record->cmid,
            'id' => (int)$record->id,
            'optionid' => (int)$record->id,
            'bookingid' => (int)$this->booking->id,
            'copyoptionid' => $copyoptionid,
            'returnurl' => '',
        ]);
        $form->set_data_for_dynamic_submission();
        return $form->render();
    }
}

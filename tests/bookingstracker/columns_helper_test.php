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

use advanced_testcase;
use mod_booking\booking_answers\booking_answers;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the Bookings tracker column configuration (responsesfields / reportfields).
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class columns_helper_test extends advanced_testcase {
    /**
     * Tests that responsesfields/reportfields drive the tracker columns and SQL runs.
     * @covers \mod_booking\local\bookingstracker\columns_helper
     */
    public function test_tracker_columns_from_instance_settings(): void {
        global $DB;

        $this->resetAfterTest();
        singleton_service::destroy_instance();
        $this->setAdminUser();

        // Custom user profile field.
        $cat = (object)['name' => 'Cat', 'sortorder' => 1];
        $cat->id = $DB->insert_record('user_info_category', $cat);
        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'supervisor',
            'name' => 'Supervisor',
            'datatype' => 'text',
            'categoryid' => $cat->id,
            'sortorder' => 1,
        ]);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['city' => 'Vienna']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'responsesfields' => 'fullname,email,city,timecreated,supervisor,completed',
            'reportsfields' => 'ignored', // Generator typo key, set real one below.
        ]);
        // The generator does not pass reportfields through, so set it directly.
        $DB->set_field('booking', 'reportfields', 'optionid,booking,location,username,email,timecreated,supervisor', [
            'id' => $booking->id,
        ]);
        singleton_service::destroy_instance();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'optiondateid_1' => '0',
            'daystonotify_1' => '0',
            'coursestarttime_1' => strtotime('now + 1 day'),
            'courseendtime_1' => strtotime('now + 2 day'),
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $ba = new booking_answers();

        // 1. Option scope display columns follow responsesfields + status-specific columns.
        // The first columns have a fixed order, all others follow in their configured order.
        // Like on report.php, custom user profile fields come after all standard columns.
        $class = $ba->return_class_for_scope('option');
        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        // Status (presence) and notes are not configured, so they don't show up.
        $this->assertSame(
            ['firstname', 'lastname', 'email', 'completed', 'city', 'timecreated', 'custsupervisor'],
            array_keys($cols)
        );

        // 2. Option scope download columns follow reportfields.
        $dcols = $class->return_cols_for_download(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertSame(
            ['optionid', 'text', 'location', 'username', 'email', 'timecreated', 'custsupervisor'],
            array_keys($dcols)
        );

        // 3. The table SQL runs and returns the booked user including the new fields.
        $bookedusers = new booked_users('option', $option->id, true, false, false, false, false);
        $table = $bookedusers->return_raw_table('option', $option->id, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $table->rawdata);
        $row = reset($table->rawdata);
        $this->assertEquals('Vienna', $row->city);
        $this->assertEquals('Test option', $row->text);
        $this->assertObjectHasProperty('custsupervisor', $row);

        // 3a. The "Toggle completion status" button is added when completed is configured.
        $completionbuttons = array_filter(
            $table->actionbuttons,
            fn($button) => ($button['methodname'] ?? '') === 'toggle_completion_booking_answers'
        );
        $this->assertCount(1, $completionbuttons);

        // 3b. The action toggles the completion status of the checked answers.
        $this->assertEquals(0, $row->completed);
        $table->action_toggle_completion_booking_answers(0, json_encode(['checkedids' => [$row->id]]));
        $this->assertEquals(1, $DB->get_field('booking_answers', 'completed', ['id' => $row->id]));
        $table->action_toggle_completion_booking_answers(0, json_encode(['checkedids' => [$row->id]]));
        $this->assertEquals(0, $DB->get_field('booking_answers', 'completed', ['id' => $row->id]));

        // 3c. The completed column shows a green check mark when completed.
        $this->assertStringContainsString('fa-check', $table->col_completed((object)['completed' => 1]));
        $this->assertSame('', $table->col_completed((object)['completed' => 0]));

        // 4. Instanceanswers scope uses the fixed per-answer columns (no responsesfields mapping).
        $class = $ba->return_class_for_scope('instanceanswers');
        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $settings->cmid);
        $this->assertSame('titleprefix', array_key_first($cols));
        $this->assertArrayHasKey('timebooked', $cols);
        $this->assertArrayHasKey('timemodified', $cols);
        $table = $bookedusers->return_raw_table('instanceanswers', $settings->cmid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $table->rawdata);

        // 5. Empty settings fall back to the default columns.
        $DB->set_field('booking', 'responsesfields', '', ['id' => $booking->id]);
        $DB->set_field('booking', 'reportfields', '', ['id' => $booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();
        $class = $ba->return_class_for_scope('option');
        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertSame(['firstname', 'lastname', 'email', 'status', 'notes'], array_keys($cols));
        $dcols = $class->return_cols_for_download(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertSame(array_keys($cols), array_keys($dcols));

        // 6. Option-specific values are mapped too; rating stays skipped as the instance is not assessed,
        // indexnumber is not supported (anymore) and userpic is moved to the front.
        $DB->set_field('booking', 'responsesfields', 'rating,places,userpic,fullname,indexnumber', ['id' => $booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();
        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertSame(
            ['userpic', 'firstname', 'lastname', 'places'],
            array_keys($cols)
        );

        // 7. The fields of the customform availability condition become columns like on report.php.
        $availability = json_encode([
            [
                'id' => MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
                'name' => 'customform',
                'class' => 'mod_booking\bo_availability\conditions\customform',
                'formsarray' => [
                    1 => [
                        1 => ['formtype' => 'shorttext', 'label' => 'T-shirt size', 'value' => ''],
                        2 => ['formtype' => 'enrolusersaction', 'label' => 'Enrol users', 'value' => ''],
                    ],
                ],
            ],
        ]);
        $DB->set_field('booking_options', 'availability', $availability, ['id' => $option->id]);
        $DB->set_field('booking', 'reportfields', 'optionid,booking,email', ['id' => $booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'bookingoptionsettings')->purge();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();

        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertSame('T-shirt size', $cols['formfield_1'] ?? null);
        $this->assertArrayHasKey('formfield_2', $cols);
        // The enrolusersaction field also adds the enrollink columns, on the page and in the download.
        $this->assertArrayHasKey('enrollink', $cols);
        $this->assertArrayHasKey('enrollinkreceivedfrom', $cols);
        $dcols = $class->return_cols_for_download(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertArrayHasKey('formfield_1', $dcols);
        $this->assertArrayHasKey('enrollink', $dcols);
        $this->assertArrayHasKey('enrollinkreceivedfrom', $dcols);

        // 8. The stored customform answer of the user is rendered into the column.
        $DB->set_field(
            'booking_answers',
            'json',
            json_encode(['condition_customform' => ['customform_shorttext_1' => 'XL']]),
            ['userid' => $student->id, 'optionid' => $option->id]
        );
        $table = $bookedusers->return_raw_table('option', $option->id, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $table->rawdata);
        $row = reset($table->rawdata);
        $this->assertSame('XL', $table->other_cols('formfield_1', $row));
        $this->assertSame('', $table->other_cols('formfield_2', $row));

        // 9. Slot columns are added for slotbooking options, like on report.php (option scope only).
        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $this->assertArrayNotHasKey('slotstarttime', $cols);
        $DB->set_field('booking_options', 'json', json_encode(['slot_enabled' => 1]), ['id' => $option->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'bookingoptionsettings')->purge();

        $cols = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        $dcols = $class->return_cols_for_download(MOD_BOOKING_STATUSPARAM_BOOKED, $option->id);
        foreach (['slotstarttime', 'slotendtime', 'slotnumslots', 'slotteachers', 'slotprice', 'moveslot'] as $slotcol) {
            $this->assertArrayHasKey($slotcol, $cols);
            $this->assertArrayHasKey($slotcol, $dcols);
        }

        // 10. The slot data of the booking answer is rendered into the slot columns.
        $slotstart = strtotime('now + 1 day');
        $DB->set_field(
            'booking_answers',
            'json',
            json_encode(['slot' => [
                'slots' => [['start' => $slotstart, 'end' => $slotstart + HOURSECS]],
                'price' => 12,
            ]]),
            ['userid' => $student->id, 'optionid' => $option->id]
        );
        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
        \cache::make('mod_booking', 'bookedusertable')->purge();
        $table = $bookedusers->return_raw_table('option', $option->id, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $table->rawdata);
        $row = reset($table->rawdata);
        $this->assertSame('1', $table->col_slotnumslots($row));
        $this->assertSame('12', $table->col_slotprice($row));
        $this->assertSame(
            userdate($slotstart, get_string('strftimedatetime', 'langconfig')),
            $table->col_slotstarttime($row)
        );
        $this->assertStringContainsString('moveslot.php', $table->col_moveslot($row));

        // 11. Optiondate scope: email, status (presence) and notes follow responsesfields too.
        // Without status/notes configured, neither the columns nor the action buttons show up.
        $optiondateid = (int)$DB->get_field('booking_optiondates', 'id', ['optionid' => $option->id]);
        $DB->set_field('booking', 'responsesfields', 'fullname,email,city', ['id' => $booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();

        $odclass = $ba->return_class_for_scope('optiondate');
        $cols = $odclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $optiondateid);
        $this->assertSame(['firstname', 'lastname', 'email'], array_keys($cols));
        $table = $bookedusers->return_raw_table('optiondate', $optiondateid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $formnames = array_column($table->actionbuttons, 'formname');
        $this->assertNotContains('mod_booking\form\optiondates\modal_change_status', $formnames);
        $this->assertNotContains('mod_booking\form\optiondates\modal_change_notes', $formnames);

        // With status and notes configured (but no email), columns and buttons appear accordingly.
        $DB->set_field('booking', 'responsesfields', 'fullname,status,notes', ['id' => $booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();

        $cols = $odclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $optiondateid);
        $this->assertSame(['firstname', 'lastname', 'status', 'notes'], array_keys($cols));
        $table = $bookedusers->return_raw_table('optiondate', $optiondateid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $formnames = array_column($table->actionbuttons, 'formname');
        $this->assertContains('mod_booking\form\optiondates\modal_change_status', $formnames);
        $this->assertContains('mod_booking\form\optiondates\modal_change_notes', $formnames);

        // 12. Instanceanswers scope: the email column follows responsesfields as well.
        $icols = $ba->return_class_for_scope('instanceanswers')
            ->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $settings->cmid);
        $this->assertArrayNotHasKey('email', $icols);
        $this->assertArrayHasKey('firstname', $icols);

        // 13. Previously booked answers get their own table (like deleted bookings) in the
        // scopes system, course, instance and option (both view types) - but not in optiondate.
        $DB->set_field(
            'booking_answers',
            'waitinglist',
            MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
            ['userid' => $student->id, 'optionid' => $option->id]
        );
        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
        \cache::make('mod_booking', 'bookedusertable')->purge();
        $scopestocheck = [
            ['option', $option->id],
            ['instance', $settings->cmid],
            ['instanceanswers', $settings->cmid],
            ['course', (int)$course->id],
            ['courseanswers', (int)$course->id],
            ['system', 0],
            ['systemanswers', 0],
        ];
        foreach ($scopestocheck as [$scopename, $sid]) {
            $table = $bookedusers->return_raw_table($scopename, $sid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);
            $this->assertCount(1, $table->rawdata, "previously booked missing in scope $scopename");
        }
        $this->assertNull(
            $bookedusers->return_raw_table('optiondate', $optiondateid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED)
        );

        // 14. The sent messages table (message_sent events, like "Show messages" on report.php)
        // is built for the tracker scopes.
        foreach ([['option', $option->id], ['instance', $settings->cmid], ['course', (int)$course->id], ['system', 0]] as $sc) {
            [$scopename, $sid] = $sc;
            $bu = new booked_users(
                $scopename,
                $sid,
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                0,
                false,
                [],
                true
            );
            $this->assertNotEmpty($bu->sentmessages, "sent messages table missing in scope $scopename");
        }
    }
}

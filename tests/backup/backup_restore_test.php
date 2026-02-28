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
use backup_controller;
use restore_controller;
use backup;
use stdClass;
use context_system;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Test restoring of bookkings with options into another course.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_booking_activity_structure_step
 * @covers \restore_booking_activity_structure_step
 *
 * @runTestsInSeparateProcesses
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Restore a quiz twice into the same target course, and verify the quiz uses the restored questions both times.
     *
     * @param array $bdata
     * @return void
     *
     * @dataProvider booking_backup_restore_settings_provider
     */
    public function test_backup_restore_bookings_with_options_quiz_into_other_course(array $bdata): void {
        global $DB, $USER;

        $this->setAdminUser();

        singleton_service::destroy_instance();

        // Step 1: Create two courses and a user with editing teacher capabilities.
        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course(['enablecompletion' => 1]);
        $course2 = $generator->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user(); // Booking manager.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $generator->enrol_user($teacher->id, $course2->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');

        if (
            class_exists('local_entities\entities') &&
            !empty($bdata['entities'])
        ) {
            // Create custom entity field.
            $categorydata = new stdClass();
            $categorydata->name = 'CustomCat';
            $categorydata->component = 'local_entities';
            $categorydata->area = 'entities';
            $categorydata->itemid = 0;
            $categorydata->contextid = context_system::instance()->id;

            $entitycat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
            $entitycat->save();

            $fielddata = new stdClass();
            $fielddata->categoryid = $entitycat->get('id');
            $fielddata->name = 'EntField1';
            $fielddata->shortname = 'entfield1';
            $fielddata->type = 'text';
            $fielddata->configdata = "";
            $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
            $bookingfield->save();

            // Create entities.
            /** @var local_entities_generator $plugingenerator */
            $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
            foreach ($bdata['entities'] as $entity) {
                $entities[] = $plugingenerator->create_entities($entity);
            }
        }

        // Create custom booking field.
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport1';
        $fielddata->shortname = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        $bookings = [];
        $options = [];
        // Create 1st booking.
        $bdata['booking']['name'] = 'Test Booking 1';
        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $teacher->username;
        $bdata['booking']['cancancelbook'] = 2;
        $bookings[0] = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        // Create 2nd booking.
        $bdata['booking']['name'] = 'Test Booking 2';
        $bdata['booking']['cancancelbook'] = 1;
        $bdata['booking']['allowupdatedays'] = 7;
        $bookings[1] = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        // Create options for bookings.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        // Create price categories if exists.
        if (
            class_exists('local_shopping_cart\shopping_cart') &&
            !empty($bdata['pricecategories'])
        ) {
            // Create user profile custom fields.
            $this->getDataGenerator()->create_custom_profile_field([
                'datatype' => 'text',
                'shortname' => 'pricecat',
                'name' => 'pricecat',
            ]);
            set_config('pricecategoryfield', 'pricecat', 'booking');
            foreach ($bdata['pricecategories'] as $pricecategory) {
                $plugingenerator->create_pricecategory($pricecategory);
            }
        }

        // Create options for the 1st booking.
        $record = (object)$bdata['options'][0];
        $record->bookingid = $bookings[0]->id;
        $record->text = 'Test Option 11';
        $record->customfield_spt1 = 'chess';
        $record->local_entities_entityid_0 = $entities[0];
        $options[0] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][1];
        $record->bookingid = $bookings[0]->id;
        $record->text = 'Test Option 12';
        $record->customfield_spt1 = 'football';
        $options[1] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][2];
        $record->bookingid = $bookings[0]->id;
        $record->text = 'Test Option 13';
        $record->customfield_spt1 = 'polo';
        $record->local_entities_entityid_0 = $entities[1];
        $options[2] = $plugingenerator->create_option($record);

        // Create options for the 2nd booking.
        $record = (object)$bdata['options'][0];
        $record->bookingid = $bookings[1]->id;
        $record->text = 'Test Option 21';
        $record->customfield_spt1 = 'tennis';
        $options[2] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][1];
        $record->bookingid = $bookings[1]->id;
        $record->text = 'Test Option 22';
        $record->customfield_spt1 = 'tennis';
        $options[3] = $plugingenerator->create_option($record);

        $record = (object)$bdata['options'][2];
        $record->bookingid = $bookings[1]->id;
        $record->text = 'Test Option 23';
        $record->customfield_spt1 = 'chess';
        $options[4] = $plugingenerator->create_option($record);

        // History item1: book student1 directly into the option.
        $settings = singleton_service::get_instance_of_booking_option_settings($options[0]->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // History item2: Add "dummy" booking_history entry manually.
        $originalhistory = (object)[
            'bookingid' => $bookings[0]->id,
            'optionid' => $options[0]->id,
            'answerid' => null,
            'userid' => $teacher->id,
            'status' => 0,
            'usermodified' => $teacher->id,
            'timecreated' => time(),
            'json' => '{"info":"test"}',
        ];
        $originalhistory->id = $DB->insert_record('booking_history', $originalhistory);

        // Validate booking history.
        $oldhistory = $DB->get_records('booking_history', ['bookingid' => $bookings[0]->id]);
        $this->assertCount(2, $oldhistory);

        // Step 2: Backup the first course.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course1->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id
        );

        // Include users (and, consequently - booked answers) into the backup.
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('anonymize')->set_value(false);
        $bc->finish_ui();
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Step 3: Import the backup into the second course.
        $rc = new restore_controller(
            $backupid,
            $course2->id,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_CURRENT_ADDING
        );
        // Include users (and, consequently - booked answers) during restore.
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->finish_ui();
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify bookings and options.
        $originbookins = get_fast_modinfo($course1->id)->get_instances_of('booking');
        $restoredbookings = get_fast_modinfo($course2->id)->get_instances_of('booking');
        $originbookins = array_values($originbookins);
        $restoredbookings = array_values($restoredbookings);
        $this->assertCount(count($originbookins), $restoredbookings);

        for ($i = 0; $i < count($restoredbookings); $i++) {
            // Validate booking settngs.
            $bookingobj11 = singleton_service::get_instance_of_booking_by_bookingid((int)($originbookins[$i]->instance));
            $bookingobj21 = singleton_service::get_instance_of_booking_by_bookingid((int)($restoredbookings[$i]->instance));
            $bookingdiff = $plugingenerator->objdiff($bookingobj11->settings, $bookingobj21->settings);
            // Remove booking setting fields that are expected to be different.
            unset(
                $bookingdiff['id'],
                $bookingdiff['course'],
                $bookingdiff['cmid'],
                $bookingdiff['timecreated'],
                $bookingdiff['timemodified']
            );
            $this->assertEmpty(
                $bookingdiff,
                'Restored booking do not match the original ones: ' . json_encode($bookingdiff)
            );
            // Validate bookings' options.
            $options1 = $bookingobj11->get_all_options(0, 0, '', '*, spt1');
            $options2 = $bookingobj21->get_all_options(0, 0, '', '*, spt1');
            $this->assertCount(count($options1), $options2);
            $options1 = array_values($options1);
            $options2 = array_values($options2);
            foreach ($options1 as $key => $option) {
                $settings1 = singleton_service::get_instance_of_booking_option_settings($option->id);
                $settings2 = singleton_service::get_instance_of_booking_option_settings($options2[$key]->id);
                $sessions1 = array_values($settings1->sessions);
                $sessions2 = array_values($settings2->sessions);
                $settings1 = (object)(array)$settings1;
                $settings2 = (object)(array)$settings2;
                unset($settings1->sessions);
                unset($settings2->sessions);
                // Compare option settings in general.
                $optiondiff = $plugingenerator->objdiff($settings1, $settings2);
                // Remove booking option setting fields that are expected to be different.
                unset(
                    $optiondiff['id'],
                    $optiondiff['cmid'],
                    $optiondiff['bookingid'],
                    $optiondiff['identifier'],
                    $optiondiff['timecreated'],
                    $optiondiff['timemodified'],
                    $optiondiff['editoptionurl'],
                    $optiondiff['manageresponsesurl'],
                    $optiondiff['optiondatesteachersurl']
                );
                $this->assertEmpty(
                    $optiondiff,
                    'Restored booking option settings do not match the original ones: ' . json_encode($optiondiff)
                );
                // Compare sessions.
                $sessiondiff = $plugingenerator->arrdiff($sessions1, $sessions2);
                $cleanarrdiff = [];
                foreach ($sessiondiff as $diff) {
                    // Remove option fields that are expected to be different.
                    unset(
                        $diff['id'],
                        $diff['bookingid'],
                        $diff['optionid'],
                        $diff['optiondateid']
                    );
                    if (!empty($diff)) {
                        $cleanarrdiff[] = $diff;
                    }
                }
                $this->assertEmpty(
                    $cleanarrdiff,
                    'Restored booking option sessions do not match the original ones: ' . json_encode($cleanarrdiff)
                );
                // Compare prices if exists.
                if (class_exists('local_shopping_cart\shopping_cart')) {
                    $price1 = price::get_price('option', $settings1->id);
                    $price2 = price::get_price('option', $settings2->id);
                    $pricediff = $plugingenerator->arrdiff($price1, $price2);
                    $this->assertEmpty(
                        $pricediff,
                        'Restored booking option prices do not match the original ones: ' . json_encode($pricediff)
                    );
                }
            }
        }
    }

    /**
     * Data provider for backup_restore_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_backup_restore_settings_provider(): array {
        $bdata = [
            'entities' => [
                [
                    'name' => 'Entity1',
                    'shortname' => 'ent1',
                    'pricefactor' => '1',
                    'maxallocation' => '15',
                ],
                [
                    'name' => 'Entity2',
                    'shortname' => 'ent2',
                    'pricefactor' => '2',
                    'maxallocation' => '25',
                ],
            ],
            'pricecategories' => [
                [
                    'ordernum' => 1,
                    'name' => 'default',
                    'identifier' => 'default',
                    'defaultvalue' => 111,
                    'pricecatsortorder' => 1,
                ],
                [
                    'ordernum' => 2,
                    'name' => 'student',
                    'identifier' => 'student',
                    'defaultvalue' => 222,
                    'pricecatsortorder' => 2,
                ],
                [
                    'ordernum' => 3,
                    'name' => 'staff',
                    'identifier' => 'staff',
                    'defaultvalue' => 333,
                    'pricecatsortorder' => 3,
                ],
            ],
            'booking' => [
                'name' => 'Test Booking',
                'eventtype' => 'Test event',
                'enablecompletion' => 1,
                'bookedtext' => ['text' => 'text'],
                'waitingtext' => ['text' => 'text'],
                'notifyemail' => ['text' => 'text'],
                'statuschangetext' => ['text' => 'text'],
                'deletedtext' => ['text' => 'text'],
                'pollurltext' => ['text' => 'text'],
                'pollurlteacherstext' => ['text' => 'text'],
                'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                'tags' => '',
                'completion' => 2,
                'cancancelbook' => 0,
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            ],
            'options' => [
                // Option 1 with 2 sessions.
                0 => [
                    'text' => 'Test Option 1',
                    'courseid' => 0,
                    'maxanswers' => 2,
                    'location' => 'ent1',
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('20 May 2050 15:00'),
                    'courseendtime_0' => strtotime('20 June 2050 14:00'),
                    'optiondateid_1' => "0",
                    'daystonotify_1' => "0",
                    'coursestarttime_1' => strtotime('20 June 2050 15:00'),
                    'courseendtime_1' => strtotime('20 July 2050 14:00'),
                ],
                // Option 2 with single session.
                1 => [
                    'text' => 'Test Option 2',
                    'courseid' => 0,
                    'maxanswers' => 4,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('20 July 2050 15:00'),
                    'courseendtime_0' => strtotime('20 August 2050 14:00'),
                ],
                // Option 3 with "wait for confirmation" and price.
                2 => [
                    'text' => 'Wait for confirmation Booking Option, price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationwithprice',
                    'maxanswers' => 1,
                    'location' => 'ent2',
                    'useprice' => 1,
                    'default' => 20,
                    'student' => 10,
                    'staff' => 0,
                    'importing' => 1,
                    'waitforconfirmation' => 1,
                ],
            ],
        ];

        return ['bdata' => [$bdata]];
    }
}

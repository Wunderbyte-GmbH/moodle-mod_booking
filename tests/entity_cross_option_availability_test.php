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
 * Tests for shared-entity availability across booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\option\fields\entities as entitiesfield;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\external\get_slots;
use mod_booking\external\save_slot_selection;
use local_entities\entities as localentities;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests that two booking options sharing the same entity block each other when it is occupied.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entity_cross_option_availability_test extends booking_advanced_testcase {
    /**
     * The expand/collapse state of every header is restored from the submitted data (suffix-tolerant
     * against the per-render random ids of dynamic forms) — not just the bespoke dates header.
     *
     * @covers \mod_booking\option\fields_info::restore_header_collapse_state
     *
     * @return void
     */
    public function test_header_collapse_state_restored_for_all_headers(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/booking/locallib.php');
        require_once($CFG->libdir . '/formslib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        // The editoptions.php page sets the page url; mirror it so rendering the editor does not complain.
        $PAGE->set_url(new \moodle_url('/mod/booking/editoptions.php'));

        $course = $this->getDataGenerator()->create_course();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Header state option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('1 July 2050 10:00');
        $record->courseendtime_1 = strtotime('1 July 2050 12:00');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $baseparams = [
            'cmid' => $settings->cmid,
            'id' => $option->id,
            'optionid' => $option->id,
            'bookingid' => $booking->id,
            'returnurl' => '',
        ];

        // Helper to grab the opening <fieldset> tag of a header by its (suffixed) id.
        $headertag = function (string $html, string $name): string {
            preg_match('/<fieldset\b[^>]*\bid="id_' . preg_quote($name, '/') . '_[^"]*"[^>]*>/', $html, $m);
            return $m[0] ?? '';
        };

        // Submitted state: entities + dates EXPANDED (1), slot settings COLLAPSED (0).
        $params = $baseparams + [
            'mform_isexpanded_id_datesheader_oldrandom' => 1,
            'mform_isexpanded_id_entitiesrelation_oldrandom' => 1,
            'mform_isexpanded_id_slotsettingsheader_oldrandom' => 0,
        ];
        $form = new \mod_booking\form\option_form(null, null, 'post', '', [], true, $params);
        $form->set_data_for_dynamic_submission();
        $html = $form->render();

        $this->assertStringNotContainsString(
            'collapsed',
            $headertag($html, 'datesheader'),
            'Dates header must be restored expanded.'
        );
        $this->assertStringNotContainsString(
            'collapsed',
            $headertag($html, 'entitiesrelation'),
            'Entities header must be restored expanded (previously always collapsed).'
        );

        // Same option, submitted state: entities COLLAPSED (0).
        $params2 = $baseparams + ['mform_isexpanded_id_entitiesrelation_oldrandom' => 0];
        $form2 = new \mod_booking\form\option_form(null, null, 'post', '', [], true, $params2);
        $form2->set_data_for_dynamic_submission();
        $html2 = $form2->render();

        $this->assertStringContainsString(
            'collapsed',
            $headertag($html2, 'entitiesrelation'),
            'Entities header must be restored collapsed when that was the submitted state.'
        );
    }

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        // These tests exercise the local_entities capacity/equipment API; skip cleanly when it is not
        // available (local_entities absent or older than the version that ships it).
        if (!\mod_booking\local\entities_compat::has_capacity_support()) {
            $this->markTestSkipped('local_entities capacity API (>= '
                . \mod_booking\local\entities_compat::MIN_VERSION . ') is required for these tests.');
        }
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        \local_entities\entities::reset_caches();
    }

    /**
     * Two normal options on the same exclusive entity: an overlapping second option must be
     * reported as a conflict, a non-overlapping one must not.
     *
     * @covers \mod_booking\option\fields\entities::validation
     * @covers \mod_booking\option\fields\entities::order_all_dates_to_book_in_form
     * @covers \local_entities\entities::return_conflicts
     *
     * @return void
     */
    public function test_entity_conflict_two_normal_options(): void {

        $bdata = [
            'name' => 'Entity conflict booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Entity in exclusive mode: a resource that can only hold one reservation at a time
        // (e.g. a tennis court), regardless of the number of participants.
        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities([
            'name' => 'Court 1',
            'shortname' => 'court1',
            'description' => 'Tennis court',
            'allocationmode' => 'exclusive',
            'maxconcurrent' => 1,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Option A occupies the entity on 1 July 2050, 10:00-12:00.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Option A';
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('1 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('1 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $entityid;
        $recorda->local_entities_entityid_1 = $entityid;
        $recorda->local_entities_entityarea_1 = "optiondate";
        $recorda->er_saverelationsforoptiondates = 1;

        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // Sanity check: option A's date is now registered on the entity.
        $this->assertNotEmpty(
            localentities::get_all_dates_for_entity($entityid),
            'Option A date should be registered on the entity.'
        );

        // Option B overlaps option A (11:00-13:00) on the same entity -> expect a conflict error.
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Option B',
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('1 July 2050 11:00'),
            'courseendtime_1' => strtotime('1 July 2050 13:00'),
            'local_entities_entityid_0' => $entityid,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);

        $this->assertArrayHasKey(
            'local_entities_entityid_0',
            $errors,
            'An overlapping option on an exclusive entity must produce a validation error.'
        );

        // Option C does NOT overlap option A (12:00-13:00) -> must be accepted.
        $datac = $datab;
        $datac['text'] = 'Option C';
        $datac['coursestarttime_1'] = strtotime('1 July 2050 12:00');
        $datac['courseendtime_1'] = strtotime('1 July 2050 13:00');

        $errorsc = [];
        entitiesfield::validation($datac, [], $errorsc);

        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errorsc,
            'A non-overlapping option must not be flagged as a conflict.'
        );
    }

    /**
     * By default (allocationmode 'none') an entity does NOT check overlaps, preserving the
     * previous behaviour: two overlapping options on the same entity must not conflict.
     *
     * @covers \local_entities\entities::return_conflicts
     * @covers \local_entities\entities::get_allocation_mode
     *
     * @return void
     */
    public function test_default_mode_does_not_check_overlaps(): void {

        $bdata = [
            'name' => 'Default mode booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Entity WITHOUT an allocation mode -> defaults to 'none' (no overlap checking).
        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities([
            'name' => 'Default court',
            'shortname' => 'defcourt',
            'description' => 'Default mode court',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Default A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Default A';
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('5 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('5 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $entityid;
        $recorda->local_entities_entityid_1 = $entityid;
        $recorda->local_entities_entityarea_1 = "optiondate";
        $recorda->er_saverelationsforoptiondates = 1;
        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // Overlapping option B on the same entity must NOT conflict (default mode = none).
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Default B',
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('5 July 2050 11:00'),
            'courseendtime_1' => strtotime('5 July 2050 13:00'),
            'local_entities_entityid_0' => $entityid,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);

        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errors,
            'Default allocation mode (none) must not check overlaps.'
        );
    }

    /**
     * Capacity mode counting participants (maxanswers): a room of capacity 100 holds option A (20)
     * plus option B (80) but not option B (90).
     *
     * @covers \local_entities\entities::return_conflicts
     * @covers \local_entities\entities::resolve_consumed_quantity
     *
     * @return void
     */
    public function test_capacity_maxanswers_room(): void {

        [$booking, $course, $entityid, $plugingenerator] = $this->make_capacity_scenario([
            'name' => 'Room 100',
            'shortname' => 'room100',
            'capacitysource' => 'maxanswers',
            'maxallocation' => 100,
        ]);

        // Option A occupies the room with 20 participants on 6 July 2050, 10:00-12:00.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Room option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Room option A';
        $recorda->maxanswers = 20;
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('6 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('6 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $entityid;
        $recorda->local_entities_entityid_1 = $entityid;
        $recorda->local_entities_entityarea_1 = "optiondate";
        $recorda->er_saverelationsforoptiondates = 1;
        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // Overlapping option B with 90 participants → 20 + 90 > 100 → conflict.
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Room option B',
            'maxanswers' => 90,
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('6 July 2050 11:00'),
            'courseendtime_1' => strtotime('6 July 2050 13:00'),
            'local_entities_entityid_0' => $entityid,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);
        $this->assertArrayHasKey(
            'local_entities_entityid_0',
            $errors,
            'Capacity exceeded (20 + 90 > 100) must conflict.'
        );

        // Same but 80 participants → 20 + 80 = 100, not greater → no conflict.
        $datac = $datab;
        $datac['maxanswers'] = 80;
        $errorsc = [];
        entitiesfield::validation($datac, [], $errorsc);
        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errorsc,
            'Capacity exactly met (20 + 80 = 100) must not conflict.'
        );
    }

    /**
     * Capacity mode with a manually entered quantity (equipment): 2 beamers in stock; one booking
     * takes 1, a second overlapping booking taking 2 conflicts, taking 1 does not.
     *
     * @covers \local_entities\entities::return_conflicts
     * @covers \local_entities\entities::resolve_consumed_quantity
     *
     * @return void
     */
    public function test_capacity_manual_equipment(): void {

        [$booking, $course, $entityid, $plugingenerator] = $this->make_capacity_scenario([
            'name' => 'Beamer pool',
            'shortname' => 'beamers',
            'capacitysource' => 'manual',
            'maxallocation' => 2,
        ]);

        // Option A reserves 1 beamer on 6 July 2050, 10:00-12:00.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Beamer option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Beamer option A';
        $recorda->maxanswers = 30;
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('6 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('6 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $entityid;
        $recorda->local_entities_entityid_1 = $entityid;
        $recorda->local_entities_entityarea_1 = "optiondate";
        $recorda->er_saverelationsforoptiondates = 1;
        $recorda->local_entities_quantity_0 = 1;
        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // Overlapping option B wants 2 beamers → 1 + 2 > 2 → conflict.
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Beamer option B',
            'maxanswers' => 30,
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('6 July 2050 11:00'),
            'courseendtime_1' => strtotime('6 July 2050 13:00'),
            'local_entities_entityid_0' => $entityid,
            'local_entities_quantity_0' => 2,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);
        $this->assertArrayHasKey(
            'local_entities_entityid_0',
            $errors,
            'Equipment over stock (1 + 2 > 2) must conflict.'
        );

        // Same but only 1 beamer → 1 + 1 = 2, not greater → no conflict.
        $datac = $datab;
        $datac['local_entities_quantity_0'] = 1;
        $errorsc = [];
        entitiesfield::validation($datac, [], $errorsc);
        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errorsc,
            'Equipment within stock (1 + 1 = 2) must not conflict.'
        );
    }

    /**
     * An entity linked only at OPTION level occupies all of its option's session times (fallback),
     * so an overlapping option on the same entity conflicts.
     *
     * @covers \mod_booking\booking::return_array_of_entity_dates
     *
     * @return void
     */
    public function test_option_level_entity_occupies_all_sessions(): void {

        [$booking, $course, $entityid, $plugingenerator] = $this->make_capacity_scenario([
            'name' => 'Exclusive room',
            'shortname' => 'exclroom',
            'allocationmode' => 'exclusive',
            'maxconcurrent' => 1,
        ]);

        // Option A links the entity ONLY at option level (no optiondate relation, no copy),
        // but has a session on 8 July 2050, 10:00-12:00.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Option-level A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Option-level A';
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('8 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('8 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $entityid;
        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // The option-level entity must now occupy the session time.
        $overlapfound = false;
        foreach (localentities::get_all_dates_for_entity($entityid) as $date) {
            if (
                (int)$date->starttime < strtotime('8 July 2050 12:00')
                && (int)$date->endtime > strtotime('8 July 2050 10:00')
            ) {
                $overlapfound = true;
                break;
            }
        }
        $this->assertTrue($overlapfound, 'An option-level entity must occupy its session times.');

        // Overlapping option B on the same exclusive entity → conflict.
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Option-level B',
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('8 July 2050 11:00'),
            'courseendtime_1' => strtotime('8 July 2050 13:00'),
            'local_entities_entityid_0' => $entityid,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);
        $this->assertArrayHasKey(
            'local_entities_entityid_0',
            $errors,
            'An overlapping option on the same exclusive option-level entity must conflict.'
        );
    }

    /**
     * Equipment availability resolves over the location hierarchy: a location offers its own
     * equipment plus ancestor equipment flagged 'availableinsublocations'.
     *
     * @covers \local_entities\entities::get_equipment_for_location
     *
     * @return void
     */
    public function test_get_equipment_for_location_hierarchy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');

        $hallid = $entitygenerator->create_entities([
            'name' => 'Hall', 'shortname' => 'hall', 'entitytype' => 'location',
        ]);
        $courtid = $entitygenerator->create_entities([
            'name' => 'Court', 'shortname' => 'court', 'entitytype' => 'location', 'parentid' => $hallid,
        ]);

        // Equipment hung at the hall, usable in sub-locations.
        $ballsid = $entitygenerator->create_entities([
            'name' => 'Balls', 'shortname' => 'balls', 'entitytype' => 'equipment',
            'parentid' => $hallid, 'availableinsublocations' => 1,
        ]);
        // Equipment hung at the hall, NOT usable in sub-locations.
        $hallgearid = $entitygenerator->create_entities([
            'name' => 'Hall gear', 'shortname' => 'hallgear', 'entitytype' => 'equipment',
            'parentid' => $hallid, 'availableinsublocations' => 0,
        ]);
        // Equipment hung directly at the court.
        $netid = $entitygenerator->create_entities([
            'name' => 'Net', 'shortname' => 'net', 'entitytype' => 'equipment', 'parentid' => $courtid,
        ]);

        // The court inherits the hall's shared balls + its own net, but NOT the non-shared hall gear.
        $courtequipment = array_keys(localentities::get_equipment_for_location($courtid));
        sort($courtequipment);
        $expected = [$ballsid, $netid];
        sort($expected);
        $this->assertEquals($expected, $courtequipment, 'Court equipment must be inherited balls + own net.');

        // The hall offers its own equipment (balls + hall gear), but not the court-level net.
        $hallequipment = array_keys(localentities::get_equipment_for_location($hallid));
        sort($hallequipment);
        $expectedhall = [$ballsid, $hallgearid];
        sort($expectedhall);
        $this->assertEquals($expectedhall, $hallequipment, 'Hall equipment must be its own balls + hall gear.');
    }

    /**
     * The booking option form renders a quantity field for each equipment available at the chosen
     * location (and none when no location is chosen) — the dynamic-form definition reads the chosen
     * location from the submitted data.
     *
     * @covers \local_entities\entitiesrelation_handler::instance_form_definition
     *
     * @return void
     */
    public function test_equipment_fields_rendered_for_chosen_location(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $locid = $entitygenerator->create_entities([
            'name' => 'Render hall', 'shortname' => 'renderhall', 'entitytype' => 'location',
        ]);
        $eqid = $entitygenerator->create_entities([
            'name' => 'Projector', 'shortname' => 'projr', 'entitytype' => 'equipment', 'parentid' => $locid,
        ]);
        $eqfield = 'local_entities_equipment_' . $eqid;

        $handler = new \local_entities\entitiesrelation_handler('mod_booking', 'option');

        // With a location chosen (as the dynamic form submits it), the equipment field is rendered.
        $_POST['local_entities_entityid_0'] = (string)$locid;
        $mform = new \MoodleQuickForm('testform1', 'post', '#');
        $handler->instance_form_definition($mform, 0);
        $this->assertTrue(
            $mform->elementExists($eqfield),
            'Equipment quantity field must be rendered for equipment available at the chosen location.'
        );

        // Without a chosen location, no equipment fields are rendered.
        unset($_POST['local_entities_entityid_0']);
        $mform2 = new \MoodleQuickForm('testform2', 'post', '#');
        $handler->instance_form_definition($mform2, 0);
        $this->assertFalse(
            $mform2->elementExists($eqfield),
            'No equipment fields must be rendered without a chosen location.'
        );
    }

    /**
     * Booking equipment of a location: it is saved as an additional option-level relation alongside
     * the room (without clobbering it), and a second overlapping option that exceeds the equipment
     * stock conflicts.
     *
     * @covers \local_entities\entitiesrelation_handler::save_equipment_relations
     * @covers \local_entities\entitiesrelation_handler::instance_form_validation
     *
     * @return void
     */
    public function test_equipment_booking_capacity_conflict(): void {
        global $DB;

        $bdata = [
            'name' => 'Equipment booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $locid = $entitygenerator->create_entities([
            'name' => 'Gym', 'shortname' => 'gym', 'entitytype' => 'location',
        ]);
        // 2 beamers in stock, hung under the gym.
        $beamerid = $entitygenerator->create_entities([
            'name' => 'Beamers', 'shortname' => 'beamers2', 'entitytype' => 'equipment',
            'parentid' => $locid, 'allocationmode' => 'capacity', 'capacitysource' => 'manual',
            'maxallocation' => 2,
        ]);
        $eqkey = 'local_entities_equipment_' . $beamerid;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Option A in the gym, reserving 1 beamer on 9 July 2050, 10:00-12:00.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Gym option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Gym option A';
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = strtotime('9 July 2050 10:00');
        $recorda->courseendtime_1 = strtotime('9 July 2050 12:00');
        $recorda->local_entities_entityid_0 = $locid;
        $recorda->{$eqkey} = 1;
        $optiona = $plugingenerator->create_option($recorda);
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);

        // The room relation AND the equipment relation must coexist (no clobber).
        $this->assertTrue(
            $DB->record_exists(
                'local_entities_relations',
                ['entityid' => $locid, 'component' => 'mod_booking', 'area' => 'option', 'instanceid' => $optiona->id]
            ),
            'Room relation must be kept.'
        );
        $this->assertTrue(
            $DB->record_exists(
                'local_entities_relations',
                ['entityid' => $beamerid, 'component' => 'mod_booking', 'area' => 'option', 'instanceid' => $optiona->id]
            ),
            'Equipment relation must be saved alongside the room.'
        );

        // Option B wants 2 beamers overlapping → 1 + 2 > 2 → conflict on the equipment field.
        $datab = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $settingsa->cmid,
            'bookingid' => $booking->id,
            'text' => 'Gym option B',
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => strtotime('9 July 2050 11:00'),
            'courseendtime_1' => strtotime('9 July 2050 13:00'),
            'local_entities_entityid_0' => $locid,
            $eqkey => 2,
        ];
        $errors = [];
        entitiesfield::validation($datab, [], $errors);
        $this->assertArrayHasKey($eqkey, $errors, 'Equipment over stock (1 + 2 > 2) must conflict.');

        // Same but only 1 beamer → 1 + 1 = 2 → no conflict.
        $datac = $datab;
        $datac[$eqkey] = 1;
        $errorsc = [];
        entitiesfield::validation($datac, [], $errorsc);
        $this->assertArrayNotHasKey($eqkey, $errorsc, 'Equipment within stock (1 + 1 = 2) must not conflict.');
    }

    /**
     * Editing an existing option that is linked to an entity must NEVER conflict with its own
     * already-stored dates.
     *
     * @covers \mod_booking\option\fields\entities::validation
     * @covers \local_entities\entities::return_conflicts
     *
     * @return void
     */
    public function test_no_self_conflict_when_editing_option(): void {

        $bdata = [
            'name' => 'Entity self conflict booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities([
            'name' => 'Court 3',
            'shortname' => 'court3',
            'description' => 'Self conflict court',
            'allocationmode' => 'exclusive',
            'maxconcurrent' => 1,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $start = strtotime('3 July 2050 10:00');
        $end = strtotime('3 July 2050 12:00');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Self option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Self option';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = $start;
        $record->courseendtime_1 = $end;
        $record->local_entities_entityid_0 = $entityid;
        $record->local_entities_entityid_1 = $entityid;
        $record->local_entities_entityarea_1 = "optiondate";
        $record->er_saverelationsforoptiondates = 1;

        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $session = reset($settings->sessions);

        // Now "edit" the same option (same entity, same date) and validate as the real form does.
        $editdata = [
            'id' => $option->id,
            'optionid' => $option->id,
            'cmid' => $settings->cmid,
            'bookingid' => $booking->id,
            'text' => 'Self option edited',
            'optiondateid_1' => (string)$session->optiondateid,
            'daystonotify_1' => "0",
            'coursestarttime_1' => $start,
            'courseendtime_1' => $end,
            'local_entities_entityid_0' => $entityid,
            'local_entities_entityid_1' => $entityid,
            'local_entities_entityarea_1' => 'optiondate',
            'er_saverelationsforoptiondates' => 1,
        ];

        $errors = [];
        entitiesfield::validation($editdata, [], $errors);

        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errors,
            'Editing an option must never conflict with its own stored dates.'
        );
    }

    /**
     * A normal option occupying an entity must mark the overlapping slots of a slotbooking
     * option on the same entity as unavailable, while non-overlapping slots stay bookable.
     *
     * @covers \mod_booking\local\slotbooking\slot_availability::has_entity_conflict_for_slot
     * @covers \mod_booking\local\slotbooking\slot_availability::evaluate_slot_for_user
     *
     * @return void
     */
    public function test_occupied_entity_blocks_overlapping_slots(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $bdata = [
            'name' => 'Entity slot blocking booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities([
            'name' => 'Court 2',
            'shortname' => 'court2',
            'description' => 'Shared court',
            'allocationmode' => 'exclusive',
            'maxconcurrent' => 1,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Normal option A occupies the entity on 7 Jan 2050, 10:00-11:00.
        $occupiedstart = strtotime('2050-01-07 10:00:00');
        $occupiedend = strtotime('2050-01-07 11:00:00');

        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Normal option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->description = 'Normal option A';
        $recorda->optiondateid_1 = "0";
        $recorda->daystonotify_1 = "0";
        $recorda->coursestarttime_1 = $occupiedstart;
        $recorda->courseendtime_1 = $occupiedend;
        $recorda->local_entities_entityid_0 = $entityid;
        $recorda->local_entities_entityid_1 = $entityid;
        $recorda->local_entities_entityarea_1 = "optiondate";
        $recorda->er_saverelationsforoptiondates = 1;
        $plugingenerator->create_option($recorda);

        // Slot option B on the SAME entity: fixed 60-minute slots 09:00-12:00 on 7 Jan 2050.
        $recordb = [
            'bookingid' => $booking->id,
            'text' => 'Slot option B',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00'),
            'slot_valid_until' => strtotime('2050-01-07 23:59:59'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'local_entities_entityid_0' => $entityid,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $recordb['slot_day_' . $day] = 1;
        }
        $optionb = $plugingenerator->create_option((object)$recordb);

        singleton_service::destroy_instance();

        $rangestart = strtotime('2050-01-07 00:00:00');
        $rangeend = strtotime('2050-01-08 00:00:00');
        $slots = slot_availability::get_slots_with_status_for_range($optionb->id, $rangestart, $rangeend);

        $this->assertNotEmpty($slots, 'Slot option B should generate slots on 7 Jan 2050.');

        $foundoverlap = false;
        foreach ($slots as $slot) {
            $overlaps = ((int)$slot['start'] < $occupiedend) && ((int)$slot['end'] > $occupiedstart);
            if ($overlaps) {
                $foundoverlap = true;
                $this->assertSame(
                    'unavailable',
                    $slot['status'],
                    'A slot overlapping the occupied entity must be unavailable.'
                );
            } else {
                $this->assertNotSame(
                    'unavailable',
                    $slot['status'],
                    'A slot that does not overlap the occupied entity must not be entity-blocked.'
                );
            }
        }

        $this->assertTrue($foundoverlap, 'Expected at least one slot overlapping the occupied entity.');
    }

    /**
     * A slot booked on a slotbooking option must create a conflict when a normal option's
     * optiondate is created over it on the same entity (slot -> normal).
     *
     * @covers \mod_booking\booking::return_array_of_entity_dates
     * @covers \mod_booking\local\slotbooking\slot_availability::get_booked_slot_ranges_for_option
     * @covers \local_entities\entities::return_conflicts
     *
     * @return void
     */
    public function test_booked_slot_blocks_overlapping_normal_option(): void {
        [$booking, , $entityid, , $bookedstart, $bookedend, $cmid] = $this->create_entity_with_booked_slot();

        // Sanity: the booked slot now shows up as entity occupancy.
        $overlapfound = false;
        foreach (localentities::get_all_dates_for_entity($entityid) as $date) {
            if ((int)$date->starttime < $bookedend && (int)$date->endtime > $bookedstart) {
                $overlapfound = true;
                break;
            }
        }
        $this->assertTrue($overlapfound, 'A booked slot must appear as entity occupancy.');

        // Normal option overlapping the booked slot must conflict.
        $data = [
            'id' => 0,
            'optionid' => 0,
            'cmid' => $cmid,
            'bookingid' => $booking->id,
            'text' => 'Normal over slot',
            'optiondateid_1' => "0",
            'daystonotify_1' => "0",
            'coursestarttime_1' => $bookedstart + 900,
            'courseendtime_1' => $bookedend + 900,
            'local_entities_entityid_0' => $entityid,
        ];
        $errors = [];
        entitiesfield::validation($data, [], $errors);
        $this->assertArrayHasKey(
            'local_entities_entityid_0',
            $errors,
            'A normal option overlapping a booked slot must conflict.'
        );

        // Normal option far away (no overlap) must not conflict.
        $datafar = $data;
        $datafar['coursestarttime_1'] = $bookedend + (7 * DAYSECS);
        $datafar['courseendtime_1'] = $bookedend + (7 * DAYSECS) + HOURSECS;
        $errorsfar = [];
        entitiesfield::validation($datafar, [], $errorsfar);
        $this->assertArrayNotHasKey(
            'local_entities_entityid_0',
            $errorsfar,
            'A normal option not overlapping any booked slot must not conflict.'
        );
    }

    /**
     * A slot booked on one slotbooking option must mark the overlapping slot of a second
     * slotbooking option on the same entity as unavailable (slot <-> slot).
     *
     * @covers \mod_booking\booking::return_array_of_entity_dates
     * @covers \mod_booking\local\slotbooking\slot_availability::has_entity_conflict_for_slot
     *
     * @return void
     */
    public function test_booked_slot_blocks_overlapping_slot_of_other_option(): void {
        [$booking, $course, $entityid, , $bookedstart, $bookedend] = $this->create_entity_with_booked_slot();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Second slot option D on the SAME entity, identical slot grid.
        $optiond = $plugingenerator->create_option(
            (object)$this->slot_option_record($booking->id, $course->id, $entityid, 'Slot option D')
        );

        singleton_service::destroy_instance();
        slot_availability::clear_request_cache();

        $rangestart = strtotime('2050-01-07 00:00:00');
        $rangeend = strtotime('2050-01-08 00:00:00');
        $slots = slot_availability::get_slots_with_status_for_range($optiond->id, $rangestart, $rangeend);
        $this->assertNotEmpty($slots, 'Slot option D should generate slots.');

        $foundoverlap = false;
        foreach ($slots as $slot) {
            $overlaps = ((int)$slot['start'] < $bookedend) && ((int)$slot['end'] > $bookedstart);
            if ($overlaps) {
                $foundoverlap = true;
                $this->assertSame(
                    'unavailable',
                    $slot['status'],
                    'A slot of option D overlapping the slot booked on option B must be unavailable.'
                );
            } else {
                $this->assertNotSame(
                    'unavailable',
                    $slot['status'],
                    'A non-overlapping slot of option D must not be entity-blocked.'
                );
            }
        }

        $this->assertTrue($foundoverlap, 'Expected one D slot overlapping the booked B slot.');
    }

    /**
     * Creates a booking + course + enrolled teacher + a capacity-mode entity.
     *
     * @param array $entityextra entity fields merged over the capacity defaults
     *                           (e.g. name, shortname, capacitysource, maxallocation)
     * @return array [booking, course, entityid, mod_booking_generator]
     */
    private function make_capacity_scenario(array $entityextra): array {
        $bdata = [
            'name' => 'Capacity booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities(array_merge([
            'description' => 'Shared resource',
            'allocationmode' => 'capacity',
        ], $entityextra));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        return [$booking, $course, $entityid, $plugingenerator];
    }

    /**
     * Returns a fixed-slot option record (09:00-12:00, 60-minute slots on 7 Jan 2050) on the
     * given entity.
     *
     * @param int $bookingid
     * @param int $courseid
     * @param int $entityid
     * @param string $text
     * @return array
     */
    private function slot_option_record(int $bookingid, int $courseid, int $entityid, string $text): array {
        $record = [
            'bookingid' => $bookingid,
            'text' => $text,
            'course' => $courseid,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00'),
            'slot_valid_until' => strtotime('2050-01-07 23:59:59'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'local_entities_entityid_0' => $entityid,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        return $record;
    }

    /**
     * Creates a booking + exclusive entity + slot option (no booking made yet).
     *
     * @param string $entityname
     * @param string $shortname
     * @param string $optiontext
     * @return array [booking, course, entityid, optionb, cmid, student]
     */
    private function create_entity_and_slot_option(string $entityname, string $shortname, string $optiontext): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $bdata = [
            'name' => 'Entity booked slot booking',
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

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid = $entitygenerator->create_entities([
            'name' => $entityname,
            'shortname' => $shortname,
            'description' => 'Shared resource',
            'allocationmode' => 'exclusive',
            'maxconcurrent' => 1,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionb = $plugingenerator->create_option(
            (object)$this->slot_option_record($booking->id, $course->id, $entityid, $optiontext)
        );

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionb->id);
        $cmid = (int)$settings->cmid;

        return [$booking, $course, $entityid, $optionb, $cmid, $student];
    }

    /**
     * Books the first offered slot of an option for a student through the real API flow
     * (get_slots + save_slot_selection + user_submit_response), no direct DB writes.
     *
     * @param int $optionid
     * @param int $cmid
     * @param \stdClass $student
     * @return array [bookedstart, bookedend]
     */
    private function book_first_slot(int $optionid, int $cmid, \stdClass $student): array {
        $this->setUser($student);

        $slots = json_decode(get_slots::execute($optionid, $student->id)['slots'], true);
        $this->assertNotEmpty($slots, 'The slot option should offer bookable slots.');

        $target = $slots[1] ?? $slots[0];
        $bookedstart = (int)$target['start'];
        $bookedend = (int)$target['end'];

        $selection = save_slot_selection::execute($optionid, $student->id, json_encode([$target['key']]), '{}');
        $selection = \core_external\external_api::clean_returnvalue(save_slot_selection::execute_returns(), $selection);
        $this->assertTrue($selection['valid'], 'The slot selection should be valid before booking.');

        $optionobj = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $optionobj->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->setAdminUser();
        singleton_service::destroy_instance();
        slot_availability::clear_request_cache();

        return [$bookedstart, $bookedend];
    }

    /**
     * Creates a booking + exclusive entity + slot option, and books one slot for a student via
     * the real API flow.
     *
     * @return array [booking, course, entityid, optionb, bookedstart, bookedend, cmid]
     */
    private function create_entity_with_booked_slot(): array {
        [$booking, $course, $entityid, $optionb, $cmid, $student] =
            $this->create_entity_and_slot_option('Court 4', 'court4', 'Slot option B');
        [$bookedstart, $bookedend] = $this->book_first_slot($optionb->id, $cmid, $student);

        return [$booking, $course, $entityid, $optionb, $bookedstart, $bookedend, $cmid];
    }

    /**
     * Booking a slot must targeted-purge the entity occupancy cache, so a subsequent cached read
     * reflects the new booking.
     *
     * @covers \local_entities\entities::get_all_dates_for_entity
     * @covers \local_entities\entitiesrelation_handler::purge_dates_cache
     *
     * @return void
     */
    public function test_booking_purges_entity_dates_cache(): void {
        [, , $entityid, $optionb, $cmid, $student] =
            $this->create_entity_and_slot_option('Cache court', 'cachecourt', 'Cache slot option');

        // Warm the (cached) occupancy read while the entity is still free.
        $before = localentities::get_all_dates_for_entity($entityid);
        $this->assertEmpty($before, 'No bookings yet, so occupancy is empty.');

        // Book a slot via the real API; this must purge the entity occupancy cache.
        $this->book_first_slot($optionb->id, $cmid, $student);

        // The cached (non-live) read must now reflect the booking — proving the cache was purged.
        $after = localentities::get_all_dates_for_entity($entityid);
        $this->assertNotEmpty(
            $after,
            'Booking must purge the entity occupancy cache so the new booking becomes visible.'
        );
    }

    /**
     * A booking on one entity's option must purge ONLY that entity's occupancy cache, not others.
     *
     * @covers \local_entities\entitiesrelation_handler::purge_dates_cache
     *
     * @return void
     */
    public function test_entity_dates_cache_purge_is_targeted(): void {
        [, , $entity1, $opt1, $cmid1, $stud1] =
            $this->create_entity_and_slot_option('Court one', 'courtone', 'Slot one');
        [, , $entity2, , , ] =
            $this->create_entity_and_slot_option('Court two', 'courttwo', 'Slot two');

        // Warm both entities' occupancy caches.
        localentities::get_all_dates_for_entity($entity1);
        localentities::get_all_dates_for_entity($entity2);

        $cache = \cache::make('local_entities', 'entitydates');
        $this->assertNotFalse($cache->get($entity1), 'Entity 1 cache should be warm.');
        $this->assertNotFalse($cache->get($entity2), 'Entity 2 cache should be warm.');

        // Booking on entity 1's option must purge entity 1 only.
        $this->book_first_slot($opt1->id, $cmid1, $stud1);

        // Re-make the cache handle so we read the shared store, not a stale static-accel copy.
        $cache = \cache::make('local_entities', 'entitydates');
        $this->assertFalse(
            $cache->get($entity1),
            'Entity 1 occupancy cache must be purged after booking on its option.'
        );
        $this->assertNotFalse(
            $cache->get($entity2),
            'Entity 2 occupancy cache must remain untouched (targeted purge, not wholesale).'
        );
    }
}

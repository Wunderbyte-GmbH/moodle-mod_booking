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
 * Tests for the global search areas of mod_booking.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use core_search\document_factory;
use core_search\manager;
use mod_booking\customfield\booking_handler;
use mod_booking\local\search\reindex;
use mod_booking\local\search\searchconfig;
use mod_booking\search\bookingoption;
use mod_booking\search\subbooking;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the global search areas of mod_booking.
 *
 * @covers \mod_booking\search\bookingoption
 * @covers \mod_booking\search\subbooking
 * @covers \mod_booking\local\search\optioncontent
 * @covers \mod_booking\local\search\searchconfig
 * @covers \mod_booking\local\search\reindex
 */
final class search_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('enableglobalsearch', 1);
        set_config('searchengine', 'simpledb');
        manager::clear_static();
        document_factory::clean_static();

        singleton_service::destroy_instance();
    }

    /**
     * Create a course, a booking instance and one booking option.
     *
     * @param array $optionrecord additional fields of the booking option
     * @return array [course, booking, option]
     */
    private function create_option(array $optionrecord = []): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Connected course fullname']);

        $bdata = [
            'name' => 'Booking instance for search',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $record = (object) array_merge([
            'bookingid' => $booking->id,
            'text' => 'Yoga for beginners',
            'titleprefix' => 'SPORT-01',
            'description' => '<p>A gentle introduction to yoga.</p>',
            'location' => 'Gym hall',
            'institution' => 'Sports institute',
            'address' => 'Mainstreet 1',
            'identifier' => 'YOGA-1',
            'annotation' => 'Internal note about the trainer contract',
            'dayofweektime' => 'Monday 10:00 - 11:00',
        ], $optionrecord);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$course, $booking, $option];
    }

    /**
     * Fetch the record of one booking option the way the search area does.
     *
     * @param int $optionid
     * @return stdClass
     */
    private function get_indexed_record(int $optionid): stdClass {
        $area = new bookingoption();
        $recordset = $area->get_document_recordset(0);

        $found = null;
        foreach ($recordset as $record) {
            if ((int) $record->id === $optionid) {
                $found = $record;
            }
        }
        $recordset->close();

        $this->assertNotNull($found, 'The booking option was not part of the indexed recordset.');

        return $found;
    }

    /**
     * The document of a booking option carries its core fields.
     */
    public function test_option_document_contains_core_fields(): void {
        [$course, $booking, $option] = $this->create_option();

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        $this->assertNotFalse($doc);
        $this->assertEquals('SPORT-01 Yoga for beginners', $doc->get('title'));
        $this->assertStringContainsString('gentle introduction to yoga', $doc->get('content'));
        $this->assertStringContainsString('Gym hall', $doc->get('description1'));
        $this->assertStringContainsString('Sports institute', $doc->get('description1'));
        $this->assertStringContainsString('Mainstreet 1', $doc->get('description1'));
        $this->assertStringContainsString('Monday 10:00 - 11:00', $doc->get('description1'));
        $this->assertStringContainsString('YOGA-1', $doc->get('description1'));
        $this->assertEquals($course->id, $doc->get('courseid'));
        $this->assertEquals(context_module::instance($booking->cmid)->id, $doc->get('contextid'));
        $this->assertEquals(manager::NO_OWNER_ID, $doc->get('owneruserid'));
    }

    /**
     * The internal annotation is only indexed after an explicit opt in.
     */
    public function test_annotation_is_indexed_only_on_opt_in(): void {
        [, , $option] = $this->create_option();

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));
        $this->assertStringNotContainsString('trainer contract', $doc->get('description1'));

        $sources = searchconfig::get_default_sources();
        $sources[] = searchconfig::SOURCE_ANNOTATION;
        set_config('searchindexsources', implode(',', $sources), 'booking');

        $doc = $area->get_document($this->get_indexed_record($option->id));
        $this->assertStringContainsString('trainer contract', $doc->get('description1'));
    }

    /**
     * A content source which is switched off does not reach the document.
     */
    public function test_switched_off_sources_do_not_reach_the_document(): void {
        [, , $option] = $this->create_option();

        $sources = array_diff(searchconfig::get_default_sources(), [
            searchconfig::SOURCE_DESCRIPTION,
            searchconfig::SOURCE_LOCATION,
        ]);
        set_config('searchindexsources', implode(',', $sources), 'booking');

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        $this->assertSame('', $doc->get('content'));
        $this->assertStringNotContainsString('Gym hall', $doc->get('description1'));
        // The identifier is still switched on.
        $this->assertStringContainsString('YOGA-1', $doc->get('description1'));
    }

    /**
     * Custom fields are indexed with their display value, not with the stored key.
     */
    public function test_customfields_are_indexed_with_display_values(): void {
        [, , $option] = $this->create_option();

        /** @var \core_customfield_generator $cfgenerator */
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'mod_booking', 'area' => 'booking']);

        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'sportstype',
            'name' => 'Sports type',
            'type' => 'select',
            'configdata' => ['options' => "Indoor\nOutdoor", 'visibility' => booking_handler::MOD_BOOKING_VISIBLETOALL],
        ]);
        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'internalnote',
            'name' => 'Internal note',
            'type' => 'text',
            'configdata' => ['visibility' => booking_handler::MOD_BOOKING_VISIBLETOTEACHERS],
        ]);

        $handler = booking_handler::create();
        $handler->field_save($option->id, 'sportstype', 2);
        $handler->field_save($option->id, 'internalnote', 'Only for teachers');

        booking_handler::reset_caches();
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_booking_option_singleton($option->id);

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        // The label of the selected option is indexed, not the stored key "2".
        $this->assertStringContainsString('Outdoor', $doc->get('description2'));
        // A field which is not visible to everybody never reaches the document.
        $this->assertStringNotContainsString('Only for teachers', $doc->get('description2'));
    }

    /**
     * Only the configured custom fields are indexed.
     */
    public function test_customfield_selection_is_honoured(): void {
        [, , $option] = $this->create_option();

        /** @var \core_customfield_generator $cfgenerator */
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'mod_booking', 'area' => 'booking']);

        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'indexedfield',
            'name' => 'Indexed field',
            'type' => 'text',
            'configdata' => ['visibility' => booking_handler::MOD_BOOKING_VISIBLETOALL],
        ]);
        $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'skippedfield',
            'name' => 'Skipped field',
            'type' => 'text',
            'configdata' => ['visibility' => booking_handler::MOD_BOOKING_VISIBLETOALL],
        ]);

        $handler = booking_handler::create();
        $handler->field_save($option->id, 'indexedfield', 'Findable value');
        $handler->field_save($option->id, 'skippedfield', 'Excluded value');

        set_config('searchindexcustomfields', 'indexedfield', 'booking');

        booking_handler::reset_caches();
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_booking_option_singleton($option->id);

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        $this->assertStringContainsString('Findable value', $doc->get('description2'));
        $this->assertStringNotContainsString('Excluded value', $doc->get('description2'));
    }

    /**
     * Teachers and the name of the connected course are part of the document.
     */
    public function test_related_data_is_indexed(): void {
        [$course, $booking, $option] = $this->create_option([
            'chooseorcreatecourse' => 1,
        ]);

        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Erika', 'lastname' => 'Musterfrau']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $teachershandler = new teachers_handler($option->id);
        $teachershandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $booking->cmid, null, false);

        global $DB;
        $DB->set_field('booking_options', 'courseid', $course->id, ['id' => $option->id]);

        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_booking_option_singleton($option->id);

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        $this->assertStringContainsString('Erika Musterfrau', $doc->get('description2'));
        $this->assertStringContainsString('Connected course fullname', $doc->get('description2'));
    }

    /**
     * Template options do not belong to an instance and are never indexed.
     */
    public function test_template_options_are_not_indexed(): void {
        [, , $option] = $this->create_option();

        global $DB;
        $DB->set_field('booking_options', 'bookingid', 0, ['id' => $option->id]);

        $area = new bookingoption();
        $recordset = $area->get_document_recordset(0);
        $ids = [];
        foreach ($recordset as $record) {
            $ids[] = (int) $record->id;
        }
        $recordset->close();

        $this->assertNotContains((int) $option->id, $ids);
    }

    /**
     * The policy for invisible booking options is honoured by the recordset.
     */
    public function test_invisible_options_can_be_kept_out_of_the_index(): void {
        [, , $option] = $this->create_option();

        global $DB;
        $DB->set_field('booking_options', 'invisible', 1, ['id' => $option->id]);

        $area = new bookingoption();

        // Default policy: the option is indexed and check_access restricts it.
        $recordset = $area->get_document_recordset(0);
        $ids = [];
        foreach ($recordset as $record) {
            $ids[] = (int) $record->id;
        }
        $recordset->close();
        $this->assertContains((int) $option->id, $ids);

        // Configured policy: invisible options never reach the index.
        set_config('searchinvisibleoptions', searchconfig::INVISIBLE_NEVER, 'booking');

        $recordset = $area->get_document_recordset(0);
        $ids = [];
        foreach ($recordset as $record) {
            $ids[] = (int) $record->id;
        }
        $recordset->close();
        $this->assertNotContains((int) $option->id, $ids);
    }

    /**
     * check_access mirrors the visibility of the option, not its bookability.
     */
    public function test_check_access_matrix(): void {
        global $DB;

        [$course, $booking, $option] = $this->create_option();

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $area = new bookingoption();

        $this->setUser($student);
        $this->assertEquals(manager::ACCESS_GRANTED, $area->check_access($option->id));

        // A booking option which does not exist any more is reported as deleted.
        $this->assertEquals(manager::ACCESS_DELETED, $area->check_access($option->id + 1000));

        // Invisible options stay hidden for users without the capability.
        $DB->set_field('booking_options', 'invisible', 1, ['id' => $option->id]);
        $area = new bookingoption();
        $this->assertEquals(manager::ACCESS_DENIED, $area->check_access($option->id));

        // With the capability the invisible option is visible again.
        $context = context_module::instance($booking->cmid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:canseeinvisibleoptions', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $student->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $area = new bookingoption();
        $this->assertEquals(manager::ACCESS_GRANTED, $area->check_access($option->id));

        // Guests never get results.
        $this->setGuestUser();
        $area = new bookingoption();
        $this->assertEquals(manager::ACCESS_DENIED, $area->check_access($option->id));
    }

    /**
     * The links of a document lead to the option and to its instance.
     */
    public function test_document_urls(): void {
        [, $booking, $option] = $this->create_option();

        $area = new bookingoption();
        $doc = $area->get_document($this->get_indexed_record($option->id));

        $this->assertStringContainsString('/mod/booking/optionview.php', $area->get_doc_url($doc)->out(false));
        $this->assertStringContainsString('optionid=' . $option->id, $area->get_doc_url($doc)->out(false));
        $this->assertStringContainsString('cmid=' . $booking->cmid, $area->get_doc_url($doc)->out(false));
        $this->assertStringContainsString('id=' . $booking->cmid, $area->get_context_url($doc)->out(false));
    }

    /**
     * Adding a teacher makes the booking option reach the indexer again.
     */
    public function test_teacher_change_triggers_reindexing(): void {
        global $DB;

        [$course, $booking, $option] = $this->create_option();

        $DB->set_field('booking_options', 'timemodified', 1000, ['id' => $option->id]);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $teachershandler = new teachers_handler($option->id);
        $teachershandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $booking->cmid, null, false);

        $timemodified = (int) $DB->get_field('booking_options', 'timemodified', ['id' => $option->id]);
        $this->assertGreaterThan(1000, $timemodified);
    }

    /**
     * Renaming the connected course makes its booking options reach the indexer again.
     */
    public function test_connected_course_rename_triggers_reindexing(): void {
        global $DB;

        [$course, , $option] = $this->create_option();

        $DB->set_field('booking_options', 'courseid', $course->id, ['id' => $option->id]);
        $DB->set_field('booking_options', 'timemodified', 1000, ['id' => $option->id]);

        $course->fullname = 'Renamed course';
        update_course($course);

        $timemodified = (int) $DB->get_field('booking_options', 'timemodified', ['id' => $option->id]);
        $this->assertGreaterThan(1000, $timemodified);
    }

    /**
     * Without global search the reindex helper does not write anything.
     */
    public function test_reindex_does_nothing_without_global_search(): void {
        global $DB;

        [, , $option] = $this->create_option();

        set_config('enableglobalsearch', 0);
        manager::clear_static();

        $DB->set_field('booking_options', 'timemodified', 1000, ['id' => $option->id]);
        reindex::mark_option_modified((int) $option->id);

        $this->assertEquals(1000, (int) $DB->get_field('booking_options', 'timemodified', ['id' => $option->id]));
    }

    /**
     * Subbookings are documents of their own and follow the visibility of their option.
     */
    public function test_subbooking_document_and_access(): void {
        global $DB;

        [$course, $booking, $option] = $this->create_option();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $subbooking = $plugingenerator->create_subbooking([
            'optionid' => $option->id,
            'name' => 'Airport transfer',
            'type' => 'subbooking_additionalitem',
            'block' => 0,
            'json' => '{"description":"Transfer"}',
        ]);

        $area = new subbooking();
        $recordset = $area->get_document_recordset(0);
        $found = null;
        foreach ($recordset as $record) {
            if ((int) $record->id === (int) $subbooking->id) {
                $found = $record;
            }
        }
        $recordset->close();

        $this->assertNotNull($found);

        $doc = $area->get_document($found);
        $this->assertEquals('Airport transfer', $doc->get('title'));
        $this->assertEquals($course->id, $doc->get('courseid'));
        $this->assertStringContainsString('optionid=' . $option->id, $area->get_doc_url($doc)->out(false));

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->assertEquals(manager::ACCESS_GRANTED, $area->check_access($subbooking->id));

        // The subbooking of an invisible option is hidden as well.
        $DB->set_field('booking_options', 'invisible', 1, ['id' => $option->id]);
        $area = new subbooking();
        $this->assertEquals(manager::ACCESS_DENIED, $area->check_access($subbooking->id));

        // A subbooking which does not exist any more is reported as deleted.
        $this->assertEquals(manager::ACCESS_DELETED, $area->check_access($subbooking->id + 1000));
    }
}

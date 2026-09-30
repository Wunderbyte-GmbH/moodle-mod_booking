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
 * Saving a booking option with the id of an entity that was deleted in local_entities.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\option_form;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * An option form that was opened before an entity got deleted (or an import, a web service call) still
 * submits the id of the deleted entity. The save must neither fail nor store a relation to an entity
 * that does not exist (Wunderbyte-GmbH/moodle-mod_booking#1620).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entities_deleted_entity_test extends booking_advanced_testcase {
    /** @var string Name of the entity that stays. */
    private const KEPTNAME = 'Kept entity';

    /** @var int Id of the entity that gets deleted. */
    private int $deletedid = 0;

    /** @var int Id of the entity that stays. */
    private int $keptid = 0;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        if (!class_exists('local_entities\entitiesrelation_handler')) {
            $this->markTestSkipped('local_entities is not installed.');
        }
    }

    /**
     * The save succeeds, no relation points to the deleted entity and the location matches the stored entity.
     *
     * @covers \mod_booking\option\fields\entities::prepare_save_field
     * @covers \mod_booking\option\fields\entities::save_data
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\form\option_form::process_dynamic_submission
     *
     * @param bool $optionentitydeleted the entity of the option is the deleted one, otherwise only the entity of the date
     * @param bool $viaform save with the option form, otherwise with booking_option::update() directly
     *
     * @dataProvider deleted_entity_provider
     */
    public function test_save_with_id_of_deleted_entity(bool $optionentitydeleted, bool $viaform): void {
        global $DB;

        $settings = $this->create_option_and_delete_entity($optionentitydeleted);
        $optionid = (int)$settings->id;
        $session = reset($settings->sessions);
        $optiondateid = (int)$session->id;
        $submittedoptionentity = $optionentitydeleted ? $this->deletedid : $this->keptid;

        // The stale values: the entity ids the option had before the entity was deleted.
        if ($viaform) {
            $this->submit_option_form($settings, $submittedoptionentity, $this->deletedid);
        } else {
            $record = new stdClass();
            $record->id = $optionid;
            $record->cmid = (int)$settings->cmid;
            $record->bookingid = (int)$settings->bookingid;
            $record->text = $settings->text;
            $record->identifier = $settings->identifier;
            $record->{LOCAL_ENTITIES_FORM_ENTITYID . 0} = $submittedoptionentity;
            $record->{MOD_BOOKING_FORM_OPTIONDATEID . 1} = $optiondateid;
            $record->{MOD_BOOKING_FORM_COURSESTARTTIME . 1} = (int)$session->coursestarttime;
            $record->{MOD_BOOKING_FORM_COURSEENDTIME . 1} = (int)$session->courseendtime;
            $record->{MOD_BOOKING_FORM_DAYSTONOTIFY . 1} = 0;
            $record->{LOCAL_ENTITIES_FORM_ENTITYID . 1} = $this->deletedid;
            booking_option::update($record);
        }

        $this->assertSame(
            0,
            $DB->count_records('local_entities_relations', ['entityid' => $this->deletedid]),
            'No relation may point to the deleted entity.'
        );

        $optionrelations = $DB->get_records(
            'local_entities_relations',
            ['component' => 'mod_booking', 'area' => 'option', 'instanceid' => $optionid]
        );
        $location = $DB->get_field('booking_options', 'location', ['id' => $optionid]);
        if ($optionentitydeleted) {
            $this->assertCount(0, $optionrelations, 'The option has no entity any more.');
            $this->assertSame('', (string)$location, 'Without entity the location is empty.');
        } else {
            $this->assertCount(1, $optionrelations, 'The existing entity of the option is kept.');
            $this->assertEquals($this->keptid, reset($optionrelations)->entityid);
            $this->assertSame(self::KEPTNAME, (string)$location);
        }

        // The date itself is kept and the option can still be loaded.
        $this->assertTrue($DB->record_exists('booking_optiondates', ['id' => $optiondateid, 'optionid' => $optionid]));
        singleton_service::destroy_instance();
        $reloaded = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertCount(1, $reloaded->sessions);
        $this->assertEquals($optionentitydeleted ? 0 : $this->keptid, $reloaded->entity['id'] ?? 0);
    }

    /**
     * Data provider: [entity of the option is the deleted one, save via option form].
     *
     * @return array
     */
    public static function deleted_entity_provider(): array {
        return [
            'form, deleted entity on option and date' => [true, true],
            'form, deleted entity on the date only' => [false, true],
            'update(), deleted entity on option and date' => [true, false],
            'update(), deleted entity on the date only' => [false, false],
        ];
    }

    /**
     * Create an option with one date, link option and date to entities and delete one entity afterwards.
     *
     * @param bool $optionentitydeleted link the option to the entity that gets deleted, otherwise to the kept one
     * @return booking_option_settings the settings as a new request sees them after the entity was deleted
     */
    private function create_option_and_delete_entity(bool $optionentitydeleted): booking_option_settings {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Deleted entity booking',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $manager->username,
        ]);
        $this->setAdminUser();

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $this->deletedid = (int)$entitygenerator->create_entities(
            ['name' => 'Deleted entity', 'shortname' => 'deletedentity', 'description' => 'Deleted entity']
        );
        $this->keptid = (int)$entitygenerator->create_entities(
            ['name' => self::KEPTNAME, 'shortname' => 'keptentity', 'description' => self::KEPTNAME]
        );

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with entity';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Description';
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 0} = $optionentitydeleted ? $this->deletedid : $this->keptid;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = make_timestamp(2050, 6, 20, 10, 0);
        $record->courseendtime_1 = make_timestamp(2050, 6, 20, 12, 0);
        $record->{LOCAL_ENTITIES_FORM_ENTITYAREA . 1} = 'optiondate';
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 1} = $this->deletedid;
        $option = $plugingenerator->create_option($record);

        (new \local_entities\settings_manager($this->deletedid))->delete();

        singleton_service::destroy_instance();
        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }

    /**
     * Save the option form like core_form\external\dynamic_form::execute() does.
     *
     * @param booking_option_settings $settings
     * @param int $entityid entity of the option
     * @param int $dateentityid entity of the stored date
     * @return void
     */
    private function submit_option_form(booking_option_settings $settings, int $entityid, int $dateentityid): void {
        $toarray = function (int $timestamp): array {
            $date = usergetdate($timestamp);
            return [
                'year' => $date['year'],
                'month' => $date['mon'],
                'day' => $date['mday'],
                'hour' => $date['hours'],
                'minute' => $date['minutes'],
            ];
        };
        $session = reset($settings->sessions);

        // The autocompletes do not export their stored values when they are not submitted, so they are
        // submitted like the browser does.
        $submitted = [
            'cmid' => (int)$settings->cmid,
            'id' => (int)$settings->id,
            'optionid' => (int)$settings->id,
            'bookingid' => (int)$settings->bookingid,
            'text' => $settings->text,
            'identifier' => $settings->identifier,
            'teachersforoption' => [],
            'institution' => '',
            LOCAL_ENTITIES_FORM_ENTITYID . 0 => $entityid,
            'datesmarker' => 0,
            'datescounter' => 1,
            MOD_BOOKING_FORM_OPTIONDATEID . '1' => (int)$session->id,
            MOD_BOOKING_FORM_COURSESTARTTIME . '1' => $toarray((int)$session->coursestarttime),
            MOD_BOOKING_FORM_COURSEENDTIME . '1' => $toarray((int)$session->courseendtime),
            MOD_BOOKING_FORM_DAYSTONOTIFY . '1' => 0,
            LOCAL_ENTITIES_FORM_ENTITYID . 1 => $dateentityid,
        ];

        $form = new option_form(null, null, 'post', '', [], true, option_form::mock_ajax_submit($submitted), true);
        $form->set_data_for_dynamic_submission();
        $this->assertTrue($form->is_validated(), 'The option form has to validate.');
        $form->process_dynamic_submission();
    }
}

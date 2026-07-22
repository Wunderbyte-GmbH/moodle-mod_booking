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
 * Saving options or instances without an active PRO license must not wipe stored PRO configuration.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\option\fields_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\utils\wb_payment;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * When the PRO license expires, the PRO-only form elements are no longer rendered, so a
 * form submit does not contain their keys. The save code must treat a missing key as
 * "field was not part of the form" and keep the stored value - only a submitted empty
 * value counts as "cleared by the user". These tests simulate both situations for every
 * affected save path.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pro_license_data_preservation_test extends booking_advanced_testcase {
    /**
     * Form keys which are only rendered with an active PRO license (option form).
     *
     * @var array
     */
    private const PROONLYOPTIONFORMKEYS = [
        // Shared places section.
        'sharedplaceswithoptions',
        'sharedplacespriority',
        // Booking actions section (hidden json transport element).
        'boactionsjson',
        'boactions',
        // Certificate section.
        'certificate',
        'taggedconditions',
        'expirydateabsolute',
        'expirydaterelative',
        'expirydatetype',
        'certificaterequiresotheroptions',
        'certificaterequiredoptionsmode',
    ];

    /**
     * Tests tear down.
     */
    protected function tearDown(): void {
        // The static override survives between tests, so always remove it.
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * A form save without PRO license (PRO keys missing entirely) keeps the stored
     * sharedplaces, boactions and certificate configuration in the option JSON.
     *
     * @covers \mod_booking\option\fields\sharedplaces::prepare_save_field
     * @covers \mod_booking\option\fields\actions::prepare_save_field
     * @covers \mod_booking\option\fields\certificate::prepare_save_field
     *
     * @return void
     */
    public function test_option_form_save_without_pro_keeps_pro_json_config(): void {
        global $DB;

        [$optiona, $optionb] = $this->create_two_options();
        $this->seed_pro_config($optiona, $optionb);

        // Precondition: the PRO configuration is stored in the option JSON.
        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optiona->id], MUST_EXIST));
        $this->assertEquals(["$optionb->id"], (array)$json->sharedplaceswithoptions, 'Precondition: sharedplaces stored.');
        $this->assertEquals(1, $json->sharedplacespriority, 'Precondition: sharedplaces priority stored.');
        $this->assertNotEmpty((array)$json->boactions, 'Precondition: boactions stored.');
        $this->assertEquals(42, $json->certificate, 'Precondition: certificate stored.');
        $this->assertEquals(1, $json->expirydatetype, 'Precondition: certificate expiry stored.');

        // Now the license expires: the PRO form elements are not rendered anymore,
        // so the submitted form data does not contain their keys at all.
        wb_payment::override_pro_version_for_tests(false);
        singleton_service::destroy_instance();

        $this->save_option_via_form_path(
            $optiona,
            self::PROONLYOPTIONFORMKEYS,
            ['text' => 'Renamed without PRO']
        );

        $record = $DB->get_record('booking_options', ['id' => $optiona->id], '*', MUST_EXIST);
        $this->assertSame('Renamed without PRO', $record->text, 'The save itself must have gone through.');

        $json = json_decode($record->json);
        $this->assertEquals(
            ["$optionb->id"],
            (array)($json->sharedplaceswithoptions ?? []),
            'sharedplaceswithoptions was wiped by a save without PRO license.'
        );
        $this->assertEquals(
            1,
            $json->sharedplacespriority ?? null,
            'sharedplacespriority was wiped by a save without PRO license.'
        );
        $this->assertNotEmpty(
            (array)($json->boactions ?? []),
            'boactions were wiped by a save without PRO license.'
        );
        $this->assertEquals(
            42,
            $json->certificate ?? null,
            'certificate was wiped by a save without PRO license.'
        );
        $this->assertEquals(
            1,
            $json->expirydatetype ?? null,
            'certificate expiry config was wiped by a save without PRO license.'
        );
    }

    /**
     * With an active PRO license the fields are part of the form again: submitting them
     * empty must still clear the stored values (the guard must not make values sticky).
     *
     * @covers \mod_booking\option\fields\sharedplaces::prepare_save_field
     * @covers \mod_booking\option\fields\actions::prepare_save_field
     * @covers \mod_booking\option\fields\certificate::prepare_save_field
     *
     * @return void
     */
    public function test_option_form_save_with_pro_can_still_clear_values(): void {
        global $DB;

        [$optiona, $optionb] = $this->create_two_options();
        $this->seed_pro_config($optiona, $optionb);

        // PRO is active (PHPUnit default): the form renders all elements, the user
        // clears them, so the keys are submitted with empty values.
        $this->save_option_via_form_path(
            $optiona,
            [],
            [
                'sharedplaceswithoptions' => [],
                'sharedplacespriority' => 0,
                'boactionsjson' => '[]',
                'certificate' => 0,
            ]
        );

        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optiona->id], MUST_EXIST));
        $this->assertObjectNotHasProperty(
            'sharedplaceswithoptions',
            $json,
            'An empty submitted value must still clear sharedplaceswithoptions.'
        );
        $this->assertObjectNotHasProperty(
            'sharedplacespriority',
            $json,
            'An empty submitted value must still clear sharedplacespriority.'
        );
        $this->assertEmpty(
            (array)($json->boactions ?? []),
            'An empty submitted boactionsjson must still clear the boactions.'
        );
        $this->assertObjectNotHasProperty(
            'certificate',
            $json,
            'An empty submitted value must still clear the certificate.'
        );
        $this->assertObjectNotHasProperty(
            'expirydatetype',
            $json,
            'Clearing the certificate must also clear its expiry config.'
        );
    }

    /**
     * With an active PRO license, changing existing values to different ones must
     * still store the new values (the guard must not freeze the old ones).
     *
     * @covers \mod_booking\option\fields\sharedplaces::prepare_save_field
     * @covers \mod_booking\option\fields\actions::prepare_save_field
     * @covers \mod_booking\option\fields\certificate::prepare_save_field
     *
     * @return void
     */
    public function test_option_form_save_with_pro_can_still_change_values(): void {
        global $DB;

        [$optiona, $optionb, $optionc] = $this->create_two_options(3);
        $this->seed_pro_config($optiona, $optionb);

        // PRO is active (PHPUnit default): the user changes the sharing target from
        // option B to option C, renames the action and picks another certificate.
        $changedactions = [
            '7' => [
                'id' => 7,
                'action_type' => 'cancelbooking',
                'boactionname' => 'Changed action',
            ],
        ];
        $this->save_option_via_form_path(
            $optiona,
            [],
            [
                'sharedplaceswithoptions' => ["$optionc->id"],
                'sharedplacespriority' => 1,
                'boactionsjson' => json_encode($changedactions),
                'certificate' => 43,
                'expirydatetype' => 2,
            ]
        );

        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optiona->id], MUST_EXIST));
        $this->assertEquals(
            ["$optionc->id"],
            (array)$json->sharedplaceswithoptions,
            'Changing the shared places target must store the new option.'
        );
        $this->assertEquals(1, $json->sharedplacespriority, 'The unchanged priority must survive a change-save.');
        $this->assertSame(
            'Changed action',
            $json->boactions->{'7'}->boactionname ?? null,
            'Changing a booking action must store the new values.'
        );
        $this->assertEquals(43, $json->certificate, 'Changing the certificate must store the new id.');
        $this->assertEquals(2, $json->expirydatetype, 'Changing the certificate expiry config must be stored.');
    }

    /**
     * Saving the instance settings without PRO license (elective checkbox and template
     * switcher are not part of the form) keeps iselective and the switchtemplates JSON.
     *
     * @covers ::booking_update_instance
     *
     * @return void
     */
    public function test_update_instance_without_pro_keeps_elective_and_switchtemplates(): void {
        global $DB;

        $booking = $this->create_booking_instance(['iselective' => 1]);
        $this->seed_switchtemplates($booking->id);

        // Precondition.
        $this->assertEquals(1, $DB->get_field('booking', 'iselective', ['id' => $booking->id]));
        $json = json_decode($DB->get_field('booking', 'json', ['id' => $booking->id], MUST_EXIST));
        $this->assertEquals(1, $json->switchtemplates, 'Precondition: template switcher on.');
        $this->assertNotEmpty($json->switchtemplatesselection, 'Precondition: switcher selection stored.');

        // License expired: the elective section and the switchtemplates checkbox are
        // not rendered, so the submitted data does not contain those keys.
        wb_payment::override_pro_version_for_tests(false);
        singleton_service::destroy_instance();

        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->name = 'Renamed without PRO';
        unset($record->iselective);
        // A DB record has no switchtemplates property, matching the missing checkbox.

        booking_update_instance($record);

        $saved = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $this->assertSame('Renamed without PRO', $saved->name, 'The save itself must have gone through.');
        $this->assertEquals(
            1,
            $saved->iselective,
            'iselective was switched off by a save without PRO license.'
        );
        $json = json_decode($saved->json);
        $this->assertEquals(
            1,
            $json->switchtemplates ?? null,
            'switchtemplates was switched off by a save without PRO license.'
        );
        $this->assertNotEmpty(
            $json->switchtemplatesselection ?? [],
            'switchtemplatesselection was wiped by a save without PRO license.'
        );
    }

    /**
     * With an active PRO license the checkboxes are rendered as advcheckboxes, so they
     * always submit 0 or 1: an explicit 0 must still turn the features off.
     *
     * @covers ::booking_update_instance
     *
     * @return void
     */
    public function test_update_instance_with_pro_can_still_turn_features_off(): void {
        global $DB;

        $booking = $this->create_booking_instance(['iselective' => 1]);
        $this->seed_switchtemplates($booking->id);
        singleton_service::destroy_instance();

        // PRO is active (PHPUnit default): iselective and switchtemplates are
        // advcheckboxes, unchecking them submits an explicit 0.
        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->iselective = 0;
        $record->switchtemplates = 0;

        booking_update_instance($record);

        $saved = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $this->assertEquals(0, $saved->iselective, 'A submitted iselective=0 must still turn the elective mode off.');
        $json = json_decode($saved->json);
        $this->assertEquals(0, $json->switchtemplates ?? null, 'switchtemplates=0 must still turn the switcher off.');
        $this->assertObjectNotHasProperty(
            'switchtemplatesselection',
            $json,
            'Turning the switcher off must still remove the stored selection.'
        );
    }

    /**
     * With an active PRO license, changing the instance settings must still store the
     * new values: enabling the elective mode and reducing the switcher selection.
     *
     * @covers ::booking_update_instance
     *
     * @return void
     */
    public function test_update_instance_with_pro_can_still_change_values(): void {
        global $DB;

        $booking = $this->create_booking_instance(['iselective' => 0]);
        $this->seed_switchtemplates($booking->id);

        // Precondition: the switcher stores all possible views by default.
        $json = json_decode($DB->get_field('booking', 'json', ['id' => $booking->id], MUST_EXIST));
        $this->assertGreaterThan(
            1,
            count((array)$json->switchtemplatesselection),
            'Precondition: more than one view stored.'
        );

        // PRO is active (PHPUnit default): the user enables the elective mode and
        // reduces the switcher selection to the cards view only.
        singleton_service::destroy_instance();
        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->iselective = 1;
        $record->switchtemplates = 1;
        $record->switchtemplatesselection = [MOD_BOOKING_VIEW_PARAM_CARDS];

        booking_update_instance($record);

        $saved = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $this->assertEquals(1, $saved->iselective, 'Enabling the elective mode must be stored.');
        $json = json_decode($saved->json);
        $this->assertEquals(1, $json->switchtemplates ?? null, 'The switcher must stay enabled.');
        $this->assertEquals(
            [MOD_BOOKING_VIEW_PARAM_CARDS],
            (array)$json->switchtemplatesselection,
            'Changing the switcher selection must store the new selection.'
        );
    }

    /**
     * Creates a course, a booking instance and two (or more) booking options.
     *
     * @param int $count number of options to create
     * @return array options as returned by the generator
     */
    private function create_two_options(int $count = 2): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = $this->booking_instance_data();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->description = 'Data preservation test';
        $record->useprice = 0;
        $record->maxanswers = 2;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $options = [];
        for ($i = 0; $i < $count; $i++) {
            $record->text = 'Option ' . chr(ord('A') + $i);
            $options[] = $plugingenerator->create_option($record);
        }

        return $options;
    }

    /**
     * Stores PRO configuration on option A through the real form save path (PRO active).
     *
     * @param stdClass $optiona
     * @param stdClass $optionb
     * @return void
     */
    private function seed_pro_config(stdClass $optiona, stdClass $optionb): void {
        $boactions = [
            '7' => [
                'id' => 7,
                'action_type' => 'cancelbooking',
                'boactionname' => 'Test action',
            ],
        ];

        $this->save_option_via_form_path(
            $optiona,
            [],
            [
                'sharedplaceswithoptions' => ["$optionb->id"],
                'sharedplacespriority' => 1,
                'boactionsjson' => json_encode($boactions),
                'certificate' => 42,
                'expirydatetype' => 1,
            ]
        );
    }

    /**
     * Simulates a real option form submit: prefill via set_data (like the form does),
     * remove the keys of elements which were not rendered, apply the submitted values
     * and save through the form path of booking_option::update (no importing flag).
     *
     * @param stdClass $option option as returned by the generator (id + cmid)
     * @param array $unsetkeys keys of form elements which were NOT rendered
     * @param array $submittedvalues values the form submit contains
     * @return void
     */
    private function save_option_via_form_path(stdClass $option, array $unsetkeys, array $submittedvalues): void {
        $formdata = new stdClass();
        $formdata->id = $option->id;
        $formdata->cmid = $option->cmid;

        fields_info::set_data($formdata);
        // The form path must not carry the importing flag.
        unset($formdata->importing);

        foreach ($unsetkeys as $key) {
            unset($formdata->{$key});
        }
        foreach ($submittedvalues as $key => $value) {
            $formdata->{$key} = $value;
        }

        booking_option::update($formdata);
        singleton_service::destroy_instance();
    }

    /**
     * Creates a booking instance.
     *
     * @param array $extra additional fields for the instance
     * @return stdClass module record as returned by create_module
     */
    private function create_booking_instance(array $extra = []): stdClass {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = $this->booking_instance_data();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        return $this->getDataGenerator()->create_module('booking', array_merge($bdata, $extra));
    }

    /**
     * Turns the template switcher on through booking_update_instance (PRO active).
     *
     * @param int $bookingid
     * @return void
     */
    private function seed_switchtemplates(int $bookingid): void {
        global $DB;

        singleton_service::destroy_instance();
        $record = $DB->get_record('booking', ['id' => $bookingid], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->switchtemplates = 1;
        booking_update_instance($record);
        singleton_service::destroy_instance();
    }

    /**
     * Minimal valid instance data for the booking module generator.
     *
     * @return array
     */
    private function booking_instance_data(): array {
        return [
            'name' => 'PRO data preservation',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
    }
}

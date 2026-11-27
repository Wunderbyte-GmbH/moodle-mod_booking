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
use context_module;
use mod_booking\external\addbookingoption;
use mod_booking\utils\wb_payment;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The web service to import booking options writes into the booking instance the request names.
 * The right to edit is checked for exactly this instance, whatever else the request names.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\external\addbookingoption
 * @covers \mod_booking\utils\webservice_import
 */
final class addbookingoption_access_test extends advanced_testcase {
    /** @var stdClass[] The booking instances of the test by name. */
    private array $bookings = [];

    /** @var stdClass The option of the own booking instance. */
    private stdClass $ownoption;

    /** @var stdClass A user who may edit the own booking instance only. */
    private stdClass $editor;

    /** @var string The error of the last refused call. */
    private string $lasterror = '';

    /**
     * Tests set up: two booking instances in two courses and an editor of one of them.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        wb_payment::override_pro_version_for_tests(true);
        $this->setAdminUser();

        $this->editor = $this->getDataGenerator()->create_user();
        foreach (['own', 'other'] as $name) {
            $course = $this->getDataGenerator()->create_course();
            $this->bookings[$name] = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
            $this->getDataGenerator()->enrol_user($this->editor->id, $course->id, 'student');
        }
        $context = context_module::instance($this->bookings['own']->cmid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $this->editor->id, $context->id);
        // The profile of the option form is a right of the whole site, it does not allow editing an instance.
        $formroleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:expertoptionform', CAP_ALLOW, $formroleid, \context_system::instance()->id, true);
        role_assign($formroleid, $this->editor->id, \context_system::instance()->id);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->ownoption = $plugingenerator->create_option((object)[
            'bookingid' => $this->bookings['own']->id,
            'text' => 'Own option',
            'maxanswers' => 5,
        ]);
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        wb_payment::override_pro_version_for_tests(null);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Number of booking options per booking instance.
     *
     * @return int[]
     */
    private function option_counts(): array {
        global $DB;

        return [
            'own' => $DB->count_records('booking_options', ['bookingid' => $this->bookings['own']->id]),
            'other' => $DB->count_records('booking_options', ['bookingid' => $this->bookings['other']->id]),
        ];
    }

    /**
     * Call the web service as the editor.
     *
     * @param array $arguments named arguments of the web service
     * @return bool whether the call was refused
     */
    private function import_is_refused(array $arguments): bool {
        $this->setUser($this->editor);
        singleton_service::destroy_instance();
        try {
            addbookingoption::execute(...$arguments);
        } catch (\moodle_exception $e) {
            $this->lasterror = get_class($e) . ': ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '');
            return true;
        }
        return false;
    }

    /**
     * The editor of one instance cannot write into another instance by naming an own option.
     */
    public function test_own_option_does_not_authorise_another_instance(): void {
        $refused = $this->import_is_refused([
            'name' => 'Injected option',
            'identifier' => 'injected1',
            'bookingcmid' => (int)$this->bookings['other']->cmid,
            'bookingoptionid' => (int)$this->ownoption->id,
        ]);

        $this->assertSame(['own' => 1, 'other' => 0], $this->option_counts(), $this->lasterror);
        $this->assertTrue($refused);
    }

    /**
     * The editor still imports into the own instance.
     */
    public function test_import_into_the_own_instance_works(): void {
        $refused = $this->import_is_refused([
            'name' => 'Imported option',
            'identifier' => 'imported1',
            'bookingcmid' => (int)$this->bookings['own']->cmid,
        ]);

        $this->assertFalse($refused, $this->lasterror);
        $this->assertSame(['own' => 2, 'other' => 0], $this->option_counts());
    }
}

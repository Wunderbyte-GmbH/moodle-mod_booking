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

namespace bookingextension_confirmation_supervisor;

use advanced_testcase;
use mod_booking\output\booked_users;
use mod_booking_generator;

/**
 * Tests for the parameter futureonly of the shortcode supervisorteam.
 *
 * @package    bookingextension_confirmation_supervisor
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class supervisorteam_futureonly_test extends advanced_testcase {
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
     * With futureonly, the team tables only list answers of booking options which have not ended yet.
     * Booking options without dates are still listed.
     *
     * @covers \mod_booking\booking_answers\scopes\supervisorteam
     * @covers \mod_booking\booking_answers\scopes\supervisorteamreduced
     * @covers \mod_booking\output\booked_users
     *
     * @dataProvider scope_provider
     *
     * @param string $scope
     */
    public function test_futureonly(string $scope): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // The supervisor of a user is stored in a custom profile field.
        $categoryid = $DB->insert_record('user_info_category', (object)['name' => 'Test Category', 'sortorder' => 1]);
        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'supervisor',
            'name' => 'Supervisor',
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => $categoryid,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 1,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
        ]);
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');

        $course = $this->getDataGenerator()->create_course();
        $supervisor = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($supervisor->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        profile_save_data((object)['id' => $student->id, 'profile_field_supervisor' => $supervisor->id]);

        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $now = time();
        $endtimes = [
            'Ended option' => $now - DAYSECS,
            'Running option' => $now + DAYSECS,
            'Future option' => $now + 3 * DAYSECS,
            'Option without dates' => 0,
        ];
        foreach ($endtimes as $text => $endtime) {
            $option = $generator->create_option((object)[
                'bookingid' => $booking->id,
                'text' => $text,
                'courseid' => $course->id,
                'chooseorcreatecourse' => 1,
            ]);
            $generator->create_answer(['optionid' => $option->id, 'userid' => $student->id]);
            // We set the dates after booking, as ended booking options can't be booked anymore.
            $starttime = $text === 'Future option' ? $now + 2 * DAYSECS : $now - 2 * DAYSECS;
            $DB->set_field('booking_options', 'coursestarttime', empty($endtime) ? 0 : $starttime, ['id' => $option->id]);
            $DB->set_field('booking_options', 'courseendtime', $endtime, ['id' => $option->id]);
        }

        $this->setUser($supervisor);

        // Without the parameter, all the answers of the team are listed.
        $this->assertEqualsCanonicalizing(
            array_keys($endtimes),
            $this->get_option_names($scope, false)
        );

        // With the parameter, the ended booking option is gone.
        $this->assertEqualsCanonicalizing(
            ['Running option', 'Future option', 'Option without dates'],
            $this->get_option_names($scope, true)
        );
    }

    /**
     * Data provider for test_futureonly.
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'supervisorteam' => ['supervisorteam'],
            'supervisorteamreduced' => ['supervisorteamreduced'],
        ];
    }

    /**
     * Returns the names of the booking options listed in the table of booked users.
     *
     * @param string $scope
     * @param bool $futureonly
     * @return array
     */
    private function get_option_names(string $scope, bool $futureonly): array {
        $bookedusers = new booked_users(
            $scope,
            0,
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
            false,
            100,
            false,
            $futureonly
        );
        $table = $bookedusers->return_raw_table($scope, 0, MOD_BOOKING_STATUSPARAM_BOOKED);
        return array_values(array_map(fn($record) => $record->text, $table->rawdata));
    }
}

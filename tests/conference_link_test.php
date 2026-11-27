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
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The link to a conference is stored per option and session. A user who may join the session of
 * one option gets the links of this session only, whatever field id the request names.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::get_conference_link
 */
final class conference_link_test extends advanced_testcase {
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
     * The link is only found among the fields of the option and the session.
     */
    public function test_link_is_resolved_within_the_option_and_session(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $options = [];
        $fieldids = [];
        foreach (['own', 'other'] as $name) {
            $options[$name] = $plugingenerator->create_option((object)[
                'bookingid' => $booking->id,
                'text' => "Option $name",
                'courseid' => $course->id,
            ]);
            // Two sessions per option, each with its own link.
            foreach ([1, 2] as $sessionid) {
                $fieldids[$name][$sessionid] = (int)$DB->insert_record('booking_customfields', (object)[
                    'bookingid' => (int)$booking->id,
                    'optionid' => (int)$options[$name]->id,
                    'optiondateid' => $sessionid,
                    'cfgname' => 'TeamsMeeting',
                    'value' => "https://example.com/$name/$sessionid",
                ]);
            }
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($options['own']->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // The link of the session, by the id of its field and by the name of the field.
        $this->assertSame('https://example.com/own/1', $option->get_conference_link(1, $fieldids['own'][1]));
        $this->assertSame('https://example.com/own/2', $option->get_conference_link(2, 0, 'TeamsMeeting'));

        // The field of another option or of another session of the same option is not delivered.
        $this->assertSame('', $option->get_conference_link(1, $fieldids['other'][1]));
        $this->assertSame('', $option->get_conference_link(1, $fieldids['own'][2]));
        $this->assertSame('', $option->get_conference_link(1, 0, 'OtherMeeting'));
    }
}

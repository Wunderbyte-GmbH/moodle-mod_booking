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
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * The custom fields of option dates are entered by everybody who may edit a booking option and
 * are shown to everybody who sees the option. Their values may contain formatting, but nothing
 * that is executed in the browser of the reader.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::render_customfield_data
 * @covers ::booking_get_rendered_customfields
 */
final class optiondate_customfields_output_test extends advanced_testcase {
    /** @var booking_option The booking option of the test. */
    private booking_option $option;

    /** @var int Id of the option date of the option. */
    private int $optiondateid;

    /** @var stdClass A user who booked the option. */
    private stdClass $bookeduser;

    /**
     * Tests set up: an option that takes place right now, with a user who booked it.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with custom fields',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'optiondateid_1' => '0',
            'daystonotify_1' => '0',
            'coursestarttime_1' => time() - 5 * MINSECS,
            'courseendtime_1' => time() + HOURSECS,
        ]);
        $this->optiondateid = (int)$DB->get_field('booking_optiondates', 'id', ['optionid' => $record->id], MUST_EXIST);

        $this->bookeduser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->bookeduser->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object)[
            'bookingid' => (int)$booking->id,
            'optionid' => (int)$record->id,
            'userid' => (int)$this->bookeduser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        booking_option::purge_cache_for_answers((int)$record->id);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($record->id);
        $this->option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
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
     * A custom field of the option date.
     *
     * @param string $name
     * @param string $value
     * @return stdClass
     */
    private function field(string $name, string $value): stdClass {
        return (object)[
            'id' => 1,
            'optionid' => $this->option->optionid,
            'optiondateid' => $this->optiondateid,
            'cfgname' => $name,
            'value' => $value,
        ];
    }

    /**
     * What a browser would execute in a piece of html, and which elements carry formatting.
     *
     * @param string $html
     * @return array with the keys executable (description of everything executable) and elements (tag names)
     */
    private function inspect(string $html): array {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>');
        $xpath = new \DOMXPath($dom);

        $executable = [];
        foreach ($xpath->query('//script | //iframe | //object | //embed') as $node) {
            $executable[] = $node->nodeName;
        }
        $elements = [];
        foreach ($xpath->query('//*[@id="root"]//*') as $node) {
            $elements[] = $node->nodeName;
            foreach ($node->attributes as $attribute) {
                $isurl = in_array($attribute->name, ['href', 'src'], true);
                $scheme = strtolower((string)parse_url(trim($attribute->value), PHP_URL_SCHEME));
                if (stripos($attribute->name, 'on') === 0 || ($isurl && !in_array($scheme, ['', 'http', 'https'], true))) {
                    $executable[] = $node->nodeName . '@' . $attribute->name;
                }
            }
        }
        return ['executable' => $executable, 'elements' => $elements];
    }

    /**
     * The value of a field keeps its formatting and loses everything executable.
     */
    public function test_value_of_a_field_is_cleaned(): void {
        $value = '<b>Room</b> 5<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">map</a>';

        $rendered = $this->option->render_customfield_data($this->field('Location', $value), $this->optiondateid);
        $inspected = $this->inspect($rendered['value']);

        $this->assertSame([], $inspected['executable']);
        $this->assertContains('b', $inspected['elements']);
    }

    /**
     * The same holds for the html of the fields of an option date.
     */
    public function test_rendered_fields_of_an_option_date_are_cleaned(): void {
        global $DB;

        $DB->insert_record('booking_customfields', (object)[
            'bookingid' => $this->option->bookingid,
            'optionid' => $this->option->optionid,
            'optiondateid' => $this->optiondateid,
            'cfgname' => 'Loc<img src=x onerror=alert(1)>ation',
            'value' => '<i>Room</i><script>alert(2)</script>',
        ]);

        $inspected = $this->inspect(booking_get_rendered_customfields($this->optiondateid));

        $this->assertSame([], $inspected['executable']);
        $this->assertNotContains('img', $inspected['elements']);
    }

    /**
     * The button to a meeting is a link to the stored address and nothing else.
     */
    public function test_meeting_button_cannot_carry_attributes_or_markup(): void {
        $this->setUser($this->bookeduser);

        // A valid address gives a link: the precondition for the cases below.
        $rendered = $this->option->render_customfield_data(
            $this->field('TeamsMeeting', 'https://meeting.example/room'),
            $this->optiondateid,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            true
        );
        $this->assertSame(['a'], $this->inspect($rendered['value'])['elements'], 'Rendered: ' . $rendered['value']);

        // A value that tries to add an attribute to the link.
        $rendered = $this->option->render_customfield_data(
            $this->field('TeamsMeeting', 'https://meeting.example/room onmouseover=alert(1)'),
            $this->optiondateid,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            true
        );
        $this->assertSame([], $this->inspect($rendered['value'])['executable']);

        // A value that is no web address.
        $rendered = $this->option->render_customfield_data(
            $this->field('TeamsMeeting', 'javascript:alert(1)'),
            $this->optiondateid,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            true
        );
        $this->assertSame([], $this->inspect($rendered['value'])['executable']);

        // A field name that carries markup, in the button for the calendar.
        $rendered = $this->option->render_customfield_data(
            $this->field('teams<img src=x onerror=alert(1)>meeting', 'https://meeting.example/room'),
            $this->optiondateid,
            MOD_BOOKING_DESCRIPTION_CALENDAR,
            true
        );
        $inspected = $this->inspect($rendered['value']);
        $this->assertSame([], $inspected['executable']);
        $this->assertSame(['a'], $inspected['elements']);
    }
}

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
 * Tests that user input which used to be read from superglobals is properly cleaned.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\option\dates_handler;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * Tests that user input which used to be read from superglobals is properly cleaned.
 *
 * All of these code paths formerly accessed $_GET or $_POST directly. They now go
 * through optional_param(), data_submitted() and clean_param(), so malicious input
 * must never reach the database or the output unfiltered.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class superglobal_input_cleaning_test extends booking_advanced_testcase {
    /** @var array */
    private $originalget = [];

    /** @var array */
    private $originalpost = [];

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->originalget = $_GET;
        $this->originalpost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        $_GET = $this->originalget;
        $_POST = $this->originalpost;
        parent::tearDown();
    }

    /**
     * The nested user[][<userid>] checkbox structure of report.php is cleaned to int ids.
     *
     * @covers ::booking_get_selected_userids
     */
    public function test_booking_get_selected_userids_returns_cleaned_ids(): void {
        // The structure as PHP creates it from user[][17], user[][23] etc.
        $submitteddata = (object) [
            'user' => [
                ['17' => '17'],
                ['23' => '23'],
            ],
        ];
        $this->assertSame([17, 23], booking_get_selected_userids($submitteddata));
    }

    /**
     * Crafted user ids must be reduced to harmless integers or dropped completely.
     *
     * @covers ::booking_get_selected_userids
     */
    public function test_booking_get_selected_userids_neutralizes_malicious_input(): void {
        $submitteddata = (object) [
            'user' => [
                // An SQL injection attempt is cut down to its leading integer.
                ['42); DELETE FROM {user};--' => 'on'],
                // An XSS attempt has no leading integer and is dropped.
                ['<script>alert(1)</script>' => 'on'],
                // Negative and zero ids are no valid user ids and are dropped.
                ['-5' => 'on'],
                ['0' => 'on'],
                // Empty checkbox entries are skipped.
                [],
            ],
        ];
        $this->assertSame([42], booking_get_selected_userids($submitteddata));
    }

    /**
     * Unexpected structures (no form submitted, scalar instead of array) yield no users.
     *
     * @covers ::booking_get_selected_userids
     */
    public function test_booking_get_selected_userids_handles_unexpected_structures(): void {
        // No form was submitted at all: data_submitted() returns false.
        $this->assertSame([], booking_get_selected_userids(false));
        // Form without user checkboxes.
        $this->assertSame([], booking_get_selected_userids(new stdClass()));
        // A crafted scalar instead of the checkbox array.
        $this->assertSame([], booking_get_selected_userids((object) ['user' => 'notanarray']));
    }

    /**
     * The dynamically named optiondate values from the form are cleaned before use.
     *
     * @covers \mod_booking\option\dates_handler::add_values_from_post_to_form
     */
    public function test_add_values_from_post_to_form_cleans_submitted_values(): void {
        $_POST = [
            'coursetime-newdate-0' => '1633942800-1633946400',
            'coursetime-customdate-0' => '<script>alert(1)</script>1633950000-1633953600',
            'coursetime-dateid-17' => '<b>1633960000-1633963600</b>',
            'semesterid' => '7<script>alert(1)</script>',
            'dayofweektime' => 'Mo, 10:00 - 11:00<script>alert(1)</script>',
            'unrelatedkey' => 'must be ignored',
            // A crafted array instead of the expected scalar value must be skipped.
            'coursetime-newdate-1' => ['crafted' => 'array'],
        ];

        $fromform = new stdClass();
        dates_handler::add_values_from_post_to_form($fromform);

        $this->assertSame(
            ['1633942800-1633946400', 'alert(1)1633950000-1633953600'],
            $fromform->newoptiondates
        );
        $this->assertSame([17 => '1633960000-1633963600'], $fromform->stillexistingdates);
        // The semesterid is reduced to its integer part.
        $this->assertSame(7, $fromform->semesterid);
        // Tags are stripped from the dayofweektime string.
        $this->assertSame('Mo, 10:00 - 11:00alert(1)', $fromform->dayofweektime);
        $this->assertObjectNotHasProperty('unrelatedkey', $fromform);
    }

    /**
     * Without any submitted data the form object gets empty date arrays and nothing else.
     *
     * @covers \mod_booking\option\dates_handler::add_values_from_post_to_form
     */
    public function test_add_values_from_post_to_form_without_submitted_data(): void {
        $fromform = new stdClass();
        dates_handler::add_values_from_post_to_form($fromform);

        $this->assertSame([], $fromform->newoptiondates);
        $this->assertSame([], $fromform->stillexistingdates);
        $this->assertObjectNotHasProperty('semesterid', $fromform);
        $this->assertObjectNotHasProperty('dayofweektime', $fromform);
    }

    /**
     * The JSON encoded list of selected electives is cleaned to int option ids.
     *
     * @covers \mod_booking\elective::return_credits_selected
     */
    public function test_return_credits_selected_sums_credits_of_valid_list(): void {
        global $DB;

        $optionid1 = $DB->insert_record('booking_options', (object) [
            'bookingid' => 0,
            'text' => 'Elective one',
            'description' => '',
            'credits' => 3,
        ]);
        $optionid2 = $DB->insert_record('booking_options', (object) [
            'bookingid' => 0,
            'text' => 'Elective two',
            'description' => '',
            'credits' => 2,
        ]);

        $_GET['list'] = json_encode([$optionid1, $optionid2]);
        $this->assertEquals(5, elective::return_credits_selected(null));
    }

    /**
     * Malicious or malformed list values must not reach the database unfiltered.
     *
     * @covers \mod_booking\elective::return_credits_selected
     */
    public function test_return_credits_selected_neutralizes_malicious_list(): void {
        global $DB;

        $optionid = $DB->insert_record('booking_options', (object) [
            'bookingid' => 0,
            'text' => 'Elective one',
            'description' => '',
            'credits' => 3,
        ]);

        // An injection attempt inside the JSON array is cut down to the leading int id.
        $_GET['list'] = json_encode([$optionid . ' OR 1=1']);
        $this->assertEquals(3, elective::return_credits_selected(null));

        // Invalid JSON falls back to an empty selection.
        $_GET['list'] = 'no-json-at-all';
        $this->assertEquals(0, elective::return_credits_selected(null));

        // A JSON object instead of an array is treated as an empty selection.
        $_GET['list'] = '{"evil":"payload"}';
        $this->assertEquals(0, elective::return_credits_selected(null));

        // Without the parameter there are no selected electives.
        unset($_GET['list']);
        $this->assertEquals(0, elective::return_credits_selected(null));

        // Empty entries in the list are skipped.
        $_GET['list'] = '[0,null,""]';
        $this->assertEquals(0, elective::return_credits_selected(null));
    }
}

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

use context_module;
use stdClass;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;
use mod_booking\local\wizard\options\skills\get_option_details_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Result-payload fixes for the read-only option skills.
 *
 * - diagnose_user_booking: previewoptionids must not repeat an optionid when the user has
 *   several answer rows (active + previous/cancelled cycles) on the same option.
 * - get_option_details: maxanswers, location and visibility are supported standard fields so
 *   freshly written values can be read back.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @covers     \mod_booking\local\wizard\options\skills\get_option_details_skill
 */
final class wizard_option_skill_result_fields_test extends booking_advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Instance-wide mode: a user with an active and a cancelled answer row on the same option
     * gets that optionid only ONCE in previewoptionids (first occurrence, order preserved).
     *
     * @return void
     */
    public function test_diagnose_user_booking_previewoptionids_deduplicated(): void {
        global $DB;

        $env = $this->setup_booking('Dedup booking');
        $optiona = $this->create_option($env, 'Dedup option A');
        $optionb = $this->create_option($env, 'Dedup option B');
        $student = $env['student'];

        // Active answer on A and B, plus an older cancelled cycle on A → duplicate optionid rows.
        $this->book_user((int)$optiona->id, (int)$student->id);
        $this->book_user((int)$optionb->id, (int)$student->id);
        $DB->insert_record('booking_answers', (object)[
            'bookingid' => (int)$env['booking']->id,
            'userid' => (int)$student->id,
            'optionid' => (int)$optiona->id,
            'timemodified' => time(),
            'timecreated' => time() - DAYSECS,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_DELETED,
            'completed' => 0,
        ]);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete((int)$optiona->id);
        singleton_service::destroy_instance();

        $this->setAdminUser();
        $result = (new diagnose_user_booking_skill())->execute(
            ['userid' => (int)$student->id, 'includemessages' => false],
            (int)$env['modulecontext']->id,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $result['status']);
        $previewids = (array)($result['previewoptionids'] ?? []);
        $this->assertSame(
            array_values(array_unique($previewids)),
            $previewids,
            'previewoptionids must not contain duplicated optionids.'
        );
        $this->assertCount(2, $previewids);
        $this->assertContains((int)$optiona->id, $previewids);
        $this->assertContains((int)$optionb->id, $previewids);
    }

    /**
     * get_option_details supports maxanswers, location and visibility as standard fields and
     * returns the persisted values (raw invisible int plus a human-readable label).
     *
     * @return void
     */
    public function test_get_option_details_returns_maxanswers_location_visibility(): void {
        global $DB;

        $env = $this->setup_booking('Details booking');
        $option = $this->create_option($env, 'Details option');

        // Freshly written values that previously could not be read back.
        $DB->set_field('booking_options', 'location', 'Vienna HQ', ['id' => (int)$option->id]);
        $DB->set_field('booking_options', 'invisible', 1, ['id' => (int)$option->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete((int)$option->id);
        singleton_service::destroy_instance();

        $this->setAdminUser();
        $result = (new get_option_details_skill())->execute(
            [
                'optionid' => (int)$option->id,
                'requested_fields' => ['maxanswers', 'location', 'visibility'],
                'includesessions' => false,
            ],
            (int)$env['modulecontext']->id,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $result['status']);
        $supported = (array)($result['detail_capabilities']['supported_standard_fields'] ?? []);
        $this->assertContains('maxanswers', $supported);
        $this->assertContains('location', $supported);
        $this->assertContains('visibility', $supported);

        $fields = (array)($result['optiondetails'][0]['standard_fields'] ?? []);
        $this->assertSame(4, $fields['maxanswers']);
        $this->assertSame('Vienna HQ', $fields['location']);
        $this->assertSame(1, $fields['visibility']['invisible']);
        $this->assertSame(get_string('optioninvisible', 'booking'), $fields['visibility']['label']);
    }

    /**
     * Build a course + booking instance with an enrolled student.
     *
     * @param string $bookingname Name of the booking activity.
     * @return array Keys: course, booking, modulecontext, student.
     */
    private function setup_booking(string $bookingname): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => $bookingname,
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        return [
            'course' => $course,
            'booking' => $booking,
            'modulecontext' => context_module::instance($booking->cmid),
            'student' => $student,
        ];
    }

    /**
     * Create a booking option with maxanswers 4 in the given environment.
     *
     * @param array $env The setup_booking() environment.
     * @param string $title The option title.
     * @return stdClass The created option record.
     */
    private function create_option(array $env, string $title): stdClass {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = (int)$env['booking']->id;
        $record->text = $title;
        $record->chooseorcreatecourse = 1;
        $record->courseid = (int)$env['course']->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        return $plugingenerator->create_option($record);
    }

    /**
     * Book a user into an option via the plugin generator and refresh caches.
     *
     * @param int $optionid The booking option id.
     * @param int $userid The user to book.
     * @return void
     */
    private function book_user(int $optionid, int $userid): void {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_answer(['optionid' => $optionid, 'userid' => $userid]);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
    }
}

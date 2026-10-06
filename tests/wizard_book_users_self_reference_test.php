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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\book_users_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Self-reference bookings must resolve to the current user (baseline finding F27).
 *
 * "Trag mich beim Naehcafe ein." made book_users ask for a name or e-mail although the
 * engine knows exactly who is asking: the user selector was structurally required, so an
 * omitted selector was rejected instead of defaulting to the acting user — the pattern
 * the diagnose skills already implement ("omit userquery for the current user").
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\book_users_skill
 */
final class wizard_book_users_self_reference_test extends booking_advanced_testcase {
    /**
     * The skill classes import the engine through component-local aliases; register them
     * before loading any skill outside the engine (developer-guides/writing-a-skill.md).
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
    }

    /**
     * An omitted user selector is valid input: it means "book the current user".
     */
    public function test_check_structure_allows_omitted_user_selector(): void {
        $this->resetAfterTest();
        $skill = new book_users_skill();

        $result = $skill->check_structure(['optionquery' => 'Selfbook Option']);

        $this->assertTrue((bool)($result['valid'] ?? false), implode(' | ', (array)($result['errors'] ?? [])));
    }

    /**
     * Regression guard (baseline finding F27): preflight without a user selector resolves
     * the ACTING user structurally (no name question), and execute books that user.
     */
    public function test_omitted_user_selector_books_the_current_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Selfbook Test Booking',
            'eventtype' => 'Webinar',
            'bookingmanager' => 'admin',
        ]);
        $contextid = (int)context_module::instance((int)$booking->cmid)->id;

        // The book_users skill is privileged-actor only (requires mod/booking:bookforothers);
        // the F27 baseline actor was a manager saying "book ME in" — mirror that with an
        // editingteacher booking themselves.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => (int)$booking->id,
            'text' => 'Selfbook Option',
            'maxanswers' => 5,
            'type' => 0,
        ]);

        $this->setUser($teacher);
        $skill = new book_users_skill();

        // Preflight: the omitted selector must not ask for a name — it resolves the actor.
        $dto = $skill->preflight(['optionid' => (int)$option->id], $contextid, (int)$teacher->id);
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $this->assertContains(
            (int)$teacher->id,
            array_map('intval', (array)($dto->preparedinput['resolvedbookuserids'] ?? [])),
            'the acting user must be the resolved booking target when the selector is omitted'
        );

        // Execute with the prepared input, as the executor would.
        $result = $skill->execute((array)$dto->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''), (string)($result['detail'] ?? ''));

        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => (int)$option->id,
                'userid' => (int)$teacher->id,
                'waitinglist' => 0,
            ]),
            'the current user must end up booked into the option'
        );
    }
}

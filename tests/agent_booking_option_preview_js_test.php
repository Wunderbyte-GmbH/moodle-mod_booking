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
use mod_booking\local\wizard\booking_option_preview_renderer;
use mod_booking\local\wizard\options\skills\get_option_details_skill;

/**
 * The booking option preview must ship its render-time JS, not just markup.
 *
 * The preview pane injects server-rendered option cards into a page that is already loaded. The
 * booking templates bind their button behaviour in Mustache {{#js}} blocks, which Moodle routes to
 * $PAGE->requires instead of the returned markup — and inside the agent's webservice request that
 * never reaches the browser. The card then carries a booking button that no handler listens to.
 * The preview data contract therefore carries a separate 'js' string next to 'html', which the
 * client executes after injection.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\booking_option_preview_renderer
 * @covers \mod_booking\local\wizard\options\skills\booking_skill_base::get_result_preview
 */
final class agent_booking_option_preview_js_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        singleton_service::destroy_instance();
    }

    /**
     * The rendered card carries a booking button, and the descriptor carries the JS that binds it.
     */
    public function test_result_preview_ships_the_js_that_binds_the_booking_button(): void {
        $env = $this->setup_bookable_option();

        $preview = (new get_option_details_skill())->get_result_preview(
            ['previewoptionids' => [(int)$env['option']->id]],
            (int)$env['context']->id,
            (int)$env['student']->id
        );

        $this->assertIsArray($preview, 'An executed option result must produce a preview descriptor.');
        $this->assertSame('booking_option', $preview['type']);
        $this->assertStringContainsString(
            'booking-button-area',
            $preview['html'],
            'The preview card is expected to contain the booking button area.'
        );

        // The actual defect: without the collected render-time JS the button above has no handler.
        $this->assertArrayHasKey('js', $preview, 'The descriptor must carry render-time JS next to the HTML.');
        $this->assertNotEmpty($preview['js'], 'The render-time JS of the booking templates must not be dropped.');
        $this->assertStringContainsString(
            'mod_booking/bookit',
            $preview['js'],
            'The preview must ship the AMD module that binds the booking button.'
        );
        $this->assertStringContainsString(
            'initbookitbutton',
            $preview['js'],
            'The preview must ship the booking button initialisation, not just the module name.'
        );
    }

    /**
     * The renderer keeps its own contract: html plus js, both strings, js empty when nothing rendered.
     */
    public function test_renderer_returns_html_and_js_as_separate_strings(): void {
        $env = $this->setup_bookable_option();

        $rendered = (new booking_option_preview_renderer())->render(
            ['optionids' => [(int)$env['option']->id]],
            (int)$env['context']->id,
            (int)$env['student']->id
        );

        $this->assertIsArray($rendered, 'The renderer hands back html and js separately.');
        $this->assertArrayHasKey('html', $rendered);
        $this->assertArrayHasKey('js', $rendered);
        $this->assertIsString($rendered['html']);
        $this->assertIsString($rendered['js']);
        $this->assertNotEmpty($rendered['html']);
    }

    /**
     * Non-success path: an id that resolves to nothing yields no preview at all, and no JS either.
     */
    public function test_unknown_option_yields_no_preview(): void {
        $env = $this->setup_bookable_option();

        $rendered = (new booking_option_preview_renderer())->render(
            ['optionids' => [(int)$env['option']->id + 100000]],
            (int)$env['context']->id,
            (int)$env['student']->id
        );

        $this->assertSame('', $rendered['html'], 'An unresolvable option must not render a card.');
        $this->assertSame('', $rendered['js'], 'Without a card there is nothing to bind, so no JS is shipped.');

        $preview = (new get_option_details_skill())->get_result_preview(
            ['previewoptionids' => []],
            (int)$env['context']->id,
            (int)$env['student']->id
        );
        $this->assertNull($preview, 'A result without option ids produces no preview descriptor.');
    }

    /**
     * One booking instance with a future, bookable option and an enrolled student looking at it.
     *
     * @return array{course:\stdClass,context:\context_module,option:\stdClass,student:\stdClass}
     */
    private function setup_bookable_option(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $gen = $this->getDataGenerator();
        /** @var \mod_booking_generator $bgen */
        $bgen = $gen->get_plugin_generator('mod_booking');

        $course = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $course->id]);

        $option = $bgen->create_option([
            'bookingid' => (int)$booking->id,
            'text' => 'Preview target',
            'description' => 'Preview target',
            'chooseorcreatecourse' => 1,
            'courseid' => (int)$course->id,
            'maxanswers' => 10,
            'optiondateid_0' => 0,
            'daystonotify_0' => 0,
            'coursestarttime_0' => strtotime('+2 days 10:00'),
            'courseendtime_0' => strtotime('+2 days 12:00'),
        ]);

        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        return [
            'course' => $course,
            'context' => context_module::instance($booking->cmid),
            'option' => $option,
            'student' => $student,
        ];
    }
}

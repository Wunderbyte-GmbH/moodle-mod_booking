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
 * Tests for the ticket PDF renderer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\ticket\ticket_pdf;
use mod_booking\local\ticket\ticket_template_installer;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test that a ticket PDF can be rendered without ever creating a tool_certificate issue.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\ticket\ticket_pdf
 * @covers \mod_booking\local\ticket\ticket_template_installer
 */
final class ticket_pdf_test extends \advanced_testcase {
    /**
     * Build a template carrying one of each element type the renderer has to handle.
     *
     * @return int The template id.
     */
    protected function create_template(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_certificate');
        $template = $generator->create_template((object) ['name' => 'Ticket render test']);
        $pageid = $generator->create_page($template)->get_id();

        // Delegated unchanged to the element classes. Note that each element type expects its own
        // form field name here, not the raw data column.
        $generator->create_element($pageid, 'text', ['text' => 'TICKET', 'name' => 'Title']);
        $generator->create_element($pageid, 'userfield', ['userfield' => 'fullname', 'name' => 'Holder']);
        // Intercepted by ticket_pdf: no customfield data exists for a ticket.
        $generator->create_element(
            $pageid,
            'program',
            ['display' => 'bookingoptionname', 'name' => 'Option']
        );
        // Intercepted by ticket_pdf: must encode mod_booking's own verification URL.
        $generator->create_element($pageid, 'code', ['display' => 4, 'width' => 30, 'name' => 'QR']);

        return (int) $template->get_id();
    }

    /**
     * A ticket renders to a valid PDF without touching tool_certificate_issues or customfield data.
     */
    public function test_render_produces_pdf_without_issue(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $templateid = $this->create_template();
        $user = $this->getDataGenerator()->create_user();

        $issuesbefore = $DB->count_records('tool_certificate_issues');
        $customfieldsbefore = $DB->count_records('customfield_data');

        $ticket = (object) [
            'id' => 1,
            'optionid' => 1,
            'userid' => $user->id,
            'templateid' => $templateid,
            'code' => 'ABCDEFGH12345678',
            'status' => 'valid',
            'timecreated' => time(),
        ];
        $data = [
            'bookingoptionname' => 'Wunderbyte Sommerfest',
            'sessions' => '01.08.2026 18:00',
            'location' => 'Vienna',
        ];

        $pdf = ticket_pdf::render($templateid, $ticket, $user, $data);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'The rendered PDF looks suspiciously small.');

        $this->assertEquals(
            $issuesbefore,
            $DB->count_records('tool_certificate_issues'),
            'Rendering a ticket must not create a certificate issue.'
        );
        $this->assertEquals(
            $customfieldsbefore,
            $DB->count_records('customfield_data'),
            'Rendering a ticket must not write customfield data.'
        );

        // The program element really takes its value from the data snapshot: rendering the same
        // template without that value must produce a different (smaller) document.
        $empty = ticket_pdf::render($templateid, $ticket, $user, []);
        $this->assertStringStartsWith('%PDF', $empty);
        $this->assertNotEquals(
            strlen($empty),
            strlen($pdf),
            'The program element intercept did not put the booking option name on the ticket.'
        );
    }

    /**
     * An unknown template id yields an empty string instead of an exception.
     */
    public function test_render_with_missing_template_is_safe(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $ticket = (object) [
            'id' => 1, 'optionid' => 1, 'userid' => $user->id,
            'templateid' => 0, 'code' => 'ABCDEFGH12345678', 'timecreated' => time(),
        ];

        $this->assertSame('', ticket_pdf::render(0, $ticket, $user, []));
        $this->assertSame('', ticket_pdf::render(99999, $ticket, $user, []));
    }

    /**
     * The fake issue only carries the properties the shipped element classes read.
     */
    public function test_build_fake_issue(): void {
        $this->resetAfterTest(true);

        $ticket = (object) [
            'id' => 7, 'userid' => 42, 'templateid' => 3,
            'code' => 'ABCDEFGH12345678', 'timecreated' => 1000,
        ];
        $issue = ticket_pdf::build_fake_issue($ticket, ['courseid' => 9]);

        // Zero, so nothing can ever look up customfield data by this id.
        $this->assertSame(0, $issue->id);
        $this->assertSame(42, $issue->userid);
        $this->assertSame('ABCDEFGH12345678', $issue->code);
        $this->assertSame(1000, $issue->timecreated);
        $this->assertSame(0, $issue->expires);
        $this->assertSame(9, $issue->courseid);
        $this->assertSame('mod_booking', $issue->component);
    }

    /**
     * The shipped ticket template installs once and is idempotent.
     */
    public function test_shipped_template_installer_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertFalse(ticket_template_installer::is_installed());

        $templateid = ticket_template_installer::ensure_installed();
        $this->assertGreaterThan(0, $templateid);
        $this->assertTrue(ticket_template_installer::is_installed());

        // The template has a page with elements, including the intercepted QR code.
        $pages = \tool_certificate\template::instance($templateid)->get_pages();
        $this->assertCount(1, $pages);
        $elements = reset($pages)->get_elements();
        $this->assertNotEmpty($elements);
        $types = array_map(fn($element) => $element->get_element(), $elements);
        $this->assertContains('code', $types);
        $this->assertContains('program', $types);
        $this->assertContains('userfield', $types);

        // Calling it again returns the same template, it does not create a second one.
        $this->assertEquals($templateid, ticket_template_installer::ensure_installed());
        $this->assertEquals(
            1,
            $DB->count_records('tool_certificate_templates', ['name' => ticket_template_installer::get_template_name()])
        );
    }
}

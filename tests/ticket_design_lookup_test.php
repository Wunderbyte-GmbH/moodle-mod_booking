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
 * Tests for resolving a ticket design by name, as the booking AI agent does.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\wizard\booking\booking_skill_support;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Ticket designs are certificate templates. The agent knows names, never ids.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\ticket\ticket_manager::search_templates
 * @covers \mod_booking\local\ticket\ticket_manager::get_template_name
 * @covers \mod_booking\local\wizard\booking\booking_skill_support::resolve_ticket_design
 */
final class ticket_design_lookup_test extends \advanced_testcase {
    /**
     * Create a certificate template.
     *
     * @param string $name
     * @return int
     */
    private function make_template(string $name): int {
        return (int) $this->getDataGenerator()->get_plugin_generator('tool_certificate')
            ->create_template((object) ['name' => $name])->get_id();
    }

    /**
     * A unique name resolves to exactly one design.
     */
    public function test_resolve_by_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->make_template('Buchungsticket');

        $result = booking_skill_support::resolve_ticket_design('Buchungsticket');
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals($id, $result['templateid']);
        $this->assertEquals('Buchungsticket', $result['name']);

        // Case-insensitive and partial matches work too.
        $this->assertEquals('ok', booking_skill_support::resolve_ticket_design('buchungsticket')['status']);
        $this->assertEquals($id, booking_skill_support::resolve_ticket_design('buchungs')['templateid']);
    }

    /**
     * A numeric value is accepted as a template id.
     */
    public function test_resolve_by_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->make_template('Some ticket');

        $result = booking_skill_support::resolve_ticket_design((string) $id);
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals($id, $result['templateid']);

        // An id that does not exist is an error, not a silent fallback.
        $this->assertEquals('error', booking_skill_support::resolve_ticket_design('999999')['status']);
    }

    /**
     * An exact name wins over partial matches, so a common word stays usable.
     */
    public function test_exact_name_beats_partial_matches(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $exact = $this->make_template('Ticket');
        $this->make_template('Ticket red');
        $this->make_template('Ticket large');

        $result = booking_skill_support::resolve_ticket_design('Ticket');
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals($exact, $result['templateid']);
    }

    /**
     * An ambiguous name comes back through the ambiguity channel, listing the candidates.
     */
    public function test_ambiguous_name_lists_candidates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->make_template('Ticket red');
        $this->make_template('Ticket large');

        $result = booking_skill_support::resolve_ticket_design('Ticket');
        $this->assertEquals('ambiguity', $result['status']);
        $this->assertStringContainsString('Ticket red', $result['message']);
        $this->assertStringContainsString('Ticket large', $result['message']);
    }

    /**
     * An unknown name explains how to create a design instead of failing blankly.
     */
    public function test_unknown_name_is_an_error_with_a_hint(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = booking_skill_support::resolve_ticket_design('Does not exist');
        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Create example ticket template', $result['message']);

        $this->assertEquals('error', booking_skill_support::resolve_ticket_design('  ')['status']);
    }

    /**
     * Template names can be read back for output.
     */
    public function test_get_template_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->make_template('Named design');
        $this->assertEquals('Named design', ticket_manager::get_template_name($id));
        $this->assertSame('', ticket_manager::get_template_name(0));
        $this->assertSame('', ticket_manager::get_template_name(999999));
    }

    /**
     * A request naming a design switches tickets on and fills the remaining fields with defaults.
     */
    public function test_apply_ticket_fields_sets_design_and_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $id = $this->make_template('Buchungsticket');
        $data = (object) ['id' => 0];

        $error = booking_skill_support::apply_ticket_fields(['ticketdesign' => 'Buchungsticket'], $data);

        $this->assertNull($error);
        $this->assertEquals($id, $data->ticket);
        $this->assertEquals(1, $data->ticketpersonalized);
        $this->assertEquals(0, $data->ticketconfirmidentity);
    }

    /**
     * An empty design switches entry tickets off again.
     */
    public function test_apply_ticket_fields_switches_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $data = (object) ['id' => 0];
        $this->assertNull(booking_skill_support::apply_ticket_fields(['ticketdesign' => 'none'], $data));
        $this->assertEquals(0, $data->ticket);

        $data2 = (object) ['id' => 0];
        $this->assertNull(booking_skill_support::apply_ticket_fields(['ticketdesign' => ''], $data2));
        $this->assertEquals(0, $data2->ticket);
    }

    /**
     * The off switch is the documented sentinel only - never natural-language words.
     *
     * Guards the no-lexical-elements rule: a design that is literally named "No" (or
     * any other word a phrase list would swallow) must stay resolvable as a design
     * query instead of being reinterpreted as "switch tickets off".
     */
    public function test_off_sentinel_is_deterministic_not_lexical(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        // The documented sentinel, case-insensitive, plus the empty string.
        $this->assertTrue(ticket_manager::is_design_off_sentinel(''));
        $this->assertTrue(ticket_manager::is_design_off_sentinel('none'));
        $this->assertTrue(ticket_manager::is_design_off_sentinel('NONE'));

        // Word variants are NOT off switches - they are design queries.
        foreach (['no', 'off', 'keine', 'kein', 'No', 'OFF'] as $word) {
            $this->assertFalse(ticket_manager::is_design_off_sentinel($word), "'{$word}' must not act as off sentinel");
        }

        // A template literally named "No" resolves as a design.
        $id = $this->make_template('No');
        $data = (object) ['id' => 0];
        $this->assertNull(booking_skill_support::apply_ticket_fields(['ticketdesign' => 'No'], $data));
        $this->assertEquals($id, $data->ticket);
    }

    /**
     * Requests without any ticket key leave the form data untouched.
     */
    public function test_apply_ticket_fields_ignores_unrelated_requests(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $data = (object) ['id' => 0];
        $this->assertNull(booking_skill_support::apply_ticket_fields(['text' => 'Something'], $data));
        $this->assertFalse(property_exists($data, 'ticket'));
    }

    /**
     * Ticket keys are refused with a clear message while the feature is switched off site-wide.
     */
    public function test_apply_ticket_fields_requires_the_feature(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 0, 'booking');

        $this->make_template('Buchungsticket');
        $data = (object) ['id' => 0];

        $error = booking_skill_support::apply_ticket_fields(['ticketdesign' => 'Buchungsticket'], $data);
        $this->assertIsArray($error);
        $this->assertEquals('error', $error['status']);
        $this->assertStringContainsString('not enabled', $error['detail']);
    }

    /**
     * A sub-flag without any design would be dropped on save, so it is refused instead.
     */
    public function test_apply_ticket_fields_refuses_flags_without_a_design(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $data = (object) ['id' => 0];
        $error = booking_skill_support::apply_ticket_fields(['ticketconfirmidentity' => 1], $data);

        $this->assertIsArray($error);
        $this->assertEquals('error', $error['status']);
        $this->assertStringContainsString('ticketdesign', $error['detail']);
    }

    /**
     * An unresolvable design is reported instead of silently doing nothing.
     */
    public function test_apply_ticket_fields_reports_unknown_design(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $data = (object) ['id' => 0];
        $error = booking_skill_support::apply_ticket_fields(['ticketdesign' => 'Nope'], $data);

        $this->assertIsArray($error);
        $this->assertEquals('error', $error['status']);
    }

    /**
     * The ticket keys are offered to the agent for updates, but not for option creation.
     */
    public function test_schema_exposure(): void {
        $this->resetAfterTest();

        $properties = \mod_booking\local\wizard\options\skills\option_schema_definition::common_properties();
        foreach (['ticketdesign', 'ticketpersonalized', 'ticketconfirmidentity', 'ticketextrainfo'] as $key) {
            $this->assertArrayHasKey($key, $properties, $key . ' must be part of the shared option schema.');
        }
    }
}

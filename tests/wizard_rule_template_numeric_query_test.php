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
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A numeric template query is the template id the planner copied from the candidate list.
 *
 * Write-path rerun W2 (2026-09-15, thread 1663 CRT-3, #2402): the constructor answered the template
 * clarification with templatequery "-12" instead of templateid -12; the name lookup found nothing and
 * the clarification repeated three times.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 */
final class wizard_rule_template_numeric_query_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');
    }

    /**
     * Query spellings that carry nothing but the id.
     *
     * @return array<string,array{string}>
     */
    public static function query_provider(): array {
        $id = -ruletemplate_bookingoption_booked::$templateid;
        return [
            'bare negative id' => [(string)$id],
            'padded' => [' ' . $id . ' '],
            'key=value' => ['templateid=' . $id],
        ];
    }

    /**
     * The numeric query resolves to exactly that template, no name matching involved.
     *
     * @dataProvider query_provider
     * @param string $query
     */
    public function test_numeric_query_resolves_as_template_id(string $query): void {
        $result = (new booking_rules_agent_service())->resolve_template(0, $query);
        $this->assertSame('ok', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertSame(-ruletemplate_bookingoption_booked::$templateid, (int)$result['template']['templateid']);
    }

    /**
     * An id that no template carries is reported as not found, not as an ambiguity.
     */
    public function test_unknown_numeric_query_is_not_found(): void {
        $result = (new booking_rules_agent_service())->resolve_template(0, '-9999');
        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
    }
}

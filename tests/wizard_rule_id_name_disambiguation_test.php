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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A missing rule id that matches a rule NAME must ask, never fail or guess.
 *
 * Follow-up to L6-C2 (#2275): numeric references now steer into ruleid — a rule literally
 * named "42" would become unreachable and a missing id 42 would hard-fail although the
 * named reading exists. The resolver offers the reading as an ambiguity (= clarification).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 */
final class wizard_rule_id_name_disambiguation_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Insert a minimal rule row named like a number.
     *
     * @param string $name
     * @return int contextid the rule lives in
     */
    private function seed_named_rule(string $name): int {
        global $DB;
        $contextid = (int)\context_system::instance()->id;
        $DB->insert_record('booking_rules', (object)[
            'contextid' => $contextid,
            'rulename' => 'rule_react_on_event',
            'rulejson' => json_encode(['name' => $name, 'conditionname' => '', 'actionname' => '']),
            'eventname' => '',
            'useastemplate' => 0,
            'isactive' => 0,
        ]);
        return $contextid;
    }

    /**
     * Missing id + existing same-named rule: the resolver must ask (ambiguity), not fail.
     */
    public function test_missing_id_with_matching_name_asks(): void {
        $contextid = $this->seed_named_rule('4242');

        $result = (new booking_rules_agent_service())->resolve_rule($contextid, 4242, '');

        $this->assertSame('ambiguity', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertStringContainsString('4242', (string)($result['message'] ?? ''));
    }

    /**
     * Missing id with NO matching name keeps the plain honest failure.
     */
    public function test_missing_id_without_matching_name_still_fails(): void {
        $contextid = $this->seed_named_rule('a normal rule name');

        $result = (new booking_rules_agent_service())->resolve_rule($contextid, 4242, '');

        // Still no guess: nothing resolves. Since 2026-09-24 the miss offers the existing rules as a
        // choice (status notfound + candidates) instead of a bare error - the choice is the model's.
        $this->assertSame('notfound', (string)($result['status'] ?? ''));
        $this->assertArrayNotHasKey('rule', $result);
        $this->assertCount(1, (array)($result['candidates'] ?? []));
    }

    /**
     * An EXISTING id always wins — the name reading never overrides a valid id.
     */
    public function test_existing_id_is_never_overridden(): void {
        global $DB;
        $contextid = $this->seed_named_rule('placeholder');
        $realid = (int)$DB->get_field_sql("SELECT MAX(id) FROM {booking_rules}");
        $this->seed_named_rule((string)$realid);

        $result = (new booking_rules_agent_service())->resolve_rule($contextid, $realid, '');

        $this->assertSame('ok', (string)($result['status'] ?? ''), 'a resolvable id must resolve, name or no name');
    }
}

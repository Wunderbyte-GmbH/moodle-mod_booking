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
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A rule created from a template is active unless the caller says otherwise.
 *
 * Finding F34 (#2241), reproduced in baseline runs 4, 5, 7 and the write-path run W1 (rules 44/46):
 * the skill schema promises "isactive (default true)", the rule form defaults to active, but the
 * built-in template records carry no isactive flag, so the rule handler defaults copied 0 and the
 * service persisted an inactive rule while the answer claimed it was active.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 */
final class wizard_rule_template_isactive_default_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');
    }

    /**
     * Built-in template ids: react-on-event and days-before, both without an isactive field.
     *
     * @return array<string,array{int}>
     */
    public static function template_provider(): array {
        return [
            'react on event' => [-ruletemplate_bookingoption_booked::$templateid],
            'days before' => [-ruletemplate_daysbeforestart::$templateid],
        ];
    }

    /**
     * Without an override the created rule is active, in the DB and in the reported record.
     *
     * @dataProvider template_provider
     * @param int $templateid
     */
    public function test_rule_from_template_is_active_by_default(int $templateid): void {
        global $DB;
        $contextid = (int)\context_system::instance()->id;

        $result = (new booking_rules_agent_service())->create_rule_from_template($contextid, $templateid, []);

        $this->assertSame('ok', (string)($result['status'] ?? ''), json_encode($result));
        $ruleid = (int)($result['rule']['id'] ?? 0);
        $this->assertGreaterThan(0, $ruleid);
        $this->assertSame(1, (int)$DB->get_field('booking_rules', 'isactive', ['id' => $ruleid]));
        $this->assertSame(1, (int)($result['rule']['isactive'] ?? -1), 'the reported record must carry the real flag');
    }

    /**
     * An explicit isactive=false override is still honoured and reported truthfully.
     */
    public function test_explicit_inactive_override_is_kept(): void {
        global $DB;
        $contextid = (int)\context_system::instance()->id;

        $result = (new booking_rules_agent_service())->create_rule_from_template(
            $contextid,
            -ruletemplate_bookingoption_booked::$templateid,
            ['isactive' => false]
        );

        $this->assertSame('ok', (string)($result['status'] ?? ''), json_encode($result));
        $ruleid = (int)($result['rule']['id'] ?? 0);
        $this->assertSame(0, (int)$DB->get_field('booking_rules', 'isactive', ['id' => $ruleid]));
        $this->assertSame(0, (int)($result['rule']['isactive'] ?? -1));
    }
}

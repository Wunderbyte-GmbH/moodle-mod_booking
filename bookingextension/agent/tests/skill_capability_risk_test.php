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
 * The skill capabilities declare the capability type and the risks of what each skill does.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Admins grant capabilities by the risk icons of the roles page, so a skill capability must not carry risks the
 * skill does not have (ticket 2779: every skill capability carried data loss and XSS, also the three memory
 * skills every authenticated user holds). Structural rules only, read from db/access.php and the registry.
 *
 * @covers \bookingextension_agent\local\wizard\skill_contract_validator
 */
final class skill_capability_risk_test extends \advanced_testcase {
    /** @var string[] Skills the engine runs without confirmation although they store the user's own data. */
    private const OWN_DATA_WRITERS = ['wizard.remember'];

    /**
     * The skill capabilities of this plugin as declared in db/access.php.
     *
     * @return array capability name => definition
     */
    private function declared_skill_capabilities(): array {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/mod/booking/bookingextension/agent/db/access.php');
        return array_filter(
            $capabilities,
            static fn(string $name): bool => str_starts_with($name, 'bookingextension/agent:skill_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * A capability every authenticated user holds carries no risk: those skills only reach the user's own data.
     */
    public function test_capabilities_of_every_authenticated_user_carry_no_risk(): void {
        $checked = 0;
        foreach ($this->declared_skill_capabilities() as $name => $definition) {
            if (($definition['archetypes']['user'] ?? null) !== CAP_ALLOW) {
                continue;
            }
            $checked++;
            $this->assertSame(0, (int)($definition['riskbitmask'] ?? 0), $name);
        }
        $this->assertGreaterThanOrEqual(3, $checked, 'the memory skills are held by every authenticated user');
    }

    /**
     * A read capability may expose personal data of others, nothing else; no skill of this plugin destroys content.
     */
    public function test_read_capabilities_carry_at_most_the_personal_data_risk(): void {
        foreach ($this->declared_skill_capabilities() as $name => $definition) {
            $risk = (int)($definition['riskbitmask'] ?? 0);
            $this->assertSame(0, $risk & RISK_DATALOSS, $name . ' destroys no content');
            if (($definition['captype'] ?? '') === 'read') {
                $this->assertSame(0, $risk & ~RISK_PERSONAL, $name . ' is a read capability');
            }
        }
    }

    /**
     * The capability type follows the skill: read for a read-only skill, write for a skill that changes something.
     */
    public function test_capability_type_follows_the_skill(): void {
        $this->resetAfterTest();
        $declared = $this->declared_skill_capabilities();
        $registry = skill_registry_factory::get_default();
        $checked = 0;
        foreach ($registry->get_skill_contracts() as $skillname => $meta) {
            $skillname = (string)$skillname;
            $capability = skill_contract_validator::build_skill_capability_name((string)($meta['component'] ?? ''), $skillname);
            if (!isset($declared[$capability])) {
                continue;
            }
            $skill = $registry->get_skill($skillname);
            $this->assertNotNull($skill, $skillname);
            $readonly = $skill->is_read_only() && !in_array($skillname, self::OWN_DATA_WRITERS, true);
            $this->assertSame($readonly ? 'read' : 'write', (string)($declared[$capability]['captype'] ?? ''), $capability);
            $checked++;
        }
        $this->assertGreaterThanOrEqual(30, $checked, 'the engine skills are registered');
    }
}

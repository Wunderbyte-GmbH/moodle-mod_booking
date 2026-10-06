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

namespace bookingextension_agent;

use advanced_testcase;
use context_system;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Every agent skill MUST be gated by a capability derived from its name.
 *
 * The governance gate (skill_executability_evaluator) authorises a skill only when the user holds
 * the skill's declared capability AND that capability is actually defined (get_capability_info()).
 * The declared capability is, by construction, the name-derived
 * "<component>:skill_<normalized_skill_name>" (skill_contract_validator::build_skill_capability_name).
 * If that capability is missing from any db/access.php, the skill is silently always-denied — a
 * latent bug. This test makes that impossible to ship.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\skill_contract_validator
 */
final class skill_name_capability_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * For every registered skill: a name-derived capability exists, is what the gate enforces, and
     * is actually defined as a Moodle capability.
     */
    public function test_every_skill_has_a_defined_name_derived_capability(): void {
        $this->resetAfterTest();
        $registry = skill_registry_factory::get_default();

        $contracts = $registry->get_skill_contracts();
        $this->assertNotEmpty($contracts, 'No skills registered — cannot validate capability coverage.');

        $undeclared = [];   // Skills whose gate exposes no capability at all.
        $mismatched = [];   // Skills whose enforced caps omit the name-derived capability.
        $undefined = [];    // Enforced capabilities that are not defined anywhere (get_capability_info null).

        foreach ($contracts as $skillname => $meta) {
            $skillname = (string)$skillname;
            $component = (string)($meta['component'] ?? '');
            $expected = skill_contract_validator::build_skill_capability_name($component, $skillname);
            $caps = $registry->get_skill_capabilities($skillname);

            if (empty($caps)) {
                $undeclared[] = $skillname;
                continue;
            }
            if ($expected === '' || !in_array($expected, $caps, true)) {
                $mismatched[] = $skillname . ' (expected ' . $expected . ', got ' . implode(',', $caps) . ')';
            }
            foreach ($caps as $cap) {
                if (get_capability_info($cap) === null) {
                    $undefined[] = $skillname . ' -> ' . $cap;
                }
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'These skills expose no governance capability, so the gate cannot authorise them: '
            . implode(', ', $undeclared)
        );
        $this->assertSame(
            [],
            $mismatched,
            'These skills do not expose their name-derived capability: ' . implode('; ', $mismatched)
        );
        $this->assertSame(
            [],
            $undefined,
            'These skills require a capability that is NOT defined in any db/access.php (the skill '
            . 'would be silently always-denied): ' . implode('; ', $undefined)
        );
    }

    /**
     * The derivation rule itself is stable and documented: <component>:skill_<normalized name>,
     * lower-cased, non-alphanumeric runs collapsed to underscores.
     */
    public function test_capability_name_derivation_rule(): void {
        // Components are the real (slash) capability prefixes the registry stores.
        $this->assertSame(
            'bookingextension/agent:skill_course_add_activity',
            skill_contract_validator::build_skill_capability_name('bookingextension/agent', 'course.add_activity')
        );
        $this->assertSame(
            'mod/booking:skill_mod_booking_book_users',
            skill_contract_validator::build_skill_capability_name('mod/booking', 'mod_booking.book_users')
        );
        $this->assertSame('', skill_contract_validator::build_skill_capability_name('', 'x.y'));
        $this->assertSame('', skill_contract_validator::build_skill_capability_name('comp', ''));
    }

    /**
     * The engine derives and enforces the name capability ITSELF — even if a skill's declared
     * metadata capabilities are empty (a 3rd-party dev who forgot, or broken/tampered metadata).
     * This guarantees the per-skill capability check can never be silently skipped.
     */
    public function test_name_capability_enforced_even_with_empty_declared_caps(): void {
        $this->resetAfterTest();

        // A registry whose metadata declares NO capabilities, but whose component + skill name still
        // map to a real, defined capability (bookingextension/agent:skill_course_add_activity).
        $registry = new class extends skill_registry {
            /**
             * Return a contract that declares no capabilities for any skill.
             *
             * @param string $skillname The skill name.
             * @return array|null
             */
            public function get_skill_contract(string $skillname): ?array {
                return ['component' => 'bookingextension/agent', 'readonly' => true, 'capabilities' => []];
            }
            /**
             * Return no declared capabilities for any skill.
             *
             * @param string $skillname The skill name.
             * @return array
             */
            public function get_skill_capabilities(string $skillname): array {
                return [];
            }
        };
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        $method = new \ReflectionMethod($evaluator, 'has_required_capabilities');
        $method->setAccessible(true);

        $ctxid = (int)context_system::instance()->id;
        $skillname = 'course.add_activity';

        $fresh = $this->getDataGenerator()->create_user();
        $this->assertFalse(
            $method->invoke($evaluator, (int)$fresh->id, $ctxid, $skillname),
            'The engine must derive + enforce the name capability even when declared caps are empty.'
        );

        $this->setAdminUser();
        global $USER;
        $this->assertTrue(
            $method->invoke($evaluator, (int)$USER->id, $ctxid, $skillname),
            'An admin holds the name-derived capability.'
        );
    }
}

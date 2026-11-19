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
use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\report\skills\schedule_report_skill;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\wizard\skills\list_skills_skill;

/**
 * A skill that needs a newer Moodle than the site runs stays registered, is not executable, and says why.
 *
 * report.schedule_report is built on the report schedule types of Moodle 5.1. On Moodle 4.5 and 5.0 it is neither
 * offered nor silently absent: the executability gate denies it with its own reason, which the skill governance page
 * and the skill listing show (Wunderbyte-GmbH/Wunderbyte-GmbH#2663).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\skill_executability_evaluator
 * @covers     \bookingextension_agent\local\wizard\skill_contract_validator
 */
final class moodle_version_requirement_test extends advanced_testcase {
    /** @var string Name of the probe skill. */
    private const PROBE = 'wizard.moodle_version_probe';

    /**
     * A registry holding only the probe skill, which needs the given Moodle branch.
     *
     * @param int $requiredbranch
     * @return skill_registry
     */
    private function make_registry(int $requiredbranch): skill_registry {
        $skill = new class ($requiredbranch) extends base_skill {
            /** @var int Moodle branch the probe needs. */
            private int $requiredbranch;

            /**
             * Build the probe.
             *
             * @param int $requiredbranch
             */
            public function __construct(int $requiredbranch) {
                parent::__construct(true, skill_risk_class::R0);
                $this->requiredbranch = $requiredbranch;
            }

            /**
             * Skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'wizard.moodle_version_probe';
            }

            /**
             * Skill schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['type' => 'object', 'description' => 'Probe for the Moodle version gate.', 'properties' => []];
            }

            /**
             * Moodle branch the probe needs.
             *
             * @return int
             */
            public function get_required_moodle_branch(): int {
                return $this->requiredbranch;
            }

            /**
             * No-op execution.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }
        };
        $provider = new class ($skill) implements skill_provider_interface {
            /** @var base_skill The probe skill. */
            private base_skill $skill;

            /**
             * Build the provider.
             *
             * @param base_skill $skill
             */
            public function __construct(base_skill $skill) {
                $this->skill = $skill;
            }

            /**
             * Component of the engine, which owns the wizard namespace.
             *
             * @return string
             */
            public function get_component(): string {
                return 'bookingextension_agent';
            }

            /**
             * The probe skill.
             *
             * @return array
             */
            public function get_skills(): array {
                return [$this->skill];
            }

            /**
             * No prompt packs.
             *
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }

            /**
             * No issue codes.
             *
             * @return issue_code_provider_interface|null
             */
            public function get_issue_code_provider(): ?issue_code_provider_interface {
                return null;
            }

            /**
             * No prompt guidance.
             *
             * @return array
             */
            public function get_prompt_guidance(): array {
                return [];
            }
        };
        $registry = new skill_registry();
        $registry->register($provider);
        // Guard against a vacuous test: the probe must really be registered (the only diagnostic is its missing
        // capability, which the evaluator checks after the Moodle version).
        $this->assertNotNull($registry->get_skill_contract(self::PROBE));
        return $registry;
    }

    /**
     * Evaluate the probe as admin in the system context.
     *
     * @param skill_registry $registry
     * @return array
     */
    private function evaluate(skill_registry $registry): array {
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        return $evaluator->evaluate_skill(self::PROBE, (int)get_admin()->id, (int)\context_system::instance()->id);
    }

    /**
     * On an older Moodle the skill is denied with its own reason, naming both versions, even when it is switched on.
     */
    public function test_a_skill_that_needs_a_newer_moodle_is_denied_with_its_reason(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        $sitebranch = (int)$CFG->branch;

        $verdict = $this->evaluate($this->make_registry($sitebranch + 1));

        $this->assertSame('deny', $verdict['executable_state']);
        $this->assertSame(skill_contract_validator::DENY_MOODLE_VERSION_UNSUPPORTED, $verdict['deny_reason']);
        $this->assertSame(
            [
                'required_moodle' => skill_contract_validator::format_moodle_branch($sitebranch + 1),
                'site_moodle' => skill_contract_validator::format_moodle_branch($sitebranch),
            ],
            $verdict['diagnostics']
        );
    }

    /**
     * A requirement the site meets, or none, never denies the skill for its Moodle version.
     */
    public function test_a_met_requirement_does_not_deny_the_skill(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        foreach ([(int)$CFG->branch, 405, 0] as $requiredbranch) {
            $verdict = $this->evaluate($this->make_registry($requiredbranch));
            $this->assertNotSame(
                skill_contract_validator::DENY_MOODLE_VERSION_UNSUPPORTED,
                $verdict['deny_reason'],
                "Required branch $requiredbranch"
            );
        }
    }

    /**
     * Report schedules need Moodle 5.1; the skill stays registered on every version and carries the requirement.
     */
    public function test_report_schedules_need_moodle_5_1(): void {
        $this->resetAfterTest();
        $this->assertSame(501, (new schedule_report_skill())->get_required_moodle_branch());

        $contract = skill_registry::make_default()->get_skill_contract(schedule_report_skill::SKILL_NAME);
        $this->assertNotNull($contract, 'The skill stays registered on every Moodle version.');
        $this->assertSame(501, $contract['required_moodle_branch']);
    }

    /**
     * Branch numbers read as Moodle versions.
     */
    public function test_format_moodle_branch(): void {
        $this->assertSame('4.5', skill_contract_validator::format_moodle_branch(405));
        $this->assertSame('5.0', skill_contract_validator::format_moodle_branch(500));
        $this->assertSame('5.1', skill_contract_validator::format_moodle_branch(501));
    }

    /**
     * The skill listing names the Moodle version the skill needs, not a generic "cannot be executed".
     */
    public function test_the_skill_listing_names_the_needed_moodle_version(): void {
        $method = new \ReflectionMethod(list_skills_skill::class, 'describe_deny_reason');
        $label = $method->invoke(
            new list_skills_skill(),
            skill_contract_validator::DENY_MOODLE_VERSION_UNSUPPORTED,
            ['required_moodle' => '5.1', 'site_moodle' => '4.5']
        );
        $this->assertSame(get_string('ai_list_actions_unavailable_moodle_version', 'bookingextension_agent', '5.1'), $label);
    }
}

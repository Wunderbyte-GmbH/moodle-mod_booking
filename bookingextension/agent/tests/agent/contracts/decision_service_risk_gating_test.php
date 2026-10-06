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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

// phpcs:disable PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundInStatic
// Note: $this inside the anonymous CLASSES built by the static data providers refers to the
// anonymous class instance itself and is valid PHP; the sniff misreads it as closure scope.

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for risk-class driven decision gating helpers.
 *
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decision_service_risk_gating_test extends TestCase {
    /**
     * Command batches must be split and annotated by risk class before routing.
     *
     * Note: single-command risk resolution itself now lives in (and is unit-tested via)
     * risk_class_resolver; this test still asserts the decision service wires it correctly.
     */
    public function test_split_commands_by_risk_class_injects_resolved_risk_classes(): void {
        $service = $this->build_service([
            'demo.read' => skill_risk_class::R0,
            'demo.write' => skill_risk_class::R2,
            'demo.external' => skill_risk_class::R3,
        ]);

        $groups = $this->invoke_private_method($service, 'split_commands_by_risk_class', [[
            ['skill' => 'demo.read', 'input' => []],
            ['skill' => 'demo.write', 'input' => []],
            ['skill' => 'demo.external', 'input' => []],
        ]]);

        $this->assertCount(1, $groups['r0']);
        $this->assertCount(1, $groups['r2']);
        $this->assertCount(1, $groups['r3']);
        $this->assertSame(skill_risk_class::R0, $groups['r0'][0]['risk_class']);
        $this->assertSame(skill_risk_class::R2, $groups['r2'][0]['risk_class']);
        $this->assertSame(skill_risk_class::R3, $groups['r3'][0]['risk_class']);
    }

    /**
     * Build a decision service with a skill registry mock that returns risk-class aware skills.
     *
     * @param array $skillriskmap
     * @return agent_decision_service
     */
    private function build_service(array $skillriskmap): agent_decision_service {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(
            static function (string $skillname) use ($skillriskmap): ?skill_interface {
                if (!array_key_exists($skillname, $skillriskmap)) {
                    return null;
                }

                $skill = new class ($skillname, $skillriskmap[$skillname]) implements skill_interface {
                    /** @var string The name of the skill. */
                    private string $name;
                    /** @var string The risk class of the skill. */
                    private string $riskclass;

                    /**
                     * Constructor.
                     *
                     * @param string $name
                     * @param string $riskclass
                     */
                    public function __construct(string $name, string $riskclass) {
                         $this->name = $name;
                         $this->riskclass = $riskclass;
                    }

                    /**
                     * Get name.
                     *
                     * @return string
                     */
                    public function get_name(): string {
                         return $this->name;
                    }

                    /**
                     * Get schema.
                     *
                     * @return array
                     */
                    public function get_schema(): array {
                         return ['version' => 1, 'properties' => []];
                    }

                    /**
                     * Get example input.
                     *
                     * @return array
                     */
                    public function get_example_input(): array {
                         return [];
                    }

                    /**
                     * Get prompt contract.
                     *
                     * @return \bookingextension_agent\local\wizard\dto\skill_prompt_contract
                     */
                    public function get_prompt_contract(): \bookingextension_agent\local\wizard\dto\skill_prompt_contract {
                         return new \bookingextension_agent\local\wizard\dto\skill_prompt_contract([
                             'intent' => 'demo',
                             'anchors' => [],
                             'minimal_input' => [],
                             'example_input' => [],
                             'namespace' => 'demo',
                             'version' => 1,
                             'capabilities' => [],
                             'context_scopes' => ['module'],
                             'risk_class' => $this->riskclass,
                         ]);
                    }

                    /**
                     * Get risk class.
                     *
                     * @return string
                     */
                    public function get_risk_class(): string {
                         return $this->riskclass;
                    }

                    /**
                     * Check structure.
                     *
                     * @param array $input
                     * @return array
                     */
                    public function check_structure(array $input): array {
                         return ['valid' => true, 'errors' => []];
                    }

                    /**
                     * Preflight check.
                     *
                     * @param array $input
                     * @param int $contextid
                     * @param int $userid
                     * @return \bookingextension_agent\local\wizard\dto\preflight_result_v2
                     */
                    public function preflight(
                        array $input,
                        int $contextid,
                        int $userid
                    ): \bookingextension_agent\local\wizard\dto\preflight_result_v2 {
                        return \bookingextension_agent\local\wizard\dto\preflight_result_v2::ok($input);
                    }

                    /**
                     * Execute skill.
                     *
                     * @param array $preparedinput
                     * @param int $contextid
                     * @param int $userid
                     * @return array
                     */
                    public function execute(array $preparedinput, int $contextid, int $userid): array {
                         return [];
                    }

                    /**
                     * Check if read only.
                     *
                     * @return bool
                     */
                    public function is_read_only(): bool {
                         return $this->riskclass === skill_risk_class::R0;
                    }
                };

                return $skill;
            }
        );

        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authz = $this->createMock(authorization_service::class);

        return new agent_decision_service($registry, $store, $authz);
    }

    /**
     * Invoke a private decision-service helper.
     *
     * @param agent_decision_service $service
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke_private_method(agent_decision_service $service, string $method, array $args) {
        $reflection = new \ReflectionClass(agent_decision_service::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($service, $args);
    }
}

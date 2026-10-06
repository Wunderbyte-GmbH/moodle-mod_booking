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
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for risk-class driven preflight gating.
 *
 * @covers \bookingextension_agent\local\wizard\services\preflight_pipeline
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preflight_pipeline_risk_class_contract_test extends TestCase {
    /**
     * The batch resolver must pick the highest-risk command in a mixed batch.
     */
    public function test_resolve_batch_risk_class_picks_the_highest_risk_command(): void {
        $service = $this->build_pipeline_service([
            'demo.read' => skill_risk_class::R0,
            'demo.write' => skill_risk_class::R2,
            'demo.external' => skill_risk_class::R3,
        ]);

        $riskclass = $this->invoke_private_method($service, 'resolve_batch_risk_class', [[
            ['skill' => 'demo.read', 'input' => []],
            ['skill' => 'demo.write', 'input' => []],
            ['skill' => 'demo.external', 'input' => []],
        ]]);

        $this->assertSame(skill_risk_class::R3, $riskclass);
    }

    // Note: single-command risk resolution now lives in risk_class_resolver (unit-tested in
    // risk_class_resolver_test); the batch test above still asserts the pipeline wires it correctly.

    /**
     * Build a pipeline service with a skill registry mock that returns risk-class aware skills.
     *
     * @param array $skillriskmap
     * @return preflight_pipeline
     */
    private function build_pipeline_service(array $skillriskmap): preflight_pipeline {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
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

        $registry->method('get_skill_contract')->willReturnCallback(
            static function (string $skillname): ?array {
                return ['skill' => $skillname, 'version' => 1];
            }
        );

        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new preflight_pipeline($registry, $store);
    }

    /**
     * Invoke a private pipeline helper.
     *
     * @param preflight_pipeline $pipeline
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke_private_method(preflight_pipeline $pipeline, string $method, array $args) {
        $reflection = new \ReflectionClass(preflight_pipeline::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($pipeline, $args);
    }
}

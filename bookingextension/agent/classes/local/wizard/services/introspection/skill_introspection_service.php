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
 * Engine implementation of skill introspection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\introspection;

use bookingextension_agent\local\wizard\interfaces\skill_introspection_provider_interface;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Lists the registry's actions and evaluates their executability for a user/context.
 *
 * This holds the registry/evaluator machinery that previously lived inside the list_skills skill, so
 * the skill can depend on {@see skill_introspection_provider_interface} instead.
 */
class skill_introspection_service implements skill_introspection_provider_interface {
    /** @var skill_registry */
    private skill_registry $registry;

    /** @var skill_executability_evaluator */
    private skill_executability_evaluator $evaluator;

    /**
     * Constructor.
     *
     * @param skill_registry|null $registry
     * @param skill_executability_evaluator|null $evaluator
     */
    public function __construct(?skill_registry $registry = null, ?skill_executability_evaluator $evaluator = null) {
        $this->registry = $registry ?? skill_registry_factory::get_default();
        $this->evaluator = $evaluator ?? new skill_executability_evaluator($this->registry, new authorization_service());
    }

    /**
     * List available + unavailable actions for the user/context.
     *
     * @param int    $userid
     * @param int    $contextid
     * @param string $scope
     * @return array{available: array[], unavailable: array[]}
     */
    public function list_actions(int $userid, int $contextid, string $scope): array {
        $available = [];
        $unavailable = [];

        foreach ($this->registry->get_skill_names_for_context($this->evaluator, $userid, $contextid, true) as $name) {
            $skill = $this->registry->get_skill($name);
            if (!$skill) {
                continue;
            }

            $schema = $skill->get_schema();
            $evaluation = $this->evaluator->evaluate_skill($name, $userid, $contextid);
            $isallowed = (string)($evaluation['executable_state'] ?? '') === 'allow';
            $provider = (string)($this->registry->get_skill_contract($name)['component'] ?? 'unknown');

            if ($isallowed) {
                if ($scope === 'readonly' && !$this->registry->is_read_only_skill($name)) {
                    continue;
                }
                if ($scope === 'mutating' && $this->registry->is_read_only_skill($name)) {
                    continue;
                }

                $available[] = [
                    'skill' => $name,
                    'label' => $name,
                    'description' => (string)($schema['description'] ?? ''),
                    'readonly' => $skill->is_read_only(),
                    'provider' => $provider,
                ];
                continue;
            }

            if ($scope !== 'all' && $scope !== 'readonly' && $scope !== 'mutating') {
                continue;
            }

            $unavailable[] = [
                'skill' => $name,
                'label' => $name,
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $skill->is_read_only(),
                'provider' => $provider,
                'deny_reason' => (string)($evaluation['deny_reason'] ?? ''),
                'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
            ];
        }

        return ['available' => $available, 'unavailable' => $unavailable];
    }

    /**
     * Render the full skill catalog as the SAME compact text the planner sees on the no-embeddings
     * (slim_all) path — scope-filtered and with the discovery meta-skills removed.
     *
     * @param int    $userid
     * @param int    $contextid
     * @param string $scope
     * @return string
     */
    public function render_full_skill_catalog(int $userid, int $contextid, string $scope): string {
        $contracts = $this->registry->get_prompt_contracts_for_context($this->evaluator, $userid, $contextid, true);

        $catalogsvc = new planner_catalog_service(new assistant_state_guidance_service());

        // Mirror the discovery catalog gate: partition by executability verdicts. Denied skills
        // (inactive, missing capability, PRO-locked, …) are not part of the rendered list — this
        // is the same source of truth the selectable catalog uses (issue #2223).
        [$contracts] = $catalogsvc->partition_prompt_contracts_by_executability(
            $contracts,
            $this->evaluator->evaluate_all_skills($userid, $contextid)
        );

        $catalog = $catalogsvc->slim_prompt_catalog_for_planner($contracts);

        if ($scope === 'readonly' || $scope === 'mutating') {
            $wantreadonly = ($scope === 'readonly');
            $catalog = array_values(array_filter(
                $catalog,
                static fn(array $entry): bool => (bool)($entry['readonly'] ?? false) === $wantreadonly
            ));
        }

        // The full list must not re-advertise the discovery meta-skills themselves.
        $catalog = $catalogsvc->exclude_discovery_meta_skills($catalog);

        return $catalogsvc->render_catalog_as_text($catalog);
    }
}

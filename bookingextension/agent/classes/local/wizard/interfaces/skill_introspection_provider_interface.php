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
 * Contract for skill introspection (the available/unavailable action listing).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Engine-provided introspection: enumerate the registry's skills and evaluate their executability
 * for a user/context, returning plain data rows.
 *
 * Skills depend only on this contract (never on the registry/evaluator directly), so the engine
 * machinery can be injected and the skill layer stays free of engine services.
 */
interface skill_introspection_provider_interface {
    /**
     * List the agent's actions for the given user/context, split into available and unavailable.
     *
     * Each available row: ['skill','label','description','readonly','provider'].
     * Each unavailable row additionally carries ['deny_reason','diagnostics'] (the human label is a
     * presentation concern left to the caller).
     *
     * @param int    $userid
     * @param int    $contextid
     * @param string $scope 'all' | 'readonly' | 'mutating'
     * @return array{available: array[], unavailable: array[]}
     */
    public function list_actions(int $userid, int $contextid, string $scope): array;

    /**
     * Render the full skill catalog for the user/context as the SAME compact text the planner sees in
     * the no-embeddings (slim_all) path — minus the discovery meta-skills themselves.
     *
     * This is what wizard.list_skills hands back: when semantic retrieval did not surface what the user
     * wants, the planner gets the complete skill list, but in the proven-acceptable slim representation
     * rather than a bespoke verbose dump (thread 565).
     *
     * @param int    $userid
     * @param int    $contextid
     * @param string $scope 'all' | 'readonly' | 'mutating'
     * @return string
     */
    public function render_full_skill_catalog(int $userid, int $contextid, string $scope): string;
}

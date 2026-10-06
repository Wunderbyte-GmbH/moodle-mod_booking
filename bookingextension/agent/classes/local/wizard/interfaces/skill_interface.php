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

namespace bookingextension_agent\local\wizard\interfaces;

use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;

/**
 * Structured AI skill interface.
 *
 * A skill encapsulates its schema, validation, execution, and read-only flag.
 *
 * Validation is split into two explicit phases:
 *
 *  1. check_structure() — pure, no DB access; used by interpreter only.
 *  2. preflight()       — DB lookups, entity resolution, conflict detection;
 *                         used by agent_decision_service during routing.
 *
 * execute() receives the prepared_input from preflight_result_v2 and must
 * NOT repeat heavy resolution logic already done in preflight().
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface skill_interface {
    /**
     * Return the fully-qualified skill name, e.g. vendor.action_name.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Return the skill schema for prompt embedding.
     *
     * @return array
     */
    public function get_schema(): array;

    /**
     * Return a compact example input owned by the skill itself.
     *
     * Used only for prompt routing hints. This must not be synthesized by the
     * registry so skill metadata stays with the skill class.
     *
     * @return array
     */
    public function get_example_input(): array;

    /**
     * Return the explicit planner prompt contract for this skill.
     *
     * @return skill_prompt_contract
     */
    public function get_prompt_contract(): skill_prompt_contract;

    /**
     * Return the declarative risk class for this skill.
     *
     * @return string
     */
    public function get_risk_class(): string;

    /**
     * Structural (pure) validation — no DB access, no side-effects.
     *
     * Called by the interpreter immediately after JSON parsing to verify that
     * the required top-level fields are present and have the expected types.
     * MUST NOT perform DB lookups or any I/O.
     *
     * @param  array $input  Raw command input from the LLM.
     * @return array{valid:bool,errors:string[]}
     */
    public function check_structure(array $input): array;

    /**
     * Deep preflight validation — DB lookups, entity resolution, conflict detection.
     *
     * Called by agent_decision_service after structural validation passes.
     * MUST NOT perform writes.  Returns a preflight_result_v2 whose
     * prepared_input carries resolved IDs and normalised values ready for execute().
     *
     * @param  array $input   Input that has already passed check_structure().
     * @param  int   $contextid Moodle context ID.
     * @param  int   $userid  Executing user ID.
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2;

    /**
     * Execute the skill.
     *
     * Receives prepared_input from the stored pending intent (i.e. the
     * prepared_input produced by preflight()).  MUST NOT repeat heavy
     * resolution logic already done in preflight().
     *
     * @param  array $preparedinput  Resolved, normalised input from preflight().
     * @param  int   $contextid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array;

    /**
     * Whether the skill is read-only and can be auto-executed.
     *
     * @return bool
     */
    public function is_read_only(): bool;
}

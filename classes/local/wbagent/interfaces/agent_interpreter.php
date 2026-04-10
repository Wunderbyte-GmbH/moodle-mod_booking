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
 * Agent interpreter interface.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent\interfaces;

/**
 * Interface for the LLM output interpreter pipeline.
 *
 * The interpreter is the mandatory trust boundary between the raw LLM
 * response and the executor.  It validates, normalises, and classifies
 * the response before any action is taken.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_interpreter {
    /**
     * Parse and validate raw LLM output.
     *
     * Returns a structured result with one of these response_type values:
     *  - 'clarification'        – LLM needs more information from the user.
     *  - 'confirmation_request' – LLM proposes one or more task_call commands for user confirmation.
     *  - 'task_call'            – Validated, ready-to-execute commands (set after confirmation).
     *  - 'error'                – Unrecoverable parse or schema error.
     *
     * @param string $rawresponse  Raw text output from the LLM.
     * @param int    $cmid         Course-module id for context/domain scoping.
     * @param int    $userid       User id.
     * @return array [
     *     'response_type' => string,
     *     'message'       => string,          // Human-readable message for the UI.
     *     'commands'      => array,           // Only set for task_call / confirmation_request.
     *     'ambiguities'   => string[],        // Questions to ask the user.
     *     'errors'        => string[],        // Validation error strings.
     * ]
     */
    public function interpret(string $rawresponse, int $cmid, int $userid): array;
}

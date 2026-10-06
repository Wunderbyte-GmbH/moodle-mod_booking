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
 * Shared planner-phase prompt delegation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\orchestrator;
use core_ai\aiactions\generate_text;

/**
 * Thin delegation to the single phase_prompt_bundle_builder, shared by the discovery and the
 * selection/construction phase services.
 *
 * The flowchart (PPB node) models prompt assembly as ONE builder. These wrappers — including the
 * userid/contextid swap into the bundle builder — were previously copy-pasted identically into both
 * phase services; centralising them here removes that duplication and keeps a single prompt-builder
 * layer, matching the flowchart.
 *
 * The using class must expose a {@see phase_prompt_bundle_builder} $promptbundlebuilder property.
 */
trait planner_phase_prompt_trait {
    /**
     * Build the discovery/selection system prompt via the shared bundle builder.
     *
     * Note the userid/contextid argument swap when delegating to phase_prompt_bundle_builder.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $phase
     * @param string $actionclass
     * @param bool $hasobservations
     * @param array|null $adaptivecatalog
     * @param array $systemskillcatalog
     * @param bool $isfirstassistantturn
     * @param bool $includeskillcatalog
     * @return string
     */
    private function build_system_prompt(
        int $contextid,
        int $userid,
        string $phase = orchestrator::PHASE_DISCOVERY,
        string $actionclass = generate_text::class,
        bool $hasobservations = false,
        ?array $adaptivecatalog = null,
        array $systemskillcatalog = [],
        bool $isfirstassistantturn = false,
        bool $includeskillcatalog = false
    ): string {
        return $this->promptbundlebuilder->build_system_prompt(
            $userid,
            $contextid,
            $phase,
            $actionclass,
            $hasobservations,
            $adaptivecatalog,
            $systemskillcatalog,
            $isfirstassistantturn,
            $includeskillcatalog
        );
    }

    /**
     * Build the full prompt string via the shared bundle builder.
     *
     * @param string $systemprompt
     * @param \stdClass[] $messages
     * @param string[] $observations
     * @param string $phase
     * @param string $runtimecontext
     * @param string[] $plannertracehistory
     * @param bool $autoconfirmmode
     * @param array $plannedstepintents
     * @param string $runtimestate
     * @param bool|null $selectedskillisreadonly Engine-known readonly flag of the selected skill
     *                  (construction phase only; null when unknown or not applicable).
     * @param array $pendingclarification M1 (#2220): engine-recorded action of an open blocking
     *                  clarification chain ({skill, issue_codes, question}); selection phase only.
     * @param array|null $selectedskillinput Input contract of the selected skill (construction only, wave 30).
     * @return string
     */
    private function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations = [],
        string $phase = orchestrator::PHASE_DISCOVERY,
        string $runtimecontext = '',
        array $plannertracehistory = [],
        bool $autoconfirmmode = false,
        array $plannedstepintents = [],
        string $runtimestate = '',
        ?bool $selectedskillisreadonly = null,
        array $pendingclarification = [],
        ?array $selectedskillinput = null
    ): string {
        return $this->promptbundlebuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $phase,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode,
            $plannedstepintents,
            $runtimestate,
            $selectedskillisreadonly,
            $pendingclarification,
            $selectedskillinput
        );
    }

    /**
     * JSON-encode a value, returning '' on failure.
     *
     * @param mixed $value
     * @param int $flags
     * @return string
     */
    private function json_encode_or_empty($value, int $flags = 0): string {
        $json = json_encode($value, $flags);
        return $json === false ? '' : $json;
    }
}

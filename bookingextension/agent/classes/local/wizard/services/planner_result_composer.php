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

namespace bookingextension_agent\local\wizard\services;

/**
 * Compose the planner result from selection and construction phase outputs.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner_result_composer {
    /**
     * Compose a unified planner result while preserving the construction payload.
     *
     * Phase trace is restricted to selection + parameter_construction.
     * Discovery context remains available via planner_trace_history only.
     *
     * @param array $discoverystate
     * @param array $selectionstate
     * @param array $constructionstate
     * @return array
     */
    public function compose(array $discoverystate, array $selectionstate, array $constructionstate): array {
        $phasetrace = [
            'selection' => $this->build_phase_snapshot($selectionstate),
            'parameter_construction' => $this->build_phase_snapshot($constructionstate),
        ];

        $plannerresult = [
            'selection' => $selectionstate,
            'parameter_construction' => $constructionstate,
            'phase_trace' => $phasetrace,
            'planner_trace_history' => (array)($discoverystate['plannertracehistory'] ?? []),
        ];

        $result = $constructionstate;
        $result['phase_trace'] = $phasetrace;
        $result['planner_result'] = $plannerresult;
        if (!empty($selectionstate['planned_steps'])) {
            $result['planned_steps'] = $selectionstate['planned_steps'];
        }
        return $result;
    }

    /**
     * Reduce a phase state to a stable trace snapshot.
     *
     * @param array $state
     * @return array
     */
    private function build_phase_snapshot(array $state): array {
        $snapshot = [
            'response_type' => (string)($state['response_type'] ?? ''),
            'message' => (string)($state['message'] ?? ''),
            'phase' => (string)($state['phase'] ?? ''),
            'selected_skill' => (string)($state['selected_skill'] ?? ''),
            'catalogselectionmode' => (string)($state['catalogselectionmode'] ?? ''),
            'embeddingstatus' => (string)($state['embeddingstatus'] ?? ''),
            'issue_codes' => (array)($state['issue_codes'] ?? []),
            'errors' => (array)($state['errors'] ?? []),
            // Planner-only repair instructions (F3): part of the trace the next planner
            // turn reads, never of any user-facing channel.
            'repair_hints' => (array)($state['repair_hints'] ?? []),
        ];
        // The phase's own words stay marked as model text (model_authored_text): never masked again.
        return model_authored_text::is_model_message($state)
            ? model_authored_text::mark($snapshot, (string)$state['message'])
            : $snapshot;
    }
}

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
 * Generic, domain-agnostic pre-execution (confirmation) preview builder.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\skill_registry;

/**
 * Builds the user-facing preview of PROPOSED commands shown in the confirmation panel.
 *
 * This is the pre-execution counterpart of preview_passthrough (which forwards previews of EXECUTED
 * results). For each proposed command it asks the skill to describe its own proposed input via
 * describe_proposed_action() — the engine never inspects the parameters itself, so it stays fully
 * skill-agnostic. The result is a single self-contained descriptor the client renders generically:
 *
 *   [
 *     'type'    => 'proposed_action',
 *     'payload' => [
 *       'actions' => [
 *         ['skill' => '…', 'title' => '…', 'summary' => '…',
 *          'rows' => [['label' => '…', 'value' => '…'], …]],
 *         …
 *       ],
 *     ],
 *   ]
 *
 * Skills get a readable preview for free through base_skill's generic default; a skill that wants a
 * richer one overrides describe_proposed_action(). Either way this service only forwards the data.
 */
class proposed_action_preview {
    /**
     * Build the JSON preview descriptor for a set of proposed commands.
     *
     * Best-effort: a skill that cannot be resolved or whose descriptor throws is skipped, never fatal.
     *
     * @param mixed[] $commands Proposed commands (each: {skill, input, …}).
     * @param skill_registry $registry
     * @param int $followupcount Series steps that follow AFTER the staged command(s).
     * @return string JSON-encoded descriptor, or '' when there is nothing to show.
     */
    public static function build_preview_json(array $commands, skill_registry $registry, int $followupcount = 0): string {
        $actions = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $skillname = trim((string)($command['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            $skill = $registry->get_skill($skillname);
            if ($skill === null || !method_exists($skill, 'describe_proposed_action')) {
                continue;
            }

            try {
                $descriptor = $skill->describe_proposed_action($input);
            } catch (\Throwable $e) {
                debugging(
                    'wizard: describe_proposed_action failed for ' . $skillname . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                continue;
            }

            $rows = self::sanitize_rows(is_array($descriptor['rows'] ?? null) ? (array)$descriptor['rows'] : []);
            if (empty($rows)) {
                continue;
            }

            $actions[] = [
                'skill' => $skillname,
                'title' => trim((string)($descriptor['title'] ?? '')),
                'summary' => trim((string)($descriptor['summary'] ?? '')),
                'rows' => $rows,
            ];
        }

        if (empty($actions)) {
            return '';
        }

        // One confirmation executes exactly one step: number the cards and say so.
        $lang = '';
        foreach ($commands as $command) {
            $lang = trim((string)(((array)($command['input'] ?? []))['outputlang'] ?? ''));
            if ($lang !== '') {
                break;
            }
        }
        $total = count($actions);
        if ($total > 1) {
            foreach ($actions as $i => $action) {
                $position = $i + 1;
                $actions[$i]['title'] = trim("({$position}/{$total}) " . (string)$action['title']);
            }
            $note = localized_string_service::get('ai_preview_series_first', 'bookingextension_agent', $total, $lang);
            $actions[0]['summary'] = trim($note . ' ' . (string)$actions[0]['summary']);
        } else if ($followupcount > 0) {
            $note = localized_string_service::get(
                'ai_preview_series_remaining',
                'bookingextension_agent',
                $followupcount,
                $lang
            );
            $actions[0]['summary'] = trim($note . ' ' . (string)$actions[0]['summary']);
        }

        $encoded = json_encode(['type' => 'proposed_action', 'payload' => ['actions' => $actions]]);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Normalize and validate the label/value rows a skill returned.
     *
     * @param mixed[] $rows
     * @return array[]
     */
    private static function sanitize_rows(array $rows): array {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $value = trim((string)($row['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $clean[] = ['label' => $label, 'value' => $value];
        }
        return $clean;
    }
}

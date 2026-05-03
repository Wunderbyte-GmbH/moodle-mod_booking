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
 * Centralised result-payload summarizer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

/**
 * Converts raw task result payloads into human-readable summary strings.
 *
 * Two output modes are provided:
 *  - for_observation(): concise LLM-ready text for the agent observation loop.
 *    Replaces the previously duplicated build_observation_from_result() in agent_runtime.
 *  - for_client(): plain-text fallback message for client-facing responses when
 *    no LLM narration is available.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_payload_summarizer {
    /**
     * Build a concise observation string for the LLM loop.
     *
     * Injected into the next orchestrator call so the model can reason about
     * what the tools returned.  It must be concise, deterministic, and never
     * contain raw DB ids or sensitive fields.
     *
     * @param  array  $results  Raw task result payloads from execute_commands().
     * @param  int    $step     1-based loop step number used as a prefix label.
     * @return string
     */
    public static function for_observation(array $results, int $step): string {
        $parts = [];

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $summary = self::describe_entry($entry);
            if ($summary !== '') {
                $parts[] = $summary;
            }
        }

        if (empty($parts)) {
            return "Step {$step}: Tool executed successfully.";
        }

        return "Step {$step}: " . implode(' ', $parts);
    }

    /**
     * Classify a single result entry into a named category.
     *
     * Used by both for_observation() and execution_feedback_service to avoid
     * duplicating the structural type-detection logic across two classes.
     *
     * Possible return values:
     *  'options'      — entry contains a booking-options array
     *  'users'        — entry contains a users array
     *  'courses'      — entry contains a courses array
     *  'docs'         — entry contains a docs/documentation array
     *  'diagnosis'    — entry contains a diagnosis object
     *  'capabilities' — entry contains a capabilities array
     *  'current_user' — entry has fullname or email keys (get_current_user result)
     *  'generic'      — none of the above
     *
     * @param  array $entry  A single raw task result payload.
     * @return string        Category identifier.
     */
    public static function detect_result_category(array $entry): string {
        if (!empty($entry['options']) && is_array($entry['options'])) {
            return 'options';
        }
        if (!empty($entry['users']) && is_array($entry['users'])) {
            return 'users';
        }
        if (!empty($entry['courses']) && is_array($entry['courses'])) {
            return 'courses';
        }
        if (!empty($entry['docs']) && is_array($entry['docs'])) {
            return 'docs';
        }
        if (!empty($entry['diagnosis']) && is_array($entry['diagnosis'])) {
            return 'diagnosis';
        }
        if (!empty($entry['capabilities']) && is_array($entry['capabilities'])) {
            return 'capabilities';
        }
        if (array_key_exists('fullname', $entry) || array_key_exists('email', $entry)) {
            return 'current_user';
        }
        return 'generic';
    }

    /**
     * Describe a single result entry as an observation string.
     *
     * @param  array $entry
     * @return string
     */
    private static function describe_entry(array $entry): string {
        $category = self::detect_result_category($entry);

        switch ($category) {
            case 'options':
                $count  = count($entry['options']);
                $titles = array_slice(
                    array_filter(array_map(
                        static fn($o): string => trim((string)($o['name'] ?? $o['text'] ?? '')),
                        $entry['options']
                    )),
                    0,
                    5
                );
                $summary = "Found {$count} booking option(s)";
                if (!empty($titles)) {
                    $summary .= ': ' . implode(', ', $titles);
                }
                return $summary . '.';

            case 'users':
                return 'Found ' . count($entry['users']) . ' user(s).';

            case 'courses':
                return 'Found ' . count($entry['courses']) . ' course(s).';

            case 'docs':
                $count = count($entry['docs']);
                $title = trim((string)($entry['docs'][0]['title'] ?? ''));
                $summary = "Retrieved {$count} documentation excerpt(s)";
                if ($title !== '') {
                    $summary .= " (top: \"{$title}\")";
                }
                return $summary . '.';

            case 'diagnosis':
                $optname = trim((string)($entry['diagnosis']['optionname'] ?? ''));
                $summary = 'Diagnosis completed';
                if ($optname !== '') {
                    $summary .= " for option \"{$optname}\"";
                }
                return $summary . '.';

            case 'capabilities':
                return 'Listed ' . count($entry['capabilities']) . ' capability/action item(s).';

            case 'current_user':
                $name = trim((string)($entry['fullname'] ?? ''));
                return 'Current user identified' . ($name !== '' ? ": {$name}" : '') . '.';

            default:
                // Fallback: use task-authored user message or detail string.
                return trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
        }
    }
}

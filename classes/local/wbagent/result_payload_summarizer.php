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
     * Describe a single result entry as an observation string.
     *
     * @param  array $entry
     * @return string
     */
    private static function describe_entry(array $entry): string {
        // Booking options list (booking.search_options / booking.list_option_properties).
        if (!empty($entry['options']) && is_array($entry['options'])) {
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
        }

        // Users list (booking.search_users / booking.get_current_user).
        if (!empty($entry['users']) && is_array($entry['users'])) {
            return 'Found ' . count($entry['users']) . ' user(s).';
        }

        // Courses list (booking.search_courses).
        if (!empty($entry['courses']) && is_array($entry['courses'])) {
            return 'Found ' . count($entry['courses']) . ' course(s).';
        }

        // Documentation excerpts (booking.explain_docs_topic).
        if (!empty($entry['docs']) && is_array($entry['docs'])) {
            $count = count($entry['docs']);
            $title = trim((string)($entry['docs'][0]['title'] ?? ''));
            $summary = "Retrieved {$count} documentation excerpt(s)";
            if ($title !== '') {
                $summary .= " (top: \"{$title}\")";
            }
            return $summary . '.';
        }

        // Booking diagnosis (booking.diagnose_booking_issue / booking.diagnose_cancellation_issue).
        if (!empty($entry['diagnosis']) && is_array($entry['diagnosis'])) {
            $optname = trim((string)($entry['diagnosis']['optionname'] ?? ''));
            $summary = 'Diagnosis completed';
            if ($optname !== '') {
                $summary .= " for option \"{$optname}\"";
            }
            return $summary . '.';
        }

        // Capabilities / actions list (booking.list_actions / booking.list_option_properties).
        if (!empty($entry['capabilities']) && is_array($entry['capabilities'])) {
            return 'Listed ' . count($entry['capabilities']) . ' capability/action item(s).';
        }

        // Current user info (booking.get_current_user).
        if (array_key_exists('fullname', $entry) || array_key_exists('email', $entry)) {
            $name = trim((string)($entry['fullname'] ?? ''));
            return 'Current user identified' . ($name !== '' ? ": {$name}" : '') . '.';
        }

        // Fallback: use task-authored user message or detail string.
        return trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
    }
}

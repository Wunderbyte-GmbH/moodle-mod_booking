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
 * Build user-facing execution feedback after task execution.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

/**
 * Generates post-execution feedback and client-safe run results.
 */
class execution_feedback_service {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Build the final assistant message and client-safe result payload.
     *
     * Message generation is now deterministic — the previous secondary LLM call
     * has been removed to comply with the "one agent-controlled LLM loop" rule.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $commands
     * @param array $results
     * @param string $outputlang
     * @return array
     */
    public function build_completion_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang = ''
    ): array {
        $message = $this->fallback_message_for_results($results, $outputlang);

        return [
            'message' => $message,
            'results' => $this->sanitize_results_for_client($results, $outputlang),
        ];
    }

    /**
     * Remove sensitive or low-value raw result fields before data reaches the client.
     *
     * @param array $results
     * @param string $outputlang
     * @return array
     */
    private function sanitize_results_for_client(array $results, string $outputlang = ''): array {
        $sanitized = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $entry = [
                'status' => (string)($result['status'] ?? ''),
                'detail' => $this->sanitize_result_detail($result, $outputlang),
                'resultid' => isset($result['resultid']) ? (int)$result['resultid'] : null,
            ];

            // Only pass task-authored user text through directly when no explicit output language
            // was requested (legacy/internal paths). Otherwise, frontend should use the normalized
            // top-level completion message to preserve language consistency.
            if (
                $outputlang === ''
                && isset($result['usermessage'])
                && is_string($result['usermessage'])
                && trim($result['usermessage']) !== ''
            ) {
                $entry['usermessage'] = trim($result['usermessage']);
            }

            if (isset($result['debugmessage']) && is_string($result['debugmessage']) && trim($result['debugmessage']) !== '') {
                $entry['debugmessage'] = trim($result['debugmessage']);
            }

            if (isset($result['userid'])) {
                $entry['userid'] = (int)$result['userid'];
            }

            if (isset($result['fullname']) && is_string($result['fullname']) && trim($result['fullname']) !== '') {
                $entry['fullname'] = trim($result['fullname']);
            }

            if (isset($result['email']) && is_string($result['email']) && trim($result['email']) !== '') {
                $entry['email'] = trim($result['email']);
            }

            if (isset($result['previewmode']) && is_string($result['previewmode']) && trim($result['previewmode']) !== '') {
                $entry['previewmode'] = trim($result['previewmode']);
            }

            if (isset($result['previewdata']) && is_array($result['previewdata'])) {
                $entry['previewdata'] = $result['previewdata'];
            }

            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                $entry['previewoptionids'] = array_values(array_map('intval', $result['previewoptionids']));
            }

            if (!empty($result['properties']) && is_array($result['properties'])) {
                $entry['properties'] = $result['properties'];
            }

            if (!empty($result['actions']) && is_array($result['actions'])) {
                $entry['actions'] = $result['actions'];
            }

            if (!empty($result['capabilities']) && is_array($result['capabilities'])) {
                $entry['capabilities'] = $result['capabilities'];
            }

            if (
                $outputlang === ''
                && isset($result['summary'])
                && is_string($result['summary'])
                && trim($result['summary']) !== ''
            ) {
                $entry['summary'] = trim($result['summary']);
            }

            $sanitized[] = $entry;
        }

        return $sanitized;
    }

    /**
     * Collapse raw task details into a safe client detail string.
     *
     * @param array $result
     * @param string $outputlang
     * @return string
     */
    private function sanitize_result_detail(array $result, string $outputlang = ''): string {
        $isgerman = strpos(strtolower($outputlang), 'de') === 0;

        if (isset($result['diagnosis']) && is_array($result['diagnosis'])) {
            $optionname = trim((string)($result['diagnosis']['optionname'] ?? ''));
            if ($optionname !== '') {
                return $isgerman
                    ? ('Ich habe die Situation fuer die Buchungsoption "' . $optionname . '" analysiert.')
                    : ('I analyzed the situation for booking option "' . $optionname . '".');
            }

            return $isgerman
                ? 'Ich habe die Buchungssituation analysiert.'
                : 'I analyzed the booking situation.';
        }

        $usermessage = trim((string)($result['usermessage'] ?? ''));
        if ($usermessage !== '' && $outputlang === '') {
            return $usermessage;
        }

        if (isset($result['users']) && is_array($result['users'])) {
            $count = count($result['users']);
            if ($count === 0) {
                return $isgerman ? 'Keine passenden Nutzer gefunden.' : 'No matching users found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' passende Nutzer gefunden.')
                : ('Found ' . $count . ' matching user(s).');
        }

        if (isset($result['courses']) && is_array($result['courses'])) {
            $count = count($result['courses']);
            if ($count === 0) {
                return $isgerman ? 'Keine passenden Kurse gefunden.' : 'No matching courses found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' passende Kurse gefunden.')
                : ('Found ' . $count . ' matching course(s).');
        }

        if (isset($result['options']) && is_array($result['options'])) {
            $count = count($result['options']);
            if ($count === 0) {
                return $isgerman ? 'Keine passende Buchungsoption gefunden.' : 'No matching booking options found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' Buchungsoption(en) gefunden.')
                : ('Found ' . $count . ' option(s).');
        }

        if (array_key_exists('fullname', $result) || array_key_exists('email', $result)) {
            return $isgerman ? 'Aktueller Nutzer identifiziert.' : 'Current user identified.';
        }

        if (!empty($result['capabilities']) && is_array($result['capabilities'])) {
            $summary = trim((string)($result['summary'] ?? ''));
            if ($summary !== '') {
                return $summary;
            }
        }

        $detail = trim((string)($result['detail'] ?? ''));
        if ($detail !== '' && $outputlang === '') {
            return $detail;
        }

        if ($detail !== '' && $outputlang !== '') {
            return $isgerman ? 'Die Aktion wurde ausgefuehrt.' : 'The action was executed.';
        }

        return $isgerman ? 'Die Aktion wurde ausgefuehrt.' : 'The action was executed.';
    }

    /**
     * Deterministic fallback when generating a user-facing result summary.
     *
     * @param array $results
     * @param string $outputlang
     * @return string
     */
    private function fallback_message_for_results(array $results, string $outputlang): string {
        $isgerman = strpos(strtolower($outputlang), 'de') === 0;
        if (empty($results)) {
            return $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        }

        $first = $results[0] ?? [];
        if (!is_array($first)) {
            return $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        }

        if (isset($first['users']) && is_array($first['users'])) {
            $count = count($first['users']);
            if ($count === 0) {
                return $isgerman ? 'Ich habe keine passenden Nutzer gefunden.' : 'I could not find any matching users.';
            }
            return $isgerman
                ? 'Ich habe ' . $count . ' passende Nutzer gefunden.'
                : 'I found ' . $count . ' matching users.';
        }

        if (isset($first['courses']) && is_array($first['courses'])) {
            $count = count($first['courses']);
            if ($count === 0) {
                return $isgerman ? 'Ich habe keine passenden Kurse gefunden.' : 'I could not find any matching courses.';
            }
            return $isgerman
                ? 'Ich habe ' . $count . ' passende Kurse gefunden.'
                : 'I found ' . $count . ' matching courses.';
        }

        if (isset($first['options']) && is_array($first['options'])) {
            $count = count($first['options']);
            if ($count === 0) {
                $nomatches = $isgerman
                    ? 'Ich habe keine passende Buchungsoption gefunden.'
                    : 'I could not find a matching booking option.';
                return $nomatches;
            }
            $foundmessage = $isgerman
                ? 'Ich habe ' . $count . ' passende Buchungsoption(en) gefunden.'
                : 'I found ' . $count . ' matching booking option(s).';
            return $foundmessage;
        }

        if (
            array_key_exists('fullname', $first)
            || array_key_exists('email', $first)
        ) {
            return $isgerman ? 'Ich habe dein Benutzerkonto gefunden.' : 'I identified your user account.';
        }

        $detail = trim((string)($first['detail'] ?? ''));
        if ($detail !== '') {
            return $detail;
        }

        $defaultmessage = $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        return $defaultmessage;
    }
}

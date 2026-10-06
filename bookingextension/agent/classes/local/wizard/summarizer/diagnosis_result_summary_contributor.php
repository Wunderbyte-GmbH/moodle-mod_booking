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
 * Diagnosis result summary contributor.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\summarizer;

use bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface;

/**
 * Summarizes diagnosis result payloads.
 */
class diagnosis_result_summary_contributor implements result_summary_contributor_interface {
    /**
     * Check whether this contributor handles diagnosis category entries.
     *
     * @param string $category
     * @param array $entry
     * @return bool
     */
    public function supports(string $category, array $entry): bool {
        return $category === 'diagnosis' && !empty($entry['diagnosis']) && is_array($entry['diagnosis']);
    }

    /**
     * Summarize diagnosis payload for observation/client fallback usage.
     *
     * @param array $entry
     * @param int $step
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string {
        $diagnosis = (array)($entry['diagnosis'] ?? []);
        $optionname = trim((string)($diagnosis['optionname'] ?? ''));
        $issue = trim((string)($diagnosis['issue'] ?? ''));
        $userstatus = trim((string)($diagnosis['userstatus'] ?? ''));

        $reasons = array_values(array_filter(array_map(
            static fn($r): string => trim((string)$r),
            (array)($diagnosis['reasons'] ?? [])
        )));

        $header = 'Diagnosis';
        if ($optionname !== '') {
            $header .= " for option \"{$optionname}\"";
        }
        if ($issue !== '') {
            $header .= " (issue: {$issue})";
        }
        $header .= '.';

        $lines = [$header];

        if ($userstatus !== '') {
            $lines[] = "User booking status: {$userstatus}.";
        }

        if (!empty($reasons)) {
            $lines[] = 'Findings:';
            foreach ($reasons as $i => $reason) {
                $lines[] = '- ' . $reason;
                if ($i >= 9) {
                    $lines[] = '- [' . (count($reasons) - 10) . ' more finding(s) omitted]';
                    break;
                }
            }
        } else {
            $lines[] = 'No specific blocking reasons detected.';
        }

        return implode("\n", $lines);
    }
}

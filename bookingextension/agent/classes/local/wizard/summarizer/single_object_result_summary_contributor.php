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
 * Reusable summarizer for single-object payloads.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\summarizer;

use bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface;

/**
 * Summarizes result payloads containing one structured object.
 */
class single_object_result_summary_contributor implements result_summary_contributor_interface {
    /** @var string[] Candidate payload keys for singular object summaries. */
    private const OBJECT_KEYS = ['site', 'entity', 'group', 'module', 'event', 'course', 'user'];

    /**
     * Check whether this contributor handles the entry.
     *
     * @param string $category
     * @param array $entry
     * @return bool
     */
    public function supports(string $category, array $entry): bool {
        if ($category !== 'generic') {
            return false;
        }

        foreach (self::OBJECT_KEYS as $key) {
            if (!empty($entry[$key]) && is_array($entry[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Summarize one structured object in a compact deterministic format.
     *
     * @param array $entry
     * @param int $step
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string {
        foreach (self::OBJECT_KEYS as $key) {
            if (empty($entry[$key]) || !is_array($entry[$key])) {
                continue;
            }

            $payload = (array)$entry[$key];
            $parts = [];
            foreach (['name', 'fullname', 'shortname', 'lang', 'timezone', 'release', 'id'] as $field) {
                if (!array_key_exists($field, $payload)) {
                    continue;
                }
                $value = trim((string)$payload[$field]);
                if ($value === '') {
                    continue;
                }
                $parts[] = $field . '=' . $value;
                if (count($parts) >= 5) {
                    break;
                }
            }

            if (empty($parts)) {
                return 'Loaded ' . $key . ' details.';
            }

            return 'Loaded ' . $key . ' details: ' . implode(', ', $parts) . '.';
        }

        return '';
    }
}

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
 * Builds compact observation payloads from queue outcomes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\queue;

/**
 * Queue observation message builder.
 */
class observation_builder {
    /**
     * Build a compact observation summary from executed queue items.
     *
     * @param array[] $queueitems
     * @return string
     */
    public function build_observation(array $queueitems): string {
        $parts = [];
        foreach ($queueitems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $skill = trim((string)($item['skill'] ?? ''));
            $status = trim((string)($item['status'] ?? ''));
            $issuecodes = array_values(array_filter(array_map('strval', (array)($item['issue_codes'] ?? []))));

            if ($skill === '') {
                continue;
            }

            $line = $skill . ': ' . ($status !== '' ? $status : 'unknown');
            if (!empty($issuecodes)) {
                $line .= ' [' . implode(', ', $issuecodes) . ']';
            }
            $parts[] = $line;
        }

        if (empty($parts)) {
            return '';
        }

        return 'Queue observation: ' . implode('; ', $parts);
    }
}

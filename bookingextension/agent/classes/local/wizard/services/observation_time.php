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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Central formatter for timestamps that appear in agent observations.
 *
 * The LLM reasons far better over a human-readable, timezone-adjusted date than over a raw Unix
 * timestamp. Any skill or service that puts a timestamp into an observation/result payload should
 * render it through here so the format (and the viewer's timezone handling via {@see userdate()})
 * stays consistent across the whole agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observation_time {
    /**
     * Format a Unix timestamp as a human-readable, timezone-adjusted date for an observation.
     *
     * @param int $timestamp Unix timestamp; 0/negative is treated as "not set".
     * @return string Human-readable date in the viewer's timezone, or the "never" string when empty.
     */
    public static function format(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string('never');
        }
        return userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig'));
    }
}

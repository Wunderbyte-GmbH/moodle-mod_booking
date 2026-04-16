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

namespace mod_booking\local\wbagent\interfaces;

/**
 * Optional interface for tasks that contribute message trigger definitions.
 *
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface task_trigger_provider_interface {
    /**
     * Return message trigger definitions this task wants the LLM to classify.
     *
     * Trigger schema:
     * - id (string, required): stable identifier (e.g. booking.some_trigger)
     * - description (string, required): what should count as a match
     * - examples (array<int,string>, optional): non-exhaustive positive examples
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array;
}

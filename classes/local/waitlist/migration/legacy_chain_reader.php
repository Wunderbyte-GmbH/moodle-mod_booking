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
 * Reads one generation of legacy waitlist-progression chain state out of a raw {task_adhoc} row
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §7, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §3).
 * upgrade_step iterates all registered readers per task_adhoc row and uses the first one whose
 * can_read() matches (Strategy pattern, Open/Closed - a newly discovered old format becomes an
 * additional reader, no change to upgrade_step itself). A row no reader recognises is left alone -
 * M3 cleanup and the T7 heartbeat are the deliberate safety net for anything not concretely
 * covered (WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §6, "Größte Einzel-Unbekannte").
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\migration;

use stdClass;

/**
 * Strategy contract for reading one legacy chain format.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface legacy_chain_reader {
    /**
     * Whether this reader recognises the given raw {task_adhoc} row (classname + customdata
     * shape). Must be defensive - a malformed/unexpected shape returns false, never throws.
     *
     * @param stdClass $taskrecord a raw {task_adhoc} row (id, classname, customdata, nextruntime, ...)
     * @return bool
     */
    public function can_read(stdClass $taskrecord): bool;

    /**
     * Extracts the chain state from a row this reader recognises. Only ever called after
     * can_read() returned true for the same row.
     *
     * @param stdClass $taskrecord a raw {task_adhoc} row
     * @return legacy_chain_state
     */
    public function extract(stdClass $taskrecord): legacy_chain_state;
}

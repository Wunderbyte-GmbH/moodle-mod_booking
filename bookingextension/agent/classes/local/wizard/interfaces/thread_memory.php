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

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Contract for per-user/per-context conversation-thread key/value memory.
 *
 * Engine-owned (thread data lives in the engine's conversation tables). Skills depend
 * on this contract via base_skill::thread_memory(), never on the concrete engine store.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface thread_memory {
    /**
     * Read a metadata value from the user's active thread in this context.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $key
     * @return mixed null when there is no active thread or the key is unset
     */
    public function get_value(int $userid, int $contextid, string $key);

    /**
     * Write a metadata value to the user's thread in this context (created if needed).
     *
     * @param int $userid
     * @param int $contextid
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_value(int $userid, int $contextid, string $key, $value): void;
}

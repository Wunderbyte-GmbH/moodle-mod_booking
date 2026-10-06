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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interfaces\thread_memory;

/**
 * Engine adapter: thread_memory backed by the conversation_store.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_thread_memory implements thread_memory {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store|null $store
     */
    public function __construct(?conversation_store $store = null) {
        $this->store = $store ?? new conversation_store();
    }

    /**
     * Return a stored metadata value for the user's active thread.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $key
     * @return mixed The stored value, or null when no active thread exists.
     */
    public function get_value(int $userid, int $contextid, string $key) {
        $thread = $this->store->get_active_thread($userid, $contextid);
        if (!$thread) {
            return null;
        }
        return $this->store->get_thread_metadata_value((int)$thread->id, $key);
    }

    /**
     * Store a metadata value on the user's thread, creating one if needed.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_value(int $userid, int $contextid, string $key, $value): void {
        $thread = $this->store->get_or_create_thread($userid, $contextid);
        $this->store->set_thread_metadata_value((int)$thread->id, $key, $value);
    }
}

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
 * In-memory lock factory for tests that need a lock to be held by another worker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\tests;

use core\lock\lock;
use core\lock\lock_factory;

/**
 * In-memory lock factory for tests that need a lock to be held by another worker.
 *
 * The database lock factories hand the same lock to the same session twice, so a test that
 * runs in one session cannot hold a lock back with them. The file lock factory can, but it
 * waits on time(), and tool_mocktesttime replaces time() in the core\lock namespace with the
 * frozen mock clock: once a contended get_lock() compares the mock clock against a give up
 * time taken from the real clock, it never returns. This factory refuses a lock that is
 * already held, at once and without looking at any clock.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class held_lock_factory implements lock_factory {
    /** @var array Keys of the locks held right now, across all instances. */
    private static array $held = [];

    /** @var string The type of lock this factory hands out. */
    private string $type;

    /**
     * Constructor.
     *
     * @param string $type
     */
    public function __construct($type) {
        $this->type = $type;
    }

    /**
     * Timeouts are not supported, a held lock is refused at once.
     *
     * @return bool
     */
    public function supports_timeout() {
        return false;
    }

    /**
     * Locks are not released when the process ends, the test releases them.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return false;
    }

    /**
     * Locks are not recursive: the same resource cannot be locked twice.
     *
     * @return bool
     */
    public function supports_recursion() {
        return false;
    }

    /**
     * Always available.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Hands out the lock unless it is held already.
     *
     * @param string $resource
     * @param int $timeout Ignored: a held lock is refused at once.
     * @param int $maxlifetime Ignored.
     * @return lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        $key = $this->type . '_' . $resource;
        if (isset(self::$held[$key])) {
            return false;
        }
        self::$held[$key] = true;
        return new lock($key, $this);
    }

    /**
     * Releases a lock.
     *
     * @param lock $lock
     * @return bool
     */
    public function release_lock(lock $lock) {
        unset(self::$held[$lock->get_key()]);
        return true;
    }

    /**
     * Extending is not supported.
     *
     * @param int $maxlifetime
     * @return bool
     */
    public function extend_lock($maxlifetime = 86400) {
        return false;
    }
}

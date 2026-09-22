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
 * Queues the sends of a released burst.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use core\lock\lock_config;
use mod_booking\local\bulk_check\bulk_check;

/**
 * Turns the releasing rows of one rule back into send tasks.
 *
 * A burst can hold thousands of sends, so the admin only moves them to releasing and this
 * picks the work up afterwards, a batch at a time. Anything left over is picked up by the
 * next run, so a burst of any size gets through without a single long request.
 *
 * Releasing a hand picked selection means there can be more than one of these tasks for the
 * same rule, so a worker only runs while it holds the lock of that rule. Without it two
 * workers can read the same releasing row before either has written its task id back, and
 * the send whose id was overwritten is then sent a second time.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class release_bulk_check extends \core\task\adhoc_task {
    /** @var int How many sends are queued per run. */
    private const BATCHSIZE = 500;

    /** @var int How many batches one run gets through before it hands back to cron. */
    private const MAXBATCHES = 10;

    /**
     * Queues the send tasks of everything waiting to be released.
     *
     * @return void
     */
    public function execute() {
        $data = (object) $this->get_custom_data();
        $ruleid = (int) ($data->ruleid ?? 0);
        if (empty($ruleid)) {
            return;
        }

        $factory = lock_config::get_lock_factory(bulk_check::LOCKTYPE);
        $lock = $factory->get_lock('release' . $ruleid, 0);
        if (!$lock) {
            // Another worker is draining this rule. Working alongside it would mean both of
            // them reading the same releasing row before either has written its task id
            // back, and the send whose id was overwritten would go out twice. Standing down
            // for good is no good either: the other worker only covers what was claimed
            // before it started its last batch. So come back once it is done.
            self::requeue($ruleid, time() + MINSECS);
            return;
        }

        try {
            for ($batch = 0; $batch < self::MAXBATCHES; $batch++) {
                $queued = bulk_check::queue_released_batch($ruleid, self::BATCHSIZE);
                if ($queued < self::BATCHSIZE) {
                    // The last batch was short, so there is nothing left to release.
                    return;
                }
            }
        } finally {
            $lock->release();
        }

        // Still more to do. Hand the rest to a fresh task rather than run on.
        self::requeue($ruleid, time());
    }

    /**
     * Hands one rule to a fresh task of this kind.
     *
     * @param int $ruleid
     * @param int $runtime
     * @return void
     */
    private static function requeue(int $ruleid, int $runtime): void {
        $task = new self();
        $task->set_custom_data(['ruleid' => $ruleid]);
        $task->set_next_run_time($runtime);
        \core\task\manager::queue_adhoc_task($task);
    }
}

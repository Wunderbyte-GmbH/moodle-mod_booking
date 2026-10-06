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

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\benchmark\benchmark_db_writer;
use core\task\scheduled_task;

/**
 * Scheduled task: purge benchmark runs older than retention period.
 * Baselines (is_baseline=1) are always kept.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_old_benchmark_runs_task extends scheduled_task {
    /**
     * Get the name of this scheduled task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_old_benchmark_runs', 'bookingextension_agent');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $days = (int)(get_config('bookingextension_agent', 'benchmark_retention_days') ?: 365);
        $writer = new benchmark_db_writer();
        $deleted = $writer->purge_old_runs($days);
        mtrace("Cleaned {$deleted} old benchmark run(s) (retention: {$days} days, baselines kept).");
    }
}

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
 * Adhoc task to run a benchmark from the interface.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\benchmark\benchmark_run_service;

/**
 * Runs a benchmark scenario set asynchronously (queued from benchmark_report.php).
 */
class run_benchmark_adhoc extends \core\task\adhoc_task {
    /**
     * Execute the task: run the benchmark with the queued options.
     *
     * @return void
     */
    public function execute(): void {
        $options = (array)$this->get_custom_data();

        $summary = (new benchmark_run_service())->run($options, static function (string $line): void {
            mtrace($line);
        });

        mtrace('bookingextension_agent benchmark run complete: '
            . 'id=' . (int)($summary['runid'] ?? 0)
            . ', passed=' . (int)($summary['passed'] ?? 0) . '/' . (int)($summary['total'] ?? 0)
            . ' (' . (float)($summary['success_rate'] ?? 0) . '%)'
            . ', ' . (int)($summary['duration_ms'] ?? 0) . 'ms'
            . ', embeddings=' . (!empty($summary['embeddings_used']) ? 'on' : 'off')
            . (!empty($summary['regression']) ? ' [REGRESSION]' : ''));
    }
}

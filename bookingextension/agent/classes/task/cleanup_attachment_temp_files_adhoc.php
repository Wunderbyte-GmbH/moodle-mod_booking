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

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;

/**
 * Scheduled task: remove expired attachment temp files.
 *
 * Scans the upload temp directory and deletes files older than the token TTL.
 * This is a safety-net on top of the per-token invalidation done inline.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_attachment_temp_files_adhoc extends \core\task\scheduled_task {
    /**
     * Return human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_attachment_temp_files', 'bookingextension_agent');
    }

    /**
     * Execute the cleanup.
     *
     * @return void
     */
    public function execute(): void {
        $svc = new attachment_token_service();
        $svc->cleanup_expired();
        mtrace('[bookingextension_agent] Attachment temp file cleanup completed.');
    }
}

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
 * Scheduled task: incrementally update the site-content embeddings index.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;

/**
 * Incrementally updates the site-content index for the enabled areas (per-area cursor, only changed
 * chunks are written) and prunes disabled areas. Self-guards (DB backend, provider), so it is a
 * cheap no-op while site search is off (the default).
 *
 * The class name is kept for historical reasons (task name lang string / task registration);
 * the underlying service is strictly incremental, not a rebuild.
 */
class rebuild_site_content_embeddings extends \core\task\scheduled_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_rebuild_site_content_embeddings', 'bookingextension_agent');
    }

    /**
     * Run the incremental update and trace its summary.
     *
     * @return void
     */
    public function execute(): void {
        $result = (new site_content_index_service())->update();
        mtrace('bookingextension_agent site_content index: ' . json_encode($result));
    }
}

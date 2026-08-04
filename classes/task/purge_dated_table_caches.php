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
 * Adhoc task purging the dated booking option table caches after a day rollover.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use cache_helper;

/**
 * Adhoc task purging the dated booking option table caches after a day rollover.
 *
 * The booking time SQL filter buckets its time params to the current day, so
 * the options WHERE clause - and with it every table cache key built from it -
 * changes at midnight. All entries created before the rollover carry the old
 * bucket in their keys and can never be read again; without a cleanup they pile
 * up as one dead generation per day. The booking time condition queues this
 * task once when it detects the rollover.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_dated_table_caches extends \core\task\adhoc_task {
    /**
     * Purge the caches holding day-bucketed table data.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('booking', 'usesqlfilteravailability')) {
            // The filter was switched off between queueing and execution.
            return;
        }

        // The raw options table data of mod_booking and the rendered tables of
        // the wunderbyte table library both key on the (dated) SQL.
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackencodedtables');

        mtrace('mod_booking: purged dated booking option table caches after day rollover.');
    }
}

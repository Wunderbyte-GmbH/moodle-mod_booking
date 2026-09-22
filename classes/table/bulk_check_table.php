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
 * Table of rule mails whose sending was parked by the bulk send checker.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

use cache_helper;
use context_system;
use html_writer;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\local\bulk_check\bulk_check;
use moodle_url;

/**
 * One row per mail that the bulk check stopped.
 *
 * Every row carries a checkbox, so that a burst can be let through for the people who should
 * get their mail after all while the rest is given up on. The two buttons that act on the
 * whole list are still there, because a burst can hold thousands of rows and ticking them one
 * page at a time is no way to deal with that.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_table extends wunderbyte_table {
    /** @var bool Every row can be picked out of the list. */
    public $addcheckboxes = true;

    /**
     * Name of the rule that queued the mail, or a placeholder when the rule was deleted.
     *
     * @param object $values
     * @return string
     */
    public function col_rulename($values): string {
        if (empty($values->rulename)) {
            return get_string('bulkcheckdeletedrule', 'mod_booking', $values->ruleid);
        }
        return format_string($values->rulename);
    }

    /**
     * Name of the booking option the mail is about, or a placeholder when it was deleted.
     *
     * @param object $values
     * @return string
     */
    public function col_optionname($values): string {
        if (empty($values->optionname)) {
            return get_string('bulkcheckdeletedoption', 'mod_booking', $values->optionid);
        }
        $name = format_string($values->optionname);
        if (empty($values->bookingid)) {
            return $name;
        }
        $cm = get_coursemodule_from_instance('booking', $values->bookingid);
        if (empty($cm)) {
            return $name;
        }
        $url = new moodle_url('/mod/booking/optionview.php', ['cmid' => $cm->id, 'optionid' => $values->optionid]);
        return html_writer::link($url, $name, ['target' => '_blank']);
    }

    /**
     * Who the mail was meant for.
     *
     * The column is sorted and searched on the concatenation built in the sql, but shown with
     * the name order of the site.
     *
     * @param object $values
     * @return string
     */
    public function col_recipient($values): string {
        return fullname($values);
    }

    /**
     * When the rule meant the mail to go out.
     *
     * @param object $values
     * @return string
     */
    public function col_sendtime($values): string {
        return userdate((int) $values->sendtime, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * How long this mail has been waiting for a decision.
     *
     * @param object $values
     * @return string
     */
    public function col_waiting($values): string {
        return html_writer::tag(
            'span',
            get_string('bulkcheckwaiting', 'mod_booking', format_time(time() - (int) $values->timemodified)),
            ['class' => 'text-muted small']
        );
    }

    /**
     * Lets the ticked mails go out after all.
     *
     * @param int $id Always -1, the buttons of this table work on the selection.
     * @param string $data
     * @return array
     */
    public function action_releaseselected(int $id, string $data): array {
        $count = bulk_check::release_rows($this->return_checked_ids($data));
        $this->purge_list();

        return [
            'success' => 1,
            'message' => get_string('bulkcheckreleasequeued', 'mod_booking', $count),
            // The library only reloads the table, but the buttons carry the totals as well.
            'reload' => 1,
        ];
    }

    /**
     * Gives up on the ticked mails.
     *
     * @param int $id Always -1, the buttons of this table work on the selection.
     * @param string $data
     * @return array
     */
    public function action_dismissselected(int $id, string $data): array {
        $count = bulk_check::dismiss_rows($this->return_checked_ids($data));
        $this->purge_list();

        return [
            'success' => 1,
            'message' => get_string('bulkcheckdismissed', 'mod_booking', $count),
            'reload' => 1,
        ];
    }

    /**
     * Lets every parked mail of the list go out after all.
     *
     * The scope is the one the page was built with, not what the list happens to show: the
     * action web service is told neither the filter nor the search, so a button that claimed
     * to act on what is on screen would be lying. The confirmation says so.
     *
     * @param int $id Always -1, the scope travels in the data.
     * @param string $data
     * @return array
     */
    public function action_releaseall(int $id, string $data): array {
        $ruleid = $this->return_scope($data);
        $count = empty($ruleid) ? bulk_check::release_all() : bulk_check::release_rule($ruleid);
        $this->purge_list();

        return [
            'success' => 1,
            'message' => get_string('bulkcheckreleasequeued', 'mod_booking', $count),
            'reload' => 1,
        ];
    }

    /**
     * Gives up on every parked mail of the list.
     *
     * @param int $id Always -1, the scope travels in the data.
     * @param string $data
     * @return array
     */
    public function action_dismissall(int $id, string $data): array {
        $ruleid = $this->return_scope($data);
        $count = empty($ruleid) ? bulk_check::dismiss_all() : bulk_check::dismiss_rule($ruleid);
        $this->purge_list();

        return [
            'success' => 1,
            'message' => get_string('bulkcheckdismissed', 'mod_booking', $count),
            'reload' => 1,
        ];
    }

    /**
     * The ids of the ticked rows, as far as they can be believed.
     *
     * The payload comes off the client raw, so it is only ever read for its numeric values.
     * Which of those name a row that may still be dealt with is decided in the database.
     *
     * @param string $data
     * @return array
     */
    private function return_checked_ids(string $data): array {
        $this->require_manage_bulk_check();

        $decoded = json_decode($data);
        $ids = $decoded->checkedids ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return $ids;
    }

    /**
     * The rule the buttons that act on everything are limited to, zero for all of them.
     *
     * @param string $data
     * @return int
     */
    private function return_scope(string $data): int {
        $this->require_manage_bulk_check();

        $decoded = json_decode($data);
        return (int) ($decoded->ruleid ?? 0);
    }

    /**
     * Every action of this table decides over mails, so every one of them is gated.
     *
     * @return void
     */
    private function require_manage_bulk_check(): void {
        require_capability('mod/booking:managebulkcheck', context_system::instance());
    }

    /**
     * Drops the cached list, so that what was just decided is gone from it.
     *
     * @return void
     */
    private function purge_list(): void {
        cache_helper::purge_by_event('setbackbulkcheckmails');
    }
}

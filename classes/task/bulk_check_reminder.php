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
 * Reminds the configured users about rule mails that are still parked.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use core\message\message;
use core_user;
use mod_booking\local\bulk_check\bulk_check;
use mod_booking\local\bulk_check\bulk_check_config;
use moodle_url;

/**
 * Nags about parked mails until they are released or dismissed.
 *
 * The block itself is announced once, at the moment it happens. That single mail is easy to
 * miss, and a parked burst that nobody looks at is mail the site silently swallowed, so this
 * keeps asking. It goes quiet by itself as soon as nothing is parked any more.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_reminder extends \core\task\scheduled_task {
    /**
     * Get's the name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbulkcheckreminder', 'mod_booking');
    }

    /**
     * Sends one digest of everything that is still parked.
     *
     * @return void
     */
    public function execute() {
        if (!bulk_check_config::is_enabled()) {
            return;
        }

        $bursts = bulk_check::get_parked_bursts();
        if (empty($bursts)) {
            return;
        }

        $total = 0;
        foreach ($bursts as $burst) {
            $total += (int) $burst->parked;
        }

        foreach (bulk_check_config::get_notify_userids() as $userid) {
            $userto = core_user::get_user($userid);
            if (empty($userto) || !empty($userto->deleted)) {
                continue;
            }
            $this->notify($userto, $bursts, $total);
        }
    }

    /**
     * Sends the digest to one user.
     *
     * @param \stdClass $userto
     * @param array $bursts
     * @param int $total
     * @return void
     */
    private function notify(\stdClass $userto, array $bursts, int $total): void {
        $stringmanager = get_string_manager();
        $lang = $userto->lang ?: current_language();

        $a = (object) [
            'bursts' => count($bursts),
            'total' => $total,
        ];
        $subject = $stringmanager->get_string('bulkcheckremindersubject', 'mod_booking', $a, $lang);

        $lines = [];
        foreach ($bursts as $burst) {
            $rulename = $burst->rulename ?? '';
            if ($rulename === '') {
                $rulename = $stringmanager->get_string('bulkcheckdeletedrule', 'mod_booking', $burst->ruleid, $lang);
            }
            $lines[] = $stringmanager->get_string('bulkcheckreminderline', 'mod_booking', (object) [
                'rule' => format_string($rulename),
                'ruleid' => $burst->ruleid,
                'parked' => $burst->parked,
                'waiting' => format_time(time() - (int) $burst->oldest),
            ], $lang);
        }

        $url = new moodle_url('/mod/booking/bulkcheck.php');
        $body = $stringmanager->get_string('bulkcheckreminderbody', 'mod_booking', $a, $lang)
            . '<ul><li>' . implode('</li><li>', $lines) . '</li></ul>'
            . \html_writer::link($url, $url->out(false));

        $msg = new message();
        $msg->component = 'mod_booking';
        $msg->name = 'bulkchecknotification';
        $msg->userfrom = core_user::get_noreply_user();
        $msg->userto = $userto;
        $msg->subject = $subject;
        $msg->fullmessage = html_to_text($body);
        $msg->fullmessageformat = FORMAT_HTML;
        $msg->fullmessagehtml = $body;
        $msg->smallmessage = $subject;
        $msg->notification = 1;
        $msg->contexturl = $url->out(false);
        $msg->contexturlname = $stringmanager->get_string('bulkcheckparked', 'mod_booking', null, $lang);

        message_send($msg);
    }
}

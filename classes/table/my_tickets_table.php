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
 * Table listing the entry tickets of a user.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

use html_writer;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Lists all entry tickets of a single user, newest first.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class my_tickets_table extends table_sql {
    /** @var int The user whose tickets are listed. */
    protected $userid;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param moodle_url $baseurl
     */
    public function __construct(int $userid, moodle_url $baseurl) {
        parent::__construct('mod_booking_my_tickets');

        $this->userid = $userid;

        $columns = ['optionname', 'coursestarttime', 'code', 'status', 'timecreated', 'download'];
        $headers = [
            get_string('bookingoptionname', 'mod_booking'),
            get_string('coursestarttime', 'mod_booking'),
            get_string('ticketcode', 'mod_booking'),
            get_string('status'),
            get_string('ticketissuedon', 'mod_booking'),
            get_string('ticketdownload', 'mod_booking'),
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->collapsible(false);
        $this->sortable(false);
        $this->no_sorting('download');
        $this->set_attribute('class', 'generaltable mod-booking-mytickets');

        $this->set_sql(
            'bt.id, bt.optionid, bt.userid, bt.code, bt.status, bt.timecreated, bt.timerevoked, bt.personalized,
             bo.text AS optionname, bo.titleprefix, bo.coursestarttime',
            '{booking_tickets} bt JOIN {booking_options} bo ON bo.id = bt.optionid',
            'bt.userid = :userid',
            ['userid' => $userid]
        );
        $this->sort_default_column = 'timecreated';
        $this->sort_default_order = SORT_DESC;
    }

    /**
     * Booking option name, linked to the option.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_optionname(stdClass $values): string {
        $name = $values->optionname;
        if (!empty($values->titleprefix)) {
            $name = $values->titleprefix . ' - ' . $name;
        }
        $name = format_string($name);

        $settings = singleton_service::get_instance_of_booking_option_settings((int) $values->optionid);
        if (empty($settings->cmid)) {
            return $name;
        }
        return html_writer::link(
            new moodle_url('/mod/booking/view.php', ['id' => $settings->cmid, 'optionid' => $values->optionid]),
            $name
        );
    }

    /**
     * Start time of the booking option.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_coursestarttime(stdClass $values): string {
        if (empty($values->coursestarttime)) {
            return '';
        }
        return userdate($values->coursestarttime, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Ticket status, with the cancellation date for cancelled tickets.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_status(stdClass $values): string {
        if (ticket_manager::is_cancelled($values)) {
            $label = html_writer::span(get_string('ticketstatuscancelled', 'mod_booking'), 'badge bg-danger text-white');
            if (!empty($values->timerevoked)) {
                $label .= ' ' . userdate($values->timerevoked, get_string('strftimedate', 'langconfig'));
            }
            return $label;
        }
        return html_writer::span(get_string('ticketstatusvalid', 'mod_booking'), 'badge bg-success text-white');
    }

    /**
     * Time the ticket was created.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_timecreated(stdClass $values): string {
        return userdate($values->timecreated, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Download link of the ticket PDF.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_download(stdClass $values): string {
        $url = ticket_manager::get_file_url($values);
        if (empty($url)) {
            // The PDF is created lazily, so a missing file is not an error.
            return html_writer::span(get_string('ticketnofile', 'mod_booking'), 'text-muted');
        }
        return html_writer::link(
            $url,
            html_writer::tag('i', '', ['class' => 'fa fa-fw fa-file-pdf-o', 'aria-hidden' => 'true'])
                . ' ' . get_string('ticketdownload', 'mod_booking'),
            ['target' => '_blank']
        );
    }

    /**
     * Public verification link of the ticket.
     *
     * @param stdClass $values
     *
     * @return string
     */
    public function col_code(stdClass $values): string {
        return html_writer::link(
            ticket_manager::get_verify_url($values),
            $values->code,
            ['target' => '_blank']
        );
    }
}

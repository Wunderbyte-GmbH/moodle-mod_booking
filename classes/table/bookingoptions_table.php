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

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../lib.php');
require_once($CFG->libdir.'/tablelib.php');

use coding_exception;
use dml_exception;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\output\col_action;
use mod_booking\output\col_availableplaces;
use mod_booking\output\col_price;
use mod_booking\output\col_text;
use mod_booking\output\col_teacher;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Search results for managers are shown in a table (student search results use the template searchresults_student).
 */
class bookingoptions_table extends wunderbyte_table {

    private $output = null;

    private $bookingsoptionsettings = [];

    private $booking = null;

    private $buyforuser = null;

    /**
     * Constructor
     * @param int $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     * @param booking $booking the booking instance
     */
    public function __construct($uniqueid, $booking = null) {
        parent::__construct($uniqueid);

        global $PAGE;
        $this->baseurl = $PAGE->url;

        if ($booking) {
            $this->booking = $booking;
        }

        $this->output = $PAGE->get_renderer('mod_booking');

        // we set the buy for user here for speed.

        $this->buyforuser = price::return_user_to_buy_for();

        // Columns and headers are not defined in constructor, in order to keep things as generic as possible.
    }

    /**
     * This function is called for each data row to allow processing of the
     * teacher value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_teacher($values) {

        // Render col_teacher using a template.
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);

        $data = new col_teacher($values->id, $settings);

        return $this->output->render_col_teacher($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * price value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_price($values) {

        // Render col_price using a template.
        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);

        // First we check if the user is booked already.
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings, $values->id);

        $bookingstatus = $bookinganswer->user_status($this->buyforuser->id);

        if ($bookingstatus == STATUSPARAM_BOOKED) {
            return get_string('booked', 'mod_booking');
        } else if ($bookingstatus == STATUSPARAM_WAITINGLIST) {
            return get_string('waitinglist', 'mod_booking');
        }

        // We pass on the id of the booking option.
        $data = new col_price($values, $settings, $this->buyforuser);

        return $this->output->render_col_price($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * text value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $string Return name of the booking option.
     * @throws dml_exception
     */
    public function col_text($values) {

        // We will have a number of modals on this site, therefore we have to distinguish them.
        $data = new stdClass();
        $data->modalcounter = $values->id;
        $data->modaltitle = $values->text;

        // We can go with the data from bookingoption_description directly to modal.
        return $this->output->render_col_text_modal_js($data);
    }


    /**
     * This function is called for each data row to allow processing of the
     * coursestarttime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $coursestarttime Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_bookings($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);
        // Render col_bookings using a template.
        $data = new col_availableplaces($values, $settings, $this->buyforuser);
        return $this->output->render_col_availableplaces($data);
    }

    /**
     * This function is called for each data row to allow processing of the
     * coursestarttime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $coursestarttime Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_location($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);

        return $settings->location;
    }

    /**
     * This function is called for each data row to allow processing of the
     * sports value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $sports Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_sports($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);

        if (isset($settings->customfields)
            && isset($settings->customfields['sport'])) {
                return implode(", ", $settings->customfields['sport']);
        }
        return '';
    }

    /**
     * This function is called for each data row to allow processing of the
     * dayofweek value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $dayofweek Returns course start time as a readable string.
     * @throws coding_exception
     */
    public function col_dayofweek($values) {

        $settings = singleton_service::get_instance_of_booking_option_settings($values->id);

        if (isset($settings->customfields['dayofweektime'])) {

            list($day, $starttime, $endtime) = explode('#', $settings->customfields['dayofweektime']);

            $dayofweektimeformatted = $day .' '
                . substr($starttime, 0, 2) . ':' . substr($starttime, 2, 2) . '-'
                . substr($endtime, 0, 2) . ':' . substr($endtime, 2, 2);

            return $dayofweektimeformatted;
        } else {
            return '';
        }
    }

    /**
     * This function is called for each data row to allow processing of the
     * courseendtime value.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $courseendtime Returns course end time as a readable string.
     * @throws coding_exception
     */
    public function col_coursedates($values) {

        // Prepare date string.
        if ($values->coursestarttime != 0) {
            $returnarray[] = userdate($values->coursestarttime, get_string('strftimedatetime'));
        }

        // Prepare date string.
        if ($values->courseendtime != 0) {
            $returnarray[] = userdate($values->courseendtime, get_string('strftimedatetime'));
        }

        return implode(' - ', $returnarray);
    }

    /**
     * This function is called for each data row to add a link
     * for managing responses (booking_answers).
     *
     * @param object $values Contains object with all the values of record.
     * @return string $link Returns a link to report.php (manage responses).
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_manageresponses($values) {
        global $CFG, $DB;

        // Link is empty on default.
        $link = '';

        if ($DB->get_records('booking_answers', ['optionid' => $values->optionid])) {
            // Add a link to redirect to the booking option.
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', array(
                'id' => $values->cmid,
                'optionid' => $values->optionid
            ));
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            $link = html_entity_decode($link->out());

            if (!$this->is_downloading()) {
                // Only format as a button if it's not an export.
                $link = '<a href="' . $link . '" class="btn btn-secondary">'
                    . get_string('bstmanageresponses', 'mod_booking')
                    . '</a>';
            }
        }
        // Do not show a link if there are no answers.

        return $link;
    }

    /**
     * This function is called for each data row to allow processing of the
     * action button.
     *
     * @param object $values Contains object with all the values of record.
     * @return string $action Returns formatted action button.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function col_action($values) {

        // Render col_action using a template.

        // Currently, this will use dummy teachers.
        $data = new col_action($values->id);

        return $this->output->render_col_action($data);
    }

    /**
     * Override wunderbyte_table function and use own renderer.
     *
     * @return void
     */
    public function finish_html() {
        $table = new \local_wunderbyte_table\output\table($this);
        echo $this->output->render_bookingoptions_table($table);
    }
}

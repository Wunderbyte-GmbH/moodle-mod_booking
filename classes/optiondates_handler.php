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

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use mod_booking\semester;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_handler {

    /** @var int $optionid */
    public $optionid = 0;

    /**
     * Constructor.
     * @param int $optionid
     */
    public function __construct(int $optionid = 0) {

        $this->optionid = $optionid;

    }

    /**
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_optiondates_for_semesters_to_mform(MoodleQuickForm &$mform) {

        $mform->addElement('header', 'headerdatesforsemester',
            get_string('datesforsemester', 'booking'));
        $mform->addElement('checkbox', 'includeholidays', 'includeholidays');
        $mform->addElement('select', 'semester', 'semester', array('WS22', 'WS23', 'SS22'));
        $mform->addElement('text', 'reocuringdatestring', get_string('reocuringdatestring', 'booking'));
        $mform->setType('reocuringdatestring', PARAM_TEXT);
        $mform->addElement('html', '<div class="datelist">');
        $mform->addElement('html', '</div>');
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found,moodle.Commenting.InlineComment.InvalidEndChar
        // TODO: ins option form: $this->add_action_buttons(false, 'load_dates');
    }


    public function save_from_form(stdClass $fromform) {
        global $DB;

        // TODO ...

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*foreach ($this->pricecategories as $pricecategory) {
            if (isset($fromform->{'pricegroup_' . $pricecategory->identifier})) {

                $pricegroup = $fromform->{'pricegroup_' . $pricecategory->identifier};

                $price = $pricegroup['bookingprice_' . $pricecategory->identifier];
                $categoryidentifier = $pricecategory->identifier;
                $currency = get_config('booking', 'globalcurrency');

                // If we retrieve a price record for this entry, we update if necessary.
                if ($data = $DB->get_record('booking_prices', ['optionid' => $fromform->optionid,
                    'pricecategoryidentifier' => $categoryidentifier])) {

                    if ($data->price != $price
                    || $data->pricecategoryidentifier != $categoryidentifier
                    || $data->currency != $currency) {

                        $data->price = $price;
                        $data->pricecategoryidentifier = $categoryidentifier;
                        $data->currency = $currency;
                        $DB->update_record('booking_prices', $data);
                    }
                } else { // If there is no price entry, we insert a new one.
                    $data = new stdClass();
                    $data->optionid = $fromform->optionid;
                    $data->pricecategoryidentifier = $categoryidentifier;
                    $data->price = $price;
                    $data->currency = $currency;
                    $DB->insert_record('booking_prices', $data);
                }

                // In any case, invalidate the cache after updating the booking option.
                // If performance is an issue, one could update only the cache of a this single option by key.
                // But right now, it seems reasonable to invalidate the cache from time to time.
                cache_helper::purge_by_event('setbackprices');
            }
        }*/
    }

    /**
     * Get date array for a specific weekday and time between two dates.
     * @param int $startdate
     * @param int $enddate
     * @param string $daystring
     * @return array
     */
    public function get_date_for_specific_day_between_dates(int $startdate, int $enddate, string $daystring): array {
        for ($i = strtotime($daystring, $startdate); $i <= $enddate; $i = strtotime('+1 week', $i)) {
            $date = new stdClass();
            $date->date = date('Y-m-d', $i);
            $date->starttime = '10:00';
            $date->endtime = '11:00';
            $date->string = $date->date . " " .$date->starttime. "-" .$date->endtime;
            $datearray['dates'][] = $date;
        }
        return $datearray;
    }

    /**
     * TODO: will be replaced by a regex function.
     * @param string $string
     * @return string
     */
    public function translate_string_to_day(string $string): string {
        if ($string == 'Monday') {
            return $string;
        }
        $lowerstring = strtolower($string);
        if (str_starts_with($lowerstring, 'mo')) {
            $day = 'Monday';
            return 'Monday';
        }
        if ($string == 'di' || $string == 'dienstag' || $string == 'tuesday' || $string == 'tu') {
            $day = 'Tuesday';
        }
        if ($string == 'mi' || $string == 'mittwoch' || $string == 'wednesday') {
            $day = 'Wednesday';
        }
        if ($string == 'do' || $string == 'donnerstag' || $string == 'thursday') {
            $day = 'Thursday';
        }
        if ($string == 'fr' || $string == 'freitag' || $string == 'friday') {
            $day = 'Friday';
        }
        if ($string == 'sa' || $string == 'saturday' || $string == 'samstag') {
            $day = 'Saturday';
        }
        if ($string == 'so' || $string == 'sonntag' || $string == 'sunday') {
            $day = 'Sunday';
        }
        return $day;
    }

    /**
     * TODO: delete this function and replace it with semester class.
     * @param int $semesterid
     * @return stdClass
     */
    public function get_semester(int $semesterid): stdClass {
        $semester = new stdClass();
        $semester->startdate = 1646598962;
        $semester->enddate = 1654505170;
        return $semester;
    }
}

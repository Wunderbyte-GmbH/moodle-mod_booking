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

use coding_exception;
use mod_booking\option\dates_handler;
use mod_booking\option\optiondate;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Handle dates
 * This class provides the form to handle dates (option dates)
 * Provides all the functionality linked to dates in booking and booking options.
 * @package mod_booking
 * @copyright 2023 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates {

    /**
     * Construct dates class
     *
     */
    public function __construct() {

    }

    /**
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     * @throws coding_exception
     */
    public static function definition_after_data(MoodleQuickForm &$mform, array $formdata) {

        // The default values are those we have just set via set_data.
        $defaultvalues = $mform->_defaultValues;

        // Here we take a look in all the transmitted information and sort out how many dates we will have.
        list($dates, $highestidx) = self::get_list_of_submitted_dates($defaultvalues);

        // Datesection for Dynamic Load.
        $elements[] = $mform->addElement('header', 'datesheader', get_string('dates', 'mod_booking'));
        $mform->setExpanded('datesheader');

        $bookingid = $formdata['bookingid'];
        $optionid = $formdata['optionid'];

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $semestersarray = semester::get_semesters_id_name_array();

        // We check if there are semsters at all...
        // ... and if on this particular instance a semesterid is set.
        if ((count($semestersarray) > 1) && !empty($bookingsettings->semesterid)) {

            $semesterid = null;
            $dayofweektime = '';
            if ($bookingoptionsettings) {
                $semesterid = $bookingoptionsettings->semesterid;
                $dayofweektime = $bookingoptionsettings->dayofweektime;
            }

            $semesteridoptions = [
                'tags' => false,
                'multiple' => false,
            ];

            $element = $mform->addElement('autocomplete', 'semesterid', get_string('chooseperiod', 'mod_booking'), $semestersarray, $semesteridoptions);
            $mform->addHelpButton('semesterid', 'chooseperiod', 'mod_booking');
            $mform->setType('semesterid', PARAM_INT);
            $element->setValue($semesterid);
            $elements[] = $element;

            $element = $mform->addElement('text', 'dayofweektime', get_string('reoccurringdatestring', 'mod_booking'));
            $mform->addHelpButton('dayofweektime', 'reoccurringdatestring', 'mod_booking');
            $mform->setType('dayofweektime', PARAM_TEXT);
            $element->setValue($dayofweektime);
            $elements[] = $element;

            // Button to attach JavaScript to reload the form.
            $mform->registerNoSubmitButton('addoptiondateseries');
            $elements[] = $mform->addElement('submit', 'addoptiondateseries', get_string('add_optiondate_series', 'mod_booking'),
                [
                'data-action' => 'addoptiondateseries',
            ]);

        }
        // If there are semesters defined (more than 0)
        // AND If instance has semesters
        // Semester selection is shown and prefilled with instance
        // AND weekdaystring is shown
        // AND create date series is shown.


        $datescounter = $defaultvalues["datescounter"];

        // The datescounter is the first element we add to the form.
        $element = $mform->addElement(
            'hidden',
            'datescounter',
            $datescounter,
        );
        $mform->setType('datescounter', PARAM_INT);
        $element->setValue($datescounter);
        $elements[] = $element;

        $now = time();

        // If we want to show more dates than we currently have...
        // ... we add them here.
        while ($datescounter > count($dates)) {
            $highestidx++;
            $dates[] = [
                'index' => $highestidx,
                'coursestarttime' => $now,
                'courseendtime' => $now,
            ];
        }

        if ($datescounter > 0) {
            self::add_dates_to_form($mform, $elements, $dates, $formdata);
        } else {
            self::add_no_dates_yet_to_form($mform, $elements, $dates, $formdata);
        }

        self::move_form_elements_to_the_right_place($mform, $elements);

    }

    /**
     *
     * @param stdClass $defaultvalues
     * @return void
     */
    public static function set_data(stdClass &$defaultvalues) {

        // The currently saved optiondates are already in the singleton. we can therefore access it via bo settings.
        // But we do this only when first loading the form.
        // When a nosubmit button is pressed (add, delete, edit) we only use data from the form.

        $datescounter = $defaultvalues->datescounter ?? 0;

        // If we have clicked on the create option date series, we recreate all option dates.
        if (isset($defaultvalues->addoptiondateseries)) {

            $newoptiondates = dates_handler::get_optiondate_series($defaultvalues->semesterid, $defaultvalues->dayofweektime);

            if (!empty($newoptiondates)) {
                $sessions = array_map(fn($a) =>
                (object)[
                    'optiondateid' => 0,
                    'coursestarttime' => $a->starttimestamp,
                    'courseendtime' => $a->endtimestamp,
                    'daystonotify' => $a->daystonotify,
                ], $newoptiondates['dates']);
            } else {
                $sessions = [];
            }

            $defaultvalues->datescounter = count($sessions);
        } else {
            $settings = singleton_service::get_instance_of_booking_option_settings($defaultvalues->optionid);
            $sessions = $settings->sessions;
            $defaultvalues->datescounter = $datescounter;
        }

        // First we modify the datescounter
        if (isset($defaultvalues->adddatebutton)) {
            $datescounter++;
        } else {

            // We might have clicked a delete nosubmit button.
            foreach ($defaultvalues as $key => $value) {
                if (strpos($key, 'deletedate_') !== false) {

                    // We want to show one element less.
                    $datescounter--;
                    break;
                    // We also need to delete the precise data.
                    list($name, $idx) = explode('_', $key);

                    unset($defaultvalues->{'optiondateid_' . $idx});
                    unset($defaultvalues->{'coursestarttime_' . $idx});
                    unset($defaultvalues->{'courseendtime_' . $idx});
                    break;
                }
            }
        }

        // If we load the form the first time datesmarker is not yet set.
        // Or if the create series is called.
        // Then we have to load the elements from the form.
        if (!isset($defaultvalues->datesmarker)
            || isset($defaultvalues->addoptiondateseries)) {

            $counter = 0;

            foreach ($sessions as $session) {
                $counter++;
                $key = 'optiondateid_' . $counter;
                $defaultvalues->{$key} = $session->optiondateid;
                $key = 'coursestarttime_' . $counter;
                $defaultvalues->{$key} = $session->coursestarttime;
                $key = 'courseendtime_' . $counter;
                $defaultvalues->{$key} = $session->courseendtime;
                $key = 'daystonotify_' . $counter;
                $defaultvalues->{$key} = $session->daystonotify;
            }

            $defaultvalues->datescounter = $counter;
        }

        return $defaultvalues;
    }

    public static function data_preprocessing($defaultvalues) {

    }

    /**
     * A way to get all the submitted course dates. Only supports up to 100.
     * Sorted for coursestarttime.
     * @param array $formvalues
     * @return array
     */
    public static function get_list_of_submitted_dates(array $formvalues) {

        $counter = 1;
        $dates = [];
        $highesindex = 1;

        // We can have up to 100 dates.
        while ($counter < 100) {
            if (isset($formvalues['optiondateid_' . $counter])) {

                if (is_array($formvalues['coursestarttime_' . $counter])) {
                    $coursestarttime = make_timestamp(...$formvalues['coursestarttime_' . $counter]);
                    $courseendtime = make_timestamp(...$formvalues['courseendtime_' . $counter]);
                } else {
                    $coursestarttime = $formvalues['coursestarttime_' . $counter];
                    $courseendtime = $formvalues['courseendtime_' . $counter];
                }

                $dates[] = [
                    'index' => $counter,
                    'optiondateid' => $formvalues['optiondateid_' . $counter],
                    'coursestarttime' => $coursestarttime,
                    'courseendtime' => $courseendtime,
                    'daystonotify' => $formvalues['daystonotify_' . $counter],
                ];
                $highesindex = $counter;
            }
            $counter++;
        }

        usort($dates, fn($a, $b) => $a['coursestarttime'] < $b['coursestarttime'] ? -1 : 1);

        return [array_values($dates), $highesindex];
    }

    /**
     *
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $date
     * @return void
     * @throws coding_exception
     */
    private static function add_date_form(MoodleQuickForm &$mform, array &$elements, array $date) {

        $idx = $date['index'];

        // Even when we don't have the edit button, we need to add this nosubmit...
        // Because of the switches in the form between edit & nonedit mode.
        $mform->registerNoSubmitButton('editdate_' . $idx);

        $elements[] =& $mform->addElement('date_time_selector', 'coursestarttime_' . $idx,
            get_string("coursestarttime", "booking"));
            $mform->setType('coursestarttime_' . $idx, PARAM_INT);
            $mform->disabledIf('coursestarttime_' . $idx, 'startendtimeknown', 'notchecked');

        $elements[] =& $mform->addElement('date_time_selector', 'courseendtime_' . $idx,
            get_string("courseendtime", "booking"));
        $mform->setType('courseendtime_' . $idx, PARAM_INT);
        $mform->disabledIf('courseendtime_' . $idx, 'startendtimeknown', 'notchecked');

        $mform->registerNoSubmitButton('deletedate_' . $idx);
        $elements[] = $mform->addElement(
            'submit',
            'deletedate_' . $idx,
            get_string("delete"), [
                'data-action' => 'deletedatebutton',
            ],
        );
    }

    /**
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     */
    public static function save_optiondates_from_form(stdClass $formdata, stdClass &$option) {

        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        list($newoptiondates, $highesindex) = self::get_list_of_submitted_dates((array)$formdata);
        $olddates = $settings->sessions;

        $datestosave = [];
        $datestodelete = [];

        foreach ($newoptiondates as $optiondate) {

            if (empty($optiondate['optiondateid'])) {
                $datestosave[] = $optiondate;
            } else if (isset($olddates[$optiondate['optiondateid']])) {

                $oldoptiondate = $olddates[$optiondate['optiondateid']];

                // If the dates are not exactly the same, we delete the old and save the new.
                // Else, we do nothing.
                if (!optiondate::compare_optiondates((array)$oldoptiondate, $optiondate)) {
                    // If one of the dates is not exactly the same, we need to delete the current option and add a new one.
                    $datestosave[] = $optiondate;
                    $datestodelete[] = $oldoptiondate;
                }
                unset($olddates[$oldoptiondate->id]);

            } else {
                // This would be sign of an error, sth went wrong.
                throw new moodle_exception('savingoptiondatewentwrong', 'mod_booking');
            }
        }

        $datestodelete = array_merge($olddates, $datestodelete);

        foreach ($datestodelete as $date) {
            $date = (array)$date;
            $DB->delete_records('booking_optiondates', ['id' => $date['optiondateid']]);
        }

        foreach ($datestosave as $date) {
            optiondate::save(
                $option->id,
                $date['coursestarttime'],
                $date['courseendtime'],
                $date['daystonotify']);
        }
    }

    /**
     *
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $date
     * @return void
     * @throws coding_exception
     */
    private static function add_date_as_string(MoodleQuickForm &$mform, array &$elements, array $date) {

        global $OUTPUT;

        $idx = $date['index'];

        if (gettype($date['coursestarttime']) == 'array') {
            $starttime = make_timestamp(...$date['coursestarttime']);
            $endtime = make_timestamp(...$date['courseendtime']);
        } else {
            $starttime = !empty($date['coursestarttime']) ? $date['coursestarttime'] : time();
            $endtime = !empty($date['courseendtime']) ? $date['courseendtime'] : time();
        }

        $headername = dates_handler::prettify_optiondates_start_end($starttime, $endtime);
        $headerid = 'booking_optiondate_' . $idx;
        $collapseid = 'booking_optiondate_collapse' . $idx;
        $accordionid = 'accordion_optionid_' . $idx;
        $headerdata = [
            'headername' => $headername,
            'headerid' => $headerid,
            'collapseid' => $collapseid,
            'accordionid' => $accordionid,
        ];

        $html2 = $OUTPUT->render_from_template('mod_booking/option/option_collapsible_close', []);
        $html1 = $OUTPUT->render_from_template('mod_booking/option/option_collapsible_open', $headerdata);

        $element = $mform->createElement('html', $html1);
        $element->setName('header_accordion_start_optiondate_' . $idx);
        $mform->addElement($element);
        $elements[] = $element;

        $elements[] =& $mform->addElement('date_time_selector', 'coursestarttime_' . $idx,
            get_string("coursestarttime", "booking"));
            $mform->setType('coursestarttime_' . $idx, PARAM_INT);
            $mform->disabledIf('coursestarttime_' . $idx, 'startendtimeknown', 'notchecked');

        $elements[] =& $mform->addElement('date_time_selector', 'courseendtime_' . $idx,
            get_string("courseendtime", "booking"));
        $mform->setType('courseendtime_' . $idx, PARAM_INT);
        $mform->disabledIf('courseendtime_' . $idx, 'startendtimeknown', 'notchecked');

        $elements[] =& $mform->addElement('text', 'daystonotify_' . $idx, get_string('daystonotifysession', 'mod_booking'));
        $mform->setType('daystonotify_' . $idx, PARAM_INT);
        $mform->addHelpButton('daystonotify_' . $idx, 'daystonotifysession', 'mod_booking');

        // $mform->registerNoSubmitButton('editdate_' . $idx);
        // $datearray[] =& $mform->createElement('submit', 'editdate_' . $idx, get_string('edit'));
        $mform->registerNoSubmitButton('deletedate_' . $idx);
        $datearray[] =& $mform->createElement('submit', 'deletedate_' . $idx, get_string('delete'));
        $elements[] =& $mform->addGroup($datearray, 'datearr_' . $idx, '', array(' '), false);

        $element = $mform->createElement('html', $html2);
        $element->setName('header_accordion_end_optiondate_' . $idx);
        $mform->addElement($element);
        $elements[] = $element;

    }

    /**
     * Elements are added after data, therefore they have to be moved to the right place in the form.
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @return void
     */
    private static function move_form_elements_to_the_right_place(MoodleQuickForm &$mform, array $elements) {
        foreach ($elements as $formelement) {

            $name = $formelement->getName();
            $value = $formelement->getValue();
            $formelement = $mform->insertElementBefore($mform->removeElement($name, false), 'datesmarker');
            if ($value !== null) {
                $formelement->setValue($value);
            }
        }
    }

    /**
     *
     * @param MoodleQuickForm $mform
     * @param array $dates
     * @param array $elements
     * @return void
     * @throws coding_exception
     */
    private static function add_dates_to_form(MoodleQuickForm &$mform, array &$elements, array $dates, array $formdata) {

        // We only want to open one date for editing at a time.
        $editted = false;

        // The default values are those we have just set via set_data.
        $defaultvalues = $mform->_defaultValues;

        foreach ($dates as $key => $date) {

            $idx = $date['index'];

            // If we just wanted to delete this date, just dont create the items for it.
            if (isset($defaultvalues['deletedate_' . $idx])) {

                $mform->registerNoSubmitButton('deletedate_' . $idx);

                continue;
            }

            $elements[] = $mform->addElement('hidden', 'optiondateid_' . $idx, 0);
            $mform->setType('optiondateid_' . $idx, PARAM_INT);

            // If we are on the last element and we just clicked "add", we print the form.
            if ((isset($defaultvalues['adddatebutton'])
                && (array_key_last($dates) == $key)
                && !$editted)
                || isset($defaultvalues['editdate_' . $idx])) {
                self::add_date_form($mform, $elements, $date);
                $editted = true;
            } else {
                self::add_date_as_string($mform, $elements, $date);
            }

        }

        // Button to attach JavaScript to reload the form.
        $mform->registerNoSubmitButton('adddatebutton');
        $elements[] = $mform->addElement('submit', 'adddatebutton', get_string('adddatebutton', 'mod_booking'),
            [
            'data-action' => 'adddatebutton',
        ]);
    }

    private static function add_no_dates_yet_to_form(MoodleQuickForm &$mform, array &$elements, array $dates, array $formdata) {

        $elements[] = $mform->addElement('static', 'nodatesmessage', '', get_string('nodateset', 'mod_booking'));

        // After deleting, we still need to register the right no delete button.
        // The default values are those we have just set via set_data.
        $defaultvalues = $mform->_defaultValues;
        foreach ($dates as $key => $date) {
            $idx = $date['index'];
            // If we just wanted to delete this date, just dont create the items for it.
            if (isset($defaultvalues['deletedate_' . $idx])) {
                $mform->registerNoSubmitButton('deletedate_' . $idx);
            }
        }

        // Button to attach JavaScript to reload the form.
        $mform->registerNoSubmitButton('adddatebutton');
        $elements[] = $mform->addElement('submit', 'adddatebutton', get_string('adddatebutton', 'mod_booking'),
            [
            'data-action' => 'adddatebutton',
        ]);
    }
}

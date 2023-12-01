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

use cache;
use coding_exception;
use mod_booking\option\dates_handler;
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
    public static function instance_form_definition(MoodleQuickForm &$mform, array $formdata) {

        // We add a coursestarttime & courseendtime button.
        /**
         * If there is no option date, we just have a "add date" button and a label which says: this course has no date yet.
         * When we click on "add date", we add an option date.
         * We can also remove the option date with a delete button.
         *
         * If there is no Semester defined, we don#t show the semester button elements.
         * If there is, we offer the create date series button and the weekday start end button.
         *
         *
         *
         *
         *
         */

        /**
         * Every optiondate consists of the optiondateid, which is the id in the bookiging_optiondates table.
         * Plus coursestarttime and courseendtime.
         */




        // At this point, there is no otherway to intercept a nosubmit value here.
        // But we need to register nosubmit buttons, as they won't register in definition after data.
        // Export Values would not work here, because the Elements we need are not yet in the form.
        // They are added only in definition after data.
        // But we can get the correct values via $_POST, because they are submitted via nosubmit button.



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

        $defaultvalues->datescounter = $datescounter;

        // If we load the form the first time datesmarker is not yet set.
        // Then we have to load the elements from the form.
        if (!isset($defaultvalues->datesmarker)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($defaultvalues->optionid);

            $counter = 0;

            foreach ($settings->sessions as $session) {
                $counter++;
                $key = 'optiondateid_' . $counter;
                $defaultvalues->{$key} = $session->optiondateid;
                $key = 'coursestarttime_' . $counter;
                $defaultvalues->{$key} = $session->coursestarttime;
                $key = 'courseendtime_' . $counter;
                $defaultvalues->{$key} = $session->courseendtime;
            }

            $defaultvalues->datescounter = $counter;
        }

        return $defaultvalues;
    }

    public static function data_preprocessing($defaultvalues) {

    }

    /**
     * A way to get all the submitted course dates. Only supports up to 100.
     * @param mixed $formvalues
     * @return array
     */
    public static function get_list_of_submitted_dates($formvalues) {

        $counter = 1;
        $dates = [];
        $highesindex = 1;

        // We can have up to 100 dates.
        while ($counter < 100) {
            if (isset($formvalues['optiondateid_' . $counter])) {
                $dates[] = [
                    'index' => $counter,
                    'coursestarttime' => $formvalues['coursestarttime_' . $counter],
                    'courseendtime' => $formvalues['courseendtime_' . $counter],
                ];
                $highesindex = $counter;
            }
            $counter++;
        }

        return [$dates, $highesindex];
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
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $date
     * @return void
     * @throws coding_exception
     */
    private static function add_date_as_string(MoodleQuickForm &$mform, array &$elements, array $date) {

        $idx = $date['index'];

        if (gettype($date['coursestarttime']) == 'array') {
            $starttime = make_timestamp(...$date['coursestarttime']);
            $endtime = make_timestamp(...$date['courseendtime']);
        } else {
            $starttime = !empty($date['coursestarttime']) ? $date['coursestarttime'] : time();
            $endtime = !empty($date['courseendtime']) ? $date['courseendtime'] : time();
        }

        $datearray = array();
        $datearray[] = $mform->createElement(
            'static',
            'datetext_' . $idx, '',
            dates_handler::prettify_optiondates_start_end($starttime, $endtime)
        );
        // We need to add these hidden elements to make sure we have the values on reload and save.
        $element = $mform->addElement('hidden', 'coursestarttime_' . $idx, $starttime);
        $element->setValue($starttime);
        $elements[] = $element;
        $element = $mform->addElement('hidden', 'courseendtime_' . $idx, $endtime);
        $element->setValue($endtime);
        $elements[] = $element;

        $mform->registerNoSubmitButton('editdate_' . $idx);
        $datearray[] =& $mform->createElement('submit', 'editdate_' . $idx, get_string('edit'));
        $mform->registerNoSubmitButton('deletedate_' . $idx);
        $datearray[] =& $mform->createElement('submit', 'deletedate_' . $idx, get_string('delete'));
        $elements[] =& $mform->addGroup($datearray, 'datearr_' . $idx, '', array(' '), false);

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

        $elements[] = $mform->addElement('static', 'nodatesmessage', '', get_string('nodates', 'mod_booking'));

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

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

        $elements = [];
        $optionid = $formdata['optionid'];
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $datescounter = $formdata['datescounter'];
        $sessions = $settings->sessions;

        // The datescounter is the first element we add to the form.
        $element = $mform->addElement(
            'hidden',
            'datescounter',
            $datescounter,
        );
        $mform->setType('datescounter', PARAM_INT);

        // First we check if we have submitted data.
        $data = $mform->getSubmitValues();

        // If we have clicked the no submit button to add a date...
        if (isset($data['adddatebutton'])) {
            $datescounter++;
        } else {

            // We might have clicked a delete nosubmit button.
            foreach ($data as $key => $value) {
                if (strpos($key, 'deletedate_') > 0) {
                    $datescounter--;
                }
            }
        }

        // After the correction of the dates counter, we can set the value.
        $element->setValue($datescounter);
        $elements[] = $element;

        if (empty($datescounter)) {
            $elements[] = $mform->addElement(
                'static',
                'nodatesstring',
                get_string('nodatesstring', 'mod_booking'),
                get_string('nodatesstring_desc', 'mod_booking'),
            );
        }

        $elements[] = $mform->addElement('submit', 'adddatebutton', get_string('adddatebutton', 'mod_booking'),
            [
            'data-action' => 'adddatebutton',
        ]);
        // Button to attach JavaScript to reload the form.
        $mform->registerNoSubmitButton('adddatebutton');

        $elements[] = $mform->addElement('checkbox', 'startendtimeknown',
            get_string('startendtimeknown', 'mod_booking'));

        $counter = 0;

        /**
         * Every optiondate consists of the optiondateid, which is the id in the bookiging_optiondates table.
         * Plus coursestarttime and courseendtime.
         */

        while ($counter < $datescounter) {
            $counter++;
            $session = null;

            if (count($sessions) > 0) {
                $session = array_shift($sessions);
            }

            $elements[] = $mform->addElement('hidden', 'optiondateid_' . $counter, 0);
            $mform->setType('optiondateid_' . $counter, PARAM_INT);

            $elements[] =& $mform->addElement('date_time_selector', 'coursestarttime_' . $counter,
            get_string("coursestarttime", "booking"));
            $mform->setType('coursestarttime_' . $counter, PARAM_INT);
            $mform->disabledIf('coursestarttime_' . $counter, 'startendtimeknown', 'notchecked');

            $elements[] =& $mform->addElement('date_time_selector', 'courseendtime_' . $counter,
                get_string("courseendtime", "booking"));
            $mform->setType('courseendtime_' . $counter, PARAM_INT);
            $mform->disabledIf('courseendtime_' . $counter, 'startendtimeknown', 'notchecked');

            $buttonarray = array();
            $buttonarray[] =& $mform->createElement('submit', 'deletedate_' . $counter, get_string('savechanges'));
            $buttonarray[] =& $mform->createElement('submit', 'deletedate_' . $counter, get_string('cancel'));
            $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);

            $elements[] = $mform->addElement('submit', 'deletedate_' . $counter,
                get_string("delete"));


            if (!empty($session)) {
                // $element = $mform->getElement('coursestarttime_' . $counter);
                // $element->setValue('yesno',  0);($session->coursestarttime);

                // $element = $mform->getElement('courseendtime_' . $counter);
                // $element->setDefault('yesno',  0);($session->courseendtime);

                $mform->setDefault('coursestarttime_' . $counter, $session->coursestarttime);
                $mform->setDefault('courseendtime_' . $counter, $session->courseendtime);
            }
        }



        // At this point, there is no otherway to intercept a nosubmit value here.
        // But we need to register nosubmit buttons, as they won't register in definition after data.
        // Export Values would not work here, because the Elements we need are not yet in the form.
        // They are added only in definition after data.
        // But we can get the correct values via $_POST, because they are submitted via nosubmit button.
        $datescounter = optional_param('datescounter', 0, PARAM_INT);

        $counter = 0;
        while ($counter <= $datescounter) {
            $mform->registerNoSubmitButton('deletedate_' . $counter);
            $counter++;
        }
    }

    public static function definition_after_data(MoodleQuickForm &$mform) {

    }

    /**
     *
     * @param stdClass $defaultvalues
     * @return void
     */
    public static function set_data(stdClass &$defaultvalues) {

        // The currently saved optiondates are already in the singleton. we can therefore access it via bo settings.

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

    public static function data_preprocessing($defaultvalues) {
        $settings = singleton_service::get_instance_of_booking_option_settings($defaultvalues->optionid);

        $counter = 0;

        $loadedvalues = new stdClass();

        foreach ($settings->sessions as $session) {
            $counter++;
            $key = 'optiondateid_' . $counter;
            $loadedvalues->{$key} = $session->optiondateid;
            $key = 'coursestarttime_' . $counter;
            $loadedvalues->{$key} = $session->coursestarttime;
            $key = 'courseendtime_' . $counter;
            $loadedvalues->{$key} = $session->courseendtime;
        }

        $loadedvalues->datescounter = $counter;

        foreach ($loadedvalues as $key => $value) {
            $_POST[$key] = $value;
        }
    }

}

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
 * Handle dates
 *
 * This class provides the form to handle dates (option dates)
 * Provides all the functionality linked to dates in booking and booking options.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use coding_exception;
use DateTime;
use local_entities\entitiesrelation_handler;
use mod_booking\customfield\optiondate_cfields;
use mod_booking\option\dates_handler;
use mod_booking\option\optiondate;
use mod_booking\option\time_handler;
use moodle_exception;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle dates
 *
 * This class provides the form to handle dates (option dates)
 * Provides all the functionality linked to dates in booking and booking options.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer <info@wunderbyte.at>
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
     * Definition after data
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     * @throws coding_exception
     */
    public static function definition_after_data(MoodleQuickForm &$mform, array $formdata) {
        global $CFG;

        // The default values are those we have just set via set_data.
        $defaultvalues = $mform->_defaultValues;

        // Here we take a look in all the transmitted information and sort out how many dates we will have.
        [$dates, $highestidx] = self::get_list_of_submitted_dates($defaultvalues);

        // Datesection for Dynamic Load.
        // By default, we collapse.
        if (!$keys = preg_grep('/^mform_isexpanded_id_datesheader_/', array_keys($formdata))) {
            $mform->setExpanded('datesheader', false);
        } else {
            $key = reset($keys);
            $mform->setExpanded('datesheader', (bool)$formdata[$key]);
        }

        $bookingid = $formdata['bookingid'] ?? 0;
        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $semestersarray = semester::get_semesters_id_name_array();

        // We check if there are semsters at all...
        // ... and if on this particular instance a semesterid is set.
        if ((count($semestersarray) >= 1) && !empty($bookingsettings->semesterid)) {
            $semesterid = null;
            $dayofweektime = '';
            if ($bookingoptionsettings) {
                // We only get it from bookingoptionsettings if it does not come via form data.
                $semesterid = $formdata['semesterid'] ?? $bookingoptionsettings->semesterid;
                $dayofweektime = $formdata['dayofweektime'] ?? $bookingoptionsettings->dayofweektime;
            }
            // Get semester from bookingsetting if it's still NULL.
            // But if it's 0, we keep it 0 because this might be intended!
            if (is_null($semesterid)) {
                $semesterid = $bookingsettings->semesterid;
            }

            $semesteridoptions = [
                'tags' => false,
                'multiple' => false,
            ];

            $element = $mform->addElement(
                'autocomplete',
                'semesterid',
                get_string('chooseperiod', 'mod_booking'),
                $semestersarray,
                $semesteridoptions
            );
            $mform->addHelpButton('semesterid', 'chooseperiod', 'mod_booking');
            $mform->setType('semesterid', PARAM_INT);
            $element->setValue($semesterid);
            $mform->hideIf('semesterid', 'selflearningcourse', 'eq', 1);
            $elements[] = $element;

            $element = $mform->addElement(
                'textarea',
                'dayofweektime',
                get_string('reoccurringdatestring', 'mod_booking'),
                'rows="3" cols="30"'
            );
            $mform->addHelpButton('dayofweektime', 'reoccurringdatestring', 'mod_booking');
            $mform->setType('dayofweektime', PARAM_TEXT);
            $element->setValue($dayofweektime);
            $mform->hideIf('dayofweektime', 'selflearningcourse', 'eq', 1);
            $elements[] = $element;

            $element = $mform->addElement(
                'static',
                'multipledayofweektimestringshint',
                '',
                '<i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;' .
                get_string('multipledayofweektimestringshint', 'mod_booking')
            );
            $mform->hideIf('multipledayofweektimestringshint', 'selflearningcourse', 'eq', 1);
            $elements[] = $element;

            // Button to attach JavaScript to reload the form.
            $mform->registerNoSubmitButton('addoptiondateseries');
            $elements[] = $mform->addElement(
                'submit',
                'addoptiondateseries',
                get_string('addoptiondateseries', 'mod_booking'),
                ['data-action' => 'addoptiondateseries']
            );
            $mform->hideIf('addoptiondateseries', 'selflearningcourse', 'eq', 1);
        }

        $datescounter = $defaultvalues["datescounter"] ?? 0;

        // The datescounter is the first element we add to the form.
        $element = $mform->addElement(
            'hidden',
            'datescounter',
            $datescounter,
        );
        $mform->setType('datescounter', PARAM_INT);
        /* $element->setValue($datescounter); */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        $elements[] = $element;

        $now = time();
        $nextfullhour = strtotime(date('Y-m-d H:00:00', $now)) + 3600;

        // If we want to show more dates than we currently have...
        // ... we add them here.
        while ($datescounter > count($dates)) {
            $highestidx++;
            $dates[] = [
                'index' => $highestidx,
                'coursestarttime' => $nextfullhour,
                'courseendtime' => $nextfullhour + 3600, // Default: 1 hour after start.
                'daystonotify' => 0,
            ];
        }

        $regexkey = '/^' . MOD_BOOKING_FORM_DELETEDATE . '/';
        // Before we add the other forms, we need to add the nosubmit in case of we just deleted an optiondate.
        $datestodelete = preg_grep($regexkey, array_keys((array)$defaultvalues));
        foreach ($datestodelete as $name) {
            [$name, $idx] = explode('_', $name);
            $mform->registerNoSubmitButton(MOD_BOOKING_FORM_DELETEDATE . $idx);
        }

        if ($datescounter > 0) {
            self::add_dates_to_form($mform, $elements, $dates, $formdata);
        } else {
            self::add_no_dates_yet_to_form($mform, $elements, $dates, $formdata);
        }

        self::move_form_elements_to_the_right_place($mform, $elements);
    }

    /**
     * Set data.
     * @param stdClass $defaultvalues
     * @return stdClass
     */
    public static function set_data(stdClass &$defaultvalues) {
        global $DB;

        // The currently saved optiondates are already in the singleton. we can therefore access it via bo settings.
        // But we do this only when first loading the form.
        // When a nosubmit button is pressed (add, delete, edit) we only use data from the form.

        $datescounter = $defaultvalues->datescounter ?? 0;
        $sessions = [];

        if (empty($defaultvalues->dayofweektime)) {
            if (
                (!empty($defaultvalues->starttime) && !empty($defaultvalues->endtime))
                || (!empty($defaultvalues->coursestarttime) && !empty($defaultvalues->courseendtime))
                || (!empty($defaultvalues->startdate) && !empty($defaultvalues->enddate))
                || (!empty($defaultvalues->coursestartdate) && !empty($defaultvalues->courseenddate))
            ) {
                // If there is no dayofweektime, we might have a single coursestartdate and courseeneddate.
                $starttime = $defaultvalues->starttime
                    ?? $defaultvalues->startdate
                    ?? $defaultvalues->coursestarttime
                    ?? $defaultvalues->coursestartdate;
                $endtime = $defaultvalues->endtime
                    ?? $defaultvalues->enddate
                    ?? $defaultvalues->courseendtime
                    ?? $defaultvalues->courseenddate;

                $defaultvalues->{MOD_BOOKING_FORM_OPTIONDATEID . 0} = 0;
                $defaultvalues->{MOD_BOOKING_FORM_COURSESTARTTIME . 0} = strtotime($starttime, time());
                $defaultvalues->{MOD_BOOKING_FORM_COURSEENDTIME . 0} = strtotime($endtime, time());
                $defaultvalues->{MOD_BOOKING_FORM_DAYSTONOTIFY . 0} = 0;
            }
        }

        // If we have clicked on the create option date series, we recreate all option dates.
        if (isset($defaultvalues->addoptiondateseries)) {
            // When creating new date series, we unset defaults for all customfields.
            $regexkey = '/^' . MOD_BOOKING_FORM_OPTIONDATEID . '/';
            $optiondates = preg_grep($regexkey, array_keys((array)$defaultvalues));
            foreach ($optiondates as $odate) {
                unset($defaultvalues->{$odate});
            }

            if (!empty($defaultvalues->semesterid)) {
                $newoptiondates = dates_handler::get_optiondate_series($defaultvalues->semesterid, $defaultvalues->dayofweektime);
            }

            if (!empty($newoptiondates)) {
                $sessions = array_map(fn($a) =>
                (object)[
                    'optiondateid' => 0,
                    'coursestarttime' => $a->starttimestamp,
                    'courseendtime' => $a->endtimestamp,
                    'daystonotify' => $a->daystonotify ?? 0,
                ], $newoptiondates['dates']);
            }

            $defaultvalues->datescounter = count($sessions);

            // When creating new date series, we unset defaults for all customfields.
            $regexkey = '/^customfield/';
            $customfields = preg_grep($regexkey, array_keys((array)$defaultvalues));
            foreach ($customfields as $cf) {
                unset($defaultvalues->{$cf});
            }

            // Also make sure, we delete all previous calendar events.
            // Delete course events for the optiondate.
            // Optionid and optiondateid are stored in uuid column like this: optionid-optiondateid.
            $DB->delete_records_select(
                'event',
                "eventtype = 'course'
                AND courseid <> 0
                AND component = 'mod_booking'
                AND uuid LIKE :pattern",
                ['pattern' => "{$defaultvalues->id}-%"]
            );
        } else if (!empty($defaultvalues->id)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($defaultvalues->id);
            // Make sure, no sessions are created for self-learning courses.
            if (
                empty($settings->selflearningcourse)
                && !isset($defaultvalues->coursestarttime_1)
            ) {
                $sessions = $settings->sessions;
            } else {
                $sessions = [];
            }
            $defaultvalues->datescounter = $datescounter;
        }

        // This Logic is linked to the webservice importer functionality.
        // We might need to add coursestartime and courseendtime as new session.
        if (
            !empty($defaultvalues->importing)
            && isset($defaultvalues->mergeparam)
            && !empty($sessions)
        ) {
            // If we are importing, we need to set back existing sessions, so we can avoid duplication.
            if (
                $defaultvalues->mergeparam <= 1
            ) {
                // If Param is 0 or 1, we will only have one session.
                $sessions = [];
            }

            if ($defaultvalues->mergeparam == 2) {
                // If we are importing via webservice and have already sessions in this option...
                // ... and we have already added new coursestarttime and courseendtime, we move them.
                $sessions[] = (object)[
                    'optiondateid' => 0,
                    'coursestarttime' => $defaultvalues->{MOD_BOOKING_FORM_COURSESTARTTIME . 0},
                    'courseendtime' => $defaultvalues->{MOD_BOOKING_FORM_COURSEENDTIME . 0},
                    'daystonotify' => 0,
                ];

                unset($defaultvalues->{MOD_BOOKING_FORM_OPTIONDATEID . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSESTARTTIME . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSEENDTIME . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_DAYSTONOTIFY . 0});
            } else if ($defaultvalues->mergeparam == 1) {
                // If this is the first date, we need to make sure that we delete all the other dates.
                $sessions = [];
            } else if ($defaultvalues->mergeparam == 3) {
                // 3 Means that we add other stuff, so we just leave the dates alone.
                unset($defaultvalues->{MOD_BOOKING_FORM_OPTIONDATEID . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSESTARTTIME . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSEENDTIME . 0});
                unset($defaultvalues->{MOD_BOOKING_FORM_DAYSTONOTIFY . 0});
            }
        }

        // We need to make sure we have all options already added to the form.
        $regexkey = '/^' . MOD_BOOKING_FORM_OPTIONDATEID . '/';
        $optiondates = preg_grep($regexkey, array_keys((array)$defaultvalues));
        $datescounter = count($optiondates);
        $defaultvalues->datescounter = $datescounter;

        // New optiondate is added.
        if (isset($defaultvalues->adddatebutton)) {
            // We have to increment datescounter.
            $datescounter++;

            if (class_exists('local_entities\entitiesrelation_handler')) {
                $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');

                // Set entity from booking option as default for entity of new optiondate.
                $defaultvalues->{LOCAL_ENTITIES_FORM_ENTITYID . $datescounter} = $defaultvalues->{LOCAL_ENTITIES_FORM_ENTITYID . 0};
            }

            $defaultvalues->datescounter = $datescounter;
        } else {
            // We might have clicked a delete nosubmit button.
            $regexkey = '/^' . MOD_BOOKING_FORM_DELETEDATE . '/';
            $datestodelete = preg_grep($regexkey, array_keys((array)$defaultvalues));

            foreach ($datestodelete as $name) {
                // We want to show one element less.
                $datescounter--;
                $defaultvalues->datescounter = $datescounter;
                // We also need to delete the precise data.
                [$name, $idx] = explode('_', $name);

                unset($defaultvalues->{MOD_BOOKING_FORM_OPTIONDATEID . $idx});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSESTARTTIME . $idx});
                unset($defaultvalues->{MOD_BOOKING_FORM_COURSEENDTIME . $idx});
                unset($defaultvalues->{MOD_BOOKING_FORM_DAYSTONOTIFY . $idx});
            }
        }

        // If we load the form the first time datesmarker is not yet set.
        // Or if the create series is called.
        // Then we have to load the elements from the form.
        if (
            !isset($defaultvalues->datesmarker)
            || isset($defaultvalues->addoptiondateseries)
        ) {
            $idx = 0;

            foreach ($sessions as $session) {
                // We might have entity relations for every session.

                $idx++;
                $key = MOD_BOOKING_FORM_OPTIONDATEID . $idx;
                $defaultvalues->{$key} = $session->optiondateid ?? 0;
                $key = MOD_BOOKING_FORM_COURSESTARTTIME . $idx;
                $defaultvalues->{$key} = $session->coursestarttime;
                $key = MOD_BOOKING_FORM_COURSEENDTIME . $idx;
                $defaultvalues->{$key} = $session->courseendtime;
                $key = MOD_BOOKING_FORM_DAYSTONOTIFY . $idx;
                $defaultvalues->{$key} = $session->daystonotify;

                // We might need to delete entities relation.
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');

                    // By default, we use the same entity as the main entity.
                    // This is helpful, when we recreate optiondate series for example.
                    $key = LOCAL_ENTITIES_FORM_ENTITYID . 0;
                    $entityid = $defaultvalues->{$key} ?? 0;

                    // If we fetch sessions from DB, we also have to load entities from DB.
                    if (empty($defaultvalues->addoptiondateseries) && !empty($session->optiondateid)) {
                        $existingentityidofoptiondate = $erhandler->get_entityid_by_instanceid($session->optiondateid);
                        if (!empty($existingentityidofoptiondate)) {
                            $entityid = $existingentityidofoptiondate;
                        }
                    }

                    $key = LOCAL_ENTITIES_FORM_ENTITYID . $idx;
                    $defaultvalues->{$key} = (int) $entityid;

                    $key = LOCAL_ENTITIES_FORM_ENTITYAREA . $idx;
                    $defaultvalues->{$key} = 'optiondate';
                }

                if (!empty($session->optiondateid)) {
                    optiondate_cfields::set_data($defaultvalues, $session->optiondateid, $idx);
                }
            }

            $defaultvalues->datescounter = $idx;
        }

        return $defaultvalues;
    }

    /**
     * Data preprocessing
     *
     * @param mixed $defaultvalues
     *
     * @return void
     *
     */
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
        $highestindex = 1;

        if (!$optiondates = preg_grep('/^optiondateid_/', array_keys($formvalues))) {
            // For performance.

            // There might be one more way data is submitted.
            if (!empty($formvalues["coursestarttime"]) && !empty($formvalues["courseendtime"])) {
                $dates[] = [
                    'id' => 0,
                    'index' => 0,
                    'optiondateid' => 0,
                    'coursestarttime' => $formvalues["coursestarttime"],
                    'courseendtime' => $formvalues["courseendtime"],
                    'daystonotify' => 0,
                    'entityid' => 0,
                    'entityarea' => 0,
                    'customfields' => [],
                ];
                $highestindex = 0;
            } else {
                return [[], 0];
            }
        }

        foreach ($optiondates as $optiondate) {
            [$a, $counter] = explode('_', $optiondate);

            if (isset($formvalues[MOD_BOOKING_FORM_OPTIONDATEID . $counter])) {
                if (is_array($formvalues[MOD_BOOKING_FORM_COURSESTARTTIME . $counter])) {
                    $coursestarttimearr = $formvalues[MOD_BOOKING_FORM_COURSESTARTTIME . $counter];
                    $courseendtimearr = $formvalues[MOD_BOOKING_FORM_COURSEENDTIME . $counter];
                    // Splat opreaton does not work with associative arrays in php < 8.
                    if (PHP_MAJOR_VERSION < 8) {
                        $coursestarttime = make_timestamp(
                            $coursestarttimearr['year'],
                            $coursestarttimearr['month'],
                            $coursestarttimearr['day'],
                            $coursestarttimearr['hour'],
                            $coursestarttimearr['minute'],
                        );
                        $courseendtime = make_timestamp(
                            $courseendtimearr['year'],
                            $courseendtimearr['month'],
                            $courseendtimearr['day'],
                            $courseendtimearr['hour'],
                            $courseendtimearr['minute'],
                        );
                    } else {
                        $coursestarttime = make_timestamp(...$coursestarttimearr);
                        $courseendtime = make_timestamp(...$courseendtimearr);
                    }
                } else {
                    $coursestarttime = $formvalues[MOD_BOOKING_FORM_COURSESTARTTIME . $counter];
                    $courseendtime = $formvalues[MOD_BOOKING_FORM_COURSEENDTIME . $counter];
                }

                // We might have entitites added.
                $entityid = '';
                $entityarea = '';
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    if (!empty($formvalues[LOCAL_ENTITIES_FORM_ENTITYID . $counter])) {
                        $entityid = $formvalues[LOCAL_ENTITIES_FORM_ENTITYID . $counter];
                    }
                    if (!empty($formvalues[LOCAL_ENTITIES_FORM_ENTITYAREA . $counter])) {
                        $entityarea = $formvalues[LOCAL_ENTITIES_FORM_ENTITYAREA . $counter];
                    }
                }

                // We might have cfields, we need to add them to our dates array here.
                $cffields = optiondate_cfields::get_list_of_submitted_cfields($formvalues, $counter);

                $dates[] = [
                    'id' => $formvalues[MOD_BOOKING_FORM_OPTIONDATEID . $counter],
                    'index' => $counter,
                    'optiondateid' => $formvalues[MOD_BOOKING_FORM_OPTIONDATEID . $counter],
                    'coursestarttime' => $coursestarttime,
                    'courseendtime' => $courseendtime,
                    'daystonotify' => $formvalues[MOD_BOOKING_FORM_DAYSTONOTIFY . $counter],
                    'entityid' => $entityid,
                    'entityarea' => $entityarea,
                    'customfields' => $cffields,
                ];
                $highestindex = $highestindex < $counter ? $counter : $highestindex;
            }
        }

        usort($dates, fn($a, $b) => $a['coursestarttime'] < $b['coursestarttime'] ? -1 : 1);

        return [array_values($dates), $highestindex];
    }

    /**
     * Save optiondates from form
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return array // Array of changes.
     */
    public static function save_optiondates_from_form(stdClass $formdata, stdClass &$option): array {

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        [$newoptiondates, $highestindex] = self::get_list_of_submitted_dates((array)$formdata);
        $olddates = $settings->sessions;
        // For the moment we don't save entities in old (to be deleted) dates since it's not considered as an important information.
        $memory = $olddates;

        $datestosave = [];
        $datestodelete = [];
        $datestoupdate = [];
        $newvalues = [];
        // Olddates don't contain location yet.
        foreach ($newoptiondates as $optiondate) {
            if (empty($optiondate['optiondateid'])) {
                $datestosave[] = $optiondate;
            } else if (isset($olddates[$optiondate['optiondateid']])) {
                $oldoptiondate = $olddates[$optiondate['optiondateid']];
                $oldoptiondate->customfields = optiondate_cfields::return_customfields_for_optiondate($optiondate['optiondateid']);

                // If the dates are not exactly the same, we delete the old and save the new.
                // Else, we do nothing.
                if (!optiondate::compare_optiondates((array)$oldoptiondate, $optiondate)) {
                    // If one of the dates is not exactly the same, we need to delete the current option and add a new one.
                    $datestoupdate[] = $optiondate;
                } else {
                    $newvalues[] = $optiondate;
                }
                unset($olddates[$oldoptiondate->id]); // Olddates is unset here but we still need it!
            } else {
                // This would be sign of an error, sth went wrong.
                throw new moodle_exception('savingoptiondatewentwrong', 'mod_booking');
            }
        }

        $datestodelete = array_merge($olddates, $datestodelete);
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $handler = new entitiesrelation_handler('mod_booking', 'optiondate');
        }

        foreach ($datestodelete as $date) {
            $date = (array)$date;

            if (!empty($date['optiondateid'])) {
                optiondate::delete($date['optiondateid']);
                // For tracking of changes, store infos about old optiondates including entities.
                if (
                    isset($date['optiondateid']) && isset($handler) &&
                    $data = $handler->get_instance_data($date['optiondateid'])
                ) {
                    $date['entityid'] = $data->id ?? 0;
                    $date['entityarea'] = $data->area ?? '';
                }
            }
        }

        // Saving and updating uses the same routines anyway.
        $datestosave = array_merge($datestosave, $datestoupdate);
        foreach ($datestosave as $date) {
            $optiondate = optiondate::save(
                (int)$date['optiondateid'] ?? 0,
                (int)$option->id,
                (int)$date['coursestarttime'],
                (int)$date['courseendtime'],
                (int)$date['daystonotify'],
                0,
                0,
                '',
                0,
                (int)$date['entityid'] ?? 0,
                $date['customfields'] ?? []
            );
        }

        if (empty($datestoupdate) && empty($datestodelete)) {
            $memory = [];
        } else {
            $memory = array_values($memory);
        }
        // Checking for changes.
        if (
            (empty($memory) && empty($datestosave))
            || in_array('dates', MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING)
        ) {
            return [];
        }

        if (!empty($formdata->selflearningcourse)) {
            return [];
        }

        $newvalue = array_merge($newvalues, $datestosave);
        $changes = [
            'changes' => [
                'fieldname' => 'dates',
                'oldvalue' => $memory,
                'newvalue' => $newvalue,
            ],
        ];
        return $changes;
    }

    /**
     * Add date as collapsible
     *
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $date
     * @param bool $expanded
     * @param array $formdata optional
     * @return void
     * @throws coding_exception
     */
    private static function add_date_as_collapsible(
        MoodleQuickForm &$mform,
        array &$elements,
        array $date,
        bool $expanded = false,
        array $formdata = []
    ) {

        global $OUTPUT;

        $idx = $date['index'];

        if (gettype($date['coursestarttime']) == 'array') {
            $starttime = make_timestamp(...$date['coursestarttime']);
            $endtime = make_timestamp(...$date['courseendtime']);
        } else {
            $starttime = !empty($date['coursestarttime']) ? $date['coursestarttime'] : time();
            $endtime = !empty($date['courseendtime']) ? $date['courseendtime'] : time() + 3600;
        }

        $headername = dates_handler::prettify_optiondates_start_end($starttime, $endtime, current_language());
        $headerid = 'booking_optiondate_' . $idx;
        $collapseid = 'booking_optiondate_collapse' . $idx;
        $accordionid = 'accordion_optionid_' . $idx;
        $headerdata = [
            'headername' => $headername,
            'headerid' => $headerid,
            'collapseid' => $collapseid,
            'accordionid' => $accordionid,
            'expanded' => $expanded,
        ];

        $html2 = $OUTPUT->render_from_template('mod_booking/option/option_collapsible_close', []);
        $html1 = $OUTPUT->render_from_template('mod_booking/option/option_collapsible_open', $headerdata);

        $element = $mform->createElement('html', $html1);
        $element->setName('header_accordion_start_optiondate_' . $idx);
        $mform->addElement($element);
        $elements[] = $element;

        $element = $mform->addElement(
            'date_time_selector',
            MOD_BOOKING_FORM_COURSESTARTTIME . $idx,
            get_string("coursestarttime", "booking"),
            time_handler::set_timeintervall(),
        );
        $mform->setType(MOD_BOOKING_FORM_COURSESTARTTIME . $idx, PARAM_INT);
        $time = self::timestamp_to_array($starttime);
        $element->setValue($time);
        $elements[] = $element;

        $element = $mform->addElement(
            'date_time_selector',
            MOD_BOOKING_FORM_COURSEENDTIME . $idx,
            get_string("courseendtime", "booking"),
            time_handler::set_timeintervall(),
        );
        $mform->setType(MOD_BOOKING_FORM_COURSEENDTIME . $idx, PARAM_INT);
        $time = self::timestamp_to_array($endtime);
        $element->setValue($time);
        $elements[] = $element;

        if (get_config('booking', 'uselegacymailtemplates')) {
            // Todo: Remove this in the future when mail template support is removed.
            $element = $mform->addElement(
                'text',
                MOD_BOOKING_FORM_DAYSTONOTIFY . $idx,
                get_string('daystonotifysession', 'mod_booking')
            );

            $mform->setType(MOD_BOOKING_FORM_DAYSTONOTIFY . $idx, PARAM_INT);
            $element->setValue($date['daystonotify']);
            $mform->addHelpButton(MOD_BOOKING_FORM_DAYSTONOTIFY . $idx, 'daystonotifysession', 'mod_booking');
            $elements[] = $element;
        } else {
            // This only applies, if we have session reminders in Booking Rules.
            // This is the new behavior without legacay mail templates.
            // If we have no session reminders in Booking Rules, we show a hint how to create them.
            $linktobookingrules = new moodle_url('/mod/booking/edit_rules.php');
            if (self::session_reminder_rule_exists($formdata["cmid"] ?? 0)) {
                $element = $mform->addElement(
                    'static',
                    'sessionremindersruleexists',
                    '',
                    '<i class="fa fa-tasks" aria-hidden="true"></i>&nbsp;' .
                    get_string('sessionremindersruleexists', 'mod_booking')
                );
                $elements[] = $element;

                // The days to notify from the rule can be overriden by each date.
                // If the value is 0, we use the number of days from the rule.
                $numberofdaysarray = booking::get_array_of_days_before_and_after(1, 30);
                $numberofdaysarray[0] = get_string('daystonotifysessionrulenooverride', 'mod_booking');
                ksort($numberofdaysarray); // We want to have 0 (no override) at the top.

                $element = $mform->addElement(
                    'select',
                    MOD_BOOKING_FORM_DAYSTONOTIFY . $idx,
                    get_string('daystonotifysessionruleoverride', 'mod_booking'),
                    $numberofdaysarray
                );
                $mform->setType(MOD_BOOKING_FORM_DAYSTONOTIFY . $idx, PARAM_INT);
                $element->setValue($date['daystonotify']);
                $mform->addHelpButton(MOD_BOOKING_FORM_DAYSTONOTIFY . $idx, 'daystonotifysessionruleoverride', 'mod_booking');
                $elements[] = $element;
            } else {
                $element = $mform->addElement(
                    'static',
                    'sessionremindershint',
                    '',
                    '<i class="fa fa-lightbulb-o" aria-hidden="true"></i>&nbsp;' .
                    get_string('sessionremindershint', 'mod_booking', $linktobookingrules)
                );
                $elements[] = $element;
            }
        }

        // Add entities.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
            $entityid = $date["entityid"] ?? 0;
            $entityid = (int)$entityid;
            $entitieselements = $erhandler->instance_form_definition($mform, $idx, false, null, null, $entityid);
            $elements = array_merge($elements, $entitieselements);
        }

        optiondate_cfields::instance_form_definition($mform, $elements, 1, $idx, $date['customfields'] ?? []);

        $mform->registerNoSubmitButton('applydate_' . $idx);
        $datearray[] =& $mform->createElement('submit', 'applydate_' . $idx, get_string('apply', 'mod_booking'));
        $mform->registerNoSubmitButton(MOD_BOOKING_FORM_DELETEDATE . $idx);
        $datearray[] =& $mform->createElement('submit', MOD_BOOKING_FORM_DELETEDATE . $idx, get_string('delete', 'mod_booking'));
        $elements[] =& $mform->addGroup($datearray, 'datearr_' . $idx, '', '', false);

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

            if ($mform->elementExists('datesmarker')) {
                $formelement = $mform->insertElementBefore($mform->removeElement($name, false), 'datesmarker');
                if ($value !== null) {
                    $formelement->setValue($value);
                }
            }
        }
    }

    /**
     * Add dates to form
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $dates
     * @param array $formdata
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

            $elements[] = $mform->addElement('hidden', MOD_BOOKING_FORM_OPTIONDATEID . $idx, 0);
            $mform->setType(MOD_BOOKING_FORM_OPTIONDATEID . $idx, PARAM_INT);

            // If we are on the last element and we just clicked "add", we print the form.
            if (
                (isset($defaultvalues['adddatebutton'])
                && (array_key_last($dates) == $key)
                && !$editted)
            ) {
                $editted = true;
            }

            self::add_date_as_collapsible($mform, $elements, $date, $editted, $formdata);
        }

        // Button to attach JavaScript to reload the form.
        $mform->registerNoSubmitButton('adddatebutton');
        $elements[] = $mform->addElement(
            'submit',
            'adddatebutton',
            get_string('adddatebutton', 'mod_booking'),
            ['data-action' => 'adddatebutton']
        );
        $mform->hideIf('adddatebutton', 'selflearningcourse', 'eq', 1);
    }

    /**
     * Add no dates yet to form
     *
     * @param MoodleQuickForm $mform
     * @param array $elements
     * @param array $dates
     * @param array $formdata
     *
     * @return void
     *
     */
    private static function add_no_dates_yet_to_form(MoodleQuickForm &$mform, array &$elements, array $dates, array $formdata) {

        $elements[] = $mform->addElement('static', 'nodatesmessage', '', get_string('datenotset', 'mod_booking'));
        $mform->hideIf('nodatesmessage', 'selflearningcourse', 'eq', 1);

        // After deleting, we still need to register the right no delete button.
        // The default values are those we have just set via set_data.
        $defaultvalues = $mform->_defaultValues;
        foreach ($dates as $key => $date) {
            $idx = $date['index'];
            // If we just wanted to delete this date, just dont create the items for it.
            if (isset($defaultvalues[MOD_BOOKING_FORM_DELETEDATE . $idx])) {
                $mform->registerNoSubmitButton(MOD_BOOKING_FORM_DELETEDATE . $idx);
            }
        }

        // Button to attach JavaScript to reload the form.
        $mform->registerNoSubmitButton('adddatebutton');
        $elements[] = $mform->addElement(
            'submit',
            'adddatebutton',
            get_string('adddatebutton', 'mod_booking'),
            ['data-action' => 'adddatebutton']
        );
        $mform->hideIf('adddatebutton', 'selflearningcourse', 'eq', 1);
    }

    /**
     * Transform a timestamp in an array to set value for datetimeselector.
     * @param int $timestamp
     * @return array
     * @throws coding_exception
     */
    private static function timestamp_to_array(int $timestamp) {

        $formatteddate = date('Y-m-d, H:i', $timestamp);
        $time = new DateTime(
            $formatteddate
        );

        $datearray = [
            'day' => [$time->format('d')],
            'month' => [$time->format('m')],
            'year' => [$time->format('Y')],
            'hour' => [$time->format('H')],
            'minute' => [$time->format('i')],
        ];

        return $datearray;
    }

    /**
     * Helper function to check if a session reminder rules already exists globally or for this instance.
     * @param int $cmid course module id of the booking instance
     * @return bool true if a session reminder rule exists in system context (globally) or for the given instance
     */
    private static function session_reminder_rule_exists(int $cmid): bool {
        global $DB;
        if (
            $DB->get_records_sql(
                "SELECT br.*, ctx.contextlevel, ctx.instanceid
                   FROM {booking_rules} br
                   JOIN {context} ctx
                     ON ctx.id = br.contextid
                  WHERE br.rulename = 'rule_daysbefore'
                    AND {$DB->sql_like('br.rulejson', ':optiondatestarttime', true)}
                    AND (
                        (ctx.contextlevel = :systemcontext AND ctx.instanceid = 0)
                        OR (ctx.contextlevel = :modulecontext AND ctx.instanceid = :cmid)
                    )",
                [
                    'optiondatestarttime' => '%optiondatestarttime%',
                    'systemcontext' => CONTEXT_SYSTEM,
                    'modulecontext' => CONTEXT_MODULE,
                    'cmid' => $cmid,
                ]
            )
        ) {
            return true;
        }
        return false;
    }
}

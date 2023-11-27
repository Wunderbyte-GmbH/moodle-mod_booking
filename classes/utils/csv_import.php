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
 * CSV import
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\utils;

use cache_helper;
use csv_import_reader;
use context_module;
use mod_booking\booking;
use stdClass;
use html_writer;
use local_entities\entitiesrelation_handler;
use mod_booking\customfield\booking_handler;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\teachers_handler;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/csvlib.class.php");

/**
 * Class csv_import
 * Import controller for CSV imports
 */
class csv_import {

    /**
     * @var booking $booking
     */
    protected $booking;

    /**
     * @var int $cmid
     */
    protected $cmid;

    /**
     * @var string
     */
    protected $delimiter = 'comma';

    /**
     * @var string
     */
    protected $enclosure = '';

    /**
     * @var string
     */
    protected $encoding = 'UTF-8';

    /**
     * @var array of column names
     */
    protected $columns = [];

    /**
     * @var array of fieldnames imported from csv
     */
    protected $fieldnames = [];

    /**
     * @var string with errors one per line
     */
    protected $csverrors = '';

    /**
     * @var object
     */
    protected $formdata = null;

    /**
     * @var string error message
     */
    protected $error = '';

    /**
     * @var array of fieldnames from other db tables than the booking_option table
     */
    protected $additionalfields = [];

    /**
     * @var array of objects
     */
    protected $customfields = [];

    public function __construct(booking $booking) {
        global $DB, $CFG;

        // Easy access for cmid and booking settings.
        $this->cmid = $booking->cmid;

        $this->columns = $DB->get_columns('booking_options');
        // Unset fields that can not be filled out be users.
        unset($this->columns['id']);
        unset($this->columns['bookingid']);
        unset($this->columns['groupid']);
        unset($this->columns['sent']);
        unset($this->columns['sent2']);
        unset($this->columns['sentteachers']);
        unset($this->columns['timemodified']);
        unset($this->columns['calendarid']);
        unset($this->columns['pollsend']);
        $this->booking = $booking;
        // If email is not unique, then allow adding email addresses for booked users and teachers.
        if (empty($CFG->allowaccountssameemail)) {
            $this->additionalfields[] = 'useremail';
            $this->additionalfields[] = 'teacheremail';
        }
        $this->additionalfields[] = 'user_username';
        // These csv-fields will be mapped to table column names: name->text, startdate->coursestartdate, enddate->courseenddate.
        $this->additionalfields[] = 'name';
        $this->additionalfields[] = 'startdate';
        $this->additionalfields[] = 'enddate';

        // Optiondates (Multisessionfields have to be added here.
        // Every multisession can have up to three customfields.
        for ($i = 1; $i < 7; ++$i) {

            $starttimekey = 'ms' . $i . 'starttime';
            $endtimekey = 'ms' . $i . 'endtime';
            $daystonotify = 'ms' . $i . 'nt';

            $this->additionalfields[] = $starttimekey;
            $this->additionalfields[] = $endtimekey;
            $this->additionalfields[] = $daystonotify;

            for ($j = 1; $j < 4; ++$j) {
                $cfname = 'ms' . $i . 'cf' . $j . 'name';
                $cfvalue = 'ms' . $i . 'cf' . $j . 'value';

                $this->additionalfields[] = $cfname;
                $this->additionalfields[] = $cfvalue;
            }
        }

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->customfields = booking_option::get_customfield_settings();
        // TODO: now only possible add fields of type text, select multiselect still to implement.
        // Add customfields.
        foreach ($this->customfields as $customfield) {
            if ($customfield['type'] == 'textfield') {
                $this->additionalfields[] = $customfield['value'];
            }
        }
        */
    }

    /**
     * Imports the csv data for booking options including user data (teachers and user bookings)
     *
     * @param $csvcontent
     * @param object $formdata
     * @return bool false when import failed, true when import worked. Line errors might have happend
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function process_data($csvcontent, $formdata = null) {

        global $DB, $CFG;

        // We need the lib for the teacher creation.
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $this->error = '';
        $this->formdata = $formdata;
        $iid = csv_import_reader::get_new_iid('modbooking');
        $cir = new csv_import_reader($iid, 'modbooking');

        $delimiter = !empty($this->formdata->delimiter_name) ? $this->formdata->delimiter_name : 'comma';
        $enclosure = !empty($this->formdata->enclosure) ? $this->formdata->enclosure : '"';
        $encoding = !empty($this->formdata->encoding) ? $this->formdata->encoding : 'utf-8';
        $updateexisting = !empty($this->formdata->updateexisting) ? $this->formdata->updateexisting : false;
        $readcount = $cir->load_csv_content($csvcontent, $encoding, $delimiter, null, $enclosure);

        if (empty($readcount)) {
            $this->error .= $cir->get_error();
            return false;
        }

        // Csv column headers.
        if (!$fieldnames = $cir->get_columns()) {
            $this->error .= $cir->get_error();
            return false;
        }
        $this->fieldnames = $fieldnames;
        $this->map_fieldnames();
        if (!empty($this->validate_fieldnames())) {
            $this->error .= $this->validate_fieldnames();
            return false;
        }

        $i = 0;
        $cir->init();
        while ($line = $cir->next()) {
            // Import option data (not user data). Do not update booking option if it exists.
            $csvrecord = array_combine($fieldnames, $line);
            $this->replace_csv_fieldnames($csvrecord);
            $bookingoption = new stdClass();
            $bookingoption->bookingid = $this->booking->id;
            if (empty($csvrecord['text'])) {
                // Collect line number with invalid data and track invalid lines.
                $this->add_csverror('No value set for booking option name (required)', $i);
            }

            // When there is an ID in location, we map it to the correct entity name.
            if (isset($csvrecord['location']) && is_numeric($csvrecord['location'])) {
                if (class_exists('local_entities\entitiesrelation_handler')) {
                    $eroptionhandler = new entitiesrelation_handler('mod_booking', 'option');
                    $entities = $eroptionhandler->get_entities_by_id($csvrecord['location']);
                    if (count($entities) === 1) {
                        $entity = reset($entities);
                        $csvrecord['location'] = $entity->name; // We store the name in location.
                    }
                }
            }

            $this->set_defaults($bookingoption);

            // Fetch a potentially existing booking option which will be updated.
            if (isset($csvrecord['identifier'])) {
                $existingoptions = $DB->get_records('booking_options', [
                    'identifier' => $csvrecord['identifier'],
                ]);
                if (empty($existingoptions)) {
                    // It's a new option with a new identifier.
                    $optionid = false;
                } else if (count($existingoptions) == 1) {
                    // Exactly one existing option was found.
                    $existingoption = array_pop($existingoptions);
                    // Check if the option is within the correct booking instance.
                    if ($existingoption->bookingid == $this->booking->id) {
                        $optionid = $existingoption->id;
                    } else {
                        $this->add_csverror(
                            "Identifier {$csvrecord['identifier']} is already in use within another booking instance!", $i);
                        $i++;
                        continue;
                    }
                } else if (count($existingoptions) > 1) {
                    $this->add_csverror("Identifier {$csvrecord['identifier']} is not unique!", $i);
                    $i++;
                    continue;
                }

            } else {
                $optionid = false;
            }

            if ($optionid) {
                $bookingoption->id = $optionid;
                // Unset all option fields in order to skip validation as existing data is used.
                foreach ($this->columns as $columname => $column) {

                    if ($columname == 'text') {
                        continue;
                    }

                    if (isset($csvrecord[$columname])) {
                        unset($csvrecord[$columname]);
                    }
                }
            }

            // Validate data.
            if ($this->validate_data($csvrecord, $i)) {

                // Save validated data to db.
                $userdata = [];
                foreach ($csvrecord as $column => $value) {
                    if ($column == 'useremail' || $column == 'teacheremail' || $column == 'user_username') {
                        $userdata[$column] = $value;
                    } else {
                        $this->prepare_data($column, $value, $bookingoption);
                    }
                }

                /* NOTE: This line is the reason why booking options will never get updated directly.
                We'll need to re-write the CSV importer, so updating also works correctly -
                see issue https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/310. */
                if ($optionid === false) {
                    /** @var context_module $context */
                    $context = $this->booking->get_context();
                    $optionid = booking_update_options($bookingoption, $context, MOD_BOOKING_UPDATE_OPTIONS_PARAM_IMPORT);
                }
                // Set the option id again in order to use it in prepare_data for user data.
                $bookingoption->id = $optionid;

                // Get the booking option settings class.
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

                // At this point, we can map the custom fields.
                $handler = booking_handler::create();
                foreach ($csvrecord as $column => $value) {
                    $handler->field_save($optionid, $column, $value);
                }

                // Now only run throught prices if there is a 'default' column.
                if (isset($csvrecord['default'])) {
                    $price = new price('option', $optionid);
                    foreach ($price->pricecategories as $category) {
                        if (isset($csvrecord[$category->identifier])) {
                            price::add_price('option', $optionid, $category->identifier, $csvrecord[$category->identifier]);
                        }
                    }
                }

                // We see if we can match the location to an entity.
                if (!empty($bookingoption->location)) {

                    // Now we check if we have an entity to which we can match the value.
                    if (class_exists('local_entities\entitiesrelation_handler')) {

                        $eroptionhandler = new entitiesrelation_handler('mod_booking', 'option');

                        // We already mapped it, so it will ALWAYS be a name (not an ID).
                        // It's the entity name (NOT shortname).
                        $entities = $eroptionhandler->get_entities_by_name($bookingoption->location);

                        // If we have exactly one entiity, we create the entities entry.
                        if (count($entities) === 1) {
                            $entity = reset($entities);

                            // If there are no booking options, we just save as normal.
                            $eroptionhandler->save_entity_relation($optionid, $entity->id);

                            // We also need to save the entity relation to the single sessions.
                            if (count($settings->sessions) > 0) {
                                // If there are booking option dates, we need to run through them.
                                // We need a new instance from the entities handler with a different area.
                                $eroptiondatehandler = new entitiesrelation_handler('mod_booking', 'optiondate');

                                foreach ($settings->sessions as $session) {
                                    $eroptiondatehandler->save_entity_relation($session->id, $entity->id);
                                }
                            }
                        }
                    }
                }

                // Finished option data, add user data to option...
                foreach ($userdata as $userfield => $value) {
                    $this->prepare_data($userfield, $value, $bookingoption);
                }
                if (isset($userdata['teacheremail'])) {

                    // First we explode teacheremail, there might be mulitple teachers.
                    // We always use comma as separator.
                    $teacheremails = explode(',', $userdata['teacheremail']);

                    foreach ($teacheremails as $teacheremail) {

                        // First, we trim the $teacheremail to make sure there are not whitespaces or linebreaks.
                        $teacheremail = trim($teacheremail);

                        // Now we check if the email exists as a user on the platform.
                        if (!$teacher = $DB->get_record('user',
                                            ['suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'email' => $teacheremail],
                                            'id', IGNORE_MULTIPLE)) {
                                $this->add_csverror(get_string('noteacherfound', 'booking', $i), $i);
                                continue;
                        }

                        // If we can't add the teacher, we add an error.
                        if (!subscribe_teacher_to_booking_option($teacher->id, $optionid, $settings->cmid)) {
                            $this->add_csverror(get_string('teachercouldntbeadded', 'booking', $i), $i);
                        }
                    }
                }

                if (isset($userdata['useremail'])) {

                    $sql = "SELECT *
                            FROM {user}
                            WHERE LOWER(email)=LOWER(:useremail)";

                    $user = $DB->get_record_sql($sql, ['useremail' => $userdata['useremail']]);

                    if ($user !== false) {

                        // Now we make sure we don't have suspended or otherwise no elegible users.
                        if ($user->suspended != 0) {
                            $this->add_csverror("The user with username {$user->username} and e-mail {$user->email} was
                            not subscribed to the booking option because of suspension", $i);
                            $i++;
                            continue;
                        }
                        if ($user->deleted != 0) {
                            $this->add_csverror("The user with username {$user->username} and e-mail {$user->email} was
                            not subscribed to the booking option because of deletion", $i);
                            $i++;
                            continue;
                        }
                        if ($user->confirmed != 1) {
                            $this->add_csverror("The user with username {$user->username} and e-mail {$user->email} was
                            not subscribed to the booking option because he/she is not confirmed", $i);
                            $i++;
                            continue;
                        }

                        $option = singleton_service::get_instance_of_booking_option($this->cmid, $optionid);
                        if ($option->user_submit_response($user, 0, 0, false, MOD_BOOKING_VERIFIED) === false) {
                            $this->add_csverror("The user with username {$user->username} and e-mail {$user->email} was
                            not subscribed to the booking option", $i);
                        }
                    } else {
                        $useremail = $userdata['useremail'];
                        $this->add_csverror("The user with the e-mail $useremail was
                            not found, couldn't be subscribed to booking option.", $i);
                    }
                }
                if (isset($userdata['user_username'])) {
                    $user = $DB->get_record('user',
                                ['suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'username' => $userdata['user_username']],
                                'id',
                                IGNORE_MULTIPLE);
                    if ($user !== false) {
                        $option = singleton_service::get_instance_of_booking_option($this->cmid, $optionid);
                        $option->user_submit_response($user, 0, 0, false, MOD_BOOKING_VERIFIED);
                    }
                }
            }
            $i++; // Increment CSV line counter.
        }
        $cir->cleanup(true);
        $cir->close();

        // We need to purge all caches to be sure display works correctly.
        cache_helper::purge_by_event('setbackoptionstable');
        // We also need to set back Wunderbyte table cache!
        cache_helper::purge_by_event('setbackencodedtables');

        return true;
    }

    /**
     * @return string line errors
     */
    public function get_error() {
        return $this->error;
    }

    /**
     * @return string line errors
     */
    public function get_line_errors() {
        return $this->csverrors;
    }

    /**
     * Add error message to $this->csverrors
     *
     * @param $errorstring
     */
    protected function add_csverror($errorstring, $i) {
        $this->csverrors .= html_writer::empty_tag('br');
        $this->csverrors .= "Error in line $i: ";
        $this->csverrors .= $errorstring;
    }

    /**
     * Prepare CSV values to be imported and saved to db.
     * Here we do necessary transforms etc.
     *
     * @param $column
     * @param $value
     * @param $bookingoption
     * @throws \dml_exception
     */
    protected function prepare_data($column, $value, &$bookingoption) {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($this->cmid);

        // Prepare custom fields.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*foreach ($this->customfields as $key => $customfield) {
            if ($customfield['value'] == $column) {
                $bookingoption->{$key} = $value;
            }
        } */

        // Check if column is in bookingoption fields, otherwise it is user data.
        if (array_key_exists($column, $this->columns) || in_array($column, $this->additionalfields)) {
            switch ($column) {
                case 'text':
                case 'description':
                case 'address':
                case 'location':
                    $bookingoption->$column = $this->fix_encoding($value);
                    break;
                case 'bookingclosingtime':
                    $bookingoption->restrictanswerperiodclosing = 1;
                    $bookingoption->$column = $this->get_timestamp($value);
                    break;
                case 'bookingopeningtime':
                    $bookingoption->restrictanswerperiodopening = 1;
                    $bookingoption->$column = $this->get_timestamp($value);
                    break;
                case 'coursestarttime':
                case 'courseendtime':
                    $bookingoption->startendtimeknown = 1;
                    $bookingoption->$column = $this->get_timestamp($value);
                    break;
                // For optiondates.
                case preg_match('/ms[1-3]starttime/', $column) ? $column : !$column:
                case preg_match('/ms[1-3]endtime/', $column) ? $column : !$column:
                    $bookingoption->startendtimeknown = 1;
                    $bookingoption->$column = $this->get_timestamp($value);
                    break;
                case 'institution':
                    // Create institution if it does not exist.
                    $bookingoption->institution = $this->fix_encoding($value);
                    break;
                case 'dayofweektime':
                    // Deal with option dates.

                    // So we can be sure that we use the right dates.
                    cache_helper::purge_by_event('setbacksemesters');

                    // We need to get the semester from the booking instance!
                    if (!empty($bookingsettings->semesterid)) {
                        $msdates = dates_handler::get_optiondate_series($bookingsettings->semesterid, $value);
                        $counter = 1;
                        if (isset($msdates['dates'])) {
                            foreach ($msdates['dates'] as $msdate) {
                                $startkey = 'ms' . $counter . 'starttime';
                                $endkey = 'ms' . $counter . 'endtime';
                                $bookingoption->$startkey = $msdate->starttimestamp;
                                $bookingoption->$endkey = $msdate->endtimestamp;
                                $counter++;
                            }
                        }
                    }
                    $bookingoption->$column = $value;
                    break;
                    // We don't need this, because values are not transformed.
                    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                    /*case preg_match('/ms[1-3]cf[1-3]name/', $column) ? $column : !$column:
                    case preg_match('/ms[1-3]cf[1-3]value/', $column) ? $column : !$column:
                    case preg_match('/ms[1-3]nt/', $column) ? $column : !$column:
                    $bookingoption->$column = $value;
                        break;*/
                default:
                    $bookingoption->$column = $value;
            }
        }
    }

    /**
     * Validate lines in csv data. Write it to csverrors.
     *
     * @param array $csvrecord
     * @param $linenumber
     * @return bool true on validation false on error
     */
    protected function validate_data(array &$csvrecord, $linenumber) {

        // Set to false if error occured in csv-line.
        if (empty($csvrecord['text'])) {
            $this->add_csverror('There seems to be an empty line.', $linenumber);
                    return false;
        }

        // Set to false if error occured in csv-line.
        if (isset($csvrecord['coursestarttime'])) {
            if (!is_null($this->formdata->dateparseformat)) {
                if (!date_create_from_format($this->formdata->dateparseformat, $csvrecord['coursestarttime']) &&
                    !strtotime($csvrecord['coursestarttime'])) {
                    $this->add_csverror('Startdate had a problem with the date format.', $linenumber);
                    return false;
                }
            }
        }
        if (isset($csvrecord['courseendtime'])) {
            if (!is_null($this->formdata->dateparseformat)) {
                if (!date_create_from_format($this->formdata->dateparseformat, $csvrecord['courseendtime']) &&
                    !strtotime($csvrecord['courseendtime'])) {
                    $this->add_csverror('Enddate hadd a problem with the date format.', $linenumber);
                    return false;
                }
            }
        }
        if (isset($csvrecord['bookingclosingtime'])) {
            if (!is_null($this->formdata->dateparseformat)) {
                if (!date_create_from_format($this->formdata->dateparseformat, $csvrecord['bookingclosingtime']) &&
                    !strtotime($csvrecord['bookingclosingtime'])) {
                    $this->add_csverror('Booking closing time hadd a problem with the date format.', $linenumber);
                    return false;
                }
            }
        }
        if (isset($csvrecord['bookingopeningtime'])) {
            if (!is_null($this->formdata->dateparseformat)) {
                if (!date_create_from_format($this->formdata->dateparseformat, $csvrecord['bookingopeningtime']) &&
                    !strtotime($csvrecord['bookingopeningtime'])) {
                    $this->add_csverror('Booking opening time hadd a problem with the date format.', $linenumber);
                    return false;
                }
            }
        }
        if (isset($csvrecord['institution'])) {
            if (empty($csvrecord['institution'])) {
                unset($csvrecord['institution']);
            }
        }
        if (isset($csvrecord['dayofweektime'])) {
            if (empty($csvrecord['dayofweektime'])) {
                unset($csvrecord['dayofweektime']);
            } else {
                if (!dates_handler::reoccurring_datestring_is_correct($csvrecord['dayofweektime'])) {
                    $this->add_csverror('The Recurring date string must be in the following format: ' .
                        '"Mo 10:00 - 12:00", not like this:' . $csvrecord['dayofweektime'], $linenumber);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Set default values.
     *
     * @param $bookingoption
     */
    protected function set_defaults(&$bookingoption) {
        $bookingoption->disablebookingusers = 0;
        $bookingoption->pollurl = "";
        $bookingoption->pollurlteachers = "";
        $bookingoption->beforebookedtext = "";
        $bookingoption->beforecompletedtext = "";
        $bookingoption->beforebookedtext = "";
        $bookingoption->aftercompletedtext = "";
        $bookingoption->duration = 0;
        $bookingoption->address = '';
        $bookingoption->courseid = 0;
        $bookingoption->limitanswers = 0;
        $bookingoption->maxoverbooking = 0;
        $bookingoption->minanswers = 0;
        $bookingoption->description = '';
        $bookingoption->institution = '';
        $bookingoption->invisible = 0;
        $bookingoption->annotation = '';
        $bookingoption->identifier = '';
        $bookingoption->titleprefix = '';
        $bookingoption->priceformulamultiply = 1;
        $bookingoption->priceformulaadd = 0;
        $bookingoption->priceformulaoff = 0;
    }

    /**
     * @return string empty if ok, errormsg if fieldname not correct in csv file.
     */
    protected function validate_fieldnames() {
        $error = '';
        // Validate fieldnames. If a field is not found error is returned.
        if (in_array('useremail', $this->fieldnames) && in_array('user_username', $this->fieldnames)) {
            $error .= "CSV was not imported. Reason: You must not set useremail AND user_username. Choose only one of them.";
        }
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* foreach ($this->fieldnames as $fieldname) {
            if (!array_key_exists($fieldname, $this->columns) AND !in_array($fieldname, $this->additionalfields)) {
                // $error .= "CSV was not imported. Reason: Invalid booking option setting in csv: {$fieldname}";
            }
        } */
        return $error;
    }

    /**
     * Return info which fields can be imported.
     *
     * @return string
     */
    public function display_importinfo() {
        $importinfo = "";
        foreach ($this->columns as $column) {
            $importinfo .= html_writer::empty_tag('br');
            $importinfo .= $column->name;
            switch ($column->name) {
                case 'text':
                    $importinfo .= ' (' . get_string('bookingoptionname', 'mod_booking') . ')';
                    break;
                case 'howmanyusers':
                    $importinfo .= ' (' . get_string('bookotheruserslimit', 'mod_booking') . ')';
                    break;
                case 'enrolmentstatus':
                    $importinfo .= ' (' . get_string('enrolmentstatus', 'mod_booking') . ')';
                    break;
            }
        }
        foreach ($this->additionalfields as $additionalfield) {
            $importinfo .= html_writer::empty_tag('br');
            $importinfo .= $additionalfield;

            switch ($additionalfield) {
                case 'name':
                    $importinfo .= ' (' . get_string('bookingoptionname', 'mod_booking') . ')';
                    break;
            }
        }
        return $importinfo;
    }

    /**
     * Map csv fieldnames with table column names.
     *
     */
    protected function map_fieldnames() {
        foreach ($this->fieldnames as $key => $fieldname) {
            switch ($fieldname) {
                case 'startdate':
                    $this->fieldnames[$key] = 'coursestarttime';
                    break;
                case 'enddate':
                    $this->fieldnames[$key] = 'courseendtime';
                    break;
            }
        }
    }

    /**
     * Replace csv fieldnames with table column names.
     *
     */
    protected function replace_csv_fieldnames(array &$csvrecord) {
        foreach ($csvrecord as $key => $value) {
            switch ($key) {
                case 'name':
                    $csvrecord['text'] = $value;
                    unset($csvrecord['name']);
                    break;
                case 'startdate':
                    $csvrecord['coursestarttime'] = $value;
                    unset($csvrecord['startdate']);
                    break;
                case 'enddate':
                    $csvrecord['courseendtime'] = $value;
                    unset($csvrecord['enddate']);
                    break;
            }
        }
    }

    /**
     * Fix encoding of CSV files for importing booking options.
     *
     * @param string $instr
     * @return string
     */
    public function fix_encoding($instr) {
        $curencoding = mb_detect_encoding($instr);
        if ($curencoding == "UTF-8" && mb_check_encoding($instr, "UTF-8")) {
            return $instr;
        } else {
            // Because utf8_encode() has been deprecated since php 8.2 .
            return mb_convert_encoding($instr, 'UTF-8', 'ISO-8859-1');
        }
    }

    /**
     * Returns the timestamp from the given value.
     * @param string $value
     * @return int
     */
    private function get_timestamp($value) {
        $date = date_create_from_format($this->formdata->dateparseformat, $value);
        if ($date) {
            $timestamp = $date->getTimestamp() ?? 0;
        } else {
            $timestamp = strtotime($value) ?? 0;
        }

        return $timestamp;
    }
}

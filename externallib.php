<?php

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
 * External Web Service Template
 *
 * @package    localbookingapi
 * @copyright  2014 Andraž Prinčič (http://www.princic.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir . "/filelib.php");
require_once($CFG->libdir . "/datalib.php");

class local_bookingapi_external extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function bookings_parameters() {
        return new external_function_parameters(
                array(
            'courseid' => new external_value(PARAM_TEXT, 'Course id', VALUE_DEFAULT, '0'),
            'printusers' => new external_value(PARAM_TEXT, 'Print user profiles', VALUE_DEFAULT, '0'),
            'days' => new external_value(PARAM_TEXT, 'How old bookings to retrive - in days.', VALUE_DEFAULT, '0')
                )
        );
    }

    public static function categories_parameters() {
        return new external_function_parameters(
                array(
            'courseid' => new external_value(PARAM_TEXT, 'Course id', VALUE_DEFAULT, '0')
                )
        );
    }

    public static function categories($courseid = '0') {
        global $DB;

        $returns = array();

        $allCategories = $DB->get_records('booking_category', array('course' => $courseid));
        foreach ($allCategories as $category) {
            $cat = array();

            $cat['id'] = $category->id;
            $cat['cid'] = $category->cid;
            $cat['name'] = $category->name;

            $returns[] = $cat;
        }

        return $returns;
    }

    public static function bookings($courseid = '0', $printusers = '0', $days = '0') {
        global $DB;

        $returns = array();

        //Parameter validation
        //REQUIRED
        $params = self::validate_parameters(self::bookings_parameters(), array('courseid' => $courseid, 'printusers' => $printusers, 'days' => $days));

        $options = 'course = ' . $courseid;

        $bookings = $DB->get_records_select("booking", $options);

        foreach ($bookings as $booking) {

            $ret = array();
            
            $options = 'bookingid = ' . $booking->id;

            if ($days > 0) {
                $timediff = strtotime('-' . $days . ' day');
                $options .= ' AND (coursestarttime = 0 OR coursestarttime  > ' . $timediff . ')';
            }

            $records = $DB->get_records_select('booking_options', $options, null, 'coursestarttime');
            //$records = $DB->get_records_select('booking_options', $options);

            $cm = get_coursemodule_from_instance('booking', $booking->id);
            
            if ($cm->visible == "1") {
                $context = context_module::instance($cm->id);

                $booking->cm = $cm;
                $booking->intro = file_rewrite_pluginfile_urls($booking->intro, 'pluginfile.php', $context->id, 'mod_booking', 'intro', null);

                $manager = $DB->get_record('user', array('username' => $booking->bookingmanager));
                
                $ret['id'] = $booking->id;
                $ret['cm'] = $booking->cm->id;
                $ret['name'] = $booking->name;
                $ret['intro'] = $booking->intro;
                $ret['duration'] = $booking->duration;
                $ret['points'] = $booking->points;
                $ret['organizatorname'] = $booking->organizatorname;
                $ret['eventtype'] = $booking->eventtype;
                $ret['bookingmanagername'] = $manager->firstname;
                $ret['bookingmanagersurname'] = $manager->lastname;
                $ret['bookingmanageremail'] = $manager->email;
                $ret['categories'] = array();
                $ret['options'] = array();

                $booking->categories = new stdClass();
                if ($booking->categoryid != '0' && $booking->categoryid != '') {
                    $categoryies = explode(',', $booking->categoryid);

                    if (!empty($categoryies) && count($categoryies) > 0) {
                        foreach ($categoryies as $category) {
                            $cat = array();
                            $cat['id'] = $category;
                            $cat['name'] = $DB->get_field('booking_category', 'name', array('id' => $category));

                            $ret['categories'][] = $cat;
                        }
                    }
                }

                $booking->all_categories = new stdClass();
                $allCategories = $DB->get_records('booking_category', array('course' => $courseid));
                foreach ($allCategories as $category) {
                    $booking->all_categories->{$category->id} = new stdClass();
                    $booking->all_categories->{$category->id} = $category;
                }

                $booking->booking_options = new stdClass();
                foreach ($records as $record) {
                    $option = array();
                    $option['id'] = $record->id;
                    $option['text'] = $record->text;
                    $option['maxanswers'] = $record->maxanswers;
                    $option['coursestarttime'] = $record->coursestarttime;
                    $option['courseendtime'] = $record->courseendtime;
                    $option['description'] = $record->description;
                    $option['location'] = $record->location;
                    $option['institution'] = $record->institution;
                    $option['address'] = $record->address;
                    $option['users'] = array();
                    $option['teachers'] = array();

                    if ($printusers) {
                        $booking->booking_options->{$record->id}->users = new stdClass();
                        $users = $DB->get_records('booking_answers', array('bookingid' => $record->bookingid, 'optionid' => $record->id));
                        foreach ($users as $user) {
                            $tmpUser = array();
                            $ruser = $DB->get_record('user', array('id' => $user->userid));
                            $tmpUser['id'] = $ruser->id;
                            $tmpUser['firstname'] = $ruser->firstname;
                            $tmpUser['lastname'] = $ruser->lastname;

                            $option['users'][] = $tmpUser;
                        }
                    }

                    $booking->booking_options->{$record->id}->teachers = new stdClass();
                    $users = $DB->get_records('booking_teachers', array('bookingid' => $record->bookingid, 'optionid' => $record->id));
                    foreach ($users as $user) {
                        $teacher = array();
                        $ruser = $DB->get_record('user', array('id' => $user->userid));
                        $teacher['id'] = $ruser->id;
                        $teacher['firstname'] = $ruser->firstname;
                        $teacher['lastname'] = $ruser->lastname;

                        $option['teachers'][] = $teacher;
                    }

                    $ret['options'][] = $option;
                }

                $returns[] = $ret;
            }
        }
        return $returns;
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function categories_returns() {
        return new external_multiple_structure(
                new external_single_structure(
                array(
            'id' => new external_value(PARAM_INT, 'Category ID'),
            'cid' => new external_value(PARAM_INT, 'Subcategory ID'),
            'name' => new external_value(PARAM_TEXT, 'Category name')
                )
                )
        );
    }

    public static function bookings_returns() {
        return new external_multiple_structure(
                new external_single_structure(
                array(
            'id' => new external_value(PARAM_INT, 'Booking ID'),
            'cm' => new external_value(PARAM_INT, 'CM'),
            'name' => new external_value(PARAM_TEXT, 'Course name'),
            'intro' => new external_value(PARAM_RAW, 'Description'),
            'duration' => new external_value(PARAM_TEXT, 'Duration'),
            'points' => new external_value(PARAM_RAW, 'Points'),
            'organizatorname' => new external_value(PARAM_TEXT, 'Organizator name'),
            'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
            'bookingmanagername' => new external_value(PARAM_TEXT, 'Booking manager name'),
            'bookingmanagersurname' => new external_value(PARAM_TEXT, 'Booking manager surname'),
            'bookingmanageremail' => new external_value(PARAM_TEXT, 'Booking manager e-mail'),
            'categories' => new external_multiple_structure(new external_single_structure(
                    array(
                'id' => new external_value(PARAM_INT, 'Category ID'),
                'name' => new external_value(PARAM_TEXT, 'Category name')
                    )
                    )),
            'options' => new external_multiple_structure(new external_single_structure(
                    array(
                'id' => new external_value(PARAM_INT, 'Option ID'),
                'text' => new external_value(PARAM_TEXT, 'Description'),
                'maxanswers' => new external_value(PARAM_INT, 'Max participants'),
                'coursestarttime' => new external_value(PARAM_INT, 'Start time'),
                'courseendtime' => new external_value(PARAM_INT, 'End time'),
                'description' => new external_value(PARAM_RAW, 'Description'),
                'location' => new external_value(PARAM_TEXT, 'Location'),
                'institution' => new external_value(PARAM_TEXT, 'Institution'),
                'address' => new external_value(PARAM_TEXT, 'Address'),
                'users' => new external_multiple_structure(new external_single_structure(
                        array(
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'First')
                        ))),
                'teachers' => new external_multiple_structure(new external_single_structure(
                        array(
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'First')
                        )))
                    )
                    ))
        )));
    }

}

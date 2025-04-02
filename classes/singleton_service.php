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

use core_user;
use Exception;
use local_entities\entitiesrelation_handler;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\booking_settings;
use stdClass;

/**
 * Singleton Service to improve performance.
 *
 * @package mod_booking
 * @since Moodle 3.11
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singleton_service {
    // Hold the class instance of the singleton service.

    /** @var singleton_service $instance */
    private static $instance = null;

    /** @var array $bookinganswers */
    public array $bookinganswers = [];

    /** @var array $bookinganswersforuser */
    public array $bookinganswersforuser = [];

    /** @var array $bookingsbycmid */
    public array $bookingsbycmid = [];

    /** @var array $bookingsbybookingid */
    public array $bookingsbybookingid = [];

    /** @var array $bookingsettingsbycmid */
    public array $bookingsettingsbycmid = [];

    /** @var array $bookingsettingsbybookingid */
    public array $bookingsettingsbybookingid = [];

    /** @var array $bookingoptions */
    public array $bookingoptions = [];

    /** @var array $bookingoptionsettings */
    public array $bookingoptionsettings = [];

    /** @var array $users */
    public array $users = [];

    /** @var array $prices */
    public array $prices = [];

    /** @var array $pricecategory */
    public array $pricecategory = [];

    /** @var array $userpricecategory */
    public array $userpricecategory = [];

    /** @var array $renderer */
    public array $renderer = [];

    /** @var array $campaigns */
    public array $campaigns = [];

    /** @var array $courses */
    public array $courses = [];

    /** @var array $cohorts */
    public array $cohorts = [];

    /** @var array $usercohorts */
    public array $usercohorts = [];

    /** @var array $entities */
    public array $entities = [];
    /** @var array $customfields */
    public array $customfields = [];

    /** @var array $index */
    public array $index = [];

    /** @var int $bookingmoduleid */
    public int $bookingmoduleid;

    /** @var array $allbookinginstances */
    public array $allbookinginstances;

    /** @var array $customfieldbyshortname */
    public array $customfieldbyshortname;

    /** @var array $sanitzedstringkey */
    public array $sanitzedstringkey;


    /**
     * Constructor
     *
     * The constructor is private to prevent initiation with outer code.
     *
     * @return void
     */
    private function __construct() {
        // The expensive process (e.g.,db connection) goes here.
    }

    /**
     * The object is created from within the class itself only if the class has no instance.
     *
     * @return singleton_service
     *
     */
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new singleton_service();
        }

        return self::$instance;
    }

    /**
     * Service to create and return singleton instance of booking answers.
     * @param booking_option_settings $settings
     * @return booking_answers
     */
    public static function get_instance_of_booking_answers($settings): booking_answers {

        $instance = self::get_instance();

        if (isset($instance->bookinganswers[$settings->id])) {
            return $instance->bookinganswers[$settings->id];
        } else {
            $bookinganswers = new booking_answers($settings);
            $instance->bookinganswers[$settings->id] = $bookinganswers;
            return $bookinganswers;
        }
    }

    /**
     * Service to store the array of answers in the singleton.
     * @param int $userid
     * @param int $bookingid
     * @return array
     */
    public static function get_answers_for_user(int $userid, int $bookingid): array {

        $instance = self::get_instance();

        if (isset($instance->bookinganswersforuser[$bookingid][$userid])) {
            return $instance->bookinganswersforuser[$bookingid][$userid];
        } else {
            return [];
        }
    }

    /**
     * Service to store the array of answers in the singleton.
     * @param int $userid
     * @param int $bookingid if not provided, 0 is used to destroy system wide.
     * @return array
     */
    public static function destroy_answers_for_user(int $userid, int $bookingid = 0): array {

        $instance = self::get_instance();
        if (empty($bookingid)) {
            // Without bookingid, we need to destroy all answers for the user.
            if (!empty($instance->bookinganswersforuser)) {
                foreach ($instance->bookinganswersforuser as $key => $value) {
                    if (isset($value[$userid])) {
                        unset($instance->bookinganswersforuser[$key][$userid]);
                    }
                }
            }
        } else if (isset($instance->bookinganswersforuser[$bookingid][$userid])) {
            // If a bookingid is provided, we need to destroy only the answers for that booking instance.
            unset($instance->bookinganswersforuser[$bookingid][$userid]);
        }
        return [];
    }

    /**
     * Service to store the array of answers in the singleton.
     * @param int $userid
     * @param int $bookingid
     * @param array $data
     * @return bool
     */
    public static function set_answers_for_user(int $userid, int $bookingid, array $data): bool {

        $instance = self::get_instance();

        $instance->bookinganswersforuser[$bookingid][$userid] = $data;

        return true;
    }

    /**
     * When invalidating the cache, we need to also destroy the booking_settings (instance settings).
     * As we batch handle a lot of users, they always need a "clean" booking (instance) settings object.
     *
     * @param int $cmid course module id
     * @return bool
     */
    public static function destroy_booking_singleton_by_cmid($cmid) {
        $instance = self::get_instance();

        $bookingsettings = self::get_instance_of_booking_settings_by_cmid($cmid);
        $bookingid = $bookingsettings->id;

        if (
            isset($instance->bookingsbycmid[$cmid])
            || isset($instance->bookingsbybookingid[$bookingid])
            || isset($instance->bookingsettingsbycmid[$cmid])
            || isset($instance->bookingsettingsbybookingid[$bookingid])
        ) {
            unset($instance->bookingsbycmid[$cmid]);
            unset($instance->bookingsbybookingid[$bookingid]);
            unset($instance->bookingsettingsbycmid[$cmid]);
            unset($instance->bookingsettingsbybookingid[$bookingid]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * When invalidating the cache, we need to also destroy the booking_option_settings.
     * As we batch handle a lot of users, they always need a "clean" booking option settings object.
     *
     * @param int $optionid
     * @return bool
     */
    public static function destroy_booking_option_singleton($optionid) {
        $instance = self::get_instance();

        if (
            isset($instance->bookingoptionsettings[$optionid])
            || isset($instance->bookingoptions[$optionid])
        ) {
            unset($instance->bookingoptionsettings[$optionid]);
            unset($instance->bookingoptions[$optionid]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * When invalidating the cache, we need to also destroy the booking_answer_object.
     * As we batch handle a lot of users, they always need a "clean" booking answers object.
     *
     * This will also destory the list of currently booked answers for users.
     *
     * @param int $optionid
     * @return bool
     */
    public static function destroy_booking_answers($optionid) {
        $instance = self::get_instance();

        if (isset($instance->bookinganswers[$optionid])) {
            unset($instance->bookinganswers[$optionid]);

            return true;
        } else {
            return false;
        }
    }

    /**
     * When invalidating the cache, we need to also destroy the singleton of the user who booked.
     * @param int $bookingid
     * @param int $userid
     * @return bool
     */
    public static function destroy_booking_answers_for_user_in_booking_instance(int $bookingid, int $userid) {
        $instance = self::get_instance();

        if (isset($instance->bookinganswersforuser[$bookingid][$userid])) {
            unset($instance->bookinganswersforuser[$bookingid][$userid]);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Service to create and return singleton instance of booking by cmid.
     *
     * @param int $cmid
     * @return booking|null
     */
    public static function get_instance_of_booking_by_cmid(int $cmid) {

        $instance = self::get_instance();

        if (isset($instance->bookingsbycmid[$cmid])) {
            return $instance->bookingsbycmid[$cmid];
        } else {
            // Before instating the new booking, we need to make sure that it already exists.
            try {
                $booking = new booking($cmid);
                $instance->bookingsbycmid[$cmid] = $booking;
                return $booking;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    /**
     * Service to create and return singleton instance of booking by bookingid.
     *
     * @param int $bookingid
     * @return booking|null
     */
    public static function get_instance_of_booking_by_bookingid(int $bookingid) {

        if (empty($bookingid)) {
            return null;
        }

        $instance = self::get_instance();

        if (isset($instance->bookingsbybookingid[$bookingid])) {
            return $instance->bookingsbybookingid[$bookingid];
        } else {
            $cm = get_coursemodule_from_instance('booking', $bookingid);

            $booking = new booking($cm->id);
            $instance->bookingsbybookingid[$bookingid] = $booking;
            return $booking;
        }
    }

    /**
     * Service to create and return singleton instance of booking.
     *
     * @param int $optionid
     *
     * @return booking
     */
    public static function get_instance_of_booking_by_optionid(int $optionid): booking {

        $bookingoptionsettings = self::get_instance_of_booking_option_settings($optionid);

        return self::get_instance_of_booking_by_bookingid($bookingoptionsettings->bookingid);
    }

    /**
     * Service to create and return singleton instance of booking by cmid.
     *
     * @param int $cmid
     *
     * @return booking_settings
     */
    public static function get_instance_of_booking_settings_by_cmid(int $cmid): booking_settings {
        $instance = self::get_instance();

        if (isset($instance->bookingsettingsbycmid[$cmid])) {
            return $instance->bookingsettingsbycmid[$cmid];
        } else {
            $settings = new booking_settings($cmid);
            $instance->bookingsettingsbycmid[$cmid] = $settings;
            return $settings;
        }
    }

    /**
     * Service to create and return singleton instance of booking by bookingid.
     *
     * @param int $bookingid
     *
     * @return booking_settings|null
     */
    public static function get_instance_of_booking_settings_by_bookingid(int $bookingid) {

        if (empty($bookingid)) {
            return null;
        }

        $instance = self::get_instance();

        if (isset($instance->bookingsettingsbybookingid[$bookingid])) {
            return $instance->bookingsettingsbybookingid[$bookingid];
        } else {
            try {
                $cm = get_coursemodule_from_instance('booking', $bookingid);

                $settings = new booking_settings($cm->id);
                $instance->bookingsettingsbybookingid[$bookingid] = $settings;
                return $settings;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    /**
     * Service to create and return singleton instance of booking_option.
     *
     * @param int $cmid
     * @param int $optionid
     *
     * @return booking_option|null
     */
    public static function get_instance_of_booking_option(int $cmid, int $optionid) {

        $instance = self::get_instance();

        if (isset($instance->bookingoptions[$optionid])) {
            return $instance->bookingoptions[$optionid];
        } else {
            try {
                $option = new booking_option($cmid, $optionid);
                $instance->bookingoptions[$optionid] = $option;
                return $option;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    /**
     * Service to create and return singleton instance of booking_option_settings.
     *
     * @param int $optionid
     * @param ?stdClass $dbrecord
     *
     * @return booking_option_settings
     */
    public static function get_instance_of_booking_option_settings($optionid, ?stdClass $dbrecord = null): booking_option_settings {
        $instance = self::get_instance();

        if (empty($optionid)) {
            return new booking_option_settings(0);
        }

        if (isset($instance->bookingoptionsettings[$optionid])) {
            return $instance->bookingoptionsettings[$optionid];
        } else {
            $settings = new booking_option_settings($optionid, $dbrecord);
            $instance->bookingoptionsettings[$optionid] = $settings;
            return $settings;
        }
    }

    /**
     * Service to create and return singleton instance of Moodle user.
     *
     * @param int $userid
     * @param bool $includeprofilefields
     *
     * @return stdClass
     */
    public static function get_instance_of_user(int $userid, bool $includeprofilefields = false) {
        global $CFG;
        $instance = self::get_instance();

        if (isset($instance->users[$userid])) {
            if ($includeprofilefields && !isset($instance->users[$userid]->profile)) {
                require_once("{$CFG->dirroot}/user/profile/lib.php");
                profile_load_custom_fields($instance->users[$userid]);
            }
            return $instance->users[$userid];
        } else {
            $user = core_user::get_user($userid);
            if ($includeprofilefields) {
                require_once("{$CFG->dirroot}/user/profile/lib.php");
                profile_load_custom_fields($user);
            }
            $instance->users[$userid] = $user;
            return $user;
        }
    }


    /**
     * When invalidating the cache, we need to also destroy the booking_users_object.
     * As we batch handle a lot of users, they always need a "clean" booking users object.
     *
     * @param int $userid
     * @return bool
     */
    public static function destroy_user(int $userid) {
        $instance = self::get_instance();

        if (isset($instance->users[$userid])) {
            unset($instance->users[$userid]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Service to create and return singleton instance of price class.
     *
     * @param int $optionid
     *
     * @return price
     */
    public static function get_instance_of_price($optionid) {
        $instance = self::get_instance();

        if (isset($instance->prices[$optionid])) {
            return $instance->prices[$optionid];
        } else {
            $price = new price('option', $optionid);
            $instance->prices[$optionid] = $price;
            return $price;
        }
    }

    /**
     * Get pricecategory from singleton service.
     * This function does not automatically get the right category but needs the setter function below to be useful.
     *
     * @param string $identifier
     *
     * @return mixed
     */
    public static function get_price_category($identifier) {
        $instance = self::get_instance();

        if (isset($instance->pricecategory[$identifier])) {
            return $instance->pricecategory[$identifier];
        } else {
            return false;
        }
    }

    /**
     * Get the price category for a single user.
     * @param mixed $user
     *
     * @return mixed
     */
    public static function get_pricecategory_for_user($user) {

        if (empty($user->id)) {
            return false;
        }

        $instance = self::get_instance();

        if (isset($instance->userpricecategory[$user->id])) {
            return $instance->userpricecategory[$user->id];
        } else {
            $category = price::get_pricecategory_for_user($user);
            $instance->userpricecategory[$user->id] = $category;
            return $category;
        }
    }

    /**
     * Set pricecategory to singleton service.
     *
     * @param string $identifier
     * @param stdClass|null $pricecategory
     *
     * @return bool
     */
    public static function set_price_category($identifier, $pricecategory) {
        $instance = self::get_instance();

        $instance->pricecategory[$identifier] = $pricecategory;
        return true;
    }

    /**
     * Sets and gets renderer instance.
     *
     * @param string $renderername
     * @return singleton_service::$renderer
     */
    public static function get_renderer(string $renderername) {

        global $PAGE;

        $instance = self::get_instance();

        if (!isset($instance->renderer[$renderername])) {
            $render = $PAGE->get_renderer($renderername);
            $instance->renderer[$renderername] = $render;
        }

        return $instance->renderer[$renderername];
    }

    /**
     * Fetch campaigns if there are not there already.
     * @return array
     */
    public static function get_all_campaigns(): array {

        global $DB;

        $instance = self::get_instance();

        if (empty($instance->campaigns)) {
            $campaigns = $DB->get_records('booking_campaigns');

            if (!$campaigns || empty($campaigns)) {
                $instance->campaigns = [];
            } else {
                $instance->campaigns = $campaigns;
            }
        }

        return (array)$instance->campaigns;
    }

    /**
     * Fetch campaigns if there are not there already.
     * @return array
     */
    public static function destroy_all_campaigns(): array {
        $instance = self::get_instance();
        unset($instance->campaigns);

        return [];
    }

    /**
     * Delete campaigns from singleton.
     * @param int $id
     * @return array
     */
    public static function reset_campaigns($id = 0): array {

        $instance = self::get_instance();

        if (empty($id)) {
            $instance->campaigns = [];
        } else {
            unset($instance->campaigns[$id]);
        }

        return (array)$instance->campaigns;
    }

    /**
     * Return course with given id.
     * Returns false if course does not exist anymore.
     *
     * @param int $courseid
     * @return object|bool
     */
    public static function get_course(int $courseid) {

        global $DB;

        $instance = self::get_instance();

        if (!isset($instance->courses[$courseid])) {
            if (!$course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING)) {
                return false;
            }
            $instance->courses[$courseid] = $course;
        }

        return $instance->courses[$courseid];
    }

    /**
     * Return course with given id.
     *
     * @param int $cohortid
     * @return object
     */
    public static function get_cohort(int $cohortid): object {

        global $DB;

        $instance = self::get_instance();

        if (!isset($instance->cohorts[$cohortid])) {
            $cohort = $DB->get_record('cohort', ['id' => $cohortid], '*', IGNORE_MISSING);
            $instance->cohorts[$cohortid] = $cohort;
        }

        return $instance->cohorts[$cohortid] ?: new stdClass();
    }

    /**
     * Return cohorts of a given user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_cohorts_of_user(int $userid): array {

        $instance = self::get_instance();

        if (!isset($instance->usercohorts[$userid])) {
            $usercohorts = cohort_get_user_cohorts($userid);
            $instance->usercohorts[$userid] = $usercohorts;
        }

        return $instance->usercohorts[$userid];
    }

    /**
     * Return entity object by id.
     *
     * @param int $id
     *
     * @return object
     *
     */
    public static function get_entity_by_id(int $id) {
        $instance = self::get_instance();

        if (!isset($instance->entities[$id])) {
            $instance->entities[$id] = entitiesrelation_handler::get_entities_by_id($id);
        }

        return $instance->entities[$id] ?: new stdClass();
    }
    /**
     * We store the options of the customfield.
     *
     * @param int $fieldid
     *
     * @return array
     *
     */
    public static function get_customfields_select_options(int $fieldid): array {

        global $DB;

        $customfields = [];
        $instance = self::get_instance();

        if (!isset($instance->customfields[$fieldid])) {
            $field = $DB->get_record('customfield_field', ['id' => $fieldid], 'configdata');
            $configdata = json_decode($field->configdata, true);

            $options = $configdata['options'];
            $optionlist = explode("\n", $options);
            $counter = 1;

            foreach ($optionlist as $option) {
                $option =

                $customfields[$counter] = trim($option);
                $counter++;
            }

            $instance->customfields[$fieldid] = $customfields;
        }

        return $instance->customfields[$fieldid];
    }

    /**
     * Returns ascending index for userids.
     *
     * @param string $uniqueid
     * @param string $indexid
     *
     * @return int
     *
     */
    public static function get_index_number(string $uniqueid, string $indexid): int {
        $instance = self::get_instance();

        if (!isset($instance->index[$uniqueid])) {
            $instance->index[$uniqueid] = [
                'counter' => 1,
            ];
            $instance->index[$uniqueid][$indexid] = 1;
        } else if (!isset($instance->index[$uniqueid][$indexid])) {
            $instance->index[$uniqueid]['counter']++;
            $instance->index[$uniqueid][$indexid] = $instance->index[$uniqueid]['counter'];
        }

        return $instance->index[$uniqueid][$indexid];
    }

    /**
     * Return id of booking module.
     *
     * @return int|mixed
     *
     */
    public static function get_id_of_booking_module() {
        $instance = self::get_instance();

        if (!isset($instance->bookingmoduleid)) {
            global $DB;

            $bookingmoduleid = $DB->get_record('modules', ['name' => 'booking'], 'id');

            $instance->bookingmoduleid = $bookingmoduleid->id;
        }

        return $instance->bookingmoduleid;
    }

    /**
     * Return array of all bookinginstance objects.
     *
     * @return array
     *
     */
    public static function get_all_booking_instances() {
        $instance = self::get_instance();

        if (!isset($instance->allbookinginstances)) {
            global $DB;

            $bookinginstances = $DB->get_records('booking');

            $instance->allbookinginstances = $bookinginstances;
        }

        return $instance->allbookinginstances;
    }

    /**
     * [Description for get_customfield_field_by_shortname]
     *
     * @param string $field
     *
     * @return object
     *
     */
    public static function get_customfield_field_by_shortname(string $field) {
        $instance = self::get_instance();

        if (!isset($instance->customfieldbyshortname[$field])) {
            global $DB;

            $record = $DB->get_record('customfield_field', ['shortname' => $field]);

            $instance->customfieldbyshortname[$field] = $record;
        }

        return $instance->customfieldbyshortname[$field];
    }

    /**
     * Destroys the singleton entirely.
     *
     * @return bool
     */
    public static function destroy_instance() {
        self::$instance = null;
        return true;
    }
}

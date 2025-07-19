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
 * Module booking data generator
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\output\view;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\booking_option;
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\singleton_service;
use mod_booking\semester;
use mod_booking\bo_availability\bo_info;
use mod_booking\price as Mod_bookingPrice;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use mod_booking\bo_actions\actions_info;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class to handle module booking data generator
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2023 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_generator extends testing_module_generator {
    /**
     *
     * @var int keep track of how many booking options have been created.
     */
    protected $bookingoptions = 0;

    /**
     * To be called from data reset code only, do not use in tests.
     *
     * @return void
     */
    public function reset() {
        $this->bookingoptions = 0;

        parent::reset();
    }

    /**
     * Create booking instance
     *
     * @param mixed|null $record
     * @param array|null $options
     *
     * @return stdClass
     *
     */
    public function create_instance($record = null, ?array $options = null) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $record = (object) (array) $record;

        $defaultsettings = [
            'assessed' => 0,
            'showviews' => 'showall,showactive,mybooking,myoptions,optionsiamresponsiblefor,myinstitution',
            'whichview' => 'showall',
            'optionsfields' => 'description,statusdescription,teacher,showdates,dayofweektime,
                                location,institution,minanswers',
            'reportsfields' => 'optionid,booking,institution,location,coursestarttime,
                                city,department,courseendtime,numrec,userid,username,
                                firstname,lastname,email,completed,waitinglist,status,
                                groups,notes,idnumber',
            'responsesfields' => 'completed,status,rating,numrec,fullname,timecreated,
                                institution,waitinglist,city,department,notes',
            'sendmail' => 1,

        ];

        foreach ($defaultsettings as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        // Process instance's maxoptionsfromcategoryvalue.
        if (!empty($record->maxoptionsfromcategoryvalue)) {
            $record->maxoptionsfromcategoryvalue = explode(',', $record->maxoptionsfromcategoryvalue);
        }

        if (empty($record->disablebooking)) {
            // This will store the correct JSON to $optionvalues->json.
            booking::remove_key_from_json($record, "disablebooking");
        } else {
            booking::add_data_to_json($record, "disablebooking", 1);
        }

        // To set default semester is mandatory.
        $semesterid = semester::get_semester_with_highest_id();
        if (!empty($record->semester)) {
            if (!$semesterid = $DB->get_field('booking_semesters', 'id', ['identifier' => $record->semester])) {
                throw new Exception('The specified booking semester with name "' . $record->semester . '" does not exist');
            }
        }
        $record->semesterid = $semesterid;

        return parent::create_instance($record, $options);
    }

    /**
     * Function to create a dummy option.
     *
     * @param ?array|stdClass $record
     * @return stdClass the booking option object
     */
    public function create_option($record = null) {
        global $DB;

        $record = (array) $record;

        if (!isset($record['bookingid'])) {
            throw new coding_exception(
                'bookingid must be present in phpunit_util::create_option() $record'
            );
        }

        if (!isset($record['text'])) {
            throw new coding_exception(
                'text must be present in phpunit_util::create_option() $record'
            );
        }

        $booking = singleton_service::get_instance_of_booking_by_bookingid($record['bookingid']);

        // Increment the forum subscription count.
        $this->bookingoptions++;

        $record = (object) $record;

        // Finalizing object with required properties.
        $record->id = 0;
        $record->cmid = $booking->cmid;
        $record->identifier = $record->identifier ?? booking_option::create_truly_unique_option_identifier();

        $context = context_module::instance($record->cmid);

        $record->addtocalendar = !empty($record->addtocalendar) ? $record->addtocalendar : 0;
        $record->maxanswers = !empty($record->maxanswers) ? $record->maxanswers : 0;

        // Process option teachers.
        if (!empty($record->teachersforoption)) {
            $teacherarr = explode(',', $record->teachersforoption);
            $record->teachersforoption = [];
            foreach ($teacherarr as $teacher) {
                $record->teachersforoption[] = $this->get_user(trim($teacher));
            }
        } else {
            $record->teachersforoption = [];
        }

        // Process option responsible contact persons.
        if (!empty($record->responsiblecontact)) {
            $rcparr = explode(',', $record->responsiblecontact);
            $record->responsiblecontact = [];
            foreach ($rcparr as $rcp) {
                $record->responsiblecontact[] = $this->get_user(trim($rcp));
            }
        } else {
            $record->responsiblecontact = [];
        }

        // Process semesterID.
        if (!empty($record->semesterid)) {
            // Force $bookingsettings->semesterid by given $record->semesterid.
            $DB->set_field('booking', 'semesterid', $record->semesterid, ['id' => $record->bookingid]);
        }

        // Create / save booking option(s).
        $record->id = booking_option::update($record, $context);

        // Override to force given timemadevisible.
        if (!empty($record->timemadevisible)) {
            $DB->set_field('booking_options', 'timemadevisible', $record->timemadevisible, ['id' => $record->id]);
            singleton_service::destroy_booking_option_singleton($record->id);
        }

        return $record;
    }

    /**
     * Function to create a dummy student's answer on option.
     *
     * @param ?array|stdClass $record
     * @return int $id the booking answer status
     */
    public function create_answer($record = null) {
        global $DB;

        $record = (object) $record;

        $settings = singleton_service::get_instance_of_booking_option_settings($record->optionid);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $user = $DB->get_record('user', ['id' => (int)$record->userid], '*', MUST_EXIST);
        $option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $record->userid, true);
        // Value of $id expected to be MOD_BOOKING_BO_COND_ALREADYBOOKED.

        return $id;
    }

    /**
     * Function to create a dummy pricecategory option.
     *
     * @param ?array|stdClass $record
     * @return stdClass the booking pricecategory object
     */
    public function create_pricecategory($record = null) {
        global $DB;

        $record = (object) $record;

        if (!$DB->record_exists('booking_pricecategories', ['identifier' => $record->identifier])) {
            $record->id = $DB->insert_record('booking_pricecategories', $record);
        }
        return $record;
    }

    /**
     * Function to create a dummy campaign option.
     *
     * @param ?array|stdClass $record
     */
    public function create_campaign($record = null) {

        $record = array_merge($record, json_decode($record['json'], true));

        $record = (object) $record;

        if ((int) $record->type == 0) {
            $record->bookingcampaigntype = 'campaign_customfield';
        } else {
            $record->bookingcampaigntype = 'campaign_blockbooking';
        }

        campaigns_info::save_booking_campaign($record);
    }

    /**
     * Function to create a dummy subooking option.
     *
     * @param ?array|stdClass $record
     * @return stdClass the booking subbooking DB object
     */
    public function create_subbooking($record = null) {
        global $DB, $USER;

        $record = (object) $record;

        $record->timemodified = time();
        $record->usermodified = $USER->id;

        $record->id = $DB->insert_record('booking_subbooking_options', $record);

        // Every time we save the subbooking, we have to invalidate caches.
        // Trigger an event that booking option has been updated.
        $booking = singleton_service::get_instance_of_booking_by_optionid($record->optionid);
        $context = context_module::instance($booking->cmid);
        $event = \mod_booking\event\bookingoption_updated::create([
                                                                    'context' => $context,
                                                                    'objectid' => $record->optionid,
                                                                    'userid' => $USER->id,
                                                                    'relateduserid' => $USER->id,
                                                                    'other' => [
                                                                        'changes' => [
                                                                            (object)[
                                                                                'fieldname' => 'subbookings',
                                                                            ],
                                                                        ],
                                                                    ],
                                                                ]);
        $event->trigger();
        cache_helper::purge_by_event('setbackeventlogtable');

        return $record;
    }

    /**
     * Function to create a dummy price item.
     *
     * @param ?array|stdClass $record
     * @return void
     */
    public function create_price($record = null): void {
        global $DB;

        $record = (object) $record;

        switch ($record->area) {
            case 'option':
                if (!$itemid = $DB->get_field('booking_options', 'id', ['text' => $record->itemname])) {
                    throw new Exception('The specified booking option with name text "' . $record->itemname . '" does not exist');
                }
                break;
            case 'subbooking':
                if (!$itemid = $DB->get_field('booking_subbooking_options', 'id', ['name' => $record->itemname])) {
                    throw new Exception('The specified subbooking with name text "' . $record->itemname . '" does not exist');
                }
                break;
            default:
                $itemid = 0;
        }

        Mod_bookingPrice::add_price(
            $record->area,
            $itemid,
            $record->pricecategoryidentifier,
            $record->price,
            $record->currency
        );
    }
    /**
     * Function to create a dummy semester option.
     *
     * @param ?array|stdClass $record
     * @return stdClass the booking semester object
     */
    public function create_semester($record = null) {
        global $DB;

        $record = (object) $record;

        $record->id = $DB->insert_record('booking_semesters', $record);

        return $record;
    }

    /**
     * Function to create a dummy rule for bookings.
     *
     * @param ?array|stdClass $ruledraft
     * @return stdClass the booking rule object
     */
    public function create_rule($ruledraft = null) {
        global $DB;

        rules_info::destroy_singletons();
        booking_rules::$rules = [];

        $ruledraft = (object) $ruledraft;

        $record = new stdClass();
        $record->bookingid = isset($ruledraft->bookingid) ? $ruledraft->bookingid : 0;
        $record->contextid = isset($ruledraft->contextid) ? $ruledraft->contextid : 1;
        $record->rulename = $ruledraft->rulename;
        $record->eventname = '';

        $ruleobject = new stdClass();
        $ruleobject->conditionname = $ruledraft->conditionname;
        $ruleobject->conditiondata = isset($ruledraft->conditiondata) ? json_decode($ruledraft->conditiondata) : '';
        $ruleobject->name = $ruledraft->name;
        $ruleobject->actionname = $ruledraft->actionname;
        $ruleobject->actiondata = json_decode($ruledraft->actiondata);
        $ruleobject->rulename = $ruledraft->rulename;
        $ruleobject->ruledata = json_decode($ruledraft->ruledata);
        if (empty($ruleobject->ruledata)) {
            $ruleobject->ruledata = new stdClass();
        }

        // Setup event name if provided explicitly or from ruledata if provided.
        if (!empty($ruledraft->eventname)) {
            $record->eventname = $ruledraft->eventname;
        } else if (!empty($ruleobject->ruledata->boevent)) {
            $record->eventname = $ruleobject->ruledata->boevent;
        }

        // Setup rule overriding.
        if (empty($ruleobject->ruledata->cancelrules)) {
            $ruleobject->ruledata->cancelrules = []; // Should be defined explicitly.
        }
        if (!empty($ruledraft->cancelrules)) {
            $cancelrules = explode(',', $ruledraft->cancelrules);
            foreach ($cancelrules as $cancelrule) {
                if ($ruleid = $this->get_rule($cancelrule)) {
                    $ruleobject->ruledata->cancelrules[] = $ruleid;
                }
            }
        }

        $record->rulejson = json_encode($ruleobject);

        $record->id = $DB->insert_record('booking_rules', $record);

        return $record;
    }

    /**
     * Function to create a dummy user purchase record.
     *
     * @param array|stdClass $record
     * @return int
     */
    public function create_user_purchase($record) {
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Clean cart.
            shopping_cart::delete_all_items_from_cart($record['userid']);
            // Set user to buy in behalf of.
            shopping_cart::buy_for_user($record['userid']);
            // Get cached data or setup defaults.
            $cartstore = cartstore::instance($record['userid']);
            // Put in a test item with given ID (or default if ID > 4).
            shopping_cart::add_item_to_cart('mod_booking', 'option', $record['optionid'], -1);
            // Confirm cash payment.
            $res = shopping_cart::confirm_payment($record['userid'], LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            $res = $this->create_answer($record);
            // Value of $res expected to be MOD_BOOKING_BO_COND_ALREADYBOOKED.
            return $res;
        } else {
            throw new Exception('The shopping_cart plugin has not installed!');
        }
    }

    /**
     * Function to create a dummy student's answer on option.
     *
     * @param ?array|stdClass $record
     * @return void
     */
    public function create_action($record = null) {
        global $DB;

        $record = (object) $record;
        $settings = singleton_service::get_instance_of_booking_option_settings($record->optionid);

        $record->id = 0;
        $record->cmid = $settings->cmid;

        $boactiondata = json_decode($record->boactionjson);
        unset($record->boactionjson);

        if (!empty($boactiondata)) {
            switch ($record->action_type) {
                case 'bookotheroptions':
                    $record->bookotheroptionsforce = $boactiondata->bookotheroptionsforce ?? 7;
                    foreach ($boactiondata->otheroptions as $optionname) {
                        if (!$id = $DB->get_field('booking_options', 'id', ['text' => $optionname])) {
                            throw new Exception('The specified booking option with name text "' . $optionname . '" does not exist');
                        }
                        $record->bookotheroptionsselect[] = $id;
                    }
                    break;
                case 'userprofilefield':
                    $record->boactionselectuserprofilefield = $boactiondata->boactionselectuserprofilefield ?? "";
                    $record->boactionuserprofileoperator = $boactiondata->boactionuserprofileoperator ?? "";
                    $record->boactionuserprofilefieldvalue = $boactiondata->boactionuserprofilefieldvalue ?? "";
                    break;
                case 'cancelbooking':
                    $record->boactioncancelbooking = $boactiondata->boactioncancelbooking ?? 0;
                    break;
            }
            actions_info::save_action($record);
        }
    }

    /**
     * This creates a show only one table via the view page.
     *
     * @param mixed $optionid
     *
     * @return array
     *
     */
    public function create_table_for_one_option($optionid) {

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $booking = singleton_service::get_instance_of_booking_by_cmid($settings->cmid);

        $view = new view($settings->cmid, 'showonlyone', $settings->id);

        // Create the table.
        $showonlyonetable = new bookingoptions_wbtable("cmid_{$settings->cmid}_optionid_{$optionid} showonlyonetable");

        $wherearray = [
            'bookingid' => (int) $booking->id,
            'id' => $optionid,
        ];
        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', null, $booking->context, [], $wherearray);
        $showonlyonetable->set_filter_sql($fields, $from, $where, $filter, $params);

        // Initialize the default columnes, headers, settings and layout for the table.
        // In the future, we can parametrize this function so we can use it on many different places.
        $view->wbtable_initialize_layout($showonlyonetable, false, false, false);
        $showonlyonetable->printtable(10, true);

        return $showonlyonetable->rawdata ?? [];
    }

    /**
     * Function, to get userid
     * @param string $username
     * @return int
     */
    private function get_user(string $username) {
        global $DB;

        if (!$id = $DB->get_field('user', 'id', ['username' => $username])) {
            throw new Exception('The specified user with username "' . $username . '" does not exist');
        }
        return $id;
    }

    /**
     * Function to get ruleid by rulename from json.
     * @param string $rulename
     * @return int
     */
    private function get_rule(string $rulename) {
        global $DB;

        $param = '\"name\":\"' . $rulename . '\"';
        $sql = 'SELECT id FROM {booking_rules} WHERE rulejson LIKE \'%' . $param . '%\'';
        if (!$id = $DB->get_field_sql($sql)) {
            throw new Exception('The specified rule with name "' . $rulename . '" does not exist');
        }
        return $id;
    }
}

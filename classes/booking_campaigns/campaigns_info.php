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
 * Base class for booking campaigns information.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\booking_campaigns;

use cache_helper;
use core_component;
use mod_booking\customfield\booking_handler;
use mod_booking\output\campaignslist;
use mod_booking\booking_campaigns\booking_campaign;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for booking campaigns information.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class campaigns_info {

    /**
     * Add form fields to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public static function add_campaigns_to_mform(MoodleQuickForm &$mform,
        ?array &$ajaxformdata = null) {

        // First, get all the type of campaigns there are.
        $campaigns = self::get_campaigns();

        $campaignsforselect = [];
        $campaignsforselect['0'] = get_string('choose...', 'mod_booking');
        foreach ($campaigns as $campaign) {
            $fullclassname = get_class($campaign); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $campaignsforselect[$shortclassname] = $campaign->get_name_of_campaign_type();
        }

        $mform->registerNoSubmitButton('btn_bookingcampaigntype');
        $buttonargs = ['class' => 'd-none'];

        $categoryselect = [
            $mform->createElement('select', 'bookingcampaigntype',
            get_string('campaigntype', 'mod_booking'), $campaignsforselect),
            $mform->createElement('submit', 'btn_bookingcampaigntype',
                get_string('bookingcampaign', 'mod_booking'), $buttonargs),
        ];
        $mform->addGroup($categoryselect, 'bookingcampaigntype',
            get_string('campaigntype', 'mod_booking'), [' '], false);
        $mform->setType('btn_bookingcampaigntype', PARAM_NOTAGS);

        if (isset($ajaxformdata['bookingcampaigntype'])) {
            $campaign = self::get_campaign_by_name($ajaxformdata['bookingcampaigntype']);
        } else {
            list($campaign) = $campaigns;
        }

        if (empty($campaign)) {
            return;
        }

        $campaign->add_campaign_to_mform($mform, $ajaxformdata);
    }

    /**
     * Get all booking campaigns.
     * @return array an array of booking campaigns (instances of class booking_campaign).
     */
    public static function get_campaigns() {

        $campaigns = core_component::get_component_classes_in_namespace(
            "mod_booking",
            'booking_campaigns\campaigns'
        );

        return array_map(fn($a) => new $a, array_keys($campaigns));
    }

    /**
     * Get booking campaign by campaign type.
     * @param int $campaigntype the campaign type param
     * @return mixed an instance of the campaign class or null
     */
    public static function get_campaign_by_type(int $campaigntype) {

        $campaignname = '';
        switch($campaigntype) {
            case MOD_BOOKING_CAMPAIGN_TYPE_CUSTOMFIELD:
                $campaignname = 'campaign_customfield';
                break;
            case MOD_BOOKING_CAMPAIGN_TYPE_BLOCKBOOKING:
                $campaignname = 'campaign_blockbooking';
                break;
        }

        $filename = 'mod_booking\\booking_campaigns\\campaigns\\' . $campaignname;

        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Get booking campaign by name.
     * @param string $campaignname the campaign class name
     * @return mixed an instance of the campaign class or null
     */
    public static function get_campaign_by_name(string $campaignname) {

        $filename = 'mod_booking\\booking_campaigns\\campaigns\\' . $campaignname;

        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Prepare data to set data to form.
     *
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {

        global $DB;

        if (empty($data->id)) {
            // Nothing to set, we return empty object.
            return new stdClass();
        }

        // If we have an ID, we retrieve the right campaign from DB.
        $record = $DB->get_record('booking_campaigns', ['id' => $data->id]);
        $campaign = self::get_campaign_by_type($record->type);
        $campaign->set_defaults($data, $record);

        return (object)$data;

    }

    /**
     * Save the booking campaign.
     * @param stdClass $data reference to the form data
     * @return void
     */
    public static function save_booking_campaign(stdClass &$data) {

        $campaign = self::get_campaign_by_name($data->bookingcampaigntype);
        $campaign->save_campaign($data);

        // Every time when we save a campaign, we have to purge data right away.
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
        cache_helper::purge_by_event('setbackprices');

        return;
    }

    /**
     * Delete a booking campaign by its ID.
     * @param int $campaignid the ID of the campaign
     */
    public static function delete_campaign(int $campaignid) {
        global $DB;
        $DB->delete_records('booking_campaigns', ['id' => (int)$campaignid]);
        singleton_service::reset_campaigns($campaignid);

        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackoptionsettings');
        cache_helper::purge_by_event('setbackprices');
    }

    /**
     * Returns the rendered html for a list of campaigns.
     * @return string the rendered campaigns
     */
    public static function return_rendered_list_of_saved_campaigns() {
        global $PAGE;
        $campaigns = self::get_list_of_saved_campaigns();
        $output = $PAGE->get_renderer('mod_booking');
        return $output->render_campaignslist(new campaignslist($campaigns));
    }

    /**
     * Returns a list of campaigns in DB.
     * @return array
     */
    private static function get_list_of_saved_campaigns(): array {
        global $DB;

        return singleton_service::get_all_campaigns();
    }

    /**
     * Destroys all campaigns in db and singleton.
     * @return bool
     */
    public static function delete_all_campaigns(): bool {
        global $DB;
        $DB->delete_records('booking_campaigns');
        singleton_service::destroy_all_campaigns();
        return true;
    }


    /**
     * Get all campaigns from DB - but already instantiated.
     * @return array
     */
    public static function get_all_campaigns(): array {
        $campaigns = [];
        $records = self::get_list_of_saved_campaigns();
        foreach ($records as $record) {
            /** @var booking_campaign $campaign */
            $campaign = self::get_campaign_by_type($record->type);
            $campaign->set_campaigndata($record);
            $campaigns[] = $campaign;
        }
        return $campaigns;
    }

    /**
     * Apply part of mform elements that some campaigns have in common.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     *
     * @return void
     *
     */
    public static function add_customfields_to_form(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        global $DB;
        $mform->addElement('text', 'name', get_string('campaignname', 'mod_booking'));
        $mform->addHelpButton('name', 'campaign_name', 'mod_booking');

        $mform->addElement('static', 'warning', '',
                get_string('optionspecificcampaignwarning', 'mod_booking'));

        // Custom field name.
        $records = booking_handler::get_customfields();

        $fieldnames = [];
        $fieldnames[0] = get_string('choose...', 'mod_booking');
        foreach ($records as $record) {
            $fieldnames[$record->shortname] = $record->name;
        }

        $mform->addElement('select', 'bofieldname',
            get_string('campaignfieldname', 'mod_booking'), $fieldnames);
        $mform->addHelpButton('bofieldname', 'campaignfieldname', 'mod_booking');
        $mform->registerNoSubmitButton('btn_bofieldname');
        $mform->addElement(
            'submit',
            'btn_bofieldname',
            'btn_bofieldname_label',
            ['class' => 'd-none']
        );

        $operators = [
            '=' => get_string('equalsplain', 'mod_booking'),
            '!~' => get_string('containsnotplain', 'mod_booking'),
        ];

        $mform->addElement('select', 'campaignfieldnameoperator',
            get_string('blockoperator', 'mod_booking'), $operators);
        $mform->hideIf('campaignfieldnameoperator', 'bofieldname', 'eq', "0");

        $fieldvalues = [];
        if (!empty($ajaxformdata["bofieldname"])) {
            // Custom field value.
            $sql = "SELECT DISTINCT cd.value
                FROM {customfield_field} cf
                JOIN {customfield_category} cc
                ON cf.categoryid = cc.id
                JOIN {customfield_data} cd
                ON cd.fieldid = cf.id
                WHERE cc.area = 'booking'
                AND cd.value IS NOT NULL
                AND cd.value <> ''
                AND cf.shortname = :bofieldname";
            // Propositions will only be displayed if form was saved before - and therefore fieldname is set.
            // Maybe add a nosubmitbutton to fetch fieldname.

            $params['bofieldname'] = $ajaxformdata["bofieldname"];
            $records = $DB->get_fieldset_sql($sql, $params);

            foreach ($records as $record) {
                if (strpos($record, ',') !== false) {
                    foreach (explode(',', $record) as $subrecord) {
                        $fieldvalues[$subrecord] = $subrecord;
                    }
                } else {
                    $fieldvalues[$record] = $record;
                }
            }
        }

        $options = [
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'tags' => true,
            'multiple' => false,
        ];
        $mform->addElement('autocomplete', 'fieldvalue',
            get_string('campaignfieldvalue', 'mod_booking'), $fieldvalues, $options);
        $mform->addHelpButton('fieldvalue', 'campaignfieldvalue', 'mod_booking');
        $mform->hideIf('fieldvalue', 'bofieldname', 'eq', "0");

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'mod_booking');

            $mform->addElement('static', 'warning', '',
                get_string('userspecificcampaignwarning', 'mod_booking'));

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = format_string($customuserprofilefield->name);
            }

            $mform->addElement('select', 'cpfield',
                get_string('customuserprofilefield', 'mod_booking'), $customuserprofilefieldsarray);

            $mform->addHelpButton('cpfield', 'customuserprofilefield', 'mod_booking');

            $operators = [
                '=' => get_string('equals', 'mod_booking'),
                '~' => get_string('contains', 'mod_booking'),
                '!~' => get_string('containsnot', 'mod_booking'),
            ];

            $mform->addElement('select', 'cpoperator',
                get_string('blockoperator', 'mod_booking'), $operators);
            $mform->hideIf('cpoperator', 'cpfield', 'eq', "0");

            $fieldnames = [];
            if (!empty($ajaxformdata["cpfield"])) {
                // Profile field value.
                $sql = "
                    SELECT DISTINCT
                        uid.data AS fieldvalue
                    FROM
                        {user_info_data} uid
                    JOIN
                        {user_info_field} uif ON uif.id = uid.fieldid
                    WHERE
                        uif.shortname = :shortname
                    AND
                        uid.data IS NOT NULL
                    AND
                        uid.data != ''
                    ORDER BY
                        uid.data ASC";
                // Propositions will only be displayed if form was saved before - and therefore fieldname is set.
                // Maybe add a nosubmitbutton to fetch fieldnames.
                $params = [
                    'shortname' => $ajaxformdata["cpfield"],
                ];
                $records = $DB->get_fieldset_sql($sql, $params);

                foreach ($records as $record) {
                    $fieldnames[$record] = $record;
                }
            }
            $mform->addElement(
                'autocomplete',
                'cpvalue',
                get_string('textfield', 'mod_booking'),
                $fieldnames,
                ['multiple' => true, 'tags' => true]
            );
            $mform->registerNoSubmitButton('btn_cpfield');
            $mform->addElement(
                'submit',
                'btn_cpfield',
                'btn_cpfield_label',
                ['class' => 'd-none']
            );

            $mform->setType('cpvalue', PARAM_TEXT);
            $mform->hideIf('cpvalue', 'cpfield', 'eq', "0");
        }
    }

    /**
     * If any of the fields apply to the user, return true.
     *
     * @param array $fields
     * @param string $fieldname
     * @param string $operator
     * @param int $userid
     *
     * @return boolean
     *
     */
    public static function check_if_profilefield_applies(
        array $fields,
        string $fieldname,
        string $operator,
        int $userid = 0
    ): bool {
        global $USER;
        $result = false;
        $userid = $userid ?? $USER->id;

        $user = singleton_service::get_instance_of_user($userid, true);

        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            switch ($operator) {
                case "=": // Equals.
                    if ($blocking = $user->profile[$fieldname] === $field) {
                        return true;
                    }
                    break;
                case "~": // Contains.
                    if ($blocking = strpos($user->profile[$fieldname], $field) !== false) {
                        return true;
                    }
                    break;
                case "!~":
                    // Does not contain.
                    if (!$blocking = strpos($user->profile[$fieldname], $field) === false) {
                        return false;
                    }
                    break;
            }
            $result = $blocking;
        }
        return $result;
    }

    /**
     * Check if given campaign is active.
     *
     * @param int $starttime
     * @param int $endtime
     * @param mixed $fieldname // The name given in the bookingoptionfield.
     * @param string $fieldvalue // The value required or excluded by campaign.
     * @param string $operator
     *
     * @return bool
     *
     */
    public static function check_if_campaign_is_active(
        int $starttime,
        int $endtime,
        $fieldname,
        string $fieldvalue,
        string $operator
    ): bool {
        $isactive = false;
        $now = time();
        if ($starttime <= $now && $now <= $endtime) {
            if (!empty($fieldname)) {
                if (is_string($fieldname) && $fieldname === $fieldvalue) {
                    // It's a string so we can compare directly.
                    $isactive = true;
                } else if (is_array($fieldname) && in_array($fieldvalue, $fieldname)) {
                    // It's an array, so we check with in_array.
                    $isactive = true;
                }
                if (
                    $operator === '!~'
                ) {
                    // If operator is set to "does not contain" we need to invert the result.
                    $isactive = !$isactive;
                }
            } else if (
                !empty($fieldvalue)
                && $operator == '!~'
            ) {
                $isactive = true;
            } else if (
                empty($fieldvalue) &&
                empty($fieldname)
            ) { // No fieldname given in option, and fieldname required in campaign with "does not contain".
                $isactive = true;
            } else {
                $isactive = false; // No fieldname given in option but fieldname required in campaign.
            }
        }
        return $isactive;
    }
}

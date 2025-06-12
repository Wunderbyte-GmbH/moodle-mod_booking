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
 * Base class for booking actions information.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\settings\optionformconfig;

use coding_exception;
use core_component;
use context_system;
use context;
use core_plugin_manager;
use ddl_exception;
use ddl_change_structure_exception;
use dml_exception;
use html_writer;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for additional information of booking actions.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optionformconfig_info {
    /**
     * NOCONFIGURATION
     *
     * @var int
     */
    const NOCONFIGURATION = 0;
    /**
     * SHOWFIELD
     *
     * @var int
     */
    const SHOWFIELD = 1;
    /**
     * HIDEFIELD
     *
     * @var int
     */
    const HIDEFIELD = 2;

    /**
     * Array of field set provides the fetched configuration from DB.
     * @var array
     */
    private static $arrayoffieldsets = [];

    /**
     * Capabilities.
     * @var array
     */
    const CAPABILITIES = [
        'mod/booking:expertoptionform',
        'mod/booking:reducedoptionform1',
        'mod/booking:reducedoptionform2',
        'mod/booking:reducedoptionform3',
        'mod/booking:reducedoptionform4',
        'mod/booking:reducedoptionform5',
    ];

    /**
     * Function to be called from webservice to return the available field ids & settings from db.
     * @param int $contextid
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws ddl_exception
     * @throws ddl_change_structure_exception
     */
    public static function return_configured_fields(int $contextid = 0) {

        if (!empty($contextid)) {
            $context = context::instance_by_id($contextid);
        } else {
            $context = context_system::instance();
        }

        $returnarray = [];

        foreach (self::CAPABILITIES as $capability) {
            $returnarray[] = self::return_configured_fields_for_capability($context->id, $capability);
        }
        return $returnarray;
    }

    /**
     * Function to be called from webservice to save the available field ids & settings to db.
     * If we find the key "reset" with the value true, we delete the record.
     * @param int $contextid
     * @param string $capability
     * @param string $json
     * @return string
     * @throws dml_exception
     */
    public static function save_configured_fields(int $contextid, string $capability, string $json) {
        global $DB;
        $status = 'failed';

        $params = [
            'area' => 'option',
            'capability' => $capability,
            'contextid' => $contextid,
        ];

        // First check if we wanted to delete.
        $jsonobject = json_decode($json);
        if (isset($jsonobject->reset) && $jsonobject->reset == true) {
            $DB->delete_records('booking_form_config', $params);
            $status = 'success';
            // Now check if we need to update.
        } else if ($record = $DB->get_record('booking_form_config', $params)) {
            $DB->update_record('booking_form_config', [
                'id' => $record->id,
                'json' => $json,
            ]);
            $status = 'success';
        } else {
            // Finally check if we insert.
            $params['json'] = $json;
            $DB->insert_record('booking_form_config', $params);
            $status = 'success';
        }
        return $status;
    }

    /**
     * Return capability for user.
     * @param int $contextid
     * @param int $userid
     * @return string
     */
    public static function return_capability_for_user(int $contextid, int $userid = 0) {

        global $USER;

        if (empty($userid)) {
            $userid = $USER->id;
        }

        $context = context::instance_by_id($contextid);

        foreach (self::CAPABILITIES as $capability) {
            if (has_capability($capability, $context)) {
                return $capability;
            }
        }
        return '';
    }

    /**
     * Fetches the record from db.
     *
     * @param int $contextid
     * @param string $capability
     *
     * @return array
     *
     */
    public static function return_configured_fields_for_capability(int $contextid, string $capability) {

        if (empty($capability)) {
            $json = '[]';
        } else if (isset(self::$arrayoffieldsets[$contextid][$capability])) {
            $json = self::$arrayoffieldsets[$contextid][$capability];
        } else {
            // If we find a record in DB, we use it.
            if ($record = self::return_capabilities_from_db($contextid, $capability)) {
                $json = $record->json;
            }
            // But we still check if we need to add fields.
            // We get really all fields, without restriction.
            $fields = core_component::get_component_classes_in_namespace(
                "mod_booking",
                'option\fields'
            );

            // Additionally, we have to check if we have fields in Booking extensions.
            $bookingextensions = core_plugin_manager::instance()->get_plugins_of_type('bookingextension');
            if (!empty($bookingextensions)) {
                foreach ($bookingextensions as $bookingextension) {
                    $fields = array_merge($fields, core_component::get_component_classes_in_namespace(
                        $bookingextension->component,
                        'option\fields'
                    ));
                }
            }

            $fields = array_map(
                fn($a) =>
                (object)[
                    'id' => $a::$id,
                    'classname' => $a::return_classname_name(),
                    'fullclassname' => $a::return_full_classname(),
                    'checked' => in_array(MOD_BOOKING_OPTION_FIELD_STANDARD, $a::$fieldcategories) ?
                        1 : 0,
                    'necessary' => in_array(MOD_BOOKING_OPTION_FIELD_NECESSARY, $a::$fieldcategories) ?
                        1 : 0,
                    'incompatible' => $a::$incompatiblefields,
                    'subfields' => $a::get_subfields(),
                ],
                array_keys($fields)
            );

            usort($fields, fn($a, $b) => $a->id > $b->id ? 1 : -1);

            $json = json_encode($fields);

            if (!empty($record) && ($record->json !== $json)) {
                $storedfields = json_decode($record->json);

                $newfields = [];

                foreach ($fields as $value) {
                    $filteredarray = array_filter($storedfields, fn($a) => $a->id == $value->id);
                    if (!empty($filteredarray)) {
                        $storefield = reset($filteredarray);
                        if (!property_exists($storefield, 'fullclassname') && property_exists($value, 'fullclassname')) {
                            $storefield->fullclassname = $value->fullclassname;
                        }
                        $newfields[] = $storefield;
                    } else {
                        $newfields[] = $value;
                    }
                }
                $json = json_encode($newfields);
            }
            self::$arrayoffieldsets[$contextid][$capability] = $json;
        }

        return [
          'id' => $contextid,
            'capability' => $capability,
            'json' => $json,
        ];
    }

    /**
     * Returns the unchecked customfields for a given context and for the capability of the given user.
     * @param int $contextid
     * @param int $userid
     * @return mixed
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_unchecked_customfields(int $contextid, int $userid = 0) {
        if (empty($contextid)) {
            return [];
        }

        $capability = self::return_capability_for_user($contextid, $userid);

        // As we can configure the fiels, we first need the configuration.
        $record = self::return_configured_fields_for_capability($contextid, $capability);

        $fields = json_decode($record['json']);
        $filteredfields = array_filter($fields, fn($a) => $a->id == MOD_BOOKING_OPTION_FIELD_COSTUMFIELDS ? true : false);
        $customfields = reset($filteredfields);
        $uncheckedfields = array_map(fn($a) => $a->checked != 1 ? $a->shortname : null, (array)$customfields->subfields);

        return $uncheckedfields;
    }

    /**
     * This function will tell us on which level we have a record stored for the given user in the given context.
     * @param int $contextid
     * @return string
     */
    public static function return_message_stored_optionformconfig(int $contextid) {

        $capability = self::return_capability_for_user($contextid);

        $context = context::instance_by_id($contextid);

        if (!$record = self::return_capabilities_from_db($contextid, $capability)) {
            return get_string('optionformconfignotsaved', 'mod_booking');
        } else {
            switch ($record->contextlevel) {
                case CONTEXT_SYSTEM:
                    $url = new moodle_url('/mod/booking/optionformconfig.php', [
                        'cmid' => 0,
                    ]);
                    $message = html_writer::link(
                        $url,
                        get_string('optionformconfigsavedsystem', 'mod_booking')
                    );
                    break;
                case CONTEXT_COURSECAT:
                    $message = get_string('optionformconfigsavedcoursecat', 'mod_booking');
                    break;
                case CONTEXT_MODULE:
                    $cmid = $context->instanceid;
                    $url = new moodle_url('/mod/booking/optionformconfig.php', [
                        'cmid' => $cmid,
                    ]);
                    $message = html_writer::link(
                        $url,
                        get_string('optionformconfigsavedmodule', 'mod_booking')
                    );
                    break;
                case CONTEXT_COURSE:
                    $message = get_string('optionformconfigsavedcourse', 'mod_booking');
                    break;
                default:
                    $message = get_string('optionformconfigsavedother', 'mod_booking', $record->contextlevel);
                    break;
            }

            return $message;
        }
    }

    /**
     * Returns the db record, if any exists.
     * @param int $contextid
     * @param string $capability
     * @return mixed
     * @throws coding_exception
     * @throws dml_exception
     */
    private static function return_capabilities_from_db(int $contextid, string $capability) {

        global $DB;

        // We dont know where exactly the config is in the context path.
        // There might be a config higher up, eg. for the course category.
        // Therefore, we look for all the contextids in the path, sorted by context_level.
        // We use the highest, ie most specific context_level.
        $context = context::instance_by_id($contextid);
        $path = $context->path;

        $patharray = explode('/', $path);

        $patharray = array_map(fn($a) => (int)$a, $patharray);

        [$inorequal, $params] = $DB->get_in_or_equal($patharray, SQL_PARAMS_NAMED);

        $sql = "SELECT *
                FROM {booking_form_config} bfc
                JOIN {context} c ON bfc.contextid=c.id
                WHERE bfc.area='option'
                AND bfc.capability=:capability
                AND bfc.contextid $inorequal
                ORDER BY c.contextlevel DESC";

        $params['capability'] = $capability;

        return $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);
    }
}

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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use local_entities\entitiesrelation_handler;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entities extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_ENTITIES;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_GENERAL;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null): string {

        if (class_exists('local_entities\entitiesrelation_handler')) {

            // We only run this to make sure we have the constants.
            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            // Every time we save an entity, we want to make sure that the name of the entity is stored in location.
            if (!empty($formdata->{LOCAL_ENTITIES_FORM_ENTITYID . 0})) {
                // We might have more than one address, this will lead to more than one record which comes back.
                $entities = entitiesrelation_handler::get_entities_by_id($formdata->{LOCAL_ENTITIES_FORM_ENTITYID . 0});
                $newoption->address = '';
                foreach ($entities as $entity) {
                    $newoption->location = $entity->name;
                    $newoption->address .= "$entity->postcode $entity->city $entity->streetname $entity->streetnumber";
                    if (count($entities) > 1) {
                        $newoption->address .= ', ';
                    }
                }
                if (count($entities) > 1) {
                    $newoption->address = substr($newoption->address, 0, -2);
                }
            } else if (isset($formdata->{LOCAL_ENTITIES_FORM_ENTITYID . 0})) {
                $newoption->location = '';
                $newoption->address = '';
            }

            /* IMPORTANT NOTE: We do not use er_saverelationsforoptiondates (checkbox to save entity for each optiondate)
            in the form anymore, but we had to keep this code, so this still works when importing! */

            // If the checkbox to save entity for each optiondate is checked...
            // ...(actually only simulated by importer)...
            // ... then we save the option entity also for each optiondate.
            if ($entityidkeys = preg_grep('/^local_entities_entityid/', array_keys((array)$formdata))) {
                // Entity for the whole option.
                $optionentity = $formdata->local_entities_entityid_0 ?? 0;

                if (!empty($option)) {
                    foreach ($entityidkeys as $entityidkey) {
                        if ($entityidkey == "local_entities_entityid_0") {
                            continue;
                        }
                        // See end of set_data function!
                        // There we just set er_saverelationsforoptiondates to 1.
                        if (!empty($formdata->er_saverelationsforoptiondates)) {
                            $formdata->{$entityidkey} = $optionentity;
                        }
                    }
                }
            }
        }
        return '';
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        // Add entities.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            $erhandler->instance_form_definition($mform, 0, $optionformconfig['formmode']);

            // We removed this because the new date series feature uses the option entity by default.

            // This checkbox is specific to mod_booking which is why it...
            // ...cannot be put directly into instance_form_definition of entitiesrelation_handler.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /*$mform->addElement('advcheckbox', 'er_saverelationsforoptiondates',
                get_string('er_saverelationsforoptiondates', 'mod_booking'));*/

            // Checkbox "Save entity for each date too" must be checked by default.
            // $mform->setDefault('er_saverelationsforoptiondates', 1);
            // But: In validation we need to check, if there are optiondates that have "outlier" entities.
            // If so, the outliers must be changed to the main entity before all relations can be saved.

            // If we have "outliers" (deviating entities), we show a confirm box...
            // ...so a user does not overwrite them accidentally.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* if (entitiesrelation_handler::option_has_dates_with_entity_outliers($optionid)) {
                $mform->addElement('advcheckbox', 'confirm:er_saverelationsforoptiondates',
                    get_string('confirm:er_saverelationsforoptiondates', 'mod_booking'));
            } */
        }
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {
        if (class_exists('local_entities\entitiesrelation_handler')) {
            // If we have the handler, we need first to add the new optiondates to the form.
            // This constant change between object and array is stupid, but comes from the mform handler.
            $fromform = (object)$data;

            $erhandler = new entitiesrelation_handler('mod_booking', 'option', $data['id']);
            /* self::order_all_dates_to_book_in_form($fromform); */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            $erhandler->instance_form_validation((array)$fromform, $errors);
        }
    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @param int $index
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option, int $index = 0) {

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')
            && isset($formdata->{LOCAL_ENTITIES_FORM_ENTITYID . 0})) {

            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            $erhandler->instance_form_save($formdata, $option->id, $index);
        }
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        $entities = [];

        if (class_exists('local_entities\entitiesrelation_handler')) {

            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            // The following possibilites we have to set entities here.
            // A) In location, we find an int. this will be considered an entityid.
            // B) The string in Location corresponds to an entityid.
            // C) We load the saved entityid.
            if (!empty($data->importing) && is_numeric($data->location)) {
                $entities = $erhandler->get_entities_by_id($data->location);
            } else if (!empty($data->importing) && !empty($data->location)) {
                $entities = $erhandler->get_entities_by_name($data->location);
            } else {
                $erhandler->values_for_set_data($data, $data->id);
                return;
            }

            if (count($entities) === 1) {
                $entity = reset($entities);
                $data->location = $entity->name; // We store the name in location.
                $data->{LOCAL_ENTITIES_FORM_ENTITYID . 0} = $entity->id; // 0 means for option, not option date.

                // Make sure, when importing, we also set entity for each optiondate.
                $data->er_saverelationsforoptiondates = 1;
            }
        }
    }
}

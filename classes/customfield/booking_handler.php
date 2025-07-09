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
 * Course handler for custom fields
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\customfield;

use core_customfield\api;
use core_customfield\field_controller;
use mod_booking\settings\optionformconfig\optionformconfig_info;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_url;
use context_system;
use stdClass;
use Throwable;

/**
 * Handler for booking custom fields.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_handler extends \core_customfield\handler {
    /**
     * @var booking_handler
     */
    protected static $singleton;

    /**
     * @var \context
     */
    protected $parentcontext;

    /** @var int Field is visible to everybody */
    const MOD_BOOKING_VISIBLETOALL = 2;
    /** @var int Field is only for teachers */
    const MOD_BOOKING_VISIBLETOTEACHERS = 1;
    /** @var int Field is not displayed  */
    const MOD_BOOKING_NOTVISIBLE = 0;

    /**
     * Returns a singleton
     *
     * @param int $itemid
     * @return \mod_booking\customfield\booking_handler
     */
    public static function create(int $itemid = 0): \core_customfield\handler {
        if (static::$singleton === null) {
            self::$singleton = new static(0);
        }
        return self::$singleton;
    }

    /**
     * Run reset code after unit tests to reset the singleton usage.
     */
    public static function reset_caches(): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('This feature is only intended for use in unit tests');
        }

        static::$singleton = null;
    }

    /**
     * Returns the customfields of the mod_booking component.
     *
     * @param array $selectedshortnames
     *
     * @return array
     *
     */
    public static function get_customfields(array $selectedshortnames = []): array {
        global $DB;

        if (empty($selectedshortnames)) {
            $sql = "SELECT cff.id, cff.name, cff.shortname, cff.configdata
                    FROM {customfield_field} cff
                    LEFT JOIN {customfield_category} cfc
                    ON cff.categoryid = cfc.id
                    WHERE cfc.component = 'mod_booking'
                    ORDER BY cfc.sortorder, cff.sortorder";
            $params = [];
        } else {
            [$insql, $params] = $DB->get_in_or_equal($selectedshortnames, SQL_PARAMS_NAMED, 'param', true);
            $sql = "SELECT cff.id, cff.name, cff.shortname, cff.configdata
                    FROM {customfield_field} cff
                    LEFT JOIN {customfield_category} cfc
                    ON cff.categoryid = cfc.id
                    WHERE cfc.component = 'mod_booking'
                    AND cff.shortname $insql
                    ORDER BY cfc.sortorder, cff.sortorder";
        }

        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

    /**
     * Saves the given data for custom fields, must be called after the instance is saved and id is present
     *
     * @param int $instanceid id received from a form
     * @param string $shortname of a given customfield
     * @param mixed $value new value of a given custom field
     */
    public function field_save($instanceid, $shortname, $value) {

        $editablefields = $this->get_editable_fields($instanceid);
        $fields = api::get_instance_fields_data($editablefields, $instanceid);
        foreach ($fields as $data) {
            $field = $data->get_field();

            if ($field->get('shortname') == $shortname) {
                if (!$data->get('id')) {
                    $data->set('contextid', $this->get_instance_context($instanceid)->id);
                }

                $data->set($data->datafield(), $value);
                $data->set('value', $value);
                $data->save();
            }
        }
    }

    /**
     * The current user can configure custom fields on this component.
     *
     * @return bool true if the current can configure custom fields, false otherwise
     */
    public function can_configure(): bool {
        return has_capability('mod/booking:addeditownoption', $this->get_configuration_context());
    }

    /**
     * The current user can edit custom fields on the given booking instance.
     *
     * @param field_controller $field
     * @param int $instanceid id of the course to test edit permission
     * @return bool true if the current can edit custom fields, false otherwise
     */
    public function can_edit(field_controller $field, int $instanceid = 0): bool {
        if ($instanceid) {
            $context = $this->get_instance_context($instanceid);
            return (!$field->get_configdata_property('locked') ||
                    has_capability('mod/booking:changelockedcustomfields', $context));
        } else {
            $context = $this->get_parent_context();
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                return (!$field->get_configdata_property('locked') ||
                    has_capability('mod/booking:changelockedcustomfields', $context));
            } else {
                return (!$field->get_configdata_property('locked') ||
                    guess_if_creator_will_have_course_capability('mod/booking:changelockedcustomfields', $context));
            }
        }
    }


    /**
     * Instance form definition
     *
     * @param \MoodleQuickForm $mform
     * @param int $instanceid
     * @param string|null $headerlangidentifier
     * @param string|null $headerlangcomponent
     * @param int $contextid
     * @param array $fieldstoinstanciate
     *
     * @return void
     *
     */
    public function instance_form_definition(
        \MoodleQuickForm $mform,
        int $instanceid = 0,
        ?string $headerlangidentifier = null,
        ?string $headerlangcomponent = null,
        int $contextid = 0,
        array $fieldstoinstanciate = []
    ) {

        global $DB;

        $uncheckedcustomfields = optionformconfig_info::get_unchecked_customfields($contextid);

        $editablefields = $this->get_editable_fields($instanceid);
        $fieldswithdata = api::get_instance_fields_data($editablefields, $instanceid);
        $lastcategoryid = null;

        foreach ($fieldswithdata as $data) {
            if (
                in_array($data->get_field()->get('shortname'), $uncheckedcustomfields)
                || (!empty($fieldstoinstanciate) && !in_array($data->get_field()->get('shortname'), $fieldstoinstanciate))
            ) {
                continue;
            }

            $categoryid = $data->get_field()->get_category()->get('id');

            if ($categoryid != $lastcategoryid) {
                $categoryname = format_string($data->get_field()->get_category()->get('name'));

                // Load category header lang string if specified.
                if (!empty($headerlangidentifier)) {
                    $categoryname = get_string($headerlangidentifier, $headerlangcomponent, $categoryname);
                }

                $mform->addElement(
                    'header',
                    'category_' . $categoryid,
                    '<i class="fa fa-fw fa-puzzle-piece" aria-hidden="true"></i>&nbsp;' .
                    $categoryname
                );

                $lastcategoryid = $categoryid;
            }
            $data->instance_form_definition($mform);
            $field = $data->get_field()->to_record();
            if (strlen($field->description)) {
                // Add field description.
                $context = $this->get_configuration_context();
                $value = file_rewrite_pluginfile_urls(
                    $field->description,
                    'pluginfile.php',
                    $context->id,
                    'core_customfield',
                    'description',
                    $field->id
                );
                $value = format_text($value, $field->descriptionformat, ['context' => $context]);
                $mform->addElement('static', 'customfield_' . $field->shortname . '_static', '', $value);
            }
        }
    }

    /**
     * The current user can view custom fields on the given course.
     *
     * @param field_controller $field
     * @param int $instanceid id of the course to test edit permission
     * @return bool true if the current can edit custom fields, false otherwise
     */
    public function can_view(field_controller $field, int $instanceid): bool {
        $visibility = $field->get_configdata_property('visibility');
        if ($visibility == self::MOD_BOOKING_NOTVISIBLE) {
            return false;
        } else if ($visibility == self::MOD_BOOKING_VISIBLETOTEACHERS) {
            return has_capability('mod/booking:addeditownoption', $this->get_instance_context($instanceid));
        } else {
            return true;
        }
    }

    /**
     * Uses categories
     *
     * @return bool
     */
    public function uses_categories(): bool {
        return true;
    }

    /**
     * Sets parent context for the course
     *
     * This may be needed when course is being created, there is no course context but we need to check capabilities
     *
     * @param \context $context
     */
    public function set_parent_context(\context $context) {
        $this->parentcontext = $context;
    }

    /**
     * Returns the parent context for the course
     *
     * @return \context
     */
    protected function get_parent_context(): \context {
        global $PAGE;
        if ($this->parentcontext) {
            return $this->parentcontext;
        } else if ($PAGE->context && $PAGE->context instanceof \context_coursecat) {
            return $PAGE->context;
        }
        return context_system::instance();
    }

    /**
     * Context that should be used for new categories created by this handler
     *
     * @return \context the context for configuration
     */
    public function get_configuration_context(): \context {
        return context_system::instance();
    }

    /**
     * URL for configuration of the fields on this handler.
     *
     * @return moodle_url The URL to configure custom fields for this component
     */
    public function get_configuration_url(): moodle_url {
        return new moodle_url('/mod/booking/customfield.php');
    }

    /**
     * Returns the context for the data associated with the given instanceid.
     *
     * @param int $instanceid id of the record to get the context for
     * @return \context the context for the given record
     */
    public function get_instance_context(int $instanceid = 0): \context {
        return context_system::instance();
    }

    /**
     * Allows to add custom controls to the field configuration form that will be saved in configdata
     *
     * @param \MoodleQuickForm $mform
     */
    public function config_form_definition(\MoodleQuickForm $mform) {
        $mform->addElement('header', 'course_handler_header', get_string('customfieldsettings', 'core_course'));
        $mform->setExpanded('course_handler_header', true);

        // If field is locked.
        $mform->addElement('selectyesno', 'configdata[locked]', get_string('customfield_islocked', 'core_course'));
        $mform->addHelpButton('configdata[locked]', 'customfield_islocked', 'core_course');

        // Field data visibility.
        $visibilityoptions = [
            self::MOD_BOOKING_VISIBLETOALL => get_string('customfield_visibletoall', 'core_course'),
            self::MOD_BOOKING_VISIBLETOTEACHERS => get_string('customfield_visibletoteachers', 'core_course'),
            self::MOD_BOOKING_NOTVISIBLE => get_string('customfield_notvisible', 'core_course'),
        ];
        $mform->addElement(
            'select',
            'configdata[visibility]',
            get_string('customfield_visibility', 'core_course'),
            $visibilityoptions
        );
        $mform->addHelpButton('configdata[visibility]', 'customfield_visibility', 'core_course');
    }

    /**
     * Creates or updates custom field data.
     *
     * @param \restore_task $task
     * @param array $data
     */
    public function restore_instance_data_from_backup(\restore_task $task, array $data) {
    }

    /**
     * Validates the given data for custom fields, used in moodleform validation() function
     *
     * @param array $data moodleform data
     * @param array $files will always be empty in this handler
     * @return array $errors an array of errors we'll need to merge with the other errors array
     */
    public function instance_form_validation(array $data, array $files = []) {

        $errors = parent::instance_form_validation($data, $files);

        // Currently nothing to validate.

        return $errors;
    }

    /**
     * When importing, we only want to load stored values when they are not present in import.
     *
     * Example:
     *   $instance = $DB->get_record(...);
     *   // .... prepare editor, filemanager, add tags, etc.
     *   $handler->instance_form_before_set_data($instance);
     *   $form->set_data($instance);
     *
     * @param stdClass $instance the instance that has custom fields, if 'id' attribute is present the custom
     *    fields for this instance will be added, otherwise the default values will be added.
     */
    public function instance_form_before_set_data_on_import(stdClass $instance) {
        $instanceid = !empty($instance->id) ? $instance->id : 0;
        $fields = api::get_instance_fields_data($this->get_editable_fields($instanceid), $instanceid);

        foreach ($fields as $formfield) {
            $shortname = $formfield->get_field()->get('shortname');
            if (isset($instance->{$shortname})) {
                $instance->{$formfield->get_form_element_name()} = $instance->{$shortname};
                unset($instance->{$shortname});
            } else {
                // If it's not set, we can go on with the stored values.
                $formfield->instance_form_before_set_data($instance);
            }
        }
    }

    /**
     * Saves the given data for custom fields, must be called after the instance is saved and id is present
     *
     * Example:
     *   if ($data = $form->get_data()) {
     *     // ... save main instance, set $data->id if instance was created.
     *     $handler->instance_form_save($data);
     *     redirect(...);
     *   }
     *
     * @param stdClass $instance data received from a form
     * @param bool $isnewinstance if this is call is made during instance creation
     */
    public function instance_form_save(stdClass $instance, bool $isnewinstance = false) {
        if (empty($instance->id)) {
            throw new \coding_exception('Caller must ensure that id is already set in data before calling this method');
        }
        if (!preg_grep('/^customfield_/', array_keys((array)$instance))) {
            // For performance.
            return;
        }
        $editablefields = $this->get_editable_fields($isnewinstance ? 0 : $instance->id);
        $fields = api::get_instance_fields_data($editablefields, $instance->id);
        foreach ($fields as $data) {
            if (!$data->get('id')) {
                $data->set('contextid', $this->get_instance_context($instance->id)->id);
            }

            // Fix for dynamic custom fields that allow multiple values (multiselect).
            $shortname = $data->get_field()->get('shortname');
            $multiselect = $data->get_field()->get_configdata_property('multiselect');
            $key = "customfield_$shortname";
            if ($multiselect == "1" && isset($instance->{$key}) && is_string($instance->{$key})) {
                // Convert them into an array, so everything works as expected.
                $values = explode(',', $instance->{$key});
                $instance->{$key} = $values;
            }
            try {
                $data->instance_form_save($instance);
            } catch (Throwable $e) {
                $donothing = true;
            }

            $elementname = $data->get_form_element_name();
        }
    }

    /**
     * Check if forbidden shortnames have been created.
     * @return string the warning message containing the forbidden shortnames
     */
    public function check_for_forbidden_shortnames_and_return_warning(): string {
        global $DB, $OUTPUT;
        // Just the first optionid we find.
        $anyoptionid = $DB->get_field_sql(
            "SELECT id FROM {booking_options} LIMIT 1"
        );
        $settings = singleton_service::get_instance_of_booking_option_settings($anyoptionid);
        $boproperties = $settings->get_booking_option_properties();
        $usedshortnames = $DB->get_fieldset_sql(
            "SELECT DISTINCT cf.shortname
                        FROM {customfield_field} cf
                        JOIN {customfield_category} cc
                          ON cc.id = cf.categoryid
                       WHERE cc.component = 'mod_booking'"
        );
        $forbiddenshortnames = array_intersect($boproperties, $usedshortnames);
        if (empty($forbiddenshortnames)) {
            return '';
        }
        $forbiddenshortnamesstring = implode(', ', $forbiddenshortnames);
        return $OUTPUT->notification(
            get_string('warningcustomfieldsforbiddenshortname', 'mod_booking', $forbiddenshortnamesstring),
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

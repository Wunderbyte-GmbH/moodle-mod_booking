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

namespace mod_booking\booking_rules;

use context_system;
use local_wunderbyte_table\local\customfield\wbt_field_controller_info;
use mod_booking\customfield\booking_handler;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Optional filter to restrict a booking rule to booking options with a certain field value.
 *
 * The filter is shared by all booking rules (days before, specific time, react on event).
 * It is stored within the ruledata of the rulejson, under the key "optionfieldfilter".
 * If no field is selected, the rule behaves exactly as before and applies to all booking options.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optionfield_filter {
    /** @var string Form element for the field to filter by. */
    public const FORMFIELD = 'rule_optionfieldfilter_field';

    /** @var string Form element for the operator. */
    public const FORMOPERATOR = 'rule_optionfieldfilter_operator';

    /** @var string Form element for the value to compare with. */
    public const FORMVALUE = 'rule_optionfieldfilter_value';

    /** @var string Key of the filter within ruledata. */
    public const JSONKEY = 'optionfieldfilter';

    /** @var string Prefix which marks a booking option customfield. */
    public const CUSTOMFIELDPREFIX = 'cf_';

    /** @var string Operator: value is exactly the given one. */
    public const OPERATOR_EQUALS = '=';

    /** @var string Operator: value is not exactly the given one. */
    public const OPERATOR_NOTEQUALS = '!=';

    /** @var string Operator: value contains the given one. */
    public const OPERATOR_CONTAINS = '~';

    /** @var string Operator: value does not contain the given one. */
    public const OPERATOR_NOTCONTAINS = '!~';

    /** @var string Operator: field has no value at all. */
    public const OPERATOR_EMPTY = 'empty';

    /** @var string Operator: field has any value. */
    public const OPERATOR_NOTEMPTY = '!empty';

    /**
     * The standard fields of a booking option which can be used for filtering.
     * Keys are columns of the {booking_options} table, values are string identifiers.
     *
     * @return array
     */
    public static function get_standard_fields(): array {
        return [
            'text' => 'ruleoptionfieldtext',
            'titleprefix' => 'titleprefix',
            'location' => 'ruleoptionfieldlocation',
            'address' => 'ruleoptionfieldaddress',
            'identifier' => 'ruleoptionfieldidentifier',
        ];
    }

    /**
     * Operators which do not need a value to compare with.
     *
     * @return array
     */
    public static function get_operators_without_value(): array {
        return [self::OPERATOR_EMPTY, self::OPERATOR_NOTEMPTY];
    }

    /**
     * Returns all selectable fields for the filter select element.
     *
     * @return array
     */
    public static function get_fields_for_select(): array {

        $fields = ['0' => get_string('ruleoptionfieldfilternofilter', 'mod_booking')];

        foreach (self::get_standard_fields() as $columnname => $stringid) {
            $fields[$columnname] = get_string($stringid, 'mod_booking');
        }

        // Now we add all customfields of booking options.
        foreach (booking_handler::get_customfields() as $customfield) {
            if (empty($customfield->shortname)) {
                continue;
            }
            $key = self::CUSTOMFIELDPREFIX . $customfield->shortname;
            $fields[$key] = format_string($customfield->name, true, ['context' => context_system::instance()])
                . " ($customfield->shortname)";
        }

        return $fields;
    }

    /**
     * Returns the operators for the filter select element.
     *
     * @return array
     */
    public static function get_operators_for_select(): array {
        return [
            self::OPERATOR_EQUALS => get_string('ruleoptionfieldoperatorequals', 'mod_booking'),
            self::OPERATOR_NOTEQUALS => get_string('ruleoptionfieldoperatornotequals', 'mod_booking'),
            self::OPERATOR_CONTAINS => get_string('ruleoptionfieldoperatorcontains', 'mod_booking'),
            self::OPERATOR_NOTCONTAINS => get_string('ruleoptionfieldoperatornotcontains', 'mod_booking'),
            self::OPERATOR_EMPTY => get_string('ruleoptionfieldoperatorempty', 'mod_booking'),
            self::OPERATOR_NOTEMPTY => get_string('ruleoptionfieldoperatornotempty', 'mod_booking'),
        ];
    }

    /**
     * Add the three form elements of the filter to the rule form.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public static function add_filter_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {

        $mform->addElement(
            'select',
            self::FORMFIELD,
            get_string('ruleoptionfieldfilter', 'mod_booking'),
            self::get_fields_for_select()
        );
        $mform->addHelpButton(self::FORMFIELD, 'ruleoptionfieldfilter', 'mod_booking');
        $mform->setDefault(self::FORMFIELD, '0');
        $repeateloptions[self::FORMFIELD]['type'] = PARAM_TEXT;

        $mform->addElement(
            'select',
            self::FORMOPERATOR,
            get_string('ruleoperator', 'mod_booking'),
            self::get_operators_for_select()
        );
        $repeateloptions[self::FORMOPERATOR]['type'] = PARAM_TEXT;
        // Without a field, there is no filter at all.
        $mform->hideIf(self::FORMOPERATOR, self::FORMFIELD, 'eq', '0');

        $mform->addElement(
            'text',
            self::FORMVALUE,
            get_string('ruleoptionfieldfiltervalue', 'mod_booking'),
            ['size' => '50']
        );
        $mform->setType(self::FORMVALUE, PARAM_TEXT);
        $repeateloptions[self::FORMVALUE]['type'] = PARAM_TEXT;
        $mform->hideIf(self::FORMVALUE, self::FORMFIELD, 'eq', '0');
        // "Is empty" and "is not empty" do not need a value.
        $mform->hideIf(self::FORMVALUE, self::FORMOPERATOR, 'eq', self::OPERATOR_EMPTY);
        $mform->hideIf(self::FORMVALUE, self::FORMOPERATOR, 'eq', self::OPERATOR_NOTEMPTY);
    }

    /**
     * Store the filter within the ruledata of the rulejson.
     * If no field was chosen, no key is added at all, so the rule behaves as before.
     *
     * @param stdClass $data the form data
     * @param stdClass $jsonobject the rulejson object, ruledata has to be set already
     * @return void
     */
    public static function save_filter(stdClass $data, stdClass $jsonobject) {

        $fieldname = $data->{self::FORMFIELD} ?? '0';

        if (empty($fieldname) || $fieldname === '0') {
            // No filter. We make sure that an old filter is removed.
            unset($jsonobject->ruledata->{self::JSONKEY});
            return;
        }

        $filter = new stdClass();
        $filter->fieldname = (string) $fieldname;
        $filter->operator = (string) ($data->{self::FORMOPERATOR} ?? self::OPERATOR_EQUALS);
        $filter->value = in_array($filter->operator, self::get_operators_without_value())
            ? ''
            : (string) ($data->{self::FORMVALUE} ?? '');

        $jsonobject->ruledata->{self::JSONKEY} = $filter;
    }

    /**
     * Set the form defaults of the filter when an existing rule is loaded.
     *
     * @param stdClass $data reference to the default values
     * @param stdClass $ruledata the ruledata of the rulejson
     * @return void
     */
    public static function set_defaults(stdClass &$data, stdClass $ruledata) {

        $filter = self::get_filter($ruledata);

        if (empty($filter)) {
            $data->{self::FORMFIELD} = '0';
            $data->{self::FORMOPERATOR} = self::OPERATOR_EQUALS;
            $data->{self::FORMVALUE} = '';
            return;
        }

        $data->{self::FORMFIELD} = $filter->fieldname;
        $data->{self::FORMOPERATOR} = $filter->operator;
        $data->{self::FORMVALUE} = $filter->value;
    }

    /**
     * Returns the filter of a rule, or null if there is no filter.
     *
     * @param ?object $ruledata the ruledata of the rulejson
     * @return ?stdClass
     */
    public static function get_filter(?object $ruledata): ?stdClass {

        if (empty($ruledata->{self::JSONKEY})) {
            return null;
        }

        $filter = $ruledata->{self::JSONKEY};

        if (empty($filter->fieldname) || $filter->fieldname === '0') {
            return null;
        }

        $filter->operator = $filter->operator ?? self::OPERATOR_EQUALS;
        $filter->value = (string) ($filter->value ?? '');

        return $filter;
    }

    /**
     * Add the filter to the SQL of a rule.
     * The SQL has to use the alias "bo" for the {booking_options} table.
     * If the rule has no filter, nothing is changed at all.
     *
     * @param stdClass $sql
     * @param array $params
     * @param ?object $ruledata the ruledata of the rulejson
     * @return void
     */
    public static function apply_to_sql(stdClass &$sql, array &$params, ?object $ruledata) {

        $filter = self::get_filter($ruledata);

        if (empty($filter)) {
            return;
        }

        $negate = self::is_negative_operator($filter->operator);
        $positiveoperator = self::get_positive_operator($filter->operator);
        $values = self::get_values_to_compare($filter);

        if (self::is_customfield($filter->fieldname)) {
            $condition = self::get_customfield_sql($filter, $positiveoperator, $values, $params);
        } else {
            $standardfields = self::get_standard_fields();
            if (!isset($standardfields[$filter->fieldname])) {
                // Unknown field, e.g. a customfield which was deleted in the meantime. The rule cannot apply.
                $sql->where .= " AND 1 = 2 ";
                return;
            }
            $condition = self::get_compare_sql("bo." . $filter->fieldname, $positiveoperator, $values, $params, false);
        }

        $sql->where .= $negate ? " AND NOT ($condition) " : " AND ($condition) ";
    }

    /**
     * Check whether one single booking option matches the filter of the rule.
     * This is needed for rules which do not rebuild their SQL before the action is executed.
     *
     * @param int $optionid
     * @param ?object $ruledata the ruledata of the rulejson
     * @return bool true if the rule may be applied to this booking option
     */
    public static function option_matches(int $optionid, ?object $ruledata): bool {

        $filter = self::get_filter($ruledata);

        if (empty($filter)) {
            // No filter, so the rule applies to every booking option.
            return true;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (self::is_customfield($filter->fieldname)) {
            $shortname = self::get_shortname($filter->fieldname);
            // Booking option settings already contain the default value of a customfield without own value.
            $value = $settings->customfields[$shortname] ?? '';
        } else {
            $standardfields = self::get_standard_fields();
            if (!isset($standardfields[$filter->fieldname])) {
                // Unknown field, e.g. a customfield which was deleted in the meantime. The rule cannot apply.
                return false;
            }
            $value = $settings->{$filter->fieldname} ?? '';
        }

        $matches = self::value_matches(
            self::value_to_string($value),
            self::get_positive_operator($filter->operator),
            self::get_values_to_compare($filter)
        );

        return self::is_negative_operator($filter->operator) ? !$matches : $matches;
    }

    /**
     * Operators which are the negation of another operator.
     * We always compare with the positive operator and negate the result afterwards.
     * Like this, sql and php check always come to the same conclusion.
     *
     * @param string $operator
     * @return bool
     */
    private static function is_negative_operator(string $operator): bool {
        return in_array(
            $operator,
            [self::OPERATOR_NOTEQUALS, self::OPERATOR_NOTCONTAINS, self::OPERATOR_EMPTY],
            true
        );
    }

    /**
     * Returns the positive counterpart of an operator.
     *
     * @param string $operator
     * @return string
     */
    private static function get_positive_operator(string $operator): string {
        switch ($operator) {
            case self::OPERATOR_NOTEQUALS:
                return self::OPERATOR_EQUALS;
            case self::OPERATOR_NOTCONTAINS:
                return self::OPERATOR_CONTAINS;
            case self::OPERATOR_EMPTY:
                // A field is empty when it does not have any value.
                return self::OPERATOR_NOTEMPTY;
            default:
                return $operator;
        }
    }

    /**
     * Turn the value of a field into a string we can compare.
     * Customfields with multiple values can be arrays.
     *
     * @param mixed $value
     * @return string
     */
    private static function value_to_string($value): string {
        if (is_array($value)) {
            return implode(',', $value);
        }
        return (string) $value;
    }

    /**
     * Returns the values we really have to compare with.
     *
     * Fields like select or multiselect do not store the value the user sees, but a key.
     * So when the user enters the value she sees in the booking option, we translate it to the stored key(s).
     * For all other fields, we simply use the entered value.
     *
     * @param stdClass $filter
     * @return array
     */
    private static function get_values_to_compare(stdClass $filter): array {

        if (
            !self::is_customfield($filter->fieldname)
            || trim($filter->value) === ''
            || !class_exists('local_wunderbyte_table\local\customfield\wbt_field_controller_info')
        ) {
            return [$filter->value];
        }

        $mapping = wbt_field_controller_info::get_resolved_value_mapping(
            self::get_shortname($filter->fieldname),
            'mod_booking',
            'booking'
        );

        if (empty($mapping)) {
            // Text like fields store exactly what the user sees.
            return [$filter->value];
        }

        $needle = \core_text::strtolower(trim($filter->value));

        $storedvalues = [];
        foreach ($mapping as $storedvalue => $resolvedvalue) {
            $label = self::value_to_string($resolvedvalue);
            // The labels are language neutral, so they can still contain multilang tags.
            // Rules run at the end of the request (shutdown), where $PAGE has no context anymore.
            // So we always have to pass the context explicitly.
            $labels = [$label, format_string($label, true, ['context' => context_system::instance()])];
            foreach ($labels as $comparelabel) {
                if (\core_text::strtolower(trim($comparelabel)) === $needle) {
                    $storedvalues[] = (string) $storedvalue;
                    break;
                }
            }
        }

        // If the user entered a value which is not a visible label, we use it as it is.
        return empty($storedvalues) ? [$filter->value] : $storedvalues;
    }

    /**
     * Compare a concrete value with the filter.
     *
     * @param string $value
     * @param string $positiveoperator
     * @param array $values the values to compare with
     * @return bool
     */
    private static function value_matches(string $value, string $positiveoperator, array $values): bool {

        // We compare case insensitively, just like the SQL does.
        $haystack = \core_text::strtolower(trim($value));

        if ($positiveoperator === self::OPERATOR_NOTEMPTY) {
            return $haystack !== '';
        }

        foreach ($values as $comparevalue) {
            $needle = \core_text::strtolower(trim(self::value_to_string($comparevalue)));
            if ($positiveoperator === self::OPERATOR_CONTAINS) {
                if ($needle === '' || strpos($haystack, $needle) !== false) {
                    return true;
                }
            } else if ($haystack === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the chosen field a customfield of booking options?
     *
     * @param string $fieldname
     * @return bool
     */
    private static function is_customfield(string $fieldname): bool {
        return strpos($fieldname, self::CUSTOMFIELDPREFIX) === 0;
    }

    /**
     * Returns the shortname of the customfield.
     *
     * @param string $fieldname
     * @return string
     */
    private static function get_shortname(string $fieldname): string {
        return substr($fieldname, strlen(self::CUSTOMFIELDPREFIX));
    }

    /**
     * Returns the default value of one booking option customfield.
     *
     * A booking option without an own value for a customfield still has the default value of this field.
     * The booking option settings return this default value as well, so the sql has to know about it too.
     *
     * @param string $shortname
     * @return string
     */
    private static function get_default_value_of_customfield(string $shortname): string {
        global $PAGE;

        foreach (booking_handler::create()->get_fields() as $field) {
            if ($field->get('shortname') !== $shortname) {
                continue;
            }

            // Rules are executed at the very end of the request (see register_shutdown_function in lib.php).
            // In AJAX requests, $PAGE has no context anymore at this point...
            // ...but some field types format their default value, which needs a context.
            // Passing null only sets the system context if there is no context yet, so a real context is never changed.
            $PAGE->set_context(null);

            // This is exactly how core builds the data of a field which has no value stored for an instance.
            $data = \core_customfield\data_controller::create(0, (object) ['instanceid' => 0], $field);
            return self::value_to_string($data->get_value());
        }

        return '';
    }

    /**
     * Build the clause needed to filter by a customfield of the booking option.
     *
     * A booking option matches when it either has a matching value stored...
     * ...or when it has no value at all and the default value of the field matches.
     *
     * @param stdClass $filter
     * @param string $positiveoperator
     * @param array $values the values to compare with
     * @param array $params
     * @return string
     */
    private static function get_customfield_sql(
        stdClass $filter,
        string $positiveoperator,
        array $values,
        array &$params
    ): string {

        $shortname = self::get_shortname($filter->fieldname);
        $params['optionfieldfiltershortname'] = $shortname;

        $compare = self::get_compare_sql('cfd.value', $positiveoperator, $values, $params, true);

        $sql = " EXISTS (
                    SELECT 1
                    FROM {customfield_data} cfd
                    JOIN {customfield_field} cff ON cff.id = cfd.fieldid
                    JOIN {customfield_category} cfc ON cfc.id = cff.categoryid
                    WHERE cfd.instanceid = bo.id
                    AND cfc.component = 'mod_booking'
                    AND cfc.area = 'booking'
                    AND cff.shortname = :optionfieldfiltershortname
                    AND $compare
                ) ";

        // If the default value of the field matches, booking options without an own value match as well.
        $default = self::get_default_value_of_customfield($shortname);
        if (self::value_matches($default, $positiveoperator, $values)) {
            $params['optionfieldfiltershortname2'] = $shortname;
            $sql .= " OR NOT EXISTS (
                    SELECT 1
                    FROM {customfield_data} cfd2
                    JOIN {customfield_field} cff2 ON cff2.id = cfd2.fieldid
                    JOIN {customfield_category} cfc2 ON cfc2.id = cff2.categoryid
                    WHERE cfd2.instanceid = bo.id
                    AND cfc2.component = 'mod_booking'
                    AND cfc2.area = 'booking'
                    AND cff2.shortname = :optionfieldfiltershortname2
                ) ";
        }

        return $sql;
    }

    /**
     * Build the comparison of one column with the values of the filter.
     * Only positive operators are used here, the negation happens in apply_to_sql.
     *
     * @param string $column the column, e.g. "bo.text" or "cfd.value"
     * @param string $positiveoperator
     * @param array $values the values to compare with
     * @param array $params
     * @param bool $istextcolumn true for columns of the type text (needs special treatment in some DBs)
     * @return string
     */
    private static function get_compare_sql(
        string $column,
        string $positiveoperator,
        array $values,
        array &$params,
        bool $istextcolumn
    ): string {
        global $DB;

        // Text columns cannot be compared directly in all databases.
        $comparecolumn = $istextcolumn ? $DB->sql_compare_text($column, 255) : $column;
        // With an empty string instead of null, we can negate the whole comparison without losing records.
        $comparecolumn = "COALESCE($comparecolumn, '')";

        if ($positiveoperator === self::OPERATOR_NOTEMPTY) {
            return " $comparecolumn <> '' ";
        }

        $parts = [];

        foreach (array_values($values) as $counter => $value) {
            $paramname = 'optionfieldfiltervalue' . $counter;
            if ($positiveoperator === self::OPERATOR_CONTAINS) {
                $params[$paramname] = '%' . $DB->sql_like_escape(self::value_to_string($value)) . '%';
            } else {
                // We use LIKE without wildcards, so that equals is case insensitive as well.
                $params[$paramname] = $DB->sql_like_escape(self::value_to_string($value));
            }
            $parts[] = $DB->sql_like($comparecolumn, ":$paramname", false);
        }

        return " (" . implode(" OR ", $parts) . ") ";
    }

    /**
     * Validation of the filter elements in the rules form.
     *
     * @param array $data
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array &$errors) {

        $fieldname = $data[self::FORMFIELD] ?? '0';

        if (empty($fieldname) || $fieldname === '0') {
            // No filter, nothing to validate.
            return;
        }

        $operator = $data[self::FORMOPERATOR] ?? self::OPERATOR_EQUALS;

        if (in_array($operator, self::get_operators_without_value(), true)) {
            // These operators do not need a value.
            return;
        }

        if (trim((string) ($data[self::FORMVALUE] ?? '')) === '') {
            $errors[self::FORMVALUE] = get_string('error:entervalue', 'mod_booking');
        }
    }
}

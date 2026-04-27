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

namespace mod_booking\local\certificate_conditions;

use core_component;
use MoodleQuickForm;

/**
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filters_info {
    /**
     * Add form fields for filters.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public static function add_filters_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $filters = self::get_filters();
        $filtersforselect = [];
        $eligiblefilters = [];
        foreach ($filters as $filter) {
            $fullclassname = get_class($filter);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);

            // Skip filter only when it explicitly declares itself incompatible.
            if (
                method_exists($filter, 'is_compatible_with_ajaxformdata')
                && !$filter->is_compatible_with_ajaxformdata($ajaxformdata)
            ) {
                continue;
            }

            $filtersforselect[$shortclassname] = $filter->get_name_of_filter();
            $eligiblefilters[] = $filter;
        }

        // Add "no restriction" option at the beginning.
        $filtersforselect = ['norestriction' => get_string('certificatefilternorestriction', 'mod_booking')] + $filtersforselect;
        $mform->registerNoSubmitButton('btn_certificatefiltertype');
        $mform->addElement(
            'select',
            'certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'),
            $filtersforselect,
        );
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement(
            'submit',
            'btn_certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'),
            $buttonargs
        );
        $mform->setType('btn_certificatefiltertype', PARAM_NOTAGS);

        $defaultfiltertype = '0';
        if (
            !empty($eligiblefilters)
            && !empty($ajaxformdata['certificatefiltertype'])
            && (string)$ajaxformdata['certificatefiltertype'] !== 'norestriction'
        ) {
            $firstfilterclassname = get_class($eligiblefilters[0]);
            $classnameparts = explode('\\', $firstfilterclassname);
            $defaultfiltertype = end($classnameparts) ?: '0';
        }

        $selectedfiltertype = $ajaxformdata['certificatefiltertype'] ?? $defaultfiltertype;

        if ((string)$selectedfiltertype === '0') {
            $filter = null;
        } else {
            $filter = self::get_filter((string)$selectedfiltertype);
            if (!$filter) {
                $selectedfiltertype = $defaultfiltertype;
                $filter = self::get_filter((string)$selectedfiltertype);
            }
        }

        $mform->setDefault('certificatefiltertype', $selectedfiltertype);
        if (is_array($ajaxformdata)) {
            $ajaxformdata['certificatefiltertype'] = $selectedfiltertype;
        }

        if ($filter) {
            $filter->add_filter_to_mform($mform, $ajaxformdata);
        }
    }

    /**
     * Return instances of all filter classes.
     * @return array
     */
    public static function get_filters() {
        $classes = [];
        $conditions = core_component::get_component_classes_in_namespace(
            'mod_booking',
            'local\\certificate_conditions\\filters'
        );
        foreach ($conditions as $classname => $namespace) {
            $classes[] = new $classname();
        }
        return $classes;
    }

    /**
     * Get specific filter by short name.
     * @param string $name
     * @return mixed|null
     */
    public static function get_filter(string $name) {
        $classname = 'mod_booking\\local\\certificate_conditions\\filters\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

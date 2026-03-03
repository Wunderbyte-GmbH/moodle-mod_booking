<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;

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
        $filtersforselect = ['0' => get_string('certificatefilternorestriction', 'mod_booking')];
        $eligiblefilters = [];
        foreach ($filters as $filter) {
            $fullclassname = get_class($filter);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);

            // Skip filter only when it explicitly declares itself incompatible.
            if (method_exists($filter, 'is_compatible_with_ajaxformdata')
                && !$filter->is_compatible_with_ajaxformdata($ajaxformdata)) {
                continue;
            }

            $filtersforselect[$shortclassname] = $filter->get_name_of_filter();
            $eligiblefilters[] = $filter;
        }

        $mform->registerNoSubmitButton('btn_certificatefiltertype');
        $mform->addElement('select', 'certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'), $filtersforselect);
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement('submit', 'btn_certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'), $buttonargs);
        $mform->setType('btn_certificatefiltertype', PARAM_NOTAGS);

        $defaultfiltertype = '0';
        if (!empty($eligiblefilters)) {
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
        global $CFG;
        $path = $CFG->dirroot . '/mod/booking/classes/local/certificate_conditions/filters/*.php';
        $files = glob($path);
        $filters = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\local\\certificate_conditions\\filters\\' . $pathinfo['filename'];
            if (class_exists($classname)) {
                $filters[] = new $classname();
            }
        }
        return $filters;
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

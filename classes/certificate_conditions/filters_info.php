<?php
namespace mod_booking\certificate_conditions;

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
        $filtersforselect = ['0' => get_string('choose...', 'mod_booking')];
        foreach ($filters as $filter) {
            $fullclassname = get_class($filter);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            if (!$filter->is_compatible_with_ajaxformdata ?? true) {
                continue;
            }
            $filtersforselect[$shortclassname] = $filter->get_name_of_filter();
        }

        $mform->registerNoSubmitButton('btn_certificatefiltertype');
        $mform->addElement('select', 'certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'), $filtersforselect);
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement('submit', 'btn_certificatefiltertype',
            get_string('certificatefilter', 'mod_booking'), $buttonargs);
        $mform->setType('btn_certificatefiltertype', PARAM_NOTAGS);

        if (isset($ajaxformdata['certificatefiltertype'])) {
            $filter = self::get_filter($ajaxformdata['certificatefiltertype']);
        } else {
            [$filter] = $filters;
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
        $path = $CFG->dirroot . '/mod/booking/classes/certificate_conditions/filters/*.php';
        $files = glob($path);
        $filters = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\certificate_conditions\\filters\\' . $pathinfo['filename'];
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
        $classname = 'mod_booking\\certificate_conditions\\filters\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

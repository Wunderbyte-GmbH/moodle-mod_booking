<?php
namespace mod_booking\certificate_conditions;

use MoodleQuickForm;

class actions_info {
    public static function add_actions_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $actions = self::get_actions();
        $actionsforselect = ['0' => get_string('choose...', 'mod_booking')];
        foreach ($actions as $action) {
            $fullclassname = get_class($action);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            $actionsforselect[$shortclassname] = $action->get_name_of_action();
        }
        $mform->registerNoSubmitButton('btn_certificateactiontype');
        $mform->addElement('select', 'certificateactiontype',
            get_string('certificateaction', 'mod_booking'), $actionsforselect);
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement('submit', 'btn_certificateactiontype',
            get_string('certificateaction', 'mod_booking'), $buttonargs);
        $mform->setType('btn_certificateactiontype', PARAM_NOTAGS);

        if (isset($ajaxformdata['certificateactiontype'])) {
            $action = self::get_action($ajaxformdata['certificateactiontype']);
        } else {
            [$action] = $actions;
        }
        if ($action) {
            $action->add_action_to_mform($mform, $ajaxformdata);
        }
    }

    public static function get_actions() {
        global $CFG;
        $path = $CFG->dirroot . '/mod/booking/classes/certificate_conditions/actions/*.php';
        $files = glob($path);
        $actions = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\certificate_conditions\\actions\\' . $pathinfo['filename'];
            if (class_exists($classname)) {
                $actions[] = new $classname();
            }
        }
        return $actions;
    }

    public static function get_action(string $name) {
        $classname = 'mod_booking\\certificate_conditions\\actions\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

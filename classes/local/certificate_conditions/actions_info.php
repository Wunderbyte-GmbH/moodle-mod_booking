<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;

class actions_info {
    /**
     * Add action selector and selected action fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
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

        $defaultactiontype = '0';
        if (!empty($actions)) {
            $firstactionclassname = get_class($actions[0]);
            $classnameparts = explode('\\', $firstactionclassname);
            $defaultactiontype = end($classnameparts) ?: '0';
        }

        $selectedactiontype = $ajaxformdata['certificateactiontype'] ?? $defaultactiontype;
        $action = self::get_action((string)$selectedactiontype);
        if (!$action) {
            $selectedactiontype = $defaultactiontype;
            $action = self::get_action((string)$selectedactiontype);
        }

        $mform->setDefault('certificateactiontype', $selectedactiontype);
        if (is_array($ajaxformdata)) {
            $ajaxformdata['certificateactiontype'] = $selectedactiontype;
        }

        if ($action) {
            $action->add_action_to_mform($mform, $ajaxformdata);
        }
    }

    /**
     * Return instances of all available action handlers.
     *
     * @return array
     */
    public static function get_actions() {
        global $CFG;
        $path = $CFG->dirroot . '/mod/booking/classes/local/certificate_conditions/actions/*.php';
        $files = glob($path);
        $actions = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\local\\certificate_conditions\\actions\\' . $pathinfo['filename'];
            if (class_exists($classname)) {
                $actions[] = new $classname();
            }
        }
        return $actions;
    }

    /**
     * Return one action handler instance by short name.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function get_action(string $name) {
        $classname = 'mod_booking\\local\\certificate_conditions\\actions\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

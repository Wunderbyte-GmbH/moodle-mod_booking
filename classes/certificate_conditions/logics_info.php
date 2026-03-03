<?php
namespace mod_booking\certificate_conditions;

use MoodleQuickForm;

class logics_info {
    public static function add_logics_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $logics = self::get_logics();
        $logicsforselect = ['0' => get_string('choose...', 'mod_booking')];
        foreach ($logics as $logic) {
            $fullclassname = get_class($logic);
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts);
            $logicsforselect[$shortclassname] = $logic->get_name_of_logic();
        }
        $mform->registerNoSubmitButton('btn_certificatelogictype');
        $mform->addElement('select', 'certificatelogictype',
            get_string('certificatelogic', 'mod_booking'), $logicsforselect);
        $buttonargs = ['style' => 'visibility:hidden;'];
        $mform->addElement('submit', 'btn_certificatelogictype',
            get_string('certificatelogic', 'mod_booking'), $buttonargs);
        $mform->setType('btn_certificatelogictype', PARAM_NOTAGS);

        if (isset($ajaxformdata['certificatelogictype'])) {
            $logic = self::get_logic($ajaxformdata['certificatelogictype']);
        } else {
            [$logic] = $logics;
        }
        if ($logic) {
            $logic->add_logic_to_mform($mform, $ajaxformdata);
        }
    }

    public static function get_logics() {
        global $CFG;
        $path = $CFG->dirroot . '/mod/booking/classes/certificate_conditions/logics/*.php';
        $files = glob($path);
        $logics = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\certificate_conditions\\logics\\' . $pathinfo['filename'];
            if (class_exists($classname)) {
                $logics[] = new $classname();
            }
        }
        return $logics;
    }

    public static function get_logic(string $name) {
        $classname = 'mod_booking\\certificate_conditions\\logics\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

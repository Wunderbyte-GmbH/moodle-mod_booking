<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;

class logics_info {
    /**
     * Add logic selector and selected logic fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
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

        $defaultlogictype = '0';
        if (!empty($logics)) {
            $firstlogicclassname = get_class($logics[0]);
            $classnameparts = explode('\\', $firstlogicclassname);
            $defaultlogictype = end($classnameparts) ?: '0';
        }

        $selectedlogictype = $ajaxformdata['certificatelogictype'] ?? $defaultlogictype;
        $logic = self::get_logic((string)$selectedlogictype);
        if (!$logic) {
            $selectedlogictype = $defaultlogictype;
            $logic = self::get_logic((string)$selectedlogictype);
        }

        $mform->setDefault('certificatelogictype', $selectedlogictype);
        if (is_array($ajaxformdata)) {
            $ajaxformdata['certificatelogictype'] = $selectedlogictype;
        }

        if ($logic) {
            $logic->add_logic_to_mform($mform, $ajaxformdata);
        }
    }

    /**
     * Return instances of all available logic handlers.
     *
     * @return array
     */
    public static function get_logics() {
        global $CFG;
        $path = $CFG->dirroot . '/mod/booking/classes/local/certificate_conditions/logics/*.php';
        $files = glob($path);
        $logics = [];
        foreach ($files as $filepath) {
            $pathinfo = pathinfo($filepath);
            $classname = 'mod_booking\\local\\certificate_conditions\\logics\\' . $pathinfo['filename'];
            if (class_exists($classname)) {
                $logics[] = new $classname();
            }
        }
        return $logics;
    }

    /**
     * Return one logic handler instance by short name.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function get_logic(string $name) {
        $classname = 'mod_booking\\local\\certificate_conditions\\logics\\' . $name;
        if (class_exists($classname)) {
            return new $classname();
        }
        return null;
    }
}

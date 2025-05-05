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

namespace mod_booking\booking_rules\rules\templates;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule do something a specified number of days before a chosen date.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruletemplate_sessionreminders {
    /** @var int $templateid */
    public static $templateid = 13;

    /** @var int $eventtype */
    public static $eventtype = 'rule_daysbefore';

    /**
     * Returns the localized name of this template
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('ruletemplatesessionreminders', 'booking');
    }

    /**
     * Returns the template as if it came from DB.
     *
     * @return object
     *
     */
    public static function return_template() {

        $rulejson = (object)[
            "conditionname" => "select_student_in_bo",
            "conditiondata" => [
                "borole" => "0",
            ],
            "name" => self::get_name(),
            "actionname" => "send_mail",
            "actiondata" => [
                "subject" => get_string('ruletemplatesessionreminderssubject', 'booking'),
                "template" => get_string('ruletemplatesessionremindersbody', 'booking'),
                "templateformat" => "1",
            ],
            "rulename" => "rule_daysbefore",
            "ruledata" => [
                "days" => "1",
                "datefield" => "optiondatestarttime",
            ],
        ];

        $returnobject = [
            'id' => self::$templateid,
            'rulename' => self::$eventtype,
            'rulejson' => json_encode($rulejson),
            'contextid' => 1,
            'useastemplate' => 0,
        ];
        return (object) $returnobject;
    }
}

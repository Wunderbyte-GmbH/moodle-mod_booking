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

use context;
use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\conditions_info;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule do something a specified number of days before a chosen date.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruletemplate_bookingoptioncompleted {
    /** @var int $templateid */
    public static $templateid = 9;

    /** @var int $eventtype */
    public static $eventtype = 'rule_react_on_event';

    /**
     * Returns the localized name of this template
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('ruletemplatebookingoptioncompleted', 'booking');
    }

    /**
     * Returns the template as if it came from DB.
     *
     * @return object
     *
     */
    public static function return_template() {

        $rulejson = (object)[
            "conditionname" => "select_user_from_event",
            "conditiondata" => [
                "userfromeventtype" => "relateduserid",
            ],
            "name" => self::get_name(),
            "actionname" => "send_mail",
            "actiondata" => [
                "subject" => get_string('ruletemplatebookingoptioncompletedsubject', 'booking'),
                "template" => get_string('ruletemplatebookingoptioncompletedbody', 'booking'),
                "templateformat" => "1",
            ],
            "rulename" => "rule_react_on_event",
            "ruledata" => [
                "boevent" => "\\mod_booking\\event\bookingoption_completed",
                "condition" => "0",
                "aftercompletion" => 1,
                "cancelrules" => [],
            ],
        ];

        $returnobject = [
            'id' => self::$templateid,
            'rulename' => self::$eventtype,
            'rulejson' => json_encode($rulejson),
            'eventname' => "\\mod_booking\\event\bookingoption_completed",
            'contextid' => 1,
            'useastemplate' => 0,
        ];
        return (object) $returnobject;
    }
}

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

/**
 * Defines message providers (types of messages being sent)
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\UnsupportedDriverActionException;
use Behat\Mink\Exception\DriverException;
use mod_booking\singleton_service;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\conditions\maxoptionsfromcategory;

/**
 * To create booking specific behat scearios.
 */
class behat_mod_booking extends behat_base {
    /**
     * Create booking option in booking instance
     * @Given /^I create booking option "(?P<optionname_string>(?:[^"]|\\")*)" in "(?P<instancename_string>(?:[^"]|\\")*)"$/
     * @param string $optionname
     * @param string $instancename
     * @return void
     */
    public function i_create_booking_option($optionname, $instancename) {

        $cm = $this->get_cm_by_booking_name($instancename);

        $booking = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optionname;
        $record->courseid = $cm->course;
        $record->description = 'Test description';

        $datagenerator = \testing_util::get_data_generator();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $datagenerator->get_plugin_generator('mod_booking');
        $bookingoption1 = $plugingenerator->create_option($record);
    }

    /**
     * Follow a certain link
     * @Given /^I open the link "(?P<linkurl_string>(?:[^"]|\\")*)"$/
     * @param string $linkurl
     * @return void
     */
    public function i_open_the_link($linkurl) {
        $this->getSession()->visit($linkurl);
    }

    /**
     * Get a booking by booking instance name.
     *
     * @param string $name booking instance name.
     * @return stdClass the corresponding DB row.
     */
    protected function get_booking_by_name(string $name): stdClass {
        global $DB;
        return $DB->get_record('booking', ['name' => $name], '*', MUST_EXIST);
    }

    /**
     * Get a booking coursemodule object from the name.
     *
     * @param string $name name.
     * @return stdClass cm from get_coursemodule_from_instance.
     */
    protected function get_cm_by_booking_name(string $name): stdClass {
        $booking = $this->get_booking_by_name($name);
        return get_coursemodule_from_instance('booking', $booking->id, $booking->course);
    }

    /**
     * Fill specified HTMLQuickForm element by its number under given xpath with a value.
     * @When /^I click on the element with the number "([^"]*)" with the dynamic identifier "([^"]*)" and action "([^"]*)"$/
     * @param mixed $numberofitem
     * @param mixed $containeridentifier
     * @param mixed $actionidentifier
     * @return void
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @throws UnsupportedDriverActionException
     * @throws DriverException
     */
    public function i_click_on_element($numberofitem, $containeridentifier, $actionidentifier) {
        // Use $dynamicIdentifier to locate and fill in the corresponding form field.
        // Use $value to set the desired value in the form field.

        // First we need to open all collapsibles.
        // We should probably have a single fuction for that.
        $xpathtarget = "//tr[starts-with(@id, '" . $containeridentifier . "')]//a[@data-methodname='" . $actionidentifier . "']";
        $fields = $this->getSession()->getPage()->findAll('xpath', $xpathtarget);

        $counter = 1;
        foreach ($fields as $field) {
            if ($counter == $numberofitem) {
                $field->click();
            }
            $counter++;
        }
    }

    /**
     * Clean bookig singleton cache
     * @Given /^I clean booking cache$/
     * @return void
     */
    public function i_clean_booking_cache() {
            // Mandatory clean-up.
            cache_helper::purge_all();
            singleton_service::reset_campaigns();
            singleton_service::get_instance()->users = [];
            singleton_service::get_instance()->bookinganswers = [];
            singleton_service::get_instance()->userpricecategory = [];
            rules_info::$rulestoexecute = [];
            booking_rules::$rules = [];
            maxoptionsfromcategory::reset_instance();
            singleton_service::destroy_instance();
    }

    /**
     * Rename bookingoption children
     * @Given /^I rename my bookingoption children$/
     * @return void
     */
    public function i_rename_my_bookingoption_children() {
        global $DB;
        $sql = "
            SELECT * FROM {booking_options}
            WHERE parentid > 0
            ORDER BY coursestarttime ASC
        ";
        $children = $DB->get_records_sql($sql, []);

        $i = 1;
        foreach ($children as $child) {
            $data = [
                'text' => 'child ' . $i,
                'id' => $child->id,
            ];
            $DB->update_record('booking_options', $data);
            $i++;
        };
    }
}

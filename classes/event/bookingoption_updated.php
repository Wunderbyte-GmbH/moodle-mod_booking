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
 * The bookingoption_updated event.
 *
 * @package mod_booking
 * @copyright 2014 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;
use Exception;
use mod_booking\output\bookingoption_changes;
use mod_booking\singleton_service;
use Throwable;

/**
 * The bookingoption_updated event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_updated extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'u'; // Meaning: u = update.
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('bookingoptionupdated', 'booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        return $this->generate_description(false);
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/report.php', ['id' => $this->contextinstanceid, 'optionid' => $this->objectid]);
    }

    /**
     * Get short description i.e. for display in mail.
     *
     * @return string
     *
     */
    public function get_simplified_description() {
        return $this->generate_description(true);
    }

    /**
     * Generate description either from default or simplified template.
     *
     * @param bool $simplified
     *
     * @return string
     *
     */
    private function generate_description($simplified = false) {
        global $PAGE;

        try {
            $data = $this->get_data();
            $jsonstring = isset($data['other']) ? $data['other'] : '[]';
            if (gettype($jsonstring) == 'string') {
                $changes = (array) json_decode($jsonstring);
            }

            if (!empty($changes) && !empty($data['objectid'])) {
                $settings = singleton_service::get_instance_of_booking_option_settings($data['objectid']);

                $data = new bookingoption_changes($changes, $settings->cmid);
                $renderer = $PAGE->get_renderer('mod_booking');
                $html = $renderer->render_bookingoption_changes($data);
            } else {
                $html = '';
            }

            if ($simplified) {
                return $html;
            }

            $infos = (object) [
                'userid' => $this->userid,
                'objectid' => $this->objectid,
            ];
            $infostring = get_string('bookingoptionupdateddesc', 'mod_booking', $infos);
            return format_text($infostring . $html);
        } catch (Throwable $e) {
            return get_string('bookingoptionupdated', 'mod_booking');
        }
    }
}

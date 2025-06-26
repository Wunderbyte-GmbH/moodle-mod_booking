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
 * This file contains the definition for the renderable classes for column 'teacher'.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    David Ala
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying the column 'responsiblecontacts'.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_responsiblecontacts implements renderable, templatable {
    /** @var array $contacts array of contacts */
    public $contacts = [];

    /**
     * Constructor
     *
     * @param int $optionid
     * @param booking_option_settings $settings
     */
    public function __construct(int $optionid, booking_option_settings $settings) {
        foreach ($settings->responsiblecontact as $contact) {
            if ($user = singleton_service::get_instance_of_user((int) $contact)) {
                $responsiblecontact = new stdClass();

                $responsiblecontact->name = "$user->firstname $user->lastname";
                $responsiblecontact->url = new moodle_url('/user/profile.php', ['id' => (int) $contact]);
                $this->contacts[] = $responsiblecontact;
            }
        }
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return [
            'contacts' => $this->contacts,
        ];
    }
}

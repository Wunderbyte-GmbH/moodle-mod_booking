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

namespace mod_booking\output\description;

use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

/**
 * Class description_ical
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_ical extends description_base {
    /**
     * Template name.
     * @var int
     */
    protected string $template = 'mod_booking/bookingoption_description_ical';

    /**
     * descriptionparam
     * @var int
     */
    protected int $param = MOD_BOOKING_DESCRIPTION_ICAL;

    /**
     * Render the description.
     *
     * @return string
     */
    public function render(): string {
        // Get the custom field name for iCal description.
        $cfname = get_config('booking', 'icaldescriptionfield');
        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        // If there is a user defined template for iCal description, use it.
        if (!empty($settings->customfields[$cfname])) {
            $userdefinedtemplate = $settings->customfields[$cfname];
            // We use the placeholders_info class to render the text with placeholders.
            $o = placeholders_info::render_text(
                $userdefinedtemplate,
                $settings->cmid,
                $this->optionid,
                0,
                0,
                0,
                0,
                $this->param
            );
            return $o;
        }

        return parent::render();
    }
}

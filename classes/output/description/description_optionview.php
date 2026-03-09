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

/**
 * Class description_optionview
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_optionview extends description_base {
    /**
     * Template name.
     * @var int
     */
    protected string $template = 'mod_booking/bookingoption_description_optionview';

    /**
     * Description param.
     * @var int
     */
    protected int $param = MOD_BOOKING_DESCRIPTION_OPTIONVIEW;

    /**
     * Render the description.
     *
     * @return string
     */
    public function render(): string {
        $o = '';
        $data = $this->data->export_for_template($this->output);
        try {
            $o .= $this->output->render_from_template($this->template, $data);
        } catch (\Exception $e) {
            $o .= get_string('bookingoptionupdated', 'mod_booking');
        }
        return $o;
    }
}

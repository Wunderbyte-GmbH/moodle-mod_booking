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
 * Handling signin pdf - extend the TCPDF
 *
 * @package mod_booking
 * @since Moodle 3.0
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace mod_booking\signinsheet;

use pdf;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');
/**
 * Extend the TCPDF class in order to add custom page break
 *
 * @package mod_booking
 * @since Moodle 3.0
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class signin_pdf extends pdf {
    /**
     * $file
     *
     * @var object
     */
    private $file = false;

    /**
     * Go to new line.
     *
     * @param float $h Cell height. Default value: 0.
     * @return bool
     */
    public function go_to_newline($h) {
        return $this->checkPageBreak($h, null, true);
    }

    /**
     * Page footer
     */
    public function footer() {
        // Position at 15 mm from bottom.
        $this->SetY(-20);

        if ($this->file) {
            $filepath = $this->file->get_filepath() . $this->file->get_filename();
            $imageinfo = $this->file->get_imageinfo();
            $signinsheetlogofooter = $this->file->get_content();
            $filetype = str_replace('image/', '', $this->file->get_mimetype());
            $w = 0;
            $h = 15;

            if ($imageinfo['height'] > 15) {
                $h = 15;
            }

            $this->Image(
                '@' . $signinsheetlogofooter,
                null,
                null,
                $w,
                $h,
                $filetype,
                '',
                'T',
                true,
                150,
                'C',
                false,
                false,
                1,
                false,
                false,
                false
            );
        }
    }

    /**
     * Set footer image
     *
     * @param object $file
     *
     * @return void
     *
     */
    public function setfooterimage($file) {
        $this->file = $file;
    }
}

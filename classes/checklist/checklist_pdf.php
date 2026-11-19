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
 * Handling checklist pdf - extend the TCPDF
 *
 * @package mod_booking
 * @since Moodle 3.0
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Christian Badusch
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */


namespace mod_booking\checklist;

use mod_booking\local\file_content;
use pdf;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');

/**
 * Extend the TCPDF class for customizing checklist PDF.
 *
 * @package mod_booking
 * @since Moodle 3.0
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist_pdf extends pdf {
    /**
     * $file
     *
     * @var object
     */
    private $file = false;

    /**
     * Page footer
     */
    public function footer() {
        // Position at 15 mm from bottom.
        $this->SetY(-20);

        // Add footer image if available.
        // Null when the content of the logo is missing in the file storage: the footer stays empty then.
        $footerlogo = $this->file ? file_content::read($this->file) : null;
        if ($footerlogo !== null) {
            $filetype = str_replace('image/', '', $this->file->get_mimetype());
            // The logo is always scaled to a height of 15 mm, the width follows.
            $w = 0;
            $h = 15;

            $this->Image(
                '@' . $footerlogo,
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
     */
    public function setfooterimage($file) {
        $this->file = $file;
    }

    /**
     * Add more elements to the header or customize the page as needed.
     */
    protected function custom_page_header() {
        // Implement any specific header features, if needed.
    }
}

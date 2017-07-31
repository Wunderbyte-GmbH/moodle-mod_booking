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
namespace mod_booking\signinsheet;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for generating the signin sheet as PDF using TCPDF
 *
 * @package    mod_booking
 * @since      Moodle 3.0
 * @copyright  2017 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class generator {

    /**
     * P for portrait or L for landscape
     *
     * @var string
     */
    public $orientation;

    /**
     *
     * @var \mod_booking\booking_option
     */
    public $bookingdata = null;

    /**
     *
     * @var signin_pdf
     */
    public $pdf;

    /**
     * first and lastname of teachers
     *
     * @var array
     */
    public $teachers = array();

    /**
     * times for event
     *
     * @var string
     */
    public $times;

    /**
     * global cfg setting of booking module for custom fields
     *
     * @var array
     */
    public $cfgcustfields = array();

    /**
     * width of the pdf columns
     *
     * @var number
     */
    public $colwidth;

    /**
     * width of logo
     * @var number
     */
    public $w = 0;

    /**
     * height of logo
     * @var number
     */
    public $h = 0;

    /**
     *
     * @var string starttime to endtime
     */
    public $time = '';

    /**
     * signinsheet logo fetched from booking module setting
     * (admin level) as string
     *
     * @var string
     */
    public $signinsheetlogo = '';

    /**
     * Define basic variable values for signinsheet pdf
     *
     * @param \mod_booking\booking_option $bookingdata
     * @param string $orientation
     */
    public function __construct(\mod_booking\booking_option $bookingdata = null,
            $orientation = "downloadsigninportrait") {
        $this->bookingdata = $bookingdata;

        if ($orientation == "downloadsigninportrait") {
            $this->orientation = "P";
            $this->colwidth = 210;
        } else {
            $this->orientation = "L";
            $this->colwidth = 297;
        }
        if (!empty($this->bookingdata->option->teachers)) {
            foreach ($this->bookingdata->option->teachers as $value) {
                $this->teachers[] = "{$value->firstname} {$value->lastname}";
            }
        }
        $this->get_bookingoption_times();
        $cfgcustfields = get_config('booking', 'showcustfields');
        if ($cfgcustfields) {
            $this->cfgcustfields = explode(',', $cfgcustfields);
        }
        $this->pdf = new signin_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8',
                false);
    }

    /**
     * Generate PDF and prepare it for download
     */
    public function download_signinsheet() {
        global $CFG, $DB;

        $extracols = array();
        for ($i = 1; $i < 4; $i++) {
            $colscfg = get_config('booking', 'signinextracols' . $i);
            $colscfg = trim($colscfg);
            if (!empty($colscfg)) {
                $extracols[] = $colscfg;
            }
        }
        $extracolsnum = count($extracols);

        $users = $DB->get_records_sql(
                'SELECT u.id, u.firstname, u.lastname
            FROM {booking_answers} ba
            LEFT JOIN {user} u ON u.id = ba.userid
            WHERE ba.optionid = ? ORDER BY u.lastname ASC', array($this->bookingdata->option->id));

        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->setPrintHeader(true);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $this->pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $this->pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $this->pdf->SetHeaderData('', 0, $this->bookingdata->option->text, '');
        $this->pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $this->pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_LEFT);
        $this->pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $this->pdf->setFontSubsetting(false);
        $this->pdf->AddPage();
        $this->pdf->setJPEGQuality(80);

        // Get logo for signin sheet.
        $fileuse = $this->get_signinsheet_logo();

        $this->set_page_header($extracols);

        $profilefields = explode(',', get_config('booking', 'custprofilefields'));
        $profiles = profile_get_custom_fields();
        $profilefieldnames = array();
        if (!empty($profiles)) {
            $profilefieldnames = array_map(
                    function ($object) {
                        return $object->shortname;
                    }, $profiles);
        }
        foreach ($profilefieldnames as $key => $value) {
            if (!in_array($key, $profilefields)) {
                unset($profilefieldnames[$key]);
            }
        }
        foreach ($users as $user) {
            $profiletext = '';
            profile_load_custom_fields($user);
            $userprofile = $user->profile;
            if (!empty($user->profile)) {
                $profiletext .= " ";
                foreach ($user->profile as $profilename => $value) {
                    if (in_array($profilename, $profilefieldnames)) {
                        $profiletext .= $value . " ";
                    }
                }
            }

            if ($this->pdf->go_to_newline(12)) {
                if ($fileuse) {
                    $this->pdf->SetXY(18, 18);
                    $this->pdf->Image('@' . $this->signinsheetlogo, '', '', $this->w, $this->h, '', '', 'T', true, 150, 'R',
                            false, false, 1, false, false, false);
                }
                $this->set_page_header($extracols);
            }
            $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 10);
            $this->pdf->Cell(
                    ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + $extracolsnum), 12,
                    $user->lastname . ", " . $user->firstname . $profiletext, 1, 0, '', 0);
            $this->pdf->Cell(
                    ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + $extracolsnum), 12,
                    "", 1, (count($extracols) > 0 ? 0 : 1), '', 0);
            if (count($extracols) > 0) {
                for ($i = 1; $i <= $extracolsnum; $i++) {
                    if ($i == $extracolsnum) {
                        $this->pdf->Cell(0, 12, "", 1, 1, '', 0);
                    } else {
                        $this->pdf->Cell(
                                ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + $extracolsnum),
                                12, "", 1, 0, '', 0);
                    }
                }
            }
        }
        $this->pdf->Output($this->bookingdata->option->text . '.pdf', 'D');
    }

    /**
     * Get the times of the booking option
     */
    public function get_bookingoption_times() {
        $this->times = get_string('datenotset', 'booking');
        if ($this->bookingdata->option->coursestarttime == 0) {
            return;
        } else {
            if (empty($this->bookingdata->optiontimes)) {
                $times = userdate($this->bookingdata->option->coursestarttime) . " -" .
                         userdate($this->bookingdata->option->courseendtime);
            } else {
                $val = array();
                $times = explode(',', $this->bookingdata->optiontimes);
                foreach ($times as $time) {
                    $slot = explode('-', $time);
                    $tmpdate = new \stdClass();
                    $tmpdate->leftdate = userdate($slot[0],
                            get_string('strftimedatetime', 'langconfig'));
                    $tmpdate->righttdate = userdate($slot[1],
                            get_string('strftimetime', 'langconfig'));
                    $val[] = get_string('leftandrightdate', 'booking', $tmpdate);
                }
                $times = implode(", ", $val);
            }
        }
        $this->times = $times;
    }

    /**
     * Get logo for signin sheet and include it in PDF
     * return true if logo is used otherwise false
     *
     * @return boolean true if image is used false if not
     */
    public function get_signinsheet_logo() {
        $fileuse = false;
        $fs = get_file_storage();
        $files = $fs->get_area_files(\context_system::instance()->id, 'booking',
                'mod_booking_signinlogo', 0, 'sortorder,filepath,filename', false);
        if ($files) {
            $file = reset($files);
            $filepath = $file->get_filepath() . $file->get_filename();
            $imageinfo = $file->get_imageinfo();
            $this->signinsheetlogo = $file->get_content();
            $filetype = str_replace('image/', '', $file->get_mimetype());
            $this->pdf->SetXY(18, 18);
            if ($imageinfo['height'] == $imageinfo['width']) {
                $this->w = 40;
                $this->h = 40;
            }
            if ($imageinfo['width'] > 200 && $imageinfo['width'] > $imageinfo['height']) {
                $this->w = 40;
                $this->h = 0;
            }
            if ($imageinfo['width'] < $imageinfo['height'] && $imageinfo['height'] > 200) {
                $this->w = 0;
                $this->h = 40;
            }
            $this->pdf->Image('@' . $this->signinsheetlogo, '', '', $this->w, $this->h, $filetype, '', 'T', true, 150, 'R',
                    false, false, 1, false, false, false);
            $fileuse = true;
        }
        return $fileuse;
    }

    /**
     * Set page header for each page
     *
     * @param array $extracols
     */
    public function set_page_header($extracols = array ()) {
        global $DB;

        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
        $this->pdf->Cell(0, 0, '', 0, 1, '', 0);
        $this->pdf->Ln();

        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
        $this->pdf->Cell(0, 0,
                get_string('teachers', 'booking') . ": " . implode(', ', $this->teachers), 0, 1, '',
                0);
        $this->pdf->Ln();

        $this->pdf->MultiCell($this->pdf->GetStringWidth(get_string('pdfdate', 'booking')) + 5, 0,
                get_string('pdfdate', 'booking'), 0, 1, '', 0);
        $this->pdf->MultiCell(0, 0, $this->times, 0, 1, '', 1);

        if (!empty($this->cfgcustfields)) {
            $customfields = \mod_booking\booking_option::get_customfield_settings();
            list($insql, $params) = $DB->get_in_or_equal($this->cfgcustfields);
            $sql = "SELECT bc.cfgname, bc.value
                  FROM {booking_customfields} bc
                 WHERE cfgname $insql
                 AND   optionid = " . $this->bookingdata->option->id;
            $custfieldvalues = $DB->get_records_sql($sql, $params);
            if (!empty($custfieldvalues)) {
                foreach ($custfieldvalues as $record) {
                    if (!empty($record->value)) {
                        $this->pdf->Cell(0, 0,
                                $customfields[$record->cfgname]['value'] . ": " . $record->value, 0, 1, '',
                                0);
                    }
                }
            }
        }

        if (!empty($this->bookingdata->option->address)) {
            $this->pdf->Cell(0, 0,
                    get_string('pdflocation', 'booking') . $this->bookingdata->option->address, 0, 1,
                    '', 0);
        }

        if (!empty($this->bookingdata->option->location)) {
            $this->pdf->Cell(0, 0,
                    get_string('pdfroom', 'booking') . $this->bookingdata->option->location, 0, 1,
                    '', 0);
        }
        $this->pdf->Ln();

        $this->pdf->Cell($this->pdf->GetStringWidth(get_string('pdftodaydate', 'booking')) + 1, 0,
                get_string('pdftodaydate', 'booking'), 0, 0, '', 0);
        $this->pdf->Cell(100, 0, "", "B", 1, '', 0);
        $this->pdf->Ln();

        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, 'B', 12);
        $this->pdf->Cell(
                ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + count($extracols)), 0,
                get_string('pdfstudentname', 'booking'), 1, 0, '', 0);
        $this->pdf->Cell(
                ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + count($extracols)), 0,
                get_string('pdfsignature', 'booking'), 1, (count($extracols) > 0 ? 0 : 1), '', 0);
        if (count($extracols) > 0) {
            for ($i = 0; $i < count($extracols); $i++) {
                if ($i == count($extracols) - 1) {
                    $this->pdf->Cell(
                            ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + count(
                                    $extracols)), 0, $extracols[$i], 1, 1, '', 0);
                } else {
                    $this->pdf->Cell(
                            ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (2 + count(
                                    $extracols)), 0, $extracols[$i], 1, 0, '', 0);
                }
            }
        }
        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
    }
}
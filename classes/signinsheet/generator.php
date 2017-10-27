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
     * show row numbers left of name in first column
     * @var boolean
     */
    public $showrownumbers = false;

    /**
     * signinsheet logo fetched from booking module setting
     * (admin level) as string
     *
     * @var string
     */
    public $signinsheetlogo = '';
    public $signinsheetlogofooter = '';
    public $headerlogofile = false;
    public $allfields = array();
    public $extracols = array();
    protected $orderby = 'lastname';

    /**
     * number of row
     * @var int
     */
    protected $rownumber = null;

    /**
     * Define basic variable values for signinsheet pdf
     *
     * @param \mod_booking\booking_option $bookingdata
     * @param string $orientation
     */
    public function __construct(\mod_booking\booking_option $bookingdata = null,
            $orientation = "downloadsigninportrait", $options = array()) {
        $this->bookingdata = $bookingdata;

        if ($orientation == "downloadsigninportrait") {
            $this->orientation = "P";
            $this->colwidth = 210;
        } else {
            $this->orientation = "L";
            $this->colwidth = 297;
        }
        if ($options['orderby'] == 'firstname') {
            $this->orderby = 'firstname';
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

        $this->allfields = explode(',', $this->bookingdata->booking->signinsheetfields);
        if (get_config('booking', 'numberrows') == 1) {
            $this->showrownumbers = true;
            $this->rownumber = 0;
            array_unshift($this->allfields, 'rownumber');
        }

        foreach ($this->allfields as $key => $value) {
            if ($value == 'signature') {
                unset($this->allfields[$key]);
                $this->allfields[] = 'signature';
            }
        }

        for ($i = 1; $i < 4; $i++) {
            $this->extracols[$i] = trim(get_config('booking', 'signinextracols' . $i));
        }

        $this->pdf = new signin_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8',
                false);
    }

    /**
     * Generate PDF and prepare it for download
     */
    public function download_signinsheet() {
        global $CFG, $DB;
        $groupparams = array();
        $addsqlwhere = '';

        if (groups_get_activity_groupmode($this->bookingdata->cm) == SEPARATEGROUPS
                AND !has_capability('moodle/site:accessallgroups', \context_course::instance($this->bookingdata->course->id))) {
            list ($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($this->bookingdata->course->id);
            $addsqlwhere .= " AND u.id IN ($groupsql)";
        }
        $remove = array('signinextracols1', 'signinextracols2', 'signinextracols3', 'fullname',
            'signature', 'rownumber', 'role');
        $userfields = array_diff($this->allfields, $remove);
        if (!empty($userfields)) {
            $userfields = ', u.' . implode(', u.', $userfields);
        } else {
            $userfields = '';
        }
        $users = $DB->get_records_sql(
                'SELECT u.id, ' . get_all_user_name_fields(true, 'u') . $userfields . '
            FROM {booking_answers} ba
            LEFT JOIN {user} u ON u.id = ba.userid
            WHERE ba.optionid = :optionid ' . $addsqlwhere . "ORDER BY u.{$this->orderby} ASC",
                array_merge($groupparams, array('optionid' => $this->bookingdata->option->id)));

        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->setPrintHeader(true);
        $this->pdf->setPrintFooter(true);
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

        $this->get_signinsheet_logo_footer();

        // Get header and footer logo for signin sheet.
        $fileuse = $this->get_signinsheet_logo();

        $this->set_page_header();

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
                    $this->pdf->Image('@' . $this->signinsheetlogo, '', '', $this->w, $this->h, '',
                            '', 'T', true, 150, 'R', false, false, 0, false, false, false);
                }
                $this->set_page_header();
            }
            $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 10);

            $c = 0;
            if ($this->showrownumbers) {
                $this->rownumber++;
            }
            foreach ($this->allfields as $value) {
                $c++;
                $w = ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (count($this->allfields));
                switch ($value) {
                    case 'rownumber':
                        $name = "{$this->rownumber}";
                        $w = 10;
                        break;
                    case 'fullname':
                        $name = "{$user->firstname} {$user->lastname}{$profiletext}";
                        break;
                    case 'institution':
                        $name = $user->institution;
                        break;
                    case 'description':
                        $name = format_text_email($user->description, FORMAT_HTML);
                        break;
                    case 'city':
                        $name = $user->city;
                        break;
                    case 'country':
                        $name = $user->country;
                        break;
                    case 'idnumber':
                        $name = $user->idnumber;
                        break;
                    case 'email':
                        $name = $user->email;
                        break;
                    case 'phone1':
                        $name = $user->phone1;
                        break;
                    case 'department':
                        $name = $user->department;
                        break;
                    case 'address':
                        $name = $user->address;
                        break;
                    case 'role':
                        $name = strip_tags(
                                get_user_roles_in_course($user->id, $this->bookingdata->course->id));
                        break;
                    default:
                        $name = '';
                }
                $this->pdf->Cell($w, 0, $name, 1, (count($this->allfields) == $c ? 1 : 0), '', 0, "", 1);
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
     * Get logo in header for signin sheet and include it in PDF return true if logo is used otherwise false
     *
     * @return boolean true if image is used false if not
     */
    public function get_signinsheet_logo() {
        $fileuse = false;
        $fs = get_file_storage();
        $context = \context_module::instance($this->bookingdata->cm->id);
        $files = $fs->get_area_files($context->id, 'mod_booking', 'signinlogoheader',
                $this->bookingdata->booking->id, 'sortorder,filepath,filename', false);

        if (!$files) {
            $files = $fs->get_area_files(\context_system::instance()->id, 'booking',
                    'mod_booking_signinlogo', 0, 'sortorder,filepath,filename', false);
        }

        if ($files) {
            $file = reset($files);

            $filepath = $file->get_filepath() . $file->get_filename();
            $imageinfo = $file->get_imageinfo();
            $this->signinsheetlogo = $file->get_content();
            $filetype = str_replace('image/', '', $file->get_mimetype());
            $this->pdf->SetXY(18, 18);
            $this->w = 0;
            $this->h = 20;

            if ($imageinfo['height'] > 20) {
                $this->h = 20;
            }

            $this->pdf->Image('@' . $this->signinsheetlogo, '', '', $this->w, $this->h, $filetype,
                    '', 'T', true, 150, 'R', false, false, 1, false, false, false);
            $fileuse = true;
        }
        return $fileuse;
    }

    /**
     * Get logo in footer for signin sheet and include it in PDF return true if logo is used otherwise false
     *
     * @return boolean true if image is used false if not
     */
    public function get_signinsheet_logo_footer() {
        $fileuse = false;
        $fs = get_file_storage();
        $context = \context_module::instance($this->bookingdata->cm->id);
        $files = $fs->get_area_files($context->id, 'mod_booking', 'signinlogofooter',
                $this->bookingdata->booking->id, 'sortorder,filepath,filename', false);

        if (!$files) {
            $files = $fs->get_area_files(\context_system::instance()->id, 'booking',
                    'mod_booking_signinlogo_footer', 0, 'sortorder,filepath,filename', false);
        }

        if ($files) {
            $file = reset($files);
            $this->pdf->setfooterimage($file);
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

        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 10);
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
                 AND   optionid = " .
                     $this->bookingdata->option->id;
            $custfieldvalues = $DB->get_records_sql($sql, $params);
            if (!empty($custfieldvalues)) {
                foreach ($custfieldvalues as $record) {
                    if (!empty($record->value)) {
                        $this->pdf->Cell(0, 0,
                                $customfields[$record->cfgname]['value'] . ": " . $record->value, 0,
                                1, '', 0, '', 1);
                    }
                }
            }
        }

        if (!empty($this->bookingdata->option->address)) {
            $this->pdf->Cell(0, 0,
                    get_string('pdflocation', 'booking') . $this->bookingdata->option->address, 0, 1,
                    '', 0, '', 1);
        }

        if (!empty($this->bookingdata->option->location)) {
            $this->pdf->Cell(0, 0,
                    get_string('pdfroom', 'booking') . $this->bookingdata->option->location, 0, 1,
                    '', 0, '', 1);
        }
        $this->pdf->Ln();
        $this->pdf->Cell($this->pdf->GetStringWidth(get_string('pdftodaydate', 'booking')) + 1, 0,
                get_string('pdftodaydate', 'booking'), 0, 0, '', 0);
        $this->pdf->Cell(100, 0, "", "B", 1, '', 0, '', 1);
        $this->pdf->Ln();
        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, 'B', 12);
        $c = 0;
        foreach ($this->allfields as $value) {
            $c++;
            $w = ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_LEFT) / (count($this->allfields));
            switch ($value) {
                case 'rownumber':
                    $w = 10;
                    $name = '';
                    break;
                case 'fullname':
                    $name = get_string('fullname', 'mod_booking');
                    break;
                case 'signature':
                    $name = get_string('signature', 'mod_booking');
                    break;
                case 'institution':
                    $name = get_string('institution');
                    break;
                case 'description':
                    $name = get_string('description');
                    break;
                case 'city':
                    $name = get_string('city');
                    break;
                case 'country':
                    $name = get_string('country');
                    break;
                case 'idnumber':
                    $name = get_string('idnumber');
                    break;
                case 'email':
                    $name = get_string('email');
                    break;
                case 'phone1':
                    $name = get_string('phone1');
                    break;
                case 'department':
                    $name = get_string('department');
                    break;
                case 'address':
                    $name = get_string('address');
                    break;
                case 'signinextracols1':
                    $name = $this->extracols[1];
                    break;
                case 'signinextracols2':
                    $name = $this->extracols[2];
                    break;
                case 'signinextracols3':
                    $name = $this->extracols[3];
                    break;
                case 'role':
                    $name = new \lang_string('role');
                    break;
                default:
                    $name = '';
            }
            $this->pdf->Cell($w, 0, $name, 1, (count($this->allfields) == $c ? 1 : 0), '', 0, '', 1);
        }

        $this->pdf->SetFont(PDF_FONT_NAME_MAIN, '', 12);
    }
}
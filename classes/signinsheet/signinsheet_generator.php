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

use mod_booking\booking_option_settings;
use mod_booking\option\fields\sharedplaces;
use mod_booking\singleton_service;
use Exception;
use user_picture;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/local/wunderbyte_table/lib/phpwordinit.php');
/**
 * Class for generating the signin sheet as PDF using TCPDF
 *
 * @package mod_booking
 * @since Moodle 3.0
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class signinsheet_generator {
    /**
     * @var int $optionid
     */
    public $optionid;

    /**
     * P for portrait or L for landscape
     *
     * @var string $orientation
     */
    public $orientation;

    /**
     *
     * @var \mod_booking\booking_option $bookingoption
     */
    public $bookingoption = null;

    /**
     *
     * @var signin_pdf $pdf
     */
    public $pdf;

    /**
     * first and lastname of teachers
     *
     * @var array
     */
    public $teachers = [];

    /**
     * add teachers?
     *
     * @var bool
     */
    public $includeteachers = false;

    /**
     * sessions from booking option settings
     *
     * @var array
     */
    public $sessions;

    /**
     * sessionsstring for event
     *
     * @var string
     */
    public $sessionsstring;

    /**
     * global cfg setting of booking module for custom fields
     *
     * @var array
     */
    public $cfgcustfields = [];

    /**
     * number of empty rows to add at the end of the pdf
     *
     * @var int
     */
    public $addemptyrows = 0;

    /**
     * width of the pdf columns
     *
     * @var float
     */
    public $colwidth;

    /**
     * width of header logo
     *
     * @var float
     */
    public $w = 0;

    /**
     * height of header logo
     *
     * @var float
     */
    public $h = 0;

    /**
     *
     * @var string starttime to endtime
     */
    public $time = '';

    /**
     * show row numbers left of name in first column
     *
     * @var bool
     */
    public $showrownumbers = false;

    /**
     * signinsheet logo fetched from booking module setting
     * (admin level) as string
     * @var \stored_file
     */
    public $signinsheetlogo = '';

    /**
     * Use header logo or not
     * @var bool
     */
    protected $uselogo = false;

    /**
     * Teachers are being processed
     * @var bool
     */
    protected $processteachers = false;

    /**
     * user info fields to display in the sign in sheet table
     * @var array
     */
    public $allfields = [];

    /**
     * extra columns to display
     * @var array
     */
    public $extracols = [];

    /**
     * extra columns to display
     * @var string
     */
    public $saveasformat = '';

    /**
     *
     * @var string $orderby order users
     */
    protected $orderby = 'lastname';

    /**
     * Title to choose: 1 = instancename + optiontext, 2 = optiontext only
     * @var int
     */
    protected $title = 1;


    /**
     * number of row
     *
     * @var int
     */
    protected $rownumber = null;

    /**
     * 0 for all sessions, -1 for none, or id of a specific session
     * @var int
     */
    protected $pdfsessions = 0;

    /**
     * 0 for all sessions, -1 for none, or id of a specific session
     * @var int
     */
    protected $extrasessioncols = -1;

    /**
     * An array containing the names of the extra session columns.
     * @var array
     */
    protected $sessioncolnames = [];

    /**
     * Width of the cell where teacher names are placed
     * @var int
     */
    protected $cellwidthteachers = 200;

    /**
     * Margin top of page
     *
     * @var int
     */
    public $margintop = 12;

    /**
     * True if there are rotated fields within header row.
     * @var bool
     */
    public $hasrotatedfields = false;

    /**
     * Customuserfields.
     * @var array
     */
    public $customuserfields = [];

    /**
     * Define basic variable values for signinsheet pdf
     *
     * @param \stdClass $pdfoptions
     * @param ?\mod_booking\booking_option $bookingoption
     *
     */
    public function __construct(stdClass $pdfoptions, ?\mod_booking\booking_option $bookingoption = null) {

        global $DB;

        $this->optionid = $bookingoption->optionid;
        $this->bookingoption = $bookingoption;
        $this->orientation = $pdfoptions->orientation;
        $this->title = $pdfoptions->title;
        $this->pdfsessions = $pdfoptions->sessions;
        $this->extrasessioncols = $pdfoptions->extrasessioncols;
        $this->addemptyrows = $pdfoptions->addemptyrows;
        $this->includeteachers = $pdfoptions->includeteachers;
        $this->saveasformat = $pdfoptions->saveasformat ?? '';

        if ($this->orientation == "P") {
            $this->colwidth = 210;
            $this->cellwidthteachers = 125;
        } else {
            $this->colwidth = 297;
            $this->cellwidthteachers = 200;
        }
        $this->orderby = $pdfoptions->orderby;
        $teachers = $this->bookingoption->get_teachers();
        if (!empty($teachers)) {
            foreach ($teachers as $value) {
                $this->teachers[] = "{$value->firstname} {$value->lastname}";
            }
        }

        $this->get_bookingoption_sessionsstring();

        $cfgcustfields = get_config('booking', 'showcustfields');
        if ($cfgcustfields) {
            $this->cfgcustfields = explode(',', $cfgcustfields);
        }

        $this->allfields = explode(',', $this->bookingoption->booking->settings->signinsheetfields);
        if (get_config('booking', 'numberrows') == 1) {
            $this->showrownumbers = true;
            $this->rownumber = 0;
            array_unshift($this->allfields, 'rownumber');
        }

        // If the signature column is present, alway move it to the right.
        foreach ($this->allfields as $key => $value) {
            if ($value == 'signature') {
                unset($this->allfields[$key]);
                $this->allfields[] = 'signature';
            }
        }

        for ($i = 1; $i < 4; $i++) {
            $this->extracols[$i] = trim(get_config('booking', 'signinextracols' . $i));
        }

        $this->customuserfields = $DB->get_records('user_info_field', []);

        $this->pdf = new signin_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT);
    }

    /**
     * Returns the user's full name in the format "Lastname, Firstname",
     * handling empty values gracefully.
     *
     * @param stdClass $user The user object with optional firstname and lastname properties.
     * @return string The formatted full name.
     */
    private function get_user_fullname($user): string {
        if (empty($user->lastname) && empty($user->firstname)) {
            return '';
        } else if (empty($user->lastname)) {
            return $user->firstname;
        } else if (empty($user->firstname)) {
            return $user->lastname;
        } else {
            return "{$user->lastname}, {$user->firstname}";
        }
    }


    /**
     * Prepares HTML content for the signinsheet based on users and settings.
     *
     * This function takes the configured HTML template, processes it to include user data,
     * session columns, and other customizations. It handles:
     * - Extracting and applying user templates from the configuration
     * - Adding extra session columns with rotated headers
     * - Generating individual user rows with their data
     * - Adding logos and titles
     * - Preparing the final output in the specified format (PDF/Word)
     *
     * @return void
     */
    public function prepare_html() {
        global $DB;
        $addsqlwhere = '';
        $groupparams = [];
        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        if (
            groups_get_activity_groupmode($this->bookingoption->booking->cm) == SEPARATEGROUPS
            && !has_capability(
                'moodle/site:accessallgroups',
                \context_course::instance($this->bookingoption->booking->course->id)
            )
        ) {
            [$groupsql, $groupparams] = \mod_booking\booking::booking_get_groupmembers_sql(
                $this->bookingoption->booking->course->id
            );
            $addsqlwhere .= " AND u.id IN ($groupsql)";
        }

        $userinfofields = $DB->get_records('user_info_field', []);
        $remove = [
            'signinextracols1',
            'signinextracols2',
            'signinextracols3',
            'fullname',
            'firstname',
            'lastname',
            'signature',
            'rownumber',
            'role',
            'userpic',
            'places',
        ];

        foreach ($userinfofields as $field) {
            $remove[] = $field->shortname;
        }

        $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $mainuserfields = trim($mainuserfields, ', ');

        $userfields = array_diff($this->allfields, $remove);
        if (!empty($userfields)) {
            $userfields = ', u.' . implode(', u.', $userfields);
        } else {
            $userfields = '';
        }

        [$select1, $from1, $filter1, $params1] = booking_option_settings::return_sql_for_custom_profile_field($userinfofields);

        $sql =
        "SELECT u.id, ba.timecreated as bookingtime, ba.places, " . $mainuserfields . $userfields . $select1 .
        " FROM {booking_answers} ba
        LEFT JOIN {user} u ON u.id = ba.userid
        $from1
        WHERE ba.optionid = :optionid AND ba.waitinglist = 0 " .
                 $addsqlwhere . "ORDER BY u.{$this->orderby} ASC";

        $users = $DB->get_records_sql(
            $sql,
            array_merge(
                $groupparams,
                ['optionid' => $this->optionid]
            )
        );

        if ($this->includeteachers) {
            $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
            $mainuserfields = trim($mainuserfields, ', ');

            $teachers = $DB->get_records_sql(
                'SELECT u.id, ' . $mainuserfields . $userfields .
                '
            FROM {booking_teachers} bt
            LEFT JOIN {user} u ON u.id = bt.userid
            WHERE bt.optionid = :optionid ' .
                $addsqlwhere . "ORDER BY u.{$this->orderby} ASC",
                array_merge(
                    $groupparams,
                    ['optionid' => $this->optionid]
                )
            );
            foreach ($teachers as $teacher) {
                $teacher->isteacher = true;
                array_push($users, $teacher);
            }
        }

        // Retrieve the configuration HTML.
        $confightml = get_config('booking', 'signinsheethtml');

        // Extract user template from the configuration HTML.
        preg_match('/\[\[users\]\](.*?)\[\[\/users\]\]/s', $confightml, $matches);
        $usertemplate = isset($matches[1]) ? $matches[1] : '';

        if ($this->pdfsessions == -1) {
                $dates = get_string('signinsheetdatetofillin', 'booking') . ": ________________________";
        }

        $extrasessioncols = $this->get_extra_session_columns();
        if (!empty($extrasessioncols)) {
            $this->allfields = array_unique(array_merge($this->allfields, $extrasessioncols));
        }

        // Session handling logic.
        if ($this->pdfsessions == 0) {
            // Logic to integrate based on existing session data.
            $val = [];
            foreach ($this->sessions as $session) {
                $val[] = userdate($session->coursestarttime) . " - " . userdate($session->courseendtime);
            }
            $dates = implode(", ", $val);
        }

        // Generate session header columns with vertical text.
        $sessionheader = '';
        foreach ($extrasessioncols as $sessiondate) {
            $sessionheader .= '<th class="vertical-text">' . $sessiondate . '</th>';
        }

        if (count($extrasessioncols) > 0) {
            // Target only the header row inside the table with class "signaturetable".
            $confightml = preg_replace(
                '/(<table[^>]*class="signaturetable"[^>]*>.*?<tr[^>]*>.*?)(<\/tr>)/s',
                '$1' . $sessionheader . '$2',
                $confightml
            );
        }

        // Generate user rows with session columns.
        $userrows = '';
        foreach ($users as $user) {
            $row = $usertemplate;
            $replacements = [
                '[[fullname]]' => $this->get_user_fullname($user),
                '[[firstname]]' => $user->firstname ?? '',
                '[[lastname]]' => $user->lastname ?? '',
                '[[email]]' => $user->email ?? '',
                '[[signature]]' => $user->signature ?? '',
                '[[institution]]' => $user->institution ?? '',
                '[[description]]' => format_text_email($user->description ?? '', FORMAT_HTML),
                '[[city]]' => $user->city ?? '',
                '[[country]]' => $user->country ?? '',
                '[[idnumber]]' => $user->idnumber ?? '',
                '[[phone1]]' => $user->phone1 ?? '',
                '[[department]]' => $user->department ?? '',
                '[[address]]' => $user->address ?? '',
                '[[places]]' => $user->places ?? '',
            ];
            $sessioncols = str_repeat('<td></td>', count($extrasessioncols));
            foreach ($replacements as $placeholder => $realvalue) {
                $row = str_replace($placeholder, $realvalue, $row);
            }
            $row = str_replace('</tr>', $sessioncols . '</tr>', $row);
            $userrows .= $row;
        }

        // Replace the [[users]] section with generated user rows.
        $htmloutput = preg_replace('/\[\[users\]\].*?\[\[\/users\]\]/s', $userrows, $confightml);

        // Determine the header title.
        if ($this->title == 2) {
            $headertitle = format_string($settings->get_title_with_prefix());
        } else if ($this->title == 1) {
            $headertitle = format_string($this->bookingoption->booking->settings->name) . ': ' .
                format_string($settings->get_title_with_prefix());
        } else {
            $headertitle = format_string($this->bookingoption->booking->settings->name, null);
        }

        $location = '';
        if (class_exists('local_entities\entitiesrelation_handler') && !empty($settings->entity)) {
            if (!empty($settings->entity['parentname'])) {
                $location = $settings->entity['parentname'] . " (" . $settings->entity['name'] . ")";
            } else {
                $location = $settings->entity['name'] ?? $settings->location ?? '';
            }
        } else if (!empty($settings->location)) {
            $location = $settings->location;
        }

        $dayofweektime = !empty($settings->dayofweektime) ? $settings->dayofweektime : '';
        $teachers = !empty($this->teachers) ? implode(', ', $this->teachers) : '';
        $dates = $this->pdfsessions != -1 && $this->pdfsessions != -2 ? $this->sessionsstring : '';

        $htmloutput = str_replace('[[location]]', $location, $htmloutput);
        $htmloutput = str_replace('[[dayofweektime]]', $dayofweektime, $htmloutput);
        $htmloutput = str_replace('[[teachers]]', $teachers, $htmloutput);
        $htmloutput = str_replace('[[dates]]', $dates, $htmloutput);
        // Add the logo URL to HTML.
        if ($this->get_signinsheet_logo()) {
            $url = \moodle_url::make_pluginfile_url(
                $this->signinsheetlogo->get_contextid(),
                $this->signinsheetlogo->get_component(),
                $this->signinsheetlogo->get_filearea(),
                $this->signinsheetlogo->get_itemid(),
                $this->signinsheetlogo->get_filepath(),
                $this->signinsheetlogo->get_filename()
            );

             $src = $url->out();
            $htmloutput = str_replace('[[logourl]]', $src, $htmloutput);
        }

        // Replace table name placeholder.
        $htmloutput = str_replace('[[tablename]]', $headertitle, $htmloutput);

        // Output the document in the specified format.
        switch ($this->saveasformat) {
            case 'pdf':
                $this->download_pdf_from_html($htmloutput, $settings);
                break;
            case 'word':
                $this->download_word_from_html($htmloutput, $settings);
                break;
            default:
                $this->download_pdf_from_html($htmloutput, $settings);
                break;
        }
    }



    /**
     * Converts HTML content to a Word document and downloads it
     *
     * @param string $htmloutput The HTML content to convert to Word format
     * @param object $settings The booking option settings object containing title information
     *
     * Takes HTML content, converts it to a Word document using PHPWord library,
     * saves it to a temporary file and forces download of the resulting .docx file.
     * The filename is based on the booking option title.
     *
     * @throws Exception If file cannot be read or downloaded
     * @return void
     */
    private function download_word_from_html($htmloutput, $settings) {
        global $DB, $PAGE;
        $worddoc = new \PhpOffice\PhpWord\PhpWord();
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $pageorientation = ($this->orientation === 'L') ? 'landscape' : 'portrait';
        $sectionstyle = [
        'orientation' => $pageorientation,
        ];
        $section = $worddoc->addSection($sectionstyle);

        \PhpOffice\PhpWord\Shared\Html::addHtml($section, $htmloutput, false, false);
        $extrasessioncols = $this->get_extra_session_columns();

        // Write the document to a temporary file.
        $filename = $settings->get_title_with_prefix() . '.docx';
        $temppath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        try {
            // Save the document.
            $worddoc->save($temppath, 'Word2007');
            // Make sure any output buffers are clean.
            if (ob_get_contents()) {
                ob_end_clean();
            }
            // Check file exists and is readable.
            if (file_exists($temppath) && is_readable($temppath)) {
                // Set headers for download.
                header("Content-Description: File Transfer");
                header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
                header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
                header("Cache-Control: must-revalidate");
                header("Expires: 0");
                header("Pragma: public");
                header("Content-Length: " . filesize($temppath));
                // Clear output buffer and stream the file.
                ob_clean();
                flush();
                readfile($temppath);
                // Proper exit.
                exit;
            } else {
                throw new Exception("File could not be read.");
            }
        } catch (Exception $e) {
            // Handle and log exceptions.
            echo "An error occurred while downloading the document: " . $e->getMessage();
        }
    }



    /**
     * Download PDF File from given html
     *
     * @param mixed $htmloutput
     * @param mixed $settings
     *
     * @return void
     *
     */
    private function download_pdf_from_html($htmloutput, $settings) {
        $pdf = new signin_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($htmloutput, true, false, true, false, '');
        $filenamepdf = $settings->get_title_with_prefix() . '.pdf';
        $pdf->Output(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filenamepdf, 'F');
        $downloadfilename = $settings->get_title_with_prefix();
        // Replace special characters to prevent errors.
        $downloadfilename = str_replace(' ', '_', $downloadfilename); // Replaces all spaces with underscores.
        $downloadfilename = preg_replace('/[^A-Za-z0-9\_]/', '', $downloadfilename); // Removes special chars.
        $downloadfilename = preg_replace('/\_+/', '_', $downloadfilename); // Replace multiple underscores with exactly one.
        $downloadfilename = format_string($downloadfilename);
        $pdf->Output($downloadfilename . '.pdf', 'D');
    }




    /**
     * Generate PDF and prepare it for download
     */
    public function download_signinsheet() {
        global $DB, $PAGE;
        $groupparams = [];
        $addsqlwhere = '';

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        if (
            groups_get_activity_groupmode($this->bookingoption->booking->cm) == SEPARATEGROUPS &&
                 !has_capability(
                     'moodle/site:accessallgroups',
                     \context_course::instance($this->bookingoption->booking->course->id)
                 )
        ) {
            [$groupsql, $groupparams] = \mod_booking\booking::booking_get_groupmembers_sql(
                $this->bookingoption->booking->course->id
            );
            $addsqlwhere .= " AND u.id IN ($groupsql)";
        }

        $userinfofields = $DB->get_records('user_info_field', []);
        $remove = [
            'signinextracols1',
            'signinextracols2',
            'signinextracols3',
            'fullname',
            'firstname',
            'lastname',
            'email',
            'signature',
            'rownumber',
            'role',
            'userpic',
            'places',
        ];

        foreach ($userinfofields as $field) {
            $remove[] = $field->shortname;
        }

        $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $mainuserfields = trim($mainuserfields, ', ');

        $userfields = array_diff($this->allfields, $remove);
        if (!empty($userfields)) {
            $userfields = ', u.' . implode(', u.', $userfields);
        } else {
            $userfields = '';
        }

        [$select1, $from1, $filter1, $params1] = booking_option_settings::return_sql_for_custom_profile_field($userinfofields);

        $spinorequal = sharedplaces::return_shared_places_where_sql($settings->id, $groupparams);
        $where = " ba.optionid $spinorequal ";

        $sql =
        "SELECT u.id, ba.timecreated as bookingtime, ba.places, " . $mainuserfields . $userfields . $select1 .
        " FROM {booking_answers} ba
        LEFT JOIN {user} u ON u.id = ba.userid
        $from1
        WHERE $where AND ba.waitinglist = 0 " .
                 $addsqlwhere . "ORDER BY u.{$this->orderby} ASC";

        $users = $DB->get_records_sql(
            $sql,
            array_merge(
                $groupparams,
                ['optionid' => $this->optionid]
            )
        );

        // Create fake users for adding empty rows.
        if ($this->addemptyrows > 0) {
            $fakeuser = new stdClass();
            $fakeuser->id = 0;
            $fakeuser->city = '';
            $fakeuser->firstname = '';
            $fakeuser->lastname = '';
            $fakeuser->institution = '';
            $fakeuser->description = '';
            $fakeuser->city = '';
            $fakeuser->country = '';
            $fakeuser->idnumber = '';
            $fakeuser->email = '';
            $fakeuser->phone1 = '';
            $fakeuser->department = '';
            $fakeuser->address = '';
            for ($i = 1; $i <= $this->addemptyrows; $i++) {
                array_push($users, $fakeuser);
            }
        }

        if ($this->includeteachers) {
            $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
            $mainuserfields = trim($mainuserfields, ', ');

            $teachers = $DB->get_records_sql(
                'SELECT u.id, ' . $mainuserfields . $userfields .
                    '
            FROM {booking_teachers} bt
            LEFT JOIN {user} u ON u.id = bt.userid
            WHERE bt.optionid = :optionid ' .
                    $addsqlwhere . "ORDER BY u.{$this->orderby} ASC",
                array_merge(
                    $groupparams,
                    ['optionid' => $this->optionid]
                )
            );
            foreach ($teachers as $teacher) {
                $teacher->isteacher = true;
                array_push($users, $teacher);
            }
        }

        $this->pdf->SetCreator(PDF_CREATOR);
        // Not needed anymore! (Otherwise we'll have a black line, we do not want).
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(true);
        $this->pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $this->pdf->SetHeaderMargin($this->margintop);
        $this->pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $this->pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $this->pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $this->pdf->setFontSubsetting(false);
        $this->pdf->AddPage();
        $this->pdf->setJPEGQuality(80);
        $this->pdf->setCellPadding(1);

        $this->get_signinsheet_logo_footer();

        $this->set_page_header();

        foreach ($users as $user) {
            if (!isset($user->isteacher)) {
                $user->isteacher = false;
            }
            // The first time a teacher is processed a new page should be made.
            if ($this->processteachers != $user->isteacher) {
                $this->processteachers = true;

                $this->pdf->SetY($this->pdf->GetY() + 5);
                $this->set_table_headerrow();
                if ($this->extrasessioncols == -1) {
                    $this->pdf->SetY($this->pdf->GetY() + 5);
                } else {
                    $this->pdf->SetY($this->pdf->GetY() + 14);
                }
            }
            $this->pdf->SetFont('freesans', '', 10);

            $c = 0;
            if ($this->showrownumbers) {
                $this->rownumber++;
            }
            if (in_array('userpic', $this->allfields)) {
                // If there is an image to be displayed, create higher rows.
                $h = 20;
            } else {
                // Initialize height with 0.
                $h = 0;
            }
            foreach ($this->allfields as $value) {
                $c++;
                $w = ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT) / (count($this->allfields));
                $rotate = false;
                $escape = false;
                switch ($value) {
                    case 'rownumber':
                        $name = "{$this->rownumber}";
                        $w = 5;
                        break;
                    case 'fullname':
                        $w = 40;
                        if (empty($user->lastname) && empty($user->firstname)) {
                            $name = '';
                        } else if (empty($user->lastname) && !empty($user->firstname)) {
                            $name = "{$user->firstname}";
                        } else if (!empty($user->lastname) && empty($user->firstname)) {
                            $name = "{$user->lastname}";
                        } else {
                            $name = "{$user->lastname}, {$user->firstname}";
                        }
                        break;
                    case 'firstname':
                        $w = 20;
                        $name = $user->firstname ?? '';
                        break;
                    case 'lastname':
                        $w = 20;
                        $name = $user->lastname ?? '';
                        break;
                    case 'signature':
                        $w = 40;
                        $name = $user->signature ?? '';
                        break;
                    case 'institution':
                        $name = $user->institution ?? '';
                        break;
                    case 'description':
                        $name = format_text_email($user->description ?? '', FORMAT_HTML);
                        break;
                    case 'city':
                        $name = $user->city ?? '';
                        break;
                    case 'country':
                        $name = $user->country ?? '';
                        break;
                    case 'idnumber':
                        $name = $user->idnumber ?? '';
                        break;
                    case 'email':
                        $w = 60;
                        $name = $user->email ?? '';
                        break;
                    case 'phone1':
                        $name = $user->phone1 ?? '';
                        break;
                    case 'department':
                        $name = $user->department ?? '';
                        break;
                    case 'address':
                        $name = $user->address ?? '';
                        break;
                    case 'places':
                        $w = 15;
                        $name = $user->places ?? '';
                        break;
                    case 'role':
                            // Check if the user is a fake user.
                        if ($user->id > 0) {
                            $roles = get_user_roles(\context_system::instance(), $user->id);
                            $rolenames = array_map(
                                function ($role) {
                                    return $role->name;
                                },
                                $roles
                            );
                            $name = implode(", ", $rolenames);
                        } else {
                            $name = '';
                        }
                        break;
                    case 'userpic':
                        $name = "";
                        $userobj = singleton_service::get_instance_of_user($user->id);
                        if (empty($user->id) || empty($userobj)) {
                            // In case row is empty. No user given.
                            // Make sure column with is respected.
                            $w = 20;
                            break;
                        }
                        $userpic = new user_picture($userobj);
                        if (empty($userpic)) {
                            break;
                        }
                        $userpic->size = 200;
                        $userpictureurl = $userpic->get_url($PAGE);
                        $out = $userpictureurl->out();
                        if (@getimagesize($out)) {
                            $this->pdf->Image(
                                $out,
                                null,
                                null,
                                0,
                                $h,
                                '',
                                '',
                                'T',
                                true,
                                400,
                                '',
                                false,
                                false,
                                1,
                                false,
                                false,
                                false
                            );
                        }

                        $escape = true;
                        break;
                    case 'timecreated':
                        $w = 30;
                        $name = "";
                        if (isset($user->bookingtime) && $user->bookingtime > 0) {
                            $name = userdate($user->bookingtime, get_string('strftimedatetime', 'langconfig'));
                        }
                        break;
                    case 'signinextracols1':
                    case 'signinextracols2':
                    case 'signinextracols3':
                        $name = '';
                        break;
                    default:
                        $w = 5;
                        $rotate = true;
                        $name = '';

                        foreach ($this->customuserfields as $customuserfield) {
                            if ($value == $customuserfield->shortname) {
                                $name = $user->{$value} ?? $user->{strtolower($value)};
                                $name = format_string($name);
                                $w = 25;
                                $rotate = false;
                                break;
                            }
                        }
                }
                if ($escape) {
                    continue;
                }
                if ($rotate) {
                    $this->pdf->Cell(
                        6,
                        $h,
                        $name,
                        1,
                        (count($this->allfields) == $c ? 1 : 0),
                        '',
                        0,
                        "",
                        1
                    );
                } else {
                    if ($c == 1) {
                        $this->pdf->SetY($this->pdf->GetY() - 5, false);
                    }
                    $this->pdf->Cell($w, $h, $name, 1, (count($this->allfields) == $c ? 1 : 0), '', 0, '', 1);
                }
            }
            $this->pdf->SetY($this->pdf->GetY() + 5);
        }

        $downloadfilename = $settings->get_title_with_prefix();
        // Replace special characters to prevent errors.
        $downloadfilename = str_replace(' ', '_', $downloadfilename); // Replaces all spaces with underscores.
        $downloadfilename = preg_replace('/[^A-Za-z0-9\_]/', '', $downloadfilename); // Removes special chars.
        $downloadfilename = preg_replace('/\_+/', '_', $downloadfilename); // Replace multiple underscores with exactly one.
        $downloadfilename = format_string($downloadfilename);

        $this->pdf->Output($downloadfilename . '.pdf', 'D');
    }

    /**
     * Get the sessionsstring of the booking option
     */
    private function get_bookingoption_sessionsstring() {

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $this->sessions = $settings->sessions ?? [];

        // If there are no sessions...
        if (empty($settings->sessions)) {
            // ... then we need to look if the option itself has start and end time.
            if ($settings->coursestarttime == 0 || $settings->courseendtime == 0) {
                $this->sessionsstring = get_string('datenotset', 'booking');
                return;
            } else {
                $this->sessionsstring = userdate($settings->coursestarttime) . " - " .
                         userdate($settings->courseendtime);
                return;
            }
        } else {
            // Value of -1 ... Add date manually.
            // Value of -2 ... Hide date.
            if ($this->pdfsessions == -1 || $this->pdfsessions == -2) {
                // Do not show sessions.
                $this->sessionsstring = '';
                return;
            } else if ($this->pdfsessions == 0) {
                // Show all sessions.
                $val = [];
                foreach ($settings->sessions as $time) {
                    $tmpdate = new stdClass();
                    $tmpdate->leftdate = userdate(
                        $time->coursestarttime,
                        get_string('strftimedatetime', 'langconfig')
                    );
                    $tmpdate->righttdate = userdate(
                        $time->courseendtime,
                        get_string('strftimetime', 'langconfig')
                    );
                    $val[] = get_string('leftandrightdate', 'booking', $tmpdate);
                }
                $this->sessionsstring = implode("\n", $val);
                return;
            } else {
                // Show a specific selected session.
                $this->sessionsstring = userdate(
                    $settings->sessions[$this->pdfsessions]->coursestarttime,
                    get_string('strftimedatetime', 'langconfig')
                );
                $this->sessionsstring .= ' - ' . userdate(
                    $settings->sessions[$this->pdfsessions]->courseendtime,
                    get_string('strftimetime', 'langconfig')
                );
                return;
            }
        }
    }

    /**
     * Add extra columns for sessions.
     * @return array an array of strings, containing the dates (names) of the extra columns
     */
    private function get_extra_session_columns() {

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        $sessioncolnames = [];

        // If there are no sessions...
        if (empty($settings->sessions)) {
            return [];
        } else {
            if ($this->extrasessioncols == -1) {
                return [];
            } else if ($this->extrasessioncols == 0) {
                // Add columns for all sessions.
                $val = [];
                foreach ($settings->sessions as $session) {
                    $sessioncolnames[] = userdate(
                        $session->coursestarttime,
                        get_string('strftimedateshortmonthabbr', 'langconfig')
                    );
                }
            } else {
                // Add a column for a specific session.
                $sessioncolnames[] = userdate(
                    $settings->sessions[$this->extrasessioncols]->coursestarttime,
                    get_string('strftimedateshortmonthabbr', 'langconfig')
                );
            }
        }

        return $sessioncolnames;
    }

    /**
     * Get logo in header for signin sheet and include it in PDF return true if logo is used
     * otherwise false
     *
     * @return bool true if image is used false if not
     */
    public function get_signinsheet_logo() {
        $fs = get_file_storage();
        $context = \context_module::instance($this->bookingoption->booking->cm->id);
        $files = $fs->get_area_files(
            $context->id,
            'mod_booking',
            'signinlogoheader',
            $this->bookingoption->booking->settings->id,
            'sortorder,filepath,filename',
            false
        );

        if (!$files) {
            $files = $fs->get_area_files(
                \context_system::instance()->id,
                'mod_booking',
                'mod_booking_signinlogo',
                0,
                'sortorder,filepath,filename',
                false
            );
        }

        if ($files) {
            $file = reset($files);
            $filepath = $file->get_filepath() . $file->get_filename();
            $imageinfo = $file->get_imageinfo();
            $this->signinsheetlogo = $file;
            $filetype = str_replace('image/', '', $file->get_mimetype());
            $this->w = 0;
            $this->h = 20;

            if ($imageinfo['height'] > 20) {
                $this->h = 20;
            }
            $this->uselogo = true;
        }
        return $this->uselogo;
    }

    /**
     * Get logo in footer for signin sheet and include it in PDF return true if logo is used
     * otherwise false
     *
     * @return bool true if image is used false if not
     */
    public function get_signinsheet_logo_footer() {
        $fileuse = false;
        $fs = get_file_storage();
        $context = \context_module::instance($this->bookingoption->booking->cm->id);
        $files = $fs->get_area_files(
            $context->id,
            'mod_booking',
            'signinlogofooter',
            $this->bookingoption->booking->settings->id,
            'sortorder,filepath,filename',
            false
        );

        if (!$files) {
            $files = $fs->get_area_files(
                \context_system::instance()->id,
                'booking',
                'mod_booking_signinlogo_footer',
                0,
                'sortorder,filepath,filename',
                false
            );
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
    public function set_page_header($extracols = []) {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        // Get header and footer logo for signin sheet.
        $this->pdf->SetXY(18, $this->pdf->getY() - 15);

        if ($this->get_signinsheet_logo()) {
            $this->pdf->Image(
                '@' . $this->signinsheetlogo->get_content(),
                null,
                null,
                $this->w,
                $this->h,
                '',
                '',
                'T',
                true,
                150,
                'R',
                false,
                false,
                0,
                false,
                false,
                false
            );
        }
        // Empty multicell for spacing.
        $this->pdf->MultiCell(55, 5, '', 0, '', 0, 1, null, null, true);

        $this->pdf->SetFont('freesans', '', 10);

        if ($this->title == 2) {
            $headertitle = format_string($settings->get_title_with_prefix());
        } else if ($this->title == 1) {
            $headertitle = format_string($this->bookingoption->booking->settings->name) . ': ' .
                format_string($settings->get_title_with_prefix());
        } else {
            $headertitle = format_string($this->bookingoption->booking->settings->name, null);
        }

        $this->pdf->writeHTMLCell(
            0,
            0,
            $this->pdf->GetX(),
            15,
            '<h3>' . $headertitle . '</h3>',
            0,
            1,
            false,
            true,
            'L',
            false
        );

        $this->pdf->SetY($this->pdf->GetY() + 2);

        if (class_exists('local_entities\entitiesrelation_handler')) {
            // If Entity manager is installed, we use location and address from entity.
            if (!empty($settings->entity)) {
                if (!empty($settings->entity['parentname'])) {
                    $nametobeshown = $settings->entity['parentname'] . " (" . $settings->entity['name'] . ")";
                } else {
                    $nametobeshown = $settings->entity['name'] ?? $settings->location ?? '';
                }
                $this->pdf->Cell(
                    0,
                    0,
                    get_string('signinsheetlocation', 'booking') . format_string($nametobeshown),
                    0,
                    1,
                    '',
                    0,
                    '',
                    1
                );
            }
        } else if (!empty($settings->location)) {
            $this->pdf->Cell(
                0,
                0,
                get_string('signinsheetlocation', 'booking') . format_string($settings->location),
                0,
                1,
                '',
                0,
                '',
                1
            );
        }

        if (!empty(trim($settings->address ?? ''))) {
            $this->pdf->Cell(
                0,
                0,
                get_string('signinsheetaddress', 'booking') . format_string($settings->address),
                0,
                1,
                '',
                0,
                '',
                1
            );
        }

        if (!empty($settings->dayofweektime)) {
            $this->pdf->Cell(
                0,
                0,
                get_string('dayofweektime', 'mod_booking') . ': ' . format_string($settings->dayofweektime),
                0,
                1,
                '',
                0,
                '',
                1
            );
        }

        $this->pdf->MultiCell(
            $this->cellwidthteachers,
            0,
            get_string('teachers', 'mod_booking') . ": " . implode(', ', $this->teachers),
            0,
            1,
            false,
            0
        );
        $this->pdf->Ln();

        // Do not show dates, if the option "Add date manually" (-1) or the option...
        // ... "Hide date" (-2) was selected in the form.
        if ($this->pdfsessions != -1 && $this->pdfsessions != -2) {
            $this->pdf->MultiCell(
                $this->pdf->GetStringWidth(get_string('signinsheetdate', 'booking')) + 5,
                0,
                get_string('signinsheetdate', 'booking'),
                0,
                1,
                false,
                0
            );
            $this->pdf->SetFont('freesans', '', 8);
            $this->pdf->MultiCell(0, 0, $this->sessionsstring, 0, 'L', false, 1);
        }

        $this->pdf->SetFont('freesans', '', 10);

        if (!empty($this->cfgcustfields)) {
            $customfields = \mod_booking\booking_option::get_customfield_settings();
            [$insql, $params] = $DB->get_in_or_equal($this->cfgcustfields);
            $sql = "SELECT bc.cfgname, bc.value
                  FROM {booking_customfields} bc
                 WHERE cfgname $insql
                 AND   optionid = " . $this->optionid;
            $custfieldvalues = $DB->get_records_sql($sql, $params);
            if (!empty($custfieldvalues)) {
                foreach ($custfieldvalues as $record) {
                    if (!empty($record->value)) {
                        $this->pdf->Cell(
                            0,
                            0,
                            $customfields[$record->cfgname]['value'] . ": " .
                                ($customfields[$record->cfgname]['type'] == 'multiselect' ? implode(
                                    ", ",
                                    explode("\n", $record->value)
                                ) : $record->value),
                            0,
                            1,
                            '',
                            0,
                            '',
                            1
                        );
                    }
                }
            }
        }

        // Do we need this line?
        $this->pdf->Ln();

        if ($this->pdfsessions == -1) {
            $this->pdf->Cell(
                $this->pdf->GetStringWidth(get_string('signinsheetdatetofillin', 'booking')) + 1,
                0,
                get_string('signinsheetdatetofillin', 'booking'),
                0,
                0,
                '',
                0
            );
            $this->pdf->Cell(100, 0, "", "B", 1, '', 0, '', 1);
            $this->pdf->Ln();
        }

        // If set, add extra columns for sessions.
        // It is important, that this happens AFTER any DB-queries using the fields in $this->allfields.
        $extrasessioncols = $this->get_extra_session_columns();
        if (!empty($extrasessioncols)) {
            $this->allfields = array_unique(array_merge($this->allfields, $extrasessioncols));
        }

        // Sessions seem to destroy Y because of newlines, so add the following little fix.
        if ($this->pdfsessions == 0) {
            // Only do this, if we show ALL sessions.
            $this->pdf->setY($this->pdf->GetY() - (count($this->sessions) * 2.9), false);
        } else {
            $this->pdf->setY($this->pdf->GetY() + 5, false);
        }
        $this->set_table_headerrow();
        if ($this->extrasessioncols == -1) {
            $this->pdf->SetY($this->pdf->GetY() + 5);
        } else {
            $this->pdf->SetY($this->pdf->GetY() + 14);
        }
    }

    /**
     * Setup the header row with the column headings for each column
     */
    private function set_table_headerrow() {
        global $DB;

        $this->pdf->SetFont('freesans', 'B', 10);
        $c = 0;

        // Setup table header row.
        foreach ($this->allfields as $value) {
            $rotate = false;
            $c++;
            $w = ($this->colwidth - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT) / (count($this->allfields));
            switch ($value) {
                case 'rownumber':
                    $w = 5;
                    $name = '';
                    break;
                case 'fullname':
                    $w = 40;
                    if ($this->processteachers) {
                        $name = get_string('teachers', 'mod_booking') . " ";
                    } else {
                        $name = get_string('fullname', 'mod_booking');
                    }
                    break;
                case 'firstname':
                    $w = 20;
                    $name = get_string('firstname');
                    break;
                case 'lastname':
                    $w = 20;
                    $name = get_string('lastname');
                    break;
                case 'signature':
                    $w = 40;
                    $name = get_string('signature', 'mod_booking');
                    break;
                case 'institution':
                    $name = get_string('institution', 'booking');
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
                    $w = 60;
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
                case 'userpic':
                    $w = 20;
                    $name = get_string('userpic');
                    break;
                case 'places':
                    $w = 15;
                    $name = get_string('places', 'mod_booking');
                    break;
                case 'timecreated':
                    $w = 30;
                    $name = get_string('bookingdate', 'mod_booking');
                    break;
                default:
                    $userfield = false;
                    foreach ($this->customuserfields as $customuserfield) {
                        if ($value == $customuserfield->shortname) {
                            $name = format_string($customuserfield->name);
                            $w = 25;
                            $userfield = true;
                            break;
                        }
                    }
                    if (!$userfield) {
                        $rotate = true;
                        $this->hasrotatedfields = true;
                        $name = $value;
                    }
            }

            if ($rotate) {
                $this->pdf->SetFont('freesans', '', 8);
                $this->pdf->SetY($this->pdf->GetY() + 15, false);
                $this->pdf->StartTransform();
                $this->pdf->Rotate(90);
                $this->pdf->Cell(
                    15,
                    6,
                    $name,
                    1,
                    (count($this->allfields) == $c ? 1 : 0),
                    '',
                    0,
                    "",
                    1
                );
                $this->pdf->StopTransform();
                $this->pdf->SetY($this->pdf->GetY() - 15, false);
                $this->pdf->SetX($this->pdf->GetX() - 9);
                $this->pdf->SetFont('freesans', 'B', 10);
            } else {
                if ($c == 1) {
                    $this->pdf->SetY($this->pdf->GetY() - 5, false);
                }
                $this->pdf->Cell($w, 15, $name, 1, (count($this->allfields) == $c ? 1 : 0), '', 0, '', 1);
            }
        }
    }
}

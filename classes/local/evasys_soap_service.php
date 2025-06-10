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
 * Evasys SOAP Service Class.
 *
 * @package mod_booking
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;
use SoapClient;
use SoapFault;
use SoapHeader;


/**
 * Serviceclass to handle SOAP calls.
 */
class evasys_soap_service extends SoapClient {
    /**
     * URL of the Endpoint.
     *
     * @var string
     */
    private string $endpoint;
    /**
     * Username for Connection
     *
     * @var string
     */
    private string $username;
    /**
     * Password for Connection
     *
     * @var string
     */
    private string $password;

    /**
     * Wsdl Adress.
     *
     * @var string
     */
    private string $wsdl;

    /**
     * Constructor with parent constructor in it. Soapheader is used for authentication.
     *
     * @param string|null $endpoint
     * @param string|null $username
     * @param string|null $password
     * @param string|null $wsdl
     *
     */
    public function __construct(?string $endpoint = null, ?string $username = null, ?string $password = null, ?string $wsdl = null) {
        $this->endpoint = $endpoint ?? get_config('booking', 'evasysbaseurl');
        $this->username = $username ?? get_config('booking', 'evasysuser');
        $this->password = $password ?? get_config('booking', 'evasyspassword');
        $this->wsdl = $wsdl ?? get_config('booking', 'evasyswsdl');

        $options = [
            'trace'      => true,
            'exceptions' => true,
            'location'   => $this->endpoint,
        ];
        try {
            parent::__construct($this->wsdl, $options);
            $this->set_soap_header();
        } catch (SoapFault $e) {
            // exception
        }
    }

    /**
     * Fetches subunits from Evasys.
     *
     * @return mixed
     *
     */
    public function fetch_subunits() {
        try {
            $response = $this->__soapCall('GetSubunits', []);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }
    /**
     * Fetches periods from Evasys.
     *
     * @return mixed
     *
     */
    public function fetch_periods() {
        try {
            $response = $this->__soapCall('GetAllPeriods', []);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Get a Period by ID from Evasys.
     *
     * @param array $perioddata
     *
     * @return mixed
     *
     */
    public function get_period($perioddata) {
        try {
            $response = $this->__soapCall('GetPeriod', $perioddata);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Fetches Forms from Evasys.
     *
     * @param array $args
     *
     * @return mixed
     *
     */
    public function fetch_forms($args) {
        try {
            $response = $this->__soapCall('GetAllForms', $args);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Gets a Form by ID from Evasys.
     *
     * @param array $args
     *
     * @return mixed
     *
     */
    public function get_form($args) {
        try {
            $response = $this->__soapCall('GetForm', $args);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

   /**
    * Inserts User to Evays.
    *
    * @param array $user
    *
    * @return mixed
    *
    */
    public function insert_user($userdata) {
        try {
            $response = $this->__soapCall('InsertUser', ['user' => $userdata ]);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Insert Course to Evasys.
     *
     * @param array $coursedata
     *
     * @return mixed
     *
     */
    public function insert_course($coursedata) {
        try {
            $response = $this->__soapCall('InsertCourse', ['course' => $coursedata ]);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Updates the Course to Evasys.
     *
     * @param object $coursedata
     *
     * @return mixed
     *
     */
    public function update_course($coursedata) {
        try {
            $response = $this->__soapCall('UpdateCourse', ['course' => $coursedata ]);
            return $response;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Deletes Course in Evasys.
     *
     * @param array $course
     *
     * @return boolean
     *
     */
    public function delete_course($course) {
        try {
            $response = $this->__soapCall('DeleteCourse', $course);
            return $response;
        } catch (SoapFault $e) {
            return false;
        }
    }

    /**
     * Insert Survey to Evasys.
     *
     * @param array $surveydata
     *
     * @return mixed
     *
     */
    public function insert_survey($surveydata) {
        try {
            $response = $this->__soapCall('InsertCentralSurvey', $surveydata);
            return $response;
        } catch (SoapFault $e) {
            $this->__getLastRequest();
            return null;
        }
    }

    /**
     * Delete the survey in evasys.
     *
     * @param array $survey
     *
     * @return boolean
     *
     */
    public function delete_survey($survey) {
        try {
            $response = $this->__soapCall('DeleteSurvey', $survey);
            return $response;
        } catch (SoapFault $e) {
            return false;
        }
    }

    /**
     * Get QR code from evasys.
     *
     * @param array $survey
     *
     * @return string
     *
     */
    public function get_qr_code($survey) {
        try {
            $response = $this->__soapCall('GetOnlineQRCode', $survey);
            return $response;
        } catch (SoapFault $e) {
            return "";
        }
    }

    /**
     * Sets Soapheader for authentication.
     *
     * @return void
     *
     */
    private function set_soap_header() {
        $ns = 'soapserver-v91.wsdl';
        $headerbody = [
            'Ticket'   => '',
            'Login'    => $this->username,
            'Password' => $this->password,
        ];
        $header = new SoapHeader($ns, 'Header', $headerbody);
        $this->__setSoapHeaders($header);
    }
}

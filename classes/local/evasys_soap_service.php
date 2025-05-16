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
     * Constructor with parent constructor in it. Soapheader is used for autehntication.
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
        parent::__construct($this->wsdl, $options);
        $this->set_soap_header();
    }

    /**
     * Fetches subunits from API.
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
     * Fetches periods from API.
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

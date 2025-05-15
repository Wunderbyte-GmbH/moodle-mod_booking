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
use stdClass;


/**
 * Helperclass to Save and Load Form.
 */
class evasys_soap_service {
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
     * Constructor.
     *
     *
     */
    public function __construct(?string $endpoint = null, ?string $username = null, ?string $password = null) {
        $this->endpoint = $endpoint ?? get_config('mod_booking', 'evasysbaseurl');
        $this->username = $username ?? get_config('mod_booking', 'evasysuser');
        $this->password = $password ?? get_config('mod_booking', 'evasyspassword');
    }

    /**
     * Fetches subunits from API.
     *
     * @return object
     *
     */
    public function fetch_subunits() {
         // Just static for the Workfow.
        $units = new stdClass();
        $units->UnitList = [
            (object)[
            'm_nId' => '11',
            'm_sName' => 'SPL002 - Evang Theol',
            'm_nPublicUnitNumber' => '2',
            'm_nImageAccess' => '0',
            'm_sPostCode' => '',
            'm_sCity' => '',
            'm_sStreet' => '',
            'm_sPhoneNumber' => '',
            'm_sFax' => '',
            'm_sEmail' => null,
            'm_aUsers' => '',
            'm_nLogoId' => '1',
            'm_sExternalId' => '',
            'm_bIsHidden' => 'false',
            ],
            (object)[
            'm_nId' => '10',
            'm_sName' => 'SPL009 - Altertumsw',
            'm_nPublicUnitNumber' => '9',
            'm_nImageAccess' => '0',
            'm_sPostCode' => '',
            'm_sCity' => '',
            'm_sStreet' => '',
            'm_sPhoneNumber' => '',
            'm_sFax' => '',
            'm_sEmail' => null,
            'm_aUsers' => '',
            'm_nLogoId' => '1',
            'm_sExternalId' => '',
            'm_bIsHidden' => 'false',
            ],
            (object)[
            'm_nId' => '18',
            'm_sName' => 'SPL032 - Pharmazie',
            'm_nPublicUnitNumber' => '32',
            'm_nImageAccess' => '0',
            'm_sPostCode' => '',
            'm_sCity' => '',
            'm_sStreet' => '',
            'm_sPhoneNumber' => '',
            'm_sFax' => '',
            'm_sEmail' => null,
            'm_aUsers' => '',
            'm_nLogoId' => '1',
            'm_sExternalId' => '',
            'm_bIsHidden' => 'false',
            ],
        ];
        return $units;
    }
    /**
     * Fetches periods from API.
     *
     * @return object
     *
     */
    public function fetch_periods() {
        // Just static for the Workfow.
        $periods = new stdClass();
        $periods->PeriodList = [
            (object)[
            'm_nPeriodId' => '24',
            'm_sTitel' => 'W21',
            'm_sStartDate' => '2021-10-01',
            'm_sEndDate' => '2022-02-28',
            ],
            (object)[
            'm_nPeriodId' => '25',
            'm_sTitel' => 'S22',
            'm_sStartDate' => '2022-03-01',
            'm_sEndDate' => '2022-10-31',
            ],
            (object)[
            'm_nPeriodId' => '26',
            'm_sTitel' => 'W22',
            'm_sStartDate' => '2022-10-01',
            'm_sEndDate' => '2023-02-28',
            ],
            (object)[
            'm_nPeriodId' => '27',
            'm_sTitel' => 'S23',
            'm_sStartDate' => '2023-03-01',
            'm_sEndDate' => '2023-09-30',
            ],
            (object)[
            'm_nPeriodId' => '28',
            'm_sTitel' => 'W23',
            'm_sStartDate' => '2023-10-01',
            'm_sEndDate' => '2024-02-29',
            ],
        ];
        return $periods;
    }
}

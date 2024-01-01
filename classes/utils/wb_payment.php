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
 * Wunderbyte Payment Methods.
 *
 * Contains methods for license verification and more.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\utils;

use stdClass;

/**
 * Class to handle Wunderbyte Payment Methods.
 *
 * Contains methods for license verification and more.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wb_payment {

    /**
     * MOD_BOOKING_PUBLIC_KEY
     *
     * @var mixed
     */
    const MOD_BOOKING_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu8vRBnPDug2pKoGY9wQS
KNTK1SzrPuU0KC8xm22GPQZQM1XkPpvNwBp8CmXUN29r/qiPxapDNVmIH5Ectvb+
NA7EsuVSS8xV6HfjV0tNZKIfFA4b1JD7t6l4gGDLuoppvKQV9n1JP/uZhQlFZ8Dg
7qMXGsEWRcmRGSBZxIVA+EiN35ALsR78MYWEmuAtKKtskqD4cwnAQzZhU1tZRFHz
/uSfhS2tFXQ7vjvCPIozzo9Mgy4Vr4Qoc9ohg0AfK/D3IoA/mpQFpVC+hyS+rQ0d
uqjiVvh1b0cI3ZBEwWeaNKR4Z3dVb3RHOnICCJPyxxIfSDKWDmQDMCMLa5UjvSvM
pwIDAQAB
-----END PUBLIC KEY-----";

    /**
     * Decrypt a PRO license key to get the expiration date of the license
     *
     * @param string $encryptedlicensekey an object containing licensekey and signature
     * @return string the expiration date of the license key formatted as Y-m-d
     */
    public static function decryptlicensekey(string $encryptedlicensekey): string {
        global $CFG;
        // Step 1: Do base64 decoding.
        $encryptedlicensekey = base64_decode($encryptedlicensekey);

        // Step 2: Decrypt using public key.
        openssl_public_decrypt($encryptedlicensekey, $licensekey, self::MOD_BOOKING_PUBLIC_KEY);

        // Step 3: Do another base64 decode and decrypt using wwwroot.
        $c = base64_decode($licensekey);
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = substr($c, 0, $ivlen);

        // Bugfix when passing wrong license keys that are too short.
        if (strlen($iv) != 16) {
            return false;
        }

        $sha2len = 32;
        $ciphertextraw = substr($c, $ivlen + $sha2len);
        $decryptedcontent = openssl_decrypt($ciphertextraw, $cipher, $CFG->wwwroot, $options = OPENSSL_RAW_DATA, $iv);

        return $decryptedcontent;
    }

    /**
     * Helper function to determine if the user has set a valid license key which has not yet expired.
     *
     * @return bool true if the license key is valid at current date
     * @throws \dml_exception
     */
    public static function pro_version_is_activated() {
        // Get license key which has been set in settings.php.
        $pluginconfig = get_config('booking');
        if (!empty($pluginconfig->licensekey)) {
            $licensekeyfromsettings = $pluginconfig->licensekey;
            // DEBUG: echo "License key from plugin config: $licensekey_from_settings<br>"; END.

            $expirationtimestamp = strtotime(self::decryptlicensekey($licensekeyfromsettings));
            // Return true if the current timestamp has not yet reached the expiration date.
            if (time() < $expirationtimestamp) {
                return true;
            }
        }
        // Overriding - always use PRO for testing / debugging.
        // Check if Behat OR PhpUnit tests are running.
        if ((defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            return true;
        }
        return false;
    }
}

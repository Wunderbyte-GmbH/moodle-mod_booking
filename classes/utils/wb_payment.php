<?php
// Wunderbyte Payment Methods Class:
// Contains methods for license verification and more

namespace mod_booking\utils;

use \stdClass;

defined('MOODLE_INTERNAL') || die();

class wb_payment
{
    const PUBLIC_KEY =
"-----BEGIN PUBLIC KEY-----
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
     * @param stdClass $signedkey an object containing licensekey and signature
     * @return string the expiration date of the license key formatted as Y-m-d
     */
    public static function decryptlicensekey(string $encrypted_licensekey): string
    {
        global $CFG;
        // Step 1: Do base64 decoding
        $encrypted_licensekey = base64_decode($encrypted_licensekey);

        // Step 2: Decrypt using public key
        openssl_public_decrypt($encrypted_licensekey, $licensekey, self::PUBLIC_KEY);

        // Step 3: Do another base64 decode and decrypt using wwwroot
        $c = base64_decode($licensekey);
        $ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
        $iv = substr($c, 0, $ivlen);

        // Bugfix when passing wrong license keys that are too short
        if (strlen($iv) != 16) return false;

        $sha2len=32;
        $ciphertext_raw = substr($c, $ivlen+$sha2len);
        $decrypted_content = openssl_decrypt($ciphertext_raw, $cipher, $CFG->wwwroot, $options=OPENSSL_RAW_DATA, $iv);

        return $decrypted_content;
    }

    /**
     * Helper function to determine if the user has set a valid license key which has not yet expired.
     *
     * @return bool true if the license key is valid at current date
     */
    public static function is_currently_valid_licensekey(){
        // get license key which has been set in settings.php
        $pluginconfig = get_config('booking');
        if (!empty($pluginconfig->licensekey)){
            $licensekey_from_settings = $pluginconfig->licensekey;
            // echo "License key from plugin config: $licensekey_from_settings<br>";

            $expiration_timestamp = strtotime(self::decryptlicensekey($licensekey_from_settings));
            // return true if the current timestamp has not yet reached the expiration date
            if (time() < $expiration_timestamp){
                return true;
            }
        }
        return false;
    }
}
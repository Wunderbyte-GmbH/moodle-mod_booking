<?php

global $CFG, $PAGE, $OUTPUT;

require_once("../../config.php");

use \mod_booking\utils\wb_payment;

// TODO: move encryption part and private key to Wunderbyte server
const PRIVATE_KEY =
"-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7y9EGc8O6Dakq
gZj3BBIo1MrVLOs+5TQoLzGbbYY9BlAzVeQ+m83AGnwKZdQ3b2v+qI/FqkM1WYgf
kRy29v40DsSy5VJLzFXod+NXS01koh8UDhvUkPu3qXiAYMu6imm8pBX2fUk/+5mF
CUVnwODuoxcawRZFyZEZIFnEhUD4SI3fkAuxHvwxhYSa4C0oq2ySoPhzCcBDNmFT
W1lEUfP+5J+FLa0VdDu+O8I8ijPOj0yDLhWvhChz2iGDQB8r8PcigD+alAWlUL6H
JL6tDR26qOJW+HVvRwjdkETBZ5o0pHhnd1VvdEc6cgIIk/LHEh9IMpYOZAMwIwtr
lSO9K8ynAgMBAAECggEAQ1hN9zBgikIH3jRndH3DPV1B97yKCB4N3DNAaOqX7x5q
eF5e4Yzh9fgJb3kg51hPtB0iodHzFBitVhSl5X8hne2F4FmYb5IxZJJJtq5MbMAu
wPRmSo5LlotxqSgNJzInxPxa2/AF6qoBXdH7T7+9ahaWXIPNxu0H2ajeSDk5GU/d
X+sYb7zEAFGPkYtNgLLFO21WW9Y5MQlBlho4HTapJEkFlbQX7KXYMHTAFLxAJp6U
ZZdulFl2inWXaZfhGtoYTIr9si8WIydYK9G2+qoOSW176rV5aJey4DPTquLlMM+F
YiRWWFlCNv3WFn2Zh7RLaR+rtd6ep0Vi6VVwilqVkQKBgQDzi87Uo/3rIsYvWl+7
QtKR2GWhDr/yrkmzWlHha8qPq86U01/P/4oxCZAxJ5HBF4I+yRIM0oRgJXS4iStS
0tjFv/QI5BGsvSzeFz7/2cUA9oJ0ApubD5eMHVGP/ifj5jCASa94esk0mTazOAIe
4TWWWE+9OonAwI7BL5MD5RCpkwKBgQDFZjOr67gV8o/XlPREMS2OhSJ2/VMBzzIP
KhP3YjQ7tNBDvhg4nqRMNgsW+v+r06tFD1uJgiHCCGuIfMeKJ/SVQ5OePvxt1wXB
P+I6sV9TQc+JRqUBQovfvqsy3thGOVA9M5GzCH9MwB9uv5+/lGhoHOIIJoCxsepR
hlB83rdtHQKBgQCgOtIHyiCrS0SSMOYcwHji5TjvvlGAqzPn4LtQEGfDICiYd3xo
ztmvK3iHLl5RaFMTVZwffX0D+ICTTAOJyRg++evm0Y3jVM6pCygykaZv3L607mZL
nPV6hGt9zZuW74HnVRMxs66egVKglG+ou0hTMqS7fUDV5JnG9bLGdDUDKwKBgGGB
XjyprsCIlCy00wNsF0iy0pdcAkh+hAehjUNBKvPjGIydtXEiS52phEjRqsDBSXRP
ZbPCp9IkPpmoqRfBLLseKiicjCvlbl5KpADB5IhHlbAFSTQaHuViVUZHdSUa4luY
wXth0x+iNuSJmusS74+d1LiZ7C/Z5hhm9BL6IDixAoGANgg67daeJQW3NfckCPH8
eVu359zEfesMEQNewzasvxstoqqRRgbQNMm4eVr5MDzrasFhMdJTLO/kgD71KZ07
eGWHvPSmMidUBJbQ1l+UffmeYJouQp9cH9hP+Jwktkre7saK+yXRS7lKgZBuHCKY
rVZcbYwWy1Qxo+jV577JYXM=
-----END PRIVATE KEY-----";

// set up the Moodle page
$PAGE->set_context(get_system_context());
$PAGE->set_pagelayout('admin');
$PAGE->set_title("Temp: Get PRO license");
$PAGE->set_heading("Temporary page to test server-side generation of PRO license key");
$PAGE->set_url($CFG->wwwroot . '/mod/booking/wb_server_generatelicense.php');

// TODO: move encryption part and private key to wunderbyte server
/**
 * Generate a PRO license key valid for the specified number of days
 * counting from the current date.
 *
 * @param string $wwwroot the wwwroot of the platform the license is for
 * @param int $number_of_days_valid the number of days the key is valid (counted from creation date), default = 1 year
 * @return string the license key to activate the PRO version
 */
function encrypt_licensekey(string $wwwroot, int $number_of_days_valid=365)
{
    // Step 1: Calculate expiration date and encode it with wwwroot
    $expiration_date = date("Y-m-d", strtotime(date("Y-m-d") . " +$number_of_days_valid days"));
    $ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext_raw = openssl_encrypt($expiration_date, $cipher, $wwwroot, $options=OPENSSL_RAW_DATA, $iv);
    $hmac = hash_hmac('sha256', $ciphertext_raw, $wwwroot, $as_binary=true);
    $licensekey = base64_encode( $iv.$hmac.$ciphertext_raw );

    // Step 2: encrypt licensekey with private key
    openssl_private_encrypt($licensekey, $encrypted_licensekey, PRIVATE_KEY);

    // Step 3: Use base64 encoding to generate a more readable string (instead of binary data)
    $encrypted_licensekey = base64_encode($encrypted_licensekey);

    return $encrypted_licensekey;
}

//Generate the actual page content
echo $OUTPUT->header();

$encrypted_licensekey = encrypt_licensekey($CFG->wwwroot);
echo "License key (valid 1 year): $encrypted_licensekey <br><br>";

echo "Decrypted expiration date (1y): ".wb_payment::decrypt_licensekey($encrypted_licensekey)."<br><br>";

// get license key which has been set in settings.php
$pluginconfig = get_config('booking');
if (!empty($pluginconfig->licensekey)){
    $licensekey_from_settings = $pluginconfig->licensekey;
    echo "License key from plugin config: $licensekey_from_settings<br>";
    $expiration_date = wb_payment::decrypt_licensekey($licensekey_from_settings);
    echo "Expires: $expiration_date";
} else {
    echo "PRO version has not been activated yet!";
}

//------------------------------------------------------//
// BEGIN OF CODE TO GENERATE PRIVATE-PUBLIC-KEY-PAIRS:
/*
// generate 2048-bit RSA key
$pk_Generate = openssl_pkey_new(array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA
));

// getting private-key
openssl_pkey_export($pk_Generate, $pk_Generate_Private); // we pass 2nd argument as reference

// getting public-key
$pk_Generate_Details = openssl_pkey_get_details($pk_Generate);
$pk_Generate_Public = $pk_Generate_Details['key'];

// free resources
openssl_pkey_free($pk_Generate);

// getting/importing public-key using PEM format
// $pk_Generate_Private now gets into PEM format...
// this is an alternative method compared to above used "public retrieval"
$pk_Import = openssl_pkey_get_private($pk_Generate_Private); // importing
$pk_Import_Details = openssl_pkey_get_details($pk_Import); // same method to get public key, like in previous
$pk_Import_Public = $pk_Import_Details['key'];
openssl_pkey_free($pk_Import); // cleanup

// see output
echo "<br><br>".$pk_Generate_Private."<br><br>".$pk_Generate_Public."<br><br>".$pk_Import_Public
    ."<br><br>".'Public keys are '.(strcmp($pk_Generate_Public,$pk_Import_Public)?'different':'identical').'.';
*/
// END OF CODE TO GENERATE PRIVATE-PUBLIC-KEY-PAIRS:
//------------------------------------------------------//

echo $OUTPUT->footer();
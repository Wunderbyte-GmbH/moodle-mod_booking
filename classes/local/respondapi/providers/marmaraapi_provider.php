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
 * marmaraapi_provider class.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Mahdi Poustini
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\local\respondapi\providers;

use mod_booking\local\respondapi\providers\interfaces\respondapi_provider_interface;



defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

use curl;

/**
 * Class respondapi
 *
 * Handles communication with the Marmara API for syncing keywords and persons.
 *
 * @package mod_booking
 */
class marmaraapi_provider implements respondapi_provider_interface {
    /** @var string Base URL of Marmara API */
    private string $baseurl;

    /** @var string Secret key for authentication */
    private string $secret;

    /** @var string Client ID for Marmara API */
    private string $clientid;

    /** @var bool Whether Marmara sync is enabled by default */
    private bool $defaultsync;

    /** @var int|null Parent keyword ID for hierarchy */
    private ?int $keywordparentid;

    /** @var curl Curl instance used for HTTP requests */
    private curl $curl;

    /**
     * Default headers for Marmara API requests.
     */
    private const DEFAULT_HEADERS = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    /**
     * Default cURL options for Marmara API requests.
     */
    private const DEFAULT_OPTIONS = [
        'CURLOPT_TIMEOUT' => 10,
        'CURLOPT_CONNECTTIMEOUT' => 5,
    ];

    /**
     * Endpoint for importing a keyword.
     *
     * Expected to return a JSON response with a numeric "id" field.
     * Example: POST /api/import_keyword
     */
    private const ENDPOINT_IMPORT_KEYWORD = '/api/import_keyword';

    /**
     * Endpoint for importing a person.
     *
     * Used to synchronize individual user data to Marmara.
     * Example: POST /api/import_person
     */
    private const ENDPOINT_IMPORT_PERSON = '/api/import_person';

    /**
     * Endpoint for get keywords.
     *
     * Used to synchronize individual user data to Marmara.
     * Example: POST /api/keywords
     */
    private const ENDPOINT_KEYWORDS = '/api/keywords';

    /**
     * respondapi constructor.
     *
     * Loads configuration settings for communicating with Marmara.
     */
    public function __construct() {
        $this->baseurl = rtrim((string)get_config('booking', 'marmara_baseurl'), '/');
        $this->secret = (string)get_config('booking', 'marmara_secret');
        $this->clientid = (string)get_config('booking', 'marmara_clientid');
        $this->defaultsync = (bool)get_config('booking', 'marmara_defaultsync');
        $this->keywordparentid = get_config('booking', 'marmara_keywordparentid');
        if ($this->keywordparentid !== '' && $this->keywordparentid !== null) {
            $this->keywordparentid = (int)$this->keywordparentid;
        } else {
            $this->keywordparentid = null;
        }

        $this->curl = new curl();
        $this->curl->setHeader(array_merge(self::DEFAULT_HEADERS, [
            'Authorization: Bearer ' . $this->secret,
        ]));
        $this->curl->setopt(self::DEFAULT_OPTIONS);

    }

    /**
     * Sync (create or update) a keyword in the Marmara API.
     *
     * @param string $name The name of the keyword (required).
     * @param int|null $id Optional existing keyword ID to update.
     * @param string|null $comment Optional comment or description.
     * @param int|null $parentid Optional parent ID.
     * @return int|null The returned keyword ID, or null on failure.
     */
    public function sync_keyword(string $name, ?int $id = null, ?string $comment = null, ?int $parentid = null): ?int {
        $url = $this->baseurl . self::ENDPOINT_IMPORT_KEYWORD;

        $payload = [
            'name' => $name,
            'client_id' => $this->clientid,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
        }

        if (!empty($comment)) {
            $payload['comment'] = $comment;
        }

        if (!empty($parentid)) {
            $payload['parent_id'] = $this->keywordparentid;
        }

        $response = $this->curl->post($url, json_encode($payload));

        if (is_numeric($response)) {
            return (int)$response;
        }

        // Log error for debugging (optional).
        debugging('Marmara sync failed: ' . $response, DEBUG_DEVELOPER);

        return null;
    }

    /**
     * Fetch a list of keywords from the Marmara API by parent keyword ID.
     *
     * @param int $parentkeywordid The parent keyword ID to filter results.
     * @return array An array of keyword objects, or an empty array on failure.
     */
    public function get_keywords(?int $parentkeywordid = null): array {
        $url = $this->baseurl . self::ENDPOINT_KEYWORDS;

        $parentkeywordid = $parentkeywordid ?? $this->keywordparentid;

        if ($parentkeywordid === null) {
            debugging('get_keywords failed: no parentkeywordid provided and no default configured', DEBUG_DEVELOPER);
            return [];
        }

        $payload = [
            'filter' => [
                'client_id' => $this->clientid,
                // 'id' => $parentkeywordid,
            ],
        ];

        $response = $this->curl->post($url, json_encode($payload));
        $responsejson = json_decode($response);

        if (is_array($responsejson)) {
            return $responsejson;
        }

        // Log error for debugging (optional).
        debugging('Marmara get_keywords failed: ' . $response, DEBUG_DEVELOPER);

        return [];
    }
}

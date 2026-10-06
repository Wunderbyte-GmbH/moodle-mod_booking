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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\attachment;

use bookingextension_agent\local\wizard\interfaces\attachment_resolver;

/**
 * Token-based lifecycle management for uploaded attachment temp files.
 *
 * Tokens are short-lived (TTL configured in db/caches.php, default 1800s).
 * Each token is bound to the uploading user and context so it cannot be
 * resolved by a different user.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attachment_token_service implements attachment_resolver {
    /**
     * Cache component name (matches db/caches.php).
     */
    private const CACHE_COMPONENT = 'bookingextension_agent';

    /**
     * Cache area name (matches db/caches.php).
     */
    private const CACHE_AREA = 'attachment_tokens';

    /**
     * Create a token for a freshly uploaded temp file.
     *
     * @param int    $userid    Uploading user id.
     * @param int    $contextid Moodle context id.
     * @param string $tmppath   Absolute path to temp file.
     * @param string $mime      Validated MIME type.
     * @param string $filename  Original file name (display only).
     * @return string Opaque token string.
     */
    public function create(int $userid, int $contextid, string $tmppath, string $mime, string $filename): string {
        // Cryptographically strong, unguessable token (256 bits). PARAM_ALPHANUMEXT-safe (hex).
        $token = bin2hex(random_bytes(32));

        $cache = \cache::make(self::CACHE_COMPONENT, self::CACHE_AREA);
        $cache->set($token, [
            'userid'    => $userid,
            'contextid' => $contextid,
            'path'      => $tmppath,
            'mime'      => $mime,
            'filename'  => $filename,
            'expires'   => time() + 1800,
        ]);

        return $token;
    }

    /**
     * Resolve a token to its file metadata.
     *
     * Validates ownership (userid + contextid) and TTL.
     *
     * @param string $token
     * @param int    $userid
     * @param int    $contextid
     * @return array{path:string,mime:string,filename:string}
     * @throws \moodle_exception When token is invalid, expired, or owned by another user.
     */
    public function resolve(string $token, int $userid, int $contextid): array {
        $cache = \cache::make(self::CACHE_COMPONENT, self::CACHE_AREA);
        $data = $cache->get($token);

        if (!is_array($data)) {
            throw new \moodle_exception('ai_attachment_token_invalid', 'bookingextension_agent');
        }

        if ((int)$data['userid'] !== $userid || (int)$data['contextid'] !== $contextid) {
            throw new \moodle_exception('ai_attachment_token_invalid', 'bookingextension_agent');
        }

        if (!empty($data['expires']) && time() > (int)$data['expires']) {
            $this->invalidate($token);
            throw new \moodle_exception('ai_attachment_token_invalid', 'bookingextension_agent');
        }

        if (!file_exists((string)$data['path'])) {
            $cache->delete($token);
            throw new \moodle_exception('ai_attachment_token_invalid', 'bookingextension_agent');
        }

        return [
            'path'     => (string)$data['path'],
            'mime'     => (string)$data['mime'],
            'filename' => (string)$data['filename'],
        ];
    }

    /**
     * Invalidate a token and delete its associated temp file.
     *
     * Safe to call even if token no longer exists.
     *
     * @param string $token
     * @return void
     */
    public function invalidate(string $token): void {
        $cache = \cache::make(self::CACHE_COMPONENT, self::CACHE_AREA);
        $data = $cache->get($token);

        if (is_array($data) && !empty($data['path'])) {
            $path = (string)$data['path'];
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $cache->delete($token);
    }

    /**
     * Clean up expired tokens and their associated temp files.
     *
     * Also scans the temp directory for orphaned files older than 1800s.
     *
     * @return void
     */
    public function cleanup_expired(): void {
        $tmpdir = make_temp_directory('bookingextension_agent/uploads');

        $files = glob($tmpdir . '/wizard_*');
        if (!is_array($files)) {
            return;
        }

        $cutoff = time() - 1800;
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}

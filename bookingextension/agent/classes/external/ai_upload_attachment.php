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
 * External service: upload a file attachment for AI agent use.
 *
 * Accepts file content as a base64-encoded data URL so the call fits within
 * Moodle's standard AJAX/WebService transport (no multipart needed).
 * The frontend reads the file with FileReader.readAsDataURL() and passes the
 * result directly to this service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;

/**
 * Upload a file attachment for use with the AI agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_upload_attachment extends external_api {
    /** Allowed MIME types. */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    /** Default max bytes for images (10 MB). */
    private const DEFAULT_MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /** Default max bytes for PDFs (20 MB). */
    private const DEFAULT_MAX_PDF_BYTES = 20 * 1024 * 1024;

    /**
     * Describe parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'filename'  => new external_value(PARAM_FILE, 'Original file name (used for display only).'),
            'mimetype'  => new external_value(PARAM_RAW, 'MIME type declared by the browser.'),
            'filedata'  => new external_value(
                PARAM_RAW,
                'Base64-encoded file content (data URL format: "data:<mime>;base64,<data>" or plain base64).'
            ),
        ]);
    }

    /**
     * Upload and store a file, returning an attachment token.
     *
     * @param int    $contextid
     * @param string $filename
     * @param string $mimetype
     * @param string $filedata  Base64 data URL or plain base64 string.
     * @return array
     */
    public static function execute(int $contextid, string $filename, string $mimetype, string $filedata): array {
        global $USER;

        // CSRF protection: this is a write endpoint (stores a temp file and mints a token).
        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'filename'  => $filename,
            'mimetype'  => $mimetype,
            'filedata'  => $filedata,
        ]);

        $contextid = (int)$params['contextid'];
        $filename  = clean_param(trim((string)$params['filename']), PARAM_FILE);
        $mimetype  = trim((string)$params['mimetype']);
        $filedata  = (string)$params['filedata'];

        // Auth.
        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, $contextid)) {
            return [
                'success' => false,
                'attachment_token' => '',
                'attachment_type' => '',
                'display_name' => '',
                'thumbnail_html' => '',
                'message' => $problem['message'],
            ];
        }
        $context = context::instance_by_id($contextid, MUST_EXIST);
        self::validate_context($context);

        // Strip the data-URL prefix if present: "data:<mime>;base64,<data>".
        $rawb64 = $filedata;
        if (preg_match('/^data:[^;]+;base64,(.+)$/s', $filedata, $m)) {
            $rawb64 = $m[1];
        }

        // Decode.
        $binary = base64_decode($rawb64, true);
        if ($binary === false || $binary === '') {
            return self::error_response(get_string('ai_upload_no_file', 'bookingextension_agent'));
        }

        // Server-side MIME detection from actual binary content.
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);
        $actualmime = $finfo->buffer($binary);

        if (!in_array($actualmime, self::ALLOWED_MIMES, true)) {
            return self::error_response(get_string('ai_upload_invalid_type', 'bookingextension_agent'));
        }

        // Size check.
        $size    = strlen($binary);
        $isimage = str_starts_with($actualmime, 'image/');
        $maxbytes = $isimage
            ? (int)(get_config('bookingextension_agent', 'max_image_upload_bytes') ?: self::DEFAULT_MAX_IMAGE_BYTES)
            : (int)(get_config('bookingextension_agent', 'max_pdf_upload_bytes') ?: self::DEFAULT_MAX_PDF_BYTES);

        if ($size > $maxbytes) {
            return self::error_response(
                get_string('ai_upload_file_too_large', 'bookingextension_agent', display_size($maxbytes))
            );
        }

        // Write to temp file.
        $tmpdir  = make_temp_directory('bookingextension_agent/uploads');
        $ext     = self::safe_extension($actualmime);
        $tmpname = 'wizard_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $tmppath = $tmpdir . '/' . $tmpname;

        if (file_put_contents($tmppath, $binary) === false) {
            return self::error_response('Could not store uploaded file.');
        }

        // Create token.
        $type     = $isimage ? 'image' : 'pdf';
        $tokensvc = new attachment_token_service();
        $token    = $tokensvc->create((int)$USER->id, $contextid, $tmppath, $actualmime, $filename);

        // Build thumbnail for images.
        $thumbhtml = '';
        if ($isimage) {
            $thumbhtml = self::build_thumbnail_html($binary, $actualmime, $filename);
        }

        return [
            'success'          => true,
            'attachment_token' => $token,
            'attachment_type'  => $type,
            'display_name'     => $filename !== '' ? $filename : $tmpname,
            'thumbnail_html'   => $thumbhtml,
            'message'          => '',
        ];
    }

    /**
     * Describe return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'          => new external_value(PARAM_BOOL, 'Whether upload succeeded.'),
            'attachment_token' => new external_value(PARAM_ALPHANUMEXT, 'Opaque token for this attachment.', VALUE_OPTIONAL, ''),
            'attachment_type'  => new external_value(PARAM_ALPHA, '"image" or "pdf".', VALUE_OPTIONAL, ''),
            'display_name'     => new external_value(PARAM_TEXT, 'Original file name for display.', VALUE_OPTIONAL, ''),
            'thumbnail_html'   => new external_value(PARAM_RAW, 'Inline thumbnail HTML for images.', VALUE_OPTIONAL, ''),
            'message'          => new external_value(PARAM_TEXT, 'Error message when success is false.', VALUE_OPTIONAL, ''),
        ]);
    }
    // Private helpers.

    /**
     * Build a normalised error response.
     *
     * @param string $message
     * @return array
     */
    private static function error_response(string $message): array {
        return [
            'success'          => false,
            'attachment_token' => '',
            'attachment_type'  => '',
            'display_name'     => '',
            'thumbnail_html'   => '',
            'message'          => $message,
        ];
    }

    /**
     * Return a safe file extension for a given MIME type.
     *
     * @param string $mime
     * @return string
     */
    private static function safe_extension(string $mime): string {
        return match ($mime) {
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/gif'     => 'gif',
            'application/pdf' => 'pdf',
            default         => 'bin',
        };
    }

    /**
     * Build a base64 inline thumbnail <img> for an image binary.
     *
     * Returns empty string if GD is unavailable or decoding fails.
     *
     * @param string $binary Raw file bytes.
     * @param string $mime   MIME type.
     * @param string $alt    Alt text.
     * @return string HTML string.
     */
    private static function build_thumbnail_html(string $binary, string $mime, string $alt): string {
        if (!extension_loaded('gd')) {
            return '';
        }

        try {
            $src = @imagecreatefromstring($binary);
            if (!$src) {
                return '';
            }

            $srcw  = (int)imagesx($src);
            $srch  = (int)imagesy($src);
            $maxw  = 120;
            $maxh  = 80;
            $scale = min($maxw / max($srcw, 1), $maxh / max($srch, 1), 1.0);
            $dstw  = max(1, (int)round($srcw * $scale));
            $dsth  = max(1, (int)round($srch * $scale));

            $dst = imagecreatetruecolor($dstw, $dsth);
            if (!$dst) {
                imagedestroy($src);
                return '';
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstw, $dsth, $srcw, $srch);
            imagedestroy($src);

            ob_start();
            imagepng($dst);
            $raw = ob_get_clean();
            imagedestroy($dst);

            if (!is_string($raw) || $raw === '') {
                return '';
            }

            $b64     = base64_encode($raw);
            $safealt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
            return '<img src="data:image/png;base64,' . $b64 . '" class="booking-ai-thumb" alt="' . $safealt . '">';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

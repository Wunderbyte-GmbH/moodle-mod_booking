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
 * Canonical output DTO for mutation operations.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent\dto;

/**
 * Canonical output DTO returned by all mutation service methods.
 *
 * Status values: 'executed', 'error', 'skipped', 'dry_run_ok'.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mutation_result_dto {
    /** @var string One of: executed, error, skipped, dry_run_ok. */
    public readonly string $status;

    /** @var string Human-readable mutation detail message. */
    public readonly string $detail;

    /** @var int|null Created/updated record id when available. */
    public readonly ?int $resultid;

    /** @var array<int,string> Non-fatal warnings collected during mutation. */
    public readonly array $warnings;

    /** @var array<int,int|string> Option ids produced by preview flows. */
    public readonly array $previewoptionids;

    /**
     * Constructor.
     *
     * @param string   $status           One of: 'executed', 'error', 'skipped', 'dry_run_ok'.
     * @param string   $detail           Human-readable message.
     * @param int|null $resultid         Created/updated record id, or null.
     * @param array    $warnings         Array of warning strings.
     * @param array    $previewoptionids Array of preview option ids.
     */
    public function __construct(
        string $status,
        string $detail,
        ?int $resultid = null,
        array $warnings = [],
        array $previewoptionids = [],
    ) {
        $this->status = $status;
        $this->detail = $detail;
        $this->resultid = $resultid;
        $this->warnings = $warnings;
        $this->previewoptionids = $previewoptionids;
    }

    /**
     * Factory for a successful mutation.
     *
     * @param int    $resultid
     * @param string $detail
     * @param array  $warnings
     * @param array  $previewoptionids
     * @return self
     */
    public static function success(
        int $resultid,
        string $detail,
        array $warnings = [],
        array $previewoptionids = []
    ): self {
        return new self('executed', $detail, $resultid, $warnings, $previewoptionids);
    }

    /**
     * Factory for a failed mutation.
     *
     * @param string $detail
     * @return self
     */
    public static function error(string $detail): self {
        return new self('error', $detail);
    }

    /**
     * Factory for a skipped mutation (e.g. idempotency key already processed).
     *
     * @param string $detail
     * @return self
     */
    public static function skipped(string $detail): self {
        return new self('skipped', $detail);
    }

    /**
     * Factory for a dry-run validation pass (no side effects).
     *
     * @param string $detail
     * @param array  $warnings
     * @return self
     */
    public static function dry_run_ok(string $detail, array $warnings = []): self {
        return new self('dry_run_ok', $detail, null, $warnings);
    }

    /**
     * Return all properties as an associative array.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'status'           => $this->status,
            'detail'           => $this->detail,
            'resultid'         => $this->resultid,
            'warnings'         => $this->warnings,
            'previewoptionids' => $this->previewoptionids,
        ];
    }
}

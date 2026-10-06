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

namespace bookingextension_agent\local\wizard\dto;

/**
 * Preflight contract v2 DTO.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_result_v2 {
    /** @var string Layer-1 blocking id. */
    public const BLOCKING_LAYER_SCHEMA = 'schema';

    /** @var string Layer-2 blocking id. */
    public const BLOCKING_LAYER_DOMAIN = 'domain';

    /** @var string Layer-3 blocking id. */
    public const BLOCKING_LAYER_EXECUTION_GATE = 'execution_gate';

    /** @var string pass|soft_block|hard_block|retry_hint */
    public readonly string $status;

    /** @var string[] */
    public readonly array $issuecodes;

    /** @var string */
    public readonly string $blockinglayer;

    /** @var int */
    public readonly int $retryafterms;

    /** @var int */
    public readonly int $retrycount;

    /** @var int */
    public readonly int $durationms;

    /** @var array Legacy-kompatibel: prepared execute input. */
    public readonly array $preparedinput;

    /** @var array[] Legacy-kompatibel: structured issues. */
    public readonly array $issues;

    /**
     * Create immutable preflight contract v2 result.
     *
     * @param string $status pass|soft_block|hard_block|retry_hint
     * @param string[] $issuecodes
     * @param string $blockinglayer
     * @param int $retryafterms
     * @param int $retrycount
     * @param int $durationms
     * @param array $preparedinput
     * @param array[] $issues
     */
    public function __construct(
        string $status,
        array $issuecodes = [],
        string $blockinglayer = '',
        int $retryafterms = 0,
        int $retrycount = 0,
        int $durationms = 0,
        array $preparedinput = [],
        array $issues = []
    ) {
        $allowed = ['pass', 'soft_block', 'hard_block', 'retry_hint'];
        $normalizedstatus = trim($status);
        if (!in_array($normalizedstatus, $allowed, true)) {
            $normalizedstatus = 'hard_block';
        }

        $this->status = $normalizedstatus;
        $this->issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));
        $this->blockinglayer = $this->normalize_blocking_layer($blockinglayer);
        $this->retryafterms = max(0, $retryafterms);
        $this->retrycount = max(0, $retrycount);
        $this->durationms = max(0, $durationms);
        $this->preparedinput = $preparedinput;
        $this->issues = $issues;
    }

    /**
     * Normalize blocking-layer value to a strict, known set.
     *
     * @param string $blockinglayer
     * @return string
     */
    private function normalize_blocking_layer(string $blockinglayer): string {
        $normalized = trim($blockinglayer);
        if ($normalized === '') {
            return '';
        }

        $aliases = [
            '1' => self::BLOCKING_LAYER_SCHEMA,
            '2' => self::BLOCKING_LAYER_DOMAIN,
            '3' => self::BLOCKING_LAYER_EXECUTION_GATE,
            'layer_1' => self::BLOCKING_LAYER_SCHEMA,
            'layer_2' => self::BLOCKING_LAYER_DOMAIN,
            'layer_3' => self::BLOCKING_LAYER_EXECUTION_GATE,
            'execution' => self::BLOCKING_LAYER_EXECUTION_GATE,
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        $allowed = [
            self::BLOCKING_LAYER_SCHEMA,
            self::BLOCKING_LAYER_DOMAIN,
            self::BLOCKING_LAYER_EXECUTION_GATE,
        ];

        return in_array($normalized, $allowed, true) ? $normalized : '';
    }

    /**
     * Export DTO as normalized associative array.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'status' => $this->status,
            'issue_codes' => $this->issuecodes,
            'blocking_layer' => $this->blockinglayer,
            'retry_after_ms' => $this->retryafterms,
            'retry_count' => $this->retrycount,
            'duration_ms' => $this->durationms,
        ];
    }

    /**
     * Legacy helper: successful preflight with normalized input.
     *
     * @param array $preparedinput
     * @return self
     */
    public static function ok(array $preparedinput): self {
        return new self('pass', [], '', 0, 0, 0, $preparedinput, []);
    }

    /**
     * Legacy helper: preflight passed with confirmable issues.
     *
     * @param array $preparedinput
     * @param array[] $issues
     * @return self
     */
    public static function confirmable(array $preparedinput, array $issues): self {
        $issuecodes = self::extract_issue_codes_from_issues($issues);
        return new self(
            'soft_block',
            $issuecodes,
            self::BLOCKING_LAYER_DOMAIN,
            0,
            0,
            0,
            $preparedinput,
            $issues
        );
    }

    /**
     * Legacy helper: preflight failed.
     *
     * @param array[] $issues
     * @return self
     */
    public static function invalid(array $issues): self {
        $issuecodes = self::extract_issue_codes_from_issues($issues);
        return new self(
            'hard_block',
            $issuecodes,
            self::BLOCKING_LAYER_DOMAIN,
            0,
            0,
            0,
            [],
            $issues
        );
    }

    /**
     * Extract canonical issue codes from legacy issue arrays.
     *
     * @param array[] $issues
     * @return string[]
     */
    private static function extract_issue_codes_from_issues(array $issues): array {
        return array_values(array_filter(array_map(
            static fn(array $issue): string => trim((string)($issue['code'] ?? '')),
            $issues
        )));
    }
}

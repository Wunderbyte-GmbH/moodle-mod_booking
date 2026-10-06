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
 * Queue transition service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\risk\risk_class_resolver;
use bookingextension_agent\local\wizard\queue\queue_manager;

/**
 * Centralizes queue status transitions.
 */
class queue_transition_service {
    /** Fallback reason code when a caller provides an empty value. */
    private const DEFAULT_REASON_CODE = 'TRANSITION_UNSPECIFIED';

    /**
     * Reason code for a preflight fail caused by a needs_clarification issue: the step is
     * blocked on a user answer, NOT dead. queue_manager keys the F5 placeholder settle on this
     * (the bound placeholder reverts to planned instead of failing), and the queue row stops
     * mislabeling category-style questions as hard blocks (thread 589).
     */
    public const REASON_PREFLIGHT_NEEDS_CLARIFICATION = 'PREFLIGHT_NEEDS_CLARIFICATION';

    /** Maximum distinct retry layers allowed for the same error class. */
    private const MAX_RETRY_LAYERS_PER_ERROR_CLASS = 2;

    /** Issue code emitted when retry layer guard blocks another retry. */
    private const RETRY_LAYER_GUARD_ISSUE_CODE = 'RETRY_LAYER_LIMIT_EXCEEDED';

    /** Issue code emitted when planner/queue/execution retry layers collide. */
    private const RETRY_LAYER_COLLISION_ISSUE_CODE = 'RETRY_LAYER_COLLISION';

    /**
     * Apply canonical preflight decision to mutating queue items.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string[] $queueitemids
     * @param string $status
     * @param string[] $issuecodes
     * @param string[] $errors
     * @param array $v2result
     * @param bool $autoconfirmmode
     * @return void
     */
    public function apply_preflight_decision(
        queue_manager $queuesvc,
        int $threadid,
        array $queueitemids,
        string $status,
        array $issuecodes,
        array $errors,
        array $v2result,
        bool $autoconfirmmode
    ): void {
        $queueitemids = $this->normalize_queue_item_ids($queueitemids);
        if (empty($queueitemids)) {
            return;
        }

        $status = trim($status);
        $targetstatus = queue_status_policy::failed_status();
        $errorclass = '';
        $extrafields = [];
        $message = trim(implode(' ', array_values(array_unique(array_map('strval', $errors)))));

        if ($status === 'pass') {
            $targetstatus = $autoconfirmmode ? queue_status_policy::ready_status() : 'blocked_confirmation';
        } else if ($status === 'soft_block') {
            $targetstatus = 'blocked_confirmation';
        } else if ($status === 'retry_hint') {
            $targetstatus = 'retry_waiting';
            $errorclass = 'preflight_retry';
        } else {
            $targetstatus = queue_status_policy::failed_status();
            $errorclass = 'preflight_block';
        }

        foreach ($queueitemids as $queueitemid) {
            $item = $queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }
            $riskclass = risk_class_resolver::normalize((string)($item['risk_class'] ?? ''));
            if (
                queue_status_policy::is_failed_status((string)($item['status'] ?? ''))
                && !empty((array)($item['issue_codes'] ?? []))
            ) {
                continue;
            }

            if (queue_status_policy::is_retry_waiting_status($targetstatus)) {
                if ($riskclass === skill_risk_class::R3) {
                    $this->to_failed(
                        $queuesvc,
                        $threadid,
                        $queueitemid,
                        'R3_NO_RETRY',
                        array_values(array_unique(array_merge($issuecodes, ['R3_NO_RETRY']))),
                        'preflight_retry_forbidden',
                        'Retry is forbidden for irreversible_or_external skills.'
                    );
                    continue;
                }
                $currentretrycount = max(0, (int)($item['preflight_retry_count'] ?? $item['retry_count'] ?? 0));
                $nextretrycount = $currentretrycount + 1;
                $retryafterms = max(1, (int)($v2result['retry_after_ms'] ?? 0));
                if ($retryafterms <= 1) {
                    $retryafterms = min(4000, 500 * (2 ** max(0, min(8, $nextretrycount - 1))));
                }
                $extrafields = [
                    'retry_count' => $nextretrycount,
                    'preflight_retry_count' => $nextretrycount,
                    'retry_after_ms' => $retryafterms,
                    'backoff_ms' => $retryafterms,
                    'next_retry_at' => time() + (int)ceil($retryafterms / 1000),
                ];
            }

            if (queue_status_policy::is_ready_status($targetstatus)) {
                $reasoncode = $autoconfirmmode ? 'PREFLIGHT_PASS_AUTOCONFIRM' : 'PREFLIGHT_PASS_READY';
                if ($riskclass === skill_risk_class::R1 && !$autoconfirmmode) {
                    $this->to_blocked_confirmation(
                        $queuesvc,
                        $threadid,
                        $queueitemid,
                        'PREFLIGHT_PASS_NEEDS_CONFIRMATION',
                        $issuecodes
                    );
                } else if ($riskclass === skill_risk_class::R2) {
                    $this->to_blocked_confirmation(
                        $queuesvc,
                        $threadid,
                        $queueitemid,
                        'PREFLIGHT_R2_EXPLICIT_CONFIRMATION',
                        $issuecodes
                    );
                } else if ($riskclass === skill_risk_class::R3) {
                    $this->to_blocked_confirmation(
                        $queuesvc,
                        $threadid,
                        $queueitemid,
                        'PREFLIGHT_R3_MANUAL_CONFIRMATION',
                        $issuecodes
                    );
                } else {
                    $this->to_ready($queuesvc, $threadid, $queueitemid, $reasoncode, $issuecodes);
                }
            } else if ($targetstatus === 'blocked_confirmation') {
                $reasoncode = $status === 'soft_block'
                    ? 'PREFLIGHT_SOFT_BLOCK'
                    : 'PREFLIGHT_PASS_NEEDS_CONFIRMATION';
                $this->to_blocked_confirmation($queuesvc, $threadid, $queueitemid, $reasoncode, $issuecodes);
            } else if (queue_status_policy::is_retry_waiting_status($targetstatus)) {
                $this->to_retry_waiting(
                    $queuesvc,
                    $threadid,
                    $queueitemid,
                    'PREFLIGHT_RETRY_HINT',
                    $issuecodes,
                    $errorclass,
                    $message,
                    $extrafields
                );
            } else {
                // A clarification-class block (any needs_clarification issue in the preflight
                // result) is a question to the user, not a dead step — carry that distinction
                // on the item so the F5 placeholder settle can keep the step owed.
                $failreason = !empty($v2result['has_clarification_issues'])
                    ? self::REASON_PREFLIGHT_NEEDS_CLARIFICATION
                    : 'PREFLIGHT_HARD_BLOCK';
                $this->to_failed(
                    $queuesvc,
                    $threadid,
                    $queueitemid,
                    $failreason,
                    $issuecodes,
                    $errorclass,
                    $message
                );
            }
        }
    }

    /**
     * Transition queue item to ready.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @return void
     */
    public function to_ready(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes = []
    ): void {
        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            queue_status_policy::ready_status(),
            $issuecodes,
            '',
            '',
            ['reason_code' => $this->normalize_reason_code($reasoncode)]
        );
    }

    /**
     * Transition queue item to blocked_confirmation.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @return void
     */
    public function to_blocked_confirmation(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes = []
    ): void {
        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            'blocked_confirmation',
            $issuecodes,
            '',
            '',
            ['reason_code' => $this->normalize_reason_code($reasoncode)]
        );
    }

    /**
     * Transition queue item to retry_waiting.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @param string $errorclass
     * @param string $message
     * @param array $meta
     * @return void
     */
    public function to_retry_waiting(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes,
        string $errorclass,
        string $message,
        array $meta
    ): void {
        $retrylayer = $this->resolve_retry_layer_from_reason_code($reasoncode);
        $retrypolicy = new retry_policy_service();
        $retrycategory = $retrypolicy->resolve_retry_hint_category($errorclass, $issuecodes, $retrylayer);
        if ($retrycategory === retry_policy_service::CATEGORY_UNDEFINED) {
            $queuesvc->update_status(
                $threadid,
                $queueitemid,
                queue_status_policy::failed_status(),
                array_values(array_unique(array_merge($issuecodes, [
                    retry_policy_service::ISSUE_RETRY_CATEGORY_UNDEFINED,
                ]))),
                trim($errorclass),
                'Retry blocked: retry_hint category is undefined.',
                [
                    'reason_code' => $this->normalize_reason_code('RETRY_CATEGORY_UNDEFINED'),
                    'retry_layer' => $retrylayer,
                    'retry_origin' => $retrylayer,
                    'retry_reason' => $this->normalize_reason_code($reasoncode),
                    'retry_hint_category' => $retrycategory,
                    'retry_terminal_reason' => 'retry_hint_category_undefined',
                ]
            );
            return;
        }

        if (!$retrypolicy->is_retryable_category($retrycategory)) {
            $queuesvc->update_status(
                $threadid,
                $queueitemid,
                queue_status_policy::failed_status(),
                array_values(array_unique(array_merge($issuecodes, [
                    retry_policy_service::ISSUE_RETRY_CATEGORY_NOT_ALLOWED,
                ]))),
                trim($errorclass),
                'Retry blocked: retry category is not retryable.',
                [
                    'reason_code' => $this->normalize_reason_code('RETRY_CATEGORY_NOT_ALLOWED'),
                    'retry_layer' => $retrylayer,
                    'retry_origin' => $retrylayer,
                    'retry_reason' => $this->normalize_reason_code($reasoncode),
                    'retry_hint_category' => $retrycategory,
                    'retry_terminal_reason' => 'retry_category_not_allowed',
                ]
            );
            return;
        }

        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $previouserrorclass = is_array($item) ? trim((string)($item['error_class'] ?? '')) : '';
        $existinglayers = is_array($item) ? $this->normalize_retry_layers($item['retry_layers'] ?? []) : [];
        $layerdecision = $this->evaluate_retry_layer_guard(
            $previouserrorclass,
            $existinglayers,
            $errorclass,
            $reasoncode
        );

        if (!$layerdecision['allow']) {
            $queuesvc->update_status(
                $threadid,
                $queueitemid,
                queue_status_policy::failed_status(),
                array_values(array_unique(array_merge($issuecodes, [
                    self::RETRY_LAYER_GUARD_ISSUE_CODE,
                    self::RETRY_LAYER_COLLISION_ISSUE_CODE,
                ]))),
                trim($errorclass),
                'Retry blocked: identical error_class exceeded retry layer limit.',
                [
                    'reason_code' => $this->normalize_reason_code('RETRY_LAYER_GUARD_BLOCKED'),
                    'retry_layers' => $layerdecision['layers'],
                    'retry_layer' => $retrylayer,
                    'retry_origin' => $retrylayer,
                    'retry_reason' => $this->normalize_reason_code($reasoncode),
                    'retry_hint_category' => $retrycategory,
                    'retry_terminal_reason' => 'retry_layer_limit_exceeded',
                ]
            );
            return;
        }

        $retryissuecodes = array_values(array_unique(array_merge(
            $issuecodes,
            [
                'RETRY_DECISION_LAYER_' . strtoupper($retrylayer),
                'RETRY_CATEGORY_' . $retrycategory,
            ]
        )));

        $retryattempt = max(
            1,
            (int)($meta['retry_count'] ?? 0),
            (int)($meta['preflight_retry_count'] ?? 0)
        );

        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            'retry_waiting',
            $retryissuecodes,
            $errorclass,
            $message,
            array_merge($meta, [
                'reason_code' => $this->normalize_reason_code($reasoncode),
                'retry_layers' => $layerdecision['layers'],
                'retry_layer' => $retrylayer,
                'retry_origin' => $retrylayer,
                'retry_reason' => $this->normalize_reason_code($reasoncode),
                'retry_attempt' => $retryattempt,
                'retry_hint_category' => $retrycategory,
                'retry_terminal_reason' => '',
            ])
        );
    }

    /**
     * Evaluate whether a retry transition is allowed under layer guardrails.
     *
     * @param string $previouserrorclass
     * @param string[] $existinglayers
     * @param string $currenterrorclass
     * @param string $reasoncode
     * @return array{allow:bool,layers:string[]}
     */
    private function evaluate_retry_layer_guard(
        string $previouserrorclass,
        array $existinglayers,
        string $currenterrorclass,
        string $reasoncode
    ): array {
        $normalizedcurrenterrorclass = trim($currenterrorclass);
        $normalizedpreviouserrorclass = trim($previouserrorclass);
        $layers = $this->normalize_retry_layers($existinglayers);
        $retrylayer = $this->resolve_retry_layer_from_reason_code($reasoncode);

        if ($normalizedcurrenterrorclass === '' || $normalizedpreviouserrorclass !== $normalizedcurrenterrorclass) {
            return ['allow' => true, 'layers' => [$retrylayer]];
        }

        if (in_array($retrylayer, $layers, true)) {
            return ['allow' => true, 'layers' => $layers];
        }

        if (count($layers) >= self::MAX_RETRY_LAYERS_PER_ERROR_CLASS) {
            return ['allow' => false, 'layers' => $layers];
        }

        $layers[] = $retrylayer;
        return ['allow' => true, 'layers' => array_values(array_unique($layers))];
    }

    /**
     * Transition queue item to failed.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @param string $errorclass
     * @param string $message
     * @return void
     */
    public function to_failed(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes = [],
        string $errorclass = '',
        string $message = ''
    ): void {
        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            queue_status_policy::failed_status(),
            $issuecodes,
            $errorclass,
            $message,
            ['reason_code' => $this->normalize_reason_code($reasoncode)]
        );
    }

    /**
     * Transition queue item to skipped.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @param string $errorclass
     * @param string $message
     * @return void
     */
    public function to_skipped(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes = [],
        string $errorclass = '',
        string $message = ''
    ): void {
        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            queue_status_policy::skipped_status(),
            $issuecodes,
            $errorclass,
            $message,
            ['reason_code' => $this->normalize_reason_code($reasoncode)]
        );
    }

    /**
     * Transition queue item to succeeded.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $reasoncode
     * @param string[] $issuecodes
     * @return void
     */
    public function to_succeeded(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $reasoncode,
        array $issuecodes = []
    ): void {
        $queuesvc->update_status(
            $threadid,
            $queueitemid,
            queue_status_policy::succeeded_status(),
            $issuecodes,
            '',
            '',
            ['reason_code' => $this->normalize_reason_code($reasoncode)]
        );
    }

    /**
     * Normalize a transition reason code.
     *
     * @param string $reasoncode
     * @return string
     */
    private function normalize_reason_code(string $reasoncode): string {
        $value = trim($reasoncode);
        return $value !== '' ? $value : self::DEFAULT_REASON_CODE;
    }

    /**
     * Resolve logical retry layer from transition reason code.
     *
     * @param string $reasoncode
     * @return string
     */
    private function resolve_retry_layer_from_reason_code(string $reasoncode): string {
        $normalized = strtoupper(trim($reasoncode));
        if (str_starts_with($normalized, 'PREFLIGHT_')) {
            return 'preflight';
        }
        if (str_starts_with($normalized, 'EXECUTION_')) {
            return 'execution';
        }
        if (str_starts_with($normalized, 'QUEUE_')) {
            return 'queue';
        }
        if (str_starts_with($normalized, 'LOOP_') || str_starts_with($normalized, 'PLANNER_')) {
            return 'planner';
        }

        return 'runtime';
    }

    /**
     * Normalize persisted retry layers to a unique non-empty list.
     *
     * @param mixed $layers
     * @return string[]
     */
    private function normalize_retry_layers($layers): array {
        if (!is_array($layers)) {
            return [];
        }

        $normalized = [];
        foreach ($layers as $layer) {
            $value = trim((string)$layer);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize queue item ids into non-empty unique string list.
     *
     * @param mixed[] $queueitemids
     * @return string[]
     */
    private function normalize_queue_item_ids(array $queueitemids): array {
        $normalized = [];
        foreach ($queueitemids as $id) {
            $value = trim((string)$id);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}

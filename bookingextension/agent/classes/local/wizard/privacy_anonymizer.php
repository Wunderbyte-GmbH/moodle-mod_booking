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
 * Privacy anonymization helper for LLM-bound text.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use core_text;

/**
 * Handles pre-LLM anonymization and pre-execution de-anonymization.
 */
class privacy_anonymizer {
    /** @var string Privacy mode disabled. */
    private const MODE_OFF = 'off';
    /** @var string Privacy mode with only backend/system anonymization. */
    private const MODE_SOFT = 'soft';
    /** @var string Privacy mode with strict user-message anonymization. */
    private const MODE_STRICT = 'strict';

    /** @var string Thread metadata key for token map. */
    private const TOKEN_MAP_METADATA_KEY = 'privacy_anon_map';
    /** @var string Cache key for user-linked name matching index. */
    private const NAME_MATCH_INDEX_CACHE_KEY = 'user_name_match_index_v1';
    /** @var string[] Common words that must never be treated as person names. */
    /**
     * @var string[] Shipped DEFAULT of the `aiprivacyprotectedwords` setting (examples of typical
     * non-name words). At runtime ONLY the setting is consulted — sites can edit or empty this list
     * in the admin UI; the constant is never merged in behind their back.
     */
    public const PROTECTED_WORDS_DEFAULT = [
        // German.
        'von', 'bei', 'mit', 'und', 'oder', 'der', 'die', 'das', 'dem', 'den', 'des',
        'ein', 'eine', 'einer', 'einem', 'einen', 'ich', 'du', 'er', 'sie', 'wir', 'ihr',
        'sein', 'ihre', 'ihren', 'soll', 'sollen', 'bitte', 'hier', 'dort', 'im', 'in',
        'am', 'an', 'auf', 'zu', 'zur', 'zum',
        // English.
        'for', 'and', 'or', 'the', 'a', 'to', 'with', 'by', 'is', 'are', 'be',
        // French.
        'les', 'une', 'pour', 'avec', 'dans', 'sur', 'par', 'qui', 'que', 'est', 'sont',
        'nous', 'vous', 'ils', 'elle', 'elles', 'leur', 'leurs', 'cette', 'ces', 'aux', 'chez',
        'sans', 'mais', 'donc', 'ici',
        // Italian.
        'gli', 'una', 'uno', 'per', 'con', 'nel', 'nella', 'dei', 'delle', 'che', 'sono', 'noi',
        'voi', 'loro', 'questo', 'questa', 'anche', 'come', 'dove',
        // Spanish.
        'los', 'las', 'para', 'por', 'del', 'son', 'nosotros', 'vosotros', 'ellos', 'ellas',
        'este', 'esta', 'donde', 'aquí', 'también',
        // Generic nouns frequently found in result summaries must never be treated as names.
        'user', 'users', 'benutzer', 'teilnehmer', 'teilnehmende',
        'utilisateur', 'utilisateurs', 'participant', 'participants',
        'utente', 'utenti', 'partecipante', 'partecipanti',
        'usuario', 'usuarios', 'participante', 'participantes',
    ];
    /** @var string[] Fields that should always resolve to original literal text for SQL updates. */
    private const SQL_TEXT_FIELDS = ['text', 'description', 'optionquery'];
    /** @var string[] Fields that represent user references and should prefer shorter observed variants. */
    private const USER_REFERENCE_FIELDS = ['userquery', 'teacherquery', 'targetuserquery'];
    /** @var string[] Structured person fields that must be anonymized independently. */
    private const PERSON_IDENTITY_FIELDS = ['firstname', 'lastname', 'email'];

    /**
     * @var string Reserved, guaranteed-undeliverable domain (RFC 2606 ".invalid" TLD) for the
     * email-shaped anonymization token. An email identity is masked as ANON_USER_<n>@anon.invalid
     * instead of the suffix form ANON_USER_<n>_email, because LLMs must still recognize the value
     * as an email address (e.g. a teacheremail parameter) — a non-email-shaped placeholder makes
     * them reject the field or ask for a different identifier. Both token regexes below spell this
     * domain out literally; keep all three in sync.
     */
    private const ANON_EMAIL_DOMAIN = 'anon.invalid';

    /**
     * @var string Regex matching an anonymization token wherever it appears in free text
     * (word-bounded find/replace). Single source so the matcher cannot drift from the parser below.
     * The email-shaped alternative must come before the generic suffix so the full address is
     * consumed (never a bare ANON_USER_<n> leaving "@anon.invalid" behind).
     */
    private const ANON_TOKEN_FIND_PATTERN = '/\bANON_USER_\d+(?:@anon\.invalid|_[a-z]+)?\b/';

    /** @var string Regex parsing a standalone token, capturing the stable id part (group 1). */
    private const ANON_TOKEN_PARSE_PATTERN = '/^(ANON_USER_\d+)(?:@anon\.invalid|_[a-z]+)?$/';

    /** @var string Visual marker appended to a de-masked identity so an authorized viewer sees it was privacy-masked. */
    private const DEMASK_MARKER = '👤';

    /** @var bool Storage mode: known map values are re-masked, no single-word name tokens are minted. */
    private bool $storagemode = false;

    /** @var string High-confidence token-map entry (full name, person field, email — #2226 D0). */
    public const CONFIDENCE_HIGH = 'high';

    /** @var string Low-confidence token-map entry (single-word name fallback — #2226 D0). */
    public const CONFIDENCE_LOW = 'low';

    /** @var string User-preference key holding per-user word/person collision decisions (JSON). */
    private const ANON_WORD_DECISIONS_PREF = 'bookingextension_agent_anonworddecisions';

    /** @var int Maximum stored collision decisions per user (oldest pruned first). */
    private const ANON_WORD_DECISIONS_MAX = 200;

    /**
     * @var string Shared email-address subpattern (no delimiters/flags) — the single address grammar
     * used by every email matcher, so they cannot drift apart.
     */
    private const EMAIL_SUBPATTERN = '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}';

    /**
     * @var string A name may start here: the text start, whitespace, an opening bracket or quote, or a URL segment
     * delimiter. Anything else before it (a dot, "@", "_", a letter) makes the name part of a larger token - a host
     * name, an address, an identifier (L45 UA-3, thread 14951: "example.org" with a user whose last name is "org").
     * Structure only, no word list.
     */
    private const NAME_START_PATTERN = '/(?<![^\s(\[{"\'\x{00AB}\x{201E}\x{201C}\x{201A}\x{2018}\x{2039}\/=?&#])\G/u';

    /**
     * @var string A name may end here: the text end, whitespace, punctuation, a closing bracket or quote, a URL
     * segment delimiter, or a dot that ends the sentence (followed by whitespace, a closing quote or bracket, or the
     * end). A dot followed by a letter or digit continues a host or file name.
     */
    private const NAME_END_PATTERN = '/\G(?=$|[\s,;:!?)\]}"\'\x{00BB}\x{201C}\x{201D}\x{2019}\x{2018}\x{203A}\x{2026}\/&=#]'
        . '|\.(?:$|[\s"\'\x{00BB}\x{201C}\x{201D}\x{2019})\]]))/u';

    /** @var conversation_store */
    private conversation_store $store;

    /** @var string[]|null Lazily-built union of built-in stop words and admin-configured protected words. */
    private ?array $protectedwords = null;

    /** @var array<int,array> Decoded collision decisions per user, memoized for the hot word loop. */
    private array $anonworddecisions = [];

    /** @var array<int,string> Raw preference value each memoized decision list was decoded from. */
    private array $anonworddecisionsraw = [];

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Return the set of words that must never be treated as a person name.
     *
     * Read exclusively from the admin-configured `aiprivacyprotectedwords` setting (comma- or
     * newline-separated; shipped default = {@see self::PROTECTED_WORDS_DEFAULT}), so sites decide
     * which common words are never anonymized when a real account happens to use them as a name
     * (e.g. a user literally called "admin user"). An emptied setting protects nothing.
     * Comparison is case-insensitive against normalized names.
     *
     * @return string[]
     */
    private function get_protected_words(): array {
        if ($this->protectedwords !== null) {
            return $this->protectedwords;
        }

        $words = [];
        $configured = (string)get_config('bookingextension_agent', 'aiprivacyprotectedwords');
        foreach (preg_split('/[,\r\n]+/', $configured) ?: [] as $word) {
            $normalized = core_text::strtolower(trim((string)$word));
            if ($normalized !== '') {
                $words[] = $normalized;
            }
        }

        $this->protectedwords = array_values(array_unique($words));
        return $this->protectedwords;
    }

    /**
     * Whether a normalized word is protected from name anonymization.
     *
     * @param string $normalized A name already passed through {@see self::normalize_name()}.
     * @return bool
     */
    private function is_protected_word(string $normalized): bool {
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, $this->get_protected_words(), true);
    }

    /**
     * Return current privacy mode.
     *
     * @return string
     */
    public function get_mode(): string {
        $mode = (string)(get_config('bookingextension_agent', 'aiprivacymode') ?: self::MODE_OFF);
        if (!in_array($mode, [self::MODE_OFF, self::MODE_SOFT, self::MODE_STRICT], true)) {
            return self::MODE_OFF;
        }
        return $mode;
    }

    /**
     * Return true if the given string value looks like an ANON token.
     *
     * Skills call this static helper to skip semantic validation on values that
     * are anonymized placeholders.  No infrastructure is required — the check
     * is a pure string test.
     *
     * @param  string $value
     * @return bool
     */
    public static function looks_like_anon_token(string $value): bool {
        return (bool)preg_match(self::ANON_TOKEN_FIND_PATTERN, $value);
    }

    /**
     * Whether strict pre-LLM anonymization of user input is required.
     *
     * @return bool
     */
    public function should_anonymize_user_input(): bool {
        return $this->get_mode() === self::MODE_STRICT;
    }

    /**
     * Whether backend data sent to the LLM must be anonymized.
     *
     * @return bool
     */
    public function should_anonymize_llm_backend_data(): bool {
        return $this->get_mode() !== self::MODE_OFF;
    }

    /**
     * Precheck and anonymize user text before it is persisted/sent to LLM.
     *
     * @param int $threadid
     * @param string $message
     * @return array
     */
    public function precheck_user_message(int $threadid, string $message): array {
        $start = microtime(true);
        $sanitized = self::valid_utf8($message);
        $emailcount = 0;
        $namecount = 0;

        $mode = $this->get_mode();
        if ($mode === self::MODE_OFF) {
            return [
                'sanitizedmessage' => $message,
                'anonymizedcount' => 0,
                'anonymizedemails' => 0,
                'anonymizednames' => 0,
                'elapsedms' => (int)round((microtime(true) - $start) * 1000),
                'blocked' => false,
            ];
        }

        $tokenmap = $this->get_token_map($threadid);

        // In privacy mode, names must never be sent to the LLM in clear text.
        if ($this->should_anonymize_user_input()) {
            [$sanitized, $emailcount] = $this->anonymize_emails($sanitized, $tokenmap);
        }
        [$sanitized, $namecount] = $this->anonymize_names($sanitized, $tokenmap);

        $this->set_token_map($threadid, $tokenmap);

        return [
            'sanitizedmessage' => $sanitized,
            'anonymizedcount' => $emailcount + $namecount,
            'anonymizedemails' => $emailcount,
            'anonymizednames' => $namecount,
            'elapsedms' => (int)round((microtime(true) - $start) * 1000),
            'blocked' => false,
        ];
    }

    /**
     * Replace ANON_USER tokens in command input recursively with original values.
     *
     * @param int $threadid
     * @param array $input
     * @return array
     */
    public function deanonymize_command_input(int $threadid, array $input): array {
        if ($this->get_mode() === self::MODE_OFF) {
            return $input;
        }

        $tokenmap = $this->get_token_map($threadid);
        if (empty($tokenmap['entries']) || !is_array($tokenmap['entries'])) {
            return $input;
        }

        return $this->deanonymize_recursive($input, $tokenmap['entries'], '');
    }

    /**
     * Whether any string in the (already de-anonymized) value still contains an ANON_USER token.
     *
     * After deanonymize_command_input() a remaining placeholder means it could not be resolved to a
     * real value (e.g. it was minted in another thread/turn). Callers use this to fail closed rather
     * than execute a skill with a meaningless placeholder string as a parameter.
     *
     * @param mixed $value string or nested array
     * @return bool
     */
    public function has_unresolved_anon_tokens($value): bool {
        if (is_string($value)) {
            return self::looks_like_anon_token($value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->has_unresolved_anon_tokens($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * De-mask assistant text for user display only (no persistence side effects).
     *
     * @param int $threadid
     * @param string $message
     * @return array
     */
    public function deanonymize_message_for_display(int $threadid, string $message): array {
        if ($message === '' || $this->get_mode() === self::MODE_OFF) {
            return [
                'message' => $message,
                'replacedcount' => 0,
                'redactedcount' => 0,
            ];
        }

        $tokenmap = $this->get_token_map($threadid);
        $entries = $tokenmap['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        $message = self::valid_utf8($message);

        $replacedcount = 0;
        $redactedcount = 0;
        // Fail closed: every ANON_USER token must resolve to its original, or - when the current
        // thread's map has no entry for it (e.g. a placeholder surfaced from another thread via
        // recall_memory) - be replaced by a neutral label. A raw placeholder must never reach the user.
        $displaymessage = preg_replace_callback(
            self::ANON_TOKEN_FIND_PATTERN,
            function (array $m) use ($entries, &$replacedcount, &$redactedcount): string {
                $token = (string)$m[0];
                $entry = $this->resolve_token_entry($entries, $token);
                if (!is_array($entry)) {
                    $redactedcount++;
                    return get_string('ai_privacy_redacted_user', 'bookingextension_agent');
                }

                $replacedcount++;
                $original = (string)($entry['original'] ?? '');
                $value = (string)($entry['value'] ?? '');
                $replacement = $original !== '' ? $original : ($value !== '' ? $value : $token);
                $matchtype = (string)($entry['type'] ?? '');
                if (in_array($matchtype, ['firstname', 'lastname', 'name', 'both', 'email'], true)) {
                    return $replacement . ' ' . self::DEMASK_MARKER;
                }

                return $replacement;
            },
            $message
        );

        // For full names split across multiple anonymized tokens, keep only one trailing marker.
        $displaymessage = preg_replace(
            '/\s+' . self::DEMASK_MARKER . '(?=\s+\p{Lu}[\p{L}\p{M}\-]+\s+' . self::DEMASK_MARKER . ')/u',
            '',
            (string)$displaymessage
        );

        return [
            'message' => (string)$displaymessage,
            'replacedcount' => $replacedcount,
            'redactedcount' => $redactedcount,
        ];
    }

    /**
     * Recursively anonymize arbitrary payload data before it is sent to the LLM.
     *
     * @param int $threadid
     * @param mixed $value
     * @return mixed
     */
    public function anonymize_value_for_llm(int $threadid, $value) {
        if (!$this->should_anonymize_llm_backend_data()) {
            return $value;
        }

        $tokenmap = $this->get_token_map($threadid);
        $sanitized = $this->anonymize_value_recursive($value, $tokenmap);
        $this->set_token_map($threadid, $tokenmap);

        return $sanitized;
    }

    /**
     * Mask a text or payload before it is STORED as conversation history (LLM input of later turns).
     *
     * Values already known to the thread's token map are replaced by their tokens (the display
     * resolves exactly these originals), and high-confidence identities not yet mapped - full names,
     * e-mails, labelled or structured person fields - are still masked. The single-word name fallback
     * does NOT run here: stored texts are engine or planner prose, and minting tokens for ordinary
     * words that happen to be site first or last names made the history unreadable (Lauf 8, F60,
     * thread 1555: "Note: this will be carried out"). The LLM-bound path keeps the full detection.
     *
     * @param int $threadid
     * @param mixed $value
     * @return mixed
     */
    public function anonymize_value_for_storage(int $threadid, $value) {
        if (!$this->should_anonymize_llm_backend_data()) {
            return $value;
        }

        $tokenmap = $this->get_token_map($threadid);
        $previous = $this->storagemode;
        $this->storagemode = true;
        try {
            $sanitized = $this->anonymize_value_recursive($value, $tokenmap);
        } finally {
            $this->storagemode = $previous;
        }
        $this->set_token_map($threadid, $tokenmap);

        return $sanitized;
    }

    /**
     * Replace every original already known to the token map by its token (storage mode only).
     *
     * @param string $message
     * @param array $tokenmap
     * @return string
     */
    private function replace_known_map_values(string $message, array $tokenmap): string {
        $entries = is_array($tokenmap['entries'] ?? null) ? (array)$tokenmap['entries'] : [];
        $needles = [];
        foreach ($entries as $token => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (['original', 'value'] as $key) {
                $candidate = trim((string)($entry[$key] ?? ''));
                if (core_text::strlen($candidate) < 2 || self::looks_like_anon_token($candidate)) {
                    continue;
                }
                if (!isset($needles[$candidate])) {
                    $needles[$candidate] = (string)$token;
                }
            }
        }
        if (empty($needles)) {
            return $message;
        }
        uksort($needles, static fn($a, $b) => core_text::strlen($b) <=> core_text::strlen($a));
        foreach ($needles as $candidate => $token) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($candidate, '/') . '(?![\p{L}\p{N}_])(?:\s*'
                . preg_quote(self::DEMASK_MARKER, '/') . ')?/iu';
            if (!preg_match($pattern, $message)) {
                continue;
            }
            // L45 UA-3: a known name inside a URL, host name or address stays; an address itself is replaced whole.
            $isaddress = strpos($candidate, '@') !== false;
            $spans = $isaddress ? [] : $this->find_protected_spans($message);
            $replaced = preg_replace_callback(
                $pattern,
                function (array $m) use ($message, $spans, $isaddress, $candidate, $token): string {
                    $start = (int)$m[0][1];
                    if (
                        !$isaddress
                        && (
                            $this->offset_overlaps_protected_span($start, $spans)
                            || !$this->stands_alone($message, $start, $start + strlen($candidate))
                        )
                    ) {
                        return (string)$m[0][0];
                    }
                    return $token;
                },
                $message,
                -1,
                $count,
                PREG_OFFSET_CAPTURE
            );
            if (is_string($replaced)) {
                $message = $replaced;
            }
        }

        return $message;
    }

    /**
     * Re-anchor ANON_USER tokens that were minted in another thread into the current thread's map.
     *
     * Recalled memory (recall_memory) surfaces content that was persisted in anonymized form under
     * a different thread's token map, so its placeholders (e.g. ANON_USER_3_firstname) have no entry
     * in the current thread and would otherwise leak verbatim. For each token we look up the SOURCE
     * thread's entry and re-mint an equivalent token in the TARGET (current) thread's map via the
     * shared {@see self::get_or_create_token()} (deduplicated by the person-stable identitykey, so
     * the same person merges and distinct persons are renumbered). The original/value are written
     * only into the target map entry (server-side, for later display de-anonymization) - they are
     * never expanded into the returned text, so no clear-text PII reaches the LLM.
     *
     * @param int $targetthreadid current thread whose map should gain the entries
     * @param int $sourcethreadid thread the recalled text was originally anonymized under
     * @param mixed $value string or nested array carrying recalled (anonymized) content
     * @return mixed value with tokens rewritten to current-thread tokens
     */
    public function reanchor_value_for_thread(int $targetthreadid, int $sourcethreadid, $value) {
        if ($this->get_mode() === self::MODE_OFF) {
            return $value;
        }
        if ($targetthreadid <= 0 || $sourcethreadid <= 0 || $targetthreadid === $sourcethreadid) {
            return $value;
        }

        $sourcemap = $this->get_token_map($sourcethreadid);
        $sourceentries = $sourcemap['entries'] ?? [];
        if (!is_array($sourceentries) || empty($sourceentries)) {
            return $value;
        }

        $targetmap = $this->get_token_map($targetthreadid);
        $touched = false;
        $result = $this->reanchor_recursive($value, $sourceentries, $targetmap, $touched);
        if ($touched) {
            $this->set_token_map($targetthreadid, $targetmap);
        }

        return $result;
    }

    /**
     * Recursively rewrite source-thread tokens to target-thread tokens in a string or nested array.
     *
     * @param mixed $value
     * @param array $sourceentries entries of the source thread's token map
     * @param array $targetmap target thread token map (mutated in place via get_or_create_token)
     * @param bool $touched set true when at least one token was re-anchored
     * @return mixed
     */
    private function reanchor_recursive($value, array $sourceentries, array &$targetmap, bool &$touched) {
        if (is_string($value)) {
            if ($value === '' || strpos($value, 'ANON_USER_') === false) {
                return $value;
            }
            return preg_replace_callback(
                self::ANON_TOKEN_FIND_PATTERN,
                function (array $m) use ($sourceentries, &$targetmap, &$touched): string {
                    $token = (string)$m[0];
                    $entry = $this->resolve_token_entry($sourceentries, $token);
                    if (!is_array($entry)) {
                        // Unknown in the source map: leave it; the display gate redacts it fail-closed.
                        return $token;
                    }
                    $touched = true;
                    return $this->get_or_create_token(
                        $targetmap,
                        (string)($entry['identitykey'] ?? ''),
                        (string)($entry['type'] ?? ''),
                        (string)($entry['value'] ?? ''),
                        (string)($entry['original'] ?? ''),
                        (array)($entry['variants'] ?? [])
                    );
                },
                $value
            );
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->reanchor_recursive($item, $sourceentries, $targetmap, $touched);
        }

        return $value;
    }

    /**
     * Resolve every token inside a display payload, however deeply nested.
     *
     * deanonymize_message_for_display() only takes a string, so payloads that reach the user as structure —
     * ambiguity options (rendered as buttons and pasted back into the input box) and preview rows — used to
     * carry raw tokens to the client. Same contract as the scalar resolver: fail closed, an unresolvable
     * token becomes the neutral label rather than travelling on.
     *
     * @param int $threadid
     * @param mixed $value Any display payload: string, list or map.
     * @return mixed Same shape, tokens resolved.
     */
    public function deanonymize_value_for_display(int $threadid, $value) {
        if ($this->get_mode() === self::MODE_OFF) {
            return $value;
        }

        return $this->deanonymize_display_recursive($threadid, $value);
    }

    /**
     * Walk a display payload and resolve strings through the scalar display resolver.
     *
     * @param int $threadid
     * @param mixed $value
     * @return mixed
     */
    private function deanonymize_display_recursive(int $threadid, $value) {
        if (is_string($value)) {
            $resolved = $this->deanonymize_message_for_display($threadid, $value);
            return (string)($resolved['message'] ?? $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->deanonymize_display_recursive($threadid, $item);
        }

        return $value;
    }

    /**
     * Recursively de-anonymize all string values in input payload.
     *
     * @param mixed $value
     * @param array $entries
     * @param string $fieldkey
     * @return mixed
     */
    private function deanonymize_recursive($value, array $entries, string $fieldkey) {
        if (is_string($value)) {
            return preg_replace_callback(self::ANON_TOKEN_FIND_PATTERN, function (array $m) use ($entries, $fieldkey): string {
                $token = $m[0];
                $entry = $this->resolve_token_entry($entries, $token);
                if (!is_array($entry)) {
                    return $token;
                }
                return $this->resolve_entry_for_field($entry, $fieldkey, $token);
            }, $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $childfield = is_string($key) ? $key : $fieldkey;
            $value[$key] = $this->deanonymize_recursive($item, $entries, $childfield);
        }

        return $value;
    }

    /**
     * Resolve a token entry for exact and base-token variants.
     *
     * Planner output can contain ANON_USER_<n> while the token map contains only
     * ANON_USER_<n>_firstname / _lastname / _email / _both variants.
     *
     * @param array $entries
     * @param string $token
     * @return array|null
     */
    private function resolve_token_entry(array $entries, string $token): ?array {
        $entry = $entries[$token] ?? null;
        if (is_array($entry)) {
            return $entry;
        }

        $base = $this->extract_base_token_from_anon_token($token);
        if ($base === '') {
            return null;
        }

        // The email-shaped key first, then the suffix variants ("_email" stays resolvable for
        // token maps persisted before the email-shaped form existed).
        $candidates = [$base . '@' . self::ANON_EMAIL_DOMAIN];
        foreach (['both', 'email', 'firstname', 'lastname'] as $suffix) {
            $candidates[] = $base . '_' . $suffix;
        }
        foreach ($candidates as $candidate) {
            $entry = $entries[$candidate] ?? null;
            if (is_array($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Recursively anonymize string values in an arbitrary payload.
     *
     * @param mixed $value
     * @param array $tokenmap
     * @param string $fieldkey
     * @return mixed
     */
    private function anonymize_value_recursive($value, array &$tokenmap, string $fieldkey = '') {
        if (is_string($value)) {
            return $this->anonymize_string_for_llm($value, $tokenmap, $fieldkey);
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($this->array_contains_person_identity_fields($value)) {
            $value = $this->anonymize_person_identity_field_group($value, $tokenmap);
        }

        foreach ($value as $key => $item) {
            $childfield = is_string($key) ? $key : $fieldkey;
            $value[$key] = $this->anonymize_value_recursive($item, $tokenmap, $childfield);
        }

        return $value;
    }

    /**
     * Drop invalid UTF-8 bytes, keep everything else (fix_utf8() returns false on a cut-off trailing sequence).
     *
     * @param string $text
     * @return string
     */
    private static function valid_utf8(string $text): string {
        if ($text === '' || preg_match('//u', $text) === 1) {
            return $text;
        }
        $subst = mb_substitute_character();
        mb_substitute_character('none');
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($subst);
        return is_string($clean) ? $clean : '';
    }

    /**
     * Anonymize a free-form string for backend LLM use.
     *
     * @param string $message
     * @param array $tokenmap
     * @param string $fieldkey
     * @return string
     */
    private function anonymize_string_for_llm(string $message, array &$tokenmap, string $fieldkey = ''): string {
        if ($message === '') {
            return $message;
        }
        // A provider that cuts its output mid-character delivers invalid UTF-8; the /u regexes below
        // would return null and the next call would fail with a TypeError (thread 2095).
        $message = self::valid_utf8($message);

        $normalizedfield = core_text::strtolower(trim($fieldkey));

        if (in_array($normalizedfield, self::PERSON_IDENTITY_FIELDS, true)) {
            $direct = $this->anonymize_person_field_value($normalizedfield, $message, $tokenmap);
            if ($direct !== null) {
                return $direct;
            }
        }

        if ($this->storagemode) {
            $message = $this->replace_known_map_values($message, $tokenmap);
        }

        // Field-labeled summaries (firstname=..., lastname=..., email=...) must keep
        // each identity field separate, otherwise one token can collapse all values.
        [$message] = $this->anonymize_labeled_user_fields($message, $tokenmap);

        [$message] = $this->anonymize_emails($message, $tokenmap);
        [$message] = $this->anonymize_names($message, $tokenmap);

        return $message;
    }

    /**
     * Anonymize labeled user fields in text while preserving field semantics.
     *
     * Example pattern: firstname=Max, lastname=Mustermann, email=max[at]example.com
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_labeled_user_fields(string $message, array &$tokenmap): array {
        $count = 0;
        $sanitized = $message;

        $sanitized = preg_replace_callback(
            '/\b(firstname|lastname)\s*=\s*([^,|\.\n]+)/iu',
            function (array $match) use (&$tokenmap, &$count): string {
                $field = core_text::strtolower(trim((string)($match[1] ?? '')));
                $rawvalue = trim((string)($match[2] ?? ''));
                if ($rawvalue === '' || $rawvalue === '-' || self::looks_like_anon_token($rawvalue)) {
                    return (string)$match[0];
                }

                $token = $this->anonymize_person_field_value($field, $rawvalue, $tokenmap);
                if ($token === null) {
                    return (string)$match[0];
                }

                $count++;
                return $field . '=' . $token;
            },
            $sanitized
        );

        $sanitized = preg_replace_callback(
            '/\b(email)\s*=\s*(' . self::EMAIL_SUBPATTERN . ')/iu',
            function (array $match) use (&$tokenmap, &$count): string {
                $field = 'email';
                $rawvalue = trim((string)($match[2] ?? ''));
                if ($rawvalue === '' || self::looks_like_anon_token($rawvalue)) {
                    return (string)$match[0];
                }

                $token = $this->anonymize_person_field_value($field, $rawvalue, $tokenmap);
                if ($token === null) {
                    return (string)$match[0];
                }

                $count++;
                return $field . '=' . $token;
            },
            $sanitized
        );

        return [(string)$sanitized, $count];
    }

    /**
     * Anonymize one identity field value with field-specific token semantics.
     *
     * @param string $field firstname|lastname|email
     * @param string $value
     * @param array $tokenmap
     * @return string|null
     */
    private function anonymize_person_field_value(string $field, string $value, array &$tokenmap): ?string {
        $normalizedfield = core_text::strtolower(trim($field));
        $trimmedvalue = trim($value);
        if ($trimmedvalue === '') {
            return null;
        }
        if (self::looks_like_anon_token($trimmedvalue)) {
            return $trimmedvalue;
        }

        if ($normalizedfield === 'email') {
            $identity = $this->resolve_identity_from_email($trimmedvalue);
            return $this->get_or_create_token(
                $tokenmap,
                (string)($identity['identitykey'] ?? ('email:' . core_text::strtolower($trimmedvalue))),
                'email',
                $trimmedvalue,
                $trimmedvalue,
                (array)($identity['variants'] ?? ['email' => $trimmedvalue])
            );
        }

        if (!in_array($normalizedfield, ['firstname', 'lastname'], true)) {
            return null;
        }

        $normalizedname = $this->normalize_name($trimmedvalue);
        if ($normalizedname === '' || $this->is_protected_word($normalizedname)) {
            return null;
        }

        $matchindex = $this->get_user_name_match_index();
        $candidateuserids = [];
        if ($normalizedfield === 'firstname') {
            $candidateuserids = array_keys((array)(($matchindex['firstusers'] ?? [])[$normalizedname] ?? []));
        } else {
            $candidateuserids = array_keys((array)(($matchindex['lastusers'] ?? [])[$normalizedname] ?? []));
        }

        $identity = $this->resolve_identity_from_user_ids($candidateuserids, [$normalizedfield => $trimmedvalue]);

        return $this->get_or_create_token(
            $tokenmap,
            (string)($identity['identitykey'] ?? ($normalizedfield . ':' . $normalizedname)),
            $normalizedfield,
            $trimmedvalue,
            $trimmedvalue,
            (array)($identity['variants'] ?? [$normalizedfield => $trimmedvalue])
        );
    }

    /**
     * Replace email-like values with ANON tokens.
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_emails(string $message, array &$tokenmap): array {
        $count = 0;
        $sanitized = preg_replace_callback(
            '/\b' . self::EMAIL_SUBPATTERN . '\b/i',
            function (array $match) use (&$tokenmap, &$count): string {
                $email = (string)$match[0];
                // An email-shaped ANON token is our own mask — re-tokenizing it on a second pass
                // (history, backend data) would corrupt the map. Leave it untouched.
                if (self::looks_like_anon_token($email)) {
                    return $email;
                }
                $identity = $this->resolve_identity_from_email($email);
                $token = $this->get_or_create_token(
                    $tokenmap,
                    (string)($identity['identitykey'] ?? ('email:' . core_text::strtolower($email))),
                    'email',
                    $email,
                    $email,
                    (array)($identity['variants'] ?? ['email' => $email])
                );
                $count++;
                return $token;
            },
            $message
        );

        return [(string)$sanitized, $count];
    }

    /**
     * Replace distinct known first/last names with ANON tokens.
     *
     * Recognizes firstname-lastname pairs as single entities to avoid creating
     * multiple tokens for a single person reference.
     *
     * @param string $message
     * @param array $tokenmap
     * @return array{0:string,1:int}
     */
    private function anonymize_names(string $message, array &$tokenmap): array {
        $matchindex = $this->get_user_name_match_index();
        $nameindex = is_array($matchindex['types'] ?? null) ? (array)$matchindex['types'] : [];
        $firstusers = is_array($matchindex['firstusers'] ?? null) ? (array)$matchindex['firstusers'] : [];
        $lastusers = is_array($matchindex['lastusers'] ?? null) ? (array)$matchindex['lastusers'] : [];
        $fullusers = is_array($matchindex['fullusers'] ?? null) ? (array)$matchindex['fullusers'] : [];
        // Protected spans: never name-anonymize inside emails or namespaced code
        // tokens (skill names / trigger ids like "wizard.forget"). Thread 288: a test
        // user with lastname "forget" turned the skill name wizard.forget into
        // "core.ANON_USER_n_lastname" in prompts/history, so the planner emitted a
        // non-registered skill. Standalone prose occurrences of such names stay
        // anonymizable — only the code-token span is exempt.
        $protectedspans = $this->find_protected_spans($message);

        if (empty($nameindex)) {
            return [$message, 0];
        }

        $wordmatches = [];
        preg_match_all('/\b[\p{L}][\p{L}\p{M}\-]{2,}\b/u', $message, $wordmatches, PREG_OFFSET_CAPTURE);
        $words = $wordmatches[0] ?? [];
        if (empty($words)) {
            return [$message, 0];
        }

        $count = 0;
        $replaceword = [];
        $skipword = [];

        // Pass 1: full-name check always first.
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (!empty($skipword[$i]) || !empty($skipword[$i + 1])) {
                continue;
            }

            $firsttoken = (string)$words[$i][0];
            $lasttoken = (string)$words[$i + 1][0];
            $firststart = (int)$words[$i][1];
            $secondstart = (int)$words[$i + 1][1];
            if (
                $this->offset_overlaps_protected_span($firststart, $protectedspans)
                || $this->offset_overlaps_protected_span($secondstart, $protectedspans)
                || !$this->stands_alone($message, $firststart, $secondstart + strlen((string)$words[$i + 1][0]))
            ) {
                continue;
            }

            $firstnorm = $this->normalize_name($firsttoken);
            $lastnorm = $this->normalize_name($lasttoken);
            if (
                $firstnorm === '' || $lastnorm === ''
                || $this->is_protected_word($firstnorm)
                || $this->is_protected_word($lastnorm)
            ) {
                continue;
            }

            $firstend = $firststart + strlen($firsttoken);
            $between = substr($message, $firstend, $secondstart - $firstend);
            if (!preg_match('/^\s+$/u', (string)$between)) {
                continue;
            }

            $fullkey = $firstnorm . ' ' . $lastnorm;
            $fullmatchusers = $fullusers[$fullkey] ?? [];
            if (is_array($fullmatchusers) && !empty($fullmatchusers)) {
                $fullname = $firsttoken . $between . $lasttoken;
                $identity = $this->resolve_identity_from_user_ids(array_keys($fullmatchusers), [
                    'both' => $fullname,
                    'firstname' => $firsttoken,
                    'lastname' => $lasttoken,
                ]);
                $replaceword[$i] = $this->get_or_create_token(
                    $tokenmap,
                    (string)($identity['identitykey'] ?? ('name:' . $fullkey)),
                    'both',
                    $fullname,
                    $fullname,
                    (array)($identity['variants'] ?? [
                        'both' => $fullname,
                        'firstname' => $firsttoken,
                        'lastname' => $lasttoken,
                    ]),
                    'fullname'
                );
                $replaceword[$i + 1] = '';
                $skipword[$i + 1] = true;
                $count++;
                continue;
            }

            // Only allow split firstname/lastname masking if they cannot belong to the same user.
            $firstids = is_array($firstusers[$firstnorm] ?? null) ? (array)$firstusers[$firstnorm] : [];
            $lastids = is_array($lastusers[$lastnorm] ?? null) ? (array)$lastusers[$lastnorm] : [];
            if ($this->user_sets_intersect($firstids, $lastids)) {
                $skipword[$i] = true;
                $skipword[$i + 1] = true;
            }
        }

        // Pass 2: single-token fallback only where pass 1 found no valid full-name pair.
        // Not in storage mode (see anonymize_value_for_storage()): known words were re-masked from
        // the token map already, new single-word tokens are never minted for stored prose.
        foreach ($words as $idx => $entry) {
            if ($this->storagemode) {
                break;
            }
            if (array_key_exists($idx, $replaceword) || !empty($skipword[$idx])) {
                continue;
            }

            $tokenvalue = (string)$entry[0];
            $tokenstart = (int)$entry[1];
            if (
                $this->offset_overlaps_protected_span($tokenstart, $protectedspans)
                || !$this->stands_alone($message, $tokenstart, $tokenstart + strlen($tokenvalue))
            ) {
                continue;
            }

            $normalized = $this->normalize_name($tokenvalue);
            if ($normalized === '' || $this->is_protected_word($normalized)) {
                continue;
            }
            // Ticket #2226: a stored per-user "ordinary word" decision ends single-word masking
            // for that word — the user already told us this standalone word is not a person.
            if ($this->get_anon_word_decision($this->acting_userid(), $tokenvalue) === 'word') {
                continue;
            }

            $matchtype = (string)($nameindex[$normalized] ?? '');
            if ($matchtype === '') {
                continue;
            }
            if ($matchtype === 'both') {
                $matchtype = 'firstname';
            }

            $candidateuserids = [];
            if ($matchtype === 'firstname') {
                $candidateuserids = array_keys((array)($firstusers[$normalized] ?? []));
            } else if ($matchtype === 'lastname') {
                $candidateuserids = array_keys((array)($lastusers[$normalized] ?? []));
            }
            $identity = $this->resolve_identity_from_user_ids($candidateuserids, [
                $matchtype => $tokenvalue,
            ]);
            $replaceword[$idx] = $this->get_or_create_token(
                $tokenmap,
                (string)($identity['identitykey'] ?? ($matchtype . ':' . $normalized)),
                $matchtype,
                $tokenvalue,
                $tokenvalue,
                (array)($identity['variants'] ?? [$matchtype => $tokenvalue]),
                'single_' . $matchtype
            );
            $count++;
        }

        $sanitized = '';
        $cursor = 0;
        foreach ($words as $idx => $entry) {
            $tokenvalue = (string)$entry[0];
            $start = (int)$entry[1];
            $end = $start + strlen($tokenvalue);
            $sanitized .= substr($message, $cursor, $start - $cursor);
            if (array_key_exists($idx, $replaceword)) {
                $sanitized .= (string)$replaceword[$idx];
            } else {
                $sanitized .= $tokenvalue;
            }
            $cursor = $end;
        }
        $sanitized .= substr($message, $cursor);

        return [$sanitized, $count];
    }

    /**
     * Every span in which a name is never masked, ordered and merged: email addresses, URLs (scheme and host - the path
     * and the query stay subject to the standalone rule), bare host and file names, and code tokens.
     *
     * One source for the LLM path and the storage path, so their protection cannot drift apart (L45 UA-3).
     *
     * @param string $message
     * @return array[]
     */
    private function find_protected_spans(string $message): array {
        return $this->merge_protected_spans(array_merge(
            $this->find_email_spans($message),
            $this->find_url_host_spans($message),
            $this->find_code_token_spans($message)
        ));
    }

    /**
     * Find byte-offset spans of URL hosts and bare host or file names.
     *
     * A URL is protected from its start to the end of its host ("https://www.example.org", "www.example.org"); a bare
     * name with at least one dot and a letter-only last label ("example.org", "bericht.org.pdf") as a whole.
     *
     * @param string $message
     * @return array[]
     */
    private function find_url_host_spans(string $message): array {
        $spans = [];
        $patterns = [
            '~(?:\b[a-z][a-z0-9+.\-]*://|\bwww\.)[^\s/?#<>"\'\x{00AB}\x{00BB}]+~iu',
            '~(?<![\p{L}\p{N}@_\-.])(?:[\p{L}\p{N}](?:[\p{L}\p{N}\-]*[\p{L}\p{N}])?\.)+\p{L}{2,}(?![\p{L}\p{N}\-])~u',
        ];
        foreach ($patterns as $pattern) {
            $matches = [];
            preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE);
            foreach ((array)($matches[0] ?? []) as $match) {
                $spans[] = ['start' => (int)$match[1], 'end' => (int)$match[1] + strlen((string)$match[0])];
            }
        }
        return $spans;
    }

    /**
     * Whether the text between two byte offsets stands alone as a word (see NAME_START_PATTERN, NAME_END_PATTERN).
     *
     * @param string $message
     * @param int $start Byte offset of the first character.
     * @param int $end Byte offset after the last character.
     * @return bool
     */
    private function stands_alone(string $message, int $start, int $end): bool {
        return preg_match(self::NAME_START_PATTERN, $message, $unused, 0, $start) === 1
            && preg_match(self::NAME_END_PATTERN, $message, $unused, 0, $end) === 1;
    }

    /**
     * Find byte-offset spans of email addresses in message text.
     *
     * @param string $message
     * @return array[]
     */
    private function find_email_spans(string $message): array {
        $spans = [];
        $matches = [];
        preg_match_all(
            '/\b' . self::EMAIL_SUBPATTERN . '\b/i',
            $message,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ((array)($matches[0] ?? []) as $match) {
            if (!is_array($match) || count($match) < 2) {
                continue;
            }

            $email = (string)$match[0];
            $start = (int)$match[1];
            $spans[] = [
                'start' => $start,
                'end' => $start + strlen($email),
            ];
        }

        return $spans;
    }

    /**
     * Find byte-offset spans of namespaced code tokens (skill names, trigger ids).
     *
     * Matches `<namespace>.<identifier>` such as "wizard.forget",
     * "mod_booking.book_users" or "wizard.remember_request" — lowercase identifiers
     * joined by a dot, exactly the naming contract enforced for skills — plus
     * JSON object keys (`"identifier":`) inside serialized command/observation
     * payloads. Words inside these spans must never be treated as person names:
     * replacing them corrupts commands, catalogs and history and makes the planner
     * emit non-registered skill names (thread 288). Emails are not affected — they
     * are replaced as a whole before name anonymization runs.
     *
     * @param string $message
     * @return array[]
     */
    private function find_code_token_spans(string $message): array {
        $spans = [];

        $patterns = [
            // Namespaced skill names and trigger ids: wizard.forget, wizard.forget_request.
            '/\b[a-z][a-z0-9_]+(?:\.[a-z][a-z0-9_]+)+\b/',
            // JSON object keys in serialized payloads: "forget": true.
            '/"[a-z][a-z0-9_]*"\s*:/',
            // Moodle capability tokens: mod/booking:addoption, moodle/course:manageactivities. Without this
            // a user name colliding with a word inside the token (e.g. "Booking") would corrupt it.
            '/\b[a-z][a-z0-9_]+\/[a-z][a-z0-9_]+:[a-z][a-z0-9_]+\b/',
        ];

        foreach ($patterns as $pattern) {
            $matches = [];
            preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE);
            foreach ((array)($matches[0] ?? []) as $match) {
                if (!is_array($match) || count($match) < 2) {
                    continue;
                }

                $token = (string)$match[0];
                $start = (int)$match[1];
                $spans[] = [
                    'start' => $start,
                    'end' => $start + strlen($token),
                ];
            }
        }

        return $spans;
    }

    /**
     * Return true when offset belongs to a protected span (email or code token).
     *
     * @param int $offset
     * @param array[] $spans
     * @return bool
     */
    private function offset_overlaps_protected_span(int $offset, array $spans): bool {
        // Run-22 finding F68: this is asked once per word, so a linear scan made the cost grow
        // with words x spans - a 853 KB recall observation never finished inside
        // max_execution_time. The list arrives sorted and disjoint from merge_protected_spans(),
        // which makes the answer a binary search.
        $low = 0;
        $high = count($spans) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $span = $spans[$mid];
            $start = (int)($span['start'] ?? 0);
            $end = (int)($span['end'] ?? 0);
            if ($offset < $start) {
                $high = $mid - 1;
            } else if ($offset >= $end) {
                $low = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort protected spans by start and merge the ones that touch or overlap.
     *
     * offset_overlaps_protected_span() relies on the result being ordered and disjoint: only then
     * does a missed offset tell it which half to keep searching. Email and code-token spans are
     * found by separate passes and do overlap - an address may sit inside a serialized payload.
     *
     * @param array[] $spans
     * @return array[] ordered, non-overlapping spans
     */
    private function merge_protected_spans(array $spans): array {
        if (count($spans) < 2) {
            return array_values($spans);
        }

        usort($spans, static function (array $a, array $b): int {
            return ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0));
        });

        $merged = [];
        foreach ($spans as $span) {
            $start = (int)($span['start'] ?? 0);
            $end = (int)($span['end'] ?? 0);
            if ($end <= $start) {
                continue;
            }

            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $end);
                continue;
            }

            $merged[] = ['start' => $start, 'end' => $end];
        }

        return $merged;
    }

    /**
     * Build name matching index with user-id links for full/split name decisions.
     *
     * @return array
     */
    private function get_user_name_match_index(): array {
        global $DB;

        $cache = \cache::make('bookingextension_agent', 'aiprivacynames');
        $cached = $cache->get(self::NAME_MATCH_INDEX_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $types = [];
        $firstusers = [];
        $lastusers = [];
        $fullusers = [];

        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0',
            null,
            '',
            'id,firstname,lastname'
        );

        foreach ($users as $user) {
            $userid = (int)($user->id ?? 0);
            if ($userid <= 0) {
                continue;
            }

            $first = $this->normalize_name((string)($user->firstname ?? ''));
            $last = $this->normalize_name((string)($user->lastname ?? ''));

            if ($first !== '') {
                $types[$first] = (($types[$first] ?? '') === 'lastname') ? 'both' : 'firstname';
                if (!isset($firstusers[$first]) || !is_array($firstusers[$first])) {
                    $firstusers[$first] = [];
                }
                $firstusers[$first][$userid] = true;
            }

            if ($last !== '') {
                $types[$last] = (($types[$last] ?? '') === 'firstname') ? 'both' : 'lastname';
                if (!isset($lastusers[$last]) || !is_array($lastusers[$last])) {
                    $lastusers[$last] = [];
                }
                $lastusers[$last][$userid] = true;
            }

            if ($first !== '' && $last !== '') {
                $fullkey = $first . ' ' . $last;
                if (!isset($fullusers[$fullkey]) || !is_array($fullusers[$fullkey])) {
                    $fullusers[$fullkey] = [];
                }
                $fullusers[$fullkey][$userid] = true;
            }
        }

        $index = [
            'types' => $types,
            'firstusers' => $firstusers,
            'lastusers' => $lastusers,
            'fullusers' => $fullusers,
        ];

        $cache->set(self::NAME_MATCH_INDEX_CACHE_KEY, $index);
        return $index;
    }

    /**
     * Determine whether two user-id maps overlap.
     *
     * @param array $left
     * @param array $right
     * @return bool
     */
    private function user_sets_intersect(array $left, array $right): bool {
        if (empty($left) || empty($right)) {
            return false;
        }

        foreach ($left as $userid => $value) {
            if (isset($right[$userid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a candidate name for index/matching.
     *
     * @param string $name
     * @return string
     */
    private function normalize_name(string $name): string {
        $name = core_text::strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        if (!preg_match('/^[\p{L}][\p{L}\p{M}\-]{2,}$/u', $name)) {
            return '';
        }

        return $name;
    }

    /**
     * Load or initialize the thread token map.
     *
     * @param int $threadid
     * @return array
     */
    /**
     * Token identifiers active in this thread (empty when nothing was anonymized).
     *
     * @param int $threadid
     * @return string[]
     */
    public function get_active_token_names(int $threadid): array {
        return array_values(array_filter(array_map(
            'strval',
            array_keys($this->get_token_map($threadid)['entries'])
        )));
    }

    /**
     * Load token map from thread metadata.
     *
     * @param int $threadid
     * @return array
     */
    private function get_token_map(int $threadid): array {
        $map = $this->store->get_thread_metadata_value($threadid, self::TOKEN_MAP_METADATA_KEY);
        if (!is_array($map)) {
            return ['nextid' => 1, 'entries' => []];
        }

        $nextid = (int)($map['nextid'] ?? 1);
        $entries = $map['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        return [
            'nextid' => max(1, $nextid),
            'entries' => $entries,
        ];
    }

    /**
     * Persist token map on thread metadata.
     *
     * @param int $threadid
     * @param array $map
     * @return void
     */
    private function set_token_map(int $threadid, array $map): void {
        $this->store->set_thread_metadata_value($threadid, self::TOKEN_MAP_METADATA_KEY, $map);
    }

    /**
     * Return existing token for value or create a new token entry.
     *
     * @param array $map
     * @param string $identitykey
     * @param string $type
     * @param string $value
     * @param string $original
     * @param array $variants
     * @param string $matchreason
     * @return string
     */
    private function get_or_create_token(
        array &$map,
        string $identitykey,
        string $type,
        string $value,
        string $original,
        array $variants = [],
        string $matchreason = ''
    ): string {
        // Deterministic confidence (#2226 D0): only the single-word name fallback (pass 2 of
        // anonymize_names) is low confidence — everything carrying more evidence (adjacent full
        // name, explicit person field, labeled field, email shape) is high. Legacy entries
        // without the field are treated as high by all readers.
        $confidence = in_array($matchreason, ['single_firstname', 'single_lastname'], true)
            ? self::CONFIDENCE_LOW
            : self::CONFIDENCE_HIGH;
        $entries = $map['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $scopedidentitykey = $identitykey;
        $requiresfieldsuffixtoken = in_array($type, ['firstname', 'lastname', 'email', 'both'], true);
        $basetoken = '';

        if ($scopedidentitykey !== '') {
            foreach ($entries as $token => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ((string)($entry['identitykey'] ?? '') !== $scopedidentitykey) {
                    continue;
                }

                $basetoken = $this->extract_base_token_from_anon_token((string)$token);
                if ($basetoken !== '') {
                    break;
                }
            }
        }

        if ($requiresfieldsuffixtoken) {
            if ($basetoken === '') {
                $nextid = max(1, (int)($map['nextid'] ?? 1));
                $basetoken = 'ANON_USER_' . $nextid;
                $map['nextid'] = $nextid + 1;
            }

            $targettoken = $this->build_field_token_from_base($basetoken, $type);
            if ($targettoken !== '') {
                $targetentry = is_array($entries[$targettoken] ?? null) ? (array)$entries[$targettoken] : [];
                $entries[$targettoken] = [
                    'identitykey' => $scopedidentitykey,
                    'type' => $type,
                    'value' => $value,
                    'original' => $original,
                    'variants' => $this->merge_identity_variants((array)($targetentry['variants'] ?? []), $variants),
                    'confidence' => $confidence,
                    'matchreason' => $matchreason,
                ];
                $map['entries'] = $entries;
                return $targettoken;
            }
        }

        foreach ($entries as $token => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['identitykey'] ?? '') === $scopedidentitykey && $scopedidentitykey !== '') {
                $entry['type'] = $type;
                $entry['value'] = $value;
                $entry['original'] = $original;
                $entry['variants'] = $this->merge_identity_variants((array)($entry['variants'] ?? []), $variants);
                $entry['confidence'] = $confidence;
                $entry['matchreason'] = $matchreason;
                $entries[$token] = $entry;
                $map['entries'] = $entries;
                return (string)$token;
            }

            if (
                $identitykey === ''
                && (string)($entry['type'] ?? '') === $type
                && (string)($entry['value'] ?? '') === $value
                && (string)($entry['original'] ?? '') === $original
            ) {
                return (string)$token;
            }
        }

        $nextid = max(1, (int)($map['nextid'] ?? 1));
        $token = 'ANON_USER_' . $nextid;
        $entries[$token] = [
            'identitykey' => $scopedidentitykey,
            'type' => $type,
            'value' => $value,
            'original' => $original,
            'variants' => $this->merge_identity_variants([], $variants),
            'confidence' => $confidence,
            'matchreason' => $matchreason,
        ];
        $map['entries'] = $entries;
        $map['nextid'] = $nextid + 1;

        return $token;
    }

    /**
     * Build a field-specific token from a base ANON token.
     *
     * @param string $basetoken
     * @param string $type
     * @return string
     */
    private function build_field_token_from_base(string $basetoken, string $type): string {
        $normalizedtype = core_text::strtolower(trim($type));
        if (!in_array($normalizedtype, ['firstname', 'lastname', 'email', 'both'], true)) {
            return '';
        }

        $normalizedbase = $this->extract_base_token_from_anon_token($basetoken);
        if ($normalizedbase === '') {
            return '';
        }

        // Email identities get an email-SHAPED token so downstream LLMs still treat the value as
        // an address (see ANON_EMAIL_DOMAIN); the other identity fields keep the suffix form.
        if ($normalizedtype === 'email') {
            return $normalizedbase . '@' . self::ANON_EMAIL_DOMAIN;
        }

        return $normalizedbase . '_' . $normalizedtype;
    }

    /**
     * Extract the ANON_USER_<id> base token from any supported token variant.
     *
     * @param string $token
     * @return string
     */
    private function extract_base_token_from_anon_token(string $token): string {
        if (!preg_match(self::ANON_TOKEN_PARSE_PATTERN, $token, $match)) {
            return '';
        }

        return (string)($match[1] ?? '');
    }

    /**
     * Resolve token entry value based on destination field semantics.
     *
     * For SQL text fields (title/description/search query), always use original literal.
     *
     * @param array $entry
     * @param string $fieldkey
     * @param string $fallback
     * @return string
     */
    private function resolve_entry_for_field(array $entry, string $fieldkey, string $fallback): string {
        $original = (string)($entry['original'] ?? '');
        $value = (string)($entry['value'] ?? '');
        $matchtype = (string)($entry['type'] ?? '');
        $variants = is_array($entry['variants'] ?? null) ? (array)$entry['variants'] : [];
        $normalizedfield = core_text::strtolower(trim($fieldkey));

        if ($original === '' && $value === '') {
            return $fallback;
        }

        if (
            in_array($normalizedfield, self::SQL_TEXT_FIELDS, true)
            && in_array($matchtype, ['firstname', 'lastname', 'email'], true)
        ) {
            return $original !== '' ? $original : $value;
        }

        if ($this->is_user_reference_field($normalizedfield)) {
            foreach (['email', 'both', 'firstname', 'lastname'] as $variantkey) {
                $variant = trim((string)($variants[$variantkey] ?? ''));
                if ($variant !== '') {
                    return $variant;
                }
            }
        }

        return $value !== '' ? $value : ($original !== '' ? $original : $fallback);
    }

    /**
     * Resolve a stable identity from an e-mail address when possible.
     *
     * @param string $email
     * @return array
     */
    private function resolve_identity_from_email(string $email): array {
        global $DB;

        $normalizedemail = trim(core_text::strtolower($email));
        if ($normalizedemail === '') {
            return [
                'identitykey' => '',
                'variants' => ['email' => $email],
            ];
        }

        $user = $DB->get_record(
            'user',
            ['email' => $normalizedemail, 'deleted' => 0],
            'id,firstname,lastname,email',
            IGNORE_MISSING
        );
        if (!$user) {
            return [
                'identitykey' => 'email:' . $normalizedemail,
                'variants' => ['email' => $email],
            ];
        }

        return [
            'identitykey' => 'user:' . (int)$user->id,
            'variants' => $this->build_identity_variants_from_user_record($user, ['email' => $email]),
        ];
    }

    /**
     * Resolve a stable identity from a candidate user-id set.
     *
     * If the name fragment is ambiguous, keep a representation-based fallback identity.
     *
     * @param array $candidateuserids
     * @param array $observedvariants
     * @return array
     */
    private function resolve_identity_from_user_ids(array $candidateuserids, array $observedvariants = []): array {
        $candidateuserids = array_values(array_unique(array_map('intval', $candidateuserids)));
        if (count($candidateuserids) === 1 && $candidateuserids[0] > 0) {
            $user = $this->load_user_identity_record($candidateuserids[0]);
            if ($user) {
                return [
                    'identitykey' => 'user:' . $candidateuserids[0],
                    'variants' => $this->build_identity_variants_from_user_record($user, $observedvariants),
                ];
            }
        }

        $fallbackseed = json_encode($observedvariants);
        return [
            'identitykey' => 'literal:' . sha1((string)$fallbackseed),
            'variants' => $observedvariants,
        ];
    }

    /**
     * Load user identity fields for token enrichment.
     *
     * @param int $userid
     * @return object|null
     */
    private function load_user_identity_record(int $userid): ?object {
        global $DB;

        if ($userid <= 0) {
            return null;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email', IGNORE_MISSING);
        return $user ?: null;
    }

    /**
     * Build normalized identity variants from a Moodle user record.
     *
     * @param object $user
     * @param array $observedvariants
     * @return array
     */
    private function build_identity_variants_from_user_record(object $user, array $observedvariants = []): array {
        $variants = [];
        $firstname = trim((string)($user->firstname ?? ''));
        $lastname = trim((string)($user->lastname ?? ''));
        $email = trim((string)($user->email ?? ''));
        $fullname = trim($firstname . ' ' . $lastname);

        if ($firstname !== '') {
            $variants['firstname'] = $firstname;
        }
        if ($lastname !== '') {
            $variants['lastname'] = $lastname;
        }
        if ($fullname !== '') {
            $variants['both'] = $fullname;
        }
        if ($email !== '') {
            $variants['email'] = $email;
        }

        return $this->merge_identity_variants($variants, $observedvariants);
    }

    /**
     * Merge observed variants into the stored variant set without dropping known values.
     *
     * @param array $basevariants
     * @param array $incomingvariants
     * @return array
     */
    private function merge_identity_variants(array $basevariants, array $incomingvariants): array {
        foreach ($incomingvariants as $key => $variant) {
            if (!is_string($key)) {
                continue;
            }
            $variant = trim((string)$variant);
            if ($variant === '') {
                continue;
            }
            $basevariants[$key] = $variant;
        }

        return $basevariants;
    }

    /**
     * Check if array has structured person identity keys.
     *
     * @param array $value
     * @return bool
     */
    private function array_contains_person_identity_fields(array $value): bool {
        foreach (self::PERSON_IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anonymize firstname/lastname/email as one identity group when present in a structured row.
     *
     * @param array $value
     * @param array $tokenmap
     * @return array
     */
    private function anonymize_person_identity_field_group(array $value, array &$tokenmap): array {
        $variants = [];
        foreach (self::PERSON_IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $value) || !is_string($value[$field])) {
                continue;
            }

            $raw = trim((string)$value[$field]);
            if ($raw === '' || self::looks_like_anon_token($raw)) {
                continue;
            }

            // Never tokenize a protected word used as a first/last name (e.g. account "admin user").
            if (
                in_array($field, ['firstname', 'lastname'], true)
                && $this->is_protected_word($this->normalize_name($raw))
            ) {
                continue;
            }

            $variants[$field] = $raw;
        }

        if (empty($variants)) {
            return $value;
        }

        $identitykey = '';
        $userid = (int)($value['userid'] ?? $value['id'] ?? 0);
        if ($userid > 0) {
            $identitykey = 'user:' . $userid;
        } else if (!empty($variants['email'])) {
            $identity = $this->resolve_identity_from_email((string)$variants['email']);
            $identitykey = (string)($identity['identitykey'] ?? '');
        }

        if ($identitykey === '') {
            $seed = json_encode($variants);
            $identitykey = 'literal:' . sha1((string)$seed);
        }

        foreach ($variants as $field => $raw) {
            $value[$field] = $this->get_or_create_token(
                $tokenmap,
                $identitykey,
                (string)$field,
                (string)$raw,
                (string)$raw,
                $variants
            );
        }

        return $value;
    }

    /**
     * Userid whose collision decisions apply to the current run (the acting user).
     *
     * @return int
     */
    private function acting_userid(): int {
        global $USER;
        return (int)($USER->id ?? 0);
    }

    /**
     * Low-confidence (single-word) name tokens of a thread: token => original word (#2226 D0).
     *
     * With a userid, words the user already decided on (person OR ordinary word) are
     * filtered out — a decided word needs no further contract/gate treatment.
     *
     * @param int $threadid
     * @param int $userid 0 = no decision filtering.
     * @return array<string,string>
     */
    public function get_low_confidence_suspects(int $threadid, int $userid = 0): array {
        $map = $this->get_token_map($threadid);
        $suspects = [];
        foreach ((array)($map['entries'] ?? []) as $token => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string)($entry['confidence'] ?? self::CONFIDENCE_HIGH) !== self::CONFIDENCE_LOW) {
                continue;
            }
            $original = (string)($entry['original'] ?? '');
            if ($original === '') {
                continue;
            }
            if ($userid > 0 && $this->get_anon_word_decision($userid, $original) !== '') {
                continue;
            }
            $suspects[(string)$token] = $original;
        }

        return $suspects;
    }

    /**
     * Low-confidence tokens referenced by a raw (still anonymized) command input (#2226 D3).
     *
     * @param int $threadid
     * @param int $userid Decision filtering (see get_low_confidence_suspects()).
     * @param array $input Raw command input BEFORE de-anonymization.
     * @param bool $onlypersonfields Restrict hits to person-reference fields (userquery etc.).
     * @return array<int,array{field:string,token:string,original:string}>
     */
    public function find_low_confidence_token_references(
        int $threadid,
        int $userid,
        array $input,
        bool $onlypersonfields = false
    ): array {
        $suspects = $this->get_low_confidence_suspects($threadid, $userid);
        if (empty($suspects)) {
            return [];
        }

        $hits = [];
        $walker = function ($value, string $fieldkey) use (&$walker, &$hits, $suspects, $onlypersonfields): void {
            if (is_string($value)) {
                $normalizedfield = core_text::strtolower(trim($fieldkey));
                if ($onlypersonfields && !$this->is_user_reference_field($normalizedfield)) {
                    return;
                }
                foreach ($suspects as $token => $original) {
                    if (str_contains($value, (string)$token)) {
                        $hits[] = ['field' => $fieldkey, 'token' => (string)$token, 'original' => $original];
                    }
                }
                return;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walker($item, is_string($key) ? $key : $fieldkey);
                }
            }
        };
        $walker($input, '');

        return $hits;
    }

    /**
     * Record a user's decision for a colliding word: person name or ordinary word (#2226).
     *
     * @param int $userid
     * @param string $word The literal word as the user wrote it.
     * @param string $decision 'person' or 'word'.
     * @return void
     */
    public function record_anon_word_decision(int $userid, string $word, string $decision): void {
        $normalized = $this->normalize_name($word);
        if ($userid <= 0 || $normalized === '' || !in_array($decision, ['person', 'word'], true)) {
            return;
        }

        $decisions = $this->get_anon_word_decisions($userid);
        $decisions[$normalized] = ['decision' => $decision, 'timecreated' => time()];
        if (count($decisions) > self::ANON_WORD_DECISIONS_MAX) {
            uasort($decisions, static fn(array $a, array $b): int =>
                (int)($a['timecreated'] ?? 0) <=> (int)($b['timecreated'] ?? 0));
            $decisions = array_slice($decisions, -self::ANON_WORD_DECISIONS_MAX, null, true);
        }
        set_user_preference(self::ANON_WORD_DECISIONS_PREF, json_encode($decisions), $userid);
    }

    /**
     * Stored decision for a word: 'person', 'word' or '' (undecided) (#2226).
     *
     * @param int $userid
     * @param string $word
     * @return string
     */
    public function get_anon_word_decision(int $userid, string $word): string {
        $normalized = $this->normalize_name($word);
        if ($userid <= 0 || $normalized === '') {
            return '';
        }

        $entry = $this->get_anon_word_decisions($userid)[$normalized] ?? null;
        $decision = is_array($entry) ? (string)($entry['decision'] ?? '') : '';

        return in_array($decision, ['person', 'word'], true) ? $decision : '';
    }

    /**
     * Load the per-user collision decision list from user preferences.
     *
     * @param int $userid
     * @return array<string,array{decision:string,timecreated:int}>
     */
    private function get_anon_word_decisions(int $userid): array {
        $raw = (string)get_user_preferences(self::ANON_WORD_DECISIONS_PREF, '', $userid);
        if ($raw === '') {
            return [];
        }

        // Asked once per word of the message; decoding the same string again each time showed up
        // next to F68 in the same hot loop. Keyed by the raw value, so a decision recorded during
        // the request invalidates the memo by itself.
        if (($this->anonworddecisionsraw[$userid] ?? null) === $raw) {
            return $this->anonworddecisions[$userid];
        }

        $decoded = json_decode($raw, true);
        $this->anonworddecisionsraw[$userid] = $raw;
        $this->anonworddecisions[$userid] = is_array($decoded) ? $decoded : [];

        return $this->anonworddecisions[$userid];
    }

    /**
     * Whether a command input field semantically refers to a person (#2226 D3).
     *
     * Public projection of the internal user-reference-field rule so the preflight
     * gate can reuse the exact same field semantics.
     *
     * @param string $field
     * @return bool
     */
    public function is_person_reference_field(string $field): bool {
        return $this->is_user_reference_field(core_text::strtolower(trim($field)));
    }

    /**
     * Normalized name words of a free-text value, split and normalized exactly like
     * anonymize_names() does when it builds tokens (F23: word-specific person context).
     *
     * @param string $value
     * @return string[]
     */
    public function name_words(string $value): array {
        $matches = [];
        preg_match_all('/\b[\p{L}][\p{L}\p{M}\-]{2,}\b/u', $value, $matches);
        $words = [];
        foreach ((array)($matches[0] ?? []) as $word) {
            $normalized = $this->normalize_name((string)$word);
            if ($normalized !== '') {
                $words[] = $normalized;
            }
        }
        return $words;
    }

    /**
     * Check whether a field semantically refers to a user identity.
     *
     * @param string $normalizedfield
     * @return bool
     */
    private function is_user_reference_field(string $normalizedfield): bool {
        if ($normalizedfield === '') {
            return false;
        }

        if (in_array($normalizedfield, self::USER_REFERENCE_FIELDS, true)) {
            return true;
        }

        return str_ends_with($normalizedfield, 'userquery');
    }
}

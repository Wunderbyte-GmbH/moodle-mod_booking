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

namespace bookingextension_agent\privacy;

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for bookingextension_agent.
 *
 * Covers all user-identifiable agent data: the user-stated memory (stored at the user's own
 * context) and the AI conversation data — threads, messages, execution runs and raw LLM debug
 * logs — which is stored at the Moodle context the agent ran in (module, course, category, user
 * or system). It also declares that user-entered content is transmitted to an external LLM
 * provider.
 *
 * It further covers the site-content rows of the semantic search index ({bx_agent_embeddings},
 * area 'site_content'): with user-content search areas (forum/glossary/wiki) enabled, those rows
 * reference user-authored content (authoring user, title, provenance) and store an embedding
 * vector derived from it — the content text itself is never stored. Docs/skills rows in the same
 * table carry no context and no owner and are not personal data.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** User memory table (stored at user context). */
    private const MEMORY_TABLE = 'bx_agent_user_memory';

    /** Conversation thread table. */
    private const THREADS_TABLE = 'bx_agent_ai_threads';

    /** Conversation message table. */
    private const MESSAGES_TABLE = 'bx_agent_ai_messages';

    /** Execution run table. */
    private const RUNS_TABLE = 'bx_agent_ai_runs';

    /** Raw LLM debug log table. */
    private const DEBUG_TABLE = 'bx_agent_ai_llm_debug';

    /** Embeddings table (shared by docs/skills/site content; only site rows carry user data). */
    private const EMBEDDINGS_TABLE = 'bx_agent_embeddings';

    /** The embeddings area whose rows reference (possibly user-authored) site content. */
    private const SITE_AREA = 'site_content';

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            self::MEMORY_TABLE,
            [
                'userid' => 'privacy:metadata:bx_agent_user_memory:userid',
                'memory' => 'privacy:metadata:bx_agent_user_memory:memory',
                'scopes' => 'privacy:metadata:bx_agent_user_memory:scopes',
                'timecreated' => 'privacy:metadata:bx_agent_user_memory:timecreated',
                'timemodified' => 'privacy:metadata:bx_agent_user_memory:timemodified',
            ],
            'privacy:metadata:bx_agent_user_memory'
        );

        $collection->add_database_table(
            self::THREADS_TABLE,
            [
                'userid' => 'privacy:metadata:bx_agent_ai_threads:userid',
                'contextid' => 'privacy:metadata:bx_agent_ai_threads:contextid',
                'status' => 'privacy:metadata:bx_agent_ai_threads:status',
                'metadatajson' => 'privacy:metadata:bx_agent_ai_threads:metadatajson',
                'timecreated' => 'privacy:metadata:bx_agent_ai_threads:timecreated',
                'timemodified' => 'privacy:metadata:bx_agent_ai_threads:timemodified',
            ],
            'privacy:metadata:bx_agent_ai_threads'
        );

        $collection->add_database_table(
            self::MESSAGES_TABLE,
            [
                'userid' => 'privacy:metadata:bx_agent_ai_messages:userid',
                'role' => 'privacy:metadata:bx_agent_ai_messages:role',
                'content' => 'privacy:metadata:bx_agent_ai_messages:content',
                'structuredjson' => 'privacy:metadata:bx_agent_ai_messages:structuredjson',
                'timecreated' => 'privacy:metadata:bx_agent_ai_messages:timecreated',
            ],
            'privacy:metadata:bx_agent_ai_messages'
        );

        $collection->add_database_table(
            self::RUNS_TABLE,
            [
                'userid' => 'privacy:metadata:bx_agent_ai_runs:userid',
                'contextid' => 'privacy:metadata:bx_agent_ai_runs:contextid',
                'status' => 'privacy:metadata:bx_agent_ai_runs:status',
                'commandsjson' => 'privacy:metadata:bx_agent_ai_runs:commandsjson',
                'resultsjson' => 'privacy:metadata:bx_agent_ai_runs:resultsjson',
                'timecreated' => 'privacy:metadata:bx_agent_ai_runs:timecreated',
                'timemodified' => 'privacy:metadata:bx_agent_ai_runs:timemodified',
            ],
            'privacy:metadata:bx_agent_ai_runs'
        );

        $collection->add_database_table(
            self::DEBUG_TABLE,
            [
                'userid' => 'privacy:metadata:bx_agent_ai_llm_debug:userid',
                'contextid' => 'privacy:metadata:bx_agent_ai_llm_debug:contextid',
                'source' => 'privacy:metadata:bx_agent_ai_llm_debug:source',
                'requesttext' => 'privacy:metadata:bx_agent_ai_llm_debug:requesttext',
                'responsetext' => 'privacy:metadata:bx_agent_ai_llm_debug:responsetext',
                'success' => 'privacy:metadata:bx_agent_ai_llm_debug:success',
                'errormessage' => 'privacy:metadata:bx_agent_ai_llm_debug:errormessage',
                'timecreated' => 'privacy:metadata:bx_agent_ai_llm_debug:timecreated',
            ],
            'privacy:metadata:bx_agent_ai_llm_debug'
        );

        $collection->add_database_table(
            self::EMBEDDINGS_TABLE,
            [
                'owneruserid' => 'privacy:metadata:bx_agent_embeddings:owneruserid',
                'title' => 'privacy:metadata:bx_agent_embeddings:title',
                'docid' => 'privacy:metadata:bx_agent_embeddings:docid',
                'contextid' => 'privacy:metadata:bx_agent_embeddings:contextid',
                'courseid' => 'privacy:metadata:bx_agent_embeddings:courseid',
                'embedding' => 'privacy:metadata:bx_agent_embeddings:embedding',
                'timemodified' => 'privacy:metadata:bx_agent_embeddings:timemodified',
            ],
            'privacy:metadata:bx_agent_embeddings'
        );

        // User-entered content is forwarded to an external LLM provider for processing.
        $collection->add_external_location_link(
            'llm_provider',
            [
                'message' => 'privacy:metadata:llm_provider:message',
            ],
            'privacy:metadata:llm_provider'
        );

        return $collection;
    }

    /**
     * Return the contexts holding personal data for the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // AI conversation data lives at the context the agent ran in. Add each table with its own
        // simple single-table SELECT: the privacy contextlist guesser deliberately bails out on UNION
        // queries (returning no joinable field), so three plain add_from_sql() calls are the robust way.
        foreach ([self::THREADS_TABLE, self::RUNS_TABLE, self::DEBUG_TABLE] as $table) {
            $contextlist->add_from_sql(
                "SELECT contextid FROM {" . $table . "} WHERE userid = :userid AND contextid > 0",
                ['userid' => $userid]
            );
        }

        // Site-content embeddings: rows of user-authored content carry the authoring user and the
        // context the content was indexed from. Docs/skills rows have a NULL context and no owner,
        // so the area + owner guard only ever matches site rows.
        $contextlist->add_from_sql(
            "SELECT contextid FROM {" . self::EMBEDDINGS_TABLE . "}"
                . " WHERE area = :embarea AND owneruserid = :embuserid AND contextid IS NOT NULL",
            ['embarea' => self::SITE_AREA, 'embuserid' => $userid]
        );

        // User-stated memory lives at the user's own context.
        if ($DB->record_exists(self::MEMORY_TABLE, ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Return the users having personal data within the given context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $params = ['contextid' => $context->id];

        $userlist->add_from_sql('userid', "SELECT userid FROM {" . self::THREADS_TABLE . "} WHERE contextid = :contextid", $params);
        $userlist->add_from_sql('userid', "SELECT userid FROM {" . self::RUNS_TABLE . "} WHERE contextid = :contextid", $params);
        $userlist->add_from_sql('userid', "SELECT userid FROM {" . self::DEBUG_TABLE . "} WHERE contextid = :contextid", $params);

        // Site-content embeddings: the authoring users of indexed content in this context
        // (owneruserid = 0 marks content without a personal author, e.g. activity descriptions).
        $userlist->add_from_sql(
            'owneruserid',
            "SELECT owneruserid FROM {" . self::EMBEDDINGS_TABLE . "}"
                . " WHERE area = :embarea AND contextid = :embcontextid AND owneruserid > 0",
            ['embarea' => self::SITE_AREA, 'embcontextid' => $context->id]
        );

        // User-stated memory only exists at the user's own context.
        if (
            $context instanceof context_user
                && $DB->record_exists(self::MEMORY_TABLE, ['userid' => $context->instanceid])
        ) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export the personal data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            // User-stated memory: only at the user's own context.
            if ($context instanceof context_user && (int)$context->instanceid === $userid) {
                self::export_memory($context, $userid);
            }

            // AI conversation data: threads (with their messages, runs and debug logs) in this context.
            self::export_conversations($context, $userid);

            // Site-content embeddings: index entries of content this user authored in this context.
            self::export_site_embeddings($context, $userid);
        }
    }

    /**
     * Export the user-stated memory at the user context.
     *
     * @param context $context
     * @param int $userid
     * @return void
     */
    private static function export_memory(context $context, int $userid): void {
        global $DB;

        $records = $DB->get_records(self::MEMORY_TABLE, ['userid' => $userid], 'timecreated ASC, id ASC');
        if (empty($records)) {
            return;
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = (object)[
                'memory' => (string)$record->memory,
                'scopes' => (string)($record->scopes ?? ''),
                'timecreated' => transform::datetime((int)$record->timecreated),
                'timemodified' => transform::datetime((int)$record->timemodified),
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:metadata:bx_agent_user_memory', 'bookingextension_agent')],
            (object)['memories' => $data]
        );
    }

    /**
     * Export the AI conversation threads (and their messages, runs and debug logs) for a user in a context.
     *
     * @param context $context
     * @param int $userid
     * @return void
     */
    private static function export_conversations(context $context, int $userid): void {
        global $DB;

        $threads = $DB->get_records(
            self::THREADS_TABLE,
            ['userid' => $userid, 'contextid' => $context->id],
            'timecreated ASC, id ASC'
        );
        if (empty($threads)) {
            return;
        }

        $exportthreads = [];
        foreach ($threads as $thread) {
            $threadid = (int)$thread->id;

            $messages = [];
            foreach ($DB->get_records(self::MESSAGES_TABLE, ['threadid' => $threadid], 'timecreated ASC, id ASC') as $msg) {
                $messages[] = (object)[
                    'role' => (string)$msg->role,
                    'content' => (string)($msg->content ?? ''),
                    'structuredjson' => (string)($msg->structuredjson ?? ''),
                    'timecreated' => transform::datetime((int)$msg->timecreated),
                ];
            }

            $runs = [];
            foreach ($DB->get_records(self::RUNS_TABLE, ['threadid' => $threadid], 'timecreated ASC, id ASC') as $run) {
                $runs[] = (object)[
                    'status' => (string)$run->status,
                    'commandsjson' => (string)($run->commandsjson ?? ''),
                    'resultsjson' => (string)($run->resultsjson ?? ''),
                    'timecreated' => transform::datetime((int)$run->timecreated),
                    'timemodified' => transform::datetime((int)$run->timemodified),
                ];
            }

            $debug = [];
            foreach ($DB->get_records(self::DEBUG_TABLE, ['threadid' => $threadid], 'timecreated ASC, id ASC') as $entry) {
                $debug[] = (object)[
                    'source' => (string)$entry->source,
                    'requesttext' => (string)($entry->requesttext ?? ''),
                    'responsetext' => (string)($entry->responsetext ?? ''),
                    'success' => (int)$entry->success,
                    'errormessage' => (string)($entry->errormessage ?? ''),
                    'timecreated' => transform::datetime((int)$entry->timecreated),
                ];
            }

            $exportthreads[] = (object)[
                'status' => (string)$thread->status,
                'timecreated' => transform::datetime((int)$thread->timecreated),
                'timemodified' => transform::datetime((int)$thread->timemodified),
                'messages' => $messages,
                'runs' => $runs,
                'debuglogs' => $debug,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:metadata:bx_agent_ai_threads', 'bookingextension_agent')],
            (object)['threads' => $exportthreads]
        );
    }

    /**
     * Export the site-content embedding index entries authored by a user in a context.
     *
     * Only the human-meaningful reference data is exported (search area, title, chunk number,
     * time); the embedding vector itself is machine-derived, non-reversible numeric data, which a
     * note in the export points out instead of dumping raw floats.
     *
     * @param context $context
     * @param int $userid
     * @return void
     */
    private static function export_site_embeddings(context $context, int $userid): void {
        global $DB;

        $records = $DB->get_records(
            self::EMBEDDINGS_TABLE,
            ['area' => self::SITE_AREA, 'contextid' => $context->id, 'owneruserid' => $userid],
            'owner ASC, refindex ASC, id ASC'
        );
        if (empty($records)) {
            return;
        }

        $entries = [];
        foreach ($records as $record) {
            $entries[] = (object)[
                'searcharea' => (string)$record->owner,
                'title' => (string)($record->title ?? ''),
                'chunknumber' => (int)$record->refindex,
                'timemodified' => transform::datetime((int)$record->timemodified),
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:metadata:bx_agent_embeddings', 'bookingextension_agent')],
            (object)[
                'entries' => $entries,
                'note' => get_string('privacy:metadata:bx_agent_embeddings:embedding', 'bookingextension_agent'),
            ]
        );
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        self::delete_conversations_select($context, '', []);

        // Embedding rows indexed from this context; area-agnostic on purpose — docs/skills rows
        // have a NULL contextid and are never matched (same argument as the store's
        // delete_by_context()).
        $DB->delete_records(self::EMBEDDINGS_TABLE, ['contextid' => $context->id]);

        // User-stated memory only exists at the user's own context.
        if ($context instanceof context_user) {
            $DB->delete_records(self::MEMORY_TABLE, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            self::delete_conversations_select($context, 'userid = :userid', ['userid' => $userid]);

            // Site-content embedding rows authored by this user in this context. The next index
            // run may legitimately re-create them while the source content still exists; deleting
            // the source is the job of the content plugin's own privacy provider.
            $DB->delete_records(self::EMBEDDINGS_TABLE, [
                'area' => self::SITE_AREA,
                'contextid' => $context->id,
                'owneruserid' => $userid,
            ]);

            if ($context instanceof context_user && (int)$context->instanceid === $userid) {
                $DB->delete_records(self::MEMORY_TABLE, ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete data for the approved set of users in the given context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        self::delete_conversations_select($context, "userid {$insql}", $inparams);

        // Site-content embedding rows authored by these users in this context.
        [$embinsql, $embinparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'embuid');
        $DB->delete_records_select(
            self::EMBEDDINGS_TABLE,
            "area = :embarea AND contextid = :embcontextid AND owneruserid {$embinsql}",
            ['embarea' => self::SITE_AREA, 'embcontextid' => $context->id] + $embinparams
        );

        if ($context instanceof context_user && in_array($context->instanceid, $userids)) {
            $DB->delete_records(self::MEMORY_TABLE, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete AI conversation data (threads + their messages + runs + debug logs) in a context,
     * optionally narrowed to a user/user-set via an extra WHERE clause.
     *
     * Messages carry no contextid of their own, so they are removed by the ids of the threads being
     * deleted; runs and debug logs are pinned to the same context as their thread and are removed
     * directly by contextid.
     *
     * @param context $context
     * @param string $extrawhere additional WHERE clause (without leading AND), e.g. "userid = :userid"
     * @param array $extraparams parameters for the extra clause
     * @return void
     */
    private static function delete_conversations_select(context $context, string $extrawhere, array $extraparams): void {
        global $DB;

        $where = 'contextid = :contextid';
        $params = ['contextid' => $context->id] + $extraparams;
        if ($extrawhere !== '') {
            $where .= ' AND ' . $extrawhere;
        }

        // Remove messages by the ids of the threads about to be deleted.
        $threadids = $DB->get_fieldset_select(self::THREADS_TABLE, 'id', $where, $params);
        if (!empty($threadids)) {
            $DB->delete_records_list(self::MESSAGES_TABLE, 'threadid', $threadids);
        }

        $DB->delete_records_select(self::THREADS_TABLE, $where, $params);
        $DB->delete_records_select(self::RUNS_TABLE, $where, $params);
        $DB->delete_records_select(self::DEBUG_TABLE, $where, $params);
    }
}

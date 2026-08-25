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

namespace local_studybuddy\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes stored metadata.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_studybuddy_documents', [
            'courseid' => 'privacy:metadata:documents:courseid',
            'contextid' => 'privacy:metadata:documents:contextid',
            'title' => 'privacy:metadata:documents:title',
        ], 'privacy:metadata:documents');

        $collection->add_database_table('local_studybuddy_stores', [
            'courseid' => 'privacy:metadata:stores:courseid',
            'provider' => 'privacy:metadata:stores:provider',
            'externalid' => 'privacy:metadata:stores:externalid',
            'metadata' => 'privacy:metadata:stores:metadata',
        ], 'privacy:metadata:stores');

        $collection->add_database_table('local_studybuddy_store_files', [
            'courseid' => 'privacy:metadata:storefiles:courseid',
            'documentid' => 'privacy:metadata:storefiles:documentid',
            'externalfileid' => 'privacy:metadata:storefiles:externalfileid',
            'vectorfileid' => 'privacy:metadata:storefiles:vectorfileid',
            'attributes' => 'privacy:metadata:storefiles:attributes',
        ], 'privacy:metadata:storefiles');

        $collection->add_database_table('local_studybuddy_syncjob', [
            'courseid' => 'privacy:metadata:syncjob:courseid',
            'provider' => 'privacy:metadata:syncjob:provider',
            'status' => 'privacy:metadata:syncjob:status',
            'error_text' => 'privacy:metadata:syncjob:error_text',
        ], 'privacy:metadata:syncjob');

        $collection->add_database_table('local_studybuddy_practice', [
            'courseid' => 'privacy:metadata:practice:courseid',
            'userid' => 'privacy:metadata:practice:userid',
            'querytext' => 'privacy:metadata:practice:querytext',
            'resultjson' => 'privacy:metadata:practice:resultjson',
        ], 'privacy:metadata:practice');

        $collection->add_database_table('local_studybuddy_drafts', [
            'courseid' => 'privacy:metadata:drafts:courseid',
            'userid' => 'privacy:metadata:drafts:userid',
            'prompttext' => 'privacy:metadata:drafts:prompttext',
            'resultjson' => 'privacy:metadata:drafts:resultjson',
        ], 'privacy:metadata:drafts');

        $collection->add_database_table('local_studybuddy_chats', [
            'courseid' => 'privacy:metadata:chats:courseid',
            'userid' => 'privacy:metadata:chats:userid',
            'sessionid' => 'privacy:metadata:chats:sessionid',
            'title' => 'privacy:metadata:chats:title',
        ], 'privacy:metadata:chats');

        $collection->add_database_table('local_studybuddy_messages', [
            'chatid' => 'privacy:metadata:messages:chatid',
            'userid' => 'privacy:metadata:messages:userid',
            'content' => 'privacy:metadata:messages:content',
            'sourcesjson' => 'privacy:metadata:messages:sourcesjson',
        ], 'privacy:metadata:messages');

        $collection->add_database_table('local_studybuddy_usage', [
            'courseid' => 'privacy:metadata:usage:courseid',
            'userid' => 'privacy:metadata:usage:userid',
            'prompttext' => 'privacy:metadata:usage:prompttext',
            'responsetext' => 'privacy:metadata:usage:responsetext',
        ], 'privacy:metadata:usage');

        $externalfields = [
            'coursecontent' => 'privacy:metadata:external:coursecontent',
            'prompt' => 'privacy:metadata:external:prompt',
            'response' => 'privacy:metadata:external:response',
            'metadata' => 'privacy:metadata:external:metadata',
        ];
        foreach (['openai', 'google', 'vertexai'] as $provider) {
            $collection->add_external_location_link(
                'local_studybuddy_' . $provider,
                $externalfields,
                'privacy:metadata:external:purpose'
            );
        }

        return $collection;
    }

    /**
     * Returns contexts containing user data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_studybuddy_practice} p ON p.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel1
                   AND p.userid = :userid1
                 UNION
                SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_studybuddy_drafts} d ON d.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel2
                   AND d.userid = :userid2
                 UNION
                SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_studybuddy_chats} ch ON ch.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel3
                   AND ch.userid = :userid3
                 UNION
                SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_studybuddy_usage} u ON u.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel4
                   AND u.userid = :userid4";
        $contextlist->add_from_sql($sql, [
            'courselevel1' => CONTEXT_COURSE,
            'userid1' => $userid,
            'courselevel2' => CONTEXT_COURSE,
            'userid2' => $userid,
            'courselevel3' => CONTEXT_COURSE,
            'userid3' => $userid,
            'courselevel4' => CONTEXT_COURSE,
            'userid4' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Adds users who have data in a context.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT userid
                  FROM {local_studybuddy_practice}
                 WHERE courseid = :courseid1
                 UNION
                SELECT userid
                  FROM {local_studybuddy_drafts}
                 WHERE courseid = :courseid2
                 UNION
                SELECT userid
                  FROM {local_studybuddy_chats}
                 WHERE courseid = :courseid3
                 UNION
                SELECT userid
                  FROM {local_studybuddy_usage}
                 WHERE courseid = :courseid4";
        $userlist->add_from_sql('userid', $sql, [
            'courseid1' => $context->instanceid,
            'courseid2' => $context->instanceid,
            'courseid3' => $context->instanceid,
            'courseid4' => $context->instanceid,
        ]);
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $practice = [];
            $records = $DB->get_records('local_studybuddy_practice', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ], 'timecreated');
            foreach ($records as $record) {
                $practice[] = [
                    'query' => $record->querytext,
                    'result' => $record->resultjson,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }

            $drafts = [];
            $records = $DB->get_records('local_studybuddy_drafts', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ], 'timecreated');
            foreach ($records as $record) {
                $drafts[] = [
                    'title' => $record->title,
                    'prompt' => $record->prompttext,
                    'result' => $record->resultjson,
                    'status' => $record->status,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }

            $chats = [];
            $records = $DB->get_records('local_studybuddy_chats', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ], 'timecreated');
            foreach ($records as $record) {
                $messages = $DB->get_records('local_studybuddy_messages', ['chatid' => $record->id], 'timecreated');
                $exportedmessages = [];
                foreach ($messages as $message) {
                    $exportedmessages[] = [
                        'role' => $message->role,
                        'content' => $message->content,
                        'sources' => $message->sourcesjson,
                        'timecreated' => transform::datetime($message->timecreated),
                    ];
                }
                $chats[] = [
                    'title' => $record->title,
                    'timecreated' => transform::datetime($record->timecreated),
                    'messages' => $exportedmessages,
                ];
            }

            $usage = [];
            $records = $DB->get_records('local_studybuddy_usage', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ], 'timecreated');
            foreach ($records as $record) {
                $usage[] = [
                    'provider' => $record->provider,
                    'prompt' => $record->prompttext,
                    'response' => $record->responsetext,
                    'timecreated' => transform::datetime($record->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_studybuddy')],
                (object)['practice' => $practice, 'drafts' => $drafts, 'chats' => $chats, 'usage' => $usage]
            );
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $courseid = (int)$context->instanceid;
        $stores = $DB->get_records('local_studybuddy_stores', ['courseid' => $courseid], '', 'id');
        if (!empty($stores)) {
            [$storesql, $storeparams] = $DB->get_in_or_equal(array_keys($stores), SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_studybuddy_store_files', "vectorstoreid {$storesql}", $storeparams);
        }
        $DB->delete_records('local_studybuddy_stores', ['courseid' => $courseid]);
        $DB->delete_records('local_studybuddy_syncjob', ['courseid' => $courseid]);
        $DB->delete_records('local_studybuddy_documents', ['courseid' => $courseid]);
        $DB->delete_records('local_studybuddy_practice', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_studybuddy_drafts', ['courseid' => $context->instanceid]);
        $chats = $DB->get_records('local_studybuddy_chats', ['courseid' => $context->instanceid], '', 'id');
        if (!empty($chats)) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($chats), SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_studybuddy_messages', "chatid {$insql}", $params);
        }
        $DB->delete_records('local_studybuddy_chats', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_studybuddy_usage', ['courseid' => $context->instanceid]);
    }

    /**
     * Deletes user data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('local_studybuddy_practice', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            $DB->delete_records('local_studybuddy_drafts', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            self::delete_chat_data_for_users($context->instanceid, [$userid]);
            $DB->delete_records('local_studybuddy_usage', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Deletes data for multiple approved users in a context.
     *
     * @param approved_userlist $userlist Approved user list.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $context->instanceid;
        $DB->delete_records_select('local_studybuddy_practice', "courseid = :courseid AND userid {$insql}", $params);
        $DB->delete_records_select('local_studybuddy_drafts', "courseid = :courseid AND userid {$insql}", $params);
        self::delete_chat_data_for_users($context->instanceid, $userids);
        $DB->delete_records_select('local_studybuddy_usage', "courseid = :courseid AND userid {$insql}", $params);
    }

    /**
     * Deletes chat rows and messages for users in a course.
     *
     * @param int $courseid Course id.
     * @param array $userids User ids.
     * @return void
     */
    private static function delete_chat_data_for_users(int $courseid, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $userparams['courseid'] = $courseid;
        $chats = $DB->get_records_select(
            'local_studybuddy_chats',
            "courseid = :courseid AND userid {$usersql}",
            $userparams,
            '',
            'id'
        );
        if (!empty($chats)) {
            [$chatsql, $chatparams] = $DB->get_in_or_equal(array_keys($chats), SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_studybuddy_messages', "chatid {$chatsql}", $chatparams);
        }
        $DB->delete_records_select('local_studybuddy_chats', "courseid = :courseid AND userid {$usersql}", $userparams);
    }
}

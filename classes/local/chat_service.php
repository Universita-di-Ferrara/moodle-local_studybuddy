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

namespace local_studybuddy\local;

use local_studybuddy\local\provider\provider_factory;
use local_studybuddy\local\provider\provider_file_scope;

/**
 * RAG chat orchestration service.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_service {
    /** @var int Default maximum number of messages retained in one temporary chat. */
    private const DEFAULT_MAX_SESSION_MESSAGES = 24;

    /**
     * Creates a chat for one course/user.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param string|null $title Optional title.
     * @return \stdClass Chat row.
     */
    public function create_chat(int $courseid, int $userid, ?string $title = null): \stdClass {
        global $SESSION;

        $context = \context_course::instance($courseid);
        require_capability('local/studybuddy:chat', $context);

        $now = time();
        $chats = $this->get_session_chats();
        foreach ($chats as $chatid => $chat) {
            if ((int)$chat['courseid'] === $courseid && (int)$chat['userid'] === $userid) {
                unset($chats[$chatid]);
            }
        }

        do {
            $chatid = random_int(1, 2147483647);
        } while (isset($chats[$chatid]));

        $record = [
            'courseid' => $courseid,
            'userid' => $userid,
            'title' => $title ?: get_string('chat:newchat', 'local_studybuddy'),
            'status' => 'active',
            'timecreated' => $now,
            'timemodified' => $now,
            'messages' => [],
        ];
        $chats[$chatid] = $record;
        $SESSION->local_studybuddy_chats = $chats;

        return (object)array_merge(['id' => $chatid], $record);
    }

    /**
     * Gets or creates the latest active chat for a user/course.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return \stdClass Chat row.
     */
    public function get_or_create_chat(int $courseid, int $userid): \stdClass {
        foreach ($this->get_session_chats() as $chatid => $chat) {
            if (
                (int)$chat['courseid'] === $courseid && (int)$chat['userid'] === $userid &&
                    $chat['status'] === 'active'
            ) {
                return (object)array_merge(['id' => $chatid], $chat);
            }
        }

        return $this->create_chat($courseid, $userid);
    }

    /**
     * Sends one message and stores the generated response.
     *
     * @param int $chatid Chat id.
     * @param int $userid User id.
     * @param string $message User message.
     * @return array Response payload.
     */
    public function send_message(int $chatid, int $userid, string $message): array {
        $chat = $this->require_chat_access($chatid, $userid);
        $status = (new source_service())->get_sync_status((int)$chat->courseid, $userid);
        if (in_array((string)$status['status'], ['queued', 'pending', 'running', 'syncing', 'in_progress'], true)) {
            throw new \moodle_exception('chat:syncinprogress', 'local_studybuddy');
        }
        if ((int)$status['available'] === 0) {
            throw new \moodle_exception('chat:nosources', 'local_studybuddy');
        }

        try {
            $answer = $this->ask_configured_provider($chat, $message);
        } catch (\Throwable $exception) {
            $this->throw_user_friendly_provider_exception($exception);
        }
        $response = $answer['response'];
        $sources = $answer['sources'];
        $now = time();

        $this->append_session_message($chatid, $userid, 'user', $message, [], $now);
        $assistantid = $this->append_session_message($chatid, null, 'assistant', $response, $sources, $now);

        return [
            'messageid' => $assistantid,
            'response' => $this->format_chat_message($response, (int)$chat->courseid),
            'responseformat' => FORMAT_HTML,
            'sources' => $sources,
            'timecreated' => $now,
        ];
    }

    /**
     * Returns chat messages.
     *
     * @param int $chatid Chat id.
     * @param int $userid User id.
     * @return array Messages.
     */
    public function get_history(int $chatid, int $userid): array {
        $chat = $this->require_chat_access($chatid, $userid);
        $messages = [];

        foreach ($this->get_session_chat_messages($chatid) as $record) {
            $messages[] = [
                'id' => (int)$record['id'],
                'role' => (string)$record['role'],
                'content' => $this->format_chat_message((string)$record['content'], (int)$chat->courseid),
                'contentformat' => FORMAT_HTML,
                'timecreated' => (int)$record['timecreated'],
                'sources' => $this->normalise_public_sources($record['sources']),
            ];
        }

        return $messages;
    }

    /**
     * Formats an AI or user message using Moodle's native Markdown formatter.
     *
     * @param string $content Raw Markdown content.
     * @param int $courseid Course id used for filtering.
     * @return string Sanitised HTML.
     */
    private function format_chat_message(string $content, int $courseid): string {
        return trim(format_text($content, FORMAT_MARKDOWN, [
            'context' => \context_course::instance($courseid),
            'filter' => true,
            'para' => true,
            'newlines' => true,
        ]));
    }

    /**
     * Requires access to a chat row.
     *
     * @param int $chatid Chat id.
     * @param int $userid User id.
     * @return \stdClass Chat row.
     */
    public function get_chat(int $chatid, int $userid): \stdClass {
        return $this->require_chat_access($chatid, $userid);
    }

    /**
     * Requires access to a chat stored in the current Moodle session.
     *
     * @param int $chatid Temporary chat identifier.
     * @param int $userid User id.
     * @return \stdClass Chat data.
     */
    private function require_chat_access(int $chatid, int $userid): \stdClass {
        $chats = $this->get_session_chats();
        if (!isset($chats[$chatid])) {
            throw new \moodle_exception('chat:expired', 'local_studybuddy');
        }

        $chat = $chats[$chatid];
        if ((int)$chat['userid'] !== $userid) {
            throw new \required_capability_exception(
                \context_course::instance((int)$chat['courseid']),
                'local/studybuddy:chat',
                'nopermissions',
                ''
            );
        }

        $context = \context_course::instance((int)$chat['courseid']);
        require_capability('local/studybuddy:chat', $context);

        return (object)array_merge(['id' => $chatid], $chat);
    }

    /**
     * Returns chats stored in the current Moodle session.
     *
     * @return array Session chat records.
     */
    private function get_session_chats(): array {
        global $SESSION;

        if (!isset($SESSION->local_studybuddy_chats) || !is_array($SESSION->local_studybuddy_chats)) {
            $SESSION->local_studybuddy_chats = [];
        }

        return $SESSION->local_studybuddy_chats;
    }

    /**
     * Returns messages for a temporary session chat.
     *
     * @param int $chatid Temporary chat identifier.
     * @return array Session messages.
     */
    private function get_session_chat_messages(int $chatid): array {
        $chats = $this->get_session_chats();
        return $chats[$chatid]['messages'] ?? [];
    }

    /**
     * Appends a message to a temporary session chat.
     *
     * @param int $chatid Temporary chat identifier.
     * @param int|null $userid User id.
     * @param string $role Message role.
     * @param string $content Message content.
     * @param array $sources Public sources.
     * @param int $timecreated Creation time.
     * @return int Temporary message identifier.
     */
    private function append_session_message(
        int $chatid,
        ?int $userid,
        string $role,
        string $content,
        array $sources,
        int $timecreated
    ): int {
        global $SESSION;

        $chats = $this->get_session_chats();
        $messageid = count($chats[$chatid]['messages']) + 1;
        $chats[$chatid]['messages'][] = [
            'id' => $messageid,
            'userid' => $userid,
            'role' => $role,
            'content' => $content,
            'sources' => $sources,
            'timecreated' => $timecreated,
        ];
        $chats[$chatid]['messages'] = array_slice(
            $chats[$chatid]['messages'],
            -$this->get_max_session_messages()
        );
        $chats[$chatid]['timemodified'] = $timecreated;
        $SESSION->local_studybuddy_chats = $chats;

        return $messageid;
    }

    /**
     * Gets the configured message limit for a temporary chat.
     *
     * @return int Number of messages to retain.
     */
    private function get_max_session_messages(): int {
        $configured = (int)get_config('local_studybuddy', 'maxchatmessages');
        $limit = max(2, min(100, $configured ?: self::DEFAULT_MAX_SESSION_MESSAGES));

        return $limit - ($limit % 2);
    }

    /**
     * Asks the configured provider.
     *
     * @param \stdClass $chat Chat row.
     * @param string $message User message.
     * @return array Provider response and sources.
     */
    private function ask_configured_provider(\stdClass $chat, string $message): array {
        global $DB;

        $courseid = (int)$chat->courseid;
        $provider = (string)(get_config('local_studybuddy', 'provider') ?: 'openai');
        $chatprovider = provider_factory::create_chat_provider();
        $retrievaloptions = ['userid' => (int)$chat->userid];
        $knowledgebaseid = $this->get_provider_knowledge_base_id(
            $courseid,
            $provider,
            $message,
            $retrievaloptions,
            (int)$chat->userid
        );
        $conversation = [];

        foreach ($this->get_session_chat_messages((int)$chat->id) as $historymessage) {
            $conversation[] = [
                'role' => (string)$historymessage['role'],
                'content' => (string)$historymessage['content'],
            ];
        }
        $conversation[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $response = $chatprovider->ask_course_kb(
            $knowledgebaseid,
            $conversation,
            $this->chat_instructions(),
            $retrievaloptions
        );
        $assistanttext = $chatprovider->extract_text($response);
        if ($assistanttext === '') {
            throw new \moodle_exception('chat:emptyresponse', 'local_studybuddy');
        }

        $sources = $this->normalise_public_sources($chatprovider->extract_sources($response));

        return [
            'response' => $assistanttext,
            'sources' => $sources,
        ];
    }

    /**
     * Converts provider failures into messages suitable for the chat UI.
     *
     * The original exception is retained in the server log, but provider
     * internals and remote API diagnostics must not be shown to users.
     *
     * @param \Throwable $exception Provider exception.
     * @return void
     */
    private function throw_user_friendly_provider_exception(\Throwable $exception): void {
        $message = strtolower($exception->getMessage());
        $busyindicators = ['high demand', 'rate limit', 'too many requests', '429'];
        foreach ($busyindicators as $indicator) {
            if (strpos($message, $indicator) !== false) {
                throw new \moodle_exception('chat:providerbusy', 'local_studybuddy');
            }
        }

        throw new \moodle_exception('chat:providerunavailable', 'local_studybuddy');
    }

    /**
     * Returns the provider KB id for the current course.
     *
     * @param int $courseid Course id.
     * @param string $provider Provider key.
     * @param string $message User message.
     * @param array $retrievaloptions Provider retrieval options.
     * @param int $userid User id.
     * @return string|null Provider KB id.
     */
    private function get_provider_knowledge_base_id(
        int $courseid,
        string $provider,
        string $message,
        array &$retrievaloptions,
        int $userid
    ): ?string {
        global $DB;

        $store = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => $provider,
        ]);
        if (!$store || empty($store->externalid) || (string)$store->status !== 'completed') {
            throw new \moodle_exception('chat:providersyncneeded', 'local_studybuddy');
        }

        if (!in_array($provider, ['openai', 'google', 'vertexai'], true)) {
            throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider);
        }

        $scope = (new provider_file_scope())->get_scope($courseid, $provider, $userid);
        if (empty($scope['files'])) {
            throw new \moodle_exception('chat:providersyncneeded', 'local_studybuddy');
        }

        if ($provider === 'openai') {
            $retrievaloptions['openai_file_filter'] = (new provider_file_scope())->openai_document_filter($scope['documentids']);
        } else if ($provider === 'vertexai') {
            $retrievaloptions['vertexragfileids'] = $scope['vertexragfileids'];
        } else if ($provider === 'google') {
            $retrievaloptions['google_metadata_filter'] = (new provider_file_scope())->google_metadata_filter(
                $scope['documentids']
            );
        }

        return (string)$store->externalid;
    }

    /**
     * System instructions shared by remote chat providers.
     *
     * @return string Instructions.
     */
    private function chat_instructions(): string {
        return str_replace(
            '%%LANGUAGE%%',
            current_language(),
            prompt_config::get('chatinstructions', 'default:chatinstructions')
        );
    }

    /**
     * Keep public chat citations compact: show source identity only, not retrieved excerpts.
     *
     * @param array $sources Provider sources.
     * @return array Normalised sources.
     */
    private function normalise_public_sources(array $sources): array {
        $normalised = [];
        $seen = [];
        foreach ($sources as $source) {
            $item = [
                'title' => (string)($source['title'] ?? $source['filename'] ?? get_string('source:unknown', 'local_studybuddy')),
                'sourcetype' => (string)($source['sourcetype'] ?? ''),
                'cmid' => (int)($source['cmid'] ?? 0),
                'documentid' => (int)($source['documentid'] ?? 0),
                'chunkid' => (int)($source['chunkid'] ?? 0),
                'externalfileid' => (string)($source['externalfileid'] ?? ''),
            ];

            $key = implode('|', [
                \core_text::strtolower($item['title']),
                $item['sourcetype'],
                $item['cmid'],
                $item['documentid'],
                $item['externalfileid'],
            ]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalised[] = $item;
        }

        return $normalised;
    }
}

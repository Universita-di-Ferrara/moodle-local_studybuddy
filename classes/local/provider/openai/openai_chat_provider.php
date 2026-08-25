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

namespace local_studybuddy\local\provider\openai;

use local_studybuddy\local\provider\chat_provider_interface;

/**
 * OpenAI chat provider using Responses API and file_search.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_chat_provider implements chat_provider_interface {
    /** @var openai_responses_api Responses wrapper. */
    private openai_responses_api $responsesapi;

    /**
     * Constructor.
     *
     * @param openai_responses_api|null $responsesapi API wrapper.
     */
    public function __construct(?openai_responses_api $responsesapi = null) {
        $this->responsesapi = $responsesapi ?? new openai_responses_api(new openai_client());
    }

    /**
     * Sends a conversation to OpenAI with file search enabled.
     *
     * @param string|null $knowledgebaseid Vector store id.
     * @param array $conversation Conversation messages.
     * @param string $systemprompt System instructions.
     * @param array $options Provider options.
     * @return array Provider response.
     */
    public function ask_course_kb(?string $knowledgebaseid, array $conversation, string $systemprompt, array $options = []): array {
        $input = [];
        foreach ($conversation as $message) {
            if (!is_array($message) || !isset($message['role'])) {
                continue;
            }

            $type = ($message['role'] === 'assistant') ? 'output_text' : 'input_text';
            $input[] = [
                'role' => $message['role'],
                'content' => [[
                    'type' => $type,
                    'text' => (string)($message['content'] ?? ''),
                ], ],
            ];
        }

        return $this->responsesapi->create(
            $input,
            $systemprompt,
            $knowledgebaseid ? [$knowledgebaseid] : [],
            null,
            null,
            $options['openai_file_filter'] ?? null
        );
    }

    /**
     * Extracts assistant text from an OpenAI response.
     *
     * @param array $response Provider response.
     * @return string Assistant text.
     */
    public function extract_text(array $response): string {
        return $this->responsesapi->extract_text($response);
    }

    /**
     * Extracts file search sources from an OpenAI response.
     *
     * @param array $response Provider response.
     * @return array Normalised sources.
     */
    public function extract_sources(array $response): array {
        $sources = [];
        foreach (($response['output'] ?? []) as $output) {
            if (($output['type'] ?? '') !== 'file_search_call') {
                continue;
            }
            foreach (($output['results'] ?? []) as $result) {
                $title = $result['filename'] ?? $result['file_id'] ?? get_string('source:unknown', 'local_studybuddy');
                $sources[] = [
                    'title' => (string)$title,
                    'sourcetype' => 'openai_file_search',
                    'cmid' => (int)($result['attributes']['cmid'] ?? 0),
                    'chunkid' => 0,
                    'documentid' => (int)($result['attributes']['documentid'] ?? 0),
                    'score' => (float)($result['score'] ?? 0),
                    'excerpt' => $this->extract_openai_excerpt($result),
                ];
            }
        }

        return $sources;
    }

    /**
     * Extracts a short file_search excerpt.
     *
     * @param array $result Search result.
     * @return string
     */
    private function extract_openai_excerpt(array $result): string {
        $parts = [];
        foreach (($result['content'] ?? []) as $content) {
            if (is_array($content) && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            } else if (is_string($content)) {
                $parts[] = $content;
            }
        }

        return shorten_text(trim(implode("\n", $parts)), 180);
    }
}

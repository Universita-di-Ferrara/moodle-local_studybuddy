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

namespace local_studybuddy\local\provider\vertexai;

use local_studybuddy\local\provider\chat_provider_interface;

/**
 * Vertex AI chat provider using Gemini and RAG Engine.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vertexai_chat_provider implements chat_provider_interface {
    /** @var vertexai_client REST client. */
    private vertexai_client $client;

    /**
     * Constructor.
     *
     * @param vertexai_client|null $client Optional Vertex AI client.
     */
    public function __construct(?vertexai_client $client = null) {
        $this->client = $client ?? new vertexai_client();
    }

    /**
     * Sends a conversation to Vertex AI with RAG retrieval enabled.
     *
     * @param string|null $knowledgebaseid RAG corpus resource name.
     * @param array $conversation Conversation messages.
     * @param string $systemprompt System instructions.
     * @param array $options Provider options.
     * @return array Provider response.
     */
    public function ask_course_kb(?string $knowledgebaseid, array $conversation, string $systemprompt, array $options = []): array {
        $contents = [];
        foreach ($conversation as $message) {
            if (!is_array($message) || !isset($message['role'])) {
                continue;
            }
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [[
                    'text' => (string)($message['content'] ?? ''),
                ], ],
            ];
        }

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemprompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.8,
            ],
        ];

        if (!empty($knowledgebaseid)) {
            $ragresource = [
                'ragCorpus' => $knowledgebaseid,
            ];
            if (!empty($options['vertexragfileids']) && is_array($options['vertexragfileids'])) {
                $ragresource['ragFileIds'] = array_values($options['vertexragfileids']);
            }

            $body['tools'] = [[
                'retrieval' => [
                    'vertexRagStore' => [
                        'ragResources' => [$ragresource],
                        'similarityTopK' => max(1, min(20, (int)(get_config('local_studybuddy', 'vertexairagtopk') ?: 5))),
                    ],
                ],
            ], ];
        }

        return $this->client->request('POST', $this->client->generation_path(), $body);
    }

    /**
     * Extracts assistant text from a Vertex AI response.
     *
     * @param array $response Provider response.
     * @return string Assistant text.
     */
    public function extract_text(array $response): string {
        $text = '';
        foreach (($response['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $text .= (string)$part['text'] . "\n";
                }
            }
        }

        return trim($text);
    }

    /**
     * Extracts grounding sources from a Vertex AI response.
     *
     * @param array $response Provider response.
     * @return array Normalised sources.
     */
    public function extract_sources(array $response): array {
        $sources = [];
        $chunks = $response['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        foreach ($chunks as $chunk) {
            $retrieved = $chunk['retrievedContext'] ?? [];
            $sources[] = [
                'title' => (string)($retrieved['title'] ?? $retrieved['uri'] ?? get_string('source:unknown', 'local_studybuddy')),
                'sourcetype' => 'vertexai_rag_engine',
                'cmid' => 0,
                'chunkid' => 0,
                'documentid' => 0,
                'score' => 0.0,
                'excerpt' => shorten_text((string)($retrieved['text'] ?? ''), 180),
            ];
        }

        return $sources;
    }
}

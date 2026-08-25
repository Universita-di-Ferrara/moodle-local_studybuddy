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

/**
 * Wrapper for OpenAI Responses API endpoints.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_responses_api {
    /** @var openai_client OpenAI API client. */
    private openai_client $client;

    /**
     * Constructor.
     *
     * @param openai_client $client Client.
     */
    public function __construct(openai_client $client) {
        $this->client = $client;
    }

    /**
     * POST /v1/responses.
     *
     * @param array|string $input Response input.
     * @param string $instructions System/developer instructions.
     * @param array $vectorstoreids Vector store ids for file_search.
     * @param array|null $jsonschema Optional JSON schema.
     * @param string|null $model Model override.
     * @param array|null $filters Optional file_search filters.
     * @return array
     */
    public function create(
        $input,
        string $instructions,
        array $vectorstoreids = [],
        ?array $jsonschema = null,
        ?string $model = null,
        ?array $filters = null
    ): array {
        $body = [
            'model' => $model ?: openai_client::get_configured_responses_model(),
            'instructions' => $instructions,
            'input' => $input,
        ];

        if (!empty($vectorstoreids)) {
            $body['tools'] = [[
                'type' => 'file_search',
                'vector_store_ids' => array_values($vectorstoreids),
            ], ];
            if ($filters !== null) {
                $body['tools'][0]['filters'] = $filters;
            }
            $body['include'] = ['file_search_call.results'];
        }

        if ($jsonschema !== null) {
            $body['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'studybuddy_quiz',
                    'schema' => $jsonschema,
                    'strict' => true,
                ],
            ];
        }

        return $this->client->request('POST', '/responses', $body);
    }

    /**
     * GET /v1/responses/{response_id}.
     *
     * @param string $responseid Response id.
     * @return array
     */
    public function retrieve(string $responseid): array {
        return $this->client->request('GET', '/responses/' . rawurlencode($responseid));
    }

    /**
     * DELETE /v1/responses/{response_id}.
     *
     * @param string $responseid Response id.
     * @return array
     */
    public function delete(string $responseid): array {
        return $this->client->request('DELETE', '/responses/' . rawurlencode($responseid));
    }

    /**
     * GET /v1/responses/{response_id}/input_items.
     *
     * @param string $responseid Response id.
     * @param int $limit Limit.
     * @return array
     */
    public function input_items(string $responseid, int $limit = 100): array {
        return $this->client->request(
            'GET',
            '/responses/' . rawurlencode($responseid) . '/input_items?limit=' . $limit
        );
    }

    /**
     * POST /v1/responses/{response_id}/input_tokens.
     *
     * @param string $responseid Response id.
     * @param array $input Input to count.
     * @return array
     */
    public function input_tokens(string $responseid, array $input): array {
        return $this->client->request(
            'POST',
            '/responses/' . rawurlencode($responseid) . '/input_tokens',
            ['input' => $input]
        );
    }

    /**
     * Extracts output text from a Responses API result.
     *
     * @param array $response Response.
     * @return string Extracted text.
     */
    public function extract_text(array $response): string {
        $text = '';

        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $block) {
                $type = $block['type'] ?? '';
                if (($type === 'output_text' || $type === 'text') && isset($block['text'])) {
                    $text .= (string)$block['text'] . "\n";
                }
            }
        }

        return trim($text);
    }
}

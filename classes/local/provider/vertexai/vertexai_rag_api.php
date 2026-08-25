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

/**
 * Wrapper for Vertex AI RAG Engine REST endpoints.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vertexai_rag_api {
    /** @var vertexai_client Vertex AI client. */
    private vertexai_client $client;

    /**
     * Constructor.
     *
     * @param vertexai_client $client Client.
     */
    public function __construct(vertexai_client $client) {
        $this->client = $client;
    }

    /**
     * Creates a RAG corpus.
     *
     * @param string $displayname Display name.
     * @param string $description Description.
     * @param string $embeddingmodel Embedding model.
     * @return array Operation or corpus response.
     */
    public function create_corpus(string $displayname, string $description, string $embeddingmodel): array {
        $body = [
            'displayName' => $displayname,
            'description' => $description,
        ];

        if ($embeddingmodel !== '') {
            $body['vectorDbConfig'] = [
                'ragEmbeddingModelConfig' => [
                    'vertexPredictionEndpoint' => [
                        'endpoint' => $this->normalise_embedding_endpoint($embeddingmodel),
                    ],
                ],
            ];
        }

        return $this->client->request('POST', '/v1beta1/' . $this->client->parent_name() . '/ragCorpora', $body, [], true);
    }

    /**
     * Gets a RAG corpus.
     *
     * @param string $name Corpus name.
     * @return array
     */
    public function get_corpus(string $name): array {
        return $this->client->request('GET', '/v1beta1/' . $name, null, [], true);
    }

    /**
     * Deletes a RAG corpus.
     *
     * @param string $name Corpus name.
     * @return array
     */
    public function delete_corpus(string $name): array {
        return $this->client->request('DELETE', '/v1beta1/' . $name, null, [], true);
    }

    /**
     * Uploads a file into a RAG corpus.
     *
     * @param string $corpusname Corpus name.
     * @param string $filepath Local file path.
     * @param string $filename Display name.
     * @param string $mimetype MIME type.
     * @return array Operation response.
     */
    public function upload_file(string $corpusname, string $filepath, string $filename, string $mimetype): array {
        return $this->client->upload_rag_file($corpusname, $filepath, $filename, $mimetype);
    }

    /**
     * Gets a RAG file.
     *
     * @param string $name RAG file name.
     * @return array
     */
    public function get_file(string $name): array {
        return $this->client->request('GET', '/v1beta1/' . $name, null, [], true);
    }

    /**
     * Deletes a RAG file.
     *
     * @param string $name RAG file name.
     * @return array
     */
    public function delete_file(string $name): array {
        return $this->client->request('DELETE', '/v1beta1/' . $name, null, [], true);
    }

    /**
     * Gets a long-running operation.
     *
     * @param string $operationname Operation name.
     * @return array
     */
    public function get_operation(string $operationname): array {
        return $this->client->request('GET', '/v1beta1/' . $operationname, null, [], true);
    }

    /**
     * Normalises publisher model ids to the full Vertex AI endpoint resource.
     *
     * @param string $embeddingmodel Configured embedding model.
     * @return string Full endpoint resource name.
     */
    private function normalise_embedding_endpoint(string $embeddingmodel): string {
        if (str_starts_with($embeddingmodel, 'projects/')) {
            return $embeddingmodel;
        }

        return $this->client->parent_name() . '/' . ltrim($embeddingmodel, '/');
    }
}

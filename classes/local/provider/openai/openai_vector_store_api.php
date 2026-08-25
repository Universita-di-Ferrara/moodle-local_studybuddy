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
 * Wrapper for OpenAI Vector Store endpoints.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_vector_store_api {
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
     * POST /v1/vector_stores.
     *
     * @param string $name Store name.
     * @param array $metadata Metadata.
     * @param array $fileids Optional initial file ids.
     * @param array|null $chunkingstrategy Optional chunking strategy.
     * @return array
     */
    public function create(string $name, array $metadata = [], array $fileids = [], ?array $chunkingstrategy = null): array {
        $body = [
            'name' => $name,
            'metadata' => (object)$metadata,
        ];

        if (!empty($fileids)) {
            $body['file_ids'] = array_values($fileids);
        }

        if ($chunkingstrategy !== null) {
            $body['chunking_strategy'] = $chunkingstrategy;
        }

        return $this->client->request('POST', '/vector_stores', $body);
    }

    /**
     * GET /v1/vector_stores.
     *
     * @param int $limit Limit.
     * @param string|null $after Cursor.
     * @return array
     */
    public function list(int $limit = 20, ?string $after = null): array {
        $query = http_build_query(array_filter([
            'limit' => $limit,
            'after' => $after,
        ], static fn($value): bool => $value !== null && $value !== ''));

        return $this->client->request('GET', '/vector_stores' . ($query ? '?' . $query : ''));
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @return array
     */
    public function retrieve(string $vectorstoreid): array {
        return $this->client->request('GET', '/vector_stores/' . rawurlencode($vectorstoreid));
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @param array $fields Fields to update.
     * @return array
     */
    public function update(string $vectorstoreid, array $fields): array {
        return $this->client->request('POST', '/vector_stores/' . rawurlencode($vectorstoreid), $fields);
    }

    /**
     * DELETE /v1/vector_stores/{vector_store_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @return array
     */
    public function delete(string $vectorstoreid): array {
        return $this->client->request('DELETE', '/vector_stores/' . rawurlencode($vectorstoreid));
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}/search.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string|array $query Search query.
     * @param array|null $filters Attribute filters.
     * @param int|null $maxresults Maximum results.
     * @return array
     */
    public function search(string $vectorstoreid, $query, ?array $filters = null, ?int $maxresults = null): array {
        $body = ['query' => $query];
        if ($filters !== null) {
            $body['filters'] = $filters;
        }
        if ($maxresults !== null) {
            $body['max_num_results'] = $maxresults;
        }

        return $this->client->request('POST', '/vector_stores/' . rawurlencode($vectorstoreid) . '/search', $body);
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}/files.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $fileid File id.
     * @param array $attributes File attributes.
     * @param array|null $chunkingstrategy Chunking strategy.
     * @return array
     */
    public function create_file(
        string $vectorstoreid,
        string $fileid,
        array $attributes = [],
        ?array $chunkingstrategy = null
    ): array {
        $body = [
            'file_id' => $fileid,
        ];

        if (!empty($attributes)) {
            $body['attributes'] = (object)$attributes;
        }

        if ($chunkingstrategy !== null) {
            $body['chunking_strategy'] = $chunkingstrategy;
        }

        return $this->client->request('POST', '/vector_stores/' . rawurlencode($vectorstoreid) . '/files', $body);
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}/files.
     *
     * @param string $vectorstoreid Vector store id.
     * @param int $limit Limit.
     * @param string|null $after Cursor.
     * @param string|null $filter Status filter.
     * @return array
     */
    public function list_files(string $vectorstoreid, int $limit = 100, ?string $after = null, ?string $filter = null): array {
        $query = http_build_query(array_filter([
            'limit' => $limit,
            'after' => $after,
            'filter' => $filter,
        ], static fn($value): bool => $value !== null && $value !== ''));

        return $this->client->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/files' . ($query ? '?' . $query : '')
        );
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}/files/{file_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $fileid File id.
     * @return array
     */
    public function retrieve_file(string $vectorstoreid, string $fileid): array {
        return $this->client->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/files/' . rawurlencode($fileid)
        );
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}/files/{file_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $fileid File id.
     * @param array $attributes Attributes.
     * @return array
     */
    public function update_file(string $vectorstoreid, string $fileid, array $attributes): array {
        return $this->client->request(
            'POST',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/files/' . rawurlencode($fileid),
            ['attributes' => (object)$attributes]
        );
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}/files/{file_id}/content.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $fileid File id.
     * @return array
     */
    public function file_content(string $vectorstoreid, string $fileid): array {
        return $this->client->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/files/' . rawurlencode($fileid) . '/content'
        );
    }

    /**
     * DELETE /v1/vector_stores/{vector_store_id}/files/{file_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $fileid File id.
     * @return array
     */
    public function delete_file(string $vectorstoreid, string $fileid): array {
        return $this->client->request(
            'DELETE',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/files/' . rawurlencode($fileid)
        );
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}/file_batches.
     *
     * @param string $vectorstoreid Vector store id.
     * @param array $fileids File ids.
     * @return array
     */
    public function create_file_batch(string $vectorstoreid, array $fileids): array {
        return $this->client->request(
            'POST',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/file_batches',
            ['file_ids' => array_values($fileids)]
        );
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}/file_batches/{batch_id}.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $batchid Batch id.
     * @return array
     */
    public function retrieve_file_batch(string $vectorstoreid, string $batchid): array {
        return $this->client->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/file_batches/' . rawurlencode($batchid)
        );
    }

    /**
     * GET /v1/vector_stores/{vector_store_id}/file_batches/{batch_id}/files.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $batchid Batch id.
     * @param int $limit Limit.
     * @return array
     */
    public function list_file_batch_files(string $vectorstoreid, string $batchid, int $limit = 100): array {
        return $this->client->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/file_batches/' .
                rawurlencode($batchid) . '/files?limit=' . $limit
        );
    }

    /**
     * POST /v1/vector_stores/{vector_store_id}/file_batches/{batch_id}/cancel.
     *
     * @param string $vectorstoreid Vector store id.
     * @param string $batchid Batch id.
     * @return array
     */
    public function cancel_file_batch(string $vectorstoreid, string $batchid): array {
        return $this->client->request(
            'POST',
            '/vector_stores/' . rawurlencode($vectorstoreid) . '/file_batches/' . rawurlencode($batchid) . '/cancel',
            []
        );
    }
}

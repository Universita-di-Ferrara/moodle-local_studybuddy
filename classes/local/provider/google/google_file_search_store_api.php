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

namespace local_studybuddy\local\provider\google;

/**
 * Wrapper for Google Gemini File Search Store endpoints.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_file_search_store_api {
    /** @var google_client Google API client. */
    private google_client $client;

    /**
     * Constructor.
     *
     * @param google_client $client Client.
     */
    public function __construct(google_client $client) {
        $this->client = $client;
    }

    /**
     * POST /v1beta/fileSearchStores.
     *
     * @param string $displayname Store display name.
     * @param string $embeddingmodel Optional embedding model.
     * @return array
     */
    public function create(string $displayname, string $embeddingmodel = ''): array {
        $body = [
            'displayName' => $displayname,
        ];

        if ($embeddingmodel !== '') {
            // The REST guide currently documents this field in snake_case.
            $body['embedding_model'] = $embeddingmodel;
        }

        return $this->client->request('POST', '/fileSearchStores', $body);
    }

    /**
     * GET /v1beta/{name=fileSearchStores/*}.
     *
     * @param string $name Store resource name.
     * @return array
     */
    public function retrieve(string $name): array {
        return $this->client->request('GET', '/' . $name);
    }

    /**
     * DELETE /v1beta/{name=fileSearchStores/*}.
     *
     * @param string $name Store resource name.
     * @param bool $force Whether to delete contained documents.
     * @return array
     */
    public function delete(string $name, bool $force = true): array {
        return $this->client->request('DELETE', '/' . $name . '?force=' . ($force ? 'true' : 'false'));
    }

    /**
     * Uploads a document into the store.
     *
     * @param string $store Store resource name.
     * @param string $filepath Local file path.
     * @param string $filename Display name.
     * @param string $mimetype MIME type.
     * @param array $custommetadata Custom metadata fields.
     * @return array Operation response.
     */
    public function upload_document(
        string $store,
        string $filepath,
        string $filename,
        string $mimetype,
        array $custommetadata = []
    ): array {
        return $this->client->upload_to_file_search_store(
            $store,
            $filepath,
            $filename,
            $mimetype,
            $custommetadata
        );
    }

    /**
     * Gets a long-running operation.
     *
     * @param string $operationname Operation resource name.
     * @return array
     */
    public function get_operation(string $operationname): array {
        return $this->client->request('GET', '/' . $operationname);
    }

    /**
     * Lists documents in a File Search store.
     *
     * @param string $store Store resource name.
     * @param int $pagesize Page size.
     * @return array
     */
    public function list_documents(string $store, int $pagesize = 100): array {
        return $this->client->request('GET', '/' . $store . '/documents?pageSize=' . $pagesize);
    }

    /**
     * Deletes a File Search document.
     *
     * @param string $documentname Document resource name.
     * @return array
     */
    public function delete_document(string $documentname): array {
        return $this->client->request('DELETE', '/' . $documentname);
    }
}

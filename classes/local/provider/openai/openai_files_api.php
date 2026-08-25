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
 * Wrapper for OpenAI Files API endpoints.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_files_api {
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
     * POST /v1/files.
     *
     * @param string $filepath Local path.
     * @param string $purpose File purpose.
     * @param string|null $filename Upload filename.
     * @return array
     */
    public function upload(string $filepath, string $purpose = 'assistants', ?string $filename = null): array {
        return $this->client->upload_file($filepath, $purpose, $filename);
    }

    /**
     * GET /v1/files.
     *
     * @param string|null $purpose Optional purpose filter.
     * @param int $limit Limit.
     * @param string|null $after Cursor.
     * @return array
     */
    public function list(?string $purpose = null, int $limit = 100, ?string $after = null): array {
        $query = http_build_query(array_filter([
            'purpose' => $purpose,
            'limit' => $limit,
            'after' => $after,
        ], static fn($value): bool => $value !== null && $value !== ''));

        return $this->client->request('GET', '/files' . ($query ? '?' . $query : ''));
    }

    /**
     * GET /v1/files/{file_id}.
     *
     * @param string $fileid File id.
     * @return array
     */
    public function retrieve(string $fileid): array {
        return $this->client->request('GET', '/files/' . rawurlencode($fileid));
    }

    /**
     * GET /v1/files/{file_id}/content.
     *
     * @param string $fileid File id.
     * @return string
     */
    public function content(string $fileid): string {
        return $this->client->raw_request('GET', '/files/' . rawurlencode($fileid) . '/content');
    }

    /**
     * DELETE /v1/files/{file_id}.
     *
     * @param string $fileid File id.
     * @return array
     */
    public function delete(string $fileid): array {
        return $this->client->request('DELETE', '/files/' . rawurlencode($fileid));
    }
}

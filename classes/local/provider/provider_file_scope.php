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

namespace local_studybuddy\local\provider;

use local_studybuddy\local\course_source_access;

/**
 * Resolves provider files that are currently enabled for RAG.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_file_scope {
    /**
     * Returns active provider file mappings for the course/provider.
     *
     * @param int $courseid Course id.
     * @param string $provider Provider key.
     * @param int $userid User id, or zero for an unscoped course view.
     * @return array Scope data.
     */
    public function get_scope(int $courseid, string $provider, int $userid = 0): array {
        global $DB;

        $store = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => $provider,
        ]);

        if (!$store || empty($store->externalid) || (string)$store->status !== 'completed') {
            return [
                'store' => null,
                'externalid' => null,
                'files' => [],
                'documentids' => [],
                'externalfileids' => [],
                'vectorfileids' => [],
                'vertexragfileids' => [],
            ];
        }

        $records = $DB->get_records_sql(
            "SELECT sf.*
               FROM {local_studybuddy_store_files} sf
               JOIN {local_studybuddy_documents} d ON d.id = sf.documentid
              WHERE sf.vectorstoreid = :storeid
                AND sf.courseid = :courseid
                AND sf.status IN (:ready, :completed)
                AND d.status = :documentstatus
                AND d.enabled = :enabled",
            [
                'storeid' => $store->id,
                'courseid' => $courseid,
                'ready' => 'ready',
                'completed' => 'completed',
                'documentstatus' => 'ready',
                'enabled' => 1,
            ]
        );

        $access = new course_source_access();
        $visiblecmids = $access->get_visible_cmids($courseid, $userid);
        if ($visiblecmids !== null) {
            $records = array_filter($records, function (\stdClass $record) use ($access, $visiblecmids): bool {
                return $access->document_is_visible($record, $visiblecmids);
            });
        }

        $documentids = [];
        $externalfileids = [];
        $vectorfileids = [];
        $vertexragfileids = [];

        foreach ($records as $record) {
            $documentids[(int)$record->documentid] = (int)$record->documentid;
            if (!empty($record->externalfileid)) {
                $externalfileids[(string)$record->externalfileid] = (string)$record->externalfileid;
            }
            if (!empty($record->vectorfileid)) {
                $vectorfileid = (string)$record->vectorfileid;
                $vectorfileids[$vectorfileid] = $vectorfileid;
                $vertexid = $this->normalise_vertex_rag_file_id($vectorfileid);
                if ($vertexid !== '') {
                    $vertexragfileids[$vertexid] = $vertexid;
                }
            }
        }

        return [
            'store' => $store,
            'externalid' => (string)$store->externalid,
            'files' => array_values($records),
            'documentids' => array_values($documentids),
            'externalfileids' => array_values($externalfileids),
            'vectorfileids' => array_values($vectorfileids),
            'vertexragfileids' => array_values($vertexragfileids),
        ];
    }

    /**
     * Builds an OpenAI vector store filter for the enabled document ids.
     *
     * @param int[] $documentids Document ids.
     * @return array|null Filter payload.
     */
    public function openai_document_filter(array $documentids): ?array {
        $filters = [];
        $documentids = array_values(array_unique(array_map('intval', $documentids)));
        sort($documentids, SORT_NUMERIC);
        foreach ($documentids as $documentid) {
            if ($documentid <= 0) {
                continue;
            }
            $filters[] = [
                'type' => 'eq',
                'key' => 'documentid',
                'value' => $documentid,
            ];
        }

        if (empty($filters)) {
            return null;
        }

        if (count($filters) === 1) {
            return $filters[0];
        }

        return [
            'type' => 'or',
            'filters' => $filters,
        ];
    }

    /**
     * Builds a Gemini File Search metadata filter for enabled documents.
     *
     * The Google store contains all course documents, so the request must
     * restrict retrieval to the active document ids for the current user.
     *
     * @param int[] $documentids Document ids.
     * @return string|null Metadata filter expression.
     */
    public function google_metadata_filter(array $documentids): ?string {
        $filters = [];
        $documentids = array_values(array_unique(array_map('intval', $documentids)));
        sort($documentids, SORT_NUMERIC);
        foreach ($documentids as $documentid) {
            if ($documentid > 0) {
                $filters[] = 'moodle_documentid = ' . $documentid;
            }
        }

        return empty($filters) ? null : implode(' OR ', $filters);
    }

    /**
     * Extracts the RAG file id expected by Vertex from a full resource name.
     *
     * @param string $name Resource name.
     * @return string File id.
     */
    private function normalise_vertex_rag_file_id(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (str_contains($name, '/ragFiles/')) {
            return (string)substr($name, strrpos($name, '/') + 1);
        }

        return $name;
    }
}

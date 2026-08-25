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

/**
 * Resolves which course modules a user can access.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_source_access {
    /**
     * Returns the user-visible course module ids.
     *
     * A userid of zero means that no user-specific filtering is requested.
     * This is used by indexing and course-level administrative status views.
     *
     * @param int $courseid Course id.
     * @param int $userid User id, or zero for an unscoped course view.
     * @return array<int, bool>|null Visible cmids, or null when unscoped.
     */
    public function get_visible_cmids(int $courseid, int $userid = 0): ?array {
        if ($userid <= 0) {
            return null;
        }

        $visiblecmids = [];
        $modinfo = get_fast_modinfo($courseid, $userid);
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->uservisible) {
                $visiblecmids[(int)$cm->id] = true;
            }
        }

        return $visiblecmids;
    }

    /**
     * Checks whether a document belongs to a user-visible course module.
     *
     * Documents without a course module are allowed because they are not
     * subject to module availability restrictions.
     *
     * @param mixed $document Document row or document payload.
     * @param array|null $visiblecmids Visible cmids.
     * @return bool Whether the document can be used.
     */
    public function document_is_visible($document, ?array $visiblecmids): bool {
        if ($visiblecmids === null) {
            return true;
        }

        $cmid = is_array($document) ? ($document['cmid'] ?? 0) : ($document->cmid ?? 0);
        return empty($cmid) || isset($visiblecmids[(int)$cmid]);
    }
}

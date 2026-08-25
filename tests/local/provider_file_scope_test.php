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

use local_studybuddy\local\provider\provider_file_scope;

/**
 * Tests provider-side source filters.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\provider\provider_file_scope
 */
final class provider_file_scope_test extends \advanced_testcase {
    /**
     * Tests the Google metadata filter used for native File Search.
     *
     * @return void
     */
    public function test_google_metadata_filter(): void {
        $scope = new provider_file_scope();
        $this->assertSame(
            'moodle_documentid = 4 OR moodle_documentid = 9',
            $scope->google_metadata_filter([9, 4, 9])
        );
        $this->assertNull($scope->google_metadata_filter([]));
    }

    /**
     * Tests the OpenAI document filter shape.
     *
     * @return void
     */
    public function test_openai_document_filter(): void {
        $scope = new provider_file_scope();
        $this->assertSame([
            'type' => 'eq',
            'key' => 'documentid',
            'value' => 7,
        ], $scope->openai_document_filter([7, 7]));
    }
}

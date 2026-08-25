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

namespace local_studybuddy\output;

/**
 * Tests StudyBuddy course navigation capability handling.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\output\navigation
 */
final class navigation_test extends \advanced_testcase {
    /**
     * A review-only user can reach the Teacher studio from section navigation.
     *
     * @return void
     */
    public function test_review_capability_includes_teacher_navigation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance((int)$course->id);
        $roleid = create_role('StudyBuddy reviewer', 'studybuddyreviewer', 'StudyBuddy reviewer role');
        assign_capability('local/studybuddy:review', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        $navigation = navigation::section_nav((int)$course->id, $context, 'teacher');
        $keys = array_column($navigation['items'], 'key');

        $this->assertContains('teacher', $keys);
    }
}

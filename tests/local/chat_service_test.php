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
 * Tests temporary StudyBuddy chat storage.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\chat_service
 */
final class chat_service_test extends \advanced_testcase {
    /**
     * Temporary chats retain only the configured number of recent messages.
     *
     * @return void
     */
    public function test_session_chat_message_limit(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        set_config('maxchatmessages', 3, 'local_studybuddy');
        unset($SESSION->local_studybuddy_chats);

        $service = new chat_service();
        $chat = $service->create_chat((int)$course->id, (int)get_admin()->id);
        $method = new \ReflectionMethod(chat_service::class, 'append_session_message');
        $method->setAccessible(true);

        $method->invoke($service, (int)$chat->id, (int)get_admin()->id, 'user', 'First', [], time());
        $method->invoke($service, (int)$chat->id, null, 'assistant', 'Second', [], time());
        $method->invoke($service, (int)$chat->id, (int)get_admin()->id, 'user', 'Third', [], time());

        $history = $service->get_history((int)$chat->id, (int)get_admin()->id);
        $this->assertCount(2, $history);
        $this->assertStringContainsString('Second', $history[0]['content']);
        $this->assertStringContainsString('Third', $history[1]['content']);
    }
}

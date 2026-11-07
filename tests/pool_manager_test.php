<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_fragenfilter;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pool account access and cleanup through their public interfaces.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(pool_manager::class)]
#[CoversClass(preview_role::class)]
#[CoversClass(task\cleanup_pool_sessions::class)]
final class pool_manager_test extends \advanced_testcase {
    public function test_enrolment_grants_only_the_current_account_temporary_bank_access(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->get_plugin_generator('block_fragenfilter')->create_public_category();
        $bankcontext = \context::instance_by_id($category->contextid);
        $coursecontext = $bankcontext->get_course_context();
        $first = $DB->get_record('user', ['username' => 'fragenfilter_pool01'], '*', MUST_EXIST);
        $other = $DB->get_record('user', ['username' => 'fragenfilter_pool02'], '*', MUST_EXIST);
        pool_manager::ensure_course_enrolment((int)$first->id, (int)$coursecontext->instanceid);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(is_enrolled($coursecontext, $first, '', true));
        $this->assertFalse(is_enrolled($coursecontext, $other, '', true));
        $this->assertTrue(has_capability('moodle/question:useall', $bankcontext, $first));
        $modinfo = get_fast_modinfo($coursecontext->instanceid, $first->id);
        $this->assertTrue($modinfo->get_cm($bankcontext->instanceid)->uservisible);
        $this->assertFalse(has_capability('moodle/question:useall', $bankcontext, $other));
        $this->assertFalse(is_viewing($coursecontext, $first));
        $this->assertFalse(has_capability('moodle/course:manageactivities', $coursecontext, $first));

        pool_manager::remove_course_enrolments((int)$first->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(is_enrolled($coursecontext, $first, '', true));
        $this->assertFalse(has_capability('moodle/question:useall', $bankcontext, $first));
        $this->assertFalse(is_viewing($coursecontext, $first));
    }

    public function test_cleanup_does_not_end_an_active_session_when_an_old_session_remains(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $userid = $DB->get_field('user', 'id', ['username' => 'fragenfilter_pool01'], MUST_EXIST);
        $ids = [];
        foreach ([time() - $CFG->sessiontimeout - 60, time()] as $modified) {
            $ids[] = $DB->insert_record('sessions', (object)[
                'state' => 0, 'sid' => bin2hex(random_bytes(16)), 'userid' => $userid,
                'timecreated' => $modified, 'timemodified' => $modified, 'firstip' => '192.0.2.1', 'lastip' => '192.0.2.1',
            ]);
        }
        ob_start();
        (new task\cleanup_pool_sessions())->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('sessions', ['id' => $ids[1], 'userid' => $userid]));
        $this->assertNotSame((int)$userid, (int)pool_manager::get_free_account()->id);
    }
}

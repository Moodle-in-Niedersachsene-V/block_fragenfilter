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
 * Login, guest exclusion and capability checks for export and preview, through the public interface.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(visitor_access::class)]
final class visitor_access_test extends \advanced_testcase {
    /**
     * Creates a user and gives the export capability to a role assigned in the system context.
     *
     * @param bool $withexport Whether the user holds the export capability.
     * @return \stdClass User.
     */
    private function user_with_export(bool $withexport): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        if ($withexport) {
            $roleid = $this->getDataGenerator()->create_role();
            assign_capability('block/fragenfilter:export', CAP_ALLOW, $roleid, \context_system::instance());
            role_assign($roleid, $user->id, \context_system::instance());
        }
        return $user;
    }

    /**
     * Anonymous visitors (user 0) have no login and no export or preview rights.
     */
    public function test_anonymous_visitor_has_no_login_export_or_preview(): void {
        $this->resetAfterTest();
        $this->setUser(null);

        $this->assertFalse(visitor_access::has_real_login());
        $this->assertFalse(visitor_access::may_export());
        $this->assertFalse(visitor_access::may_preview());
    }

    /**
     * The guest account is never a real login, even when the export capability is granted to it.
     */
    public function test_guest_account_is_excluded_even_with_export_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setGuestUser();
        $guestrole = $DB->get_record('role', ['shortname' => 'guest'], '*', MUST_EXIST);
        assign_capability('block/fragenfilter:export', CAP_ALLOW, $guestrole->id, \context_system::instance(), true);

        $this->assertFalse(visitor_access::has_real_login());
        $this->assertFalse(visitor_access::may_export());
        $this->assertFalse(visitor_access::may_preview());
    }

    /**
     * A real account without the export capability may preview but not export.
     */
    public function test_real_account_without_capability_may_preview_but_not_export(): void {
        $this->resetAfterTest();
        $this->setUser($this->user_with_export(false));

        $this->assertTrue(visitor_access::has_real_login());
        $this->assertFalse(visitor_access::may_export());
        $this->assertTrue(visitor_access::may_preview());
    }

    /**
     * A real account with the export capability may export and preview.
     */
    public function test_real_account_with_capability_may_export_and_preview(): void {
        $this->resetAfterTest();
        $this->setUser($this->user_with_export(true));

        $this->assertTrue(visitor_access::may_export());
        $this->assertTrue(visitor_access::may_preview());
    }

    /**
     * A pool account is a real login: with the preview role it may export and preview.
     */
    public function test_pool_account_with_preview_role_may_export_and_preview(): void {
        $this->resetAfterTest();
        global $DB;
        // Leftover pool accounts may exist in the test database, so take the first free pool name.
        $free = array_values(array_diff(
            \block_kursfilter\pool_manager::get_pool_usernames(\block_kursfilter\pool_manager::POOL_SIZE_MAX),
            $DB->get_fieldset_select('user', 'username', 'username LIKE ?', ['kursfilter_pool%'])
        ));
        $pool = $this->getDataGenerator()->create_user([
            'username' => $free[0], 'auth' => 'manual', 'confirmed' => 1,
        ]);
        preview_role::assign_to_pool_account($pool);
        $this->setUser($pool);

        $this->assertTrue(visitor_access::has_real_login());
        $this->assertTrue(visitor_access::may_export());
        $this->assertTrue(visitor_access::may_preview());
    }
}

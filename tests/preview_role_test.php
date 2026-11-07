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
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * The preview role reaches every Kursfilter pool account.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(preview_role::class)]
#[CoversFunction('xmldb_block_fragenfilter_upgrade')]
final class preview_role_test extends \advanced_testcase {
    /**
     * Returns the usernames of all pool accounts that exist, up to the hard cap.
     *
     * @return string[] Usernames.
     */
    private function existing_pool_usernames(): array {
        global $DB;

        $usernames = \block_kursfilter\pool_manager::get_pool_usernames(\block_kursfilter\pool_manager::POOL_SIZE_MAX);
        return array_keys($DB->get_records_list('user', 'username', $usernames, '', 'username'));
    }

    /**
     * Whether the account holds the preview role in the system context.
     *
     * @param string $username Username.
     * @return bool
     */
    private function has_preview_role(string $username): bool {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'fragenfilter_preview']);
        $userid = $DB->get_field('user', 'id', ['username' => $username]);
        return $roleid && $userid
            && user_has_role_assignment($userid, $roleid, \context_system::instance()->id);
    }

    public function test_upgrade_assigns_the_preview_role_to_every_pool_account(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/fragenfilter/db/upgrade.php');
        $this->resetAfterTest();
        set_config('poolsize', 12, 'block_kursfilter');
        \block_kursfilter\pool_manager::create_pool_accounts();
        $regular = $this->getDataGenerator()->create_user();
        // Former account of the removed own pool: not a Kursfilter pool name.
        $legacy = $this->getDataGenerator()->create_user(['username' => 'questionfilter_guest01']);
        // State before the upgrade: the role is gone and no pool account holds it.
        delete_role($DB->get_field('role', 'id', ['shortname' => 'fragenfilter_preview'], MUST_EXIST));
        set_config('version', 2026101602, 'block_fragenfilter');

        xmldb_block_fragenfilter_upgrade(2026101602);

        $poolusernames = $this->existing_pool_usernames();
        $this->assertCount(12, $poolusernames);
        foreach ($poolusernames as $username) {
            $this->assertTrue($this->has_preview_role($username), $username);
        }
        $this->assertFalse($this->has_preview_role($regular->username));
        $this->assertFalse($this->has_preview_role($legacy->username));
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'fragenfilter_preview'], MUST_EXIST);
        $systemcontext = \context_system::instance();
        $capabilities = get_capabilities_from_role_on_context($DB->get_record('role', ['id' => $roleid]), $systemcontext);
        $allowed = [];
        foreach ($capabilities as $capability) {
            if ((int)$capability->permission === CAP_ALLOW) {
                $allowed[] = $capability->capability;
            }
        }
        sort($allowed);
        $this->assertSame(['block/fragenfilter:export', 'moodle/question:usemine'], $allowed);
    }

    public function test_lookalike_accounts_do_not_receive_the_preview_role(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/fragenfilter/db/upgrade.php');
        $this->resetAfterTest();
        // A self-registered lookalike is unconfirmed and not a manual account.
        $signup = $this->getDataGenerator()->create_user([
            'username' => 'kursfilter_pool49', 'auth' => 'email', 'confirmed' => 0,
        ]);
        $outside = $this->getDataGenerator()->create_user(['username' => 'kursfilter_pool99']);
        $partial = $this->getDataGenerator()->create_user(['username' => 'xkursfilter_pool01']);

        preview_role::assign_to_pool_accounts();

        foreach ([$signup, $outside, $partial] as $user) {
            $this->assertFalse($this->has_preview_role($user->username), $user->username);
        }
    }

    public function test_the_plugin_registers_no_pool_guards_of_its_own(): void {
        global $PAGE;

        $manager = \core\hook\manager::get_instance();
        foreach ([new \core\hook\after_config(), new \core\hook\output\before_http_headers($PAGE->get_renderer('core'))] as $hook) {
            foreach (iterator_to_array($manager->getListenersForEvent($hook)) as $callback) {
                $this->assertStringStartsNotWith('block_fragenfilter\\', (string)$callback, get_class($hook));
            }
        }
    }
}

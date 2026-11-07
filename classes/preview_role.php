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

/**
 * The preview role for pool accounts used by block_fragenfilter.
 *
 * The role allows the question preview and the export (ADR 0002). Both the
 * fragenfilter-owned pool accounts (fragenfilter_pool*) and the kursfilter-owned
 * accounts (kursfilter_pool*) receive this role — fragenfilter_pool* because they
 * are the primary pool used by this plugin; kursfilter_pool* for backwards
 * compatibility with installations that still share the kursfilter pool.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_role {
    /** Role shortname. */
    public const SHORTNAME = 'fragenfilter_preview';

    /**
     * Create the preview role if missing.
     *
     * The role grants moodle/question:useall and the export, so pool accounts can open
     * the question preview without receiving additional permissions. An existing role is
     * left unchanged, because administrators may have adjusted its capabilities.
     *
     * @return int Role ID.
     */
    public static function ensure(): int {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => self::SHORTNAME]);
        if ($roleid) {
            return (int)$roleid;
        }
        // Capabilities from db/access.php are not yet in the database during installation.
        update_capabilities('block_fragenfilter');
        $roleid = create_role(
            get_string('role_preview_name', 'block_fragenfilter'),
            self::SHORTNAME,
            get_string('role_preview_desc', 'block_fragenfilter'),
            ''
        );
        $systemcontext = \context_system::instance();
        // CONTEXT_MODULE is required for Moodle 5+ mod_qbank: question_require_capability_on()
        // checks the capability in the module context of the question's question bank.
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_MODULE]);
        // viewmine: allows question_visibility::sql() to find questions for this pool account.
        // useall:   allows opening the preview for any question (pool accounts have no 'own' questions, so usemine would always deny access).
        // export:   allows downloading the question export file.
        assign_capability('moodle/question:viewmine', CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('moodle/question:useall',  CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('block/fragenfilter:view', CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('block/fragenfilter:export', CAP_ALLOW, $roleid, $systemcontext->id, true);
        return (int)$roleid;
    }

    /**
     * Whether the account is any pool account recognised by block_fragenfilter.
     *
     * Accepts both fragenfilter_pool* (own pool) and kursfilter_pool* (legacy shared pool).
     * Pool accounts are confirmed manual accounts; a self-registered account with a matching
     * username would be unconfirmed or use a different auth method.
     *
     * @param \stdClass $user User record.
     * @return bool
     */
    public static function is_pool_account(\stdClass $user): bool {
        if ($user->auth !== 'manual' || empty($user->confirmed) || !empty($user->deleted)) {
            return false;
        }
        // Own fragenfilter pool accounts.
        if (\block_fragenfilter\pool_manager::is_pool_account($user->username)) {
            return true;
        }
        // Kursfilter pool accounts (backwards compatibility).
        if (class_exists('\block_kursfilter\pool_manager')) {
            return in_array(
                $user->username,
                \block_kursfilter\pool_manager::get_pool_usernames(\block_kursfilter\pool_manager::POOL_SIZE_MAX),
                true
            );
        }
        return false;
    }

    /**
     * Give the role to a pool account; other accounts and accounts holding the role stay unchanged.
     *
     * @param \stdClass $user User record.
     */
    public static function assign_to_pool_account(\stdClass $user): void {
        if (!self::is_pool_account($user)) {
            return;
        }
        $roleid = self::ensure();
        $contextid = \context_system::instance()->id;
        if (!user_has_role_assignment($user->id, $roleid, $contextid)) {
            role_assign($roleid, $user->id, $contextid);
        }
    }

    /**
     * Give the role to all existing pool accounts, up to the hard cap of the pool size.
     *
     * Covers both fragenfilter_pool* (own pool) and kursfilter_pool* (legacy shared pool).
     */
    public static function assign_to_pool_accounts(): void {
        global $DB;

        self::ensure();

        // Own fragenfilter pool accounts.
        $usernames = \block_fragenfilter\pool_manager::get_pool_usernames(
            \block_fragenfilter\pool_manager::POOL_SIZE_MAX
        );

        // Legacy kursfilter pool accounts (backwards compatibility).
        if (class_exists('\block_kursfilter\pool_manager')) {
            $usernames = array_unique(array_merge(
                $usernames,
                \block_kursfilter\pool_manager::get_pool_usernames(\block_kursfilter\pool_manager::POOL_SIZE_MAX)
            ));
        }

        foreach ($DB->get_records_list('user', 'username', $usernames) as $user) {
            self::assign_to_pool_account($user);
        }
    }
}

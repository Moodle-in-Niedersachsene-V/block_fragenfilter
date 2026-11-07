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
 * Pool manager for the question preview of block_fragenfilter.
 *
 * Manages a small set of dedicated pool accounts that let anonymous visitors
 * open a Moodle question preview without a real login. Unlike the kursfilter
 * pool, there is intentionally NO per-IP lock: multiple browser tabs from the
 * same visitor may each open a different preview simultaneously.
 *
 * Pool accounts are named fragenfilter_pool01 … fragenfilter_pool<N> and are
 * created automatically during plugin installation and upgrade.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pool_manager {
    /** Default number of pool accounts. */
    const POOL_SIZE_DEFAULT = 10;

    /** Hard cap on the pool size. */
    const POOL_SIZE_MAX = 50;

    /** Username prefix for the fragenfilter pool accounts. */
    const USERNAME_PREFIX = 'fragenfilter_pool';

    /**
     * Return the configured pool size.
     *
     * @return int
     */
    public static function get_pool_size(): int {
        $configured = (int)get_config('block_fragenfilter', 'poolsize');
        if ($configured < 1) {
            $configured = self::POOL_SIZE_DEFAULT;
        }
        return min($configured, self::POOL_SIZE_MAX);
    }

    /**
     * Return the canonical usernames of all pool accounts.
     *
     * @param int|null $size Pool size; uses the configured value when null.
     * @return string[]
     */
    public static function get_pool_usernames(?int $size = null): array {
        $names = [];
        for ($i = 1; $i <= ($size ?? self::get_pool_size()); $i++) {
            $names[] = self::USERNAME_PREFIX . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        }
        return $names;
    }

    /**
     * Whether the username belongs to a fragenfilter pool account.
     *
     * @param string $username Username.
     * @return bool
     */
    public static function is_pool_account(string $username): bool {
        return in_array($username, self::get_pool_usernames(self::POOL_SIZE_MAX), true);
    }

    /**
     * Create missing pool accounts; existing ones are left unchanged.
     *
     * @return int Number of newly created accounts.
     */
    public static function create_pool_accounts(): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $created = 0;
        foreach (self::get_pool_usernames() as $username) {
            if ($DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
                continue;
            }

            $user               = new \stdClass();
            $user->auth         = 'manual';
            $user->confirmed    = 1;
            $user->mnethostid   = $CFG->mnet_localhost_id;
            $user->username     = $username;
            $user->password     = hash_internal_user_password(self::generate_password());
            $user->firstname    = 'Fragenfilter';
            $user->lastname     = 'Pool ' . ltrim(substr($username, strlen(self::USERNAME_PREFIX)), '0') ?: '0';
            $user->email        = $username . '@fragenfilter.invalid';
            $user->emailstop    = 1;
            $user->lang         = get_string_manager()->translation_exists('de') ? 'de' : $CFG->lang;
            $user->timecreated  = time();
            $user->timemodified = time();
            $user->description  = 'Automatisch angelegtes Pool-Konto für block_fragenfilter. Nicht löschen.';

            user_create_user($user, false, false);
            $created++;
        }
        return $created;
    }

    /**
     * Return the first free pool account (not occupied by an active session).
     *
     * No IP lock — multiple visitors may each get their own pool account.
     *
     * @return \stdClass|null User record, or null when all accounts are busy.
     */
    public static function get_free_account(): ?\stdClass {
        global $DB;

        $occupied = array_column(self::get_active_sessions(), 'userid');
        foreach (self::get_pool_usernames() as $username) {
            $user = $DB->get_record(
                'user',
                ['username' => $username, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
            if (
                $user && empty($user->suspended) && preview_role::is_pool_account($user)
                    && !in_array((int)$user->id, array_map('intval', $occupied), true)
            ) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Atomically occupy a free account and log the current visitor in as that account.
     *
     * Returns the occupied account on success, or null when the pool is full.
     *
     * @return \stdClass|null
     * @throws \moodle_exception When the pool lock times out.
     */
    public static function occupy_free_account(): ?\stdClass {
        $factory = \core\lock\lock_config::get_lock_factory('block_fragenfilter_pool');
        $lock = $factory->get_lock('occupancy', 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'block_fragenfilter');
        }
        try {
            $account = self::get_free_account();
            if (!$account) {
                return null;
            }
            // Destroy any lingering sessions of this pool account before logging in.
            \core\session\manager::destroy_user_sessions($account->id);
            complete_user_login(get_complete_user_data('id', $account->id));
            return $account;
        } finally {
            $lock->release();
        }
    }

    /**
     * Return sessions that are currently occupying a fragenfilter pool account.
     *
     * @return \stdClass[]
     */
    private static function get_active_sessions(): array {
        global $DB, $CFG;

        [$insql, $params] = $DB->get_in_or_equal(
            self::get_pool_usernames(self::POOL_SIZE_MAX),
            SQL_PARAMS_NAMED
        );
        $params['cutoff'] = time() - $CFG->sessiontimeout;
        return $DB->get_records_sql(
            "SELECT s.id, s.userid
               FROM {sessions} s
               JOIN {user} u ON u.id = s.userid
              WHERE u.username $insql AND u.deleted = 0 AND s.timemodified >= :cutoff",
            $params
        );
    }

    /**
     * Name of the course category whose courses pool accounts may be enrolled in.
     * Only courses inside this category (or its sub-categories) are eligible.
     */
    const POOL_ENROL_CATEGORY = 'Aufgabenpool';

    /**
     * Ensure a pool account is enrolled in the given course so that preview.php
     * can load its course context without blocking the user.
     *
     * Enrolment is only granted when the course belongs to the category named
     * POOL_ENROL_CATEGORY (or one of its sub-categories). Courses outside that
     * category are not touched — the preview simply won't open for them.
     *
     * Uses the 'manual' enrol plugin with the student role. The enrolment is
     * time-limited to the session timeout so it expires automatically; the
     * cleanup_pool_sessions task also calls remove_course_enrolments() on
     * session end.
     *
     * Safe to call repeatedly — skips enrolment if the user is already enrolled.
     *
     * @param int $userid   Pool account user ID.
     * @param int $courseid Course that contains the mod_qbank activity.
     */
    public static function ensure_course_enrolment(int $userid, int $courseid): void {
        global $DB, $CFG;

        // Only enrol in courses that belong to the Aufgabenpool category tree.
        if (!self::course_in_pool_category($courseid)) {
            return;
        }

        require_once($CFG->dirroot . '/enrol/manual/lib.php');

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return;
        }

        // Use the dedicated preview role; never grant teacher or editing rights.
        $roleid = preview_role::ensure();
        $coursecontext = \context_course::instance($courseid);
        foreach (['moodle/course:view', 'moodle/course:viewhiddencourses', 'moodle/course:viewhiddenactivities'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $coursecontext->id, true);
        }

        // Find or create the manual enrol instance for this course.
        $instance = $DB->get_record(
            'enrol',
            ['enrol' => 'manual', 'courseid' => $courseid],
            '*',
            IGNORE_MISSING
        );
        if (!$instance) {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $instanceid = $enrol->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        }
        if (!$instance) {
            return;
        }

        // If already enrolled with the correct role, just refresh timeend and return.
        $existing = $DB->get_record(
            'user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $userid],
            'id',
            IGNORE_MISSING
        );
        $timeend = time() + (int)$CFG->sessiontimeout;
        if ($existing) {
            // Check whether the current role assignment matches the target role.
            $ctx = \context_course::instance($courseid);
            $hasrole = $roleid && $DB->record_exists(
                'role_assignments',
                ['roleid' => $roleid, 'userid' => $userid, 'contextid' => $ctx->id]
            );
            if ($hasrole) {
                // Role is correct — only refresh the enrolment timeend.
                $DB->set_field('user_enrolments', 'timeend', $timeend, ['id' => $existing->id]);
                return;
            }
            // Wrong role (e.g. still enrolled as student from an earlier build):
            // unenrol first so enrol_user() re-assigns with the correct role.
            $enrol->unenrol_user($instance, $userid);
        }

        // Enrol with the target role and a timeend matching the session timeout.
        $enrol->enrol_user($instance, $userid, $roleid, time(), $timeend);
    }

    /**
     * Whether a course belongs to the Aufgabenpool category tree.
     *
     * Walks up the category hierarchy from the course's own category until it
     * finds a category named POOL_ENROL_CATEGORY, or reaches the root.
     *
     * @param int $courseid
     * @return bool
     */
    private static function course_in_pool_category(int $courseid): bool {
        global $DB;

        $categoryid = $DB->get_field('course', 'category', ['id' => $courseid]);
        if (!$categoryid) {
            return false;
        }

        // Walk up the category tree.
        $visited = [];
        while ($categoryid && !isset($visited[$categoryid])) {
            $visited[$categoryid] = true;
            $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id,name,parent', IGNORE_MISSING);
            if (!$cat) {
                break;
            }
            if (trim($cat->name) === self::POOL_ENROL_CATEGORY) {
                return true;
            }
            $categoryid = (int)$cat->parent;
        }
        return false;
    }

    /**
     * Remove all temporary manual course enrolments for a pool account.
     * Called by cleanup_pool_sessions when the pool slot is freed.
     *
     * @param int $userid Pool account user ID.
     */
    public static function remove_course_enrolments(int $userid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/enrol/manual/lib.php');
        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return;
        }

        $enrolments = $DB->get_records_sql(
            "SELECT ue.id, ue.enrolid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND e.enrol = 'manual'",
            ['uid' => $userid]
        );
        foreach ($enrolments as $ue) {
            $instance = $DB->get_record('enrol', ['id' => $ue->enrolid], '*', IGNORE_MISSING);
            if ($instance) {
                $enrol->unenrol_user($instance, $userid);
            }
        }
    }

    /**
     * Generate a random password that nobody knows; pool accounts authenticate
     * only through the automatic login in occupy_free_account().
     *
     * @return string
     */
    private static function generate_password(): string {
        return random_string(20) . 'Aa1!';
    }
}

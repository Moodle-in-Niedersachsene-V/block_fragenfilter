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

namespace block_fragenfilter\task;

/**
 * Scheduled task: destroy idle sessions of fragenfilter pool accounts.
 *
 * Guests who open a question preview and then close the tab without clicking
 * "Abmelden" leave their pool-account session open. This task destroys those
 * sessions after they have been idle longer than $CFG->sessiontimeout, freeing
 * the pool slots for the next visitor.
 *
 * The task runs every 15 minutes by default (configurable in Site admin →
 * Scheduled tasks). In practice Moodle's own session GC runs first and removes
 * the database records; this task ensures the pool slots are visibly free even
 * when the site is lightly loaded and Moodle's GC has not run yet.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_pool_sessions extends \core\task\scheduled_task {
    /**
     * Human-readable name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_pool_sessions', 'block_fragenfilter');
    }

    /**
     * Destroy idle fragenfilter pool sessions.
     */
    public function execute(): void {
        global $DB, $CFG;

        $factory = \core\lock\lock_config::get_lock_factory('block_fragenfilter_pool');
        $lock = $factory->get_lock('occupancy', 10);
        if (!$lock) {
            throw new \moodle_exception('error_pool_lock', 'block_fragenfilter');
        }
        try {
            $this->cleanup_idle_accounts();
        } finally {
            $lock->release();
        }
    }

    /**
     * Rechecks idle accounts while holding the same lock as pool occupancy.
     */
    private function cleanup_idle_accounts(): void {
        global $DB, $CFG;
        $usernames  = \block_fragenfilter\pool_manager::get_pool_usernames(
            \block_fragenfilter\pool_manager::POOL_SIZE_MAX
        );
        if (empty($usernames)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($usernames, SQL_PARAMS_NAMED);
        // Sessions that have been idle longer than the timeout are considered abandoned.
        $params['cutoff'] = time() - $CFG->sessiontimeout;
        $params['activecutoff'] = $params['cutoff'];
        $idle = $DB->get_records_sql(
            "SELECT s.id, s.userid, u.username
               FROM {sessions} s
               JOIN {user} u ON u.id = s.userid
              WHERE u.username $insql AND u.deleted = 0 AND u.auth = 'manual' AND u.confirmed = 1
                AND s.timemodified < :cutoff
                AND NOT EXISTS (SELECT 1 FROM {sessions} active
                                 WHERE active.userid = u.id AND active.timemodified >= :activecutoff)",
            $params
        );

        if (empty($idle)) {
            mtrace('block_fragenfilter: no idle pool sessions to clean up.');
            return;
        }

        $destroyed = 0;
        foreach ($idle as $session) {
            \core\session\manager::destroy_user_sessions((int)$session->userid);
            // Remove temporary course enrolments that were created in guest_preview.php
            // so that pool accounts are not left enrolled in courses indefinitely.
            \block_fragenfilter\pool_manager::remove_course_enrolments((int)$session->userid);
            mtrace(sprintf(
                'block_fragenfilter: destroyed idle session for pool account %s (session id %s).',
                $session->username,
                $session->id
            ));
            $destroyed++;
        }
        mtrace(sprintf('block_fragenfilter: cleaned up %d idle pool session(s).', $destroyed));
    }
}

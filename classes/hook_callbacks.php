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
 * Hook callbacks for block_fragenfilter.
 *
 * Restricts pool users to the question search page and suppresses messaging
 * for their session. Delegates entirely to block_kursfilter when that plugin
 * is installed — its own hook_callbacks handles pool-account restrictions.
 * This callback only activates when block_fragenfilter is installed without
 * block_kursfilter (standalone mode).
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Standalone pool-account guard, fired via \core\hook\after_config.
     *
     * @param \core\hook\after_config $hook Hook instance (unused, signature required).
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG, $USER;

        // Only act on real logged-in users.
        if (!isloggedin() || isguestuser()) {
            return;
        }

        // If block_kursfilter is installed its own hook already handles pool-account
        // restrictions; avoid duplicate work.
        if (class_exists('\block_kursfilter\pool_manager')) {
            return;
        }

        // Standalone guard: accept fragenfilter_pool* (own pool) and legacy
        // kursfilter_pool*/kursfilter_guest* prefixes.
        $username = $USER->username ?? '';
        $ispool = (
            strpos($username, 'fragenfilter_pool') === 0 ||
            strpos($username, 'kursfilter_pool') === 0 ||
            strpos($username, 'kursfilter_guest') === 0
        );
        if (!$ispool) {
            return;
        }

        // Suppress messaging icon for this session.
        $CFG->messaging = false;

        // Redirect blocked paths to site home.
        $blockedprefixes = [
            '/user/profile.php',
            '/user/edit.php',
            '/user/preferences.php',
            '/user/managefiles.php',
            '/message/',
            '/calendar/',
            '/grade/report/overview/',
            '/grade/report/user/',
            '/badges/',
            '/blog/',
            '/my/',
            '/user/files.php',
        ];

        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $wwwroot = rtrim(parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '', '/');
        if ($wwwroot !== '' && strpos($script, $wwwroot) === 0) {
            $script = substr($script, strlen($wwwroot));
        }

        foreach ($blockedprefixes as $prefix) {
            if (strpos($script, $prefix) === 0) {
                header('Location: ' . $CFG->wwwroot . '/');
                exit;
            }
        }
    }
}

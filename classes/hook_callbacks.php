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
 * for their session, independently of other installed plugins.
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
        global $CFG, $USER, $SCRIPT;

        // Only act on real logged-in users.
        if (!isloggedin() || isguestuser()) {
            return;
        }

        if (!preview_role::is_pool_account($USER)) {
            return;
        }

        // Suppress messaging icon for this session.
        $CFG->messaging = false;

        // Redirect blocked paths to site home.
        $blockedprefixes = [
            '/login/change_password.php',
            '/user/view.php',
            '/user/editadvanced.php',
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

        $script = $SCRIPT ?? '';
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

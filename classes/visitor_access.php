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
 * Decides what the current visitor may do with login-bound features.
 *
 * Login, guest exclusion and capability are combined here only. Pool accounts count as real logins.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class visitor_access {
    /**
     * Whether the visitor is logged in with a real account, not anonymously and not as the guest account.
     *
     * @return bool True for a real login, including a pool account.
     */
    public static function has_real_login(): bool {
        return isloggedin() && !isguestuser();
    }

    /**
     * Whether the visitor may export questions: a real login with the export capability.
     *
     * @return bool True if the export is allowed.
     */
    public static function may_export(): bool {
        return self::has_real_login()
            && has_capability('block/fragenfilter:export', \context_system::instance());
    }

    /**
     * Whether the visitor may open the question preview: a real login.
     *
     * @return bool True if the preview is allowed.
     */
    public static function may_preview(): bool {
        return self::has_real_login();
    }
}

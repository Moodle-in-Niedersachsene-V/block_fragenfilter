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

/**
 * Post-installation steps for block_fragenfilter.
 *
 * Creates the own fragenfilter pool accounts (fragenfilter_pool01-10), sets up the
 * preview role, and assigns it to the dedicated Fragenfilter pool accounts.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs after the plugin is installed.
 */
function xmldb_block_fragenfilter_install(): void {
    // Create the fragenfilter-owned pool accounts if they don't exist yet.
    \block_fragenfilter\pool_manager::create_pool_accounts();
    // Assign the preview role to the dedicated Fragenfilter pool accounts.
    \block_fragenfilter\preview_role::assign_to_pool_accounts();
}

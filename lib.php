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
 * Library functions for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Download handler for export files.
 *
 * Called automatically by Moodle at
 * /pluginfile.php/.../block_fragenfilter/export/...
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context File context.
 * @param string $filearea File area.
 * @param array $args Remaining path components.
 * @param bool $forcedownload Whether to force the download.
 * @param array $options Additional file serving options.
 * @return void
 */
function block_fragenfilter_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
): void {
    global $USER, $SESSION;

    require_login();

    if ($filearea !== 'export' || $context->contextlevel !== CONTEXT_SYSTEM) {
        send_file_not_found();
    }

    if (!\block_fragenfilter\visitor_access::may_export()) {
        send_file_not_found();
    }

    $itemid = array_shift($args);
    if ((int)$itemid !== (int)$USER->id || count($args) !== 1) {
        send_file_not_found();
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'block_fragenfilter', 'export', $itemid, $filepath, $filename);

    if (
        !$file || (int)$file->get_userid() !== (int)$USER->id || $file->is_directory() ||
        empty($SESSION->fragenfilter_export_token) ||
        !hash_equals($SESSION->fragenfilter_export_token, (string)$file->get_source())
    ) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, true, $options);
}

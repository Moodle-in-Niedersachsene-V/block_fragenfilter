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

use block_fragenfilter\export\writer;

/**
 * Authorises selected questions and stores one visitor's export.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_export {
    /**
     * Returns the enabled, supported export formats.
     *
     * @return string[] Formats.
     */
    public static function enabled_formats(): array {
        $config = get_config('block_fragenfilter', 'exportformats');
        if ($config === false) {
            return ['xml', 'csv', 'gift'];
        }
        $formats = is_array($config) ? array_keys(array_filter($config)) : explode(',', $config);
        return array_values(array_intersect(['xml', 'csv', 'gift'], $formats));
    }

    /**
     * Returns the writer for a supported format.
     *
     * @param string $format Format: xml, csv or gift.
     * @return writer The writer.
     * @throws \invalid_parameter_exception For an unknown format.
     */
    public static function writer(string $format): writer {
        return match ($format) {
            'xml' => new export\xml_writer(),
            'csv' => new export\csv_writer(),
            'gift' => new export\gift_writer(),
            default => throw new \invalid_parameter_exception('Unknown export format.'),
        };
    }

    /**
     * Creates an export after the external API has checked login and export capability.
     *
     * @param string $questionids Selected question IDs.
     * @param string $format Requested format.
     * @param int $contextid Caller context.
     * @return array File URL and metadata.
     */
    public static function create(string $questionids, string $format, int $contextid): array {
        global $DB, $USER, $SESSION;

        if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $questionids)) {
            throw new \invalid_parameter_exception('Expected positive question IDs.');
        }
        $ids = array_unique(array_map('intval', explode(',', $questionids)));
        if (count($ids) > 200) {
            throw new \invalid_parameter_exception('At most 200 questions may be exported.');
        }
        if (!in_array($format, self::enabled_formats(), true)) {
            throw new \moodle_exception('exportformatdisabled', 'block_fragenfilter');
        }
        [$from, $args] = question_visibility::visible_query('all', $contextid);
        [$insql, $idargs] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'exportid');
        $allowed = $DB->get_fieldset_sql(
            "SELECT q.id $from AND q.id $insql",
            $args + $idargs
        );
        if (count($allowed) !== count($ids)) {
            throw new \moodle_exception('invalidquestion', 'block_fragenfilter');
        }

        $writer = self::writer($format);
        $content = $writer->write($ids);
        $filename = 'questions_' . date('Ymd_His') . '.' . $writer->extension();

        // Store the file in Moodle's temporary area and return its URL.
        $fs = get_file_storage();
        $sysctx = \context_system::instance();
        $fs->delete_area_files($sysctx->id, 'block_fragenfilter', 'export', (int)$USER->id);

        // Pool accounts are reused; an export belongs to this browser session as well as its account.
        if (empty($SESSION->fragenfilter_export_token)) {
            $SESSION->fragenfilter_export_token = bin2hex(random_bytes(16));
        }
        $fileinfo = [
            'contextid' => $sysctx->id,
            'userid' => $USER->id,
            'source' => $SESSION->fragenfilter_export_token,
            'component' => 'block_fragenfilter',
            'filearea' => 'export',
            'itemid' => (int)$USER->id,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $fs->create_file_from_string($fileinfo, $content);
        $url = \moodle_url::make_pluginfile_url(
            $sysctx->id,
            'block_fragenfilter',
            'export',
            (int)$USER->id,
            '/',
            $filename
        )->out(false);

        return [
            'success' => true,
            'url' => $url,
            'filename' => $filename,
            'mimetype' => $writer->mimetype(),
            'count' => count($ids),
        ];
    }
}

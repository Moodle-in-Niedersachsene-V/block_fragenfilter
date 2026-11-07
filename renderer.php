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
 * Renderer for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Question bank filter renderer.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_fragenfilter_renderer extends plugin_renderer_base {
    /**
     * Renders the block content from the Mustache template.
     *
     * @param int $blockid Block instance ID.
     * @param bool $canexport Whether the user can export.
     * @return string Rendered HTML.
     */
    public function render_block(int $blockid, bool $canexport = false): string {
        // Configurable difficulty levels from the administration settings.
        $diffraw = get_config('block_fragenfilter', 'difficulty_levels') ?? "Leicht\nMittel\nSchwer";
        $difficulties = array_filter(array_map('trim', explode("\n", $diffraw)));

        // Export buttons are always rendered in the DOM (initially display:none).
        // JS shows them through refreshCapabilities(), including after pool login.
        $templatedata = [
            'blockid'      => $blockid,
            'difficulties' => array_values($difficulties),
            'scope_all'    => (get_config('block_fragenfilter', 'searchscope') ?: 'all') === 'all',
        ];

        return $this->render_from_template('block_fragenfilter/block', $templatedata);
    }
}

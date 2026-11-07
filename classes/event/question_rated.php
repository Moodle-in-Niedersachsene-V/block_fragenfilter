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

namespace block_fragenfilter\event;

/**
 * A visitor rated a question; the cookie hash is never logged.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_rated extends \core\event\base {
    /**
     * Initialises the event's data contract.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'block_fragenfilter_ratings';
    }

    /**
     * Returns the event name.
     *
     * @return string Localised event name.
     */
    public static function get_name(): string {
        return get_string('event_question_rated', 'block_fragenfilter');
    }

    /**
     * Describes the rating without its cookie hash or question text.
     *
     * @return string Audit description.
     */
    public function get_description(): string {
        return "The visitor rated question '" . $this->other['questionid'] . "' with '" . $this->other['stars'] . "' stars.";
    }

    /**
     * Ratings are not part of question backups.
     *
     * @return bool False.
     */
    public static function get_other_mapping(): bool {
        return false;
    }
}

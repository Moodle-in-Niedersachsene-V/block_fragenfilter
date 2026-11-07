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
 * A reviewer accepted or rejected a tag suggestion.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string status: New suggestion status, 'applied' or 'rejected'.
 * }
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class suggestion_reviewed extends \core\event\base {
    /**
     * Initialises the event's data contract.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'block_fragenfilter_tag_pending';
    }

    /**
     * Returns the event name.
     *
     * @return string Localised event name.
     */
    public static function get_name(): string {
        return get_string('event_suggestion_reviewed', 'block_fragenfilter');
    }

    /**
     * Describes the review without question text or tags.
     *
     * @return string Audit description.
     */
    public function get_description(): string {
        return "The user with id '$this->userid' reviewed the tag suggestion with id '$this->objectid'"
            . " with outcome '" . $this->other['status'] . "'.";
    }

    /**
     * Returns the review page URL.
     *
     * @return \moodle_url Review page.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/blocks/fragenfilter/review.php');
    }

    /**
     * Validates the event data.
     *
     * @throws \coding_exception If the outcome is missing or unknown.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['status']) || !in_array($this->other['status'], ['applied', 'rejected'], true)) {
            throw new \coding_exception('The \'status\' value must be \'applied\' or \'rejected\'.');
        }
    }

    /**
     * Returns no mapping: the status is a plain value.
     *
     * @return bool False.
     */
    public static function get_other_mapping(): bool {
        return false;
    }
}

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
 * Typed filters for a question search.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_filters {
    /**
     * Holds the validated search filters.
     *
     * Free text is matched against question name and tag names. Empty lists mean "no restriction".
     *
     * @param string $text Free-text term.
     * @param int[] $categoryids Question category IDs.
     * @param string[] $types Question type names.
     * @param string $difficulty Difficulty tag.
     * @param string[] $tags Required tag names.
     * @param string $scope Search scope.
     * @param int $limit Maximum number of results.
     */
    public function __construct(
        /** @var string Free-text term. */
        public readonly string $text = '',
        /** @var int[] Category IDs; empty means all categories. */
        public readonly array $categoryids = [],
        /** @var string[] Question type names; empty means all types. */
        public readonly array $types = [],
        /** @var string Difficulty level, applied as a tag. */
        public readonly string $difficulty = '',
        /** @var string[] Tag names, all of which a question must carry. */
        public readonly array $tags = [],
        /** @var string Search scope: all, course or system. */
        public readonly string $scope = 'all',
        /** @var int Maximum number of results. */
        public readonly int $limit = 200,
    ) {
    }

    /**
     * Builds filters from the comma-separated web service inputs.
     *
     * @param string $text Free-text term.
     * @param string $categories Comma-separated positive category IDs.
     * @param string $types Comma-separated question types.
     * @param string $difficulty Difficulty tag.
     * @param string $tags Comma-separated tags.
     * @param string $scope Search scope.
     * @param int $limit Requested limit.
     * @param int $maxlimit Configured maximum; out-of-range limits fall back to it.
     * @return self Filters.
     * @throws \invalid_parameter_exception If the category list is malformed.
     */
    public static function from_input(
        string $text,
        string $categories,
        string $types,
        string $difficulty,
        string $tags,
        string $scope,
        int $limit,
        int $maxlimit,
    ): self {
        $categoryids = [];
        if (!empty($categories)) {
            if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $categories)) {
                throw new \invalid_parameter_exception('Expected positive category IDs.');
            }
            $categoryids = array_map('intval', explode(',', $categories));
        }
        $typelist = array_values(array_filter(array_map('trim', explode(',', $types))));
        $taglist = array_values(array_filter(array_map('trim', explode(',', $tags))));
        if ($limit < 1 || $limit > $maxlimit) {
            $limit = $maxlimit;
        }
        return new self($text, $categoryids, $typelist, $difficulty, $taglist, $scope, $limit);
    }
}

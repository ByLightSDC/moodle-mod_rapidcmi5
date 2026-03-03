<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_rapidcmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_rapidcmi5\player_manager;

class list_player_versions extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'offset' => new external_value(PARAM_INT, 'Offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Limit', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(int $offset = 0, int $limit = 50): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        $versions = player_manager::list_player_versions($params['offset'], $params['limit']);
        $total = player_manager::count_player_versions();

        $items = [];
        foreach ($versions as $v) {
            $items[] = [
                'id' => (int) $v->id,
                'version' => $v->version,
                'sha256hash' => $v->sha256hash ?? '',
                'timecreated' => (int) $v->timecreated,
            ];
        }

        return [
            'versions' => $items,
            'total' => $total,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'versions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Player version ID'),
                    'version' => new external_value(PARAM_TEXT, 'Version string'),
                    'sha256hash' => new external_value(PARAM_TEXT, 'ZIP hash'),
                    'timecreated' => new external_value(PARAM_INT, 'Upload timestamp'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total count'),
        ]);
    }
}

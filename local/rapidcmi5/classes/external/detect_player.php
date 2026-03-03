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
use core_external\external_single_structure;
use core_external\external_value;
use local_rapidcmi5\player_manager;

class detect_player extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionid' => new external_value(PARAM_INT, 'Library package version ID (0 if using cmid)', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module ID (0 if using versionid)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $versionid = 0, int $cmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'versionid' => $versionid,
            'cmid' => $cmid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        if ($params['versionid'] > 0) {
            $detection = player_manager::detect_player_in_library($params['versionid']);
        } else if ($params['cmid'] > 0) {
            $detection = player_manager::detect_player_in_activity($params['cmid']);
        } else {
            throw new \invalid_parameter_exception('Either versionid or cmid must be provided');
        }

        return [
            'is_rapidcmi5' => $detection->is_rapidcmi5,
            'player_version' => $detection->player_version ?? '',
            'au_count' => $detection->au_count,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'is_rapidcmi5' => new external_value(PARAM_BOOL, 'Whether this is a RapidCMI5 package'),
            'player_version' => new external_value(PARAM_TEXT, 'Detected player version (empty if unknown)'),
            'au_count' => new external_value(PARAM_INT, 'Number of AUs found'),
        ]);
    }
}

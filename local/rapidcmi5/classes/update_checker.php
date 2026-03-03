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

namespace local_rapidcmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Checks for available player and content updates for cmi5 activities.
 */
class update_checker {

    /**
     * Check a single activity for available updates.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass Update status.
     */
    public static function check_activity(int $cmid): \stdClass {
        global $DB;

        $result = new \stdClass();
        $result->has_player_update = false;
        $result->has_content_update = false;
        $result->current_player = '';
        $result->latest_player = '';
        $result->current_content_version = '';
        $result->latest_content_version = '';
        $result->is_rapidcmi5 = false;

        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return $result;
        }

        // Check content version via deployment tracking.
        $deployment = $DB->get_record('local_rapidcmi5_deployments', ['cmid' => $cmid]);
        if ($deployment) {
            $result->is_rapidcmi5 = true;
            $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $deployment->projectid]);
            if ($project && $project->currentversionid) {
                $currentver = $DB->get_record('local_rapidcmi5_versions', ['id' => $deployment->versionid]);
                $latestver = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);

                if ($currentver) {
                    $result->current_content_version = $currentver->versionnumber;
                }
                if ($latestver) {
                    $result->latest_content_version = $latestver->versionnumber;
                }
                if ($currentver && $latestver && $deployment->versionid != $project->currentversionid) {
                    $result->has_content_update = true;
                }
            }
        }

        // Check player version.
        $latestplayer = player_manager::get_latest_player_version();
        if (!$latestplayer) {
            return $result;
        }
        $result->latest_player = $latestplayer->version;

        // Detect current player version in the activity's package.
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance]);
        if (!$instance) {
            return $result;
        }

        if (!empty($instance->packageversionid)) {
            // Library-based activity.
            $detection = player_manager::detect_player_in_library((int) $instance->packageversionid);
        } else {
            // Ad-hoc uploaded activity.
            $detection = player_manager::detect_player_in_activity($cmid);
        }

        if ($detection->is_rapidcmi5) {
            $result->is_rapidcmi5 = true;
            $result->current_player = $detection->player_version ?? '';

            if (!empty($detection->player_version) && $detection->player_version !== $latestplayer->version) {
                $result->has_player_update = true;
            } else if (empty($detection->player_version)) {
                // Unknown version (legacy) — always offer upgrade.
                $result->has_player_update = true;
                $result->current_player = 'unknown';
            }
        }

        return $result;
    }

    /**
     * Batch check all cmi5 activities in a course.
     *
     * @param int $courseid Course ID.
     * @return array Keyed by cmid => update status.
     */
    public static function check_course(int $courseid): array {
        global $DB;

        $results = [];

        // Get all cmi5 activities in this course.
        $modinfo = get_fast_modinfo($courseid);
        if (!isset($modinfo->instances['cmi5'])) {
            return $results;
        }

        // Pre-fetch all deployments for this course.
        $deployments = $DB->get_records('local_rapidcmi5_deployments', ['courseid' => $courseid], '', '*');
        $deploymentsbycmid = [];
        foreach ($deployments as $d) {
            $deploymentsbycmid[$d->cmid] = $d;
        }

        $latestplayer = player_manager::get_latest_player_version();

        foreach ($modinfo->instances['cmi5'] as $cminfo) {
            if (isset($deploymentsbycmid[$cminfo->id])) {
                // Managed activity: lightweight check via deployment records.
                $deployment = $deploymentsbycmid[$cminfo->id];
                $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $deployment->projectid]);

                $status = new \stdClass();
                $status->has_player_update = false;
                $status->has_content_update = false;
                $status->is_rapidcmi5 = true;

                // Content update check.
                if ($project && $project->currentversionid && $deployment->versionid != $project->currentversionid) {
                    $currentver = $DB->get_record('local_rapidcmi5_versions', ['id' => $deployment->versionid]);
                    $latestver = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);
                    $status->has_content_update = true;
                    $status->current_content_version = $currentver ? $currentver->versionnumber : '?';
                    $status->latest_content_version = $latestver ? $latestver->versionnumber : '?';
                }

                // Player update: flag if we have any player versions uploaded.
                // Full detection is expensive; for course page we just flag all managed activities.
                if ($latestplayer) {
                    $status->has_player_update = true; // Will be refined on activity detail.
                    $status->latest_player = $latestplayer->version;
                }

                $results[$cminfo->id] = $status;
            } else {
                // Unmanaged activity: detect player via file inspection.
                $detection = player_manager::detect_player_in_activity((int) $cminfo->id);
                if (!$detection->is_rapidcmi5) {
                    continue;
                }

                $status = new \stdClass();
                $status->has_player_update = false;
                $status->has_content_update = false;
                $status->is_rapidcmi5 = true;

                if ($latestplayer) {
                    $currentver = $detection->player_version ?? '';
                    if (!empty($currentver) && $currentver !== $latestplayer->version) {
                        $status->has_player_update = true;
                        $status->current_player = $currentver;
                        $status->latest_player = $latestplayer->version;
                    } else if (empty($currentver)) {
                        $status->has_player_update = true;
                        $status->current_player = 'unknown';
                        $status->latest_player = $latestplayer->version;
                    }
                }

                $results[$cminfo->id] = $status;
            }
        }

        return $results;
    }
}

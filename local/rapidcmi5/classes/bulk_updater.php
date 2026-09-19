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

/** Moves many managed activities to their project's current version at once. */
class bulk_updater {
    /**
     * Update the chosen activities to their project's current version, each on its own.
     *
     * Only activities the finder still reports as behind are touched, so stale or tampered IDs are skipped.
     * A failed activity does not stop the rest.
     *
     * @param int[] $deploymentids Deployments to update.
     * @return \stdClass updated and skipped (deployment IDs), and failed (deploymentid, activityname,
     *                   coursename, message).
     */
    public static function update(array $deploymentids): \stdClass {
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $deploymentids = array_values(array_unique(array_filter(array_map('intval', $deploymentids))));
        $result = (object) ['updated' => [], 'skipped' => [], 'failed' => []];
        $pending = [];
        foreach (update_finder::find(['deploymentids' => $deploymentids]) as $project) {
            foreach ($project->activities as $activity) {
                $pending[$activity->deploymentid] = [$project, $activity];
            }
        }
        \core_php_time_limit::raise();
        foreach ($deploymentids as $deploymentid) {
            if (!isset($pending[$deploymentid])) {
                $result->skipped[] = $deploymentid;
                continue;
            }
            [$project, $activity] = $pending[$deploymentid];
            try {
                $updated = version_uploader::with_project_lock($project->identifier,
                    fn() => self::update_one($deploymentid));
                $result->{$updated ? 'updated' : 'skipped'}[] = $deploymentid;
            } catch (\Throwable $e) {
                $result->failed[] = (object) ['deploymentid' => $deploymentid, 'activityname' => $activity->activityname,
                    'coursename' => $activity->coursename, 'message' => $e->getMessage()];
            }
        }
        return $result;
    }

    /** Move one activity to its project's current version, unless that happened since the page was loaded. */
    private static function update_one(int $deploymentid): bool {
        global $DB;
        $deployment = $DB->get_record('local_rapidcmi5_deployments', ['id' => $deploymentid], '*', MUST_EXIST);
        $currentversionid = (int) $DB->get_field('local_rapidcmi5_projects', 'currentversionid',
            ['id' => $deployment->projectid], MUST_EXIST);
        if (!$currentversionid || (int) $deployment->versionid === $currentversionid) {
            return false;
        }
        deployment_manager::update_deployment($deploymentid, $currentversionid);
        return true;
    }
}

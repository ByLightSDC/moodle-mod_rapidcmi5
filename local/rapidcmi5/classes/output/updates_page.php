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

namespace local_rapidcmi5\output;

defined('MOODLE_INTERNAL') || die();

use local_rapidcmi5\update_finder;
use moodle_url;

/** Template data for updates.php: activities behind their project's current version. */
class updates_page {
    /** URL of updates.php with the given parameters, leaving out empty ones. */
    public static function url(array $params = []): moodle_url {
        return new moodle_url('/local/rapidcmi5/updates.php', array_filter($params, fn($value) => $value !== '' &&
            $value !== 0 && $value !== []));
    }

    /**
     * Template data for the Updates list.
     *
     * @param array $filters search and projectid.
     * @param int[] $selected Deployment IDs to show as ticked, e.g. those that failed last time.
     * @param bool $canupdate Whether the user may apply updates.
     * @return array
     */
    public static function data(array $filters, array $selected, bool $canupdate): array {
        $systemcontext = \context_system::instance();
        $projects = [];
        $selectedcount = 0;
        foreach (update_finder::find($filters) as $project) {
            $group = 'rcmi-updates project-' . $project->id . '-';
            $activities = [];
            foreach ($project->activities as $activity) {
                $checked = $project->canupdate && in_array($activity->deploymentid, $selected);
                $selectedcount += (int) $checked;
                $activities[] = [
                    'deploymentid' => $activity->deploymentid,
                    'activityname' => format_string($activity->activityname, true,
                        ['context' => \context_module::instance($activity->cmid)]),
                    'activityurl' => (new moodle_url('/mod/cmi5/view.php', ['id' => $activity->cmid]))->out(false),
                    'coursename' => format_string($activity->coursename, true,
                        ['context' => \context_course::instance($activity->courseid)]),
                    'installedlabel' => $activity->installedlabel ?? get_string('unknownversion', 'local_rapidcmi5'),
                    'checked' => $checked,
                    'togglegroup' => $group,
                ];
            }
            $projects[] = [
                'id' => $project->id,
                'name' => format_string($project->name, true, ['context' => $systemcontext]),
                'projecturl' => (new moodle_url('/local/rapidcmi5/project.php', ['id' => $project->id]))->out(false),
                'currentlabel' => $project->currentlabel,
                'selectable' => $canupdate && $project->canupdate,
                'revisionmissing' => !$project->canupdate,
                'togglegroup' => $group,
                'activities' => $activities,
            ];
        }
        $filtered = trim($filters['search'] ?? '') !== '' || !empty($filters['projectid']);
        return [
            'actionurl' => self::url($filters)->out(false),
            'filterurl' => self::url()->out(false),
            'search' => $filters['search'] ?? '',
            'projectid' => $filters['projectid'] ?? 0,
            'filtered' => $filtered,
            'projects' => $projects,
            'hasprojects' => !empty($projects),
            'canupdate' => $canupdate,
            'hasselectable' => $canupdate && (bool) array_filter($projects, fn($project) => $project['selectable']),
            'selectedcount' => $selectedcount,
            'sesskey' => sesskey(),
            'emptytext' => get_string($filtered ? 'updatesnomatch' : 'updatesnone', 'local_rapidcmi5'),
        ];
    }
}

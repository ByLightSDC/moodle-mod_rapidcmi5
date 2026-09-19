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

/** Shared presentation for the RapidCMI5 management pages. */
class dashboard {
    /**
     * Render the Moodle header, scoped layout, introduction and section navigation.
     *
     * Styling lives in this plugin's styles.css, which Moodle compiles into the theme.
     */
    public static function header(string $active, string $description): string {
        global $OUTPUT;
        $items = [];
        foreach (self::sections() as $key => $section) {
            $items[] = ['label' => get_string($section[1], 'local_rapidcmi5'),
                'url' => (new \moodle_url('/local/rapidcmi5/' . $section[0]))->out(false),
                'active' => $active === $key];
        }
        return $OUTPUT->header() . \html_writer::start_div('rapidcmi5-dashboard') .
            $OUTPUT->render_from_template('local_rapidcmi5/dashboard_header', [
                'description' => get_string($description, 'local_rapidcmi5'), 'navigation' => $items]);
    }

    /**
     * Build one overview card. The card shows a metric only when a count callback is given.
     *
     * @param string $path Page filename within this plugin.
     * @param string $title Language string key for the title; '<key>_desc' supplies the description.
     * @param string $icon Moodle pix icon identifier.
     * @param callable|null $count Deferred count, so a card without a metric costs no query.
     * @param string $countlabel Language string key naming what is counted.
     * @return array Template context for local_rapidcmi5/dashboard_overview.
     */
    public static function overview_card(string $path, string $title, string $icon,
            ?callable $count = null, string $countlabel = ''): array {
        global $OUTPUT;
        return [
            'url' => (new \moodle_url('/local/rapidcmi5/' . $path))->out(false),
            'title' => get_string($title, 'local_rapidcmi5'),
            'description' => get_string($title . '_desc', 'local_rapidcmi5'),
            'icon' => $OUTPUT->pix_icon($icon, ''),
            'hascount' => $count !== null,
            'count' => $count === null ? 0 : $count(),
            'countlabel' => $countlabel === '' ? '' : get_string($countlabel, 'local_rapidcmi5'),
        ];
    }

    /** Close the shared layout and render Moodle's footer. */
    public static function footer(): string {
        global $OUTPUT;
        return \html_writer::end_div() . $OUTPUT->footer();
    }

    /** Shared navigation definitions. */
    public static function sections(): array {
        return [
            'overview' => ['manage.php', 'dashboardoverview'],
            'projects' => ['index.php', 'projects'],
            'courses' => ['courses.php', 'projectsbycourse'],
            'players' => ['player.php', 'playerversions'],
            'updates' => ['updates.php', 'updates'],
            'unmanaged' => ['unmanaged.php', 'unmanagedactivities'],
        ];
    }
}

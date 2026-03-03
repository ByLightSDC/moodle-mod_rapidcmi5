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

/**
 * Serve plugin files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_rapidcmi5_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    require_login();
    require_capability('local/rapidcmi5:manage', $context);

    if ($filearea !== 'player_package') {
        return false;
    }

    $itemid = (int) array_shift($args);
    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/local_rapidcmi5/player_package/{$itemid}/{$relativepath}";

    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Add "Update available" badge to cmi5 activities on the course page.
 *
 * @param cm_info $cminfo
 */
function local_rapidcmi5_cm_info_dynamic(cm_info $cminfo) {
    if ($cminfo->modname !== 'cmi5') {
        return;
    }

    // Only show to users who can manage.
    $syscontext = context_system::instance();
    if (!has_capability('local/rapidcmi5:manage', $syscontext)) {
        return;
    }

    // Use static cache to avoid repeated DB calls per course page.
    static $coursechecks = [];
    $courseid = $cminfo->course;

    if (!isset($coursechecks[$courseid])) {
        $coursechecks[$courseid] = \local_rapidcmi5\update_checker::check_course($courseid);
    }

    $checks = $coursechecks[$courseid];
    if (!isset($checks[$cminfo->id])) {
        return;
    }

    $status = $checks[$cminfo->id];
    if ($status->has_content_update || $status->has_player_update) {
        $badge = html_writer::span(
            get_string('updateavailable', 'local_rapidcmi5'),
            'badge badge-warning ml-2'
        );
        $cminfo->set_after_link($badge);
    }
}

/**
 * Add update notification to the cmi5 activity settings form.
 *
 * @param moodleform $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_rapidcmi5_coursemodule_standard_elements($formwrapper, $mform) {
    $cm = $formwrapper->get_coursemodule();
    if (!$cm || $cm->modname !== 'cmi5') {
        return;
    }

    $syscontext = context_system::instance();
    if (!has_capability('local/rapidcmi5:manage', $syscontext)) {
        return;
    }

    $status = \local_rapidcmi5\update_checker::check_activity($cm->id);
    if (!$status->is_rapidcmi5) {
        return;
    }

    $notifications = [];

    if ($status->has_content_update) {
        $a = new stdClass();
        $a->current = $status->current_content_version;
        $a->latest = $status->latest_content_version;
        $notifications[] = get_string('contentupdateavailable', 'local_rapidcmi5', $a);
    }

    if ($status->has_player_update) {
        $a = new stdClass();
        $a->current = $status->current_player ?: 'unknown';
        $a->latest = $status->latest_player;
        $notifications[] = get_string('playerupdateavailable', 'local_rapidcmi5', $a);
    }

    if (!empty($notifications)) {
        $mform->addElement('header', 'rapidcmi5updates', get_string('updateavailable', 'local_rapidcmi5'));
        foreach ($notifications as $note) {
            $mform->addElement('static', '', '', html_writer::div($note, 'alert alert-info'));
        }
    }
}

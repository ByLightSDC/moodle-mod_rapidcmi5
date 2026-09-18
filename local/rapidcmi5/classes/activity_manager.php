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

/** Adds existing RapidCMI5 activities to project tracking without changing learner data. */
class activity_manager {
    /** Return validated source metadata for the confirmation page and import. */
    public static function get_source(int $cmid): \stdClass {
        global $DB;
        require_capability('local/rapidcmi5:manage', \context_system::instance());
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, MUST_EXIST);
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));
        if (!player_manager::detect_player_in_activity($cmid)->is_rapidcmi5) {
            throw new \moodle_exception('error:notrapidcmi5', 'local_rapidcmi5');
        }
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $libraryversion = empty($instance->packageversionid) ? null :
            $DB->get_record('cmi5_package_versions', ['id' => $instance->packageversionid], '*', MUST_EXIST);
        $identifier = $libraryversion ? $libraryversion->courseid_iri : $instance->courseid_iri;
        if (empty($identifier) || \core_text::strlen($identifier) > 255) {
            throw new \moodle_exception('error:invalidprojectidentifier', 'local_rapidcmi5');
        }
        return (object) ['cm' => $cm, 'instance' => $instance,
            'libraryversion' => $libraryversion, 'identifier' => $identifier];
    }

    /** Adopt an activity. Repeated submissions return the existing deployment. */
    public static function adopt(int $cmid): \stdClass {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('local_rapidcmi5');
        $lock = $factory->get_lock('adopt_activity', 10);
        if (!$lock) {
            throw new \moodle_exception('error:adoptionbusy', 'local_rapidcmi5');
        }
        $transaction = null;
        try {
            $source = self::get_source($cmid);
            $existing = $DB->get_record('local_rapidcmi5_deployments', ['cmid' => $cmid]);
            if ($existing) {
                return $existing;
            }
            $project = project_manager::get_project_by_identifier($source->identifier);
            if ($project && $DB->record_exists('local_rapidcmi5_deployments', [
                    'projectid' => $project->id, 'courseid' => $source->cm->course])) {
                throw new \moodle_exception('error:managedcourseconflict', 'local_rapidcmi5');
            }
            $transaction = $DB->start_delegated_transaction();
            if (!$project) {
                $project = project_manager::get_or_create_project(
                    $source->identifier, $source->instance->name)->project;
            }
            $libraryversion = $source->libraryversion ?? self::snapshot_activity($source);
            // The label identifies the precise imported library revision, not the player version.
            $label = 'imported-' . $libraryversion->id;
            $version = $DB->get_record('local_rapidcmi5_versions', [
                'projectid' => $project->id, 'versionnumber' => $label]);
            if ($version && ((int) $version->packageid !== (int) $libraryversion->packageid ||
                    $version->sha256hash !== $libraryversion->sha256hash)) {
                throw new \moodle_exception('error:versionexists', 'local_rapidcmi5', '', $label);
            }
            if (!$version && !empty($libraryversion->sha256hash)) {
                $matches = $DB->get_records('local_rapidcmi5_versions', [
                    'projectid' => $project->id, 'packageid' => $libraryversion->packageid,
                    'sha256hash' => $libraryversion->sha256hash], 'id ASC', '*', 0, 1);
                $version = $matches ? reset($matches) : false;
            }
            if (!$version) {
                // Importing an older activity must not replace an established project's current
                // version, so only a project with no current version adopts this one.
                $version = project_manager::create_version($project->id, $label,
                    $libraryversion->packageid, '', $libraryversion->timecreated,
                    $libraryversion->sha256hash ?? '', '', (int) $libraryversion->id,
                    empty($project->currentversionid));
            }
            $now = time();
            $deployment = (object) ['projectid' => $project->id, 'versionid' => $version->id,
                'courseid' => $source->cm->course, 'cmid' => $cmid,
                'sectionid' => $DB->get_field('course_sections', 'section', ['id' => $source->cm->section]),
                'timecreated' => $now, 'timemodified' => $now];
            $deployment->id = $DB->insert_record('local_rapidcmi5_deployments', $deployment);
            $transaction->allow_commit();
            return $deployment;
        } catch (\Throwable $e) {
            if ($transaction) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Snapshot ad-hoc content into the library, retaining the original activity and AU records. */
    private static function snapshot_activity(\stdClass $source): \stdClass {
        global $USER;
        $fs = get_file_storage();
        $context = \context_module::instance($source->cm->id);
        $packages = $fs->get_area_files($context->id, 'mod_cmi5', 'package', 0, 'sortorder, id', false);
        if (!$packages) {
            throw new \moodle_exception('packagenotfound', 'mod_cmi5');
        }
        $packer = get_file_packer('application/zip');
        $tempdir = make_request_directory();
        $original = reset($packages);
        if (!$original->extract_to_pathname($packer, $tempdir) || !is_file($tempdir . '/cmi5.xml')) {
            throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
        }
        $structure = \mod_cmi5\cmi5_package::parse_cmi5_xml_static(file_get_contents($tempdir . '/cmi5.xml'));
        if ($structure->courseid !== $source->identifier) {
            throw new \moodle_exception('error:invalidprojectidentifier', 'local_rapidcmi5');
        }
        // Use current extracted content so player upgrades made since upload are included.
        $files = ['cmi5.xml' => $tempdir . '/cmi5.xml'];
        foreach ($fs->get_area_files($context->id, 'mod_cmi5', 'content', 0, '', false) as $file) {
            $path = ltrim($file->get_filepath(), '/') . $file->get_filename();
            if ($path !== 'cmi5.xml') {
                $files[$path] = $file;
            }
        }
        $zip = $packer->archive_to_storage($files, \context_user::instance($USER->id)->id,
            'user', 'draft', file_get_unused_draft_itemid(), '/', 'managed-content.zip', $USER->id, false);
        if (!$zip) {
            throw new \moodle_exception('error:adoptionarchive', 'local_rapidcmi5');
        }
        try {
            return \mod_cmi5\content_library::upload_package($zip, $source->instance->name,
                '', (int) ($source->instance->profileid ?? 0));
        } finally {
            $zip->delete();
        }
    }
}

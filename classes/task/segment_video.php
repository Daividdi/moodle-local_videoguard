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

namespace local_videoguard\task;

use local_videoguard\local\segmenter;

/**
 * Segments one activity's video and repoints it at the signed manifest.
 *
 * Adhoc rather than synchronous because segmenting gigabytes must not happen
 * inside the request that saves the activity form.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class segment_video extends \core\task\adhoc_task {

    /**
     * Queues this task for a course module, unless an identical one is already queued.
     *
     * @param int $cmid
     */
    public static function queue(int $cmid): void {
        $task = new self();
        $task->set_custom_data((object)['cmid' => $cmid]);
        $task->set_component('local_videoguard');
        // Deduplicates on identical custom data, so ten saves in a row leave one job.
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Runs the segmentation.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $cmid = (int)($data->cmid ?? 0);
        if ($cmid <= 0) {
            mtrace('videoguard: no cmid in custom data, nothing to do');
            return;
        }

        $cm = get_coursemodule_from_id('interactivevideo', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            // Activity deleted between queueing and running - not an error.
            mtrace("videoguard: cmid {$cmid} no longer exists, skipping");
            return;
        }

        $file = segmenter::source_file($cm);
        if (!$file) {
            // Activity points at YouTube/Vimeo/etc, or has no upload yet.
            mtrace("videoguard: cmid {$cmid} has no uploaded video, skipping");
            return;
        }

        if (segmenter::needs_segmenting($file, (int)$cm->instance)) {
            mtrace("videoguard: segmenting cmid {$cmid} ("
                . display_size($file->get_filesize()) . ')');
            $started = microtime(true);
            segmenter::segment($file, (int)$cm->instance);
            mtrace(sprintf('videoguard: done in %.1fs', microtime(true) - $started));
        } else {
            mtrace("videoguard: cmid {$cmid} already segmented for this source");
        }

        // Repoint every time, not only after segmenting: saving the activity resets
        // the source columns even when the video file itself did not change.
        segmenter::repoint($cm, (int)$cm->instance);
        mtrace("videoguard: cmid {$cmid} pointing at the signed manifest");
    }
}

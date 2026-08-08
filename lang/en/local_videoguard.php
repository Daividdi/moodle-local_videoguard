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

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Video guard';
$string['notprocessed'] = 'This video is still being prepared for secure playback. Please try again shortly.';
$string['misconfigured'] = 'Secure video delivery is not configured on this site.';
$string['tasksweep'] = 'Find and protect unsegmented videos';
$string['segmentfailed'] = 'Video segmentation failed: {$a}';
$string['settingdelivery'] = 'Segment delivery';
$string['settingdelivery_desc'] = 'How segments reach the browser. <b>Signed URL</b> lets the web server validate a signature by itself, so media never touches PHP - but the signature stands in for a session, which means a copied URL keeps working, logged in or not, until it expires. <b>Session check</b> sends every segment back through Moodle, which re-checks enrolment each time and makes a copied URL worthless to anyone not logged in; it costs one Moodle bootstrap per segment, and needs a send-file module so the worker is released immediately.';
$string['deliverysecurelink'] = 'Signed URL (nginx secure_link) - no PHP in the media path';
$string['deliveryxsendfile'] = 'Session check + send-file (recommended where available)';
$string['deliveryreadfile'] = 'Session check + PHP streams the file (last resort)';
$string['settingxsendheader'] = 'Send-file header';
$string['settingxsendheader_desc'] = 'Which header hands the file to the web server. Only used by the session-check delivery mode. Getting this wrong produces empty responses, because the header is simply ignored and no body is written.';

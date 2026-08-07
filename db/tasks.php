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

$tasks = [
    [
        // Hourly, not nightly: an activity created by course restore would
        // otherwise serve the unprotected MP4 for most of a working day.
        'classname' => 'local_videoguard\task\sweep',
        'blocking'  => 0,
        'minute'    => '17',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];

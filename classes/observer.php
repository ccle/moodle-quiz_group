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

require_once($CFG->dirroot . '/mod/quiz/classes/event/attempt_started.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/locallib.php');



/**
 * This file defines the function triggered by the event observer.
 *
 * @package   quiz_group
 * @copyright 2017 Camille Tardy, University of Geneva
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

class quiz_group_observer{

    /**
     * Event processor - attempt started
     * Create new attempt record in group quiz table
     *
     * @param \mod_quiz\event\attempt_started $event
     * @return bool
     */
    public static function attempt_started(core\event\base $event) {
        global $DB, $PAGE;

        $attempt = $event->get_data();
        $cm = $PAGE->cm;
        $quiz_id = $cm->instance;

        $groupingid = get_groupquiz_groupingid($quiz_id);


        if($groupingid == null || $groupingid ==0){
            // if grp_quiz is not enabled do nothing

        }else{
            //check if a user from the same group is trying to attempt quiz when we already have an attempt for this group.
            $user_grp = get_user_group_for_groupquiz($attempt['userid'], $quiz_id, $attempt['courseid']);

            $attempt_grp_inDB = $DB->get_records('quiz_group_attempts', array('quizid'=>$quiz_id, 'groupid'=>$user_grp, 'groupingid'=>$groupingid));
            if (!empty($attempt_grp_inDB)){
                // An attempt already exist for this group block current user attempt
                $grp_attemptID = 0;
                $grp_name = $DB->get_field('groups', 'name', array('id'=>$user_grp));
                //return to view quiz page with message  : warning(yellow) --> NOTIFY_WARNING // error(red) --> NOTIFY_ERROR
                redirect(new moodle_url('/mod/quiz/view.php', array('id' => $cm->id)), get_string('group_attempt_already_created', 'quiz_group', $grp_name), null, \core\output\notification::NOTIFY_ERROR);

            }else{
                // no attempt yet for this group : proceed with current user

                $group_attempt = quiz_group_attempt_to_groupattempt_dbobject($attempt, $quiz_id, $user_grp, $groupingid);

                //save in DB
                $grp_attemptID = $DB->insert_record('quiz_group_attempts', $group_attempt, true);
            }

            return $grp_attemptID;
        }


    }

    /**
     * Event processor - attempt submited
     * edit the group attemp object actual attempt id
     *
     * @param \mod_quiz\event\attempt_submitted $event
     * @return bool
     */
    public static function attempt_submitted(core\event\base $event){
        global $DB;

        $attempt = $event->get_data();
        $quiz_id = $attempt['other']['quizid'];
        $user_id = $attempt['userid'];
        $attempt_id = $attempt['objectid'];
        $course_id = $attempt['courseid'];

        $groupingid = get_groupquiz_groupingid($quiz_id);

        if($groupingid == null || $groupingid ==0){
            // of grp_quiz is not enabled do nothing
        }else{

            $gid = get_user_group_for_groupquiz($user_id, $quiz_id, $course_id);

            //retrieve grp attempt object
            $grp_attempt = $DB->get_record('quiz_group_attempts', array('groupid'=>$gid, 'quizid'=>$quiz_id));

            if(!empty($grp_attempt)){
                //edit grp attempt
                $grp_attempt->attemptid = $attempt_id;
                //save in DB
                $DB->update_record('quiz_group_attempts',  $grp_attempt, false);
            }else {
                //ERROR : Grp attempt not in DB
                //create grp_attempt if not in DB
                create_grpattempt_from_attempt($attempt,$course_id);
            }
        }

        return true;
    }

    /**
     * Event processor - attempt deleted
     * delete the group attempt record in group quiz table
     *
     * @param \mod_quiz\event\attempt_started $event
     * @return bool
     */
    public static function attempt_deleted(core\event\base $event) {
        global $DB;

        $attempt = $event->get_data();
        $quiz_id = $attempt['other']['quizid'];
       // $attempt_id = $attempt['objectid'];
        $user_id = $attempt['relateduserid'];

        //attempt can be null in grp_attempt if attempt never submitted by user
        //better to retreive attempt via quizid and userid

        //delete record in DB
        $DB->delete_records('quiz_group_attempts', array('quizid'=>$quiz_id, 'userid'=>$user_id));

        return true ;
    }

    /**
     * Event processor - attempt abandoned
     * delete the group attempt record in group quiz table
     *
     * @param \mod_quiz\event\attempt_abandoned $event
     * @return bool
     */
    public static function attempt_abandoned(core\event\base $event) {
        global $DB;

        $attempt = $event->get_data();
        $quizid = $attempt['other']['quizid'];
        $userid = $attempt['other']['userid'];
      //  $courseid = $attempt['other']['courseid'];

       /* $attemptid = $attempt['other']['$attemptid'];

        if($attemptid !== null){
            //if we know the attempt id and if it exist in DB use
            $DB->delete_records('quiz_group_attempts', array('quizid'=>$quiz_id, 'attemptid'=>$attempt_id));
        }else{}*/

       // $groupid = get_user_group_for_groupquiz($userid, $quizid, $courseid);

        //delete record in DB for group in quiz
        $DB->delete_records('quiz_group_attempts', array('quizid'=>$quizid, 'userid'=>$userid));

        return true ;
    }



    /**
     * Flag whether a course reset is in progress or not.
     *
     * @var int The course ID.
     */
    protected static $resetinprogress = false;

    /**
     * A course reset has started.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    public static function course_reset_started($event) {
        self::$resetinprogress = $event->courseid;
    }

    /**
     * A course reset has ended.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    public static function course_reset_ended($event) {
        if (!empty(self::$resetinprogress)) {
            if (!empty($event->other['reset_options']['reset_groups_remove'])) {
                quiz_process_grp_deleted_in_course($event->courseid);
            }
        }

        self::$resetinprogress = null;
    }

    /**
     * A group was deleted.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    public static function group_deleted($event) {
        if (!empty(self::$resetinprogress)) {
            // We will take care of that once the course reset ends.
            return;
        }
        quiz_process_grp_deleted_in_course($event->courseid);
    }

}
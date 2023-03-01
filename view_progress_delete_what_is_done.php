<?php
// We use this file to keep track of what is already migrated to new view.php.

        // TODO: Groups - wie hat das funktioniert??

        $columns[] = 'id';
        $headers[] = "";
        $usersofgroupsql = '';
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {
            list ($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($course->id);
            $conditionsparams = array_merge($conditionsparams, $groupparams);
            $usersofgroupsql = "
                (SELECT COUNT(*)
                   FROM {booking_answers} ba
                  WHERE ba.optionid = bo.id
                    AND ba.userid IN ( $groupsql )) AS allbookedsamegroup,";
        }

        $fields = "DISTINCT bo.id,
                         bo.text,
                         bo.address,
                         bo.description,
                         bo.coursestarttime,
                         bo.courseendtime,
                         bo.limitanswers,
                         bo.maxanswers,
                         bo.maxoverbooking,
                         bo.minanswers,
                         bo.invisible,
                         bo.status as bostatus,
                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0) AS booked,

                          $usersofgroupsql

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 1) AS waiting,
                         bo.location,
                         bo.institution,

                  (SELECT bo.maxanswers - (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.waitinglist = 0)) AS availableplaces,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.userid = :userid) AS iambooked,
                         b.allowupdate,
                         b.allowupdatedays,
                         bo.bookingopeningtime,
                         bo.bookingclosingtime,
                         b.btncancelname,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.completed = 1
                     AND ba.userid = :userid4) AS completed,

                  (SELECT status
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.status > 0
                     AND ba.userid = :userid6) AS status,

                  (SELECT DISTINCT(ba.waitinglist)
                   FROM {booking_answers} ba
                   WHERE ba.optionid = bo.id
                   AND ba.waitinglist < 2
                     AND ba.userid = :userid1) AS waitinglist,
                         b.btnbooknowname,
                         b.maxperuser,

                  (SELECT COUNT(*)
                   FROM {booking_answers} ba
                    LEFT JOIN
                        {booking_options} bo ON bo.id = ba.optionid
                   WHERE ba.bookingid = b.id
                    AND ba.userid = :userid2
                    AND ba.waitinglist < 2
                    AND (bo.courseendtime = 0
                    OR bo.courseendtime > :timestampnow)) AS bookinggetuserbookingcount,
                         b.cancancelbook,
                         bo.disablebookingusers,

                  (SELECT COUNT(*)
                   FROM {booking_teachers} ba
                   WHERE ba.optionid = bo.id
                     AND ba.userid = :userid3) AS isteacher,

                  (SELECT AVG(rate)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS rating,

                  (SELECT COUNT(*)
                   FROM {booking_ratings} br
                  WHERE br.optionid = bo.id) AS ratingcount,

                  (SELECT rate
                  FROM {booking_ratings} br
                  WHERE br.optionid = bo.id
                    AND br.userid = :userid5) AS myrating
                ";
        $from = "{booking} b LEFT JOIN {booking_options} bo ON bo.bookingid = b.id";
        $where = "b.id = :bookingid " .
                 (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions));

        // TODO: groupmode (??).

        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', \context_course::instance($course->id))) {

            list ($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($course->id);
            array_push($conditions, "tu.id IN ($groupsql)");
            $conditionsparams = array_merge($conditionsparams, $groupparams);
        }

        $fields = "tba.id,
                        tu.id AS userid,
                        tba.optionid AS optionid,
                        tbo.text AS booking,
                        tu.institution AS institution,
                        tbo.location AS location,
                        tbo.coursestarttime AS coursestarttime,
                        tbo.courseendtime AS courseendtime,
                        tba.numrec AS numrec,
                        tu.firstname AS firstname,
                        tu.lastname AS lastname,
                        tu.city AS city,
                        tu.department AS department,
                        tu.username AS username,
                        tu.email AS email,
                        tba.completed AS completed,
                        tba.status,
                        tba.numrec,
                        tba.notes,
                        otherbookingoption.text AS otheroptions,
                        tba.waitinglist AS waitinglist,
                        tu.idnumber AS idnumber {$customfields}";
        $from = '{booking_answers} tba
                JOIN {user} tu ON tu.id = tba.userid
                JOIN {booking_options} tbo ON tbo.id = tba.optionid
                LEFT JOIN {booking_options} otherbookingoption ON otherbookingoption.id = tba.frombookingid';
        $where = 'tu.deleted = 0
              AND tu.suspended = 0
              AND tba.waitinglist < 2
              AND tba.optionid IN (
                SELECT DISTINCT bo.id
                FROM {booking} b
                LEFT JOIN {booking_options} bo
                ON bo.bookingid = b.id
                WHERE b.id = :bookingid ' .
            (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')';

        $conditionsparams['userid'] = $USER->id;
        $conditionsparams['userid1'] = $USER->id;
        $conditionsparams['userid2'] = $USER->id;
        $conditionsparams['userid3'] = $USER->id;
        $conditionsparams['bookingid'] = $booking->settings->id;
        $conditionsparams['tcourseid'] = $course->id;
        $tablealloptions->define_columns($columns);
        $tablealloptions->define_headers($headers);
        $tablealloptions->set_sql($fields, $from, $where, $conditionsparams);
        unset($tablealloptions->attributes['cellspacing']);
        $tablealloptions->setup();
        $tablealloptions->query_db(10);
        if (!empty($tablealloptions->rawdata)) {
            foreach ($tablealloptions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
            }
        }
        if (!empty($tablealloptions->rawdata)) {
            foreach ($tablealloptions->rawdata as $option) {
                $option->otheroptions = "";
                $option->groups = "";
                $groups = groups_get_user_groups($course->id, $option->userid);
                if (!empty($groups[0])) {
                    $groupids = implode(',', $groups[0]);
                    list($groupids, $groupidsparams) = $DB->get_in_or_equal($groups[0]);
                    $groupnames = $DB->get_fieldset_select('groups', 'name', " id $groupids", $groupidsparams);
                    $option->groups = implode(', ', $groupnames);
                }
            }
        }
    }

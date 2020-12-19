<?php

/* $banner_data is as single row of roster data from Banner fetched by CRN */
function is_practicum($banner_data) {
    $title = $banner_data['CTITLE'];
    $subject_code = $banner_data['SSBSECT_SUBJ_CODE'];
    $grade_mode = $banner_data['SCRGMOD_GMOD_CODE'];
    ## If the title is Practicum then we have a practicum
    ## If the subject code is PC it is Satisfactor/Unsatisfactory we have either a practicum
    ##   or something like a practicum in terms of how it is graded.
    if ($title == 'Practicum' || ($subject_code == 'PC' && $grade_mode == 'S')) {
        return(True);
    }
    return(False);
}

/* Uses a series of rules to calculate the full grade mode */
function calculate_full_grade_mode($connection, $crnterm, $publisher_emails, $logger, $mailobj, $testing) {
    $crntermarray = explode(".",$crnterm);
    $crn = $crntermarray[0];
    $term = $crntermarray[1];

    $banner_data = get_roster_data($connection, $crn, $term);
    $full_grade_mode = [];
    $grade_mode = $banner_data['SCRGMOD_GMOD_CODE'];
    $course_number = intval($banner_data['SSBSECT_CRSE_NUMB'][0]);
    $is_a_practicum = is_practicum($banner_data);

    ## First add the roster grade mode from Banner
    $full_grade_mode['mode'] = $grade_mode;
    if ($grade_mode == 'G') {
        $full_grade_mode['values'] = array('A','A-','B','B+','B-','C','C+','C-','F');
    } else if ($grade_mode == 'P') {
        $full_grade_mode['values'] = array('P','F');
    } else if ($grade_mode == 'S') {
        $full_grade_mode['values'] = array('SA','NS');
    }

    if (count($full_grade_mode['values']) == 0) {
        $logger->logmsg("Error: Grade mode cannot be determined for $crn.$term\n       Rejecting the Submission\n");
        $mailobj->send_system_failure_message($publisher_emails);
        exit(1);
    }

    if ($is_a_practicum) {
    $logger->logmsg("Info: $crn.$term is a practicum course\n", False);
    } else {
    $logger->logmsg("Info: $crn.$term is not a practicum course\n", False);
    }

    ##
    ## Determine if the class takes RD and AIC/ASA
    ##
    if ($is_a_practicum) {
        ## Practicum take RD directly, so no AIC or ASA
        $full_grade_mode['rd'] = True;
        $full_grade_mode['aicasa'] = False;
    } else {
        $full_grade_mode['rd'] = False;
        ## Not a practicum, so if it is graded (not S) thin it will take AIC/ASA, no otherwise
        if ($grade_mode != 'S') {
            $full_grade_mode['aicasa'] = True;
        } else {
            $full_grade_mode['aicasa'] = False;
        }
    }

    ##
    ## Determine H1
    ##
    if ($is_a_practicum) {
        ## Practicum do not take H1
        $full_grade_mode['h1'] = False;
    } else if ($course_number >= 7 and !$is_a_practicum) {
        ## 700 and 800 level courses which are not practicum
        $full_grade_mode['h1'] = True;
    } else {
        $full_grade_mode['h1'] = False;
    }

    ##
    ## Determine Incomplete
    ##
    if (!$is_a_practicum && $course_number == 5 && ($grade_mode == 'G' || $grade_mode == 'P')) {
        ## If it is a 500 level graded or pass/fail course that isn't a practicum than accept an incomplete
        $full_grade_mode['incomplete'] = True;
    } else {
        ## otherwise don't
        $full_grade_mode['incomplete'] = False;
    }

    ##
    ## Add the other grades to the full grade mode values array
    ##
    if ($full_grade_mode['rd']) {
        $full_grade_mode['values'][] = 'RD';
    }
    if ($full_grade_mode['aicasa']) {
        $full_grade_mode['values'][] = 'AIC';
        $full_grade_mode['values'][] = 'ASA';
    }
    if ($full_grade_mode['h1']) {
        $full_grade_mode['values'][] = 'H1';
    }
    if ($full_grade_mode['incomplete']) {
        $full_grade_mode['values'][] = 'I';
    }

    return($full_grade_mode);
}

/*
 * try {
 *     validate_export($content, $testing);
 * } catch (RuntimeException $e) {
 *     $subject = "[Grade Syncing - Canvas/Banner] There were problems with your grade export, please contact teach@fuller.edu";
 *     $msg = $e.getMessage();
 *     $mailobj->send_failure_message($publisher_emails, $subject, $msg);
 * }
 */

function validate_export($content, $testing) {

    ## Take a first pass from the file and grab every valid mapping of a canvas id to a gnumber
    $canvas_id_to_gnumber = array();
	foreach ($content as $line) {
        if ($line != "") {
            $fields = explode(",",$line);
            $gnumber = $fields[7];	
            $canvas_id = $fields[8];
            if ($gnumber != "" and $canvas_id != "") {
                $canvas_id_to_gnumber[$canvas_id] = $gnumber;
            }
        }
    }

    ## now go back through the file, every line must have a canvas id, and the canvas id must have a
    ## gnumber somewhere in the file. It is possible that canvas id has two account associated and so
    ## it will have two lines. We ignore the line with no SIS integration ID but there must be another 
    ## line with the integration ID. If not we might have a user who has been incorrectly set up and 
    ## that needs to be fixed before we can accept the grade submission.
    foreach ($content as $line) {
		if ($line != "") {
			$fields = explode(",",$line);
			$publisher_fullerid = $fields[1];
			$crnterm = $fields[5];
			$gnumber = $fields[7];
            $canvas_id = $fields[8];
			if (count($fields) >= 12) {
				$grade = $fields[11];
			} else {
				// No grading schema most likely, final grade wasn't set in export
				$grade = False;
			}
			$crntermarray = explode(".",$crnterm);

            if (!array_key_exists($canvas_id, $canvas_id_to_gnumber)) {
                $message = "For $crnterm the canvas user id $canvas_id did not have a gnumber in the grades you exported, and so we may be missing a student grade. Please contact teach@fuller.edu and forward them this message so that they can investigate.";
                throw new RuntimeException($message);
            }
		}
	}

    return(true);
}


/* Validate the grades submitted to ensure the match the valid grading scheme for the course */
function validate_grades($grade, $crnterm, $valid_grades, $gnumber, $admin_grade_by_gnumber, $publisher_emails, $logger, $mailobj, $config) {
    // This is more complex then it needs to be as the program used to allow for an empty admin grade column
    // if there was an earned grade. Now we are completely ignoring the earned grade and just using the admin
    // grade column. Write a test harness for this function and then refactor it. (It should probably return
    // true or false rather than end the execution of the program as well ...)

    // This is a temporary hard code, we accept blank grades from these crosslisted DMIN courses
    $accept_blank_grades = array("48391.202004","48394.202004","48322.202004");
    if (!($admin_grade_by_gnumber && array_key_exists($gnumber, $admin_grade_by_gnumber) && in_array($admin_grade_by_gnumber[$gnumber], $valid_grades))) {
        if ($admin_grade_by_gnumber && !array_key_exists($gnumber, $admin_grade_by_gnumber) && in_array($accept_blank_grades, $crnterm)) {
            // This is one of the crosslisted dmin sections, we can just return true and ignore that there is no admin grade for this user
            return(true);
        }
        $valid_grade_string = implode(", ", $valid_grades);
        $subject = "[Grade Syncing - Canvas/Banner] Please try it again";
        $msg = "Thank you for submitting your final grades for $crnterm. There is some important information missing or invalid grades. Only the following grades can be assigned to students in your section $crnterm: $valid_grade_string. Please remember that only the primary faculty can submit grades. Please try submitting your grades again. If the problem continues or if you have any questions, contact teach@fuller.edu. Thank you.";
        if ($admin_grade_by_gnumber && array_key_exists($gnumber, $admin_grade_by_gnumber)) {
            $logger->logmsg("Error: Invalid Administrative grade ".$admin_grade_by_gnumber[$gnumber]." for student $gnumber\n       Rejecting the Submission\n");
            $mailobj->send_failure_message($publisher_emails, $subject, $msg);
            exit(1);
        } else {
            $logger->logmsg("Error: Missing Administrative grade for student $gnumber\n       Rejecting the Submission\n");
            $mailobj->send_failure_message($publisher_emails, $subject, $msg);
            exit(1);
        }
    }
}


/* Queries Banner and retrieves the roster data for a given term */
function get_roster_data($connection, $crn, $term) {
    $query = "
SELECT DISTINCT sfrstcr_crn, sfrstcr_term_code, ssbsect_subj_code, ssbsect_crse_numb, scbcrse_coll_code,
       stvcamp_desc,
       ssbsect_subj_code||ssbsect_crse_numb catalog_number,
       ssbsect_subj_code||ssbsect_crse_numb||' '||nvl(ssbsect_crse_title,scbcrse_title) course,
       ssbsect_crse_title,scbcrse_title ctitle,
       ssbsect_enrl,
       scrgmod_gmod_code,
       decode(scrgmod_gmod_code,'G',
        'GRADED (A, A-, B+, B, B-, C+, C, C-, F)',
        'S','SATISFACTORY/NOT SATISFACTORY (SA or NS)',
        'P','PASS/FAIL (P or F)',
        'U','UNGRADED (-- or F)') gmod
    FROM sfrstcr, ssbsect, scbcrse, scrgmod, stvcamp
    WHERE sfrstcr_term_code = :termcode
        AND sfrstcr_crn = :crn
    AND sfrstcr_rsts_code = 'RE'
    AND ssbsect_term_code = sfrstcr_term_code
    AND ssbsect_crn = sfrstcr_crn
    AND ssbsect_ptrm_code not in ('3','ESL')
    and ssbsect_camp_code not in ('U','V','W','X','Y','8D')
    and ssbsect_schd_code <> 'XX'
    AND scbcrse_subj_code = ssbsect_subj_code 
    and scbcrse_crse_numb = ssbsect_crse_numb
    and scbcrse_eff_term =
          (select max(scbcrse_eff_term)
                 from saturn.scbcrse b
                    where b.scbcrse_subj_code = ssbsect_subj_code
                        and  b.scbcrse_crse_numb = ssbsect_crse_numb
                            and  b.scbcrse_eff_term <= ssbsect_term_code)
                        AND scrgmod_subj_code = ssbsect_subj_code 
                        and scrgmod_crse_numb = ssbsect_crse_numb
and scrgmod_eff_term =
      (select max(scrgmod_eff_term)
       from saturn.scrgmod b
       where b.scrgmod_subj_code = ssbsect_subj_code
        and  b.scrgmod_crse_numb = ssbsect_crse_numb
        and  b.scrgmod_eff_term <= ssbsect_term_code)
and  scrgmod_default_ind = 'D'
and  scrgmod_gmod_code <> 'U'
and ssbsect_camp_code = stvcamp_code
ORDER BY sfrstcr_crn
";
    $stmt = oci_parse($connection,$query);
    oci_bind_by_name($stmt, ':crn', $crn);
    oci_bind_by_name($stmt, ':termcode', $term);
    oci_execute($stmt);
    $bannerdata = oci_fetch_assoc($stmt);

    return($bannerdata);
}

?>

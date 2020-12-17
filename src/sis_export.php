<?php
include 'administrative_grades.php';
include 'ErrorLogger.php';
include 'grading_scheme.php';
include 'ValidateInstructor.php';
include 'GradeSyncEmail.php';

##
## MAIN Program
##

## Set up connections and key constants from the configuration file

// Parse our config file to get credentials
if (file_exists("/var/www/config.ini")) {
    $c = parse_ini_file("/var/www/config.ini");
} else if (file_exists("../config.ini")) {
    $c = parse_ini_file("../config.ini");
} else if (file_exists("./config.ini")) {
    $c = parse_ini_file("./config.ini");
} else {
    error_log("Server Error: Unable to read configuration file");
    exit(1);
}

// Set our testing flag and determine if we are mocking the admin column or not
$testing = TRUE;
if ($c['is_testing'] == "false") {
    $testing = FALSE;
} else {
	error_log("We are in testing mode");
}
$use_mock = FALSE;
if ($c['use_mock'] == "true" && ($testing or $c['use_test_data'] != 'false')) {
    ## Only mock if we are also in testing mode
    $use_mock = TRUE;
	error_log("Using Mock Admin Grade Data"); 
}
if ($c['email_testing'] != "false") {
    error_log("E-mail testing mode is set, no e-mails will be sent.");
}

// Create our logger
if ($testing) {
    $logger = new ErrorLogger($c['logfile'], $c['test_address']);
} else {
    $logger = new ErrorLogger($c['logfile'], $c['admin_address']);
}

// Create our e-mail sending object
$mailobj = new GradeSyncEmail($c, $logger);

// Add the mail object to the logger so that it can send email when appropriate
$logger->add_mailobj($mailobj);

// Ensure that the roster directory exists, otherwise die
if (!is_dir($c['roster_directory'])) {
    $msg = "The roster directory ".$c['roster_directory']." does not exist so we can not save rosters, dying\n";
    $logger->logmsg($msg, False);
    exit(1);
}

// Get list of super users from the config file
$super_users = $c['superuser'];

// Set up the canvas connection info
if ($testing) {
    $canvas_token = $c['test_token'];
    $canvas_host = $c['test_canvas_host'];
} else {
    $canvas_token = $c['prod_token'];
    $canvas_host = $c['prod_canvas_host'];
}

// Get the data file
$logger->logmsg("\n\n----\n-- Running\n----\n", False);
$data = file_get_contents('php://input');
if (!$data && ($testing or $c['use_test_data'] != 'false')) {
    error_log("No data was found and we are testing, slurp in sample data from the local file system");
	$data = file_get_contents("./sample_grades.csv");
	if (!$data) {
		error_log("Failed to read in test data from ./sample_grades.csv, dying");
		exit(1);
	}	
}

// Log the raw file that we received
$logger->logmsg($data, False);

// Now parse the data we received into an array of lines, one line per student grade
$lines = explode("\n", $data);
$headers = explode(",",array_slice($lines, 0, 1)[0]);
$content = array_slice($lines, 1);

// Get the CRN.TERM and Published GNumber so that we can 
// e-mail a helpful e-mail error message
$publisher_ids = array();
$crn_terms = array();
$crosslist = False;
foreach ($content as $line) {
    if ($line != "") {
        $fields = explode(",",$line);
        $publisher = $fields[1];
        $crnterm = $fields[3];
        if (substr($fields[3],0,3) == "XLS") {
            // If this is a crosslist then the crn is in the 5th column and the crosslist
            // number is in the 3rd column.
            $crnterm = $fields[5];
            $crosslist = $fields[3];
        }
        if ($crnterm && !in_array($crnterm, $crn_terms)) {
            $crn_terms[] = $crnterm;
        }
        if ($publisher && !in_array($publisher, $publisher_ids)) {
            $publisher_ids[] = $publisher;
        }
    }
}

if (count($publisher_ids) == 0) {
    $logger->logmsg("Unable to get the GNumber for the publisher from the data submitted, data was either missing or corrupt\n");
    exit(1);
}

// Handle crosslists / multiple CRNs in the submission
$logger->logmsg($crn_terms, False);
if (count($crn_terms) > 1) {
    $all = implode(", ", $crn_terms);
    // There is more than one crn in the submission we must have a crosslist
    if (!$crosslist) {
        $logger->logmsg("Submission has multiple crns ($all) but is not a crosslist. This is unexpected on concerning. The submission was not processed.\n");
        exit(1);
    }
    $logger->logmsg("Working on crosslist $crosslist with crns $all.\n");
    $logger->add_courseid($crosslist);
} else {
    $crnterm = $crn_terms[0];
    $logger->logmsg("Working on $crnterm\n", False);
    $logger->add_courseid($crnterm);
}

##
## Try to connect to Banner
##
if ($testing) {
    // connect to the test instance
    error_log("TESTING: Connecting to ".$c['test_server']);
    $connection = oci_connect($c['test_user'],$c['test_password'],$c['test_server']."/".$c['test_sid']);
} else {
    // connect to the production instance
    $connection = oci_connect($c['prod_user'],$c['prod_password'],$c['prod_server']."/".$c['prod_sid']);
}

if (!$connection) {
    $e = oci_error();
    $logger->logmsg("Internal Error: Unable to connect to Oracle Database: ".$e['message']."\n");
    error_log("Internal Error: Unable to connect to Oracle Database: ".$e['message']);
    $subject = "Attempt to submit grades failed due to database connection error.";
    $msg = implode(", ",$publisher_ids)." attempted to submit grades for section(s) ".implode(", ",$crn_terms)." but we were unable to process the request because the data connection failed with error: ".$e['message']."\n";
    $mailobj->send_error_to_admin($subject, $msg);
    exit(1);
}

//
// Get the e-mail address of the submitter so that we can route the error messages properly
//
$publisher_emails = array();
$publisher_names = array();
$query = "select gobtpac_external_user||'@fuller.edu' as email, spriden_last_name, spriden_first_name
		  from gobtpac join spriden on spriden_pidm = gobtpac_pidm and spriden_change_ind is null
		  where spriden_id = :gnumber";
foreach ($publisher_ids as $gnumber) {
    $emailquery = oci_parse($connection,$query);
    oci_bind_by_name($emailquery, ':gnumber', $gnumber);
    oci_execute($emailquery);
    oci_fetch($emailquery);
    $publisher_email = oci_result($emailquery, 'EMAIL');
    $publisher_name = oci_result($emailquery, 'SPRIDEN_LAST_NAME').", ".oci_result($emailquery, 'SPRIDEN_FIRST_NAME');

    if (!$publisher_email) {
        $msg = "Error: failed to find a primary instructor for $crn.$term. Rejecting grade submission";
        $logger->logmsg($msg."\n");
        error_log($msg);
        exit(1);
    }
    $publisher_emails[] = $publisher_email;
    $publisher_names[] = $publisher_name;
}
$publisher_name_string = implode(", ", $publisher_names);

$logger->logmsg("Publisher: $publisher_name_string E-mail is: ".implode(", ", $publisher_emails)."\n", False);
$logger->add_publisher($publisher_name_string);

## We are shut down after the grading due date
$now = new DateTime("now", new DateTimeZone('America/Los_Angeles') );
$duedate = new DateTime("2020-12-18 14:00:00", new DateTimeZone('America/Los_Angeles'));
#$duedate = new DateTime("2019-06-12 14:00:00", new DateTimeZone('America/Los_Angeles'));
if ($now > $duedate) {
    $logger->logmsg("Instructor ".implode(", ", $publisher_emails)." submitted grades past the deadline and they were rejected.\n");
	$mailobj->send_deadline_missed_message($publisher_emails);
	exit(0);
}

$vi = new ValidateInstructor($connection, $super_users, $logger);

// Different sections will have different grade modes, even if they are crosslisted, so determine
// the valid grades per section
$valid_grades_per_crn = array();
foreach ($crn_terms as $crnterm) {
    $full_grade_mode = calculate_full_grade_mode($connection, $crnterm, $publisher_emails, $logger, $mailobj, $testing);
    $valid_grades = $full_grade_mode['values'];
    $logger->logmsg("Valid grades for $crnterm are: ".implode(", ", $valid_grades)."\n", False);
    $valid_grades_per_crn[$crnterm] = $valid_grades;
}

##
## First gets any administrative grades (I, RD, etc) that might be present
##
## This needs to move into the validate_grades function. And must be call by gnumber,
## instead of sweeping through everything using the foreach statement.
##
$admin_grade_by_gnumber = array();
if (($testing or $c['use_test_data']) && $use_mock) {
	$handle = fopen("./mock_admin.csv", "r");
	if ($handle) {
		while (($line = fgets($handle)) !== false) {
			// process the line read.
            $gnum_grade = explode(",", trim($line));
            if (trim($line) != "" && substr($line,0,1) != "#") {
                $admin_grade_by_gnumber[$gnum_grade[0]] = strtoupper($gnum_grade[1]);
            }
		}
		fclose($handle);
	} else {
        error_log("Instructed to mock but couldn't open file ./mock_admin.csv to load the mock administrative column, dying");
        exit(1);
	} 
} else {
    // This can be done per crosslist
    $column_id = get_administrative_grades_id($canvas_token, $canvas_host, $crnterm, $crosslist, $logger);
    if ($column_id) {
        $logger->logmsg("Found Administrative Grade column ID: $column_id\n", False);
        // this can be done per crosslist
        $admin_grade_by_gnumber = get_administrative_grades($column_id, $canvas_token, $canvas_host, $crnterm, $crosslist, $logger);
        if ($admin_grade_by_gnumber) {
            foreach ($admin_grade_by_gnumber as $gnumber) {
                $logger->logmsg("Found grade ".$admin_grade_by_gnumber[$gnumber]." for $gnumber\n", False);
            }
        }
    } else {
        // We are now requiring the administrative grade column
        //
        // If there is no administrative grade column then there was an error in the course shell provisioning. IT and T&L will need to address this problem
        // before the grades can be submitted.
        $msg = "Error: There is no administrative grade column for this course $crnterm. Grades were not submitted.\n";
        $logger->logmsg($msg);
        $subject = "[Grade Syncing - Canvas/Banner] Please try it again";
        $all = implode(", ", $crn_terms);
        $msg = "Thank you for submitting your final grades for $all. There is some important information missing or invalid grades. Please try submitting your grades again and ensure that you are entering the final grade in the administrative grade column. If the problem continues or if you have any questions, contact teach@fuller.edu. Thank you.";
        $mailobj->send_failure_message($publisher_emails, $subject, $msg);
        exit(1);
    }
}

//
// Validate the export file itself, does various integrity checks to ensure that the data 
// being exported has integrity
try {
    validate_export($content, $testing);
} catch (RuntimeException $e) {
    $subject = "[Grade Syncing - Canvas/Banner] There were problems with your grade export, please contact teach@fuller.edu";
    $msg = $e.getMessage();
    $mailobj->send_failure_message($publisher_emails, $subject, $msg);
}

// Now validate each grade and and make sure that there is an administrative grade
foreach ($content as $line) {
    if ($line != "") {
        $fields = explode(",",$line);
        $publisher_fullerid = $fields[1];
        $crnterm = $fields[5];
        $gnumber = $fields[7];
        if (count($fields) >= 12) {
            $grade = $fields[11];
        } else {
            // No grading schema most likely, final grade wasn't set in export
            $grade = False;
        }
        $crntermarray = explode(".",$crnterm);

        // Canvas will create multiple duplicate entries in a canvas user ID has multiple login ids
        // connect to it. We only care about the one with an SISID number, so ignore the rest.
        // The validate_export call above checks the grade submission and makes sure that every canvas user
        // in the export does have a line with a valid g-number. So we don't need to worry that we are missing
        // grades at this point.
        if ($gnumber != "") {
            // sloppy, but validate_grades will e-mail out an error message, log the error
            // and end the execution of the program if it determines that the grade is in fact
            // invalid. This should be refactored.
            // So note, this is the function which e-mails the faculty member if they submit an 
            // invalid grade.
            $valid_grades = $valid_grades_per_crn[$crnterm]; // valid grades are determined by section, not crosslist
            validate_grades($grade, $crnterm, $valid_grades, $gnumber, $admin_grade_by_gnumber, $publisher_emails, $logger, $mailobj, $testing);
            if (!in_array($publisher_fullerid, $super_users) && !$vi->validate($crntermarray[0], $crntermarray[1], $publisher_fullerid, $testing)) {
                $msg = "Instructor $publisher_fullerid is not the primary instructor for $crnterm, rejecting grades";
                $logger->logmsg($msg."\n");
                $subject = "[Grade Syncing - Canvas/Banner] Please try it again";
                $msg = "Thank you for submitting your final grades for $crnterm; however, your submission could not be accepted. Please note that only the primary instructor for the course can submit grades. If you are the primary instructor please try submitting your grades again. If the problem continues or if you have any questions, contact teach@fuller.edu. Thank you.";
                $mailobj->send_failure_message($publisher_emails, $subject, $msg);
                exit(1);
            }
        }
    }
}

//
// Insert the Grades
//

//
// The Insert Query
//
// Auditors:
//
// Auditors are treated as full students in Canvas, and will have a grade.
// However, the Registrar's office puts a value of 'AU' in sfrstcr_grde_code
// for all auditors. This code will only update the grade IF it does not already
// have a value of 'AU'.
//

$query = "
update sfrstcr set sfrstcr_grde_code = :grade
 where sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null)
   and sfrstcr_crn = :crn
   and sfrstcr_term_code = :termcode
   and not exists (select 1 from sfrstcr a where a.sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null) and a.sfrstcr_crn = :crn and a.sfrstcr_term_code = :termcode and a.sfrstcr_grde_code in ('RD','AU','I'))
";
$aicasa_query = "
update sfrstcr set sfrstcr_grde_code = 'RD', sfrstcr_gcmt_code = :grade
 where sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null)
   and sfrstcr_crn = :crn
   and sfrstcr_term_code = :termcode
   and not exists (select 1 from sfrstcr a where a.sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null) and a.sfrstcr_crn = :crn and a.sfrstcr_term_code = :termcode and a.sfrstcr_grde_code in ('RD','AU','I'))
";
$incomplete_query = "
update sfrstcr set sfrstcr_gcmt_code = 'INP'
 where sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null)
   and sfrstcr_crn = :crn
   and sfrstcr_term_code = :termcode
   and not exists (select 1 from sfrstcr a where a.sfrstcr_pidm = (select spriden_pidm from spriden where spriden_id = :gnumber and spriden_change_ind is null) and a.sfrstcr_crn = :crn and a.sfrstcr_term_code = :termcode and a.sfrstcr_grde_code in ('RD','AU','I'))
";


#exit;


foreach ($content as $line) {
    if ($line != "") {
        $fields = explode(",",$line);
        $publisher_fullerid = $fields[1];
        $crnterm = $fields[5];
        $gnumber = $fields[7];
        if (count($fields) >= 12) {
            $grade = $fields[11];
        } else {
            // No grading schema most likely, final grade wasn't set in export
            $grade = False;
        }
        $crntermarray = explode(".",$crnterm);

        // It is possible that we have a Canvas account that has a non-FullerID login, which would 
        // show up as a line in the export that does not have a gnumber. The call to validate_export
        // makes sure that there is a line for that student in the export which does have a gnumber,
        // so it is safe to ignore the non-gnumber lines when processing the grades.
        if ($gnumber != "") {
            ## Replace earned grades with Administrative Grades
            if (array_key_exists($gnumber, $admin_grade_by_gnumber)) {
                $grade = $admin_grade_by_gnumber[$gnumber];
            } else {
                // this should never happen, it should always be caught earlier in the grade validation process
                $logger->logmsg("Recording of grade $grade from $publisher_fullerid for $gnumber in section $crnterm failed because no administrative grade was present. This should never happen at this point\n");
                exit(1);
            }

            ## Handle everthing but incompletes
            if ($grade != "I") {
                $logger->logmsg("$publisher_fullerid published grade $grade for $gnumber in section $crnterm\n", False);

                ## If the grade is AIC or ASA then the actual grade we insert is RD and we put ASA/AIC
                ## in the comment field. So select the query that performs that function. 
                if ($grade == 'AIC' || $grade == 'ASA') {
                    $q = $aicasa_query;
                } else {
                    $q = $query;
                } 
                if ($c['skip_insert_into_banner'] != "false") {
                    error_log("skip_insert_into_banner is set in the config.ini");
                    error_log("Would have executed query: $q");
                } else {
                    $insertstmt = oci_parse($connection,$q);
                    if (!$insertstmt) {
                        $e = oci_error($connection);
                        $msg = "Fatal Error: Could not insert grades for $gnumber in $crnterm: ".$e['message']."\n";
                        error_log($msg);
                        $logger->logmsg($msg);
                        exit(1);
                    }
                    oci_bind_by_name($insertstmt, ':grade', $grade);
                    oci_bind_by_name($insertstmt, ':gnumber', $gnumber);
                    oci_bind_by_name($insertstmt, ':crn', $crntermarray[0]);
                    oci_bind_by_name($insertstmt, ':termcode', $crntermarray[1]);
                /* */
                    $r = oci_execute($insertstmt);
                    if (!$r) {
                        $e = oci_error($insertstmt);
                        $msg = "Fatal Error: Could not insert grades for $gnumber in $crnterm: ".$e['message']."\n";
                        $logger->logmsg($msg);
                        $mailobj->send_error_to_admin("Attempt to submit grades failed with insert error", $msg);
                        $mailobj->send_system_error_message($publisher_emails);
                        exit(1);
                    } else {
                        $msg = "Write Succeded\n";
                        $logger->logmsg($msg, False);
                        $logger->logmsg("Rows inserted ".oci_num_rows($insertstmt)."\n", False);
                    }
                }
            ## Handle incomplete grades
            } else {
                $q = $incomplete_query;
                $logger->logmsg("$publisher_fullerid published grade $grade for $gnumber in section $crnterm. It was recorded in Banner as comment INP\n", False);
                if ($c['skip_insert_into_banner'] != "false") {
                    error_log("skip_insert_into_banner is set in the config.ini");
                    error_log("Would have executed query: $q");
                } else {
                    $insertstmt = oci_parse($connection,$q);
                    if (!$insertstmt) {
                        $e = oci_error($connection);
                        $msg = "Fatal Error: Could not insert grades for $gnumber in $crnterm: ".$e['message']."\n";
                        error_log($msg);
                        $logger->logmsg($msg);
                        exit(1);
                    }

                    oci_bind_by_name($insertstmt, ':gnumber', $gnumber);
                    oci_bind_by_name($insertstmt, ':crn', $crntermarray[0]);
                    oci_bind_by_name($insertstmt, ':termcode', $crntermarray[1]);
                    $r = oci_execute($insertstmt);

                    if (!$r) {
                        $e = oci_error($insertstmt);
                        $msg = "Fatal Error: Could not insert grades for $gnumber in $crnterm: ".$e['message']."\n";
                        $logger->logmsg($msg);
                        $mailobj->send_error_to_admin("Attempt to submit grades failed with insert error", $msg);
                        $mailobj->send_system_error_message($publisher_emails);
                        exit(1);
                    } else {
                        $msg = "Write Succeded\n";
                        $logger->logmsg($msg, False);
                        $logger->logmsg("Rows inserted ".oci_num_rows($insertstmt)."\n", False);
                    }
                }
            }
        }
    }
}

// Now let people know we have inserted the grades
$subject = "[Grade Syncing Submission] $publisher_name_string has sumbitted the following roster for ".implode(", ", $crn_terms)." through Canvas";
if ($crosslist) {
    $msg_a = ["$publisher_name_string has submitted the following roster for crosslist $crosslist with section(s): ".implode(", ", $crn_terms), ""];
} else {
    $msg_a = ["$publisher_name_string has submitted the following roster for section(s): ".implode(", ", $crn_terms), ""];
}

foreach ($content as $line) {
    if ($line != "") {
        $fields = explode(",",$line);
        $publisher_fullerid = $fields[1];
        $crnterm = $fields[5];
        $gnumber = $fields[7];
        if (count($fields) >= 12) {
            $grade = $fields[11];
        } else {
            // No grading scheme most likely, final grade was not assigned
            $grade = False;
        }
        $crntermarray = explode(".",$crnterm);

        $name = $mailobj->get_name_from_gnumber($connection, $gnumber);
        if ($admin_grade_by_gnumber[$gnumber] == "I") {
	    if ($grade) {
	        $msg_a[] = str_pad($name, 30)."\t$gnumber\t".$admin_grade_by_gnumber[$gnumber]." with an earned grade of $grade";
	    } else {
            $msg_a[] = str_pad($name, 30)."\t$gnumber\t".$admin_grade_by_gnumber[$gnumber]." and no earned grade was recorded";
	    }
        } else {
            $msg_a[] = str_pad($name, 30)."\t$gnumber\t".$admin_grade_by_gnumber[$gnumber];
        }
    }
}

//send_mono_email($addresses, $subject, implode("\n",$msg_a), $logger, $c);
if ($crosslist) {
    $filename = $crosslist."-".implode("-", $crn_terms)."-roster.txt";
} else {
    $filename = implode("-", $crn_terms)."-roster.txt";
}

$full_filename = $c['roster_directory']."/".$filename;
file_put_contents($full_filename, implode("\n",$msg_a));

if ($crosslist) {
    $mailobj->send_success_message($publisher_emails, $crosslist);
} else {
    $mailobj->send_success_message($publisher_emails, implode(", ", $crn_terms));
}

?>


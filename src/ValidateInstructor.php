<?php

##
## Class that handles the validation of the instructor's permission 
## to post the grades
##

class ValidateInstructor {
    var $crn_term_to_fullerid = array();
    var $oracle_connection;
    var $superuser_exceptions;
    var $logger;

    public function __construct($oracle_connection, $superuser_exceptions, $logger) {
        $this->oracle_connection = $oracle_connection;
        $this->superuser_exceptions = $superuser_exceptions;
        $this->logger = $logger;
    }

    function get_primary_instructor($crn, $term, $testing) {
        $query = "
select spriden_id, sirasgn_crn, sirasgn_term_code
  from sirasgn join spriden on spriden_pidm = sirasgn_pidm and spriden_change_ind is null
 where sirasgn_primary_ind = 'Y'
   and sirasgn_term_code = :termcode
   and sirasgn_crn = :crn
";
        $insertstmt = oci_parse($this->oracle_connection,$query);
        oci_bind_by_name($insertstmt, ':crn', $crn);
        oci_bind_by_name($insertstmt, ':termcode', $term);
        oci_execute($insertstmt);
        oci_fetch($insertstmt);
        $primary_instructor = oci_result($insertstmt, 'SPRIDEN_ID');

        if (!$primary_instructor) {
            $msg = "Error: failed to find a primary instructor for $crn.$term. Rejecting grade submission";
            $this->logger->logmsg($msg."\n");
            $msg = implode(", ",$publisher_ids)." attempted to submit grades for section(s) ".implode(", ",$crn_terms)." but we were unable to process the request because we could not find a primary instructor.\n";
            send_error("Attempt to submit grades failed", $msg, $this->logger, $testing);
            send_failure_message($publisher_emails, $this->logger, $testing);
            exit(1);
        }

        return $primary_instructor;
    }

    function validate($crn, $term, $fuller_id, $testing) {

        ## See if they are a primary instructor in Banner OR a super user

        # Get the Primary Instructor
        if (!array_key_exists("$crn.$term", $this->crn_term_to_fullerid)) {
            // Get get primary instructor for the crn, term
            $this->crn_term_to_fullerid["$crn.$term"] = $this->get_primary_instructor($crn, $term, $testing);
        }

        ##echo("Comparing ".$this->crn_term_to_fullerid["$crn.$term"]." to $fuller_id\n");
        ## Must be the primary instructor for the course or a super user
        if ($this->crn_term_to_fullerid["$crn.$term"] == $fuller_id) {
            $msg = "Validated that $fuller_id is the primary instuctor of $crn.$term in Banner";
            #$this->logger->logmsg($msg."\n");
            return True;
        } elseif (in_array($fuller_id, $this->superuser_exceptions)) {
            $msg = "Validated that $fuller_id is able to submit grades for $crn.$term because they are a super user";
            #$this->logger->logmsg($msg."\n");
            return True;
        } else {
            $msg = "$fuller_id is NOT able to submit grades for $crn.$term.";
            #$this->logger->logmsg($msg."\n");
            return False;
        }
    }
}

?>

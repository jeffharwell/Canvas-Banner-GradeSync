<?php

##
## Functions for Sending E-mail
##

class GradeSyncEmail {
    var $config;
    var $logger;

    public function __construct($config, $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /* Get the instructors name so that we can use it in the e-mail message */
    function get_name_from_gnumber($connection, $gnumber) {
        $query = "select spriden_last_name, spriden_first_name
                  from spriden
                  where spriden_change_ind is null
                  and spriden_id = :gnumber";
        $namequery = oci_parse($connection,$query);
        oci_bind_by_name($namequery, ':gnumber', $gnumber);
        oci_execute($namequery);
        oci_fetch($namequery);
        $publisher_name = oci_result($namequery, 'SPRIDEN_LAST_NAME').", ".oci_result($namequery, 'SPRIDEN_FIRST_NAME');
        return($publisher_name);
    }

    function create_cc_string($addresses) {
        $address_string = implode(', ', $addresses);
        return("Cc: $address_string");
    }


    /* Send an e-mail message */
    /*
    function send_mono_email($addresses, $cc_addresses, $subject, $message, $logger, $config) {
        $cc_emails = create_cc_string($cc_addresses);
        $cc_emails = "Cc: Jeff Harwell <jharwell@fuller.edu>";
        if (!$testing) {
            ##$cc_emails = "Cc: Teach <teach@fuller.edu>, Grades <grades@fuller.edu>";
            ## As per Todd, the actual grades are only sent to him, and me :(
            $cc_emails = "Grades <grades@fuller.edu>";
        }

        $headers = 'From: grades@fuller.edu' . "\r\n" .
                'Reply-To: grades@fuller.edu' . "\r\n" .
            'X-Mailer: PHP/' . phpversion() . "\r\n" .
            $cc_emails . "\r\n" .
            "Content-Type: text/html; charset=ISO-8859-1\r\n";
        $html_msg = "<html><body><pre>".$message."</pre></body></html>";
        foreach ($addresses as $address) {
            $logger->logmsg("Sending message: $message to $address\n", False);
            mail($address, $subject, $html_msg, $headers);
        }
    }
     */

    function send_email($addresses, $cc_addresses, $subject, $message) {
        $cc_email = False;
        if (count($cc_addresses) >= 1) { 
            $cc_email = $this->create_cc_string($cc_addresses);
        }

        $headers = 'From: grades@fuller.edu' . "\r\n" .
                'Reply-To: grades@fuller.edu' . "\r\n" .
            'X-Mailer: PHP/' . phpversion() . "\r\n";
        if ($cc_email) {
            $headers = $headers.$cc_email."\r\n";
        }

        foreach ($addresses as $address) {
            $this->logger->logmsg("Sending $address message: $message\n", False);
            if ($this->config['email_testing'] != 'false') {
                if ($cc_email) {
                    error_log("Email Testing - would send $address and $cc_email the message: $subject: $message");
                } else {
                    error_log("Email Testing - would send $address the message: $subject: $message");
                }
            } else {
                mail($address, $subject, $message, $headers);
            }
        }
    }

    function send_error_to_admin($subject, $message) {
        if ($this->config['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
        } else {
            $addresses = $this->config['admin_address'];
        }

        $headers = 'From: grades@fuller.edu' . "\r\n" .
                'Reply-To: grades@fuller.edu' . "\r\n" .
            'X-Mailer: PHP/' . phpversion() . "\r\n";
        foreach ($addresses as $address) {
            $this->logger->logmsg("Sending error message to $address via e-mail subject: $subject\n");
            if ($this->config['email_testing'] != 'false') {
                error_log("Testing send_error_to_admin: Would send $addresses the following subject: $subject message: $message");
            } else {
                mail($address, $subject, $message, $headers);
            }
        }
    }

    ##
    ## This function take a log message and sends it to the specified e-mail addresses.
    ##
    function send_log_error($subject, $message, $addresses) {
        foreach ($addresses as $address) {
            $headers = 'From: jharwell@fuller.edu' . "\r\n" .
                    'Reply-To: jharwell@fuller.edu' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();

            if ($this->config['email_testing'] != 'false') {
                error_log("Testing send_log_error: Would send $address the following subject: $subject message: $message");
            } else {
                mail($address, $subject, $message, $headers);
            }
        }
    }

    function send_failure_message($addresses, $subject, $message) {
        if ($this->config['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
            $cc_addresses = [];
        } else {
            ## We are not testing, add the cc addreses
            $cc_addresses = $this->config['other_address'];
        }

        $this->send_email($addresses, $cc_addresses, $subject, $message);
    }

    function send_window_not_open_message($addresses, $window_open_date) {
        if (c['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
            $cc_addresses = [];
        } else {
            ## We are not testing, add the cc addreses
            $cc_addresses = $this->config['other_address'];
        }
	$ds = date_format($window_open_date, 'm-d-Y');
        $subject = "[Grade Syncing - Canvas/Banner] Grade Submission Window Does Not Open Until $ds";
        $msg = "The grade submission window is currently not open so the system cannot accept your grade submission . Please wait until $ds and try again. Please contact the Registrar's office at grades@fuller.edu or 626.384.5413 if you have any questions. Thank you.";
        $this->send_email($addresses, $cc_addresses, $subject, $msg);
    }


    function send_deadline_missed_message($addresses) {
        if (c['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
            $cc_addresses = [];
        } else {
            ## We are not testing, add the cc addreses
            $cc_addresses = $this->config['other_address'];
        }

        $subject = "[Grade Syncing - Canvas/Banner] Please Contact The Registrar's Office for Assistance";
        $msg = "Thank you for submitting your final grades. It is now past the deadline for online grade submission so your grades were not submitted. Please contact the Registrar's office at grades@fuller.edu or 626.384.5413 if you need to make any changes to your grade submission for this quarter. Thank you.";
        $this->send_email($addresses, $cc_addresses, $subject, $msg);
    }

    function send_system_error_message($addresses) {
        if (c['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
            $cc_addresses = [];
        } else {
            ## We are not testing, add the cc addreses
            $cc_addresses = $this->config['other_address'];
        }

        $subject = "[Grade Syncing - Canvas/Banner] Please try it again";
        $msg = "Thank you for submitting your final grades. There was a system error. Please try again and if the problem continues or if you have any questions, contact teach@fuller.edu. Thank you.";
        $this->send_email($addresses, $cc_addresses, $subject, $msg);
    }

    function send_success_message($addresses, $class) {
        if ($this->config['is_testing'] != "false") {
            ## we are testing, send to the configured test_address instead of the 
            ## specified address and do not 'cc'
            $addresses = $this->config['test_address'];
            $cc_addresses = [];
        } else {
            ## We are not testing, add the cc addreses
            $cc_addresses = $this->config['success_address'];
        }

        $subject = "[Grade Syncing - Canvas/Banner] We have received your final grades for $class";
        $msg = "Your final grades have successfully been submitted for $class. If you have any questions, please contact grades@fuller.edu. Thank you.";
        $this->send_email($addresses, $cc_addresses, $subject, $msg);
    }
}

?>

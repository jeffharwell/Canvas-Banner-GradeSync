<?php

class ErrorLogger {
    var $courseid;
    var $publisher;
    var $logfile;
    var $admin_emails;
    var $mailobj;

    /*
     * Param $admin_emails an array of e-mail addresses
     */
    public function __construct($logfile, $admin_emails) {
        $this->logfile = $logfile;
        $this->admin_emails = $admin_emails;
    }

    public function add_courseid($courseid) {
        $this->courseid = $courseid;
    }

    public function add_publisher($publisher) {
        $this->publisher = $publisher;
    }

    public function add_mailobj($mailobj) {
        $this->mailobj = $mailobj;
    }

    public function logmsg($msg, $send_email=True) {
      $prefix = date("Y-m-d H:i:s");
      if ($this->courseid) {
          $prefix = $prefix." ".$this->courseid;
      }
      if ($this->publisher) {
          $prefix = $prefix." ".$this->publisher;
      }
      if ($prefix) {
          $prefix = $prefix.": ";
      }

      ## Explicitly catch any arrays and convert to strings
      if (is_array($msg)) {
          $msg = implode(" ", $msg);
      }

      error_log("$prefix$msg",3,$this->logfile);
      if ($send_email) {
          $this->mailobj->send_log_error("Log Message from Online Grade Submission", "$prefix$msg", $this->admin_emails);
      }
    }
}

?>

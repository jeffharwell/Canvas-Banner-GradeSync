<?php declare(strict_types=1);

function logmsg($msg) {
  error_log("$msg",3,'/tmp/sys_export_err.log');
}

function get_administrative_grades_id($token, $host, $crnterm) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://$host/api/v1/courses/sis_course_id:$crnterm/custom_gradebook_columns",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_POSTFIELDS => "",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer $token",
        "Postman-Token: 9a31f3ca-2efc-4cd5-8ccf-de99c102a415",
        "cache-control: no-cache"
      ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
      logmsg("cURL Error #:" . $err."\n");
    } else {
      logmsg("Response from Canvas: $response\n");
      $json = json_decode($response);
      #logmsg($json[0]->{"id"}."\n");
      #var_dump($json);
      # If the section exists the response looks like: {"id":412,"title":"Administrative Grade","position":1,"teacher_notes":false,"read_only":false,"hidden":false}
      # If the section does not exist the response looks like: {"errors":[{"message":"The specified resource does not exist."}]}
      if (isset($json->{"errors"})) {
          echo("Exception: ".$json->{"errors"}[0]->{"message"}."\n");
          throw new Exception("$crnterm does not exist in Canvas");
      }
      foreach ($json as $column) {
          if ($column->{"title"} == "Administrative Grade") {
              return($column->{"id"});
          }
      }
    }
    return(False);
}

function provision_administrative_grades_column($token, $host, $crnterm) {
	$curl = curl_init();

	curl_setopt_array($curl, array(
	  CURLOPT_URL => "https://$host/api/v1/courses/sis_course_id:$crnterm/custom_gradebook_columns?column[title]=Administrative%20Grade&column[hidden]=false&column[read_only]=false",
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => "",
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 30,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	  CURLOPT_CUSTOMREQUEST => "POST",
	  CURLOPT_POSTFIELDS => "",
	  CURLOPT_HTTPHEADER => array(
		"Authorization: Bearer $token",
		"Postman-Token: 97188d25-7a3a-4ec5-9a55-ea0cf4f1238b",
		"cache-control: no-cache"
	  ),
	));

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

    if ($err) {
      logmsg("cURL Error #:" . $err."\n");
    } else {
      /* Response looks like:
       * {
       * "id": 121,
       * "title": "Administrative Grade",
       * "position": 1,
       * "teacher_notes": false,
       * "read_only": false,
       * "hidden": false
       * }
       */
      logmsg("Response from Canvas: $response\n");
      $json = json_decode($response);
	  #var_dump($json);
      if ($json->{"title"} == "Administrative Grade") {
	      return($json->{"id"});
      } 
    }
    return(False);
}


// Parse our config file to get credentials
if (file_exists("/var/www/config.ini")) {
    $c = parse_ini_file("/var/www/config.ini");
} else if (file_exists("../config.ini")) {
    $c = parse_ini_file("../config.ini");
} else if (file_exists("./config.ini")) {
    $c = parse_ini_file("./config.ini");
} else {
    logmsg("Error: was unable to find config.ini in the parent directory or current directory");
    echo "Server Error: Unable to read configuration file\n";
    exit(1);
}

## Set up the canvas connection info
$canvas_token = $c['token'];
$canvas_host = $c['canvas_host'];

## Grab the list of pilot courses
#$data = file_get_contents("./202004_courses.txt");
#$data = file_get_contents("./nonexistant_course.txt");
$data = file_get_contents("./oneoff_courses.txt");
#$data = file_get_contents("./202004_graded_courses.txt");
if (!$data) {
	echo "Failed to read in pilot course list from ./sample_grades.csv, dying\n";
	exit(1);
}
$lines = explode("\n", $data); // don't judge me

## Loop through our pilot courses
foreach ($lines as $line) {
    if ($line != "") {
        ## Try to get the ID of the administrative column, will return false if it doesn't exist
		##   ... upon further reflect this should really be a try, catch block ...
        try {
		    $id = get_administrative_grades_id($canvas_token, $canvas_host, $line);
        } catch (Exception $e) {
            #echo("Exception $e\n");
            $id = -1;
        }

        if ($id < 0) {
            echo "$line does not exist in Canvas\n";
        } else if ($id) {
			## we got an ID, so the crn already has an administrative column
			echo "$line has an administrative grade column\n";
		} else {
			## no id, it actually returns False :/ ... so provision one
			echo "$line does NOT have an administrative grade column, provisioning ...\n";
			$id = provision_administrative_grades_column($canvas_token, $canvas_host, $line);
			if ($id) {
				echo "   Provisioning Successful, Columum ID = $id\n";
			} else {
				## If the Admin column fails to provision then bail out, we need to do some
				## troubleshooting, not just continue trying.
				echo "   Provisioning Failed, please troubleshoot and try again. Dying.\n";
				exit(1);
			}
		}
    }
}

?>

<?php

##
## Functions that use the Canvas API to get Administrative Grades (I, RD, etc)
##

#
# Convert a header with links into an array of links 
#
function return_links($headers) {
	$link = [];
	if (array_key_exists("link", $headers)) {
		$split_links = function($u) {
			return explode(',', $u);
		};
		$make_tuple = function($u) {
			// "<https://fuller.instructure.com/api/v1/accounts/1/terms?workflow_state%5B%5D=all&page=1&per_page=10>; rel="current"" ->
			// array("<https://fuller.instructure.com/api/v1/accounts/1/terms?workflow_state%5B%5D=all&page=1&per_page=10>", " rel="current"")
			$elements = explode(';', $u, 2);

			// <https://fuller.instructure.com/api/v1/accounts/1/terms?workflow_state%5B%5D=all&page=1&per_page=10> -> 
			// https://fuller.instructure.com/api/v1/accounts/1/terms?workflow_state%5B%5D=all&page=1&per_page=10
			$link = str_replace("<", "", $elements[0]);
			$link = str_replace(">", "", $link);

			//  rel="last" -> last
			$destination = explode("=", $elements[1]);
			$destination = str_replace('"', "", $destination[1]);

			// make sure that we got something valid
			$valid_destinations = ['current','next','prev','first','last'];
			if (!in_array($destination, $valid_destinations)) {
				call_user_func($this->logger,"$process_name: link header contained invalid destination: $destination");
				call_user_func($this->logger,"$process_name: full link header $u");
				echo "$process_name: link header contained invalid destination: $destination\n";
				echo "$process_name: full link header $u\n";
				exit(1);
			}

			return [$destination, $link];
		};
		//$link_structure = array_map($split_one, $split_links($headers["link"][0]));
        $link_structure = $split_links($headers['link'][0]);
		$link_structure = array_map($make_tuple, $link_structure);
		foreach ($link_structure as $u) {
			$link[$u[0]] = $u[1];
		}
	}
	return $link;
}

#
# Get the actual administrative grades for the course
#
function get_administrative_grades($column_id, $token, $host, $crnterm, $crosslist, $logger, $next_url = False) {
	$curl = curl_init();

	$canvas_course_id = $crnterm;
	if ($crosslist) {
		## this is a crosslisted course, used the crosslist ID to get the column, not the
		## usual CRN
		$canvas_course_id = $crosslist;
	}

	$url = "https://$host/api/v1/courses/sis_course_id:$canvas_course_id/custom_gradebook_columns/$column_id/data";
    if ($next_url) {
		$url = $next_url;
	}

	curl_setopt_array($curl, array(
	  CURLOPT_URL => $url,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => "",
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 30,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	  CURLOPT_CUSTOMREQUEST => "GET",
	  CURLOPT_POSTFIELDS => "",
	  CURLOPT_HTTPHEADER => array(
		"Authorization: Bearer $token",
		"Postman-Token: 4959929b-f369-4ba2-abab-6e216343a250",
		"cache-control: no-cache"
	  ),
	));
	// this function is called by curl for each header received
	// see https://stackoverflow.com/questions/9183178/can-php-curl-retrieve-response-headers-and-body-in-a-single-request
	// credits to https://stackoverflow.com/users/637874/geoffrey
	$headers = [];
	curl_setopt($curl, CURLOPT_HEADERFUNCTION,
	  function($curl, $header) use (&$headers)
	  {
		$len = strlen($header);
		$header = explode(':', $header, 2);
		if (count($header) < 2) // ignore invalid headers
		  return $len;

		$name = strtolower(trim($header[0]));
		if (!array_key_exists($name, $headers))
		  $headers[$name] = [trim($header[1])];
		else
		  $headers[$name][] = trim($header[1]);

		return $len;
	  }
	);

	$response = curl_exec($curl);
	$err = curl_error($curl);
	curl_close($curl);

	$link = return_links($headers);
	//var_dump($link);

	if ($err) {
	  $logger->logmsg("cURL Error #:" . $err."\n");
	} else {
	  $logger->logmsg("Canvas Response: $response\n", False);
          $grade_by_number = array();
          $json = json_decode($response);
          foreach ($json as $grade) {
              $gnumber = get_gnumber_from_id($grade->{"user_id"}, $token, $host, $logger);
              $grade_by_gnumber[$gnumber] = trim(strtoupper($grade->{"content"}));
          }
          if (array_key_exists("next", $link)) {
              // recurse, recurse, ambulate over the ... web
              $logger->logmsg("Multiple pages of administrative grades, getting the next page", False);
              $next_grades = get_administrative_grades($column_id, $token, $host, $crnterm, $crosslist, $logger, $link['next']);
              //echo "Current Grades:\n";
              //var_dump($grade_by_gnumber);
              //echo "Next Grades:\n";
              //var_dump($next_grades);
              return(array_merge($grade_by_gnumber, $next_grades));
          }
          return($grade_by_gnumber);
	}
    return(False);
}

#
# Get the id number of the administrative grades column for the given course
#
function get_administrative_grades_id($token, $host, $crnterm, $crosslist, $logger) {
    $curl = curl_init();

    $canvas_course_id = $crnterm;
        if ($crosslist) {
        ## this is a crosslisted course, used the crosslist ID to get the column, not the
        ## usual CRN
        $canvas_course_id = $crosslist;
    }

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://$host/api/v1/courses/sis_course_id:$canvas_course_id/custom_gradebook_columns",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_POSTFIELDS => "",
      CURLOPT_HTTPHEADER => array(
    	"Authorization: Bearer $token",
    	"cache-control: no-cache"
      ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    $logger->logmsg("Attempting to get the administrative grade column from $canvas_course_id\n", False);
    if ($err) {
      $logger->logmsg("cURL Error #:" . $err."\n");
    } else {
      $logger->logmsg("Response from Canvas: $response\n", False);
      $json = json_decode($response);
      #$logger->logmsg($json[0]->{"id"}."\n");
      foreach ($json as $column) {
         if ($column->{"title"} == "Administrative Grade") {
             return($column->{"id"});
         }
      }
    }
    return(False);
} 

# Get the GNumber for a given Canvas ID
function get_gnumber_from_id($user_id, $token, $host, $logger) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://$host/api/v1/users/$user_id",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_POSTFIELDS => "",
      CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer $token",
        "cache-control: no-cache"
      ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
      $logger->logmsg("cURL Error #:" . $err. "\n");
    } else {
      $logger->logmsg("Canvas Response: ".$response."\n", False);
      $json = json_decode($response);
      return($json->{"sis_user_id"});
    }
    return(False);
}


?>

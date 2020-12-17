<?php
declare(strict_types=1);

/*
 * Functions that Interact With Banner
 */
class CanvasLibrary {
    private $config;
    private $debug = false;

    function __construct($config, $logger, $debug=false) {
        $this->config = $config;
        $this->debug = $debug;
    }

    function get_courses() {
        /* Now do the prep and send up the curl request */
        if ($this->config['is_testing'] === 'false') {
            $INSTANCE = $this->config['prod_canvas_host'];
            $TOKEN = $this->config['prod_token'];
        } else {
            $INSTANCE = $this->config['test_canvas_host'];
            $TOKEN = $this->config['test_token'];
        }

        $graphql_query = <<<'EOD'
query ListSectionsForTerm {
  term(id: "112") {
    name
    coursesConnection {
      nodes {
        _id
        name
        state
        sisId
      }
    }
  }
}
EOD;

        $url = "https://$INSTANCE/api/graphql";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer $TOKEN"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,
        http_build_query(array('query' => $graphql_query)));

        $results = curl_exec($ch);

        if ($this->debug) {
            echo "----------\nGetting Sections from Canvas\n";
            echo "Contacting: $url\n";

            echo "Results: \n";
            if ($results) {
                echo "Raw JSON:\n";
                echo $results."\n";
                //var_dump(json_decode($results));
            } else {
                echo "No Results Found!\n";
            }
        }

        $all_courses = array();
        if ($results !== false) {
            $structure = json_decode($results);
            //var_dump($structure);
            foreach ($structure->data->term->coursesConnection->nodes as $node) {
                //var_dump($node);
                //echo($node->sisId."\n");
                if ($node->state != "deleted" and !is_null($node->sisId)) {
                    array_push($all_courses, $node->sisId);
                }
            }
        } else {
            throw new RuntimeException("Fatal Error: No results return when attempting to resolve termcode");
        }
        return($all_courses);
    }

    function get_sections() {
        /* Now do the prep and send up the curl request */
        if ($this->config['is_testing'] === 'false') {
            $INSTANCE = $this->config['canvas_host'];
            $TOKEN = $this->config['token'];
        } else {
            $INSTANCE = $this->config['canvas_host_test'];
            $TOKEN = $this->config['token_test'];
        }

        $graphql_query = <<<'EOD'
query ListSectionsForTerm {
  term(id: "112") {
    name
    coursesConnection {
      nodes {
        _id
        name
        state
        sisId
        sectionsConnection {
          nodes {
            _id
            name
            sisId
          }
        }
      }
    }
  }
}
EOD;

        $url = "https://$INSTANCE/api/graphql";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer $TOKEN"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,
        http_build_query(array('query' => $graphql_query)));

        $results = curl_exec($ch);

        if ($this->debug) {
            echo "----------\nGetting Sections from Canvas\n";
            echo "Contacting: $url\n";

            echo "Results: \n";
            if ($results) {
                echo "Raw JSON:\n";
                echo $results."\n";
                //var_dump(json_decode($results));
            } else {
                echo "No Results Found!\n";
            }
        }

        $all_sections = array();
        if ($results !== false) {
            $structure = json_decode($results);
            //var_dump($structure);
            foreach ($structure->data->term->coursesConnection->nodes as $node) {
                //var_dump($node);
                if (array_key_exists('sectionsConnection', $node)) {
                    //echo("Has sections\n");
                    $sections = $node->sectionsConnection->nodes;
                    foreach ($sections as $s) {
                        //echo($s->sisId."\n");
                        array_push($all_sections, $s->sisId);
                    }
                }
            }
        } else {
            throw new RuntimeException("Fatal Error: No results return when attempting to resolve termcode");
        }
        return($all_sections);
    }

} // end of BannerCanvas class

/*
// Parse our config file to get credentials
if (file_exists("/var/www/config.ini")) {
    $c = parse_ini_file("/var/www/config.ini");
} else if (file_exists("../config.ini")) {
    $c = parse_ini_file("../config.ini");
} else if (file_exists("./config.ini")) {
    $c = parse_ini_file("./config.ini");
} else {
    error_log("Error: was unable to find config.ini in the parent directory or current directory");
    echo "Server Error: Unable to read configuration file\n";
    exit(1);
}

// Create a logger function (this looks like Javascript .. cool)
$process_name = 'Get All Sections';
$logger = function($mesg) use ($c, $process_name) {
    // When the "anonomous" function is called, log the message
    $d = date("m/d/y G.i:s", time());
    file_put_contents($c['applicationlog'], $d.": ".$process_name.": ".$mesg."\n", FILE_APPEND);
};


$cl = new CanvasLibrary($c, $logger, true);
//$sections = $cl->get_sections();
$courses = $cl->get_courses();
//var_dump($sections);
var_dump($courses);
 */

?>

<?php
    define('APP_ENV',     'dev');
    define('APP_DIR',     dirname(__FILE__));
    define('APP_VERSION', '0.0.1');

    require_once('autoloader.php');

    use App\Utils;
    use Data\Database;
    use Data\Files;
    use Functions\MapDetails;
    use Functions\Download;
    use Functions\Rate;

    $config  = require_once(__DIR__ . '/include/config/' . APP_ENV . '_config.php');
    $request = null;

    class Apiv1
    {
        private $utils       = null;
        private $dbHandler   = null;
        private $fileHandler = null;

        public function __construct() {
            global $config, $request;

            $this -> utils       = new App\Utils();
            $this -> dbHandler   = new Data\Database();
            $this -> fileHandler = new Data\Files();

            $request = $this -> utils -> parse_path();
        }

        public function start() {
            global $config, $request;

            $response = Array();

            if ($request['call_parts'][2] === 'getMaps') {
                $mapDetailFunc = new Functions\MapDetails();

                $response = $mapDetailFunc -> getApiResponse($this -> dbHandler);
            } elseif ($request['call_parts'][2] === 'downloadMap') {
                $downloadFunc = new Functions\Download();
                $fullPath     = $downloadFunc -> getApiResponse($this -> dbHandler);

                if ($fullPath === null) {
                    header('Cache-Control: no-cache, must-revalidate');
                    header('Content-type: application/json');

                    $response['Status']  = 'ERROR';
                    $response['Message'] = 'Requested map does not exist!';
                    print(json_encode($response, JSON_PRETTY_PRINT));

                    Exit;
                };

                ignore_user_abort(true);
                // Disable the time limit for this script
                set_time_limit(0);

                if ($fileData = fopen ($fullPath, 'r')) {
                    $fileSize  = filesize($fullPath);
                    $pathParts = pathinfo($fullPath);

                    header('Content-type: application/octet-stream');
                    // Use 'attachment' to force a file download
                    header('Content-Disposition: attachment; filename="' . $pathParts['basename'] . '"');
                    header('Content-length: ' . $fileSize);
                    // Use this to open files directly
                    header('Cache-control: private');

                    while(!feof($fileData)) {
                        $buffer = fread($fileData, 2048);
                        echo $buffer;
                    };
                };

                fclose ($fileData);
                Exit;
            } else {
                $response['Status']  = 'ERROR';
                $response['Message'] = 'Requested function does not exist!';
            };

            $this -> dbHandler -> Destroy();
            print(json_encode($response, JSON_PRETTY_PRINT));
        }
    }

    $api = new Apiv1();
    $api -> start();

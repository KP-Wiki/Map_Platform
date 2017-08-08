<?php
    /**
     * The internal security class
     *
     * This package should be used for all security features
     *
     * PHP version 7
     *
     * @package MapPlatform
     * @subpackage Core
     * @author  Thimo Braker <thibmorozier@gmail.com>
     * @version 1.0.0
     * @since   First available since Release 1.0.0
     */
    namespace MapPlatform\Core;

    use InvalidArgumentException;

    /**
     * Security
     *
     * @package    MapPlatform
     * @subpackage Core
     * @author     Thimo Braker <thibmorozier@gmail.com>
     * @version    1.0.0
     * @since      First available since Release 1.0.0
     */
    class Security
    {
		/** @var \Slim\Container $container The framework container */
        private $container;

        /**
         * Security constructor.
         *
         * @param \Slim\Container The application controller.
         */
        public function __construct($aContainer) {
            $this->container = $aContainer;
        }

        /**
         * PBKDF2 key derivation function as defined by RSA's PKCS #5: https://www.ietf.org/rfc/rfc2898.txt
         * Test vectors can be found here: https://www.ietf.org/rfc/rfc6070.txt
         *
         * @param string The hash algorithm to use. Recommended: SHA256
         * @param string The password.
         * @param string A salt that is unique to the password.
         * @param int Iteration count. Higher is better, but slower. Recommended: At least 1000.
         * @param int The length of the derived key in bytes.
         * @param boolean If true, the key is returned in raw binary format. Hex encoded otherwise.
         *
         * @return mixed A $keyLength-byte key derived from the password and salt.
         */
        private function pbkdf2($algorithm, $password, $salt, $count, $keyLength, $rawOutput = False) {
            $algorithm = strtolower($algorithm);

            if (!in_array($algorithm, hash_algos(), True))
                trigger_error('PBKDF2 ERROR: Invalid hash algorithm.', E_USER_ERROR);

            if ($count <= 0 || $keyLength <= 0)
                trigger_error('PBKDF2 ERROR: Invalid parameters.', E_USER_ERROR);

            if (function_exists('hash_pbkdf2')) {
                // The output length is in NIBBLES (4-bits) if $rawOutput is false!
                if (!$rawOutput)
                    $keyLength = $keyLength * 2;

                return hash_pbkdf2($algorithm, $password, $salt, $count, $keyLength, $rawOutput);
            };

            $hashLength = StrLen(hash($algorithm, "", True));
            $blockCount = ceil($keyLength / $hashLength);

            $output = "";

            for ($i = 1; $i <= $blockCount; $i++) {
                // $i encoded as 4 bytes, big endian.
                $last = $salt . pack('N', $i);
                // first iteration
                $last = $xorSum = hash_hmac($algorithm, $last, $password, True);

                // perform the other $count - 1 iterations
                for ($j = 1; $j < $count; $j++) {
                    $xorSum ^= ($last = hash_hmac($algorithm, $last, $password, True));
                };

                $output .= $xorSum;
            };

            return ($rawOutput ? SubStr($output, 0, $keyLength) : bin2hex(SubStr($output, 0, $keyLength)));
        }
		
		/**
         * Compare the given password and salt with the given hash
         *
         * @param string The raw password to compare with
         * @param string The salt to use for the comparison
         * @param string The hash to compare against
         *
         * @return boolean
         */
        public function isValidPassword($password, $salt, $hash) {
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');
            $algorithm      = 'sha512';
            $iterationCount = 1024;
            $keyLength      = 1024;
            $outputRaw      = False;
            $hashVal        = $this->pbkdf2($algorithm, $password, $salt, $iterationCount, $keyLength, $outputRaw);

            // We can't use Levenshtein because our hashes are too large. :-(
            return ($hashVal === $hash);
        }

        /**
         * Check validity of ReCaptcha response value
         *
         * @param string The response provided by Google
         *
         * @return boolean
         */
        private function isValidReCaptcha($reCaptchaResponse) {
            global $config, $logger;

            $logger -> log('isValidReCaptcha -> start( reCaptchaResponse = ' . $reCaptchaResponse . ' )', Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

            if (Empty($reCaptchaResponse))
                return False;

            $secret = $config['reCaptcha']['secretKey'];
            $curl   = curl_init(); // Create curl resource

            curl_setopt_array($curl, Array(CURLOPT_RETURNTRANSFER => 1, // Return the server's response data as a string rather then a boolean
                                           CURLOPT_URL            => 'https://www.google.com/recaptcha/api/siteverify?secret=' . $secret .
                                                                     '&response=' . $reCaptchaResponse .
                                                                     '&remoteip=' . $_SERVER['REMOTE_ADDR'],
                                           CURLOPT_USERAGENT      => 'Maps_Platform/v' . APP_VERSION));
            $response = json_decode(curl_exec($curl), True);
            curl_close($curl); // Close curl resource to free up system resources
            $logger -> log('isValidReCaptcha -> response = ' . print_r($response, True), Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

            return $response['success'];
        }

        /**
         * Check validity of ReCaptcha response value
         *
         * @param array POST values as an array
         *
         * @return array The status repsresented as an array
         */
        public function register(array $aDataArray) {
            $database       = $this->container->dataBase->PDO;
            $algorithm      = 'sha512';
            $iterationCount = 1024;
            $keyLength      = 1024;
            $outputRaw      = False;
            $defaultGroup   = 1;

            try {
                $username          = filter_var($aDataArray, 'username', FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW || 
                                                                                                 FILTER_FLAG_STRIP_HIGH ||
                                                                                                 FILTER_FLAG_STRIP_BACKTICK);
                $emailAddress      = filter_var($aDataArray, 'emailAddress', FILTER_SANITIZE_EMAIL);
                $password          = filter_var($aDataArray, 'password', FILTER_UNSAFE_RAW);             // Don't clean this, passwords should be left untouched as they are hashed
                $confirmPassword   = filter_var($aDataArray, 'confirmPassword', FILTER_UNSAFE_RAW);      // Don't clean this, passwords should be left untouched as they are hashed
                $reCaptchaResponse = filter_var($aDataArray, 'g-recaptcha-response', FILTER_UNSAFE_RAW); // Don't clean this, it's provided by Google

                $this->container->logger->debug('Register -> start( username = ' . $username .
                                                ', emailAddress = '. $emailAddress .
                                                ', reCaptchaResponse = '. $reCaptchaResponse . ' )');

                $query = $database->select()
                                  ->count('*', 'user_count')
                                  ->from('Users')
                                  ->where('user_name', '=', $username, 'OR')
                                  ->where('user_email_address', '=', $emailAddress);
                $stmt = $query->execute();

                $userCount               = $stmt->fetch();
                $LevenshteinForpasswords = Levenshtein($password, $confirmPassword);
                $googleResponse          = $this->isValidReCaptcha($reCaptchaResponse);

                $this->container->logger->debug('Register -> userCount = ' . print_r($userCount, True) .
                                                ', LevenshteinForpasswords = ' . $LevenshteinForpasswords .
                                                ', googleResponse = ' . $googleResponse);

                if ($userCount['user_count'] != 0) {
                    $this->container->logger->debug('Register -> User already exists');

                    return [
                        'status' => 'Error',
                        'message' => 'User already exists'
                    ];
                };

                if (($LevenshteinForpasswords !== 0) || !$googleResponse) {
                    $this->container->logger->debug('Register -> Passwords don\'t match');

                    return [
                        'status' => 'Error',
                        'message' => 'Bad Request'
                    ];
                };

                $bytes   = openssl_random_pseudo_bytes(128, $crypto_strong);
                $salt    = bin2hex($bytes);
                $hashVal = $this->pbkdf2($algorithm, $password, $salt, $iterationCount, $keyLength, $outputRaw);

                $query = $database->insert(['user_name', 'user_password', 'user_salt', 'user_email_address', 'group_fk'])
                                  ->into('Users')
                                  ->values([$username, $hashVal, $salt, $emailAddress, $defaultGroup]);
                $insertId = $dbHandler->getLastInsertId();

                $this->container->logger->debug('Register -> googleResponse = ' . $googleResponse);

                if (Empty($insertId)) {
                    $this->container->logger->debug('Register -> Unable to create user');
                    
                    return [
                        'status' => 'Error',
                        'message' => 'Unable to create user, please try again later'
                    ];
                };

                $this->container->logger->debug('Register -> Registration successful');
                $this->login($aDataArray);
                
                return [
                    'status' => 'Success',
                    'message' => 'Registration successful!'
                ];
            } catch (Exception $ex) {
                return [
                    'status' => 'Error',
                    'message' => 'Unable to create user, please try again later'
                ];
            }
        }

        /**
         * Check validity of ReCaptcha response value
         *
         * @param array POST values as an array
         *
         * @return array The status repsresented as an array
         */
        public function login(array $aDataArray) {
            $database         = $this->container->dataBase->PDO;
            $_SESSION['user'] = (object)[];
            $username         = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW || 
                                                                                             FILTER_FLAG_STRIP_HIGH ||
                                                                                             FILTER_FLAG_STRIP_BACKTICK);
            $password         = filter_input(INPUT_POST, 'password', FILTER_DEFAULT);
            $ipAddress        = $this->container->miscUtils->getClientIp();
            $logger -> log('Login -> start( username = ' . $username . ', ipAddress = ' . $ipAddress . ' )', Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

            $query1 = 'SET @username = :username;';
            $dbHandler -> PrepareAndBind($query1, Array('username' => $username));
            $dbHandler -> Execute();

            $query2 = 'SELECT ' . PHP_EOL .
                      '    `user_pk`, ' . PHP_EOL .
                      '    `user_name`, ' . PHP_EOL .
                      '    `user_password`, ' . PHP_EOL .
                      '    `user_salt`, ' . PHP_EOL .
                      '    `user_email_address`, ' . PHP_EOL .
                      '    `group_fk` ' . PHP_EOL .
                      'FROM ' . PHP_EOL .
                      '    `Users` ' . PHP_EOL .
                      'WHERE ' . PHP_EOL .
                      '    `user_name` = @username OR ' . PHP_EOL .
                      '    `user_email_address` = @username;';
            $dbHandler -> PrepareAndBind ($query2);
            $user = $dbHandler -> ExecuteAndFetch();
            $logger -> log('Login -> user : ' . print_r($user, True), Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

            if (!isset($user['user_pk'])) {
                $this -> utils -> http_response_code(401);
                $logger -> log('Login -> Invalid credentials', Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                $content['status']  = 'Error';
                $content['message'] = 'Invalid credentials, try again.';
                return $content;
            };

            $passwordCheck = $this -> isValidPassword($password, $user['user_salt'], $user['user_password']);

            if (!$passwordCheck) {
                $this -> utils -> http_response_code(401);
                $logger -> log('Login -> Invalid credentials', Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                $content['status']  = 'Error';
                $content['message'] = 'Invalid credentials, try again.';
                return $content;
            };

            $bytes = openssl_random_pseudo_bytes(32, $crypto_strong);
            $token = bin2hex($bytes);

            $insertQuery = 'INSERT INTO `RememberMe` ' . PHP_EOL .
                           '    (`user_fk`, `token`, `ip_address`) ' . PHP_EOL .
                           'VALUES ' . PHP_EOL .
                           '    (:userid, :token, :ipaddr);';
            $dbHandler -> PrepareAndBind($insertQuery, Array('userid' => $user['user_pk'],
                                                             'token'  => $token,
                                                             'ipaddr' => $ipAddress));
            $dbHandler -> Execute();
            $insertId = $dbHandler -> GetLastInsertId();
            $logger -> log('Login -> insertId : ' . $insertId, Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');
            $dbHandler -> Clean();

            if (Empty($insertId)) {
                $this -> utils -> http_response_code(500);
                $logger -> log('Login -> Unable to create rememberme token', Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                $content['status']  = 'Error';
                $content['message'] = 'Unable to create rememberme token, please try again later';
                return $content;
            };

            setcookie('userId', $user['user_pk'], time() + $config['security']['cookieLifetime'], '/');
            setcookie('token', $token, time() + $config['security']['cookieLifetime'], '/');

            $this -> utils -> http_response_code(200);
            $logger -> log('Login -> Login successful', Logger::DEBUG);
            $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');
            
            $content['status']  = 'Success';
            $content['message'] = 'Login successful, redirecting you to the homepage';
            return $content;
        }

        /**
         * Check existance and validity of a user's remember-me cookie
         */
        public function checkRememberMe() {
            $database         = $this->container->dataBase->PDO;
            $_SESSION['user'] = (object)[];

            if (isset($_COOKIE['userId']) && isset($_COOKIE['token'])) {
                $userId = filter_input(INPUT_COOKIE, 'userId', FILTER_SANITIZE_NUMBER_INT);
                $token  = filter_input(INPUT_COOKIE, 'token', FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW || 
                                                                                      FILTER_FLAG_STRIP_HIGH ||
                                                                                      FILTER_FLAG_STRIP_BACKTICK);
                $ipAddress = $this->container->miscUtils->getClientIp();
                $logger -> log('checkRememberMe -> start( userId = ' . $userId .
                               ', token = ' . $token .
                               ', ipAddress = ' . $ipAddress . ' )', Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                $query1 = 'SELECT ' . PHP_EOL .
                          '    `user_pk`, ' . PHP_EOL .
                          '    `user_name`, ' . PHP_EOL .
                          '    `user_email_address`, ' . PHP_EOL .
                          '    `group_fk` ' . PHP_EOL .
                          'FROM ' . PHP_EOL .
                          '    `Users` ' . PHP_EOL .
                          'WHERE ' . PHP_EOL .
                          '    `user_pk` = :userid;';
                $dbHandler -> PrepareAndBind ($query1, array('userid' => $userId));
                $user = $dbHandler -> ExecuteAndFetch();
                $dbHandler -> Clean();
                $logger -> log('checkRememberMe -> user = ' . print_r($user, True), Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                $query2 = 'SELECT ' . PHP_EOL .
                         '    `rememberme_pk` ' . PHP_EOL .
                         'FROM ' . PHP_EOL .
                         '    `RememberMe` ' . PHP_EOL .
                         'WHERE ' . PHP_EOL .
                         '    `user_fk` = :userid AND ' . PHP_EOL .
                         '    `token` = :token AND ' . PHP_EOL .
                         '    `ip_address` = :ipaddress;';
                $dbHandler -> PrepareAndBind ($query2, Array('userid'    => $userId,
                                                             'token'     => $token,
                                                             'ipaddress' => $ipAddress));
                $rememberMe = $dbHandler -> ExecuteAndFetch();
                $dbHandler -> Clean();
                $logger -> log('checkRememberMe -> rememberMe = ' . print_r($rememberMe, True), Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                if (!isset($user['user_pk']) || !isset($rememberMe['rememberme_pk'])) {
                    setcookie('userId', '', time() - 3600, '/');
                    setcookie('token', '', time() - 3600, '/');
                    $_SESSION['user'] -> group = 0;
                    $logger -> log('checkRememberMe -> User not found', Logger::DEBUG);
                    return;
                };

                $bytes    = openssl_random_pseudo_bytes(32, $crypto_strong);
                $newToken = bin2hex($bytes);

                $query3 = 'UPDATE ' . PHP_EOL .
                          '    `RememberMe` ' . PHP_EOL .
                          'SET ' . PHP_EOL .
                          '    `token` = :token ' . PHP_EOL .
                          'WHERE ' . PHP_EOL .
                          '    `rememberme_pk` = :remembermeid;';
                $dbHandler -> PrepareAndBind ($query3, Array('token' => $newToken,
                                                             'remembermeid' => $rememberMe['rememberme_pk']));
                $dbHandler -> Execute();
                $dbHandler -> Clean();

                if (!property_exists($_SESSION['user'], 'id') || $_SESSION['user'] -> id !== $user['user_pk'])
                    $_SESSION['user'] -> id = $user['user_pk'];

                if (!property_exists($_SESSION['user'], 'username') || $_SESSION['user'] -> username !== $user['user_name'])
                    $_SESSION['user'] -> username = $user['user_name'];

                if (!property_exists($_SESSION['user'], 'emailAddress') || $_SESSION['user'] -> emailAddress !== $user['user_email_address'])
                    $_SESSION['user'] -> emailAddress = $user['user_email_address'];

                if (!property_exists($_SESSION['user'], 'group') || $_SESSION['user'] -> group !== $user['group_fk'])
                    $_SESSION['user'] -> group = $user['group_fk'];

                $_SESSION['user'] -> token        = $newToken;
                $logger -> log('checkRememberMe -> _SESSION[user] = ' . print_r($_SESSION['user'], True), Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');

                setcookie('userId', $user['user_pk'], time() + $config['security']['cookieLifetime'], '/');
                setcookie('token', $newToken, time() + $config['security']['cookieLifetime'], '/');
            } else {
                $_SESSION['user'] -> group = -1;
                $logger -> log('checkRememberMe -> No rememberme cookie set', Logger::DEBUG);
                $this->container->logger->debug('isValidPassword -> start( salt = ' . $salt . ' )');
                return;
            };
        }

        /**
         * Deauthorize a user
         *
         * @return array The status repsresented as an array
         */
        public function logout() {
            $database = $this->container->dataBase->PDO;
			
			try {
				$userId    = $_SESSION['user'] -> id;
				$token     = $_SESSION['user'] -> token;
				$ipAddress = $this->container->miscUtils->getClientIp();
				
				$this->container->logger->debug("MapPlatform 'MapPlatform\Core\Security\logout' data: " . print_r($data, True));
				$stmt = $database->delete()
                                 ->from('RememberMe')
                                 ->where('user_fk', '=', $userId, 'AND')
                                 ->where('token', '=', $token, 'AND')
                                 ->where('ip_address', '=', $ipAddress);
                $database->beginTransaction();
                $affectedRows = $stmt2->execute();

                if ($affectedRows === 1) {
                    $database->commit();

                    // Unset the 'remember me' cookies
                    setcookie('userId', '', time() - 3600, '/');
                    setcookie('token', '', time() - 3600, '/');
                    session_unset();   // Remove all session variables
                    session_destroy(); // Destroy the session

					return [
						'status' => 'Success',
						'message' => 'Successfully logged out, redirecting you to the homepage'
					];
                } else {
                    $database->rollBack();

                    // Unset the 'remember me' cookies
                    setcookie('userId', '', time() - 3600, '/');
                    setcookie('token', '', time() - 3600, '/');
                    session_unset();   // Remove all session variables
                    session_destroy(); // Destroy the session

					return [
						'status' => 'Fail',
						'message' => 'Unable to logout'
					];
                };
            } catch (Exception $ex) {
				return [
					'status' => 'Fail',
					'message' => 'Exception while trying to logout',
                    'trace' => print_r($ex, True)
				];
            };
        }
    }

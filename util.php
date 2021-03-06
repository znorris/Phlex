<?PHP
	require_once dirname(__FILE__) . '/vendor/autoload.php';
	
	// Checks whether an API Token exists for the current user, generates and saves one if none exists.
	// Returns generated or existing API Token.
	function checkSetApiToken($userName) {
		// Check that we have generated an API token for our user, create and save one if none exists
		$config = new Config_Lite('config.ini.php');
		foreach ($config as $section => $user) {
				if (($userName = $user['plexUserName']) && ($section != "general")) {
					$apiToken = ($user['apiToken'] ? $user['apiToken'] : false);
				}
		}
		
		if (! $apiToken) {
			write_log("NO API TOKEN FOUND, generating one for ".$_SESSION['username']);
			$apiToken = randomToken(21);
			$cleaned = str_repeat("X", strlen($apiToken)); 
			write_log("API token created ".$cleaned);
			$config->set('user-_-'.$_SESSION['username'],'apiToken',$apiToken);
			saveConfig($config);
		} else {
			write_log("Found an existing token, returning it.");
		}
		return $apiToken;
	}
	
	// Generate a random token using the first available PHP function
    function randomToken($length = 32){
		write_log("Function fired.");
	    if(!isset($length) || intval($length) <= 8 ){
	      $length = 32;
	    }
	    if (function_exists('mcrypt_create_iv')) {
			write_log("Generating using mcrypt_create.");
	        return bin2hex(mcrypt_create_iv($length, MCRYPT_DEV_URANDOM));
	    } 
		if (function_exists('openssl_random_pseudo_bytes')) {
			write_log("Generating using pseudo_random.");
	        return bin2hex(openssl_random_pseudo_bytes($length));
	    }
		// Keep this last, as there appear to be issues with random_bytes and Docker.
		if (function_exists('random_bytes')) {
			write_log("Generating using random_bytes.");
	        return bin2hex(random_bytes($length));
	    }
	}
	
	// Generate a timestamp and return it
	function timeStamp() {
		$php_timestamp = time();
		$stamp = date(" h:i:s A - m/d/Y", $php_timestamp);
		return $stamp;
	}
	
	// Recursively filter empty keys from an array
	// Returns filtered array.
	function array_filter_recursive( array $array, callable $callback = null ) {
		$array = is_callable( $callback ) ? array_filter( $array, $callback ) : array_filter( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				$value = call_user_func( __FUNCTION__, $value, $callback );
			}
		}
 
		return $array;
	}
	
	//Get the current protocol of the server
	function serverProtocol() {
	   return (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')	|| $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://');
	}
	
	//Get the relative path to $to in relation to where $from is
	function getRelativePath($from, $to)
	{
		// some compatibility fixes for Windows paths
		$from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
		$to   = is_dir($to)   ? rtrim($to, '\/') . '/'   : $to;
		$from = str_replace('\\', '/', $from);
		$to   = str_replace('\\', '/', $to);

		$from     = explode('/', $from);
		$to       = explode('/', $to);
		$relPath  = $to;

		foreach($from as $depth => $dir) {
			// find first non-matching dir
			if($dir === $to[$depth]) {
				// ignore this directory
				array_shift($relPath);
			} else {
				// get number of remaining dirs to $from
				$remaining = count($from) - $depth;
				if($remaining > 1) {
					// add traversals up to first matching dir
					$padLength = (count($relPath) + $remaining - 1) * -1;
					$relPath = array_pad($relPath, $padLength, '..');
					break;
				} else {
					$relPath[0] = './' . $relPath[0];
				}
			}
		}
		return implode('/', $relPath);
	}
	
	// Grab an image from a server and save it locally
	// TODO: Some way to remove images older than N days
	function cacheImage($url) {
		try {
			$cacheDir = dirname(__FILE__) . '/img/cache/';
			$cached_filename = md5($url);
			$files = glob($cacheDir . '*.{jpg,jpeg,png,gif}', GLOB_BRACE);
			$now = time();
			foreach ($files as $file) {
				if (is_file($file)) {
					if ($now - filemtime($file) >= 60 * 60 * 24 * 5) { // 5 days
						unlink($file);
					}
				}
			}
			foreach($files as $file) {
				$fileName = explode('.',basename($file));
				if ($fileName[0] == $cached_filename) {
					  return getRelativePath(dirname(__FILE__),$file);
				}
			}
			$image = file_get_contents($url);
			if ($image) {
				$tempName = $cacheDir . $cached_filename;
				file_put_contents($tempName,$image);
				$imageData = getimagesize($tempName);
				$mimeType = image_type_to_mime_type($imageData[2]);
				$extension = image_type_to_extension($imageData[2]);
				if($extension) {
					$filenameOut = $cacheDir . $cached_filename . $extension;
					$result = file_put_contents($filenameOut, $image);
					if ($result) {
						rename($tempName,$filenameOut);
						return getRelativePath(dirname(__FILE__),$filenameOut);
					}
				} else {
					unset($tempName);
				}
			}
		} catch (\Exception $e) {
			write_log('Exception: ' . $e->getMessage());
		}
		return $url;
	}
	
	// Check if string is present in an array
	function arrayContains($str, array $arr)	{
		//write_log("Function Fired.");
		$result = array_intersect($arr,explode(" ",$str));
		if (count($result)==1) $result = true;
		if (count($result)==0) $result = false;
		return $result;
	}
 
	// Fetch data from a URL using CURL
	function curlGet($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
		$result = curl_exec($ch);
		curl_close ($ch);
		//write_log("URL is ".$url.". Result is ".$result);
		return $result;
	}
	
	
	function curlPost($url,$content,$JSON=false) {
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
		if ($JSON) curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
		$response = curl_exec($curl);
		curl_close($curl);
		return $response;
	}
	
	// Write log information to $filename
	// Auto rotates files larger than 2MB
	function write_log($text,$level=null,$nocall=null) {
		if ($level === null) {
			$level = 'I';	
		}
		if(! $nocall) $caller = (string) getCaller();
		$filename = 'Phlex.log';
		$text = $level .'/'. date(DATE_RFC2822) . ': '.$caller . ": " . $text . PHP_EOL;
		if (!file_exists($filename)) { touch($filename); chmod($filename, 0666); }
		if (filesize($filename) > 2*1024*1024) {
			$filename2 = "$filename.old";
			if (file_exists($filename2)) unlink($filename2);
			rename($filename, $filename2);
			touch($filename); chmod($filename,0666);
		}
		if (!is_writable($filename)) die;
		if (!$handle = fopen($filename, 'a+')) die;
		if (fwrite($handle, $text) === FALSE) die;
		fclose($handle);
	}
	
	// Get the name of the function calling write_log
	function getCaller() {
		$trace = debug_backtrace();
		$count = count($trace);
		$useNext = false;
		$caller = false;
		//write_log("TRACE: ".print_r($trace,true),null,true);
		foreach($trace as $event) {
			if ($useNext) {
				if (($event['function'] != 'require') && ($event['function'] != 'include')) {
					$caller .= "::".$event['function'];
					break;
				}
			}
			if ($event['function'] == 'write_log') {
				$useNext = true;
				// Set our caller as the calling file until we get a function
				$file = pathinfo($event['file']);
				$caller = $file['filename'].".".$file['extension'];
			}
		}
		return $caller;   
	}
	
	// Save the specified configuration file using CONFIG_LITE
	function saveConfig($inConfig) {
		try {
			$inConfig->save();
		} catch (Config_Lite_Exception $e) {
			echo "\n" . 'Exception Message: ' . $e->getMessage();
			write_log('Error saving configuration.','E');
		}
		$configFile = dirname(__FILE__) . '/config.ini.php';
		$cache_new = "'; <?php die('Access denied'); ?>"; // Adds this to the top of the config so that PHP kills the execution if someone tries to request the config-file remotely.
		$cache_new .= file_get_contents($configFile);
		file_put_contents($configFile,$cache_new);
		
	}
	
	function protectURL($string) {
		$keys = parse_url($string);
		$cleaned = str_repeat("X", strlen($keys['host'])); 
		$string = str_replace($keys['host'],$cleaned,$string);
		$pairs = array();
		if($keys['query']) {
			$params = explode('&',$keys['query']);
			foreach ($params as $key) {
				$set = explode('=',$key);
				if (count($set) == 2) {
					$pairs[$set[0]] = $set[1];
				}
			}
		}
		if (! empty($pairs)) {
			foreach ($pairs as $key=>$value) {
				if ((preg_match("/token/",$key)) || (preg_match("/Token/",$key)) || (preg_match("/address/",$key))) {
					$cleaned = str_repeat("X", strlen($value)); 
					$string = str_replace($value,$cleaned,$string);
				}
			}
		}
		return $string;
	}
	
	// A more precise way of calculating the similarity between two strings
	function similarity($str1, $str2) {
		$len1 = strlen($str1);
		$len2 = strlen($str2);
		
		$max = max($len1, $len2);
		$similarity = $i = $j = 0;
		
		while (($i < $len1) && isset($str2[$j])) {
			if ($str1[$i] == $str2[$j]) {
				$similarity++;
				$i++;
				$j++;
			} elseif ($len1 < $len2) {
				$len1++;
				$j++;
			} elseif ($len1 > $len2) {
				$i++;
				$len1--;
			} else {
				$i++;
				$j++;
			}
		}

		return round($similarity / $max, 2);
	}

?>
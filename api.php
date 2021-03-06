<?php
	require_once dirname(__FILE__) . '/vendor/autoload.php';
	require_once dirname(__FILE__) . '/cast/Chromecast.php';
	require_once dirname(__FILE__) . '/util.php';
	use Kryptonit3\SickRage\SickRage;
	date_default_timezone_set("America/Chicago");
	ini_set("log_errors", 1);
	error_reporting(E_ERROR);
	$errfilename = 'Phlex_error.log';
	ini_set("error_log", $errfilename);
	if ( is_session_started() === FALSE ) {
		if (isset($_GET['apiToken'])) {
			session_id($_GET['apiToken']);
		}

		if (isset($_SERVER['HTTP_APITOKEN'])) {
			session_id($_SERVER['HTTP_APITOKEN']);
		}
		session_start();
	}
	// Define our config globally
	$_SESSION['config'] = new Config_Lite('config.ini.php');

	// Fetch our Plex device ID, create one if it does not exist
	$_SESSION['deviceID'] = $_SESSION['config']->get('general','deviceID','');
	if (($_SESSION['deviceID'])=="") {
		$_SESSION['deviceID'] = randomToken(12);
		$_SESSION['config']->set('general','deviceID',$_SESSION['deviceID']);
		saveConfig($_SESSION['config']);
	}
	if (!(file_exists('commands.php'))) $fh = fopen('commands.php', 'w') or die("Can't write commands.php file.  Check permissions.");

	// Check that we have some form of set credentials
	if ((isset($_SESSION['plex_token'])) || (isset($_GET['apiToken'])) || (isset($_SERVER['HTTP_APITOKEN']))) {

		// If this request is using an API Token
		if ((isset($_GET['apiToken'])) || (isset($_SERVER['HTTP_APITOKEN']))) {
			$valid = false;
			if (isset($_GET['apiToken'])) {
				$token = $_GET['apiToken'];
			}
			if (isset($_SERVER['HTTP_APITOKEN'])) {
				$token = $_SERVER['HTTP_APITOKEN'];
			}
			if (!(isset($_GET['pollPlayer']))) {
				write_log("AUTHENTICATION: Using Valid API Token for authentication.");
			}
			foreach ($_SESSION['config'] as $section => $setting) {
				if ($section != "general") {
					$testToken = $setting['apiToken'];
					if ($testToken == $token) {
						if (!(isset($_GET['pollPlayer']))) {
							write_log("API Token is a match, on to the wizard!");
						}
						$_SESSION['username'] = $setting['plexUserName'];
						$_SESSION['plex_cred'] = $setting['plexCred'];
						$_SESSION['plex_token'] = $setting['plexToken'];
						$valid = true;
					} else {
						if (isset($_GET['testclient'])) {
							write_log("API Link Test failed, token does not match!");
							echo 'ERROR: unrecognized API token!';
							die();
						}
					}
				}
			}

			if ($valid) {
			// Handler for API.ai calls.
				if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_SERVER['HTTP_APITOKEN']))) {
					write_log("This is an API.ai Request!!!!");
					setSessionVariables();
					// The request is using the POST method
					//write_log("Okay, now it's a POST");
					$json =file_get_contents('php://input');
					$request = json_decode($json, true);
					$request = array_filter_recursive($request);
					parseApiCommand($request);
				}
			} else {
				if (isset($_GET['testclient'])) {
					write_log("API Link Test FAILED!  Invalid API Token.");
					echo 'Invalid API Token Specified! <br>';
					die();
				}
				write_log("API Token specified, but value is invalid.  Execution terminated.");
				die();
			}
		}

		// If this is coming from the web portal
		if (isset($_SESSION['plex_token'])) {
			$valid = false;
			foreach ($_SESSION['config'] as $section => $name) {
				$sections=explode("-_-",$section);
				if ($sections[0]=='user') {
					if ($_SESSION['config']->get($section,'plexToken','')==$_SESSION['plex_token']) {
						$_SESSION['username'] = $name['plexUserName'];
						$_SESSION['plex_cred'] = $name['plexCred'];
						$valid = true;
					}
				}
			}
			if ($valid) {
				if (!(isset($_GET['pollPlayer']))) {
					write_log('_______________________________________________________________________________');
					write_log('-------------------------------- SESSION START --------------------------------');
					write_log("Starting session ID ".session_id());
					write_log((isset($_SESSION['plex_token'])?"Session token is set to ".$_SESSION['plex_token']:"Session token not found."));
					write_log((isset($_SESSION['username'])?"Session username is set to ".$_SESSION['username']:"Session token not found."));
					write_log("Valid plex token used for authentication.");
				}
			} else {
				write_log("Error, plex token for credentials does not match saved value.");
			}
		}

		// Check that our token is still valid, re-authenticate if not.
		// This is slightly time-consuming, but necessary.

			if ($_SESSION['plex_cred']) {
				$token = signIn($_SESSION['plex_cred']);
				if ($token) {
					$_SESSION['plex_token'] = $token;
				} else {
					write_log("ERROR: Could not sign in to Plex.");
					die();
				}
				if ((!(isset($_SESSION['uri_plexclient']))) || (!(isset($_SESSION['uri_plexserver'])))) {
					setSessionVariables();
				}
			} else {
				write_log("ERROR: Could not retrieve user credentials.");
				die();
			}

	} else {
		write_log("ERROR: Unauthenticated access detected.  Originating IP - ".$_SERVER['REMOTE_ADDR']);
		$entityBody = curlGet('php://input');
		write_log("Post BODY: ".$entityBody);
		die();
	}


	// If we are authenticated and have a username and token, continue


	if (!(isset($_SESSION['counter']))) {
		$_SESSION['counter'] = 0;
		setSessionVariables();
	}


	if (isset($_GET['pollPlayer'])) {
		$result['playerStatus'] = playerStatus();
		$file = 'commands.php';
		$handle = fopen($file, "r");
		//Read first line, but do nothing with it
		$foo = fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}
		$result['commands'] =  urlencode(($contents));
		$result['players'] = fetchClientList();
		$result['servers'] = fetchServerList();
		$result['dvrs'] = fetchDVRList();
		header('Content-Type: application/json');
		echo JSON_ENCODE($result);
		die();
	}

	if ((isset($_GET['getProfiles'])) && (isset($_GET['service']))) {
		$service = $_GET['service'];
		write_log("Got a request to fetch the profiles for ".$service);
	}

	if (isset($_GET['testclient'])) {
		write_log("API Link Test successful!!");
		write_log("API Link Test successful!!");
		echo 'success';
		die();
	}

	if (isset($_GET['test'])) {
		$result = array();
		$result['status'] = testConnection($_GET['test']);
		header('Content-Type: application/json');
		echo JSON_ENCODE($result);
		die();
	}


	if (isset($_GET['setup'])) {
		write_log("SetupBot should be fired now");
		$result = array();
		$result['status'] = setupBot();
		write_log("Returning ".JSON_ENCODE($result));
		header('Content-Type: application/json');
		echo JSON_ENCODE($result);
		die();
	}

	if (isset($_GET['registerServer'])) {
		registerServer();
		echo "OK";
		die();
	}

	if (isset($_GET['device'])) {
		$type = $_GET['device'];
		$id = $_GET['id'];
		$uri = $_GET['uri'];
		$name = $_GET['name'];
		$product = $_GET['product'];
		write_log('GET: New device selected. Type is ' . $type.". ID is ". $id.". Name is ".$name);
		if ($type == 'plexServer') {
			$token = $_GET['token'];
			$_SESSION['config']->set('user-_-'.$_SESSION['username'],$type.'Token',$token);
		}
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],$type,$id);
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],$type.'Uri',$uri);
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],$type.'Name',$name);
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],$type.'Product',$product);
		saveConfig($_SESSION['config']);
		setSessionVariables();
		write_log("Refreshing devices of ".$type);
		refreshDevices($type,true);
		die();
	}

	// If we are changing a setting variable via the web UI.
	if (isset($_GET['id'])) {
		$id = $_GET['id'];
		if (trim($id) == 'useCast') {
			refreshDevices('plexClient',true);
		}
		$value = $_GET['value'];
		write_log('GET: Setting parameter changed '.$id . ' : ' . $value);
		if (preg_match("/IP/",$id)) {
			$url = parse_url($value);
			write_log("Got a URL: ".$value);
			if (isset($url['scheme'])) {
				write_log("This has a protocol");
			} else {
				write_log("No protocol specified, assuming http://");
				$value = 'http://'.$url['host'];
			}
			write_log("Full thing: ".print_r($url,true));
		}
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],$id,$value);
		saveConfig($_SESSION['config']);
		reloadVariables();
		die();
	}

	// Fetches a list of clients
	if (isset($_GET['sendlog'])) {
		write_log("API: Sending logs");
		sendLog();
		die();
	}

	if (isset($_GET['TEST'])) {
		write_log("API: Test command received.");
		if (isset($_GET['apiToken'])) {
			$token = $_GET['apiToken'];
			foreach ($_SESSION['config'] as $section => $setting) {
				if ($section != "general") {
					$testToken = $setting['apiToken'];
					if ($testToken == $token) {
						echo 'success';
						die();
					}
				}
			}
		}
		echo 'token_not_recognized';
		die();
	}

	// Fetches a list of clients
	if (isset($_GET['clientList'])) {
		write_log("API: Returning clientList");
		$devices = fetchClientList();
		echo $devices;
		die();
	}

	if (isset($_GET['serverList'])) {
		write_log("API: Returning serverList");
		$devices = fetchServerList();
		echo $devices;
		die();
	}

	if (isset($_GET['fetchList'])) {
		$fetch = $_GET['fetchList'];
		write_log("API: Returning profile list for ".$fetch);
		$list = fetchList($fetch);
		echo $list;
		die();
	}


	// This tells the api to parse our command with the plex "play" parser
	if (isset($_GET['play'])) {
		if (isset($_GET['command'])) {
			$command = cleanCommandString($_GET['command']);
			write_log("################PARSEPLAY_START$###################################\r\n\r\n");
			write_log('Got a request to play ' . $command);
			$resultArray = parsePlayCommand($command);
			$queryOut = array();
			$queryOut['initialCommand'] = $command;
			$queryOut['parsedCommand'] = $command;
			if ($resultArray) {
				$result = $resultArray[0];
				$queryOut['mediaResult'] = $result;
				$playResult = playMedia($result);
				$searchType = $result['searchType'];
				$type = (($searchType == '') ? $result['type'] : $searchType);
				$queryOut['parsedCommand'] = 'Play the '.$type.' named '.$command.'.';
				$queryOut['playResult'] = $playResult;

				if ($queryOut['mediaResult']['@attributes']['exact'] == 1) {
					$queryOut['mediaStatus'] = "SUCCESS: Exact match found.";
				} else {
					$queryOut['mediaStatus'] = "SUCCESS: Approximate match found.";
				}
			} else {
				$queryOut['mediaStatus'] = 'ERROR: No results found';
			}
			$queryOut['timestamp'] = timeStamp();
			$queryOut['serverURI'] = $_SESSION['uri_plexserver'];
			$queryOut['serverToken'] = $_SESSION['token_plexserver'];
			$queryOut['clientURI'] = $_SESSION['uri_plexclient'];
			$queryOut['clientName'] = $_SESSION['name_plexclient'];
			$queryOut['commandType'] = 'play';
			$result = json_encode($queryOut);
			header('Content-Type: application/json');
			log_command($result);
			echo $result;
			die();
		}
	}


	// This tells the api to parse our command with the plex "control" parser
	if (isset($_GET['control'])) {
		if (isset($_GET['command'])) {
			$command = cleanCommandString($_GET['command']);
			write_log('Got a control request: ' . $command);
			$result = parseControlCommand($command);
			$newCommand = json_decode($result,true);
			$newCommand['timestamp'] = timeStamp();
			$result = json_encode($newCommand);
			header('Content-Type: application/json');
			log_command($result);
			echo $result;
			die();
		}
	}

	// This tells the api to parse our command with the "fetch" parser
	if (isset($_GET['fetch'])) {
		if (isset($_GET['command'])) {
			$command = cleanCommandString($_GET['command']);
			$result = parseFetchCommand($command);
			$result['commandType'] = 'fetch';
			$result['timestamp'] = timeStamp();
			log_command(json_encode($result));
			header('Content-Type: application/json');
			echo json_encode($result);
			die();
		}

	}







	/*

	This is the mack-daddy function for how everything here works.  The premise is - you ABSOLUTELY HAVE TO HAVE a plex token set in the session before this is called.  Using the token and username,
	we will fetch all the server and client data, and then correlate it to what is saved in preferences to determine what command to issue, and to where.

	*/

	function setSessionVariables() {
		write_log("Function Fired.");

		$ip = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'publicAddress', false);
		if (!($ip)) {
			$ip = curlGet('https://plex.tv/pms/:/ip');
			$ip = serverProtocol() . $ip . '/Phlex';
			$_SESSION['config']->set('user-_-'.$_SESSION['username'],'publicAddress', $ip);
			saveConfig($_SESSION['config']);
		}

		// See if we have a server saved in settings
		$_SESSION['id_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexserver', false);
		if (!($_SESSION['id_plexserver'])) {
			// If no server, fetch a list of them and select the first one.
			write_log('No server selected, fetching first avaialable device.');
			$servers = fetchDevices('servers');
			if ($servers) {
				foreach($servers as $server) {
					if ($server['selected']) {
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServer',$server['id']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerProduct',$server['product']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerName',$server['name']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerUri',$server['uri']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerPublicAddress',$server['publicAddress']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerToken',$server['token']);
						saveConfig($_SESSION['config']);
					}
				}
			}
		}
		// Now check and set up our client, just like we did with the server

		$_SESSION['id_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexClient', false);
		write_log("Session client read as ".$_SESSION['id_plexclient']);
		if (!($_SESSION['id_plexclient'])) {
			write_log("No client selected, fetching first available device.");
			$clients = fetchDevices('clients');
			if ($clients) {
				foreach($clients as $client) {
					if ($client['selected']) {
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClient',$client['id']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientProduct',$client['product']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientName',$client['name']);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientUri',$client['uri']);
						saveConfig($_SESSION['config']);
					}
				}
			}
		}
		$_SESSION['id_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexDVR', false);
		if (!($_SESSION['id_plexdvr'])) {
			write_log("No DVR found, checking for them.");
			$dvrs = fetchDVRList();
			write_log("Hey, I got somethin... ".json_encode($dvrs));
			if (count($dvrs) >= 1) {
				$dvr = $dvrs[0];
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVR',$dvr['id']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRProduct',$dvr['product']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRName',$dvr['name']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRUri',$dvr['uri']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRPublicAddress',$dvr['publicAddress']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRToken',$dvr['token']);
				saveConfig($_SESSION['config']);
			}

		}

		reloadVariables();
	}

	function reloadVariables() {
		$_SESSION['enable_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchEnabled', false);
		$_SESSION['enable_ombi'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'ombiEnabled', false);
		$_SESSION['enable_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrEnabled', false);
		$_SESSION['enable_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickEnabled', false);
		$_SESSION['enable_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrEnabled', false);
		$_SESSION['enable_apiai'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'apiEnabled', false);

		$_SESSION['returnItems'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'returnItems', 0);

		$_SESSION['ip_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchIP', 'http://localhost');
		$_SESSION['ip_ombi'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'ombiUrl', 'http://localhost');
		$_SESSION['ip_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrIP', 'http://localhost');
		$_SESSION['ip_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickIP', 'http://localhost');
		$_SESSION['ip_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrIP', 'http://localhost');
		$ips = array("ip_couch"=>$_SESSION['ip_couch'],"ip_ombi"=>$_SESSION['ip_ombi'],"ip_sonarr"=>$_SESSION['ip_sonarr'],"ip_sick"=>$_SESSION['ip_sick'],"ip_radarr"=>$_SESSION['ip_radarr']);
		foreach ($ips as $key=>$value) {
			write_log("IPS: ".$key." = ".$value);
			$addy = parse_url($value);
			if(! isset($addy['scheme'])) {
				write_log("Fixing URL");
				$_SESSION[$key] = 'http://'.$value;
				write_log("Fixed value for ".$key." is ".$_SESSION[$key]);
			}
		}
		$_SESSION['port_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchPort', '5050');
		$_SESSION['port_ombi'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'ombiPort', '3579');
		$_SESSION['port_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrPort', '8989');
		$_SESSION['port_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickPort', '8083');
		$_SESSION['port_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrPort', '7878');

		$_SESSION['auth_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchAuth', '');
		$_SESSION['auth_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrAuth', '');
		$_SESSION['auth_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickAuth', '');
		$_SESSION['auth_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrAuth', '');

		$_SESSION['profile_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchProfile', false);
		$_SESSION['profile_ombi'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'ombiProfile', false);
		$_SESSION['profile_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrProfile', false);
		$_SESSION['profile_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickProfile', false);
		$_SESSION['profile_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrProfile', false);

		$_SESSION['list_couch'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'couchList', false);
		$_SESSION['list_ombi'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'ombiList', false);
		$_SESSION['list_sonarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sonarrList', false);
		$_SESSION['list_sick'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'sickList', false);
		$_SESSION['list_radarr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'radarrList', false);

		$_SESSION['log_tokens'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'logTokens', false);
		$_SESSION['plexToken'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexToken', false);
		$_SESSION['id_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexServer', false);
		$_SESSION['name_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexServerName',false);
		$_SESSION['uri_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexServerUri',false);
		$_SESSION['publicAddress_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexServerPublicAddress',false);
		$_SESSION['token_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexServerToken',false);

		$_SESSION['id_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexClient', false);
		$_SESSION['name_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexClientName',false);
		$_SESSION['uri_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexClientUri',false);
		$_SESSION['product_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexClientProduct',false);
		$_SESSION['token_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexClientToken',false);

		$_SESSION['id_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'plexDVR', false);
		$_SESSION['name_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexDVRName',false);
		$_SESSION['uri_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexDVRUri',false);
		$_SESSION['publicAddress_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexDVRPublicAddress',false);
		$_SESSION['token_plexdvr'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexDVRToken',false);

		$_SESSION['dvr_newairings'] = $_SESSION['config']->getBool('user-_-'.$_SESSION['username'], 'dvr_newairings', true);
		$_SESSION['dvr_replacelower'] = $_SESSION['config']->getBool('user-_-'.$_SESSION['username'], 'dvr_replacelower', true);
		$_SESSION['dvr_recordpartials'] = $_SESSION['config']->getBool('user-_-'.$_SESSION['username'], 'dvr_recordpartials', false);
		$_SESSION['dvr_startoffset'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'dvr_startoffset', 2);
		$_SESSION['dvr_endoffset'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'dvr_endoffset', 2);
		$_SESSION['resolution'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'resolution', 0);

		$_SESSION['fetch_plexclient'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexClientFetched',false);
		$_SESSION['fetch_plexserver'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexServerFetched',false);

		$_SESSION['use_cast'] = $_SESSION['config']->getBool('user-_-'.$_SESSION['username'], 'useCast', false);
		$_SESSION['plexHeader'] = '&X-Plex-Product=Phlex'.
		'&X-Plex-Version=1.0.0'.
		'&X-Plex-Client-Identifier='.$_SESSION['deviceID'].
		'&X-Plex-Platform=Web'.
		'&X-Plex-Platform-Version=1.0.0'.
		'&X-Plex-Device=PhlexWeb'.
		'&X-Plex-Device-Name=Phlex'.
		'&X-Plex-Device-Screen-Resolution=1520x707,1680x1050,1920x1080'.
		'&X-Plex-Token='.$_SESSION['plex_token'];

		// Q&D Variable with the plex target client header
		$_SESSION['plexClientHeader']='&X-Plex-Target-Client-Identifier='.$_SESSION['id_plexclient'];

		// Log our current session variables
		write_log("-------------Session Variables----------");
		write_log("DeviceID: ".$_SESSION['deviceID']);
		write_log("Username: ".$_SESSION['username']);
		write_log("Token: ".$_SESSION['plex_token']);
		write_log("----------------------------------------");
		write_log("Server Name: ".$_SESSION['name_plexserver']);
		write_log("Server ID: ".$_SESSION['id_plexserver']);
		write_log("Server URI: ".$_SESSION['uri_plexserver']);
		write_log("Server Public Address: ".$_SESSION['publicAddress_plexserver']);
		write_log("Server Token: ".$_SESSION['token_plexserver']);
		write_log("----------------------------------------");
		write_log("Client Name: ".$_SESSION['name_plexclient']);
		write_log("Client ID: ".$_SESSION['id_plexclient']);
		write_log("Client URI: ".$_SESSION['uri_plexclient']);
		write_log("Client Product: ".$_SESSION['product_plexclient']);
		write_log("----------------------------------------");
		write_log("Plex DVR Enabled: ".($_SESSION['uri_plexdvr'] ? "true" : "false"));
		write_log("----------------------------------------");
		write_log("CouchPotato Enabled: ".$_SESSION['enable_couch']);
		write_log("Ombi Enabled: ".$_SESSION['enable_ombi']);
		write_log("Radarr Enabled: ".$_SESSION['enable_radarr']);
		write_log("Sonarr Enabled: ".$_SESSION['enable_sonarr']);
		write_log("Sick Enabled: ".$_SESSION['enable_sick']);
		write_log("----------------------------------------");
	}


	/* This is our handler for fetch commands

	You can either say just the name of the show or series you want to fetch,
	or explicitely state "the movie" or "the show" or "the series" to specify which one.

	If no media type is specified, a search will first be executed for a movie, and then a
	show, with the first found result being added.

	If a searcher is not enabled in settings, nothing will happen and an appropriate status
	message should be returned as the 'status' value of our object.

	*/


	function parseFetchCommand($command) {

		write_log("Function Fired.");
		//Sanitize our string and try to rule out synonyms for commands
		$result[initialCommand] = $command;
		$commandArray = explode(' ',$command);
		$type = false;
		if (arrayContains('movie',$commandArray)) {
			$commandArray = array_diff($commandArray,array('movie'));
			$type = 'movie';
			$resultOut['parsedCommand'] = 'Fetch the movie named '.implode(" ",$commandArray);
		}
		if (arrayContains('show',$commandArray) || arrayContains('series',$commandArray)) {
			$commandArray = array_diff($commandArray,array('show','series'));
			$type = 'show';
		}
		$useNext = false;
		if (arrayContains('season',$commandArray)) {
			write_log("Found the word season.");
			foreach($commandArray as $word) {
				if ($useNext) {
					$season = intVal($word);
					break;
				}
				if ($word == 'season') {
					$useNext = true;
				}

			}
			if ($season) {
				$type = 'show';
				$commandArray = array_diff($commandArray,array('season',$season));
			}
		}
		$useNext = false;
		if (arrayContains('episode',$commandArray)) {
			write_log("Found the word episode.");
			foreach($commandArray as $word) {
				if ($useNext) {
					$episode = intVal($word);
					break;
				}
				if ($word == 'episode') {
					$useNext = true;
				}
				if (($word == 'latest') || ($word == 'new') || ($word == 'newest')) {
					$remove = $word;
					$episode = -1;
					break;
				}
			}
			if ($episode) {
				$type = 'show';
				$commandArray = array_diff($commandArray,array('episode',$episode));
				if ($episode == -1) $commandArray = array_diff($commandArray,array('episode',$remove));
			}
		}
		if ($type == false) $resultOut['parsedCommand'] = 'Fetch the first movie or show named '.implode(" ",$commandArray);

		switch ($type) {
			case 'show':
			write_log("Searching explicitely for a show.");
				if ($_SESSION['enable_sonarr'] || $_SESSION['enable_sick']) {
					$result = downloadSeries(implode(" ",$commandArray),$season,$episode);
					$resultTitle = $result['mediaResult']['@attributes']['title'];
					$resultOut['parsedCommand'] = 'Fetch '.($season ? 'Season '.$season.' of ' : '').($episode ? 'Episode '.$episode.' of ' : '').'the show named '.$resultTitle;
					write_log("Result ".json_encode($result));

				} else {
					$result['status'] = 'no fetcher configured for ' .$type;
					write_log("". $result['status']);
				}
				break;
			case 'movie':
				write_log("Searching explicitely for a movie.");
				if (($_SESSION['enable_couch']) || ($_SESSION['enable_ombi']) || ($_SESSION['enable_radarr'])) {
					$result = downloadMovie(implode(" ",$commandArray));
				} else {
					$result['status'] = 'no fetcher configured for ' .$type;
					write_log("". $result['status']);
				}
				break;
			default:
				if (($_SESSION['enable_couch']) || ($_SESSION['enable_ombi']) || ($_SESSION['enable_radarr'])) {
					write_log("Searching for first media matching title.");
					$result = downloadMovie(implode(" ",$commandArray));
					if ($result['status'] == 'successfully added') break;
					if (($result['status'] == 'no results') && (($_SESSION['enable_sonarr']) || (($_SESSION['enable_ombi'])))) {
						write_log("No results for transient search as movie, searching for show.");
						$result = downloadSeries(implode(" ",$commandArray));
						break;
					}
				} else {
					$result['status'] = 'no fetcher configured for ' .$type;
					write_log("". $result['status']);
				}
				break;
		}
		$result['mediaStatus'] = $result['status'];
		$result['parsedCommand'] = $resultOut['parsedCommand'];
		$result['initialCommand'] = $command;
		return $result;
	}


	function parseControlCommand($command) {
		write_log("Function Fired.");
		//Sanitize our string and try to rule out synonyms for commands
		$queryOut[initialCommand] = $command;
		write_log("Initial command is ".$command);
		$command = str_replace("resume","play",$command);
		$command = str_replace("jump","skip",$command);
		$command = str_replace("go","skip",$command);
		$command = str_replace("seek","step",$command);
		$command = str_replace("ahead","forward",$command);
		$command = str_replace("backward","back",$command);
		$command = str_replace("rewind","seek back",$command);
		$command = str_replace("fast", "seek",$command);
		$command = str_replace("skip forward","skipNext",$command);
		$command = str_replace("fast forward","stepForward",$command);
		$command = str_replace("seek forward","stepForward",$command);
		$command = str_replace("seek back","stepBack",$command);
		$command = str_replace("skip back","skipPrevious",$command);
		$cmd = false;
		write_log("Fixed command is ".$command);
		$queryOut[parsedCommand] = "";
		$serverToken = $_SESSION['plex_token'];
		$client = $_SESSION['uri_plexclient'];
		$command = cleanCommandString($command);
		$commandArray = array("play","pause","stop","skipNext","stepForward","stepBack","skipPrevious","volume");
		if (strpos($command,"volume")) {
				write_log("Should be a volume command.");
				$int = filter_var($command, FILTER_SANITIZE_NUMBER_INT);
				if (! $int) {
					$adjust = false;
					if (preg_match("/UP/",$command)) {
						$adujst = true;
						$int = 10;
					}

					if (preg_match("/DOWN/",$command)) {
						$adjust = true;
						$int = -10;
					}
					if ($adjust) {
						$status = playerStatus();
						$status = json_decode($status,true);
						$volume = $status['volume'];
						if ($volume) {
							$int = $volume + $int;
						}

					}
				}
				$queryOut[parsedCommand] .= "Set the volume to " . $int . " percent.";
				$cmd = 'setParameters?volume='.$int;
		} else {
			$result = implode(" ",array_intersect($commandArray,explode(" ",$command)));
			if ($result) {
				$queryOut[parsedCommand] .= $result;
				$cmd = $result;
			}
		}
		if (preg_match("/subtitles/",$command)) {
			write_log("Fixing subtitle Command.");
			if (preg_match("/off/",$command)) {
				$streamID = 0;
			} else {
				$status = playerStatus();
				write_log("Got Player Status: ".$status);
				$statusArray = json_decode($status,true);
				$streams = $statusArray['mediaResult']['Media']['Part']['Stream'];
				foreach ($streams as $stream) {
					$type = $stream['@attributes']['streamType'];
					if ($type == 3) {
						write_log("Got me a subtitle.");
						$code = $stream['@attributes']['languageCode'];
						if (preg_match("/eng/",$code)) {
							$streamID = $stream['@attributes']['id'];
						}
					}
				}
			}
			$cmd = 'setStreams?subtitleStreamID='.$streamID;
		}
		if ($cmd) {
			$result = sendCommand($cmd);
			$results['url'] = $result['url'];
			$results['status'] = $result['status'];
			$queryOut['playResult'] = $results;
			$queryOut['mediaStatus'] = 'SUCCESS: Not a media command';
			$queryOut['commandType'] = 'control';
			$queryOut['clientURI'] = $_SESSION[uri_plexclient];
			$queryOut['clientName'] = $_SESSION[name_plexclient];
			return json_encode($queryOut);
		}
		return false;

	}


	function parseRecordCommand($command) {
		write_log("Function fired.");
		$url = $_SESSION['uri_plexdvr'].'/tv.plex.providers.epg.onconnect:4/hubs/search?sectionId=&query='.urlencode($command).'&X-Plex-Token='.$_SESSION['token_plexdvr'];
		write_log("Url is: ".$url);
		$result = curlGet($url);
		if ($result) {
			write_log("Result is ".$result);
			$container = new SimpleXMLElement($result);
			$result = false;
			foreach($container->Hub as $hub) {
				$array = json_decode(json_encode($hub),true);
				$type = (string) $array['@attributes']['type'];
				if (($type == 'show') || ($type == 'movie')) {
					$title = $array['Directory']['@attributes']['title'];
					if (similarity(cleanCommandString($title), $command) >=.7) {
						write_log("We have a match, proceeding: ".$command);
						$result = $array;
					}
				}
			}
			if ($result) {
				unset($array);
				$array = $result;
				$url = $_SESSION['uri_plexserver'];
				$guid = $array['Directory']['@attributes']['guid'];
				$params = array(
					guid=>$guid
				);
				$url.= '/media/subscriptions/template?'.http_build_query($params).'&X-Plex-Token='.$_SESSION['token_plexserver'];
				write_log("URL is ".$url);
				$template = curlGet($url);
				if (! $template) {
					write_log("Error fetching download template, aborting.");
					return false;
				}
				$container = new SimpleXMLElement($template);
				$container = json_decode(json_encode($container),true);
				$paramString = $container['SubscriptionTemplate']['MediaSubscription']['@attributes']['parameters'];
				unset($params);
				unset($url);
				$year = $array['Directory']['@attributes']['year'];
				$thumb = $array['Directory']['@attributes']['thumb'];
				$url=$_SESSION['uri_plexserver'].'/media/subscriptions?';
				$params = array();
				$prefs = array();
				//These need to be put into settings at some point in time
				$prefs['onlyNewAirings']=$_SESSION['dvr_newairings'];
				$prefs['minVideoQuality']=$_SESSION['resolution'];
				$prefs['replaceLowerQuality']=$_SESSION['dvr_replacelower'];
				$prefs['recordPartials']=$_SESSION['dvr_recordpartials'];
				$prefs['startOffsetMinutes']=$_SESSION['dvr_startoffset'];
				$prefs['endOffsetMinutes']=$_SESSION['dvr_endoffset'];
				$prefs['lineupChannel']='';
				$prefs['startTimeslot']=-1;
				$prefs['oneShot']="true";
				$prefs['autoDeletionItemPolicyUnwatchedLibrary']=0;
				$prefs['autoDeletionItemPolicyWatchedLibrary']=0;
				$params['prefs'] = $prefs;
				$sectionId = $array['Directory']['@attributes']['librarySectionID'];
				$title = $array['Directory']['@attributes']['title'];
				$params['targetLibrarySectionID']= $sectionId;
				$params['targetSectionLocationID']= $sectionId;
				$params['includeGrabs'] = 1;
				$params['type'] = $sectionId;
				$url .= http_build_query($params).'&'.$paramString.'&X-Plex-Token='.$_SESSION['token_plexserver'];
				$result = curlPost($url);
				if ($result) {
					$container = new SimpleXMLElement($result);
					foreach ($container->MediaSubscription as $subscription) {
						$show = json_decode(json_encode($subscription),true);
						$added = $show['Directory']['@attributes']['title'];
						if (cleanCommandString($title) == cleanCommandString($added)) {
							write_log("Show added to record successfully: ".json_encode($show));
							$return = array(
								title=>$added,
								year=>$year,
								type=>$show['Directory']['@attributes']['type'],
								thumb=>$thumb,
								art=>$thumb,
								url=>$_SESSION['uri_plexserver'].'/subscriptions/'.$subscription['@attributes']['key'].'?X-Plex-Token='.$_SESSION['token_plexserver']
							);
							return $return;
						}
					}
				}
			}
		}
		return false;
	}

	// This is now our one and only handler for searches.
	function parsePlayCommand($command,$year=false) {
		$searchType = false;
		write_log("################parsePlayCommand_START$##########################");
		write_log("Function Fired.");
		write_log("Initial command - ".$command);
		$parsedCommand = "";
		$commandArray = explode(" "	,$command);
		// An array of words which don't do us any good
		// Adding the apostrophe and 's' are necessary for the movie "Daddy's Home", which Google Inexplicably returns as "Daddy ' s Home"
		$stripIn = array("of","the","an","a","at","th","nd","in","it","from","'","s","and","on","in");

		// An array of words that indicate what kind of media we'd like
		$mediaIn = array("season","series","show","episode","movie","film","beginning","rest","end","minutes","minute","hours","hour","seconds","second");

		// An array of words that would modify or filter our search
		$filterIn = array("genre","year","actor","director","directed","starring","featuring","with","made","created","released","filmed");

		// An array of words that would indicate which specific episode or media we want
		$numberWordIn = array("first","pilot","second","third","last","final","latest","random");

		// An array of words to indicate where to start playing from (reference, we should filter these from media)
		$timeIn = array("beginning","rest","end","minute","minutes","hour","hours");

		foreach($_SESSION['list_plexclient'] as $client) {
			write_log("I got a client named ".$client['name']);
			$clientName = '/'.strtolower($client['name']).'/';
			if (preg_match($clientName,$command)) {
				write_log("I was just asked me to play something on a specific device: ".$client['name']);
				$playerIn = explode(" ",cleanCommandString($client['name']));
				$_SESSION['id_plexclient'] = $client['id'];
				$_SESSION['name_plexclient'] = $client['name'];
				$_SESSION['uri_plexclient'] = $client['uri'];
				$_SESSION['product_plexclient'] = $client['product'];
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClient',$client['id']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientProduct',$client['product']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientName',$client['name']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientUri',$client['uri']);
				saveConfig($_SESSION['config']);
			}
		}

		if ($playerIn) {
			$commandArray = array_diff($commandArray,$playerIn);
		}

		// An array of words from our command that are numeric
		$numberIn=array();
		foreach($commandArray as $number) {
			if ((is_numeric($number)) || in_array($number,$numberWordIn)) {
				array_push($numberIn,$number);
			}
		}

		// Create arrays of values we need to evaluate
		$stripOut = array_intersect($commandArray,$stripIn);
		$mediaOut = array_intersect($commandArray,$mediaIn);
		$filterOut = array_intersect($commandArray,$filterIn);
		$numberOut = array_intersect($commandArray,$numberIn);

		if ($year) {
			array_push($mediaOut,'year');
			array_push($numberOut,$year);
		}

		$mods = array();
		$mods['num'] = array();
		$mods['filter'] = array();
		$mods['media'] = array();

		if ($stripOut) {
			$commandArray = array_diff($commandArray, $stripOut);
			write_log("stripOut: ".implode(" : ",$stripOut));
		}

		if ($filterOut) {
			$commandArray = array_diff($commandArray, $filterOut);
			//					 "genre","year","actor","director","directed","starring","featuring","with","made","created","released","filmed"
			$replaceArray = array("","","actor","director","director","actor","actor","actor","year","year","year","year");
			$filterOut = str_replace($filterIn,$replaceArray,$filterOut);
			$mods['filter']=$filterOut;
			write_log("filterOut: ".implode(" : ",$mods['filterOut']));
		}

		if ($mediaOut) {
			$commandArray = array_diff($commandArray, $mediaOut);
			//					  "season","series","show","episode","movie","film","beginning","rest","end","minute","minutes","hour","hours"
			$replaceArray = array("season","season","show","episode","movie","movie","0","-1","-1","mm","mm","hh","hh","ss","ss");
			write_log("mediaOut: ".implode(" : ",$mediaOut));
			$mediaOut=str_replace($mediaIn,$replaceArray,$mediaOut);
			$timeIn = array(0,-1,-1,"mm","mm","hh","hh");
			foreach($mediaOut as $media) {
				if (is_numeric($media)) {
					$mediaOut = array_diff($mediaOut,$media);
					array_push($mediaOut,"offset");
					array_push($numberOut,$media);
				}
			}
			$mods['media'] = $mediaOut;
		}
		$mods['preFilter'] = implode(" ",$commandArray);
		if ($numberOut) {
			$commandArray = array_diff($commandArray, $numberOut);
			// "first","pilot","second","third","last","final","latest","random"
			$replaceArray = array(1,1,2,3,-1,-1,-1,-2);
			$mods['num']=str_replace($numberWordIn,$replaceArray,$numberOut);
			write_log("numberOut: ".implode(" : ",$mods['num']));
		}

		if((empty($commandArray)) && (count($mods['num']) > count($mods['media']))) {
			array_push($commandArray,$mods['num'][count($mods['num'])-1]);
			unset($mods['num'][count($mods['num'])-1]);
		}
		write_log("Resulting string is:".implode(" : ",$commandArray));
		$mods['target']=implode(" ",$commandArray);
		$result = fetchInfo($mods); // Returns false if nothing found
		return $result;
	}


	// Parse and handle API.ai commands
	function parseApiCommand($request) {
		$contextName = "yes";
		write_log("Function fired.");
		write_log("Full request text is ".json_encode($request));
		$result = $request["result"];
		$sessionId = $request['sessionId'];
		$action = $request["result"]['parameters']["action"];
		$parameters = $request["result"]["parameters"];
		$clientToken = $request['originalRequest']['data']['user']['access_token'];
		$rawspeech = (string)$request['originalRequest']['data']['inputs'][0]['raw_inputs'][0]['query'];
		write_log("Raw speech is ".$rawspeech);
		$queryOut=array();
		$queryOut['serverURI'] = $_SESSION['uri_plexserver'];
		$queryOut['serverToken'] = $_SESSION['token_plexserver'];
		$queryOut['clientURI'] = $_SESSION['uri_plexclient'];
		$queryOut['clientName'] = $_SESSION['name_plexclient'];
		$queryOut['initialCommand'] = $rawspeech;
		$queryOut['timestamp'] = timeStamp();
		$control = (string)strtolower($request["result"]['parameters']["Controls"]);
		$command = false;
		$year = false;
		$command = (string) $request["result"]["parameters"]["command"];
		$command = cleanCommandString($command);
		$age = $request["result"]["parameters"]["age"];
		if (is_array($age))	$year = strtolower($request["result"]["parameters"]["age"]["amount"]);
		$control = strtolower($request["result"]["parameters"]["Controls"]);
		$greeting = false;

		$contexts=$result["contexts"];
		foreach($contexts as $context) {
			if (($context['name'] == 'promptfortitle') && ($action=='') && ($control=='') && ($command=='')) {
				$action = 'play';
				write_log("This is a response to a title query.");
				if (!($command)) $command = cleanCommandString($request['result']['resolvedQuery']);
				if ($command == 'googleassistantwelcome') {
					unset($command);
					unset($action);
					$greeting = true;
				}
			}
			if (($context['name'] == 'yes') && ($action=='fetchAPI')) {
				write_log("Context JSON should be ".json_encode($context));
				$command = (string)$context['parameters']['command'];
				$command = cleanCommandString($command);
			}
			if (($context['name'] == 'google_assistant_welcome') && ($action == '') && ($command == '') && ($control == ''))  {
				write_log("Looks like the default intent, we should say hello.");
				$greeting = true;
			}
		}

		if ($action == 'changeDevice') {
			$command = $request['result']['parameters']['player'];
			write_log("Got a player name: ".$command);
		}

		if ($control == 'skip forward') {
			write_log("Action should be changed now.");
			$action ='control';
			$command = 'skip forward';
		}
		if ($control == 'skip backward') {
			write_log("Action should be changed now.");
			$action ='control';
			$command = 'skip backward';
		}

		if (preg_match("/subtitles/",$control)) {
			write_log("Subtitles?");
			$action = 'control';
			$command = str_replace(' ', '', $control);
		}
		write_log("Final params should be an action of ".$action.", a command of ".$command.", and a control of ".$control);
		if($action == 'changeDevice') write_log("Got a change device command: ");
		// This value tells API.ai that we are done talking.  We set it to a positive value if we need to ask more questions/get more input.
		$waitForResponse = false;
		$contextName = "yes";
		$queryOut['commandType'] = $action;
		$resultData = array();

		if ($greeting) {
			$greetings = array("Hi, I'm Flex TV.  What can I do for you today?","Greetings! How can I help you?","Hello there. Try asking me to play a movie or show.'");
			$speech = $greetings[array_rand($greetings)];
			$waitForResponse = true;
			$contextName = 'PlayMedia';
			returnSpeech($speech,$contextName,$waitForResponse);
			unset($_SESSION['deviceArray']);
			die();
		}

		if (($action == 'record') && ($command)) {
			write_log("Got a record command.");
			$waitForResponse = false;
			$contextName = 'waitforplayer';
			if($_SESSION['uri_plexdvr']) {
				$result = parseRecordCommand($command);
				if($result) {
					$title = $result['title'];
					$year = $result['year'];
					$type = $result['type'];
					$queryOut['parsedCommand'] = 'Add the '.$type.' named '.$title.' ('.$year.') to the recording schedule.';
					$speech = "Hey, look at that.  I've added the ".$type." named ".$title." (".$year.") to the recording schedule.";
					$results['url'] = $result['url'];
					$results['status'] = "Success.";
					$queryOut['mediaResult'] = $result;
					$queryOut['mediaStatus'] = 'SUCCESS: Not a media command';
					$queryOut['commandType'] = 'dvr';
				} else {
					$queryOut['parsedCommand'] = 'Add the media named '.$command;
					$speech = "I wasn't able to find any results in the episode guide that match '".ucwords($command)."'.";
					$results['url'] = $result['url'];
					$results['status'] = "No results.";
				}
			} else {
				$speech = "I'm sorry, but I didn't find any instances of Plex DVR to use.";
			}
			returnSpeech($speech,$contextName,$waitForResponse);
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			die();

		}

		if (($action == 'changeDevice') && ($command)) {
			$list = $_SESSION['deviceArray'];
			$score = 0;
			foreach($list as $device) {
				$value = similarity(cleanCommandString($device['name']),cleanCommandString($command));
				if (($value >=.7) && ($value >= $score)) {
					write_log("Got a winner: ".$device['name']);
					$result = $device;
					$score = $value;
				}
			}
			write_log("Result should be ".json_encode($result));
			if ($result) {
				$typeString = (($result['product'] == 'Plex Media Server') ? 'server' : 'client');
				$speech = "Okay, I've switched the ".$typeString." to ".$command.".";
				$waitForResponse = false;
				$contextName = 'waitforplayer';
				returnSpeech($speech,$contextName,$waitForResponse);
				write_log("Still alive.");
				$name = (($result['product'] == 'Plex Media Server') ? 'plexServer' : 'plexClient');
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],$name,$result['id']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],$name.'Uri',$result['uri']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],$name.'Name',$result['name']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],$name.'Product',$result['product']);
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],$name.'Token',$result['token']);
				saveConfig($_SESSION['config']);
				setSessionVariables();
				$queryOut['playResult']['status'] = 'SUCCESS: '.$typeString. ' changed to '.$command.'.';
			} else {
				$speech = "I'm sorry, but I couldn't find a ".$typeString." named ".$command." to select.";
				$waitForResponse = false;
				$contextName = 'waitforplayer';
				returnSpeech($speech,$contextName,$waitForResponse);
				$queryOut['playResult']['status'] = 'ERROR: No device to select.';
			}
			$queryOut['parsedCommand'] = "Change ".$typeString." to ".$command.".";
			$queryOut['speech'] = $speech;
			$queryOut['mediaStatus'] = "Not a media command.";
			refreshDevices($type,true);
			log_command(json_encode($queryOut));
			unset($_SESSION['deviceArray']);
			die();
		}

		if ($action == 'status') {
				$status = playerStatus();
				write_log("Raw status ".$status);
				$status = json_decode($status,true);
				write_log("Status is ".$status['status']);
				if ($status['status'] == 'playing') {
					$type = $status['mediaResult']['@attributes']['type'];
					$player = $_SESSION['name_plexclient'];
					$title = $status['mediaResult']['@attributes']['title'];
					if ($type == 'episode') {
						$showTitle = $status['mediaResult']['@attributes']['grandparentTitle'];
						$epNum = $status['mediaResult']['@attributes']['index'];
						$seasonNum = $status['mediaResult']['@attributes']['parentIndex'];
						$speech = "Currently, Season ".$seasonNum." episode ". $epNum. " of ".$showTitle." is playing. This episode is named ".$title.".";
					} else {
						$speech = "Currently, the ".$type." ".$title." is playing on ".$player.".";
					}
				} else {
					$speech = "It doesn't look like there's anything playing right now.";
				}
				$waitForResponse = false;
				$contextName = 'PlayMedia';
				returnSpeech($speech,$contextName,$waitForResponse);
				$queryOut['parsedCommand'] = "Report player status";
				$queryOut['speech'] = $speech;
				$queryOut['mediaStatus'] = "Success: Player status retrieved";
				$queryOut['mediaResult'] = $status['mediaResult'];
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
			die();
		}

		if ($action == 'upcoming') {
			write_log("Got an upcoming recordings request.");
			if($_SESSION['uri_plexdvr']) {
				$url = $_SESSION['uri_plexdvr'].'/media/subscriptions/scheduled?X-Plex-Token='.$_SESSION['token_plexdvr'];
				write_log("URL is ".$url);
				$result = curlGet($url);
				if ($result) {
					$container = new SimpleXMLElement($result);
					$array = json_decode(json_encode($container),true);
					write_log("Got a container: ".json_encode($array));
				}
			}

		}

		if (($action == 'recent') || ($action == 'ondeck')) {
			$type = $request["result"]['parameters']["type"];
			$list = (($action =='recent') ? fetchHubList($action,$type) : fetchHubList($action));
			if ($list) {
				write_log("Got me some results: ".$list);
				$array = json_decode($list,true);
				$speech = (($action=='recent')? "Here's a list of recent ".$type."s: " : "Here's a list of on deck items: ");
				$i = 1;
				$count = count($array);
				foreach($array as $result) {
					$title = $result['@attributes']['title'];
					$showTitle = $result['@attributes']['grandparentTitle'];
					$type = trim($result['@attributes']['type']);
					write_log("Media item ".$title." is a ".$type);
					if ($type == 'episode') {
						write_log("This is a show, appending show title.");
						write_log($showTitle);
						$title = $showTitle.": ".$title;
					}
					if (($i == $count) && ($count >=2)) {
						$speech .= "and ". $title.".";
					} else {
						$speech .= $title.", ";
					}
					$i++;
				}
				$queryOut['mediaStatus'] = 'SUCCESS: Hub array returned';
				$queryOut['mediaResult'] = $array[0];
			} else {
				write_log("Error fetching hub list.");
				$queryOut['mediaStatus'] = "ERROR: Could not fetch hub list.";
				$speech = "Unfortunately, I wasn't able to find any results for that.  Please try again later.";
			}
			$waitForResponse = false;
			$contextName = 'PlayMedia';
			returnSpeech($speech,$contextName,$waitForResponse);
			$queryOut['parsedCommand'] = "Return a list of ".$action.' '.(($action == 'recent') ? $type : 'items').'.';
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			unset($_SESSION['deviceArray']);
			die();
		}

		// Start handling playback commands now"
		if (($action == 'play') || ($action == 'playfromlist')) {
			if (!($command)) {
				write_log("This does not have a command.  Checking for a different identifier.");
				foreach($request["result"]['parameters'] as $param=>$value) {
					if ($param == 'type') {
						$mediaResult = fetchRandomNewMedia($value);
						$queryOut['parsedCommand'] = 'Play a random ' .$value;
					}
				}
			} else {
				if ($action == 'playfromlist') {
					$list = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'mlist',false);
					$list = base64_decode($list);
					write_log("Decode List: ".$list);
					if ($list) $_SESSION['mediaList'] = json_decode($list,true);
					write_log("So, we have a list to play from, neat.");
					foreach($_SESSION['mediaList'] as $mediaItem) {
						write_log("MediaItemJSON: ".json_encode($mediaItem));
						$title = cleanCommandString($mediaItem['@attributes']['title']);
						$cleanedRaw = cleanCommandString($rawspeech);
						if ($age) $title .= " ".$mediaItem['@attributes']['year'];
						$weight = similarity($title,$cleanedRaw);
						write_log("Weight of ".$title." versus ".$cleanedRaw." is ".$weight.".");
						if ($weight >=.8) {
							$mediaResult = [$mediaItem];
							break;
						}
					}
					if (! $mediaResult) {
						if (preg_match('/none/',$cleanedRaw) || preg_match('/neither/',$cleanedRaw) || preg_match('/nevermind/',$cleanedRaw) || preg_match('/cancel/',$cleanedRaw)) {
							$speech = "Okay.";
						} else {
							$speech = "I'm sorry, but '".$rawspeech."' doesn't seem to match anything I just said.";
						}
						$waitForResponse = false;
						returnSpeech($speech,$contextName,$waitForResponse);
						die();
					}
				} else {
					$mediaResult = parsePlayCommand(strtolower($command),$year);
				}
			}
			if ($mediaResult) {
				if (count($mediaResult)==1) {
					write_log("Got media, sending play command.");
					$queryOut['mediaResult'] = $mediaResult[0];
					$searchType = $queryOut['mediaResult']['searchType'];
					$title = $queryOut['mediaResult']['title'];
					$year = $queryOut['mediaResult']['year'];
					$type = $queryOut['mediaResult']['type'];
					$queryOut['parsedCommand'] = 'Play the '.(($searchType == '') ? $type : $searchType). ' named '. $title.'.';
					unset($affirmatives);
					$affirmatives = array("Yes captain, ","Okay, ","Sure, ","No problem, ","Yes master, ","You got it, ","As you command, ","Allrighty then, ");
					$titlelower = strtolower($title);
					switch($titlelower) {
						case (strpos($titlelower, 'batman') !== false):
							$affirmative = "Holy pirated media!  ";
							break;
						case (strpos($titlelower, 'ghostbusters') !== false):
							$affirmative = "Who you gonna call?  ";
							break;
						case (strpos($titlelower, 'iron man') !== false):
							$affirmative = "Yes Mr. Stark, ";
							break;
						case (strpos($titlelower, 'avengers') !== false):
							$affirmative = "Family assemble! ";
							break;
						case (strpos($titlelower, 'frozen') !== false):
							$affirmative = "Let it go! ";
							break;
						case (strpos($titlelower, 'space odyssey') !== false):
							$affirmative = "I am afraid I can't do that Dave.  Okay, fine, ";
							break;
						case (strpos($titlelower, 'big hero') !== false):
							$affirmative = "Hello, I am Baymax, I am going to be ";
							break;
						case (strpos($titlelower, 'wall-e') !== false):
							$affirmative = "Thank you for shopping Buy and Large, and enjoy as we begin ";
							break;
						case (strpos($titlelower, 'evil dead') !== false):
							$affirmative = "Hail to the king, baby! "; //"playing Evil Dead 1/2/3/(2013)"
							break;
						case (strpos($titlelower, 'fifth element') !== false):
							$affirmative = "Leeloo Dallas Mul-ti-Pass! "; //"playing The Fifth Element"
							break;
						case (strpos($titlelower, 'game of thrones') !== false):
							$affirmative = "Brace yourself...";
							break;
						case (strpos($titlelower, 'they live') !== false):
							$affirmative = "I'm here to chew bubblegum and ";
							break;
						case (strpos($titlelower, 'heathers') !== false):
							$affirmative = "Well, charge me gently with a chainsaw.  ";
							break;
						case (strpos($titlelower, 'star wars') !== false):
							$affirmative = "These are not the droids you're looking for.  ";
							break;
					}
					// Put our easter egg affirmative in the array of other possible options, so it's only sometimes used.

					array_push($affirmatives,$affirmative);

					// Make sure we didn't just say whatever affirmative we decided on.
					do {
						$affirmative = $affirmatives[array_rand($affirmatives)];
					} while ($affirmative == $_SESSION['affirmative']);

					// Store the last affirmative.
					$_SESSION['affirmative'] = $affirmative;

					if ($type == 'episode') {
						$seriesTitle = $queryOut['mediaResult']['grandparentTitle'];
						$speech = $affirmative. "Playing the episode of ". $seriesTitle ." named ".$title.".";
					} else {
						$speech = $affirmative. "Playing ".$title . " (".$year.").";
					}
					if ($_SESSION['promptfortitle'] == true) {
						$contextOut = 'promptfortitle';
						$_SESSION['promptfortitle'] = false;
					}
					$waitForResponse = false;
					returnSpeech($speech,$contextName,$waitForResponse);
					$playResult = playMedia($mediaResult[0]);
					$exact = $mediaResult[0]['@attributes']['exact'];
					$queryOut['speech'] = $speech;
					$queryOut['mediaStatus'] = "SUCCESS: ".($exact ? 'Exact' : 'Fuzzy' )." result found";
					$queryOut['playResult'] = $playResult;
					write_log("Type and stuff: ".$type. " and ".$title);

					log_command(json_encode($queryOut));
					unset($_SESSION['deviceArray']);
					die();
				}

				if (count($mediaResult)>=2) {
					write_log("Got multiple results, prompting for moar info.");
					$speechString = "";
					$resultTitles = array();
					$count = 0;
					$_SESSION['config']->set('user-_-'.$_SESSION['username'],'mlist',base64_encode(json_encode($mediaResult)));
					saveConfig($_SESSION['config']);
					write_log("MR: ".print_r($_SESSION['mediaList'],true));
					foreach($mediaResult as $Media) {
						$count++;
						write_log("Counting: ".$count. " and ". count($mediaResult));
						if ($count == count($mediaResult)) {
							$speechString .= " or ".$Media['title']." ".$Media['year'].".";
						} else {
							$speechString .= " ".$Media['title']." ".$Media['year'].",";
						}
						array_push($resultTitles,$Media['title']." ".$Media['year']);
					}
					$speech = "I found several possible results for that, which one was it?  ". $speechString;
					$contextName = "promptfortitle";
					$_SESSION['promptfortitle'] = true;
					if (isset($_SESSION['mediaList'])) unset($_SESSION['mediaList']);
					$waitForResponse = true;
					returnSpeech($speech,$contextName,$waitForResponse);
					$playResult = playMedia($mediaResult);
					$queryOut['parsedCommand'] = 'Play a media item named '.$command.'. (Multiple results found)';
					$queryOut['mediaStatus'] = 'SUCCESS: Multiple Results Found, prompting user for more information';
					$queryOut['playResult'] = $playResult;
					$title = $queryOut['mediaResult']['title'];
					$type = $queryOut['mediaResult']['type'];
					log_command(json_encode($queryOut));
					unset($_SESSION['deviceArray']);
					die();
				}

			} else {
				$waitForResponse = true;
				if ($command) {
					$speech = "I'm sorry, I was unable to find ".$command." in your library.  Would you like me to add it to your watch list?";
					returnSpeech($speech,$contextName,$waitForResponse);
					$queryOut['parsedCommand'] = "Play a media item with the title of '".$command.".'";
					$queryOut['mediaStatus'] = 'ERROR: No results found, prompting to download.';
					$contextName = 'yes';
					$queryOut['speech'] = $speech;
					log_command(json_encode($queryOut));
					die();
				}
			}
		}


		if (($action == 'player') || ($action == 'server')) {
			write_log("Got a request to change ".$action);
			unset($_SESSION['deviceArray']);
			$type = (($action == 'player') ? 'clients' : 'servers');
			$list = refreshDevices($type);
			if (count($list) >=2) {
				$_SESSION['deviceArray'] = $list;
				foreach($list as $device) {
					$count++;
					if ($count == count($list)) {
						$speechString .= " or ".$device['name'].".";
					} else {
						$speechString .= " ".$device['name'].",";
					}
				}
				$speech = "Change ".$action.", sure.  What device would you like to use? ".$speechString;
				$contextName = "waitforplayer";
				$waitForResponse = true;
			}
			if (count($list) == 1) {
				$speech = "I'd like to help you with that, but I only see one ".$action." that I can currently talk to.";
				$contextName = "waitforplayer";
				$waitForResponse = false;
			}
			returnSpeech($speech,$contextName,$waitForResponse);
			$queryOut['parsedCommand'] = 'Switch '.$action.'.';
			$queryOut['mediaStatus'] = 'Not a media command.';
			$queryOut['speech'] = $speech;
			log_command(json_encode($queryOut));
			die();

		}

		if ($action == 'fetchAPI') {
			$response = $request["result"]['parameters']["YesNo"];
			if ($response == 'yes') {
				$action = 'fetch';
			} else {
				$speech = "Okay, let me know if you change your mind.";
				$waitForResponse = false;
				returnSpeech($speech,$contextName,$waitForResponse);
				die();
			}
		}

		if (($action == 'fetch') && ($command)) {
			$queryOut['parsedCommand'] = 'Fetch the media named '.$comand.'.';
			$waitForResponse = false;
			$result = parseFetchCommand($command);
			if ($result['status'] === 'success') {
				$queryOut['mediaResult'] = $result['mediaResult'];
				$resultTitle = $result['mediaResult']['@attributes']['title'];
				$resultYear = $result['mediaResult']['@attributes']['year'];
				$resultImage = $result['mediaResult']['@attributes']['art'];
				$resultSummary = $result['mediaResult']['@attributes']['summary'];
				$resultData['image'] = $resultImage;
				//$resultData[]
				if ($resultTitle) {
					$speech = "Okay, I've added ".$resultTitle." (".$resultYear.") to the fetch list.";
				} else {
					$speech = "Hmmm, looks like there was an issue understanding '".$command."' as a fetch command.";
				}
				returnSpeech($speech,$contextName,$waitForResponse);
				$queryOut['mediaStatus'] = 'SUCCESS: Media added to fetcher.';
				$queryOut['speech'] = $speech;
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
				die();
			}
			if ($result['status'] == 'already in searcher') {
				$resultTitle = $result['mediaResult']['@attributes']['title'];
				$resultYear = $result['mediaResult']['@attributes']['year'];
				$resultImage = $result['mediaResult']['@attributes']['art'];
				$resultSummary = $result['mediaResult']['@attributes']['summary'];
				$resultData['image'] = $resultImage;
				//$resultData[]
				$speech = "It looks like ".$resultTitle." is already set to download.";
				returnSpeech($speech,$contextName,$waitForResponse);
				$queryOut['mediaStatus'] = 'SUCCESS: Media already in fetcher.';
				$queryOut['speech'] = $speech;
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
				die();
			}
			else {
				$speech = "Unfortunately, I was not able to find anything with that title to download.";
				returnSpeech($speech,$contextName,$waitForResponse);
				$queryOut['mediaStatus'] = 'ERROR: Nothing found to fetch.';
				$queryOut['speech'] = $speech;
				log_command(json_encode($queryOut));
				unset($_SESSION['deviceArray']);
				die();
			}
		}


		$cleaned = cleanCommandString($rawspeech);
		if (preg_match('/to play/',cleanCommandString($rawspeech))) {
			$action = 'control';
			$command = 'play';
		}

		if (($action == 'control') || ($control != '')) {
			if ($action == '') $command = cleanCommandString($control);
			$waitForResponse = false;
			if (preg_match("/volume/",$command)) {
				$int = strtolower($request["result"]["parameters"]["percentage"]);
				if ($int != '') {
					$command .= " " . $int;
					$speech = "Okay, setting the volume to ".$int;
				} else {
					$adjust = false;
					if (preg_match("/up/",$rawspeech)) {
						write_log("UP, UP, UP");
						$int = (($_SESSION['volume'] <=90) ? $_SESSION['volume'] + 10 : 100);
						$command .= " UP";
						$speech = "Okay, I'll turn it up a little.";
					}
					if (preg_match("/down/",$rawspeech)) {
						write_log("DOWN, DOWN, DOWN");
						$command .= " DOWN";
						$speech = "Okay, I'll turn it down a little.";
					}
				}
			} else {
				switch ($command) {
					case "resume":
					case "play":
						$speech = 'Resuming playback.';
						break;
					case "stop":
						$speech = 'Plex should now be stopped.';
						break;
					case "pause":
						$speech = 'Plex should now be paused.';
						break;
					case "subtitleson":
						$speech = 'Subtitles have been enabled.';
						$queryOut['initialCommand'] = $rawspeech;
						$queryOut['parsedCommand'] = "Enable Subtitles.";
						break;
					case "subtitlesoff":
						$speech = 'Subtitles have been disabled.';
						$queryOut['initialCommand'] = $rawspeech;
						$queryOut['parsedCommand'] = "Disable Subtitles.";
						break;
					default:
						$speech = 'Sending a command to '.$command;
				}
			}
			$queryOut['speech'] = $speech;
			returnSpeech($speech,$contextName,$waitForResponse);
			if ($command == 'jump to') {
				write_log("This is a jump command, raw speech was ".$rawspeech);
			}
			$result = parseControlCommand($command);
			$newCommand = json_decode($result,true);
			$newCommand = array_merge($newCommand,$queryOut);
			$newCommand['timestamp'] = timeStamp();
			$result = json_encode($newCommand);
			log_command($result);
			refreshDevices('clients',true);
			unset($_SESSION['deviceArray']);
			die();

		}

			// Say SOMETHING if we don't undersand the request.
		$unsureAtives = array("I'm afraid I don't understand what you mean by ".$rawspeech.".","Unfortunately, I couldn't figure out to do when you said '".$rawspeech."'.","Danger Will Robinson!  Command '".$rawspeech."' not understood!","I'm sorry, your request of '".$rawspeech."' does not compute.");
		$speech = $unsureAtives[array_rand($unsureAtives)];
		$waitForResponse = false;
		$contextName = 'playmedia';
		returnSpeech($speech,$contextName,$waitForResponse);
		$queryOut['parsedCommand'] = 'Command not recognized.';
		$queryOut['mediaStatus'] = 'ERROR: Command not recognized.';
		$queryOut['speech'] = $speech;
		log_command(json_encode($queryOut));
		unset($_SESSION['deviceArray']);
		die();


	}
	//
	// ############# Client/Server Functions ############
	//

	// Sign in, get a token if we need it

	function signIn($plexCred) {
		$token = $_SESSION['plex_token'];
		$url = 'https://plex.tv/pms/servers.xml?X-Plex-Token='.$_SESSION['plex_token'];
		$ch=curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
		$result=curl_exec($ch);
		curl_close($ch);
		if (strpos($result,'Please sign in.')){
			write_log("Test connection to Plex failed, updating token.");
			$url='https://plex.tv/users/sign_in.xml';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'X-Plex-Client-Identifier: '.$_SESSION['deviceID'],
				'X-Plex-Device:PhlexWeb',
				'X-Plex-Device-Screen-Resolution:1520x707,1680x1050,1920x1080',
				'X-Plex-Device-Name:Phlex',
				'X-Plex-Platform:Web',
				'X-Plex-Platform-Version:1.0.0',
				'X-Plex-Product:Phlex',
				'X-Plex-Version:1.0.0',
				'X-Plex-Provides:player,controller,sync-target,pubsub-player',
				'Authorization:Basic '.$plexCred

			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$result = curl_exec ($ch);
			curl_close ($ch);
			if ($result) {
				$container = new SimpleXMLElement($result);
				$token = (string)$container['authToken'];
				return $token;
			}
			write_log("Valid token could not be found.");
			return false;
		}
		return $token;
	}

	function refreshDevices($type,$force=null) {
		if ($force) write_log("Forcing recache.");
		if (!(isset($_GET['pollPlayer']))) write_log("Checking for cached ".$type);
		$now = microtime(true);
		if (($type == 'clients') || ($type == 'plexClients') || ($type == 'plexClient')) {
			$lastCheck = $_SESSION['fetch_plexclient'];
			$list = $_SESSION['list_plexclient'];
			$type = 'clients';
		}
		if (($type == 'servers') || ($type == 'plexServers') || ($type == 'plexServer')) {
			$lastCheck = $_SESSION['fetch_plexserver'];
			$list = $_SESSION['list_plexserver'];
			$type = 'servers';
		}
		$diffSeconds = round($now-$lastCheck);
		$diffMinutes = ceil($diffSeconds/60);
		if (($diffMinutes >= 5) || $force || is_null($list)) {
			if (!(isset($_GET['pollPlayer']))) write_log("Time expired or forced, re-fetching ".$type);
			$list = fetchDevices($type);
			if (($type == 'clients') || ($type == 'plexClients')) {
			$_SESSION['list_plexclient'] = $list;
		}
		if (($type == 'servers') || ($type == 'plexServers')) {
			$_SESSION['list_plexserver'] = $list;
		}
		} else {
			if (!(isset($_GET['pollPlayer']))) write_log("Returning cached list of ".$type);
		}
		return $list;
	}

	// Should have been doing this the whole time.
	// Pass one paramater to this function, either "clients" or "servers".
	// Returns an array of devices, which we then parse and display in a dropdown.
	// The dropdown is displayed every time it is clicked, thus, the list is updated.
	// When an item is changed, send the relevant info (URL, token, ID) back and save it in settings, instead of
	// caching all the devices.

	function fetchDevices($type) {
		write_log("Looking for ".$type);
		$url = 'https://plex.tv/api/resources'.(($type=='clients') ? '?X-Plex-Token=' : '?includeHttps=1&X-Plex-Token=').$_SESSION['plex_token'];
		$settingType = (($type =='clients') ? 'plexClient' : 'plexServer');
		$selected = $_SESSION['config']->get('user-_-'.$_SESSION['username'], $settingType, false);
		if (!(isset($_GET['pollPlayer']))) write_log('URL is: '.protectURL($url));
		if($selected) {
			$selectedName = $_SESSION['config']->get('user-_-'.$_SESSION['username'], $settingType.'Name', false);
		}
		if (!(isset($_GET['pollPlayer'])))write_log("I am looking for ".$type);
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$devices = array();
			$i = 0;
			foreach ($container->children() as $device) {
				unset($deviceOut);
				$deviceOut = array();
				$add = false;
				$provides = explode(',',(string)$device['provides']);
				$present = ($device['presence'] == 1);
				$local = ($device['publicAddressMatches'] == 1);
				$owned = ($device['owned'] == 1);
				$publicAddress = (string) $device['publicAddress'];
				$deviceOut['name'] = (string) $device['name'];
				$deviceOut['product'] = (string) $device['product'];
				$deviceOut['id'] = (string) $device['clientIdentifier'];
				$deviceOut['token'] = (($device['accessToken']=="") ? $_SESSION['plexToken'] : (string)$device['accessToken']);
				if ($present) {
					$deviceOut['selected'] = false;
					if ($selected) {
						if (trim($deviceOut['id']) == trim($selected)) {
							if (!(isset($_GET['pollPlayer'])))write_log( "I have a preselected device - ".$deviceOut['name']);
							if (!(isset($_GET['pollPlayer'])))write_log( "Stuff: ".$deviceOut['id'] ." vs ". $selected);
							$deviceOut['selected'] = true;
						}
					} else {
						if ($i == 0) {
							if (!(isset($_GET['pollPlayer'])))write_log( "Using device ".$deviceOut['name']." as first selected item.");
							$deviceOut['selected'] = true;
						}
					}

					if ($type == 'clients') {
						if (arrayContains('player',$provides)) {
							write_log("This matches.");
						}
					}
					if (($type === 'clients') && (arrayContains('player',$provides)) && $owned)  {
						foreach ($device->Connection as $connection) {
							$address = (string) $connection['address'];
							$octets = explode(".",$address);
							if (($connection['local'] == 1) && ($octets[0] != 169)) {
								$deviceOut['uri'] = (string) rtrim($connection['uri'], '/');
								array_push($devices, $deviceOut);
								$i++;
								break;
							}
						}
					}

					if (($type == 'servers') && arrayContains('server',$provides)) {
						$deviceOut['uri'] = false;
						$platform = $device['platform'];
						$deviceOut['publicAddress'] = (string) $device['publicAddress'];
						foreach ($device->Connection as $connection) {
							if ($connection['local'] == $local) {
								if (strtolower($platform) == 'linux') {
									$deviceOut['uri'] = 'http://'.$connection['address'].':'.$connection['port'];
								} else {
									$deviceOut['uri'] = (string) rtrim($connection['uri'], '/');
								}
								array_push($devices, $deviceOut);
								$i++;
								break;
							}
						}
					}

				}

			}

			if (($type == 'clients') && ($_SESSION['use_cast'])) {
			$castDevices = fetchCastDevices();
				foreach ($castDevices as $castDevice) {
					if (trim($castDevice['id']) == trim($selected)) {
						$castDevice['selected'] = true;
					}
					array_push($devices, $castDevice);
				}
			}
			if ($type == 'clients') {
				$_SESSION['fetch_plexclient'] = microtime(true);
				$_SESSION['list_plexclient'] = $devices;
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexClientFetched',$_SESSION['fetch_plexclient']);
			} else {
				$_SESSION['fetch_plexserver'] = microtime(true);
				$_SESSION['list_plexserver'] = $devices;
				$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexServerFetched',$_SESSION['fetch_plexserver']);
			}
			saveConfig($_SESSION['config']);
			if (!(empty($devices)))	return $devices;
		}
		return false;
	}


	function fetchCastDevices() {
		if (!(isset($_GET['pollPlayer']))) write_log("Function fired.");
		$result = Chromecast::scan();
		$returns = array();
		if (!(isset($_GET['pollPlayer']))) write_log("Returns: ".json_encode($result));
		foreach ($result as $key=>$value) {
			$deviceOut = array();
			$nameString = preg_replace("/\._googlecast.*/","",$key);
			$nameArray = explode('-',$nameString);
			$id = array_pop($nameArray);
			$deviceOut['name'] = $value['fname'];
			$deviceOut['product'] = 'cast';
			$deviceOut['id'] = $id;
			$deviceOut['token'] = 'none';
			$deviceOut['uri'] = "https://" . $value['ip'] . ":" . $value['port'];
			array_push($returns, $deviceOut);
		}
		return $returns;
	}


	/// What used to be a bigl ugly THING is now just a wrapper and parser of the result of fetchDevices
	function fetchClientList() {
		if (!(isset($_GET['pollPlayer']))) write_log("Function Fired.");
		$clients = refreshDevices('clients');
		$options = "";
		if ($clients) {
			if (!(isset($_GET['pollPlayer']))) write_log("Client list retrieved.");
			foreach($clients as $client) {
				$selected = $client['selected'];
				$id = $client['id'];
				$name = $client['name'];
				$uri = $client['uri'];
				$product = $client['product'];
				$displayName = $name;
				$options.='<a class="dropdown-item client-item'.(($selected) ? ' dd-selected':'').'" href="#" product="'.$product.'" value="'.$id.'" name="'.$name.'" uri="'.$uri.'">'.ucwords($displayName).'</a>';
			}
		}
		return $options;
	}


	// Fetch a list of servers for playback
	function fetchServerList() {
		if (!(isset($_GET['pollPlayer']))) write_log("Function Fired.");
		$clients = refreshDevices('servers');
		$options = "";
		if ($clients) {
			foreach($clients as $client) {
				$selected = $client['selected'];
				$id = $client['id'];
				$name = $client['name'];
				$uri = $client['uri'];
				$token = $client['token'];
				$product = $client['product'];
				$publicAddress = $client['publicAddress'];
				$options .= '<option type="plexServer" publicAddress="'.$publicAddress.'" product="'.$product.'" value="'.$id.'" uri='.$uri.' name="'.$name.'"'.' token="'.$token.'"'.($selected ? ' selected':'').'>'.ucwords($name).'</option>';
			}
		}
		return $options;
	}

	function fetchDVRList() {
		$now = microtime(true);
		$lastCheck = $_SESSION['config']->get('user-_-'.$_SESSION['username'],'plexDVRFetched',$now);
		$diffSeconds = round($now-$lastCheck);
		$diffMinutes = ceil($diffSeconds/60);
		$_SESSION['config']->set('user-_-'.$_SESSION['username'],'plexDVRFetched',$now);
		saveConfig($_SESSION['config']);
		if ((! isset($_SESSION['list_plexdvr'])) || ($diffMinutes >=10) || $force) {
			write_log("Re-checking DVR List.");
			if (! isset($_SESSION['list_plexserver'])) refreshDevices('servers',true);
			$dvrList = array();
			foreach($_SESSION['list_plexserver'] as $server) {
				write_log("Here's what we've got: ".json_encode($server));
				$url = $server['uri'].'/tv.plex.providers.epg.onconnect:4?X-Plex-Token='.$server['token'];
				write_log("URL is ".$url);
				$epg = curlGet($url);
				if($epg) {
					if (preg_match('/mediaTagPrefix/',$epg)) array_push($dvrList,$server);
				}
			}
			if (count($dvrList)) {
				$chosen = $_SESSION['id_plexdvr'];
				foreach($dvrList as $client) {
					$id = $client['id'];
					$selected = ($chosen == $id);
					$name = $client['name'];
					$uri = $client['uri'];
					$token = $client['token'];
					$product = $client['product'];
					$publicAddress = $client['publicAddress'];
					$options .= '<option type="plexDVR" publicAddress="'.$publicAddress.'" product="'.$product.'" value="'.$id.'" uri='.$uri.' name="'.$name.'"'.' token="'.$token.'"'.($selected ? ' selected':'').'>'.ucwords($name).'</option>';
				}
			}
			$_SESSION['list_plexdvr'] = $options;
		}
		return $_SESSION['list_plexdvr'];
	}

	// Look up our device by name
	function fetchDeviceByName($name,$type) {
		if (!(isset($_GET['pollPlayer']))) write_log("Function fired");
		if (!(isset($_GET['pollPlayer']))) write_log("Looking for a type ".$type);
		$type = (($type=='players') ? 'clients' : 'servers');
		$name = strtolower(preg_replace("#[[:punct:]]#", "", $name));
		$list = refreshDevices($type);
		foreach($list as $device) {
			$devName = strtolower(preg_replace("/[^A-Za-z0-9 ]/", '', $device['name']));
			if (!(isset($_GET['pollPlayer']))) write_log("Looking for a match with a device named ".$name ." against ".$devName);
			if ($devName == $name) {
				write_log("Got the matching device.");
				return $device;
			}
		}
		return false;
	}

	// Fetch a transient token from our server, might be key to proxy/offsite playback
	function fetchTransientToken() {
		write_log("Function Fired.");
		$url = $_SESSION['uri_plexserver'].
		'/security/token?type=delegation&scope=all'.
		$_SESSION['plexHeader'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$ttoken = (string)$container['token'];
			if ($ttoken) {
				$_SESSION['transientToken'] = $ttoken;
				write_log("Transient token is valid: ".substr($ttoken,0,5));
				return $ttoken;
			}
		}
		return false;
	}


	//
	// ############# Media Find Functions ############
	//



	// Once we have parsed the play string and stripped out what we think are key terms,
	//send it over here to figure out if we have media that matches the user's query.
	function fetchInfo($matrix) {
		write_log("Function Fired.");
		$title = $matrix['target'];
		unset($matrix['target']);
		$type = false;
		$offset = 0;
		$season = false;
		$episode = false;
		$year = false;
		$actor = false;
		$director = false;
		$genre = false;
		$nums = $matrix['num'];
		$media = $matrix['media'];
		$filter = $matrix['filter'];
		$results2 = array();
		$winner = false;

		foreach($matrix as $key=>$mod) {
			write_log("Mod string: " .$key.": " .implode(", ",$mod));
			if ($key=='media') {
				foreach($mod as $flag) {
					if (($flag=='movie') || ($flag=='show')) {
						write_log("Media modifier is ".$flag);
						$type = $flag;
					}
					if(($key = array_search($type, $media)) !== false) {
						unset($media[$key]);
					}
				}
			}
			if ($key=='filter') {
				foreach($mod as $flag) {
					if (($flag=='movie') || ($flag=='show')) {
						write_log("Media modifier is ".$flag);
						$type = $flag;
					}
					if(($key = array_search($type, $media)) !== false) {
						unset($media[$key]);
					}
				}
			}
			if ($key=='preFilter') {
				write_log("We have a preFilter: ".$mod);
				$preFilter = $mod;
			}
		}
		$searchType = $type;
		$matchup = array();
		write_log("Mod counts are ".count($media). " and ".count($nums));
		if ((count($media) == count($nums)) && (count($media))) {
			write_log("Merging arrays.");
			$matchup = array_combine($media, $nums);
		} else {
			write_log("Number doesn't appear to be related to a context, re-appending.");
			$title = $preFilter;
		}

		if (count($matchup)) {
			foreach($matchup as $key=>$mod) {
				write_log("Mod string: " .$key.": " .$mod);
				switch ($key) {
					case 'hh':
						$offset += $mod*60*60*1000;
						break;
					case 'mm':
						$offset += $mod*60*1000;
						break;
					case 'ss':
						$offset += $mod*1000;
						break;
					case 'offset':
						$offset = $mod;
						break;
					case 'season':
						$type = 'show';
						$season = $mod;
						break;
					case 'movie':
						$type = 'movie';
						break;
					case 'episode':
						$type = 'show';
						$episode = $mod;
						break;
					case 'year':
						$year = $mod;
						break;
				}
			}
		}

		write_log("Offset has been set to ".$offset);
		$checkCount=0;

		checkString: {
		$winner = false;
			$results = fetchHubResults(strtolower($title),$type);
			if ($results) {
				if ((count($results)>=2) && (count($matchup))) {
					write_log("Multiple results found, let's see if there are any mods.");
					$resultCount=0;
					foreach($results as $result) {
						if ($year == $result['year']) {
							write_log("Hey, this looks like it matches." . $result['year']);
							$resultCount++;
							unset($results);
							$result['searchType'] = $searchType;
							$results = array($result);
							break;
						}
					}
				}

				// If we have just one result, check to see if it's a show.
				if (count($results)==1) {
					$winner = $results[0];
					if ($winner['type']=='show') {
						$showResult = $winner;
						$winner = false;
						write_log("This is a show, checking for modifiers.");
						$title = $showResult['title'];
						$key = $showResult['key'];
						$epNum = false;
						if ($episode == -2) write_log("FOUND IT HERE.");
						if (($season) || (($episode) && ($episode >= 1))) {
							if (($season) && ($episode)) {
								$selector = 'season';
								$num = $season;
								$epNum = $episode;
							}
							if ($season) {
								$selector = 'season';
								$num = $season;
								$epNum = false;
							} else {
								$selector = 'episode';
								$num = $episode;
								$epNum = false;
							}
							write_log("Mods Found, fetching a numbered TV Item.");
							$searchType = 'Numbered TV Item ';
							$winner = fetchNumberedTVItem($key,$num,$selector,$epNum);
						}
						if ($episode == -2) {
							write_log("Mods Found, fetching random episode.");
							$winner = fetchRandomEpisode($key);
						}
						if ($episode == -1) {
							write_log("Mods Found, fetching latest/newest episode.");
							$searchType = 'Latest TV Item ';
							$winner = fetchLatestEpisode($key);
						}
						if (!($winner)) {
							write_log("No Mods Found, returning first on Deck Item.");
							$showResult2 = json_decode(json_encode($showResult),true);
							$onDeck = $showResult->OnDeck->Video;
							$searchType = 'First On-deck TV Item '.$searchType;

							if ($ondeck) {
								$winner = $onDeck;
							} else {
								write_log("Show has no on deck items, fetching first episode.");
								$winner = fetchFirstUnwatchedEpisode($key);
							}
							write_log("Winning JSON: ".json_encode($onDeck));
							//write_log("WTF Now: ".print_r($showResult,true));
						}
					}
				}
			}
		}
		if ($winner) {
			write_log("We have a winner.  Title is ".$winner['title']);
			// -1 is our magic key to tell it to just use whatever is there
			if ($offset != -1) {
				$winner['viewOffset']=$offset;
			}
			write_log("Appending offset for ".$winner['title']. ' to '.$winner['viewOffset']);
			$final = array($winner);
			return $final;
		} else {
			return $results;
		}
		return false;
	}


	// This is our one-shot search mechanism
	// It queries the /hubs endpoint, scrapes together a bunch of results, and then decides
	// how relevant those results are and returns them to our talk-bot
	function fetchHubResults($title,$type=false) {
		write_log("Function Fired.");
		write_log("Type is ".$type);
		$title = cleanCommandString($title);
		$searchType = '';
		$url = $_SESSION['uri_plexserver'].'/hubs/search?query='.urlencode($title).'&limit=30&X-Plex-Token='.$_SESSION['token_plexserver'];
		$searchResult['url'] = $url;
		$cast = false;
		write_log('URL is : '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$exactResults = array();
			$fuzzyResults = array();
			$finalResults = array();
			$castResults = array();
			$genre = false;
			foreach($container->Hub as $Hub) {
				if ($Hub['size'] != "0") {
					if (($Hub['type'] == 'show') || ($Hub['type'] == 'movie') || ($Hub['type'] == 'episode')) {
						$nameLocation = 'title';
						write_log("Found search results for a movie, show, or episode.");
					}

					if (($Hub['type'] == 'actor') || ($Hub['type'] == 'director')) {
						$nameLocation = 'tag';
						write_log("Found search results for a actor/director.");
					}

					foreach($Hub->children() as $Element) {

						$elementTitle = strtolower((string)$Element[$nameLocation]);
						$titleArray = explode(" ",$elementTitle);
						$yearString = "(".$Element['year'].")";
						$year = $Element['year'];
						$titleOut = cleanCommandString($elementTitle);

						if ($titleOut == $title) {
							write_log("Title matches exactly: ".$title);

							if (($Hub['type'] == 'actor') || ($Hub['type'] == 'director')) {
								$searchType = 'by cast';
								$cast = true;
							}
							if ($Hub['type'] == 'genre') {
								$genre = true;
								$searchType = 'by genre';
								unset($exactResult);
								foreach($Hub->children() as $dir) {
									$result = fetchRandomMediaByKey($dir['key']);
									array_push($exactResults,$result);
								}
							}
							if (($Hub['type'] == 'show') || ($Hub['type'] == 'movie') || ($Hub['type'] == 'episode')) {
								if ($type) {
									if ($Hub['type'] == $type) {
										array_push($exactResults,$Element);
									}
								} else {
									array_push($exactResults,$Element);
								}
							}
						} else {
							$weight = similarity($title, $titleOut);
							write_log("Weight of ".$title . " vs " . $titleOut . " is ".$weight);
							if ($weight >= .36) {
								write_log("Heavy enough, pushing.");
								array_push($fuzzyResults,$Element);
							}
							array_push($castResults,$Element);
						}
					}
				}
			}
			if ((count($exactResults)) && (!($cast)) && (!($genre))) {
				write_log("Exact results found.");
				$exact = true;
				$finalResults = $exactResults;
			} else {
				write_log("Fuzzy results found.");
				$exact = false;
				$finalResults = array_unique($fuzzyResults);
			}

			if ($genre) {
				write_log("Detected override for ".($cast ? 'cast' : 'genre').".");
				$size = sizeof($exactResults)-1;
				$random = rand(0,$size);
				$winner = array($exactResults[$random]);
				write_log("Result from ".($cast ? 'cast' : 'genre'). " search is ".print_r($winner,true));
				unset($finalResults);
				$finalResults=$winner;
			}

			if ($cast) {
				write_log("Detected override for ".($cast ? 'cast' : 'genre').".");
				$size = sizeof($castResults)-1;
				$random = rand(0,$size);
				$winner = array($castResults[$random]);
				write_log("Result from ".($cast ? 'cast' : 'genre'). " search is ".print_r($winner,true));
				unset($finalResults);
				$finalResults=$winner;
			}



			$Returns = false;

			write_log("We have ".count($finalResults)." results.");
			// Need to check the type of each result object, make sure that we return a media result for each type
			$Returns = array();
			$count = 0;
			foreach($finalResults as $Result) {
				if (! isset($Result['title'])) {
					write_log("Hey, this doesn't have a title, what is it?  ".json_encode($Result));
				}
				write_log("This result is called ".$Result['title']. " or maybe ".$Result['tag']);
				$Result['@attributes']['exact'] = $exact;
				$Result['searchType'] = $searchType;
				array_push($Returns,$Result);
			}
			return $Returns;

		}
		return false;
	}

	function fetchHubList($section,$type=null) {

		$baseUrl = $_SESSION['uri_plexserver'];
		if ($section == 'recent') {
			write_log("Looking for recents");
			if ($type == 'show') {
				$url = $baseUrl . '/hubs/home/recentlyAdded?type=2';
			}
			if ($type == 'movie') {
				$url = $baseUrl . '/hubs/home/recentlyAdded?type=1';
			}

		}
		if ($section == 'ondeck') {
			write_log("Fetching on-deck list");
			$url = $baseUrl . '/hubs/home/recentlyAdded?type=1';
		}
		if ($url) {
			$url = $url."&X-Plex-Token=".$_SESSION['token_plexserver']."&X-Plex-Container-Start=0&X-Plex-Container-Size=".$_SESSION['returnItems'];
			write_log("URL is ".$url);
			$result = curlGet($url);
			write_log("Result: ".$result);
			if ($result) $container = new SimpleXMLElement($result);
			if ($container) {
				$results = array();
				foreach ($container->Video as $video) array_push($results, $video);
				write_log("We got a container and stuff: ".json_encode($results));
				if (!(empty($results))) return json_encode($results);
			}
		}
		return false;
	}

	// Build a list of genres available to our user
	// Need to determine if this list is static, or changes depending on the collection
	// If static, MAKE IT A STATIC LIST AND SAVE THE CALLS
	function fetchAvailableGenres() {
		write_log("Function Fired.");
		$sectionsUrl = $_SESSION['uri_plexserver'].'/library/sections?X-Plex-Token='.$_SESSION['token_plexserver'];
		write_log($sectionsUrl);
		$genres = array();
		$result = curlGet($sectionsUrl);
		if ($result) {
			$container = new SimpleXMLElement($result);
			foreach($container->children() as $section) {
				$url = $_SESSION['uri_plexserver'].'/library/sections/'.$section->Location['id'].'/genre'.'?X-Plex-Token=' . $_SESSION['token_plexserver'];
				write_log("GenreSection url: ".$url);
				$result = curlGet($url);
				if ($result) {
					$container = new SimpleXMLElement($result);
					foreach($container->Directory as $genre) {
						$genres[strtolower($genre['fastKey'])] = $genre['title'];
					}
				}
			}
			if (count($genres)) {
				return $genres;
			}
		}
		return false;
	}


	// We should pass something here that will be a directory of shows or movies
	function fetchRandomMediaByKey($key) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$url = $_SESSION['uri_plexserver'].$key.'&limit=30&X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log('URL is : '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				array_push($matches,$video);
			}
			$size = sizeof($matches);
			write_log("Resulting array size is ".$size);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $matches[$winner];
				write_log("We got a winner!  Out of ".$size ."  choices, we found  ". $winner['title'] . " and key is " . $winner['key'] . $size);
				if ($winner['type'] == 'show') {
					$winner = fetchFirstUnwatchedEpisode($winner['key']);
				}
				return $winner;
			}
		}
		return false;
	}


	function fetchRandomNewMedia($type) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$url = $_SESSION['uri_plexserver'].'/library/recentlyAdded'.'?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log("FetchRandomNew url for ".$type." is ".$url);
		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				if ($video['type'] == $type) {
					array_push($matches,$video);
				}
				if (($video['type'] == 'season') && ($type == 'show')) {
					array_push($matches,$video);
				}
			}
			write_log("I Got me sum matches!!: ".json_encode($matches));
			$size = sizeof($matches);
				if ($size > 0) {
					$winner = rand(0,$size);
					$winner = $matches[$winner];
					write_log("We got a winner!  Out of ".$size ."  choices, we found  ". ($type=='movie' ? $winner['title']:$winner['parentTitle']) . " and key is " . $winner['key'] . $size);
					if ($winner['type'] == 'season') {
						$result = fetchFirstUnwatchedEpisode($winner['parentKey'].'/children');
						write_log("I am going to play an episode named ".$result[title]);
						return array($result);
					}
					return array($winner);
				} else {
					write_log("Can't seem to find any random " . $type);
				}
		}
		return false;


	}


	// TV Functions


	function fetchFirstUnwatchedEpisode($key) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$mediaDir = preg_replace('/children$/', 'allLeaves', $key);
		$url = $_SESSION['uri_plexserver'].$mediaDir. '?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log("Searching for first unwatched episode of " . $showTitle . ' at url '. $url);

		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$lastVideo = '';
			foreach ($container->children() as $video)
			{
				if ($video['viewCount']== 0) {
					$video['art']=$container['art'];
					return $video;
				}
			}
			// If no unwatched episodes, return the first episode
			return $container->Video[0];
		}
		return false;
	}


	// We assume that people want to watch the latest unwatched episode of a show
	// If there are no unwatched, we'll play the newest one
	function fetchLatestEpisode($key) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$mediaDir = preg_replace('/children$/', 'allLeaves', $key);
		$url = $_SESSION['uri_plexserver'].$mediaDir.'?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log('URL is: '.protectURL($url));
		$result = curlGet($url);
		write_log("fetchlatest: Result string is ".$result);
		if ($result) {
			$container = new SimpleXMLElement($result);
			foreach($container->Video as $episode) {
				$last = $episode;
			}
			return $last;
		}
		return false;
	}


	function fetchRandomEpisode($showKey) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$mediaDir = preg_replace('/children/', 'allLeaves', $showKey);
		$url = $_SESSION['uri_plexserver'].$mediaDir.'?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log('URL is: '.protectURL($url));
		$results = curlGet($url);
		if ($results) {
			$container = new SimpleXMLElement($results);
			$size=sizeof($container->Video);
			$winner = rand(0,$size);
			$contArray = json_decode(json_encode($container),true);
			$parentArt = (string)$contArray['@attributes']['art'];
			$result = $container->Video[$winner];
			$result = json_decode(json_encode($result),true);
			$result['@attributes']['art'] = $parentArt;
			$resultz = array();
			$resultz = $result['@attributes'];
			$resultz['@attributes'] = $result['@attributes'];
			return $resultz;
		}
		return false;
	}


	function fetchNumberedTVItem($seriesKey, $num, $selector, $epNum=null) {
		write_log("Function Fired.");
		write_log("Searching for ".$selector." number ". $num . ($epNum != null ? ' and episode number ' . $epNum : ''));
		$mediaDir = preg_replace('/children$/', 'allLeaves', $seriesKey);
		$url = $_SESSION['uri_plexserver'].$mediaDir. '?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log('URL is: '.protectURL($url));
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			// If we're specifying a season, get all those episodes who's ParentIndex matches the season number specified
			$matches = array();
			if ($selector == "season") {
				foreach($container as $episode) {
					if ($epNum) {
						if (($episode['parentIndex'] == $num) && ($episode['index'] == $epNum)) {
							$episode['art']=$container['art'];
							return $episode;
						}
					} else {
						if ($episode['parentIndex'] == $num) {
							write_log("Searching for a Season");
							$episode['index'] = $episode['parentIndex'];
							$episode['thumb']=$episode['parentThumb'];
							$episode['art']=$container['art'];
							return $episode;

						}
					}
				}
			} else {
				$episode = $container->Video[intval($num)-1];
				$count = $container->count();
				$episode['art']=$container['art'];
				return $episode;
			}
		}
		return false;
	}


	// Movie Functions
	function fetchRandomMovieByYear($year) {
		write_log("Function Fired.");
		write_log("Someone wants a movie from the year " . $year);
		return $year;
	}


	function fetchRandomMediaByGenre($fastKey,$type=false) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['token_plexserver'];
		$sectionsUrl = $_SESSION['uri_plexserver'].$fastKey.'&X-Plex-Token='.$serverToken;
		write_log("Url is ". protectURL($sectionsUrl) . " type search is ".$type);
		$sectionsResult = curlGet($sectionsUrl);
		if ($sectionsResult) {
			$container = new SimpleXMLElement($sectionsResult);
			$winners = array();
			foreach ($container->children() as $directory) {
				if (($directory['type']=='movie') && ($type != 'show')) {
					write_log("fetchRandomMediaByGenre: Pushing  ". $directory['title']);
					array_push($winners,$directory);
				}
				if (($directory['type']=='show') && ($type != 'movie')) {
					$media = fetchLatestEpisode($directory['title']);
					write_log("Pushing  ". $directory['title']);
					if ($media) array_push($winners,$media);
				}
			}
			$size = sizeof($winners);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $winners[$winner];
				write_log("WE GOT A WINNER!! ". $winner['title']);
				return $winner;
			}
		}
		return false;
	}


	function fetchRandomMediaByCast($actor,$type='movie') {
		write_log("Function Fired.");
		$serverToken = $_SESSION['token_plexserver'];
		$sectionsUrl = $_SESSION['uri_plexserver'].'/library/sections?X-Plex-Token='.$serverToken;
		$sectionsResult = curlGet($sectionsUrl);
		$actorKey = false;
		if ($sectionsResult) {
			$container = new SimpleXMLElement($sectionsResult);
			foreach ($container->children() as $directory) {
				write_log("Directory type ". $directory['type']);
				if ($directory['type']==$type) {
					$section = $directory->Location[id];
				}
			}
		} else {
			write_log("Unable to list sections");
			return false;
		}
		$url = $_SESSION['uri_plexserver']. '/library/sections/'.$section.'/actor'. '?X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log("Actorsections url: " .$url);
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			write_log("Trying to find an actor named ".ucwords(trim($actor)));
			foreach ($container->children() as $actors) {
				if ($actors['title'] == ucwords(trim($actor))) {
					$actorKey = $actors['fastKey'];
					write_log("Actor found: ". $actors['title']);
				}
			}
			if (!($actorKey)) {
				write_log("No actor key found, I should be done now.");
				return false;
			}
		} else {
			write_log("No result found, I should be done now.");
			return false;
		}

		$url = $_SESSION['uri_plexserver']. $actorKey. '&X-Plex-Token=' . $_SESSION['token_plexserver'];
		write_log("I have an actor key, and now a URL: ". $url);

		$result = curlGet($url);
		if ($result) {
			$matches = array();
			$container = new SimpleXMLElement($result);
			foreach ($container->children() as $video) {
				array_push($matches,$video);
			}
			$size = sizeof($matches);
			if ($size > 0) {
				$winner = rand(0,$size);
				$winner = $matches[$winner];
				write_log("WE GOT A WINNER!! ". $winner['title']);
				return $winner;
			}
		}
		return false;
	}


	// Send some stuff to a play queue
	function queueMedia($media) {
		write_log("Function Fired.");
		write_log("Media array: ".json_encode($media));
		$key = $media['key'];
		$url = $_SESSION['uri_plexserver'].$key.'?X-Plex-Token='.$_SESSION['plexToken'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$media = $container;
		}
		write_log("Media Section UUID is " .$media['librarySectionUUID']);
		write_log("Media key is " .$key);
		$key = urlencode($key);
		$uri = 'library://'.$media['librarySectionUUID'].'/item/'.$key;
		write_log("Media URI is " .$uri);
		$uri = urlencode($uri);
		$_SESSION['serverHeader'] = '&X-Plex-Client-Identifier='.$_SESSION['id_plexclient'].
		'&X-Plex-Token='.$_SESSION['plex_token'];
		write_log("Encoded media URI is " .$uri);
		$url = $_SESSION['uri_plexserver'].'/playQueues?type=video'.
		'&uri='.$uri.
		'&shuffle=0&repeat=0&includeChapters=1&continuous=1'.
		$_SESSION['plexHeader'];
		write_log("QueueMedia Url: ".$url);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
		$headers = array(
			'X-Plex-Client-Identifier:'.$_SESSION['deviceID'],
			'X-Plex-Device:PhlexWeb',
			'X-Plex-Device-Name:Phlex',
			'X-Plex-Device-Screen-Resolution:1520x707,1680x1050,1920x1080',
			'X-Plex-Platform:Web',
			'X-Plex-Platform-Version:1.0.0',
			'X-Plex-Product:Phlex',
			'X-Plex-Target-Client-Identifier:'.$_SESSION['id_plexclient'],
			'X-Plex-Version:1.0.0'
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($ch);
		curl_close ($ch);
		//$result = curlGet($url);
		if ($result) {
			write_log("Result contains: ".$result);
			$container = new SimpleXMLElement($result);
			$container = json_decode(json_encode($container),true);
			write_log("Have some JSON ".json_encode($container));
			$queueID = (string)$container['@attributes']['playQueueID'];
			write_log("Holy fucking fucking fuck, I got an ID of ".$queueID);
			return $queueID;

		}
		return false;

	}


	function playMedia($media) {
		if (isset($media['key'])) {
			$clientProduct = $_SESSION['product_plexclient'];
			switch ($clientProduct) {
				case 'cast':
					$result = playMediaCast($media);
					break;
				case 'Plex for Android':
					$result = playMediaDirect($media);
					break;
				case 'Plex Media Player':
				case 'Plex Web':
				case 'Plex TV':
				default:
					$result = playMediaQueued($media);
					break;
			}
			return $result;
		} else {
			write_log("No media to play!!","E");
			$result['status'] = 'error';
			return $result;
		}
	}


	function playMediaDirect($media) {
		write_log("Function Fired.");
		$serverToken = $_SESSION['plex_token'];
		$serverID = $_SESSION['id_plexserver'];
		$client = $_SESSION['uri_plexclient'];
		$server = parse_url($_SESSION['uri_plexserver']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$transientToken = fetchTransientToken();
		$playUrl = $client.'/player/playback/playMedia'.
		'?key='.urlencode($media['key']) .
		'&offset='.($media['viewOffset']?$media['viewOffset']:0).
		'&machineIdentifier=' .$serverID.
		'&protocol='.$serverProtocol.
		'&address=' .$serverIP.
		'&port=' .$serverPort.
		'&path='.urlencode($_SESSION['uri_plexserver'].'/'.$media['key']).
		'&X-Plex-Target-Client-Identifier='.$_SESSION['id_plexclient'].
		'&token=' .$transientToken;
		$status = playerCommand($playUrl);
		write_log('Playback URL is ' . protectURL($playUrl));
		$result['url'] = $playUrl;
		$result['status'] = $status['status'];
		return $result;
	}


	function playMediaQueued($media) {
		write_log("Function Fired.");
		$server = parse_url($_SESSION['uri_plexserver']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$serverToken = $_SESSION['plex_token'];
		$serverID = $_SESSION['id_plexserver'];
		$deviceID = $_SESSION['deviceID'];
		$client = $_SESSION['uri_plexclient'];
		$queueID = queueMedia($media);
		$transientToken = fetchTransientToken();
		$_SESSION['counter']++;
		$headers = array(
			'X-Plex-Client-Identifier:'.$_SESSION['deviceID'],
			'X-Plex-Target-Client-Identifier:'.$_SESSION['id_plexclient'],
			'X-Plex-Device:PhlexWeb',
			'X-Plex-Device-Name:Phlex',
			'X-Plex-Device-Screen-Resolution:1520x707,1680x1050,1920x1080',
			'X-Plex-Platform:Web',
			'X-Plex-Platform-Version:1.0.0',
			'X-Plex-Product:Phlex',
			'X-Plex-Version:1.0.0'
		);
		write_log("Current command ID is " . $_SESSION['counter']);
		write_log("Queue Token is ".$queueID);
		$playUrl = $_SESSION['uri_plexclient'].'/player/playback/playMedia'.
		'?key='.urlencode($media['key']) .
		'&offset='.($media['viewOffset']?$media['viewOffset']:0).
		'&machineIdentifier=' .$serverID.
		'&protocol='.$serverProtocol.
		'&address=' .$serverIP.
		'&port=' .$serverPort.
		'&containerKey=%2FplayQueues%2F'.$queueID.'%3Fown%3D1%26window%3D200'.
		'&token=' .$transientToken.
		'&commandID='.$_SESSION['counter'];
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $playUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_NOBODY => true,
			CURLOPT_VERBOSE => true,
			CURLOPT_HTTPHEADER => $headers
		));
		$result = curl_exec($ch);
		curl_close ($ch);
		write_log('Playback URL is ' . protectURL($playUrl));
		write_log("Result value is ".$result);
		$status = ((strpos($result,'HTTP/1.1 200 OK')!==false)?'success':'error');
		write_log("Result is ".$status);
		$return['url'] = $playUrl;
		$return['status'] = $status;
		return $return;

	}

	function playMediaCast($media) {
		write_log("Function fired.");

		//Set up our variables like a good boy
		$key = $media['key'];
		$machineIdentifier = $_SESSION['deviceID'];
		$transientToken = fetchTransientToken();
		$server = parse_url($_SESSION['uri_plexserver']);
		$serverProtocol = $server['scheme'];
		$serverIP = $server['host'];
		$serverPort =$server['port'];
		$userName = $_SESSION['username'];
		$queueID = queueMedia($media);


		// Set up our cast device
		$addresses = explode(":",$_SESSION['uri_plexclient']);
		$client = parse_url($_SESSION['uri_plexclient']);
		$cc = new Chromecast($client['host'],$client['port']);
		// Launch the Plex cast app
		// Is there some way to test if app is already loaded, or check a status before firing this?
		$cc->launch("9AC194DC");
		$status = $cc->getStatus;
		write_log("Launch response: ".$status);
		// Connect to the Application
		$cc->connect();
		$status = $cc->getCastMessage;
		write_log("Connect response: ".$status);
		write_log("Sleeping to make sure plex is up and running (not necessary in real app)");
		sleep(5);

		// Build JSON
		$result = [
			'type' => 'LOAD',
			'requestId' => $cc->requestId,
			'media' => [
				'contentId' => (string)$key,
				'streamType' => 'BUFFERED',
				'contentType' => 'video',
				'customData' => [
					'offset' => (array_key_exists('viewOffset',$media) ? $media['viewOffset']:0),
					'directPlay' => true,
					'directStream' => true,
					'subtitleSize' => 100,
					'audioBoost' => 100,
					'server' => [
						'machineIdentifier' => $machineIdentifier,
						'transcoderVideo' => true,
						'transcoderVideoRemuxOnly' => false,
						'transcoderAudio' => true,
						'version' => '1.4.3.3433',
						'myPlexSubscription' => true,
						'isVerifiedHostname' => true,
						'protocol' => $serverProtocol,
						'address' => $serverIP,
						'port' => $serverPort,
						'accessToken' => $transientToken,
						'user' => [
							'username' => $userName,
						],
						'containerKey' => $queueID . '?own=1&window=200',
					],
					'autoplay' => true,
					'currentTime' => 0,
				]
			]
		];

		$json = json_encode($result);
		$status = $cc->sendMessage("urn:x-cast:com.google.cast.media", $json);
		write_log("Play response: ".$status);
		sleep(2);
		$cc->sendMessage("urn:x-cast:plex",'{"type":"PLAY"}');
		sleep(2);
		$status = $cc->getCastMessage;
		write_log("Post-Play response: ".$status);

		$return['url'] = 'chromecast://'.$client['host'].':'.$client['port'];
		$return['status'] = 'success';
		return $return;

	}

	function castStatus($wait=0) {
		$addresses = parse_url($_SESSION['uri_plexclient']);
		$url = $_SESSION['uri_plexserver'].'/status/sessions/?X-Plex-Token='.$_SESSION['token_plexserver'];
		$result = curlGet($url);
		if ($result) {
			$container = new SimpleXMLElement($result);
			$container2 = json_decode(json_encode($container),true);
			$status = array();
			foreach ($container->Video as $Video) {
				$vidArray = json_decode(json_encode($Video),true);
				$isCast = ($vidArray['Player']['@attributes']['address'] == $addresses['host']);
				$isPlayer = ($vidArray['Player']['@attributes']['machineIdentifier'] == $_SESSION['id_plexclient']);
				if (($isPlayer) || ($isCast)) {
					$status['status'] = $vidArray['Player']['@attributes']['state'];
					$time=$vidArray['TranscodeSession']['@attributes']['progress'];
					$duration = $vidArray['TranscodeSession']['@attributes']['duration'];
					$status['time'] = $duration / $time;
					$status['plexServer']=$_SESSION['uri_plexserver'];
					$status['mediaResult'] = $vidArray;
					$thumb = (($vidArray['@attributes']['type'] == 'movie') ? $vidArray['@attributes']['thumb'] : $vidArray['@attributes']['parentThumb']);
					$art = $vidArray['@attributes']['art'];
					$thumb = $_SESSION['uri_plexserver'].$thumb."?X-Plex-Token=".$_SESSION['token_plexserver'];
					$art = $_SESSION['uri_plexserver'].$art."?X-Plex-Token=".$_SESSION['token_plexserver'];
					$thumb = cacheImage($thumb);
					$art = cacheImage($art);
					$status['mediaResult']['thumb'] = $thumb;
					$status['mediaResult']['art'] = $art;
					$status['mediaResult']['@attributes']['thumb'] = $thumb;
					$status['mediaResult']['@attributes']['art'] = $art;
				}
			}
		} else {
			$status['status'] = 'error';
		}
		$status = json_encode($status);
		return $status;
	}

	function playerStatus($wait=0) {
		if ($_SESSION['product_plexclient'] == 'cast') {
			return castStatus();
		} else {
			$url = $_SESSION['uri_plexclient'].
			'/player/timeline/poll?wait='.$wait.'&commandID='.$_SESSION['counter'];
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
			curl_setopt($ch, CURLOPT_TIMEOUT, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'X-Plex-Client-Identifier:'.$_SESSION['deviceID'],
				'X-Plex-Device:PhlexWeb',
				'X-Plex-Device-Name:Phlex',
				'X-Plex-Device-Screen-Resolution:1520x707,1680x1050,1920x1080',
				'X-Plex-Platform:Web',
				'X-Plex-Platform-Version:1.0.0',
				'X-Plex-Product:Phlex',
				'X-Plex-Target-Client-Identifier:'.$_SESSION['id_plexclient'],
				'X-Plex-Version:1.0.0'
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$results = curl_exec($ch);
			curl_close ($ch);
			$status = array();
			if ($results) {
				$container = new SimpleXMLElement($results);
				if (count($container)>=1) {
					$status['status'] = 'stopped';
					foreach($container->Timeline as $Timeline) {
						if($Timeline['key']) {
							$mediaURL = $_SESSION['uri_plexserver'].$Timeline['key'].
							'?checkFiles=1&includeChapters=1'.
							'&X-Plex-Token='.$_SESSION['token_plexserver'];
							$media = curlGet($mediaURL);
							if ($media) {
								$mediaContainer = new SimpleXMLElement($media);
								$MC = json_decode(json_encode($mediaContainer),true);
								$thumb = (($MC['Video']['@attributes']['type'] == 'movie') ? $MC['Video']['@attributes']['thumb'] : $MC['Video']['@attributes']['parentThumb']);
								$art = $MC['Video']['@attributes']['art'];
								$status['status'] = (string)$Timeline['state'];
								$status['volume'] = (string)$Timeline['volume'];
								$status['mediaResult'] = $mediaContainer->Video;
								if ($Timeline['time']) {
									$status['time']=(string)$Timeline['time'];
									$status['plexServer']=$_SESSION['uri_plexserver'];
								}
							}
						}
					}
				}
			}
		}
		if ($mediaContainer) {

			$thumb = $_SESSION['uri_plexserver'].$thumb."?X-Plex-Token=".$_SESSION['token_plexserver'];
			$art = $_SESSION['uri_plexserver'].$art."?X-Plex-Token=".$_SESSION['token_plexserver'];

			$thumb = cacheImage($thumb);
			$art = cacheImage($art);
			$status['mediaResult']['thumb'] = $thumb;
			$status['mediaResult']['art'] = $art;
			$status['mediaResult']['@attributes']['thumb'] = $thumb;
			$status['mediaResult']['@attributes']['art'] = $art;

		} else {
			$status['status'] = 'error';
		}
		$status = json_encode($status);
		return $status;
	}

	function sendCommand($cmd) {
			write_log("Function fired!");
			$clientProduct = $_SESSION['product_plexclient'];
			switch ($clientProduct) {
				case 'cast':
					$result = castCommand($cmd);
					break;
				default:
					$url = $client.'/player/playback/'. $cmd . ((strstr($cmd, '?')) ? "&" : "?").'X-Plex-Token=' .$_SESSION['plex_token'];
					$result = playerCommand($url);
					break;
			}
			write_log("Result is ".print_r($result,true));
			return $result;
	}


	function playerCommand($url) {
		if (!(preg_match('/http/',$url))) $url = $_SESSION['uri_plexclient'].$url;
		$status = 'success';
		write_log("Function Fired.");
		$_SESSION['counter']++;
		write_log("Current command ID is " . $_SESSION['counter']);
		$url .='&commandID='.$_SESSION['counter'];
		$ch = curl_init();
		$clientID = $_SESSION['id_plexclient'];
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
		$headers = array(
			'X-Plex-Client-Identifier:'.$_SESSION['deviceID'],
			'X-Plex-Device:PhlexWeb',
			'X-Plex-Device-Name:Phlex',
			'X-Plex-Device-Screen-Resolution:1520x707,1680x1050,1920x1080',
			'X-Plex-Platform:Web',
			'X-Plex-Platform-Version:1.0.0',
			'X-Plex-Product:Phlex',
			'X-Plex-Target-Client-Identifier:'.$_SESSION['id_plexclient'],
			'X-Plex-Version:1.0.0'
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$container = curl_exec($ch);
		write_log("Command response is ".$container);
		if (curl_errno($ch)) {
			// this would be your first hint that something went wrong
			write_log("CURL Error while sending command. " + curl_error($ch));
		} else {
			// check the HTTP status code of the request
			$resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($resultStatus != 200) {
				write_log('Request failed, HTTP status code: ' . $status);
				$status = 'error';
			} else {
				$status = 'success';
			}
		}
		curl_close ($ch);
		write_log('URL is: '.protectURL($url));
		write_log("Status is " . $status);
		$return['url'] = $url;
		$return['status'] = $status;
		return $return;

	}

	function castCommand($cmd) {
		// Set up our cast device
		if (preg_match("/volume/s", $cmd)) {
			$int = filter_var($cmd, FILTER_SANITIZE_NUMBER_INT);
			$cmd = "volume";
		}
		$client = parse_url($_SESSION['uri_plexclient']);
		$cc = new Chromecast($client['host'],$client['port']);

		// Connect to the Application
		if ($cmd != 'volume') {
			$cc->cc_connect();
			$cc->getStatus();
			$cc->connect();
			$cc->getStatus();
		}
		$valid = true;
		switch ($cmd) {
			case "play":
				$status= $cc->sendMessage("urn:x-cast:plex",'{"type":"PLAY"}');
				break;
			case "pause":
				$status = $cc->sendMessage("urn:x-cast:plex",'{"type":"PAUSE"}');
				break;
			case "stepForward":
				$status = $cc->sendMessage("urn:x-cast:plex",'{"type":"STEPFORWARD"}');
				break;
			case "stop":
				$status = $cc->sendMessage("urn:x-cast:plex",'{"type":"STOP"}');
				break;
			case "skipBack":
				$status = $cc->sendMessage("urn:x-cast:plex",'{"type":"PREVIOUS"}');
				break;
			case "skipForward":
				$status = $cc->sendMessage("urn:x-cast:plex",'{"type":"NEXT"}');
				break;
			case "volume":
				write_log("Should be a volume command.");
				$status = $cc->DMP->SetVolume($int);
				break;
			default:
				$return['status'] = 'error';
				$valid = false;

		}

		if ($valid) {
			write_log("Command response: ".$status);
			$return['url'] = "No URL";
			$return['status'] = 'success';
			return $return;
		}
		$return['status'] = 'error';
		return $return;
	}

	//
	// Utility functions that should probably be in a separate file
	//


	// Check if we have a running session before trying to start one
	function is_session_started() {
		if ( php_sapi_name() !== 'cli' ) {
			if ( version_compare(phpversion(), '5.4.0', '>=') ) {
				return session_status() === PHP_SESSION_ACTIVE ? TRUE : FALSE;
			} else {
				return session_id() === '' ? FALSE : TRUE;
			}
		}
		return FALSE;
	}

	function clientInSameSubnet($test_ip=false) {
		if (!$server_ip)
			$server_ip = $_SERVER['SERVER_ADDR'];
		// Extract broadcast and netmask from ifconfig
		if (!($p = popen("ifconfig","r"))) return false;
		$out = "";
		while(!feof($p))
			$out .= fread($p,1024);
		fclose($p);
		// This is because the php.net comment function does not
		// allow long lines.
		$match  = "/^.*".$server_ip;
		$match .= ".*Bcast:(\d{1,3}\.\d{1,3}i\.\d{1,3}\.\d{1,3}).*";
		$match .= "Mask:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/im";
		if (!preg_match($match,$out,$regs))
			return false;
		$bcast = ip2long($regs[1]);
		$smask = ip2long($regs[2]);
		$ipadr = ip2long($client_ip);
		$nmask = $bcast & $smask;
		return (($ipadr & $smask) == ($nmask & $smask));
	}









	// This should take our command objects and save them to the JSON file
	// read by the webUI.
	function log_command($resultObject) {
		// Decode our incoming command, append a timestamp.
		$newCommand = json_decode($resultObject,true);
		$artUrl = (string)$newCommand['mediaResult']['@attributes']['art'];
		$thumbUrl = (string)$newCommand['mediaResult']['@attributes']['thumb'];
		write_log("Art Path is ".$artUrl);
		if (preg_match('/library/',$artUrl)) {
			write_log("Logged command is from Plex, building cached URL.");
			$artUrl = $_SESSION['uri_plexserver'].$artUrl.'?X-Plex-Token='.$_SESSION['plexToken'];
			write_log("Full art URL is ".$artUrl);
			$artUrl = cacheImage($artUrl);
			unset($newCommand['mediaResult']['@attributes']['art']);
			write_log("New art URL should be ".$artUrl);
			$newCommand['mediaResult']['@attributes']['art'] = $artUrl;
		}
		write_log("Thumb path is ".$thumbUrl);
		$tmpString = explode(":",$thumbUrl);
		$thumbPaths = parse_url($thumbUrl);
		if (preg_match('/library/',$thumbUrl)) {
			write_log("Logged command is from Plex, building cached URL.");
			$thumbUrl = $_SESSION['uri_plexserver'].$thumbUrl.'?X-Plex-Token='.$_SESSION['plexToken'];
			$thumbUrl = cacheImage($thumbUrl);
			write_log("New thumb URL Should be ".$thumbUrl);
			unset($newCommand['mediaResult']['@attributes']['thumb']);
			$newCommand['mediaResult']['@attributes']['thumb'] = $thumbUrl;
		}

		// Check for our JSON file and make sure we can access it
		$filename = "commands.php";
		$handle = fopen($filename, "r");
		//Read first line, but do nothing with it
		$foo = fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}

		// Read contents into an array
		$json_a = array();
		$jsondata = $contents;
		$json_a = json_decode($jsondata);
		if (empty($json_a)) $json_a = array();

		// Append our newest command to the beginning
		array_unshift($json_a,$newCommand);

		// If we have more than 10 commands, remove one.
		if (count($json_a) >= 11) {
			array_pop($json_a);
		}

		// Triple-check we can write, write JSON to file
		if (!$handle = fopen($filename, 'wa+')) die;
		$cache_new = "'; <?php die('Access denied'); ?>";
		$cache_new .= json_encode($json_a, JSON_PRETTY_PRINT);
		if (fwrite($handle, $cache_new) === FALSE) die;
		fclose($handle);
		refreshDevices('clients');
		return $json_a;
	}

	function sendLog() {
		write_log("Sending Log File to Mothership");
		$zip = new ZipArchive();
		$destination = dirname(__FILE__) . '/Logs.zip';
		$overwrite = false;
		if($zip->open($destination,$overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
			write_log("Error opening zip file, cancelled.");
			return false;
		}
		write_log("Adding files");
		$zip->addFile('Phlex.log','Phlex.log');
		$zip->addFile('Phlex_error.log','Phlex_error.log');
		$zip->close();
		if (file_exists($destination)) {
			write_log("File exists, trying to send.");
			$request = curl_init('https://phlexserver.cookiehigh.us/api.php');
			curl_setopt($request, CURLOPT_POST, true);
			curl_setopt(
				$request,
				CURLOPT_POSTFIELDS,
				array(
				  'file' => '@' . realpath($destination). ';filename='.$_SESSION['apiToken'].'.zip',
				  'apiToken' => $_SESSION['apiToken']
				));
			curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
			$result = curl_exec($request);
			curl_close($request);

		}
		unset($destination);
	}

	// Write and save some data to the webUI for us to parse
	// IDK If we need this anymore
	function metaTags() {
		$tags = '';
		$filename = "commands.php";
		$handle = fopen($filename, "r");
		//Read first line, but do nothing with it
		$foo = fgets($handle);
		$contents = '[';
		//now read the rest of the file line by line, and explode data
		while (!feof($handle)) {
			$contents .= fgets($handle);
		}
		$dvr = ($_SESSION['uri_plexdvr'] ? "true" : "");
		if ($contents == '[') $contents = '';
		$commandData = urlencode($contents);
		$tags .= '<meta id="tokenData" data="'.$_SESSION['token_plexserver'].'"></meta>'.
				'<meta id="usernameData" data="'.$_SESSION['username'].'"></meta>'.
				'<meta id="publicIP" data="'.$_SESSION['publicAddress'].'"></meta>'.
				'<meta id="deviceID" data="'.$_SESSION['deviceID'].'"></meta>'.
				'<meta id="serverURI" data="'.$_SESSION['uri_plexserver'].'"></meta>'.
				'<meta id="clientURI" data="'.$_SESSION['uri_plexclient'].'"></meta>'.
				'<meta id="clientName" data="'.$_SESSION['name_plexclient'].'"></meta>'.
				'<meta id="plexdvr" enable="'.$dvr.'" uri="'.$_SESSION['uri_plexdvr'].'"></meta>'.
				'<meta id="rez" value="'.$_SESSION['resolution'].'"></meta>'.
				'<meta id="couchpotato" enable="'.$_SESSION['enable_couch'].'" ip="'.$_SESSION['ip_couch'].'" port="'.$_SESSION['port_couch'].'" auth="'.$_SESSION['auth_couch'].'"></meta>'.
				'<meta id="sonarr" enable="'.$_SESSION['enable_sonarr'].'" ip="'.$_SESSION['ip_sonarr'].'" port="'.$_SESSION['port_sonarr'].'" auth="'.$_SESSION['auth_sonarr'].'"></meta>'.
				'<meta id="sick" enable="'.$_SESSION['enable_sick'].'" ip="'.$_SESSION['ip_sick'].'" port="'.$_SESSION['port_sick'].'" auth="'.$_SESSION['auth_sick'].'"></meta>'.
				'<meta id="radarr" enable="'.$_SESSION['enable_radarr'].'" ip="'.$_SESSION['ip_radarr'].'" port="'.$_SESSION['port_radarr'].'" auth="'.$_SESSION['auth_radarr'].'"></meta>'.
				'<meta id="ombi" enable="'.$_SESSION['enable_ombi'].'" ip="'.$_SESSION['ip_ombi'].'" port="'.$_SESSION['port_ombi'].'" auth="'.$_SESSION['auth_ombi'].'"></meta>'.
				'<meta id="logData" data="'.$commandData.'"></meta>';
		return $tags;
	}


	function downloadSeries($command,$season,$episode) {
		$enableSick = $_SESSION['enable_sick'];
		$enableSonarr = $_SESSION['enable_sonarr'];

		if ($enableSonarr == 'true') {
			write_log("Using Sonarr for Episode agent");
			$response = sonarrDownload($command);
			return $response;
		}

		if ($enableSick == 'true') {
			write_log("Using Sick for Episode agent");
			$response = sickDownload($command,$season,$episode);
			return $response;
		}
		return "No downloader";
	}

	function sickDownload($command,$season=false,$episode=false) {
		write_log("Function fired");
		$response = [];
		$sickURL = $_SESSION['ip_sick'];
		$sickApiKey = $_SESSION['auth_sick'];
		$sickPort = $_SESSION['port_sick'];
		$sick = new SickRage($sickURL.':'.$sickPort, $sickApiKey);
		$result = $sick->sbSearchTvdb($command);
		write_log($result);
		if ($result) {
			$responseJSON = json_decode($result, true);
			$highest = 0;
			foreach ($responseJSON['data']['results'] as $searchResult) {
				$resultName = (string) $searchResult['name'];
				$cleaned = cleanCommandString($resultName);
				write_log("Similarity between results is ".similarity($command, $cleaned) * 100);
				if ($cleaned == $command) {
					write_log("This is an exact match.");
					$result = $searchResult;
					$exact = true;
					break;
				} else {
					$score = similarity($command,$cleaned) * 100;
					if ($score > $highest) {
						write_log("This is the highest matched result so far.");
						$highest = $score;
						$result = $searchResult;
					}
				}
			}

			if ($result) {
				$resultName = (string) $result['name'];
				write_log("Got response for search: ".$resultName);
				$resultID = (string) $result['tvdbid'];
				$resultDate = (string) $result['first_aired'];
				$resultDate = explode("-",$resultDate);
				$resultYear = $resultDate[0];
			}

			unset($result);
			unset($responseJSON);
			$check = $sick->show($resultID);
			if ($check) {
				write_log("Check contains ".$check);
				$responseJSON = json_decode($check,true);
				write_log("Check result is ".$checkJSON['result']);
				$result = (string) $checkJSON['result'];
				$exists = (($result == 'failure') ? false : true);
			}

			if (! $exists) {
				write_log("Show not in list, adding.");
				$result = $sick->showAddNew($resultID);
				$responseJSON = json_decode($result,true);
				write_log('Fetch result: '.$result);
				$exists = (($responseJSON['result'] == 'success') ? true : false);
			}

			if ($exists) {
				if ($season) {
					if ($episode) {
						write_log("And an episode. ".$episode);
						$result = $sick->episodeSearch($resultID, $season, $episode);
						if ($result) {
							unset($responseJSON);
							write_log("Episode search worked, result is ".$result);
							$responseJSON = json_decode($result,true);
							$resultName = (string)$responseJSON['data']['name'];
							$resultYear = (string)$responseJSON['data']['airdate'];
							$resultYearArray = explode("-",$resultYear);
							$resultYear = $resultYearArray[0];
						}
					}
				}

				$response['status'] = ($responseJSON['message'] ? $responseJSON['message'] : $responseJSON['result']);
				$response['mediaResult']['@attributes']['title'] = $resultName;
				$response['mediaResult']['@attributes']['year'] = $resultYear;
				$artURL = $sickURL.':'.$sickPort.'/api/'.$sickApiKey.'/' . '?cmd=show.getbanner&tvdbid='.$resultID;
				$responseJSON['art'] = cacheImage($artURL);
				write_log("Art should be findable at ".$responseJSON['art']);
				$responseJSON['type'] = 'show';
				$response['mediaResult']['@attributes'] = $responseJSON;
				return $response;

			}
		} else {
			$response['status'] = 'no results';
			return $response;
		}
		return false;


	}
	// Fetch a series from Sonarr
	// Need to add a method to trigger it to search for all episodes, etc.
	function sonarrDownload($command,$season=false,$episode=false) {
		$sonarrURL = $_SESSION['ip_sonarr'];
		$sonarrApiKey = $_SESSION['auth_sonarr'];
		$sonarrPort = $_SESSION['port_sonarr'];
		$baseURL = $sonarrURL.':'.$sonarrPort.'/api';
		$searchString = '/series/lookup?term='.urlencode($command);
		$authString = '&apikey='.$sonarrApiKey;
		$searchURL = $baseURL.$searchString.$authString;
		$root = curlGet($baseURL.'/rootfolder?apikey='.$sonarrApiKey);
		if ($root) {
			$rootPathObj = json_decode($root,true);
			$rootObj = $rootPathObj[0];
			$rootPath = (string)$rootObj['path'];
			write_log("RootPath: ".$rootPath);
			write_log("Search URL is ".protectURL($searchURL));
		}
		$seriesCollectionURL = $baseURL.'/series?apikey='.$sonarrApiKey;
		$seriesCollection = curlGet($seriesCollectionURL);
		if ($seriesCollection) {
			$seriesJSON = json_decode($seriesCollection,true);
		}
		$result = curlGet($baseURL.$searchString.$authString);
		if ($result) {
			$resultJSONS = json_decode($result,true);
			if (!(empty($resultJSONS))) {
				$resultJSON = $resultJSONS[0];
				$aired = $resultJSON['firstAired'];
				$date = explode("-",$aired);
				$year = $date[0];
				write_log("Result JSON is ".json_encode($resultJSON));
				$putURL = $baseURL.'/series'.'?apikey='.$sonarrApiKey;
				write_log("sending result for fetching, URL is ".protectURL($putURL));
				$resultObject['title'] = (string)$resultJSON['title'];
				$resultObject['tvdbId'] = (string)$resultJSON['tvdbId'];
				$resultObject['qualityProfileId'] = ($_SESSION['profile_sonarr'] ? $_SESSION['profile_sonarr'] : 0);
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['images'] = $resultJSON['images'];
				$seasons = array();
				foreach ($resultJSON['seasons'] as $season) {
					$monitored = (($season['seasonNumber'] == 0) ? false : true);
					array_push($seasons,array(seasonNumber=>$season['seasonNumber'],monitored=>$monitored));
				}
				$resultObject['seasons'] = $seasons;
				$resultObject['monitored'] = true;
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['rootFolderPath'] = $rootPath;
				$resultObject['addOptions']['ignoreEpisodesWithFiles'] = false;
				$resultObject['addOptions']['searchForMissingEpisodes'] = true;
				$resultObject['addOptions']['ignoreEpisodesWithoutFiles'] = false;
				$exists = false;
				foreach($seriesJSON as $series) {
					if ($series['title'] == $resultObject['title']) {
						write_log("Results match: ".$resultObject['title']);
						$exists = true;
						$response['status'] = 'already in searcher';
						$response['mediaResult']['@attributes']['url'] = $url2;
						$resultObject['year'] = $year;
						$resultObject['summary'] = $resultJSON['overview'];
						$resultObject['type'] = 'show';
						$artUrl = $sonarrURL.':'.$sonarrPort.'/MediaCover/'. $series['id'] . '/fanart.jpg?apikey='.$sonarrApiKey;
						$resultObject['art'] = cacheImage($artUrl);
						$resultObject['thumb'] = cacheImage($artUrl);
						$response['mediaResult']['@attributes'] = $resultObject;
						$scanURL = $baseURL . "/command/SearchSeries?apikey=".$sonarrApiKey;
						$searchArray = [name=>"SearchSeries",seriesId=>$resultObject['tvdbId']];
						$foo = curlPost($scanURL,json_encode($searchArray),true);
						return $response;
					}
				}
				write_log("Made it to the next CURL");
				$content = json_encode($resultObject);
				write_log("Request content format: ".$content);
				$json_response = curlPost($putURL,$content,true);
				write_log("Add Command Successful!  Response is ".$json_response);
				$responseJSON = json_decode($json_response, true);
				if ($responseJSON) {
					$response['status'] = 'success';
					$response['mediaResult']['@attributes']['url'] = $url2;
					$artImage = $sonarrURL.':'.$sonarrPort.'/MediaCover/'. $responseJSON['id'] . '/fanart.jpg?apikey='.$sonarrApiKey;
					$artImage = cacheImage($artImage);
					$responseJSON['art'] = $artImage;
					$responseJSON['thumb'] = $artImage;
					$responseJSON['type'] = 'show';
					$responseJSON['year'] = $year;
					$seriesID = $responseJSON['id'];
					$response['mediaResult']['@attributes'] = $responseJSON;
					$scanURL = $baseURL.'/command'.'?apikey='.$sonarrApiKey;
					$fetchMe = array();
					$fetchMe['name'] = 'SeriesSearch';
					$fetchMe['seriesId'] = $seriesID;
					$curl = curl_init($scanURL);
					curl_setopt($curl, CURLOPT_HEADER, false);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
					curl_setopt($curl, CURLOPT_HTTPHEADER,
							array("Content-type: application/json"));
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fetchMe));
					$json_response = curl_exec($curl);
					$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

				} else {
					$response['status'] = 'no results';
				}
				return $response;
			}
		}
	}


	// Fetch a movie from CouchPotato
	function downloadMovie($command) {
		write_log("Function fired.");
		$enableOmbi = $_SESSION['enable_ombi'];
		$enableCouch = $_SESSION['enable_couch'];
		$enableRadarr = $_SESSION['enable_radarr'];
		if ($enableOmbi == 'true') {
			write_log("Using Ombi for Movie agent");
		}

		if ($enableCouch == 'true') {
			write_log("Using Couchpotoato for Movie agent");
			$response = couchDownload($command);
			return $response;
		}

		if ($enableRadarr == 'true') {
			write_log("Using Radarr for Movie agent");
			$response = radarrDownload($command);
			return $response;
		}
		return "No downloader";
	}

	function couchDownload($command) {
		$couchURL = $_SESSION['ip_couch'];
		$couchApikey = $_SESSION['auth_couch'];
		$couchPort = $_SESSION['port_couch'];
		$response = array();
		$response['initialCommand'] = $command;
		$response['parsedCommand'] = 'fetch the movie ' .$command;

		// Send our initial request to search the movie

		$url = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/movie.search/?q=" . urlencode($command);
		write_log("Sending request to " . $url);
		$result = curlGet($url);

		// Parse the response, look for IMDB ID

		$body = json_decode($result,true);
		write_log("body:" .$result);
		$imdbID = (string)$body['movies'][0]['imdb'];

		// Now take the IMDB ID and send it with the title to Couchpotato
		if ($imdbID) {
			$title = $body['movies'][0]['titles'][0];
			$year = $body['movies'][0]['year'];
			$art = $body['movies'][0]['images']['backdrop_original'][0];
			$art = cacheImage($art);
			write_log("Art URL should be ".$art);
			$plot = $body['movies'][0]['plot'];
			write_log("imdbID: " . $imdbID);
			$resultObject['title'] = $title;
			$resultObject['year'] = $year;
			$resultObject['art'] = $art;
			$resultObject['thumb'] = $art;
			$resultObject['summary'] = $plot;
			$resultObject['type'] = 'movie';
			$url2 = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/movie.add/?identifier=" . $imdbID . "&title=" . urlencode($command).($_SESSION['profile_couch'] ? '&profile_id='.$_SESSION['profile_couch'] : '');
			write_log("Sending add request to: " . $url2);
			$response2 = curlGet($url2);
			$response['status'] = 'success';
			$response['mediaResult']['@attributes'] = $resultObject;
			$response['mediaResult']['@attributes']['url'] = $url2;
			return $response;
		} else {
			$response['status'] = 'no results';
			return $response;
		}
	}

	function radarrDownload($command) {
		$radarrURL = $_SESSION['ip_radarr'];
		$radarrApiKey = $_SESSION['auth_radarr'];
		$radarrPort = $_SESSION['port_radarr'];
		$baseURL = $radarrURL.':'.$radarrPort.'/api';

		$searchString = '/movies/lookup?term='.urlencode($command);
		$authString = '&apikey='.$radarrApiKey;
		$searchURL = $baseURL.$searchString.$authString;
		//$defaultProfile =
		$root = curlGet($baseURL.'/rootfolder?apikey='.$radarrApiKey);
		if ($root) {
			$rootPathObj = json_decode($root,true);
			$rootObj = $rootPathObj[0];
			$rootPath = (string)$rootObj['path'];
			write_log("RootPath: ".$rootPath);
			write_log("Search URL is ".protectURL($searchURL));
		}

		$movieCollectionURL = $baseURL.'/movie?apikey='.$radarrApiKey;
		$movieCollection = curlGet($movieCollectionURL);
		if ($movieCollection) {
			//write_log("Collection data retrieved: ".$movieCollection);
			$movieJSON = json_decode($movieCollection,true);
		}

		$result = curlGet($baseURL.$searchString.$authString);
		if ($result) {
			//write_log("Result is ".$result);
			$resultJSONS = json_decode($result,true);
			if (!(empty($resultJSONS))) {
				$resultJSON = $resultJSONS[0];
				write_log("Result JSON: ".json_encode($resultJSON));
				$putURL = $baseURL.'/movie'.'?apikey='.$radarrApiKey;
				write_log("sending result for fetching, URL is ".protectURL($putURL));
				unset($resultObject);
				$resultObject['title'] = (string)$resultJSON['title'];
				$resultObject['year'] = (string)$resultJSON['year'];
				$resultObject['tmdbId'] = (string)$resultJSON['tmdbId'];
				//$resultObject['qualityProfileId'] = 0;
				$resultObject['profileId'] = ($_SESSION['profile_radarr'] ? $_SESSION['profile_radarr'] : 0);
				$resultObject['qualityProfileId'] = ($_SESSION['profile_radarr'] ? $_SESSION['profile_radarr'] : 0);
				//$resultObject['ProfileId'] = 1;
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['images'] = $resultJSON['images'];
				$resultObject['monitored'] = true;
				$resultObject['titleSlug'] = (string)$resultJSON['titleSlug'];
				$resultObject['rootFolderPath'] = $rootPath;
				$resultObject['addOptions']['ignoreEpisodesWithFiles'] = false;
				$resultObject['addOptions']['searchForMovie'] = true;
				$resultObject['addOptions']['ignoreEpisodesWithoutFiles'] = false;
				$exists = false;
				foreach($movieJSON as $movie) {
					if ($movie['title'] == $resultObject['title']) {
						write_log("Results match: ".$resultObject['title']);
						$exists = true;
						$response['status'] = 'already in searcher';
						$response['mediaResult']['@attributes']['url'] = $url2;
						$resultObject['year'] = $resultJSON['year'];
						$resultObject['summary'] = $resultJSON['overview'];
						$resultObject['type'] = 'movie';
						$artUrl = $radarrURL.':'.$radarrPort.'/api/MediaCover/'. $movie['id'] . '/banner.jpg?apikey='.$radarrApiKey;
						write_log("Art URL Should be ".$artUrl);
						$resultObject['art'] = cacheImage($artUrl);
						$response['mediaResult']['@attributes'] = $resultObject;
						return $response;
					}
				}
				write_log("Made it to the next CURL");
				$content = json_encode($resultObject);
				$curl = curl_init($putURL);
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
				curl_setopt($curl, CURLOPT_HTTPHEADER,
						array("Content-type: application/json"));
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
				$json_response = curl_exec($curl);
				$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

				/*if ( $status != 201 ) {
					write_log("Curl error. Status is ".$status . " and response is ".$json_response);
					die("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
				}*/
				curl_close($curl);
				write_log("Add Command Successful!  Response is ".$json_response);
				$responseJSON = json_decode($json_response, true);
				if ($responseJSON) {
					$response['status'] = 'success';
					$response['mediaResult']['@attributes']['url'] = $url2;
					$artUrl = $radarrURL.':'.$radarrPort.'/api/MediaCover/'. $responseJSON['id'] . '/banner.jpg?apikey='.$radarrApiKey;
					write_log("Art URL Should be ".$artUrl);
					$responseJSON['art'] = cacheImage($artUrl);
					$responseJSON['type'] = 'movie';
					$movieID = $responseJSON['id'];
					$response['mediaResult']['@attributes'] = $responseJSON;
					$scanURL = $baseURL.'/command'.'?apikey='.$radarrApiKey;
					$fetchMe = array();
					$fetchMe['name'] = 'MovieSearch';
					$fetchMe['movieId'] = $movieID;
					$curl = curl_init($scanURL);
					curl_setopt($curl, CURLOPT_HEADER, false);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
					curl_setopt ($curl, CURLOPT_CAINFO, dirname(__FILE__) . "/cert/cacert.pem");
					curl_setopt($curl, CURLOPT_HTTPHEADER,
							array("Content-type: application/json"));
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fetchMe));
					$json_response = curl_exec($curl);
					$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

				} else {
					$response['status'] = 'no results';
				}
				return $response;
			}
		}
	}

	// Do a thing with a cast device
	function castMedia() {
		$chromecast = new Chromecast();
		$chromecasts = $chromecast->discover();
		$movie = 'https://ia700408.us.archive.org/26/items/BigBuckBunny_328/BigBuckBunny_512kb.mp4';

		if(!count($chromecasts)){
			write_log("No chromecasts discovered.");
			return false;
		}

		foreach($chromecasts as $c){
			write_log($c['description']['device']['friendlyName']);
			$app = new Chromecast\Applications\DefaultMediaReceiver($c);
			$remote = new Chromecast\Remote($c, $app);
			$result = $remote->play($movie);
			write_log(print_r($result,true));
		}
	}


	function fetchList($serviceName) {
		switch($serviceName) {
			case "sick":
				if ($_SESSION['list_sick']) {
					$list = $_SESSION['list_sick'];
				} else {
					testConnection("Sick");
					$list = $_SESSION['list_sick'];
				}
				$selected = $_SESSION['profile_sick'];
				break;
			case "ombi":
				if ($_SESSION['list_ombi']) {
					$list = $_SESSION['ombi'];
				} else {}
				break;
			case "sonarr":
				if ($_SESSION['list_sonarr']) {
					$list = $_SESSION['list_sonarr'];
				} else {
					testConnection("Sonarr");
					$list = $_SESSION['list_sonarr'];
				}
				$selected = $_SESSION['profile_sonarr'];
				break;
			case "couch":
				if ($_SESSION['list_couch']) {
					$list = $_SESSION['list_couch'];
				}  else {
					testConnection("Couch");
					$list = $_SESSION['list_couch'];
				}
				$selected = $_SESSION['profile_couch'];
				break;
			case "radarr":
				if ($_SESSION['list_radarr']) {
					$list = $_SESSION['list_radarr'];
				} else {
					testConnection("Radarr");
					$list = $_SESSION['list_radarr'];
				}
				$selected = $_SESSION['profile_radarr'];
				break;
			default:
				$list = false;
				break;
		}
		$html = "";
		foreach ($list as $id=>$name) {
			$html .= "<option index='".$id."' id='".$name."' ".(($selected == $id) ? 'selected' : '').">".$name."</option>";
		}
		return $html;
	}


	// Test the specified service for connectivity
	function testConnection($serviceName) {
		switch($serviceName) {

			case "Ombi":
				$ombiURL = $_SESSION['ip_ombi'];
				$ombiPort = $_SESSION['port_ombi'];
				$plexCred = $_SESSION['plex_cred'];
				$authString = 'Authorization:Basic '.$plexCred;
				$len = strlen($authString);
				if (($ombiURL) && ($plexCred) && ($ombiPort)) {
					$url = $ombiURL . ":" . $ombiPort . "/api/v1/login";
					write_log("Test URL is ".protectURL($url));
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL,$url);
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
					$headers = array(
						$authString,
						'Content-Length: 0'
					);
					curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
					$result = curl_exec ($ch);
					curl_close ($ch);
					write_log('Test result is '.$result);
					$result = ((strpos($result,'"success": true') ? 'Connection to CouchPotato Successful!': 'ERROR: Server not available.'));
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "CouchPotato":
				$couchURL = $_SESSION['ip_couch'];
				$couchApikey = $_SESSION['auth_couch'];
				$couchPort = $_SESSION['port_couch'];
				if (($couchURL) && ($couchApikey) && ($couchPort)) {
					$url = $couchURL . ":" . $couchPort . "/api/" . $couchApikey . "/profile.list";
					$result = curlGet($url);
					if ($result) {
						$resultJSON = json_decode($result,true);
						write_log("Hey, we've got some profiles: ".json_encode($resultJSON));
						$array = array();
						$first = false;
						foreach ($resultJSON['list'] as $profile) {
							$id = $profile['_id'];
							$name = $profile['label'];
							$array[$id] = $name;
							if (! $first) $first = $id;
						}
						$_SESSION['list_couch'] = $array;
						if (! $_SESSION['profile_couch']) $_SESSION['profile_couch'] = $first;
						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'couchProfile',$first);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'couchList',$array);
						saveConfig($_SESSION['config']);
					}
					$result = ((strpos($result,'"success": true') ? 'Connection to CouchPotato Successful!': 'ERROR: Server not available.'));
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Sonarr":
				$result = false;
				$sonarrURL = $_SESSION['ip_sonarr'];
				$sonarrApikey = $_SESSION['auth_sonarr'];
				$sonarrPort = $_SESSION['port_sonarr'];
				if (($sonarrURL) && ($sonarrApikey) && ($sonarrPort)) {
					$url = $sonarrURL . ":" . $sonarrPort . "/api/profile?apikey=".$sonarrApikey;
					$result = curlGet($url);
					if ($result) {
						write_log("Result retrieved.");
						$resultJSON = json_decode($result,true);
						write_log("Result JSON: ".json_encode($resultJSON));

						$array = array();
						$first = false;
						foreach($resultJSON as $profile) {
							$first = ($first ? $first : $profile['id']);
							$array[$profile['id']] = $profile['name'];
						}
						write_log("Final array is ".json_encode($array));
						$_SESSION['list_sonarr'] = $array;
						if (! $_SESSION['profile_sonarr']) $_SESSION['profile_sonarr'] = $first;
						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'sonarrProfile',$first);
						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'sonarrList',$array);
						saveConfig($_SESSION['config']);
					}
					$result = (($result !== false) ? 'Connection to Sonarr successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";

				break;

			case "Radarr":
				$result = false;
				$radarrURL = $_SESSION['ip_radarr'];
				$radarrApikey = $_SESSION['auth_radarr'];
				$radarrPort = $_SESSION['port_radarr'];
				if (($radarrURL) && ($radarrApikey) && ($radarrPort)) {
					$url = $radarrURL . ":" . $radarrPort . "/api/profile?apikey=".$radarrApikey;
					$result = curlGet($url);
					if ($result) {
						write_log("Result retrieved.");
						$resultJSON = json_decode($result,true);
						$array = array();
						$first = false;
						foreach($resultJSON as $profile) {
							$first = ($first ? $first : $profile['id']);
							$array[$profile['id']] = $profile['name'];
						}
						write_log("Final array is ".json_encode($array));
						$_SESSION['list_radarr'] = $array;
						if (! $_SESSION['profile_radarr']) $_SESSION['profile_radarr'] = $first;
						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'radarrProfile',$first);

						$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'radarrList',$array);
						saveConfig($_SESSION['config']);
					}
					$result = (($result !== false) ? 'Connection to Radarr successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Sick":
				$result = false;
				$sickURL = $_SESSION['ip_sick'];
				$sickApiKey = $_SESSION['auth_sick'];
				$sickPort = $_SESSION['port_sick'];
				if (($sickURL) && ($sickApiKey) && ($sickPort)) {
					$sick = new SickRage($sickURL.':'.$sickPort, $sickApiKey);
					$result = $sick->sbGetDefaults();
					$result = json_decode($result,true);
					write_log("Got some kind of result ".json_encode($result));
					$list = $result['data']['initial'];
					$array = array();
					$count = 0;
					$first = false;
					foreach ($list as $profile) {
						$first = ($first ? $first : $cound);
						$array[$count] = $profile;
						$count++;
					}
					$_SESSION['list_sick'] = $array;
					$_SESSION['config']->set('user-_-'.$_SESSION['username'], 'sickList',$array);
					saveConfig($_SESSION['config']);
					write_log("List: ".print_r($_SESSION['list_sick'],true));
					$result = (($result) ? 'Connection to Sick successful!' : 'ERROR: Server not available.');
				} else $result = "ERROR: Missing server parameters.";
				break;

			case "Plex":
				$url = $_SESSION['uri_plexserver'].'?X-Plex-Token='.$_SESSION['plexToken'];
				write_log('URL is: '.protectURL($url));
				$result = curlGet($url);
				$result = (($result) ? 'Connection to '.$_SESSION['name_plexserver'].' successful!': 'ERROR: '.$_SESSION['name_plexserver'].' not available.');
				break;

			default:
				$result = "ERROR: Service name not recognized";
				break;
		}
		return $result;
	}


 // APIAI ITEMS
 // Put our calls to API.ai here
 // #######################################################################
 // Push API.ai bot to other's account.  This can go after Google approval



	// Fetch a list of objects specified by $type (either 'intents' or 'entities')
	function fetchApiAiList($type) {
		$apiUrl = 'https://api.api.ai/v1/'.$type.'?v=20150910';
		write_log("URL is ".protectURL($apiUrl));
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$apiUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
		$headers = array(
			'Authorization:Bearer '.$_SESSION['apiai_dev_token']
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec ($ch);
		curl_close ($ch);
		$resultObject = json_decode($result);
		write_log("result and object are ".$result. " and ");
		return $resultObject;
	}


	// Send an array of JSON elements to Api.ai, specified by $type (either 'intents' or 'entities')
	function putApiAiList($list, $deleteList, $intentList, $deleteIntentList) {
		write_log("Function Fired: ". __FUNCTION__);
		write_log("put Type is ".$type);
		// First, loop through the list of elements and delete them from API.ai
		// This may need to be updated if people want to expand their old versions.
		// Maybe make this back up the current settings just in case
		$type = "intents";
		foreach ($deleteIntentList as $item) {
			$itemJSON = json_decode(json_encode($item),true);
			write_log("DeleteItem as JSON is: ".$itemJSON);
			$ID = (string)$itemJSON['id'];
			$name = (string)$itemJSON['name'];
			$apiUrl = 'https://api.api.ai/v1/'.$type.'/'.$ID;
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$apiUrl);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($item));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
			//curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'Authorization:Bearer '.$_SESSION['apiai_dev_token'],
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$result = curl_exec ($ch);
			curl_close ($ch);
			write_log("Delete Result for ".$type." ".$name." is ".(print_r($result,true)));

		}
		sleep(5);
		$type = "entities";
		foreach ($deleteList as $item) {
			$itemJSON = json_decode(json_encode($item),true);
			write_log("DeleteItem as JSON is: ".$itemJSON);
			$ID = (string)$itemJSON['id'];
			$name = (string)$itemJSON['name'];
			$apiUrl = 'https://api.api.ai/v1/'.$type.'/'.$ID;
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$apiUrl);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($item));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
			//curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'Authorization:Bearer '.$_SESSION['apiai_dev_token'],
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$result = curl_exec ($ch);
			curl_close ($ch);
			write_log("Delete Result for ".$type." ".$name." is ".($result));

		}

		$type = "entities";
		foreach ($list as $item) {
			$itemJSON = json_decode(json_encode($item),true);
			$ID = (string)$itemJSON['id'];
			$name = (string)$itemJSON['name'];
			$apiUrl = 'https://api.api.ai/v1/'.$type.'/';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$apiUrl);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($item));
			//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'Authorization:Bearer '.$_SESSION['apiai_dev_token'],
				'Content-Type: application/json; charset=utf-8'
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			if (curl_errno($ch)) {
				$results = false;
			} else {
				$result = curl_exec ($ch);
				$results = true;
				curl_close ($ch);
				write_log("Put Result for ".$type." ".$name." is ".(print_r($result,true)));
			}
		}
		$type = "intents";
		foreach ($intentList as $item) {
			$itemJSON = json_decode(json_encode($item),true);
			$ID = (string)$itemJSON['id'];
			$name = (string)$itemJSON['name'];
			$apiUrl = 'https://api.api.ai/v1/'.$type.'/';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$apiUrl);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($item));
			//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_CAINFO, rtrim(dirname(__FILE__), '/') . "/cert/cacert.pem");
			$headers = array(
				'Authorization:Bearer '.$_SESSION['apiai_dev_token'],
				'Content-Type: application/json; charset=utf-8'
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			if (curl_errno($ch)) {
				$results = false;
			} else {
				$result = curl_exec ($ch);
				$results = true;
				curl_close ($ch);
				write_log("Put Result for ".$type." ".$name." is ".(print_r($result,true)));
			}
		}
		return $result;
	}


	// Get a list of apiAi Objects stored locally
	function getApiAiList($type) {
		$dir = "APIAI/".$type."/";
		// Open a directory, and read its contents
		if (is_dir($dir)){
			if ($dh = opendir($dir)){
				$items = array();
				while (($file = readdir($dh)) !== false){
					$item = curlGet("APIAI/".$type."/".$file);
					if ($item) {
						array_push($items, json_decode($item));
					}
				}
			closedir($dh);
			return $items;
			}
		}
	}


	// Returns a speech object to be read by Assistant
	function returnSpeech($speech, $contextName, $waitForResponse) {
		write_log("Final Speech should be: ".$speech);
		$waitForResponse = ($waitForResponse ? $waitForResponse : false);
		header('Content-Type: application/json');
		ob_start();
		$output["speech"] = $speech;
		$returns = array();
		$contexts = array('waitforplayer','yes','promptfortitle');
		foreach($contexts as $context) {
			if ($context == $contextName) {
				$lifespan = 2;
			} else {
				$lifespan = 0;
			}
			$item = array(name=>$context, lifespan=>$lifespan);
			array_push($returns,$item);
		}
		$output["contextOut"] = $returns;
		$output["contextOut"][0]["name"] = $contextName;
		$output["contextOut"][0]["lifespan"] = 2;
		write_log("Expect response is ". $waitForResponse);
		$output["data"]["google"]["expect_user_response"] = $waitForResponse;
		//$output["data"] = $resultData;
		$output["displayText"] = $speech;
		$output["source"] = "whatever.php";
		ob_end_clean();
		echo json_encode($output);
		write_log("JSON out is ".json_encode($output));
	}




	// Register our server with the mothership and link google account
	function registerServer() {
		$realIP = trim(curlGet('https://plex.tv/pms/:/ip'));
		$_SESSION['publicAddress'] = $_SESSION['config']->get('user-_-'.$_SESSION['username'], 'publicAddress', $realIP);
		$registerUrl = "https://phlexserver.cookiehigh.us/api.php".
		"?apiToken=".$_SESSION['apiToken'].
		"&serverAddress=".htmlentities($_SESSION['publicAddress']);
		write_log("registerServer: URL is " . protectURL($registerUrl));
		$result = curlGet($registerUrl);
		if ($result == "OK") {
			$_SESSION['config']->set('user-_-'.$_SESSION['username'],'lastCheckIn',time());
			saveConfig($_SESSION['config']);
			write_log("Successfully registered with server.");
		} else {
			write_log("Server registration failed.");
		}
	}



	function cleanCommandString($string) {
		$string = trim(strtolower($string));
		$string = preg_replace("#[[:punct:]]#", "", $string);
		$stringArray = explode(" "	,$string);
		$stripIn = array("of","the","an","a","at","th","nd","in","it","from","and");
		$stringArray = array_diff($stringArray,array_intersect($stringArray,$stripIn));
		$result = implode(" ",$stringArray);
		return $result;
	}
?>

<?php
require_once('Constants.class.php');
require_once('data/DataLoadLog.class.php');

class WarOfNationsWS {
	// Database
	public $db;
	
	// Current Data Load ID
	public $data_load_id;
	
	// List of proxy servers to use for connections.
	private $proxies;
	private $max_retries = -1;
	
	// Base Configuration
	private $url_base;
	private $hmac_key;
	
	function __construct($db, $proxies, $url_base, $hmac_key) {
		$this->db = $db;
		$this->proxies = $proxies;
		$this->url_base = $url_base;
		$this->hmac_key = $hmac_key;
	}
	
	private function max_attempts_reached($retry_count) {
		//echo 'retries: '.$retry_count.'/'.self::$max_retries."\r\n";
		return $retry_count >= self::$max_retries && self::$max_retries >= 0;
	}
	
	private function get_headers($endpoint, $data_string) {
		$time = substr(microtime(true), 0, 10);
		$hmac_secret = $time.$endpoint.$data_string;
		
		return array(
		  	'Accept: application/json',
			'Content-type: application/json; charset=UTF-8;',
			'X-Signature: ' . hash_hmac('md5', $hmac_secret, $this->hmac_key, false),
			'X-Timestamp: ' . $time,
			'User-Agent: Dalvik/1.6.0 (Linux; U; Android 4.4.2; SCH-I545 Build/KOT49H)',
			'Connection: Keep-Alive',
			'Accept-Encoding: gzip'
		);
	}
	
	private function init_request($url, $proxy = false) {
		// If we're using a proxy server for this request, set the curl options
		if($proxy !== false) {
			$proxy_str = "{$proxy['ip_address']}:{$proxy['port']}";
			$ch = curl_init($url);         
			curl_setopt($ch, CURLOPT_PROXY, $proxy_str);
			
			if($proxy['type'] == 'SOCKS') // If this is a SOCKS proxy, set the type - HTTP is the default
				curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
			
			// If this proxy has a username and apssword, set the authentication string
			if($proxy['username'] != null && $proxy['password'] != null) {
				$proxyauth = "{$proxy['username']}:{$proxy['password']}";
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyauth);
			}
		}
		
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Timeout in seconds - prevents taking a long time to connect to a bad proxy
		curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout in seconds - prevents taking a long time to connect to a bad proxy
		
		return $ch;
	}
	
	public function MakeRequest($endpoint, $data_string, $retry_count = 0) {	
		$log_msg = "Attempt #".($retry_count + 1)."\r\n\r\n".$data_string;
		$log_seq = 0;
		DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'START', $endpoint, $log_msg);
			
		if($retry_count > 0)
			echo "Retry Attempt #$retry_count<br/>\r\n";
		
		// Assemble our URL
		$url = $this->url_base.$endpoint;
		
		// Initialize our Curl request
		$headers = $this->get_headers($endpoint, $data_string);
		$proxy = $this->proxies[array_rand($this->proxies)];
		
		$ch = $this->init_request($url, $proxy);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		
		$log_msg = "Proxy: $proxy_str\r\n\r\n".print_r($headers, true);
		DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'REQUEST_INFO', null, $log_msg);
		
		// Execute our request
		$start = microtime(true);
		$response_string = curl_exec($ch);
		$end = microtime(true);
		
		// cleans up the curl request
		curl_close($ch);
		
		DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'REQUEST_COMPLETE', 'Time: '.($end - $start), null);
		
		$request_time = $end - $start;
		echo "Request completed in ".$request_time." seconds\r\n";
		
		// If our call failed
		if(!$response_string) {
			$log_msg = "Proxy: {$proxy['ip_address']}:{$proxy['port']}<br/>\r\nURL: $url<br/>\r\nData: $data_string<br/>\r\n";
			DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'ERROR_CURL_RESPONSE', 'Error occurred during curl request', $log_msg, 1);
			
			echo "Error occurred while getting Curl response.\r\n";
			//echo "Proxy: {$proxy['ip_address']}:{$proxy['port']}<br/>\r\n";
			//echo "URL: $url<br/>\r\n";
			//echo "Data: $data_string<br/>\r\n\r\n";
			
			ProxyDAO::countFailure($this->db, $proxy['id'], $request_time);
			
			// Retry if we haven't reached our max
			if(!$this->max_attempts_reached($retry_count))
				return $this->MakeRequest($endpoint, $data_string, $retry_count + 1);
			else
				return false;
		}
		
		// We send a header requesting a gzip encoded response, so try to decode it
		$decoded = gzdecode($response_string);
		
		// If we encountered an error in decoding, see what we can do with it
		if(!$decoded) {
			// Some of our proxies decode the gzip for us, so check to see if we've been decoded
			// If we have UTF-8 encoding already, then we might be OK after all
			if(mb_check_encoding($response_string, 'UTF-8')) {
				DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'GZDECODE_QUESTIONABLE', 'Gzip decoding failed, but response is utf8 encoded.  We\'ll try to use it.', $response_string);
				
				echo "Error occurred while decoding CURL response, but let's assume this is OK!\r\n";
				
				// If we think we got a proxy error, then log it, and disable the proxy server.  Otherwise return the response
				if(stripos($response_string, 'The maximum web proxy user limit has been reached') > 0) {
					DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'PROXY_LIMIT_REACHED', null, null);
					ProxyDAO::disableProxy($this->db, $proxy['id'], 'The maximum web proxy user limit has been reached');
				} else if(stripos($response_string, '<title>Access Den') > 0) {
					DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'ACCESS_DENIED', null, null);
					ProxyDAO::disableProxy($this->db, $proxy['id'], 'Access Denied Received');
				} else {
					ProxyDAO::countSuccess($this->db, $proxy['id'], $request_time);
					return $response_string;
				}
			} else {
				DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'GZDECODE_FAILED', 'Gzip decoding failed, and we don\'t know what to do with it.  Retry this request.', $response_string, 1);
				
				echo "Error occurred while decoding CURL response, and we think this is a problem!\r\n";
				ProxyDAO::countFailure($this->db, $proxy['id'], $request_time);
			}
			
			// If we made it this far then we need to retry
			if(!$this->max_attempts_reached($retry_count))
				return $this->MakeRequest($endpoint, $data_string, $retry_count + 1);
			else
				return false;
		} 
		
		// If we made it this far, then we were successful, to let's log it as a success to our proxy
		ProxyDAO::countSuccess($this->db, $proxy['id'], $request_time);
		DataLoadLogDAO::logEvent($this->db, $this->data_load_id, 'MAKE_REQUEST', $log_seq++, 'COMPLETE', null, null);
		
		// Return the decoded string
		return $decoded;
	}
}
?>
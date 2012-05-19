<?php
/**
 * Opauth
 * Multi-provider authentication framework for PHP
 *
 * @copyright		Copyright © 2012 U-Zyn Chua (http://uzyn.com)
 * @link 			http://opauth.org
 * @package			Opauth
 * @license			MIT License
 */

/**
 * Opauth
 * Multi-provider authentication framework for PHP
 * 
 * @package			Opauth
 */
class Opauth{
	/**
	 * User configuraable settings
	 * Refer to example/opauth.conf.php.default or example/opauth.conf.php.advanced for sample
	 * More info: https://github.com/uzyn/opauth/wiki/Opauth-configuration
	 */
	public $config;	
	
	/**
	 * Environment variables
	 */
	public $env;
	
	/** 
	 * Strategy map: for mapping URL-friendly name to Class name
	 */
	public $strategyMap;
	
	public function __construct($config = array(), $run = true){

		/**
		 * Configurable settings
		 */
		$this->config = array_merge(array(
			'host' => ((array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'],
			'path' => '/',
			'callback_url' => '{path}callback',
			'callback_transport' => 'session',
			'debug' => false,
			
			/**
		 	* Security settings
		 	*/
			'security_salt' => 'LDFmiilYf8Fyw5W10rx4W1KsVrieQCnpBzzpTBWA5vJidQKDx8pMJbmw28R1C4m',
			'security_iteration' => 300,
			'security_timeout' => '2 minutes'
			
		), $config);
		
		/**
		 * Environment variables, including config
		 * Used mainly as accessors
		 */
		$this->env = array_merge(array(
			'request_uri' => $_SERVER['REQUEST_URI'],
			'complete_path' => $this->config['host'].$this->config['path'],
			'lib_dir' => dirname(__FILE__).'/',
			'strategy_dir' => dirname(__FILE__).'/Strategy/'
		), $this->config);
		
		foreach ($this->env as $key => $value){
			$this->env[$key] = $this->envReplace($value);
		}
	
		if ($this->env['security_salt'] == 'LDFmiilYf8Fyw5W10rx4W1KsVrieQCnpBzzpTBWA5vJidQKDx8pMJbmw28R1C4m'){
			trigger_error('Please change the value of \'security_salt\' to a salt value specific to your application', E_USER_NOTICE);
		}
		
		$this->loadStrategies();
		
		if ($run) $this->run();
	}
	
	/**
	 * Run Opauth:
	 * Parses request URI and perform defined authentication actions based based on it.
	 */
	public function run(){
		$this->parseUri();
		
		if (!empty($this->env['params']['strategy'])){
			if (strtolower($this->env['params']['strategy']) == 'callback'){
				$this->callback();
			}
			elseif (array_key_exists($this->env['params']['strategy'], $this->strategyMap)){
				$name = $this->strategyMap[$this->env['params']['strategy']]['name'];
				$class = $this->strategyMap[$this->env['params']['strategy']]['class'];
				$strategy = $this->env['Strategy'][$name];
				
				require $this->env['lib_dir'].'OpauthStrategy.php';
				require $this->env['strategy_dir'].$class.'/'.$class.'.php';

				$this->Strategy = new $class($this, $strategy);
				$this->Strategy->callAction($this->env['params']['action']);
			}
			else{
				trigger_error('Unsupported or undefined Opauth strategy - '.$this->env['strategy'], E_USER_ERROR);
			}
		}
	}
	
	/**
	 * Parses Request URI
	 */
	private function parseUri(){
		$this->env['request'] = substr($this->env['request_uri'], strlen($this->env['path']) - 1);
		
		if (preg_match_all('/\/([A-Za-z0-9-_]+)/', $this->env['request'], $matches)){
			foreach ($matches[1] as $match){
				$this->env['params'][] = $match;
			}
		}
		
		if (!empty($this->env['params'][0])) $this->env['params']['strategy'] = $this->env['params'][0];
		if (!empty($this->env['params'][1])) $this->env['params']['action'] = $this->env['params'][1];
	}
	
	/**
	 * Load strategies from user-input $config
	 */	
	private function loadStrategies(){
		if (isset($this->env['Strategy']) && is_array($this->env['Strategy']) && count($this->env['Strategy']) > 0){
			foreach ($this->env['Strategy'] as $key => $strategy){
				if (!is_array($strategy)){
					$key = $strategy;
					$strategy = array();
				}
				
				$strategyClass = $key;
				if (array_key_exists('opauth_strategy', $strategy)) $strategyClass = $strategy['opauth_strategy'];
				else $strategy['opauth_strategy'] = $strategyClass;
				
				$strategy['opauth_name'] = $key;
				
				// Define a URL-friendly name
				if (empty($strategy['opauth_url_name'])) $strategy['opauth_url_name'] = strtolower($key);
				$this->strategyMap[$strategy['opauth_url_name']] = array(
					'name' => $key,
					'class' => $strategyClass
				);
				
				$this->env['Strategy'][$key] = $strategy;
			}
		}
		else{
			trigger_error('No Opauth strategies defined', E_USER_ERROR);
		}
	}
	
	/**
	 * Replace defined env values enclused in {} with values from $dictionary
	 * $dictionary is defaulted to $this->env
	 */
	public function envReplace($value, $dictionary = null){
		if (is_null($dictionary)) $dictionary = $this->env;
		
		if (is_string($value) && preg_match_all('/{([A-Za-z0-9-_]+)}/', $value, $matches)){
			foreach ($matches[1] as $key){
				if (array_key_exists($key, $dictionary)){
					$value = str_replace('{'.$key.'}', $dictionary[$key], $value);
				}
			}

			return $value;
		}

		return $value;
	}
	
	/**
	 * Validate $auth response
	 * Accepts either function call or HTTP-based call
	 * 
	 * @param string $input = sha1(print_r($auth, true))
	 * @param string $timestamp = $_REQUEST['timestamp'])
	 * @param string $signature = $_REQUEST['signature']
	 * @param $reason Sets reason for failure if validation fails
	 * @return boolean true: valid; false: not valid.
	 */
	public function validate($input = null, $timestamp = null, $signature = null, &$reason = null){
		$functionCall = true;
		if (!empty($_REQUEST['input']) && !empty($_REQUEST['timestamp']) && !empty($_REQUEST['signature'])){
			$functionCall = false;
			$provider = $_REQUEST['input'];
			$timestamp = $_REQUEST['timestamp'];
			$signature = $_REQUEST['signature'];
		}
		
		$timestamp_int = strtotime($timestamp);
		if ($timestamp_int < strtotime('-'.$this->env['security_timeout']) || $timestamp_int > time()){
			$reason = "Auth response expired";
			return false;
		}
		
		require $this->env['lib_dir'].'OpauthStrategy.php';
		$hash = OpauthStrategy::hash($input, $timestamp, $this->env['security_iteration'], $this->env['security_salt']);
		
		if (strcasecmp($hash, $signature) !== 0){
			$reason = "Signature does not validate";
			return false;
		}
		
		return true;
	}
	
	/**
	 * Callback: prints out $auth values, and acts as a guide on Opauth security
	 * Application should redirect callback URL to application-side.
	 * Refer to example/callback.php on how to handle auth callback.
	 */
	public function callback(){
		echo "<strong>Note: </strong>Application should set callback URL to application-side for further specific authentication process.\n<br>";
		
		$response = null;
		switch($this->env['callback_transport']){
			case 'session':
				session_start();
				$response = $_SESSION['opauth'];
				unset($_SESSION['opauth']);
				break;
			case 'post':
				$response = $_POST;
				break;
			case 'get':
				$response = $_GET;
				break;
			default:
				echo '<strong style="color: red;">Error: </strong>Unsupported callback_transport.'."<br>\n";
				break;
		}
		
		/**
		 * Check if it's an error callback
		 */
		if (array_key_exists('error', $response)){
			echo '<strong style="color: red;">Authentication error: </strong> Opauth returns error auth response.'."<br>\n";
		}

		/**
		 * No it isn't. Proceed with auth validation
		 */
		else{
			if (empty($response['auth']) || empty($response['timestamp']) || empty($response['signature']) || empty($response['auth']['provider']) || empty($response['auth']['uid'])){
				echo '<strong style="color: red;">Invalid auth response: </strong>Missing key auth response components.'."<br>\n";
			}
			elseif (!$this->validate(sha1(print_r($response['auth'], true)), $response['timestamp'], $response['signature'], $reason)){
				echo '<strong style="color: red;">Invalid auth response: </strong>'.$reason.".<br>\n";
			}
			else{
				echo '<strong style="color: green;">OK: </strong>Auth response is validated.'."<br>\n";
			}
		}		
		
		/**
		 * Auth response dump
		 */
		echo "<pre>";
		print_r($response);
		echo "</pre>";
	}
	
	
	/**
	 * Prints out variable with <pre> tags
	 * Silence if Opauth is not in debug mode
	 * 
	 * @param $var mixed Object or variable to be printed
	 */	
	public function debug($var){
		if ($this->env['debug'] !== false){
			echo "<pre>";
			print_r($var);
			echo "</pre>";
		}
	}
}
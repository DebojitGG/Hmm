<?php
/**
* HybridAuth
* http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
* (c) 2009-2015, HybridAuth authors | http://hybridauth.sourceforge.net/licenses.html
*/

/**
 * SocialIntegration_Auth class
 * 
 * SocialIntegration_Auth class provide a simple way to authenticate users via OpenID and OAuth.
 * 
 * Generally, SocialIntegration_Auth is the only class you should instanciate and use throughout your application.
 */
class SocialIntegration_Auth 
{
	public static $version = "2.4.0-dev";

	public static $config  = array();

	public static $store   = NULL;

	public static $error   = NULL;

	public static $logger  = NULL;

	// --------------------------------------------------------------------

	/**
	* Try to start a new session of none then initialize SocialIntegration_Auth
	* 
	* SocialIntegration_Auth constructor will require either a valid config array or
	* a path for a configuration file as parameter. To know more please 
	* refer to the Configuration section:
	* http://hybridauth.sourceforge.net/userguide/Configuration.html
	*/
	function __construct( $config )
	{ 
		SocialIntegration_Auth::initialize( $config ); 
	}

	// --------------------------------------------------------------------

	/**
	* Try to initialize SocialIntegration_Auth with given $config hash or file
	*/
	public static function initialize( $config )
	{
		if( ! is_array( $config ) && ! file_exists( $config ) ){
			throw new Exception( "Hybriauth config does not exist on the given path.", 1 );
		}

		if( ! is_array( $config ) ){
			$config = include $config;
		}

		// build some need'd paths
		$config["path_base"]        = realpath( dirname( __FILE__ ) )  . "/"; 
		$config["path_libraries"]   = $config["path_base"] . "Base/";
		$config["path_resources"]   = $config["path_base"] . "resources/";
		$config["path_providers"]   = $config["path_base"] . "Providers/";

		// reset debug mode
		if( ! isset( $config["debug_mode"] ) ){
			$config["debug_mode"] = false;
			$config["debug_file"] = null;
		}

		# load hybridauth required files, a autoload is on the way...
		require_once $config["path_base"] . "Error.php";
		require_once $config["path_base"] . "Exception.php";
		require_once $config["path_base"] . "Logger.php";

		require_once $config["path_base"] . "Provider_Adapter.php";

		require_once $config["path_base"] . "Provider_Model.php";
		require_once $config["path_base"] . "Provider_Model_OpenID.php";
		require_once $config["path_base"] . "Provider_Model_OAuth1.php";
		require_once $config["path_base"] . "Provider_Model_OAuth2.php";

		require_once $config["path_base"] . "User.php";
		require_once $config["path_base"] . "User_Profile.php";
		require_once $config["path_base"] . "User_Contact.php";
		require_once $config["path_base"] . "User_Activity.php";

		if ( ! class_exists("Hybrid_Storage", false) ){
			require_once $config["path_base"] . "Storage.php";
        	}

		// hash given config
		SocialIntegration_Auth::$config = $config;

		// instance of log mng
		SocialIntegration_Auth::$logger = new Hybrid_Logger();

		// instance of errors mng
		SocialIntegration_Auth::$error = new Hybrid_Error();

		// start session storage mng
		SocialIntegration_Auth::$store = new SocialIntegration_Storage();

		Hybrid_Logger::info( "Enter SocialIntegration_Auth::initialize()"); 
		Hybrid_Logger::info( "SocialIntegration_Auth::initialize(). PHP version: " . PHP_VERSION ); 
		Hybrid_Logger::info( "SocialIntegration_Auth::initialize(). SocialIntegration_Auth version: " . SocialIntegration_Auth::$version ); 
		Hybrid_Logger::info( "SocialIntegration_Auth::initialize(). SocialIntegration_Auth called from: " . SocialIntegration_Auth::getCurrentUrl() ); 

		// PHP Curl extension [http://www.php.net/manual/en/intro.curl.php]
		if ( ! function_exists('curl_init') ) {
			Hybrid_Logger::error('Hybridauth Library needs the CURL PHP extension.');
			throw new Exception('Hybridauth Library needs the CURL PHP extension.');
		}

		// PHP JSON extension [http://php.net/manual/en/book.json.php]
		if ( ! function_exists('json_decode') ) {
			Hybrid_Logger::error('Hybridauth Library needs the JSON PHP extension.');
			throw new Exception('Hybridauth Library needs the JSON PHP extension.');
		} 

		// session.name
		if( session_name() != "PHPSESSID" ){
			Hybrid_Logger::info('PHP session.name diff from default PHPSESSID. http://php.net/manual/en/session.configuration.php#ini.session.name.');
		}

		// safe_mode is on
		if( ini_get('safe_mode') ){
			Hybrid_Logger::info('PHP safe_mode is on. http://php.net/safe-mode.');
		}

		// open basedir is on
		if( ini_get('open_basedir') ){
			Hybrid_Logger::info('PHP open_basedir is on. http://php.net/open-basedir.');
		}

		Hybrid_Logger::debug( "SocialIntegration_Auth initialize. dump used config: ", serialize( $config ) );
		Hybrid_Logger::debug( "SocialIntegration_Auth initialize. dump current session: ", SocialIntegration_Auth::storage()->getSessionData() ); 
		Hybrid_Logger::info( "SocialIntegration_Auth initialize: check if any error is stored on the endpoint..." );

		if( Hybrid_Error::hasError() ){ 
			$m = Hybrid_Error::getErrorMessage();
			$c = Hybrid_Error::getErrorCode();
			$p = Hybrid_Error::getErrorPrevious();

			Hybrid_Logger::error( "SocialIntegration_Auth initialize: A stored Error found, Throw an new Exception and delete it from the store: Error#$c, '$m'" );

			Hybrid_Error::clearError();

			// try to provide the previous if any
			// Exception::getPrevious (PHP 5 >= 5.3.0) http://php.net/manual/en/exception.getprevious.php
			if ( version_compare( PHP_VERSION, '5.3.0', '>=' ) && ($p instanceof Exception) ) { 
				throw new Exception( $m, $c, $p );
			}
			else{
				throw new Exception( $m, $c );
			}
		}

		Hybrid_Logger::info( "SocialIntegration_Auth initialize: no error found. initialization succeed." );

		// Endof initialize 
	}

	// --------------------------------------------------------------------

	/**
	* Hybrid storage system accessor
	*
	* Users sessions are stored using HybridAuth storage system ( HybridAuth 2.0 handle PHP Session only) and can be accessed directly by
	* SocialIntegration_Auth::storage()->get($key) to retrieves the data for the given key, or calling
	* SocialIntegration_Auth::storage()->set($key, $value) to store the key => $value set.
	*/
	public static function storage()
	{
		return SocialIntegration_Auth::$store;
	}

	// --------------------------------------------------------------------

	/**
	* Get hybridauth session data. 
	*/
	function getSessionData()
	{ 
		return SocialIntegration_Auth::storage()->getSessionData();
	}

	// --------------------------------------------------------------------

	/**
	* restore hybridauth session data. 
	*/
	function restoreSessionData( $sessiondata = NULL )
	{ 
		SocialIntegration_Auth::storage()->restoreSessionData( $sessiondata );
	}

	// --------------------------------------------------------------------

	/**
	* Try to authenticate the user with a given provider. 
	*
	* If the user is already connected we just return and instance of provider adapter,
	* ELSE, try to authenticate and authorize the user with the provider. 
	*
	* $params is generally an array with required info in order for this provider and HybridAuth to work,
	*  like :
	*          hauth_return_to: URL to call back after authentication is done
	*        openid_identifier: The OpenID identity provider identifier
	*           google_service: can be "Users" for Google user accounts service or "Apps" for Google hosted Apps
	*/
	public static function authenticate( $providerId, $params = NULL )
	{
		Hybrid_Logger::info( "Enter SocialIntegration_Auth::authenticate( $providerId )" );

		// if user not connected to $providerId then try setup a new adapter and start the login process for this provider
		if( ! SocialIntegration_Auth::storage()->get( "hauth_session.$providerId.is_logged_in" ) ){ 
			Hybrid_Logger::info( "SocialIntegration_Auth::authenticate( $providerId ), User not connected to the provider. Try to authenticate.." );

			$provider_adapter = SocialIntegration_Auth::setup( $providerId, $params );

			$provider_adapter->login();
		}

		// else, then return the adapter instance for the given provider
		else{
			Hybrid_Logger::info( "SocialIntegration_Auth::authenticate( $providerId ), User is already connected to this provider. Return the adapter instance." );

			return SocialIntegration_Auth::getAdapter( $providerId );
		}
	}

	// --------------------------------------------------------------------

	/**
	* Return the adapter instance for an authenticated provider
	*/ 
	public static function getAdapter( $providerId = NULL )
	{
		Hybrid_Logger::info( "Enter SocialIntegration_Auth::getAdapter( $providerId )" );

		return SocialIntegration_Auth::setup( $providerId );
	}

	// --------------------------------------------------------------------

	/**
	* Setup an adapter for a given provider
	*/ 
	public static function setup( $providerId, $params = NULL )
	{
		Hybrid_Logger::debug( "Enter SocialIntegration_Auth::setup( $providerId )", $params );

		if( ! $params ){ 
			$params = SocialIntegration_Auth::storage()->get( "hauth_session.$providerId.id_provider_params" );
			
			Hybrid_Logger::debug( "SocialIntegration_Auth::setup( $providerId ), no params given. Trying to get the stored for this provider.", $params );
		}

		if( ! $params ){ 
			$params = ARRAY();
			
			Hybrid_Logger::info( "SocialIntegration_Auth::setup( $providerId ), no stored params found for this provider. Initialize a new one for new session" );
		}

		if( is_array($params) && ! isset( $params["hauth_return_to"] ) ){
			$params["hauth_return_to"] = SocialIntegration_Auth::getCurrentUrl(); 

			Hybrid_Logger::debug( "SocialIntegration_Auth::setup( $providerId ). HybridAuth Callback URL set to: ", $params["hauth_return_to"] );
		}

		# instantiate a new IDProvider Adapter
		$provider   = new SocialIntegration_Provider_Adapter();

		$provider->factory( $providerId, $params );

		return $provider;
	} 

	// --------------------------------------------------------------------

	/**
	* Check if the current user is connected to a given provider
	*/
	public static function isConnectedWith( $providerId )
	{
		return (bool) SocialIntegration_Auth::storage()->get( "hauth_session.{$providerId}.is_logged_in" );
	}

	// --------------------------------------------------------------------

	/**
	* Return array listing all authenticated providers
	*/ 
	public static function getConnectedProviders()
	{
		$idps = array();

		foreach( SocialIntegration_Auth::$config["providers"] as $idpid => $params ){
			if( SocialIntegration_Auth::isConnectedWith( $idpid ) ){
				$idps[] = $idpid;
			}
		}

		return $idps;
	}

	// --------------------------------------------------------------------

	/**
	* Return array listing all enabled providers as well as a flag if you are connected.
	*/ 
	public static function getProviders()
	{
		$idps = array();

		foreach( SocialIntegration_Auth::$config["providers"] as $idpid => $params ){
			if($params['enabled']) {
				$idps[$idpid] = array( 'connected' => false );

				if( SocialIntegration_Auth::isConnectedWith( $idpid ) ){
					$idps[$idpid]['connected'] = true;
				}
			}
		}

		return $idps;
	}

	// --------------------------------------------------------------------

	/**
	* A generic function to logout all connected provider at once 
	*/ 
	public static function logoutAllProviders()
	{
		$idps = SocialIntegration_Auth::getConnectedProviders();

		foreach( $idps as $idp ){
			$adapter = SocialIntegration_Auth::getAdapter( $idp );

			$adapter->logout();
		}
	}

	// --------------------------------------------------------------------

	/**
	* Utility function, redirect to a given URL with php header or using javascript location.href
	*/
	public static function redirect( $url, $mode = "PHP" )
	{
		Hybrid_Logger::info( "Enter SocialIntegration_Auth::redirect( $url, $mode )" );

		if( $mode == "PHP" ){
			header( "Location: $url" ) ;
		}
		elseif( $mode == "JS" ){
			echo '<html>';
			echo '<head>';
			echo '<script type="text/javascript">';
			echo 'function redirect(){ window.top.location.href="' . $url . '"; }';
			echo '</script>';
			echo '</head>';
			echo '<body onload="redirect()">';
			echo 'Redirecting, please wait...';
			echo '</body>';
			echo '</html>'; 
		}

		die();
	}

	// --------------------------------------------------------------------

	/**
	* Utility function, return the current url. TRUE to get $_SERVER['REQUEST_URI'], FALSE for $_SERVER['PHP_SELF']
	*/
	public static function getCurrentUrl( $request_uri = true ) 
	{
		if (php_sapi_name() == 'cli') {
			return '';
		}
		
		if(
			isset( $_SERVER['HTTPS'] ) && ( $_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1 )
		|| 	isset( $_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
		){
			$protocol = 'https://';
		}
		else {
			$protocol = 'http://';
		}

		$url = $protocol . $_SERVER['HTTP_HOST'];

		if( $request_uri ){
			$url .= $_SERVER['REQUEST_URI'];
		}
		else{
			$url .= $_SERVER['PHP_SELF'];
		}

		// return current url
		return $url;
	}
}
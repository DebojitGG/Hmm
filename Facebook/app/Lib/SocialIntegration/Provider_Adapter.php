<?php
/**
* SocialIntegrationAuth
* http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
* (c) 2009-2014, SocialIntegrationAuth authors | http://hybridauth.sourceforge.net/licenses.html
*/

/**
 * SocialIntegration_Provider_Adapter is the basic class which SocialIntegration_Auth will use
 * to connect users to a given provider. 
 * 
 * Basically SocialIntegration_Provider_Adapter will create a bridge from your php
 * application to the provider api.
 * 
 * SocialIntegration_Auth will automatically load SocialIntegration_Provider_Adapter and create
 * an instance of it for each authenticated provider.
 */
class SocialIntegration_Provider_Adapter
{
	/**
	 * Provider ID (or unique name)
	 * @var Numeric/String
	 */
	public $id       = NULL ;

	/**
	 * Provider adapter specific config
	 * @var Array
	 */
	public $config   = NULL ;

	/**
	 * Provider adapter extra parameters
	 * @var array
	 */
	public $params   = NULL ; 

	/**
	 * Provider adapter wrapper path
	 * @var String
	 */
	public $wrapper  = NULL ;

	/**
	 * Provider adapter instance
	 * @var object
	 */
	public $adapter  = NULL ;

	// --------------------------------------------------------------------

	/**
	* create a new adapter switch IDp name or ID
	*
	* @param string  $id      The id or name of the IDp
	* @param array   $params  (optional) required parameters by the adapter 
	*/
	function factory( $id, $params = NULL )
	{          
		// SocialIntegration_Logger::info( "Enter SocialIntegration_Provider_Adapter::factory( $id )" );

		# init the adapter config and params
		$this->id     = $id;
		$this->params = $params;
		$this->id     = $this->getProviderCiId( $this->id );
		$this->config = $this->getConfigById( $this->id );

		# check the IDp id
		if( ! $this->id ){
			throw new Exception( "No provider ID specified.", 2 ); 
		}

		# check the IDp config
		if( ! $this->config ){
			throw new Exception( "Unknown Provider ID, check your configuration file.", 3 ); 
		}

		# check the IDp adapter is enabled
		if( ! $this->config["enabled"] ){
			throw new Exception( "The provider '{$this->id}' is not enabled.", 3 );
		}

		# include the adapter wrapper
		if( isset( $this->config["wrapper"] ) && is_array( $this->config["wrapper"] ) ){
			require_once $this->config["wrapper"]["path"];

			if( ! class_exists( $this->config["wrapper"]["class"] ) ){
				throw new Exception( "Unable to load the adapter class.", 3 );
			}

			$this->wrapper = $this->config["wrapper"]["class"];
		}
		else{ 
                        
			require_once SocialIntegration_Auth::$config["path_providers"] . $this->id . ".php" ;

			$this->wrapper = "SocialIntegration_Providers_" . $this->id; 
		}
               
		# create the adapter instance, and pass the current params and config
		$this->adapter = new $this->wrapper( $this->id, $this->config, $this->params );
 
		return $this;
	}

	// --------------------------------------------------------------------

	/**
	* SocialIntegration_Provider_Adapter::login(), prepare the user session and the authentication request
	* for index.php
	*/
	function login()
	{
		// SocialIntegration_Logger::info( "Enter SocialIntegration_Provider_Adapter::login( {$this->id} ) " );

		if( ! $this->adapter ){
			throw new Exception( "SocialIntegration_Provider_Adapter::login() should not directly used." );
		}

		// clear all unneeded params
		foreach( SocialIntegration_Auth::$config["providers"] as $idpid => $params ){
			SocialIntegration_Auth::storage()->delete( "hauth_session.{$idpid}.hauth_return_to"    );
			SocialIntegration_Auth::storage()->delete( "hauth_session.{$idpid}.hauth_endpoint"     );
			SocialIntegration_Auth::storage()->delete( "hauth_session.{$idpid}.id_provider_params" );
		}

		// make a fresh start
		$this->logout();

		# get hybridauth base url
		if (empty(SocialIntegration_Auth::$config["base_url"])) {
	        // the base url wasn't provide, so we must use the current
	        // url (which makes sense actually)
			$url  = empty($_SERVER['HTTPS']) ? 'http' : 'https';
			$url .= '://' . $_SERVER['HTTP_HOST'];
                    	$url .= $_SERVER['REQUEST_URI'];
			$SocialIntegration_Auth_URL_BASE = $url;
                         
		} else {
			$SocialIntegration_Auth_URL_BASE = SocialIntegration_Auth::$config["base_url"];
		}

		# we make use of session_id() as storage hash to identify the current user
		# using session_regenerate_id() will be a problem, but ..
		$this->params["hauth_token"] = session_id();

		# set request timestamp
		$this->params["hauth_time"]  = time();

		# for default SocialIntegrationAuth endpoint url hauth_login_start_url
		# 	auth.start  required  the IDp ID
		# 	auth.time   optional  login request timestamp
		$this->params["login_start"] = $SocialIntegration_Auth_URL_BASE . ( strpos( $SocialIntegration_Auth_URL_BASE, '?' ) ? '&' : '?' ) . "hauth.start={$this->id}&hauth.time={$this->params["hauth_time"]}";
       
		# for default SocialIntegrationAuth endpoint url hauth_login_done_url
		# 	auth.done   required  the IDp ID
               

                if($this->id == 'Live'){
                     $this->params["login_done"]  = $SocialIntegration_Auth_URL_BASE . '/livedone';
                }elseif($this->id == 'Yahoo'){
                    $this->params["login_done"]  = $SocialIntegration_Auth_URL_BASE . '/yahoo';
                }else{
                    $this->params["login_done"]  = $SocialIntegration_Auth_URL_BASE . ( strpos( $SocialIntegration_Auth_URL_BASE, '?' ) ? '&' : '?' ) . "hauth.done={$this->id}";
                }
		
		SocialIntegration_Auth::storage()->set( "hauth_session.{$this->id}.hauth_return_to"    , $this->params["hauth_return_to"] );
		SocialIntegration_Auth::storage()->set( "hauth_session.{$this->id}.hauth_endpoint"     , $this->params["login_done"] ); 
		SocialIntegration_Auth::storage()->set( "hauth_session.{$this->id}.id_provider_params" , $this->params );
		// store config to be used by the end point 
		SocialIntegration_Auth::storage()->config( "CONFIG", SocialIntegration_Auth::$config );

		// move on
	//	SocialIntegration_Logger::debug( "SocialIntegration_Provider_Adapter::login( {$this->id} ), redirect the user to login_start URL." );

		SocialIntegration_Auth::redirect( $this->params["login_start"] );
	}

	// --------------------------------------------------------------------

	/**
	* let hybridauth forget all about the user for the current provider
	*/
	function logout()
	{
		$this->adapter->logout();
	}

	// --------------------------------------------------------------------

	/**
	* return true if the user is connected to the current provider
	*/ 
	public function isUserConnected()
	{
		return $this->adapter->isUserConnected();
	}

	// --------------------------------------------------------------------

	/**
	* handle :
	*   getUserProfile()
	*   getUserContacts()
	*   getUserActivity() 
	*   setUserStatus() 
	*/ 
	public function __call( $name, $arguments ) 
	{
		SocialIntegration_Logger::info( "Enter SocialIntegration_Provider_Adapter::$name(), Provider: {$this->id}" );

		if ( ! $this->isUserConnected() ){
			throw new Exception( "User not connected to the provider {$this->id}.", 7 );
		} 

		if ( ! method_exists( $this->adapter, $name ) ){
			throw new Exception( "Call to undefined function SocialIntegration_Providers_{$this->id}::$name()." );
		}

		if( count( $arguments ) ){
			return $this->adapter->$name( $arguments[0] ); 
		} 
		else{
			return $this->adapter->$name(); 
		}
	}

	// --------------------------------------------------------------------

	/**
	* If the user is connected, then return the access_token and access_token_secret
	* if the provider api use oauth
	*/
	public function getAccessToken()
	{
		if( ! $this->adapter->isUserConnected() ){
			SocialIntegration_Logger::error( "User not connected to the provider." );

			throw new Exception( "User not connected to the provider.", 7 );
		}

		return
			ARRAY(
				"access_token"        => $this->adapter->token( "access_token" )       , // OAuth access token
				"access_token_secret" => $this->adapter->token( "access_token_secret" ), // OAuth access token secret
				"refresh_token"       => $this->adapter->token( "refresh_token" )      , // OAuth refresh token
				"expires_in"          => $this->adapter->token( "expires_in" )         , // OPTIONAL. The duration in seconds of the access token lifetime
				"expires_at"          => $this->adapter->token( "expires_at" )         , // OPTIONAL. Timestamp when the access_token expire. if not provided by the social api, then it should be calculated: expires_at = now + expires_in
			);
	}

	// --------------------------------------------------------------------

	/**
	* Naive getter of the current connected IDp API client
	*/
	function api()
	{
		if( ! $this->adapter->isUserConnected() ){
			SocialIntegration_Logger::error( "User not connected to the provider." );

			throw new Exception( "User not connected to the provider.", 7 );
		}

		return $this->adapter->api;
	}

	// --------------------------------------------------------------------

	/**
	* redirect the user to hauth_return_to (the callback url)
	*/
	function returnToCallbackUrl()
	{ 
		// get the stored callback url
		$callback_url = SocialIntegration_Auth::storage()->get( "hauth_session.{$this->id}.hauth_return_to" );

		// remove some unneeded stored data
		SocialIntegration_Auth::storage()->delete( "hauth_session.{$this->id}.hauth_return_to"    );
		SocialIntegration_Auth::storage()->delete( "hauth_session.{$this->id}.hauth_endpoint"     );
		SocialIntegration_Auth::storage()->delete( "hauth_session.{$this->id}.id_provider_params" );

		// back to home
		SocialIntegration_Auth::redirect( $callback_url );
	}

	// --------------------------------------------------------------------

	/**
	* return the provider config by id
	*/
	function getConfigById( $id )
	{ 
		if( isset( SocialIntegration_Auth::$config["providers"][$id] ) ){
			return SocialIntegration_Auth::$config["providers"][$id];
		}

		return NULL;
	}

	// --------------------------------------------------------------------

	/**
	* return the provider config by id; insensitive
	*/
	function getProviderCiId( $id )
	{
		foreach( SocialIntegration_Auth::$config["providers"] as $idpid => $params ){
			if( strtolower( $idpid ) == strtolower( $id ) ){
				return $idpid;
			}
		}

		return NULL;
	}
}

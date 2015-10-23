<?php

// This class is not namespaced as simplesamlphp does not namespace its classes.

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drupalath authentication source for using Drupal's login page.
 *
 * Original author: SIL International, Steve Moitozo, <steve_moitozo@sil.org>, http://www.sil.org
 * Modified by: Brad Jones, <brad@bradjonesllc.com>, http://bradjonesllc.com
 *
 * This class is an authentication source which is designed to
 * more closely integrate with a Drupal site. It causes the user to be
 * delivered to Drupal's login page, if they are not already authenticated.
 *
 * Original source: http://code.google.com/p/drupalauth/
 *
 * !!! NOTE !!!
 *
 * You must configure store.type in config/config.php to be something
 * other than phpsession, or this module will not work. SQL and memcache
 * work just fine. The tell tail sign of the problem is infinite browser
 * redirection when the SimpleSAMLphp login page should be presented.
 *
 * -------------------------------------------------------------------
 *
 * To use this put something like this into config/authsources.php:
 *
 *  'drupal-userpass' => array(
 *    'drupalauth:External',
 *
 *    // Whether to turn on debug
 *    'debug' => true,
 *
 *    // the URL of the Drupal logout page
 *    'drupal_logout_url' => 'https://www.example.com/drupal7/user/logout',
 *
 *    // the URL of the Drupal login page
 *    'drupal_login_url' => 'https://www.example.com/drupal7/user',
 *
 *    // Which attributes should be retrieved from the Drupal site.
 *
 *              'attributes' => array(
 *                                    array('drupaluservar'   => 'uid',  'callit' => 'uid'),
 *                                     array('drupaluservar' => 'name', 'callit' => 'cn'),
 *                                     array('drupaluservar' => 'mail', 'callit' => 'mail'),
 *                                     array('drupaluservar' => 'field_first_name',  'callit' => 'givenName'),
 *                                     array('drupaluservar' => 'field_last_name',   'callit' => 'sn'),
 *                                     array('drupaluservar' => 'field_organization','callit' => 'ou'),
 *                                     array('drupaluservar' => 'roles','callit' => 'roles'),
 *                                   ),
 *  ),
 *
 * Format of the 'attributes' array explained:
 *
 * 'attributes' can be an associate array of attribute names, or NULL, in which case
 * all attributes are fetched.
 *
 * If you want everything (except) the password hash do this:
 *    'attributes' => NULL,
 *
 * If you want to pick and choose do it like this:
 * 'attributes' => array(
 *          array('drupaluservar' => 'uid',  'callit' => 'uid),
 *                     array('drupaluservar' => 'name', 'callit' => 'cn'),
 *                     array('drupaluservar' => 'mail', 'callit' => 'mail'),
 *                     array('drupaluservar' => 'roles','callit' => 'roles'),
 *                      ),
 *
 *  The value for 'drupaluservar' is the variable name for the attribute in the
 *  Drupal user object.
 *
 *  The value for 'callit' is the name you want the attribute to have when it's
 *  returned after authentication. You can use the same value in both or you can
 *  customize by putting something different in for 'callit'. For an example,
 *  look at the entry for name above.
 */
class sspmod_drupalauth_Auth_Source_External extends SimpleSAML_Auth_Source {

  /**
   * Whether to turn on debugging
   */
  private $debug;

  /**
   * The Drupal user attributes to use, NULL means use all available
   */
  private $attributes;

  /**
   * The name of the cookie
   */
  private $cookie_name;

  /**
   * The cookie path
   */
  private $cookie_path;

  /**
   * The cookie salt
   */
  private $cookie_salt;

  /**
   * The logout URL of the Drupal site
   */
  private $drupal_logout_url;

  /**
   * The login URL of the Drupal site
   */
  private $drupal_login_url;

  /**
   * Dependency injection container
   */
  private $container;

  /**
   * Bootstrap Drupal, e.g., if we're being called from simplesamlphp.
   * @see index.php
   */
  private function bootstrap() {
    try {
      $this->container = \Drupal::getContainer();
    }
    catch (Exception $e) {
      // If we're here, this script is being called from simplesamlphp, not Drupal.
      // We will find the autoloader, then, relative to that library.
      $pwd = getcwd();
      $autoloader = substr($pwd, 0, strpos($pwd, 'simplesamlphp/www')) . '../autoload.php';
      // Below is adapted from SSP's autoloader script.
      // SSP is loaded as a library.
      if (file_exists($autoloader)) {
        $classloader = require $autoloader;
        // @todo - Catch a failed import.
      }
      $request = Request::createFromGlobals();
      // The REQUEST_URI of the current request is meaningless. Override.
      // @see http://stackoverflow.com/a/22484670/4447064
      $request->server->set('REQUEST_URI', '/');
      $kernel = DrupalKernel::createFromRequest($request, $classloader, 'prod');
      // @todo - Early test on module enabled or not.

      // We change directories to the Drupal root, now that we can detect it.
      // Drupal may try to find/save files to paths relative to the root.
      // Must get it off $kernel as we can't get at the container, yet.
      chdir($kernel->getAppRoot());
      $kernel->handle($request);
      $user = \Drupal::currentUser();
      chdir($pwd);
      $this->container = $kernel->getContainer();
    }
  }

	/**
	 * Constructor for this authentication source.
	 *
	 * @param array $info  Information about this authentication source.
	 * @param array $config  Configuration.
	 */
	public function __construct($info, $config) {
    assert('is_array($info)');
    assert('is_array($config)');

    /* Call the parent constructor first, as required by the interface. */
    parent::__construct($info, $config);

    /* Get the configuration for this module */
    $drupalAuthConfig = new sspmod_drupalauth_ConfigHelper($config,
      'Authentication source ' . var_export($this->authId, TRUE));
    $this->debug       = $drupalAuthConfig->getDebug();
    $this->attributes  = $drupalAuthConfig->getAttributes();
    $this->cookie_name = $drupalAuthConfig->getCookieName();
    $this->drupal_logout_url = $drupalAuthConfig->getDrupalLogoutURL();
    $this->drupal_login_url = $drupalAuthConfig->getDrupalLoginURL();
    $ssp_config = SimpleSAML_Configuration::getInstance();
    $this->cookie_path = '/' . $ssp_config->getValue('baseurlpath');
    $this->cookie_salt = $ssp_config->getValue('secretsalt');

    $this->bootstrap();
  }


	/**
	 * Retrieve attributes for the user.
	 *
	 * @return array|NULL  The user's attributes, or NULL if the user isn't authenticated.
	 */
	private function getUser() {
    $user = $this->container->get('current_user')->getAccount();
    if ($user->id() != 0) {
      return array(
        'uid' => array($user->getUsername()),
        'displayName' => array($user->getDisplayName()),
      );
    }
    return NULL;
	}


	/**
	 * Log in using an external authentication helper.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public function authenticate(&$state) {
		assert('is_array($state)');

		if ($attributes = $this->getUser()) {
			/*
			 * The user is already authenticated.
			 *
			 * Add the users attributes to the $state-array, and return control
			 * to the authentication process.
			 */
			$state['Attributes'] = $attributes;
			return;
		}

		/*
		 * The user isn't authenticated. We therefore need to
		 * send the user to the login page.
		 */

		/*
		 * First we add the identifier of this authentication source
		 * to the state array, so that we know where to resume.
		 */
		$state['drupalauth:AuthID'] = $this->authId;

		/*
		 * We need to save the $state-array, so that we can resume the
		 * login process after authentication.
		 *
		 * Note the second parameter to the saveState-function. This is a
		 * unique identifier for where the state was saved, and must be used
		 * again when we retrieve the state.
		 *
		 * The reason for it is to prevent attacks where the user takes a
		 * $state-array saved in one location and restores it in another location,
		 * and thus bypasses steps in the authentication process.
		 */
		$stateId = SimpleSAML_Auth_State::saveState($state, 'drupalauth:External');

		/*
		 * Now we generate an URL the user should return to after authentication.
		 * We assume that whatever authentication page we send the user to has an
		 * option to return the user to a specific page afterwards.
		 *
		 * Drupal will not redirect to an external URL. So, build a relative one.
		 */
    $globalConfig = SimpleSAML_Configuration::getInstance();
    $baseURL = $globalConfig->getString('baseurlpath', 'simplesaml/');
    $returnTo = '/' . $baseURL . 'drupalauth/resume.php?State=' . $stateId;
		/*
		 * Get the URL of the authentication page.
		 *
		 * Here we use the getModuleURL function again, since the authentication page
		 * is also part of this module, but in a real example, this would likely be
		 * the absolute URL of the login page for the site.
		 */
    $login = \Drupal::url('user.login', array(), array('absolute' => TRUE));

		/*
		 * The redirect to the authentication page.
		 *
		 * Note the 'ReturnTo' parameter. This must most likely be replaced with
		 * the real name of the parameter for the login page.
		 */
		SimpleSAML_Utilities::redirectTrustedURL($login, array(
			'destination' => $returnTo,
		));

		/*
		 * The redirect function never returns, so we never get this far.
		 */
		assert('FALSE');
	}

	/**
	 * Resume authentication process.
	 *
	 * This function resumes the authentication process after the user has
	 * entered his or her credentials.
	 *
	 * @param array &$state  The authentication state.
	 */
	public static function resume() {

		/*
		 * First we need to restore the $state-array. We should have the identifier for
		 * it in the 'State' request parameter.
		 */
		if (!isset($_REQUEST['State'])) {
			throw new SimpleSAML_Error_BadRequest('Missing "State" parameter.');
		}
		$stateId = (string)$_REQUEST['State'];

		/*
		 * Once again, note the second parameter to the loadState function. This must
		 * match the string we used in the saveState-call above.
		 */
		$state = SimpleSAML_Auth_State::loadState($stateId, 'drupalauth:External');

		/*
		 * Now we have the $state-array, and can use it to locate the authentication
		 * source.
		 */
		$source = SimpleSAML_Auth_Source::getById($state['drupalauth:AuthID']);
		if ($source === NULL) {
			/*
			 * The only way this should fail is if we remove or rename the authentication source
			 * while the user is at the login page.
			 */
			throw new SimpleSAML_Error_Exception('Could not find authentication source with id ' . $state[self::AUTHID]);
		}

		/*
		 * Make sure that we haven't switched the source type while the
		 * user was at the authentication page. This can only happen if we
		 * change config/authsources.php while an user is logging in.
		 */
		if (! ($source instanceof self)) {
			throw new SimpleSAML_Error_Exception('Authentication source type changed.');
		}


		/*
		 * OK, now we know that our current state is sane. Time to actually log the user in.
		 *
		 * First we check that the user is acutally logged in, and didn't simply skip the login page.
		 */
		$attributes = $source->getUser();
		if ($attributes === NULL) {
			/*
			 * The user isn't authenticated.
			 *
			 * Here we simply throw an exception, but we could also redirect the user back to the
			 * login page.
			 */
			throw new SimpleSAML_Error_Exception('User not authenticated after login page.');
		}

		/*
		 * So, we have a valid user. Time to resume the authentication process where we
		 * paused it in the authenticate()-function above.
		 */

		$state['Attributes'] = $attributes;
		SimpleSAML_Auth_Source::completeAuth($state);

		/*
		 * The completeAuth-function never returns, so we never get this far.
		 */
		assert('FALSE');
	}


	/**
	 * This function is called when the user start a logout operation, for example
	 * by logging out of a SP that supports single logout.
	 *
	 * @param array &$state  The logout state array.
	 */
	public function logout(&$state) {
    assert('is_array($state)');

    if (!session_id()) {
      /* session_start not called before. Do it here. */
      session_start();
    }

    /*
     * In this example we simply remove the 'uid' from the session.
     */
    unset($_SESSION['uid']);

    // Added armor plating, just in case
    if (isset($_COOKIE[$this->cookie_name])) {
      setcookie($this->cookie_name, "", time() - 3600, $this->cookie_path);

    }


    /*
      * Redirect the user to the Drupal logout page
      */
    header('Location: ' . $this->drupal_logout_url);
    die;

  }

}

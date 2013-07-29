<?php
/**
 * WP_Instagram_Client - Library to help WordPress developer working with Instagram REST API.
 * Copyright (C) 2013 Akeda Bagus <admin@gedex.web.id>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class WP_Instagram_Client {

	/**
	 * This library version.
	 *
	 * @constant VERSION
	 */
	const VERSION = '0.1.0';

	/**
	 * Instagram API version.
	 *
	 * @constant INSTAGRAM_API_VERSION
	 */
	const INSTAGRAM_API_VERSION = 'v1';

	/**
	 * Instagram API domain.
	 *
	 * @constant INSTAGRAM_API_DOMAIN
	 */
	const INSTAGRAM_API_DOMAIN = 'api.instagram.com';

	/**
	 * Instagram API scheme.
	 *
	 * @constant INSTAGRAM_API_SCHEME
	 */
	const INSTAGRAM_API_SCHEME = 'https://';

	/**
	 * Settings that will be used to make a call to Instagram API.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Default settings/parameters.
	 *
	 * @var array
	 */
	protected $defaults;

	/**
	 * Constructor.
	 */
	public function __construct( $settings = array() ) {
		$this->defaults = array(
			'client_id'     => '', // App's client_id. Get it via http://instagram.com/developer/clients/manage/
			'client_secret' => '', // App's client_secret. Get it via http://instagram.com/developer/clients/manage/
			'redirect_uri'  => '', // App's redirect_uri.
			'access_token'  => '', // User's `access_token`. Used to make authenticated call
			'code'          => '', // Received after Instagram redirects to `redirect_uri`
			'state'         => '', // Like nonce, to protect against CSRF issues
			'grant_type'    => 'authorization_code', // Used in step #3, request `access_token`
		);

		$this->settings  = wp_parse_args( $settings, $this->defaults );
	}

	/**
	 * Get Instagram authorization URL.
	 *
	 * @param array $args Override `client_id` and/or `redirect_uri` from `$this->settings`.
	 *        There's `scope` (string with '+' separator) arg to define permission.
	 * @return string Instagram authorization URL
	 */
	public function get_authorize_url( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'client_id' => $this->settings['client_id'],
			'scope'     => 'basic',
			'state'     => wp_create_nonce(),
		) );

		return sprintf(
			self::INSTAGRAM_API_SCHEME . self::INSTAGRAM_API_DOMAIN . '/oauth/authorize?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&state=%s',
			$args['client_id'],
			$args['redirect_uri'],
			$args['scope'],
			$args['state']
		);
	}

	/**
	 * Setter method for settings/parameters. For example, to set `access_token`:
	 *
	 * ~~~
	 * $this->access_token = 'YOUR_ACCESS_TOKEN';
	 * ~~~
	 *
	 * @param string $param
	 * @param string $value
	 */
	public function __set( $param, $value ) {
		$this->settings[ $param ] = apply_filters( 'wp_instagram_client_set_' . $param, $value );
	}

	/**
	 * Getter method for settings/parameters. For example, to get `access_token`:
	 *
	 * ~~~
	 * $a = $this->access_token;
	 * ~~~
	 *
	 * @param string $name Parameter/setting name
	 * @return mixed May trigger `E_USER_NOTICE` if `$param` doesn't exist and null will be returned
	 */
	public function __get( $param ) {
		$value = null;
		if ( array_key_exists( $param, $this->settings ) ) {
			$value = $this->settings[ $param ];
		} else {
			if ( apply_filters( 'wp_instagram_client_get_undefined_param', true ) ) {
				$trace = debug_backtrace();
				trigger_error( sprintf('Undefined property %s in %s on line %d', $param, $trace[0]['file'], $trace[0]['line'] ) );
			}
		}

		return apply_filters( 'wp_instagram_client_get_' . $param, $value );
	}

	/**
	 * Check whether given $param exists in settings.
	 *
	 * @param string $param Parameter name
	 * @return bool True if $param exists in settings, otherwise false
	 */
	public function __isset( $param ) {
		return isset( $this->settings[ $param ] );
	}

	/**
	 * Unset given $param from settings.
	 *
	 * @param string $param Parameter name
	 */
	public function __unset( $param ) {
		unset( $this->settings[ $param ] );
	}

	/**
	 * Make an authorized request to the Instagram API. This method uses `wp_remote_request`.
	 *
	 * @param string $http_method GET|POST|DELETE
	 * @param string $endpoint Resources as seen on http://instagram.com/developer/endpoints
	 */
	public function request( $http_method, $endpoint, $args = array() ) {
		$defaults = array(
			'headers' => array(
				'User-Agent' => apply_filters( 'wp_instagram_client_user_agent', __CLASS__ . '/' . self::VERSION ),
				'Accept'     => 'application/json', // Always JSON
			),
			'body' => '',
			'version_in_url' => true,
			'parameters'     => array(),
		);

		extract( wp_parse_args( $args, $defaults ) );

		$base_url = self::INSTAGRAM_API_SCHEME . self::INSTAGRAM_API_DOMAIN;

		// The oauth/* endpoints don't have /INSTAGRAM_API_VERSION/ appears in url
		if ( $version_in_url && strpos( $endpoint, 'oauth' ) === false )
			$base_url .= '/' . self::INSTAGRAM_API_VERSION;

		$base_url .= '/' . $endpoint;

		// Can be useful in case someone accessing parameters/settings via getter method
		$this->settings['base_url'] = $base_url;

		$http_method = strtoupper( $http_method );
		if ( ! in_array( $http_method, array('GET', 'POST', 'DELETE', 'PUT') ) )
			$http_method = 'GET';
		// Can be useful in case someone accessing parameters/settings via getter method
		$this->settings['http_method'] = $http_method;

		// Merge with passed parameters
		$this->settings = wp_parse_args( $parameters, $this->settings );

		// Remove empty parameters
		foreach ( $this->settings as $key => $val ) {
			if ( ! $val )
				unset( $this->settings[ $key ] );
		}

		// Make sure unecessary parameters removed before building query string
		switch( $endpoint ) {
			case 'oauth/access_token':
				error_log( 'Before intersect: ' . print_r( $this->settings, true ) );
				// Only client_id, client_secret, grant_type, redirect_uri, and code is expected to appear
			  // when calling 'oauth/access_token' endpoint
				$this->settings = array_intersect_key( $this->settings , array(
					'client_id'     => '',
					'client_secret' => '',
					'grant_type'    => '',
					'redirect_uri'  => '',
					'code'          => 'authorization_code',
				));
				error_log( 'After intersect: ' . print_r( $this->settings, true ) );
				break;
			default:
			  // Remove code, state, and grant_type when make a call to regular endpoints
				$this->settings = array_diff_key( $this->settings, array(
					'code'       => '',
					'state'      => '',
					'grant_type' => '',
				) );
				break;
		}

		$url = $base_url;
		switch ( $http_method ) {
			case 'POST':
				$body = wp_parse_args( $body, $this->settings );
				break;
			default:
				// Build query string from paramters and append it to base_url
				$params = array();
				foreach ( $this->settings as $key => $val ) {
					$params[] = $key . '=' . $val;
				}
				if ( ! empty( $params ) )
					$url .= '?' . implode( '&', $params );
		}

		return wp_remote_request( $url, array(
			'method'  => $http_method,
			'headers' => $headers,
			'body'    => $body,
		) );
	}
}

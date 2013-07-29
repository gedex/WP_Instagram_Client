<?php
/**
 * Example to demostrate authorization using WP_Instagram_Client.
 * This demo will add sub menu page to the Settings menu where it renders
 * a button to authorize the app. Once authorized, you should be able
 * to see the instagram `username` and `profile_picture`.
 *
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

// Adjust the path to where `class-wp-instagram-client.php` resides.
require_once( STYLESHEETPATH . '/WP_Instagram_Client/class-wp-instagram-client.php' );

// If you get timeout error try increasing the timeout below.
add_filter( 'http_request_timeout', function() {
	return 15;
} );

// Go to http://instagram.com/developer/clients/manage/ to create an app
// Update following CLIENT_ID and CLIENT_SECRET with yours
define( 'CLIENT_ID',     '30d4ddbd926d4e1193bdd9f89841949d' );
define( 'CLIENT_SECRET', '4afd034cca2f40a0975fc93523115634' );

class Example_Auth_WP_Instagram_Client {
	/**
	 * Option's name to store access token.
	 *
	 * @constant TOKEN_OPTION
	 */
	const TOKEN_OPTION = 'wp_instagram_client_access_token';

	/**
	 * Option's name to store temporary error message.
	 *
	 * @constant ERROR_OPTION
	 */
	const ERROR_OPTION = 'wp_instagram_client_error';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->page_title = __( 'Example Auth WP_Instagram_Client Page', 'theme-domain' );
		$this->menu_title = __( 'Example Auth WP_Instagram_Client', 'theme-domain' );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// Save WP_Instagram_Client instance
		$this->instagram = new WP_Instagram_Client( array(
			'client_id'     => CLIENT_ID,
			'client_secret' => CLIENT_SECRET,
			'redirect_uri'  => admin_url( 'options-general.php?page=' . __CLASS__ ),
		) );

		// URL to current setting page.
		$this->redirect_uri = admin_url( 'options-general.php?page=' . __CLASS__ );

		$this->profile = null;
	}

	/**
	 * Add sub menu page to the Settings menu and action when the page loads.
	 */
	public function admin_menu() {
		$this->admin_page = add_options_page( $this->page_title, $this->menu_title, 'manage_options', __CLASS__, array( $this, 'settings_page' ) );

		add_action( 'load-' . $this->admin_page, array( $this, 'on_this_page_load' ) );
	}

	/**
	 * Does the following things when this page loads:
	 * 1. Perform Server-side flow to get `access_token`
	 * 2. Once access token is retrieved make a call to `users/self`.
	 * 3. Check if there's an error to be shown.
	 */
	public function on_this_page_load() {
		$screen = get_current_screen();

		if ( $this->admin_page !== $screen->id )
			return;

		$url_to_redirect = $this->redirect_uri;

		// Check if Instagram is redirecting back.
		if ( isset( $_REQUEST['code'] ) && isset( $_REQUEST['state'] ) ) {
			// Verify the state
			if ( ! wp_verify_nonce( $_REQUEST['state'] ) ) {
				$error_message = __( 'Failed to verify state', 'theme-domain' );
				delete_option( self::TOKEN_OPTION );
				update_option( self::ERROR_OPTION, $error_message );
				$url_to_redirect = add_query_arg( 'got_error', true, $url_to_redirect );
				wp_redirect( $url_to_redirect );
				exit();
			}

			$this->instagram->code = $_REQUEST['code'];
			$resp = $this->instagram->request( 'POST', 'oauth/access_token' );

			if ( wp_remote_retrieve_response_code( $resp ) == 200 ) {
				wp_parse_str( wp_remote_retrieve_body( $resp ), &$token );
				$token = json_decode( $token );
				error_log( $token, true );
				update_option( self::TOKEN_OPTION, $token->access_token );
			} else if ( ! isset( $_REQUEST['got_error'] ) ) { // To avoid endless redirection
				if ( is_wp_error( $resp ) || ! isset( $resp['response'] ) ) {
					// Error during request
					$error_message = 'WP HTTP Error: ' . $resp->get_error_message();
					delete_option( self::TOKEN_OPTION );
					update_option( self::ERROR_OPTION, $error_message );
					$url_to_redirect = add_query_arg( 'got_error', true, $url_to_redirect );
				} else {
					// Unexpected response
					$error_message = sprintf(
						'<strong>%s</strong> %s',
						sprintf( __( 'Status code %s: ', 'theme-domain' ), wp_remote_retrieve_response_code( $resp ) ),
						wp_remote_retrieve_body( $resp )
					);
					delete_option( self::TOKEN_OPTION );
					delete_option( self::ERROR_OPTION );
					$url_to_redirect = add_query_arg( 'got_error', true, $url_to_redirect );
				}
			}

			wp_redirect( $url_to_redirect );
			exit();
		}

		// Check if we have access token
		$token = get_option( self::TOKEN_OPTION );
		if ( $token ) {
			$this->instagram->access_token = $token;

			// Lets use the access_token to call 'users/self'
			$resp = $this->instagram->request( 'GET', 'users/self' );

			if ( wp_remote_retrieve_response_code( $resp ) == 200 ) {
				$this->profile = json_decode( wp_remote_retrieve_body( $resp ) );
			} else {
				$error_message = sprintf(
					'<strong>%s</strong> %s',
					sprintf( __( 'Status code %s: ', 'theme-domain' ), wp_remote_retrieve_response_code( $resp ) ),
					wp_remote_retrieve_body( $resp )
				);
				delete_option( self::TOKEN_OPTION );
				update_option( self::ERROR_OPTION, $error_message );
				$url_to_redirect = add_query_arg( 'got_error', true, $url_to_redirect );
				wp_redirect( $url_to_redirect );
				exit();
			}
		}
	}

	/**
	 * Output admin notices.
	 */
	public function output_admin_notices() {
		$screen = get_current_screen();

		if ( $this->admin_page != $screen->id )
			return;

		$message = get_option( self::ERROR_OPTION );
		if ( $message ) {
			printf( '<div class="error fade"><p>%s</p></div>', $message );
		}
		delete_option( self::ERROR_OPTION );
	}

	/**
	 * Render the setting page.
	 */
	public function settings_page() {
		$remove_token_url = add_query_arg( 'remove_tokens', true );
		?>
		<div class="wrap">
			<h2><?php echo esc_html( $this->page_title ); ?></h2>

			<?php if ( $this->profile ): ?>
			<?php print_r( $this->profile ); ?>
			<!--
			<p class="column-links"><?php echo esc_html( sprintf( __('Hola %s!', 'theme-domain'), $this->profile['screen_name'] ) ); ?></p>
			-->
			<?php endif; ?>

			<p class="column-links">
				<a href="<?php echo esc_url( $this->instagram->get_authorize_url( array('redirect_uri' => $this->redirect_uri) ) ) ?>" class="button"><?php _e('Authorize with Instagram', 'theme-domain'); ?></a>
			</p>
		</div>
		<!-- / wrap -->
		<?php
	}
}
new Example_Auth_WP_Instagram_Client();

<?php

// The two places a person sees this sign-in in wp-admin:
//
//   Theme Settings > Miscellaneous > MCP Access   the on/off switch and a
//                                                 status line saying whether
//                                                 it is working, and if not why
//                                                 (including "handled by the
//                                                 MCP Adapter" once it has its own)
//   Users > Profile > Connected AI Apps           every app connected as that
//                                                 user, with Disconnect

// The status line is worked out when the settings page is shown, not when the
// field is registered: the other-provider check needs the REST routes.
add_filter( 'acf/load_field/key=field_devbase_mcp_oauth_status', function ( $field ) {
	if ( ! is_admin() || wp_doing_ajax() ) {
		return $field;
	}

	if ( ! did_action( 'rest_api_init' ) ) {
		rest_get_server();
	}

	$reason = devbase_mcp_oauth_inactive_reason();

	if ( '' === $reason ) {
		$field['message'] = sprintf(
			/* translators: %s: the MCP address */
			__( 'On. In Claude (Settings > Connectors > Add custom connector) or ChatGPT, enter %s and leave the OAuth fields empty. The app sends you here to log in and approve. Application Passwords keep working as before.', 'devbase_parent_theme' ),
			'<code>' . esc_html( devbase_mcp_oauth_resource() ) . '</code>'
		);
	} else {
		$field['message'] = esc_html( $reason );
	}

	return $field;
} );

// ── Profile: Connected AI Apps ───────────────────────────────────────────

function devbase_mcp_oauth_profile_section( $user ) {
	$grants = devbase_mcp_oauth_grants( $user->ID );

	if ( ! $grants && ! devbase_mcp_oauth_active() ) {
		return;
	}
	?>
	<h2 id="devbase-mcp-apps"><?php esc_html_e( 'Connected AI Apps', 'devbase_parent_theme' ); ?></h2>
	<p class="description"><?php esc_html_e( 'AI apps (such as Claude or ChatGPT) you allowed to read and change this site as you. Disconnecting one stops it at once. Application Passwords are a separate list on this page.', 'devbase_parent_theme' ); ?></p>
	<?php if ( ! $grants ) : ?>
		<p><?php esc_html_e( 'None connected.', 'devbase_parent_theme' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:720px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'App', 'devbase_parent_theme' ); ?></th>
					<th><?php esc_html_e( 'Connected', 'devbase_parent_theme' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'devbase_parent_theme' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $grants as $grant_id => $grant ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $grant['client_name'] ); ?></strong><br><span class="description"><?php echo esc_html( $grant['redirect_host'] ); ?></span></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $grant['created'] ) ); ?></td>
						<td>
							<?php
							/* translators: %s: time since */
							echo esc_html( sprintf( __( '%s ago', 'devbase_parent_theme' ), human_time_diff( (int) $grant['last_used'] ) ) );
							?>
						</td>
						<td>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'devbase_mcp_disconnect', 'user_id' => $user->ID, 'grant' => $grant_id ), admin_url( 'admin-post.php' ) ), 'devbase_mcp_disconnect_' . $grant_id ) ); ?>"><?php esc_html_e( 'Disconnect', 'devbase_parent_theme' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

add_action( 'show_user_profile', 'devbase_mcp_oauth_profile_section' );
add_action( 'edit_user_profile', 'devbase_mcp_oauth_profile_section' );

add_action( 'admin_post_devbase_mcp_disconnect', function () {
	$user_id  = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
	$grant_id = isset( $_GET['grant'] ) ? sanitize_key( wp_unslash( $_GET['grant'] ) ) : '';

	check_admin_referer( 'devbase_mcp_disconnect_' . $grant_id );

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( esc_html__( 'You can\'t change this account.', 'devbase_parent_theme' ), '', array( 'response' => 403 ) );
	}

	devbase_mcp_oauth_revoke_grant( $user_id, $grant_id );

	$back = get_current_user_id() === $user_id ? admin_url( 'profile.php' ) : add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );

	wp_safe_redirect( $back . '#devbase-mcp-apps' );
	exit;
} );

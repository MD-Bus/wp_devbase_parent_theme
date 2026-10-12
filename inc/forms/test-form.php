<?php

// Proof-of-concept test form for the Form Data / Registered Form /
// Destinations system above — exercises the whole flow end to end without
// requiring any WP page or theme template to exist yet (this theme has no
// header/footer template built yet). Visit any site URL with
// ?devbase_test_form=1 to see it. Trivially removable later: delete this
// file and its functions.php require, nothing else depends on it.
//
// Registered under the name 'devbase_test_form' in registered-forms.json,
// so it shows up under Registered Forms automatically.

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['devbase_test_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$submitted = isset( $_GET['submitted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$blocked   = isset( $_GET['blocked'] );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	header( 'Content-Type: text/html; charset=utf-8' );
	?>
	<!doctype html>
	<html>
	<head><meta charset="utf-8"><title>Dev Base Test Form</title></head>
	<body style="font-family:sans-serif;max-width:480px;margin:40px auto;">
		<h1>Dev Base Test Form</h1>

		<?php if ( $submitted ) : ?>
			<p style="color:green;">Submitted — check Form Data in wp-admin, and your configured destination(s).</p>
		<?php elseif ( $blocked ) : ?>
			<p style="color:red;">Blocked (invalid/missing security nonce, or CAPTCHA verification failed).</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="devbase_test_form_submit">
			<?php devbase_form_nonce_field( 'devbase_test_form' ); ?>

			<p>
				<label for="btf-name">Name</label><br>
				<input type="text" id="btf-name" name="name" aria-label="Name" required style="width:100%;">
			</p>
			<p>
				<label for="btf-email">Email</label><br>
				<input type="email" id="btf-email" name="email" aria-label="Email Address" required style="width:100%;">
			</p>
			<p>
				<label for="btf-message">Message</label><br>
				<textarea id="btf-message" name="message" aria-label="Message" required style="width:100%;" rows="4"></textarea>
			</p>

			<?php echo devbase_security_widget_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<p><button type="submit">Submit</button></p>
		</form>
	</body>
	</html>
	<?php
	exit;
} );

foreach ( array( 'admin_post_devbase_test_form_submit', 'admin_post_nopriv_devbase_test_form_submit' ) as $hook ) {
	add_action( $hook, function () {
		// No manual nonce check here — devbase_form_log_submission() below
		// verifies the devbase_form_nonce_field() rendered in the form
		// automatically and returns false if it's missing/invalid, same as
		// any other block reason (routes to blocked=1 below).
		$content = devbase_form_build_content( $_POST, array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'name'    => 'Name',
			'email'   => array( 'label' => 'Email Address', 'sanitize' => 'sanitize_email' ),
			'message' => array( 'label' => 'Message', 'sanitize' => 'sanitize_textarea_field' ),
		) );

		$result = devbase_form_log_submission( 'devbase_test_form', $content );

		$redirect_args = $result ? array( 'devbase_test_form' => 1, 'submitted' => 1 ) : array( 'devbase_test_form' => 1, 'blocked' => 1 );

		wp_safe_redirect( add_query_arg( $redirect_args, home_url( '/' ) ) );
		exit;
	} );
}

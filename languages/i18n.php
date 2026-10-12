<?php

// Loads translation files for this theme's text domain (declared in
// style.css) from the languages/ directory. Required for any __()/_e()
// call in this theme to actually pick up a translation — without this,
// wrapping a string in __() has no effect even if a .mo file exists.
add_action( 'after_setup_theme', function () {
	load_theme_textdomain( 'devbase_parent_theme', get_template_directory() . '/languages' );
} );

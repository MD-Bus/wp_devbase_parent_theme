<?php

namespace ASENHA\Classes;

/**
 * Class for Redirect 404 module
 *
 * @since 6.9.5
 */
class Redirect_Fourofour {
    /**
     * Redirect 404 to homepage
     *
     * @since 1.7.0
     */
    public function redirect_404() {
        if ( !is_404() || is_admin() || defined( 'DOING_CRON' ) && DOING_CRON || defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return;
        } elseif ( is_404() ) {
            // RFC 8615 reserves /.well-known/ for machine-readable site metadata.
            // A missing file must stay a real 404 so clients do not parse homepage HTML.
            if ( $this->is_well_known_request() ) {
                return;
            }
            $redirect_url = site_url();
            header( 'HTTP/1.1 301 Moved Permanently' );
            header( 'Location: ' . sanitize_url( $redirect_url ) );
            exit;
        } else {
        }
    }

    /**
     * Whether the current request targets the /.well-known/ URI prefix.
     *
     * RFC 8615 reserves this prefix for machine-readable site metadata such as
     * security.txt, assetlinks.json, apple-app-site-association, and
     * ai-catalog.json. Redirecting a missing file to HTML makes those clients
     * fail. The prefix is matched anywhere in the path so a subdirectory
     * install such as /blog/.well-known/ is covered. The character after the
     * prefix must be a slash, or the path must end there, so a lookalike such
     * as /.well-known-extra is still redirected.
     *
     * @since 9.2.1
     *
     * @return bool
     */
    public function is_well_known_request() {
        $request_uri_raw = ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        $request_uri_parts = explode( '?', $request_uri_raw );
        $request_path = $request_uri_parts[0];
        if ( '' === $request_path ) {
            return false;
        }
        $well_known = '/.well-known';
        $position = strpos( $request_path, $well_known );
        if ( false === $position ) {
            return false;
        }
        $remainder = substr( $request_path, $position + strlen( $well_known ) );
        return '' === $remainder || isset( $remainder[0] ) && '/' === $remainder[0];
    }

}

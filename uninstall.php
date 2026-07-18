<?php
/**
 * Uninstall: remove the normalization rules stored in form meta.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! class_exists( 'GFAPI' ) ) {
	// Gravity Forms is not loaded: nothing accessible to clean up.
	return;
}

$gfen_slug = 'entry-normalizer-for-gravity-forms';

foreach ( array( true, false ) as $gfen_active ) {
	$gfen_forms = GFAPI::get_forms( $gfen_active );
	if ( ! is_array( $gfen_forms ) ) {
		continue;
	}
	foreach ( $gfen_forms as $gfen_form ) {
		if ( isset( $gfen_form[ $gfen_slug ] ) ) {
			unset( $gfen_form[ $gfen_slug ] );
			GFAPI::update_form( $gfen_form );
		}
	}
}

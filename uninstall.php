<?php
/**
 * Uninstall cleanup for Krynox Captcha.
 *
 * @package KrynoxCaptcha
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'krynox_captcha_options' );

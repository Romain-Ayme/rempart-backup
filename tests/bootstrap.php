<?php
/**
 * Loads the plugin's helpers and classes for the unit tests, without WordPress.
 * The few WordPress functions the tested code calls are provided here.
 */
require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}

if (!function_exists('number_format_i18n')) {
	function number_format_i18n($number, $decimals = 0) {
		return number_format((float) $number, $decimals, ',', ' ');
	}
}

// No translation in the unit tests: the texts stay as written in the code (in French).
if (!function_exists('__')) {
	function __($text, $domain = '') {
		return $text;
	}
	function _x($text, $context, $domain = '') {
		return $text;
	}
	function esc_html__($text, $domain = '') {
		return $text;
	}
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/class-tar.php';

<?php
/**
 * Plugin Name: Backup Guard
 * Description: Sauvegarde, restauration, migration et clonage de WordPress. Toutes les fonctions sont incluses, sans licence : planification, rotation, FTP, FTPS, SFTP, WebDAV, Amazon S3 et compatibles, Google Drive, OneDrive, Dropbox, pCloud et WP-CLI.
 * Version: 1.0.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Karom Studio - Romain AYME
 * Author URI: https://karom.studio
 * License: LGPL v2.1
 * License URI: http://www.gnu.org/licenses/lgpl-2.1.html
 * Text Domain: backup-guard
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

define('BACKUP_GUARD_VERSION', '1.0.0');
define('BACKUP_GUARD_FILE', __FILE__);
define('BACKUP_GUARD_DIR', __DIR__);
define('BACKUP_GUARD_URL', plugins_url('', __FILE__));
define('BACKUP_GUARD_ROOT', str_replace('\\', '/', WP_CONTENT_DIR) . '/backup-guard/');

/** Seconds one slice of work may run before it hands over to the next one. */
define('BACKUP_GUARD_SLICE_SECONDS', 25);
/** Stop starting new work this many seconds before the slice ends. */
define('BACKUP_GUARD_SLICE_MARGIN', 5);
/** A job with no sign of life for this long is considered dead. */
define('BACKUP_GUARD_TIMEOUT_SECONDS', 300);
/** A running job with no progress for this long gets pushed again. */
define('BACKUP_GUARD_STALL_SECONDS', 45);
/** How many times a stuck job is pushed before it is declared failed. */
define('BACKUP_GUARD_MAX_STALLS', 6);
/** Uncompressed bytes per gzip member of the archive. */
define('BACKUP_GUARD_GZ_MEMBER_BYTES', 8388608);
/** Backups shown per history page. */
define('BACKUP_GUARD_HISTORY_PER_PAGE', 20);
/** Longest backup note, in characters. */
define('BACKUP_GUARD_NOTE_MAX', 80);
/** Chunk size for backup files uploaded from the browser. */
define('BACKUP_GUARD_UPLOAD_CHUNK', 2097152);
/** A week without a backup, and a week since the reminder was last dismissed, brings it back. */
define('BACKUP_GUARD_NAG_SECONDS', 604800);
/** The first reminder's clock starts this far back, so it shows one day after installing. */
define('BACKUP_GUARD_NAG_FIRST_OFFSET', 518400);
/** A backup this recent satisfies the "backup before update" setting. */
define('BACKUP_GUARD_PREUPDATE_FRESH_SECONDS', 21600);

require_once BACKUP_GUARD_DIR . '/includes/helpers.php';
require_once BACKUP_GUARD_DIR . '/includes/class-tar.php';
require_once BACKUP_GUARD_DIR . '/includes/class-database.php';
require_once BACKUP_GUARD_DIR . '/includes/storage/class-storage.php';
require_once BACKUP_GUARD_DIR . '/includes/class-locations.php';
require_once BACKUP_GUARD_DIR . '/includes/class-backups.php';
require_once BACKUP_GUARD_DIR . '/includes/class-notifications.php';
require_once BACKUP_GUARD_DIR . '/includes/class-litespeed.php';
require_once BACKUP_GUARD_DIR . '/includes/class-job.php';
require_once BACKUP_GUARD_DIR . '/includes/class-restore.php';
require_once BACKUP_GUARD_DIR . '/includes/class-schedule.php';
require_once BACKUP_GUARD_DIR . '/includes/class-updates.php';

/**
 * The plugin is written in French. Sites in French get the texts as they are; every other language
 * gets the English translation (languages/backup-guard-en_US.mo), whatever the English variant.
 * The file is registered for the site's own locale, which is what WordPress looks translations up by.
 */
add_action('plugins_loaded', 'backup_guard_load_translations', 0);
add_action('change_locale', 'backup_guard_load_translations');
function backup_guard_load_translations() {
	unload_textdomain('backup-guard');
	$locale = determine_locale();
	if (strpos((string) $locale, 'fr') === 0) {
		return;
	}
	load_textdomain('backup-guard', BACKUP_GUARD_DIR . '/languages/backup-guard-en_US.mo', $locale);
}

register_activation_hook(__FILE__, 'backup_guard_activate');
register_deactivation_hook(__FILE__, 'backup_guard_deactivate');

function backup_guard_activate() {
	backup_guard_prepare_folders();
	if (!get_option('backup_guard_settings')) {
		update_option('backup_guard_settings', backup_guard_default_settings(), false);
	}
	if (!get_option('backup_guard_cron_key')) {
		update_option('backup_guard_cron_key', wp_generate_password(40, false), false);
	}
	Backup_Guard_Schedule::sync();
	if (!wp_next_scheduled('backup_guard_daily_cleanup')) {
		wp_schedule_event(time() + 3600, 'backup_guard_daily', 'backup_guard_daily_cleanup');
	}
	if (!wp_next_scheduled('backup_guard_watchdog')) {
		wp_schedule_event(time() + 300, 'backup_guard_five_minutes', 'backup_guard_watchdog');
	}
}

function backup_guard_deactivate() {
	wp_clear_scheduled_hook('backup_guard_auto_backup');
	wp_clear_scheduled_hook('backup_guard_daily_cleanup');
	wp_clear_scheduled_hook('backup_guard_watchdog');
}

add_filter('cron_schedules', array('Backup_Guard_Schedule', 'register_intervals'));
add_action('backup_guard_auto_backup', array('Backup_Guard_Schedule', 'run_auto_backup'));
add_action('backup_guard_watchdog', array('Backup_Guard_Job', 'watchdog'));
add_action('backup_guard_daily_cleanup', 'backup_guard_cleanup_leftovers');
add_action('init', array('Backup_Guard_Schedule', 'handle_custom_cron'));

// The slice endpoint answers to anyone holding the job's secret token, because
// the loopback call that drives a job carries no login.
add_action('wp_ajax_backup_guard_slice', array('Backup_Guard_Job', 'ajax_slice'));
add_action('wp_ajax_nopriv_backup_guard_slice', array('Backup_Guard_Job', 'ajax_slice'));

Backup_Guard_Updates::init();

if (is_admin()) {
	require_once BACKUP_GUARD_DIR . '/includes/class-admin.php';
	require_once BACKUP_GUARD_DIR . '/includes/class-ajax.php';
	Backup_Guard_Admin::init();
	Backup_Guard_Ajax::init();
}

if (defined('WP_CLI') && WP_CLI) {
	require_once BACKUP_GUARD_DIR . '/includes/class-cli.php';
	WP_CLI::add_command('backup-guard', 'Backup_Guard_CLI');
}

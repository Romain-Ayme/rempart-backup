<?php
/**
 * Plugin Name: Rempart Backup
 * Description: Sauvegarde, restauration, migration et clonage de WordPress. Toutes les fonctions sont incluses, sans licence : planification, rotation, FTP, FTPS, SFTP, WebDAV, Amazon S3 et compatibles, Google Drive, OneDrive, Dropbox, pCloud et WP-CLI.
 * Version: 1.0.0
 * Requires at least: 5.2
 * Requires PHP: 7.4
 * Author: Rempart Backup
 * License: LGPL v2.1
 * License URI: http://www.gnu.org/licenses/lgpl-2.1.html
 * Text Domain: rempart-backup
 */

if (!defined('ABSPATH')) {
	exit;
}

define('REMPART_VERSION', '1.0.0');
define('REMPART_FILE', __FILE__);
define('REMPART_DIR', __DIR__);
define('REMPART_URL', plugins_url('', __FILE__));
define('REMPART_ROOT', str_replace('\\', '/', WP_CONTENT_DIR) . '/rempart/');

/** Seconds one slice of work may run before it hands over to the next one. */
define('REMPART_SLICE_SECONDS', 25);
/** Stop starting new work this many seconds before the slice ends. */
define('REMPART_SLICE_MARGIN', 5);
/** A job with no sign of life for this long is considered dead. */
define('REMPART_TIMEOUT_SECONDS', 300);
/** A running job with no progress for this long gets pushed again. */
define('REMPART_STALL_SECONDS', 45);
/** How many times a stuck job is pushed before it is declared failed. */
define('REMPART_MAX_STALLS', 6);
/** Uncompressed bytes per gzip member of the archive. */
define('REMPART_GZ_MEMBER_BYTES', 8388608);
/** Backups shown per history page. */
define('REMPART_HISTORY_PER_PAGE', 20);
/** Longest backup note, in characters. */
define('REMPART_NOTE_MAX', 80);
/** Chunk size for backup files uploaded from the browser. */
define('REMPART_UPLOAD_CHUNK', 2097152);
/** A week without a backup, and a week since the reminder was last dismissed, brings it back. */
define('REMPART_NAG_SECONDS', 604800);
/** The first reminder's clock starts this far back, so it shows one day after installing. */
define('REMPART_NAG_FIRST_OFFSET', 518400);
/** A backup this recent satisfies the "backup before update" setting. */
define('REMPART_PREUPDATE_FRESH_SECONDS', 21600);

require_once REMPART_DIR . '/includes/helpers.php';
require_once REMPART_DIR . '/includes/class-tar.php';
require_once REMPART_DIR . '/includes/class-database.php';
require_once REMPART_DIR . '/includes/storage/class-storage.php';
require_once REMPART_DIR . '/includes/class-locations.php';
require_once REMPART_DIR . '/includes/class-backups.php';
require_once REMPART_DIR . '/includes/class-notifications.php';
require_once REMPART_DIR . '/includes/class-litespeed.php';
require_once REMPART_DIR . '/includes/class-job.php';
require_once REMPART_DIR . '/includes/class-restore.php';
require_once REMPART_DIR . '/includes/class-schedule.php';
require_once REMPART_DIR . '/includes/class-updates.php';

register_activation_hook(__FILE__, 'rempart_activate');
register_deactivation_hook(__FILE__, 'rempart_deactivate');

function rempart_activate() {
	rempart_prepare_folders();
	if (!get_option('rempart_settings')) {
		update_option('rempart_settings', rempart_default_settings(), false);
	}
	if (!get_option('rempart_cron_key')) {
		update_option('rempart_cron_key', wp_generate_password(40, false), false);
	}
	Rempart_Schedule::sync();
	if (!wp_next_scheduled('rempart_daily_cleanup')) {
		wp_schedule_event(time() + 3600, 'rempart_daily', 'rempart_daily_cleanup');
	}
	if (!wp_next_scheduled('rempart_watchdog')) {
		wp_schedule_event(time() + 300, 'rempart_five_minutes', 'rempart_watchdog');
	}
}

function rempart_deactivate() {
	wp_clear_scheduled_hook('rempart_auto_backup');
	wp_clear_scheduled_hook('rempart_daily_cleanup');
	wp_clear_scheduled_hook('rempart_watchdog');
}

add_filter('cron_schedules', array('Rempart_Schedule', 'register_intervals'));
add_action('rempart_auto_backup', array('Rempart_Schedule', 'run_auto_backup'));
add_action('rempart_watchdog', array('Rempart_Job', 'watchdog'));
add_action('rempart_daily_cleanup', 'rempart_cleanup_leftovers');
add_action('init', array('Rempart_Schedule', 'handle_custom_cron'));

// The slice endpoint answers to anyone holding the job's secret token, because
// the loopback call that drives a job carries no login.
add_action('wp_ajax_rempart_slice', array('Rempart_Job', 'ajax_slice'));
add_action('wp_ajax_nopriv_rempart_slice', array('Rempart_Job', 'ajax_slice'));

Rempart_Updates::init();

if (is_admin()) {
	require_once REMPART_DIR . '/includes/class-admin.php';
	require_once REMPART_DIR . '/includes/class-ajax.php';
	Rempart_Admin::init();
	Rempart_Ajax::init();
}

if (defined('WP_CLI') && WP_CLI) {
	require_once REMPART_DIR . '/includes/class-cli.php';
	WP_CLI::add_command('rempart', 'Rempart_CLI');
}

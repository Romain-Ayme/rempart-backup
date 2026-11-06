<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Settings go. The backups themselves stay in wp-content/rempart: deleting a plugin
// should never silently destroy the copies someone may still need.
foreach (array('rempart_settings', 'rempart_excludes', 'rempart_locations', 'rempart_channels', 'rempart_cron_key', 'rempart_last_backup', 'rempart_last_restore', 'rempart_nag_time') as $option) {
	delete_option($option);
}
wp_clear_scheduled_hook('rempart_auto_backup');
wp_clear_scheduled_hook('rempart_daily_cleanup');
wp_clear_scheduled_hook('rempart_watchdog');

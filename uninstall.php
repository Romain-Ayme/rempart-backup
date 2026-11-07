<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Settings go. The backups themselves stay in wp-content/backup-guard: deleting a plugin
// should never silently destroy the copies someone may still need.
foreach (array('backup_guard_settings', 'backup_guard_excludes', 'backup_guard_locations', 'backup_guard_channels', 'backup_guard_email_migrated', 'backup_guard_cron_key', 'backup_guard_last_backup', 'backup_guard_last_restore', 'backup_guard_nag_time') as $option) {
	delete_option($option);
}
wp_clear_scheduled_hook('backup_guard_auto_backup');
wp_clear_scheduled_hook('backup_guard_daily_cleanup');
wp_clear_scheduled_hook('backup_guard_watchdog');

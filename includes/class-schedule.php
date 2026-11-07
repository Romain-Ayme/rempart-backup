<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Automatic backups: WP-Cron schedules, or a URL for a real cron job. */
class Backup_Guard_Schedule {
	public static function intervals() {
		return array(
			'backup_guard_thirty_minutes' => array('interval' => 1800, 'display' => __('Toutes les 30 minutes', 'backup-guard')),
			'backup_guard_one_hour'       => array('interval' => 3600, 'display' => __('Toutes les heures', 'backup-guard')),
			'backup_guard_two_hours'      => array('interval' => 7200, 'display' => __('Toutes les 2 heures', 'backup-guard')),
			'backup_guard_daily'          => array('interval' => 86400, 'display' => __('Une fois par jour', 'backup-guard')),
			'backup_guard_weekly'         => array('interval' => 604800, 'display' => __('Une fois par semaine', 'backup-guard')),
			'backup_guard_monthly'        => array('interval' => 2635200, 'display' => __('Une fois par mois', 'backup-guard')),
		);
	}

	public static function register_intervals($schedules) {
		$schedules += self::intervals();
		$schedules['backup_guard_five_minutes'] = array('interval' => 300, 'display' => __('Toutes les 5 minutes', 'backup-guard'));
		return $schedules;
	}

	/** Make WP-Cron match the saved settings. */
	public static function sync() {
		$settings = backup_guard_settings();
		$schedule = $settings['schedule'];
		wp_clear_scheduled_hook('backup_guard_auto_backup');
		$intervals = self::intervals();
		if ($schedule !== '' && $schedule !== 'custom' && isset($intervals[$schedule])) {
			wp_schedule_event(time() + $intervals[$schedule]['interval'], $schedule, 'backup_guard_auto_backup');
		}
	}

	public static function run_auto_backup() {
		$settings = backup_guard_settings();
		if (Backup_Guard_Job::is_active() || Backup_Guard_Restore::is_running()) {
			return;
		}
		try {
			Backup_Guard_Job::start_backup(array(
				'files'    => !empty($settings['auto_files']),
				'db'       => !empty($settings['auto_db']),
				'scope'    => $settings['auto_scope'] ?? 'all',
				'location' => $settings['auto_location'],
				'auto'     => true,
				'rotation' => (int) $settings['rotation'],
				'note'     => __('Sauvegarde automatique', 'backup-guard'),
			));
		} catch (Exception $e) {
			try {
				Backup_Guard_Notifications::event('failure', sprintf(__('Échec de la sauvegarde automatique - %s', 'backup-guard'), Backup_Guard_Notifications::site_name()),
					sprintf(__("Site : %1\$s\nRaison : %2\$s", 'backup-guard'), home_url(), $e->getMessage()),
					array(__('Site', 'backup-guard') => home_url(), __('Raison', 'backup-guard') => $e->getMessage())
				);
			} catch (Throwable $ignored) {
				backup_guard_debug(sprintf(__('Notifications : %s', 'backup-guard'), $ignored->getMessage()));
			}
		}
	}

	public static function cron_url() {
		return add_query_arg(array('backup_guard_cron' => get_option('backup_guard_cron_key')), home_url('/'));
	}

	/** The custom schedule: a server cron job calls this address when it wants a backup. */
	public static function handle_custom_cron() {
		if (empty($_GET['backup_guard_cron'])) {
			return;
		}
		$key = (string) get_option('backup_guard_cron_key');
		$given = sanitize_text_field(wp_unslash($_GET['backup_guard_cron']));
		if ($key === '' || !hash_equals($key, $given)) {
			status_header(403);
			exit(__('Clé refusée.', 'backup-guard'));
		}
		nocache_headers();
		self::run_auto_backup();
		exit('OK');
	}
}

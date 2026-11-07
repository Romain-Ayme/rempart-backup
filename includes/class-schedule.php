<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Automatic backups: WP-Cron schedules, or a URL for a real cron job. */
class Rempart_Schedule {
	public static function intervals() {
		return array(
			'rempart_thirty_minutes' => array('interval' => 1800, 'display' => __('Toutes les 30 minutes', 'rempart-backup')),
			'rempart_one_hour'       => array('interval' => 3600, 'display' => __('Toutes les heures', 'rempart-backup')),
			'rempart_two_hours'      => array('interval' => 7200, 'display' => __('Toutes les 2 heures', 'rempart-backup')),
			'rempart_daily'          => array('interval' => 86400, 'display' => __('Une fois par jour', 'rempart-backup')),
			'rempart_weekly'         => array('interval' => 604800, 'display' => __('Une fois par semaine', 'rempart-backup')),
			'rempart_monthly'        => array('interval' => 2635200, 'display' => __('Une fois par mois', 'rempart-backup')),
		);
	}

	public static function register_intervals($schedules) {
		$schedules += self::intervals();
		$schedules['rempart_five_minutes'] = array('interval' => 300, 'display' => __('Toutes les 5 minutes', 'rempart-backup'));
		return $schedules;
	}

	/** Make WP-Cron match the saved settings. */
	public static function sync() {
		$settings = rempart_settings();
		$schedule = $settings['schedule'];
		wp_clear_scheduled_hook('rempart_auto_backup');
		$intervals = self::intervals();
		if ($schedule !== '' && $schedule !== 'custom' && isset($intervals[$schedule])) {
			wp_schedule_event(time() + $intervals[$schedule]['interval'], $schedule, 'rempart_auto_backup');
		}
	}

	public static function run_auto_backup() {
		$settings = rempart_settings();
		if (Rempart_Job::is_active() || Rempart_Restore::is_running()) {
			return;
		}
		try {
			Rempart_Job::start_backup(array(
				'files'    => !empty($settings['auto_files']),
				'db'       => !empty($settings['auto_db']),
				'scope'    => $settings['auto_scope'] ?? 'all',
				'location' => $settings['auto_location'],
				'auto'     => true,
				'rotation' => (int) $settings['rotation'],
				'note'     => __('Sauvegarde automatique', 'rempart-backup'),
			));
		} catch (Exception $e) {
			try {
				Rempart_Notifications::event('failure', sprintf(__('Échec de la sauvegarde automatique - %s', 'rempart-backup'), Rempart_Notifications::site_name()),
					sprintf(__("Site : %1\$s\nRaison : %2\$s", 'rempart-backup'), home_url(), $e->getMessage()),
					array(__('Site', 'rempart-backup') => home_url(), __('Raison', 'rempart-backup') => $e->getMessage())
				);
			} catch (Throwable $ignored) {
				rempart_debug(sprintf(__('Notifications : %s', 'rempart-backup'), $ignored->getMessage()));
			}
		}
	}

	public static function cron_url() {
		return add_query_arg(array('rempart_cron' => get_option('rempart_cron_key')), home_url('/'));
	}

	/** The custom schedule: a server cron job calls this address when it wants a backup. */
	public static function handle_custom_cron() {
		if (empty($_GET['rempart_cron'])) {
			return;
		}
		$key = (string) get_option('rempart_cron_key');
		$given = sanitize_text_field(wp_unslash($_GET['rempart_cron']));
		if ($key === '' || !hash_equals($key, $given)) {
			status_header(403);
			exit(__('Clé refusée.', 'rempart-backup'));
		}
		nocache_headers();
		self::run_auto_backup();
		exit('OK');
	}
}

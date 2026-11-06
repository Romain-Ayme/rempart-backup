<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Automatic backups: WP-Cron schedules, or a URL for a real cron job. */
class Rempart_Schedule {
	public static function intervals() {
		return array(
			'rempart_thirty_minutes' => array('interval' => 1800, 'display' => 'Toutes les 30 minutes'),
			'rempart_one_hour'       => array('interval' => 3600, 'display' => 'Toutes les heures'),
			'rempart_two_hours'      => array('interval' => 7200, 'display' => 'Toutes les 2 heures'),
			'rempart_daily'          => array('interval' => 86400, 'display' => 'Une fois par jour'),
			'rempart_weekly'         => array('interval' => 604800, 'display' => 'Une fois par semaine'),
			'rempart_monthly'        => array('interval' => 2635200, 'display' => 'Une fois par mois'),
		);
	}

	public static function register_intervals($schedules) {
		$schedules += self::intervals();
		$schedules['rempart_five_minutes'] = array('interval' => 300, 'display' => 'Toutes les 5 minutes');
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
				'location' => $settings['auto_location'],
				'auto'     => true,
				'rotation' => (int) $settings['rotation'],
				'note'     => 'Sauvegarde automatique',
			));
		} catch (Exception $e) {
			if (!empty($settings['email']) && is_email($settings['email'])) {
				wp_mail($settings['email'], 'Échec de la sauvegarde automatique', 'Site : ' . home_url() . "\nRaison : " . $e->getMessage());
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
			exit('Clé refusée.');
		}
		nocache_headers();
		self::run_auto_backup();
		exit('OK');
	}
}

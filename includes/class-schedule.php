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
		$minutes = self::minutes(backup_guard_settings());
		$schedules['backup_guard_every'] = array('interval' => $minutes * 60, 'display' => sprintf(__('Toutes les %d minutes', 'backup-guard'), $minutes));
		return $schedules;
	}

	/** The wait between two backups in the basic mode, in minutes (5 at least). */
	public static function minutes(array $settings) {
		return max(5, (int) ($settings['schedule_minutes'] ?? 60));
	}

	/**
	 * Settings from an older version name a fixed interval ("every hour"): they become the basic
	 * mode with the same wait.
	 */
	public static function normalize(array $settings) {
		$intervals = self::intervals();
		$schedule = $settings['schedule'] ?? '';
		if (isset($intervals[$schedule])) {
			$settings['schedule'] = 'interval';
			$settings['schedule_minutes'] = (int) round($intervals[$schedule]['interval'] / 60);
		}
		return $settings;
	}

	/**
	 * The next time (timestamp) that falls on one of the days (0 = Sunday) and at the start of one
	 * of the hours (0 to 23), in the site's time zone. Null when there is none.
	 */
	public static function next_slot(array $days, array $hours, $from = null) {
		$days = array_map('intval', $days);
		$hours = array_map('intval', $hours);
		sort($hours);
		if (!$days || !$hours) {
			return null;
		}
		$from = $from ?? time();
		$zone = wp_timezone();
		$day = (new DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime(0, 0);
		for ($i = 0; $i < 9; $i++, $day = $day->modify('+1 day')) {
			if (!in_array((int) $day->format('w'), $days, true)) {
				continue;
			}
			foreach ($hours as $hour) {
				$slot = $day->setTime($hour, 0)->getTimestamp();
				if ($slot > $from) {
					return $slot;
				}
			}
		}
		return null;
	}

	/** Make WP-Cron match the saved settings. */
	public static function sync() {
		$settings = backup_guard_settings();
		wp_clear_scheduled_hook('backup_guard_auto_backup');
		if ($settings['schedule'] === 'interval') {
			wp_schedule_event(time() + self::minutes($settings) * 60, 'backup_guard_every', 'backup_guard_auto_backup');
		} elseif ($settings['schedule'] === 'advanced') {
			$next = self::next_slot((array) $settings['schedule_days'], (array) $settings['schedule_hours']);
			if ($next) {
				wp_schedule_single_event($next, 'backup_guard_auto_backup');
			}
		}
	}

	public static function run_auto_backup() {
		$settings = backup_guard_settings();
		if ($settings['schedule'] === 'advanced') {
			self::sync();
		}
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

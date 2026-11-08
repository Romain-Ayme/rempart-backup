<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Root files and folders of WordPress that are always part of a files backup. */
function backup_guard_core_entries() {
	return array(
		'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-admin', 'wp-blog-header.php',
		'wp-comments-post.php', 'wp-config-sample.php', 'wp-content', 'wp-cron.php', 'wp-includes',
		'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
		'wp-trackback.php', 'xmlrpc.php', '.htaccess', 'wp-config.php',
	);
}

/** Which files a backup keeps: everything, only the site's own files, or those plus the edited WordPress files. */
function backup_guard_scopes() {
	return array(
		'all'      => array('label' => __('Tout WordPress', 'backup-guard'), 'help' => __('Le cœur de WordPress, vos thèmes, extensions et médias.', 'backup-guard')),
		'content'  => array('label' => __('Mes fichiers seulement', 'backup-guard'), 'help' => __('Sans les fichiers d\'origine de WordPress (wp-admin, wp-includes, index.php, etc.).', 'backup-guard')),
		'modified' => array('label' => __('Mes fichiers et les fichiers WordPress modifiés', 'backup-guard'), 'help' => __('Comme le précédent, plus les fichiers d\'origine de WordPress que vous avez modifiés.', 'backup-guard')),
	);
}

function backup_guard_valid_scope($scope) {
	return isset(backup_guard_scopes()[$scope]) ? $scope : 'content';
}

/**
 * The official fingerprint (MD5) of every WordPress core file for this version, as wordpress.org
 * publishes them. Paths are relative to the site root; wp-content is left out, since what lives there
 * is the site's own. Throws when they cannot be fetched.
 *
 * @return array<string,string>
 */
function backup_guard_core_checksums() {
	$filtered = apply_filters('backup_guard_core_checksums', null);
	if (is_array($filtered)) {
		return $filtered;
	}
	$version = get_bloginfo('version');
	$locale = get_locale();
	$key = 'backup_guard_core_' . md5($version . $locale);
	$cached = get_transient($key);
	if (is_array($cached) && $cached) {
		return $cached;
	}
	foreach (array_unique(array($locale, 'en_US')) as $try) {
		$response = wp_remote_get('https://api.wordpress.org/core/checksums/1.0/?' . http_build_query(array('version' => $version, 'locale' => $try)), array('timeout' => 20));
		if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			continue;
		}
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!empty($body['checksums']) && is_array($body['checksums'])) {
			$sums = array();
			foreach ($body['checksums'] as $path => $sum) {
				if (strpos($path, 'wp-content/') !== 0) {
					$sums[$path] = is_array($sum) ? reset($sum) : $sum;
				}
			}
			set_transient($key, $sums, DAY_IN_SECONDS);
			return $sums;
		}
	}
	throw new RuntimeException(sprintf(__('Impossible de récupérer la liste des fichiers d\'origine de WordPress %s (wordpress.org). Choisissez « Tout WordPress » ou réessayez.', 'backup-guard'), $version));
}

/** Is this file left out of the backup because it is an untouched WordPress file? */
function backup_guard_skips_core_file($scope, $relative, $full_path, array $core) {
	if ($scope === 'all' || !isset($core[$relative])) {
		return false;
	}
	return $scope === 'content' || md5_file($full_path) === $core[$relative];
}

function backup_guard_default_settings() {
	return array(
		'backup_files'       => 1,
		'backup_db'          => 1,
		'backup_scope'       => 'content',
		'auto_scope'         => 'content',
		// gzip level of the archive, 1 (fastest, biggest) to 9 (smallest, slowest).
		'gzip_level'         => 9,
		'location'           => '',
		'pre_update_backup'  => 0,
		'auto_update_backup' => 0,
		'schedule'           => '',
		// Basic mode: minutes between two backups. Cron mode: an expression such as "5 4 * * *".
		'schedule_minutes'   => 60,
		'schedule_cron'      => '0 2 * * *',
		'rotation'           => 0,
		'auto_files'         => 1,
		'auto_db'            => 1,
		'auto_location'      => '',
		'email'              => '',
		'additional'         => array(),
		'debug'              => 0,
	);
}

function backup_guard_settings() {
	return array_merge(backup_guard_default_settings(), (array) get_option('backup_guard_settings', array()));
}

function backup_guard_excludes() {
	$defaults = array('db' => array(), 'exact' => array(), 'extension' => array(), 'beginning' => array(), 'end' => array(), 'anywhere' => array());
	return array_merge($defaults, (array) get_option('backup_guard_excludes', array()));
}

/**
 * Create the working folders and protect them from the web.
 * The folders holding backups get a random suffix so their address can't be guessed,
 * which is the only protection on web servers that ignore .htaccess (nginx).
 */
function backup_guard_prepare_folders() {
	if (!is_dir(BACKUP_GUARD_ROOT)) {
		wp_mkdir_p(BACKUP_GUARD_ROOT);
	}
	foreach (array('backups', 'info') as $name) {
		if (!backup_guard_folder($name)) {
			wp_mkdir_p(BACKUP_GUARD_ROOT . $name . '-' . backup_guard_random(10));
		}
	}
	foreach (array('state', 'logs', 'tmp') as $name) {
		if (!is_dir(BACKUP_GUARD_ROOT . $name)) {
			wp_mkdir_p(BACKUP_GUARD_ROOT . $name);
		}
	}
	backup_guard_protect_folder(BACKUP_GUARD_ROOT);
	foreach (array(backup_guard_folder('backups'), backup_guard_folder('info'), BACKUP_GUARD_ROOT . 'state', BACKUP_GUARD_ROOT . 'logs', BACKUP_GUARD_ROOT . 'tmp') as $dir) {
		if ($dir) {
			backup_guard_write_if_missing($dir . '/index.html', '');
		}
	}
}

function backup_guard_protect_folder($dir) {
	$dir = rtrim($dir, '/') . '/';
	backup_guard_write_if_missing($dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\ndeny from all\n</IfModule>\n");
	backup_guard_write_if_missing($dir . 'web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");
	backup_guard_write_if_missing($dir . 'index.html', '');
}

function backup_guard_write_if_missing($file, $content) {
	if (!file_exists($file)) {
		@file_put_contents($file, $content);
	}
}

/** Find a folder that has a random suffix. */
function backup_guard_folder($name) {
	$found = glob(BACKUP_GUARD_ROOT . $name . '-*', GLOB_ONLYDIR);
	return $found ? str_replace('\\', '/', $found[0]) : false;
}

function backup_guard_random($length = 16) {
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$out = '';
	for ($i = 0; $i < $length; $i++) {
		$out .= $chars[random_int(0, strlen($chars) - 1)];
	}
	return $out;
}

function backup_guard_clean_path($path) {
	$path = str_replace('\\', '/', (string) $path);
	$path = preg_replace('#/+#', '/', $path);
	return strlen($path) > 1 ? rtrim($path, '/') : $path;
}

/** Keep only characters that are safe in a backup file name; refuses path tricks. */
function backup_guard_safe_backup_name($name) {
	$name = basename(str_replace('\\', '/', (string) $name));
	$name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
	$name = preg_replace('/\.tar\.gz$/i', '', $name);
	return ltrim($name, '.');
}

/** Read a JSON state file written by backup_guard_write_state(). */
function backup_guard_read_state($file) {
	$raw = @file_get_contents($file);
	if ($raw === false) {
		return null;
	}
	$raw = preg_replace('/^<\?php exit;\?>\n/', '', $raw);
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

/** Write a state file in one step, so a killed process can't leave half of it. */
function backup_guard_write_state($file, array $data) {
	$tmp = $file . '.' . getmypid() . '.tmp';
	$json = wp_json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);
	if (@file_put_contents($tmp, "<?php exit;?>\n" . $json) === false) {
		return false;
	}
	if (!@rename($tmp, $file)) {
		@unlink($file);
		return @rename($tmp, $file);
	}
	return true;
}

/** The progress log of the running or last job: one JSON object per line. */
function backup_guard_log_file($kind = 'job') {
	return BACKUP_GUARD_ROOT . 'logs/' . $kind . '.log.php';
}

function backup_guard_log_reset($kind = 'job') {
	@file_put_contents(backup_guard_log_file($kind), "<?php exit;?>\n");
}

function backup_guard_log($message, $level = 'info', $kind = 'job') {
	$line = wp_json_encode(array('t' => time(), 'l' => $level, 'm' => (string) $message));
	@file_put_contents(backup_guard_log_file($kind), $line . "\n", FILE_APPEND);
}

function backup_guard_debug($message) {
	$settings = backup_guard_settings();
	if (!empty($settings['debug'])) {
		backup_guard_log('[debug] ' . $message, 'debug');
	}
}

/** Lines of the log after line $from. Returns [lines, next position]. */
function backup_guard_log_read($from = 0, $file = null) {
	$file = $file ? $file : backup_guard_log_file();
	$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!$lines) {
		return array(array(), 0);
	}
	array_shift($lines); // The line that stops the file from being read over the web.
	$total = count($lines);
	$out = array();
	for ($i = max(0, (int) $from); $i < $total; $i++) {
		$row = json_decode($lines[$i], true);
		if (is_array($row)) {
			$out[] = $row;
		}
	}
	return array($out, $total);
}

/** Remove a folder and everything in it. Never follows links. */
function backup_guard_rmdir($dir) {
	$dir = rtrim($dir, '/');
	if (is_link($dir) || !is_dir($dir)) {
		return @unlink($dir);
	}
	$items = @scandir($dir);
	if ($items) {
		foreach ($items as $item) {
			if ($item !== '.' && $item !== '..') {
				backup_guard_rmdir($dir . '/' . $item);
			}
		}
	}
	return @rmdir($dir);
}

function backup_guard_format_size($bytes) {
	$bytes = (float) $bytes;
	$units = array(_x('o', 'byte unit', 'backup-guard'), _x('Ko', 'kilobyte unit', 'backup-guard'), _x('Mo', 'megabyte unit', 'backup-guard'), _x('Go', 'gigabyte unit', 'backup-guard'), _x('To', 'terabyte unit', 'backup-guard'));
	$i = 0;
	while ($bytes >= 1024 && $i < count($units) - 1) {
		$bytes /= 1024;
		$i++;
	}
	return number_format_i18n($bytes, $i ? 2 : 0) . ' ' . $units[$i];
}

/** Daily cleaning: unfinished archives and temporary folders older than a day. */
function backup_guard_cleanup_leftovers() {
	$backups = backup_guard_folder('backups');
	$cutoff = time() - 86400;
	if ($backups) {
		foreach ((array) glob($backups . '/.*.tar.gz') as $file) {
			if (is_file($file) && filemtime($file) < $cutoff && !Backup_Guard_Job::is_active()) {
				@unlink($file);
			}
		}
	}
	foreach ((array) glob(BACKUP_GUARD_ROOT . 'tmp/*', GLOB_ONLYDIR) as $dir) {
		if (filemtime($dir) < $cutoff && !Backup_Guard_Job::is_active()) {
			backup_guard_rmdir($dir);
		}
	}
	// A finished restore nobody came back to: its program and key shouldn't linger.
	$restore = Backup_Guard_Restore::current();
	if ($restore && !empty($restore['finished']) && (time() - (int) $restore['heartbeat']) > 3600) {
		Backup_Guard_Restore::acknowledge();
	}
}

/**
 * A restored database may come from a site that never had this plugin, or from before it was
 * set up: put back what the plugin needs, and clear a finished restore left behind.
 */
function backup_guard_self_heal() {
	if (!get_option('backup_guard_cron_key')) {
		update_option('backup_guard_cron_key', wp_generate_password(40, false), false);
	}
	if (!wp_next_scheduled('backup_guard_daily_cleanup')) {
		wp_schedule_event(time() + 3600, 'backup_guard_daily', 'backup_guard_daily_cleanup');
	}
	if (!wp_next_scheduled('backup_guard_watchdog')) {
		wp_schedule_event(time() + 300, 'backup_guard_five_minutes', 'backup_guard_watchdog');
	}
	Backup_Guard_Notifications::migrate_email();
	$settings = backup_guard_settings();
	if (in_array($settings['schedule'], array('interval', 'cron'), true) && !wp_next_scheduled('backup_guard_auto_backup')) {
		Backup_Guard_Schedule::sync();
	}
	$restore = Backup_Guard_Restore::current();
	if ($restore && !empty($restore['finished']) && (time() - (int) $restore['heartbeat']) > 300) {
		Backup_Guard_Restore::acknowledge();
	}
}

/**
 * Secrets of storage locations are stored encrypted with a key from the site's own salts.
 * After a move to a site with other salts they can't be read and must be entered again.
 */
function backup_guard_encrypt($plain) {
	$key = sodium_crypto_generichash(wp_salt('auth') . '|backup-guard', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
	$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	return base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, $key));
}

function backup_guard_decrypt($stored) {
	$raw = base64_decode((string) $stored, true);
	if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
		return false;
	}
	$key = sodium_crypto_generichash(wp_salt('auth') . '|backup-guard', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
	$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
}

/** Does this path match one of the person's exclude rules? */
function backup_guard_is_excluded($relative, array $excludes) {
	$relative = ltrim($relative, '/');
	$base = basename($relative);

	foreach ($excludes['exact'] as $exact) {
		$exact = trim($exact, '/');
		if ($exact !== '' && ($relative === $exact || strpos($relative, $exact . '/') === 0)) {
			return true;
		}
	}
	if (!empty($excludes['extension'])) {
		$ext = pathinfo($base, PATHINFO_EXTENSION);
		if ($ext !== '' && in_array($ext, $excludes['extension'], true)) {
			return true;
		}
	}
	foreach ($excludes['beginning'] as $pattern) {
		if ($pattern !== '' && strpos($base, $pattern) === 0) {
			return true;
		}
	}
	foreach ($excludes['end'] as $pattern) {
		if ($pattern !== '' && substr($base, -strlen($pattern)) === $pattern) {
			return true;
		}
	}
	foreach ($excludes['anywhere'] as $pattern) {
		if ($pattern !== '' && strpos($relative, $pattern) !== false) {
			return true;
		}
	}
	return false;
}

/**
 * Read a cron expression of five fields (minute hour day-of-month month day-of-week). Each field
 * takes *, a number, a range (1-5), a list (1,3) and a step (every 5: star slash 5, or 10-30/5).
 * Returns the allowed values of each field, or null when the expression is not valid.
 */
function backup_guard_cron_parse($expression) {
	$fields = preg_split('/\s+/', trim((string) $expression));
	if (count($fields) !== 5) {
		return null;
	}
	$limits = array(array(0, 59), array(0, 23), array(1, 31), array(1, 12), array(0, 7));
	$sets = array();
	foreach ($fields as $i => $field) {
		list($low, $high) = $limits[$i];
		$allowed = array();
		foreach (explode(',', $field) as $part) {
			if (!preg_match('/^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/', $part, $m)) {
				return null;
			}
			$step = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
			if ($step < 1) {
				return null;
			}
			if ($m[1] === '*') {
				$from = $low;
				$to = $high;
			} else {
				$from = (int) $m[2];
				$to = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (isset($m[4]) && $m[4] !== '' ? $high : $from);
			}
			if ($from < $low || $to > $high || $from > $to) {
				return null;
			}
			for ($v = $from; $v <= $to; $v += $step) {
				$allowed[$v === 7 && $i === 4 ? 0 : $v] = true;
			}
		}
		$sets[] = $allowed;
	}
	return array(
		'minutes'  => array_keys($sets[0]),
		'hours'    => array_keys($sets[1]),
		'days'     => $sets[2],
		'months'   => $sets[3],
		'weekdays' => $sets[4],
		// As in a real crontab: when both the day of the month and of the week are restricted, either one is enough.
		'either'   => strpos(preg_split('/\s+/', trim((string) $expression))[2], '*') !== 0 && strpos(preg_split('/\s+/', trim((string) $expression))[4], '*') !== 0,
	);
}

function backup_guard_cron_valid($expression) {
	return backup_guard_cron_parse($expression) !== null;
}

/** The first moment after $from (timestamp) that the expression allows, in this time zone. Null when never. */
function backup_guard_cron_next($expression, $from, DateTimeZone $zone) {
	$cron = backup_guard_cron_parse($expression);
	if (!$cron) {
		return null;
	}
	sort($cron['minutes']);
	sort($cron['hours']);
	$day = (new DateTimeImmutable('@' . (int) $from))->setTimezone($zone)->setTime(0, 0);
	for ($i = 0; $i < 1500; $i++, $day = $day->modify('+1 day')) {
		if (!isset($cron['months'][(int) $day->format('n')])) {
			continue;
		}
		$by_day = isset($cron['days'][(int) $day->format('j')]);
		$by_weekday = isset($cron['weekdays'][(int) $day->format('w')]);
		if (!($cron['either'] ? ($by_day || $by_weekday) : ($by_day && $by_weekday))) {
			continue;
		}
		foreach ($cron['hours'] as $hour) {
			foreach ($cron['minutes'] as $minute) {
				$moment = $day->setTime($hour, $minute)->getTimestamp();
				if ($moment > $from) {
					return $moment;
				}
			}
		}
	}
	return null;
}

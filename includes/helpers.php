<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Root files and folders of WordPress that are always part of a files backup. */
function rempart_core_entries() {
	return array(
		'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-admin', 'wp-blog-header.php',
		'wp-comments-post.php', 'wp-config-sample.php', 'wp-content', 'wp-cron.php', 'wp-includes',
		'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
		'wp-trackback.php', 'xmlrpc.php', '.htaccess', 'wp-config.php',
	);
}

function rempart_default_settings() {
	return array(
		'backup_files'       => 1,
		'backup_db'          => 1,
		// gzip level of the archive, 1 (fastest, biggest) to 9 (smallest, slowest).
		'gzip_level'         => 9,
		'location'           => '',
		'pre_update_backup'  => 0,
		'auto_update_backup' => 0,
		'schedule'           => '',
		'rotation'           => 0,
		'auto_files'         => 1,
		'auto_db'            => 1,
		'auto_location'      => '',
		'email'              => '',
		'additional'         => array(),
		'debug'              => 0,
	);
}

function rempart_settings() {
	return array_merge(rempart_default_settings(), (array) get_option('rempart_settings', array()));
}

function rempart_excludes() {
	$defaults = array('db' => array(), 'exact' => array(), 'extension' => array(), 'beginning' => array(), 'end' => array(), 'anywhere' => array());
	return array_merge($defaults, (array) get_option('rempart_excludes', array()));
}

/**
 * Create the working folders and protect them from the web.
 * The folders holding backups get a random suffix so their address can't be guessed,
 * which is the only protection on web servers that ignore .htaccess (nginx).
 */
function rempart_prepare_folders() {
	if (!is_dir(REMPART_ROOT)) {
		wp_mkdir_p(REMPART_ROOT);
	}
	foreach (array('backups', 'info') as $name) {
		if (!rempart_folder($name)) {
			wp_mkdir_p(REMPART_ROOT . $name . '-' . rempart_random(10));
		}
	}
	foreach (array('state', 'logs', 'tmp') as $name) {
		if (!is_dir(REMPART_ROOT . $name)) {
			wp_mkdir_p(REMPART_ROOT . $name);
		}
	}
	rempart_protect_folder(REMPART_ROOT);
	foreach (array(rempart_folder('backups'), rempart_folder('info'), REMPART_ROOT . 'state', REMPART_ROOT . 'logs', REMPART_ROOT . 'tmp') as $dir) {
		if ($dir) {
			rempart_write_if_missing($dir . '/index.html', '');
		}
	}
}

function rempart_protect_folder($dir) {
	$dir = rtrim($dir, '/') . '/';
	rempart_write_if_missing($dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\ndeny from all\n</IfModule>\n");
	rempart_write_if_missing($dir . 'web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");
	rempart_write_if_missing($dir . 'index.html', '');
}

function rempart_write_if_missing($file, $content) {
	if (!file_exists($file)) {
		@file_put_contents($file, $content);
	}
}

/** Find a folder that has a random suffix. */
function rempart_folder($name) {
	$found = glob(REMPART_ROOT . $name . '-*', GLOB_ONLYDIR);
	return $found ? str_replace('\\', '/', $found[0]) : false;
}

function rempart_random($length = 16) {
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$out = '';
	for ($i = 0; $i < $length; $i++) {
		$out .= $chars[random_int(0, strlen($chars) - 1)];
	}
	return $out;
}

function rempart_clean_path($path) {
	$path = str_replace('\\', '/', (string) $path);
	$path = preg_replace('#/+#', '/', $path);
	return strlen($path) > 1 ? rtrim($path, '/') : $path;
}

/** Keep only characters that are safe in a backup file name; refuses path tricks. */
function rempart_safe_backup_name($name) {
	$name = basename(str_replace('\\', '/', (string) $name));
	$name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
	$name = preg_replace('/\.tar\.gz$/i', '', $name);
	return ltrim($name, '.');
}

/** Read a JSON state file written by rempart_write_state(). */
function rempart_read_state($file) {
	$raw = @file_get_contents($file);
	if ($raw === false) {
		return null;
	}
	$raw = preg_replace('/^<\?php exit;\?>\n/', '', $raw);
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

/** Write a state file in one step, so a killed process can't leave half of it. */
function rempart_write_state($file, array $data) {
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
function rempart_log_file($kind = 'job') {
	return REMPART_ROOT . 'logs/' . $kind . '.log.php';
}

function rempart_log_reset($kind = 'job') {
	@file_put_contents(rempart_log_file($kind), "<?php exit;?>\n");
}

function rempart_log($message, $level = 'info', $kind = 'job') {
	$line = wp_json_encode(array('t' => time(), 'l' => $level, 'm' => (string) $message));
	@file_put_contents(rempart_log_file($kind), $line . "\n", FILE_APPEND);
}

function rempart_debug($message) {
	$settings = rempart_settings();
	if (!empty($settings['debug'])) {
		rempart_log('[debug] ' . $message, 'debug');
	}
}

/** Lines of the log after line $from. Returns [lines, next position]. */
function rempart_log_read($from = 0, $file = null) {
	$file = $file ? $file : rempart_log_file();
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
function rempart_rmdir($dir) {
	$dir = rtrim($dir, '/');
	if (is_link($dir) || !is_dir($dir)) {
		return @unlink($dir);
	}
	$items = @scandir($dir);
	if ($items) {
		foreach ($items as $item) {
			if ($item !== '.' && $item !== '..') {
				rempart_rmdir($dir . '/' . $item);
			}
		}
	}
	return @rmdir($dir);
}

function rempart_format_size($bytes) {
	$bytes = (float) $bytes;
	$units = array('o', 'Ko', 'Mo', 'Go', 'To');
	$i = 0;
	while ($bytes >= 1024 && $i < count($units) - 1) {
		$bytes /= 1024;
		$i++;
	}
	return number_format_i18n($bytes, $i ? 2 : 0) . ' ' . $units[$i];
}

/** Daily cleaning: unfinished archives and temporary folders older than a day. */
function rempart_cleanup_leftovers() {
	$backups = rempart_folder('backups');
	$cutoff = time() - 86400;
	if ($backups) {
		foreach ((array) glob($backups . '/.*.tar.gz') as $file) {
			if (is_file($file) && filemtime($file) < $cutoff && !Rempart_Job::is_active()) {
				@unlink($file);
			}
		}
	}
	foreach ((array) glob(REMPART_ROOT . 'tmp/*', GLOB_ONLYDIR) as $dir) {
		if (filemtime($dir) < $cutoff && !Rempart_Job::is_active()) {
			rempart_rmdir($dir);
		}
	}
	// A finished restore nobody came back to: its program and key shouldn't linger.
	$restore = Rempart_Restore::current();
	if ($restore && !empty($restore['finished']) && (time() - (int) $restore['heartbeat']) > 3600) {
		Rempart_Restore::acknowledge();
	}
}

/**
 * A restored database may come from a site that never had this plugin, or from before it was
 * set up: put back what the plugin needs, and clear a finished restore left behind.
 */
function rempart_self_heal() {
	if (!get_option('rempart_cron_key')) {
		update_option('rempart_cron_key', wp_generate_password(40, false), false);
	}
	if (!wp_next_scheduled('rempart_daily_cleanup')) {
		wp_schedule_event(time() + 3600, 'rempart_daily', 'rempart_daily_cleanup');
	}
	if (!wp_next_scheduled('rempart_watchdog')) {
		wp_schedule_event(time() + 300, 'rempart_five_minutes', 'rempart_watchdog');
	}
	Rempart_Notifications::migrate_email();
	$settings = rempart_settings();
	$intervals = Rempart_Schedule::intervals();
	if (isset($intervals[$settings['schedule']]) && !wp_next_scheduled('rempart_auto_backup')) {
		Rempart_Schedule::sync();
	}
	$restore = Rempart_Restore::current();
	if ($restore && !empty($restore['finished']) && (time() - (int) $restore['heartbeat']) > 300) {
		Rempart_Restore::acknowledge();
	}
}

/**
 * Secrets of storage locations are stored encrypted with a key from the site's own salts.
 * After a move to a site with other salts they can't be read and must be entered again.
 */
function rempart_encrypt($plain) {
	$key = sodium_crypto_generichash(wp_salt('auth') . '|rempart', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
	$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	return base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, $key));
}

function rempart_decrypt($stored) {
	$raw = base64_decode((string) $stored, true);
	if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
		return false;
	}
	$key = sodium_crypto_generichash(wp_salt('auth') . '|rempart', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
	$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
}

/** Does this path match one of the person's exclude rules? */
function rempart_is_excluded($relative, array $excludes) {
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

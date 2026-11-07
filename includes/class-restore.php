<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Prepares a restore. The work itself is done by a small program that doesn't load WordPress,
 * because WordPress's own files and tables get replaced while it runs.
 */
class Backup_Guard_Restore {
	public static function state_file() {
		return BACKUP_GUARD_ROOT . 'state/restore.php';
	}

	public static function current() {
		return backup_guard_read_state(self::state_file());
	}

	public static function is_running() {
		$state = self::current();
		return is_array($state) && empty($state['finished']);
	}

	/**
	 * Start restoring a backup; fetches it first when it lives on a storage.
	 *
	 * @param array $options files, db (booleans).
	 * @return array ['mode' => 'fetch'|'restore', ...]
	 */
	public static function start($name, array $options) {
		$name = backup_guard_safe_backup_name($name);
		$info = Backup_Guard_Backups::get($name);
		if (!$info) {
			throw new RuntimeException(__('Sauvegarde introuvable.', 'backup-guard'));
		}
		$files = !empty($options['files']) && !empty($info['backup_dir']);
		$db = !empty($options['db']) && !empty($info['backup_db']);
		if (!$files && !$db) {
			throw new RuntimeException(__('Choisissez ce qu\'il faut restaurer.', 'backup-guard'));
		}
		$options = array('files' => $files, 'db' => $db);

		$archive = Backup_Guard_Backups::archive_path($name);
		if (!file_exists($archive) && !empty($info['location'])) {
			Backup_Guard_Job::start_fetch($name, $options);
			return array('mode' => 'fetch');
		}
		return self::launch($name, $options, false);
	}

	/** Write the program and its instructions, then start it. */
	public static function launch($name, array $options, $delete_after) {
		global $table_prefix;
		$info = Backup_Guard_Backups::get($name);
		$archive = Backup_Guard_Backups::archive_path($name);
		if (!$info || !file_exists($archive)) {
			throw new RuntimeException(__('Le fichier de cette sauvegarde est introuvable.', 'backup-guard'));
		}
		if (Backup_Guard_Job::is_active()) {
			throw new RuntimeException(__('Une tâche est en cours. Attendez sa fin ou arrêtez-la.', 'backup-guard'));
		}

		// Older keys in info files, from backups made by the plugin this one replaces.
		$source_url = $info['site_url'] ?? ($info['backup_site_url'] ?? '');
		$source_home = $info['home_url'] ?? $source_url;
		$source_path = $info['site_path'] ?? ($info['backup_site_path'] ?? '');

		$target_url = site_url();
		$target_home = home_url();
		$target_path = backup_guard_clean_path(ABSPATH);
		$is_migration = $source_url !== '' && (
			untrailingslashit($source_url) !== untrailingslashit($target_url)
			|| ($source_path !== '' && rtrim($source_path, '/') !== $target_path)
		);

		$key = wp_generate_password(48, false);
		$runner_name = 'backup-guard-restore-' . backup_guard_random(24) . '.php';
		$runner_path = backup_guard_clean_path(WP_CONTENT_DIR) . '/' . $runner_name;
		$template = BACKUP_GUARD_DIR . '/runner/restore-runner.php';
		$reader_source = file_get_contents(BACKUP_GUARD_DIR . '/includes/class-tar.php');
		$code = file_get_contents($template);
		if ($code === false || $reader_source === false) {
			throw new RuntimeException(__('Fichiers du plugin incomplets : réinstallez-le.', 'backup-guard'));
		}
		// The program must keep working while the plugin's own folder is replaced, so it carries its own copy.
		$reader_source = preg_replace('/^<\?php/', '', $reader_source);
		$code = str_replace('/*BACKUP_GUARD_TAR_CLASSES*/', $reader_source, $code);
		if (@file_put_contents($runner_path, $code) === false) {
			throw new RuntimeException(__('Impossible d\'écrire dans le dossier wp-content.', 'backup-guard'));
		}

		// The program has no gettext: give it the translations of its messages, in this site's language.
		$i18n = array();
		if (preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'/", $code, $found)) {
			foreach (array_unique($found[1]) as $raw) {
				$text = str_replace(array("\\'", '\\\\'), array("'", '\\'), $raw);
				$translated = translate($text, 'backup-guard');
				if ($translated !== $text) {
					$i18n[$text] = $translated;
				}
			}
		}

		$state = array(
			'key'        => $key,
			'i18n'       => $i18n,
			'runner'     => $runner_path,
			'runner_url' => content_url($runner_name),
			'archive'    => $archive,
			'name'       => $name,
			'plugin'     => plugin_basename(BACKUP_GUARD_FILE),
			'files'      => !empty($options['files']),
			'db'         => !empty($options['db']),
			'phase'      => !empty($options['files']) ? 'extract' : 'extract_db',
			'percent'    => 1,
			'heartbeat'  => time(),
			'stalls'     => 0,
			'loop'       => 0,
			'finished'   => 0,
			'status'     => 'running',
			'errors'     => array(),
			'warnings'   => 0,
			'delete_after' => (bool) $delete_after,
			'root'       => $target_path,
			'protected'  => array(
				ltrim(substr(backup_guard_clean_path(BACKUP_GUARD_ROOT), strlen($target_path)), '/'),
				ltrim(substr($runner_path, strlen($target_path)), '/'),
				'.maintenance',
			),
			'migration'  => $is_migration,
			'db_conn'    => array('host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASSWORD, 'name' => DB_NAME),
			'prefix'     => $table_prefix,
			'source'     => array('url' => $source_url, 'home' => $source_home, 'path' => $source_path, 'prefix' => $info['table_prefix'] ?? ''),
			'target'     => array('url' => $target_url, 'home' => $target_home, 'path' => $target_path),
			'read'       => array('member' => 0, 'skip' => 0, 'entry' => null),
			'extracted'  => 0,
			'import'     => array('offset' => 0),
			'replace'    => null,
			'log_file'   => backup_guard_log_file('restore'),
			'lock_file'  => BACKUP_GUARD_ROOT . 'state/restore.lock',
			'state_file' => self::state_file(),
			'tmp_sql'    => BACKUP_GUARD_ROOT . 'tmp/restore-' . backup_guard_random(8) . '.sql',
		);
		backup_guard_log_reset('restore');
		backup_guard_log(sprintf(__('Restauration démarrée : %s', 'backup-guard'), $name) . ($is_migration ? ' ' . __('(migration vers cette adresse)', 'backup-guard') : ''), 'info', 'restore');
		if (!backup_guard_write_state(self::state_file(), $state)) {
			@unlink($runner_path);
			throw new RuntimeException(__('Impossible d\'écrire l\'état de la restauration.', 'backup-guard'));
		}

		wp_remote_post($state['runner_url'], array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array('key' => $key, 'op' => 'run'),
		));

		return array('mode' => 'restore', 'url' => $state['runner_url'], 'key' => $key);
	}

	/** Remove a finished restore's leftovers, once the person has seen how it ended. */
	public static function acknowledge() {
		$state = self::current();
		if ($state && !empty($state['finished'])) {
			if (!empty($state['runner'])) {
				@unlink($state['runner']);
			}
			@copy(backup_guard_log_file('restore'), BACKUP_GUARD_ROOT . 'logs/last-restore.log.php');
			@unlink(self::state_file());
			if (!empty($state['delete_after']) && !empty($state['archive'])) {
				@unlink($state['archive']);
			}
		}
	}

	/** Give up on a restore that stopped answering, and take the site out of maintenance. */
	public static function force_stop() {
		$state = self::current();
		if ($state) {
			if (!empty($state['runner'])) {
				@unlink($state['runner']);
			}
			if (!empty($state['tmp_sql'])) {
				@unlink($state['tmp_sql']);
			}
			@unlink(backup_guard_clean_path(ABSPATH) . '/.maintenance');
			@unlink(self::state_file());
			backup_guard_log(__('Restauration arrêtée de force.', 'backup-guard'), 'warning', 'restore');
		}
	}
}

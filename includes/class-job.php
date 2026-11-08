<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * A long-running job (a backup, or fetching a backup from a storage before restoring it).
 *
 * Web requests die after a while, so the work happens in slices of about 25 seconds. Each
 * slice loads the state from disk, works, saves the state and calls the site again to start
 * the next slice. If that call never arrives (blocked loopback, killed process), the page
 * watching the progress, WP-Cron or WP-CLI push the job on.
 */
class Backup_Guard_Job {
	public static function state_file() {
		return BACKUP_GUARD_ROOT . 'state/job.php';
	}

	public static function current() {
		return backup_guard_read_state(self::state_file());
	}

	public static function is_active() {
		$state = self::current();
		return is_array($state) && empty($state['finished']);
	}

	/**
	 * Checking that no job runs and writing the new job's state must happen as one step, or two
	 * requests arriving together would both pass the check and one backup would silently replace the other.
	 * The lock is kept for the whole start; a second start meanwhile is refused as if the first had finished starting.
	 *
	 * @return mixed Whatever the callback returns.
	 */
	private static function exclusive_start(callable $start) {
		backup_guard_prepare_folders();
		$lock = @fopen(BACKUP_GUARD_ROOT . 'state/start.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			if ($lock) {
				fclose($lock);
			}
			throw new RuntimeException(__('Une tâche est déjà en cours. Attendez sa fin ou arrêtez-la.', 'backup-guard'));
		}
		try {
			return call_user_func($start);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** @return array The job, once started. Throws if one is already running. */
	public static function start_backup(array $options) {
		return self::exclusive_start(function () use ($options) {
			return Backup_Guard_Job::begin_backup($options);
		});
	}

	/** Only called through start_backup(), under the start lock. */
	public static function begin_backup(array $options) {
		backup_guard_prepare_folders();
		$active = self::current();
		if ($active && empty($active['finished'])) {
			throw new RuntimeException(__('Une tâche est déjà en cours. Attendez sa fin ou arrêtez-la.', 'backup-guard'));
		}
		if (file_exists(BACKUP_GUARD_ROOT . 'state/restore.php')) {
			throw new RuntimeException(__('Une restauration est en cours.', 'backup-guard'));
		}
		$files = !empty($options['files']);
		$db = !empty($options['db']);
		if (!$files && !$db) {
			throw new RuntimeException(__('Choisissez au moins les fichiers ou la base de données.', 'backup-guard'));
		}
		$location = isset($options['location']) ? (string) $options['location'] : '';
		if ($location !== '' && !Backup_Guard_Locations::get($location)) {
			throw new RuntimeException(__('L\'emplacement choisi n\'existe plus.', 'backup-guard'));
		}
		if ($location !== '') {
			// A bad password should stop the job now rather than after an hour of compressing.
			Backup_Guard_Storage::make(Backup_Guard_Locations::get($location))->test();
		}

		$scope = backup_guard_valid_scope($options['scope'] ?? 'content');
		// The list of WordPress's own files is fetched now, so a failure shows before any work is done.
		$core = ($files && $scope !== 'all') ? backup_guard_core_checksums() : array();

		$host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
		$name = 'wp_' . $host . '_' . gmdate('Y-m-d_H-i-s') . '_' . backup_guard_random(6);
		$note = isset($options['note']) ? mb_substr(sanitize_text_field((string) $options['note']), 0, BACKUP_GUARD_NOTE_MAX) : '';

		$state = array(
			'kind'     => 'backup',
			'token'    => wp_generate_password(40, false),
			'name'     => $name,
			'phase'    => $db ? 'database' : 'scan',
			'loop'     => 0,
			'started'  => time(),
			'heartbeat'=> time(),
			'percent'  => 1,
			'stalls'   => 0,
			'stop'     => 0,
			'finished' => 0,
			'status'   => 'running',
			'errors'   => array(),
			'files'    => $files ? 1 : 0,
			'db'       => $db ? 1 : 0,
			'location' => $location,
			'scope'    => $scope,
			'note'     => $note,
			'auto'     => !empty($options['auto']) ? 1 : 0,
			'rotation' => (int) ($options['rotation'] ?? 0),
			'reason'   => (string) ($options['reason'] ?? ''),
			'user_email' => wp_get_current_user()->user_email,
			'added'    => 0,
			'skipped'  => 0,
			'bytes_done'  => 0,
			'bytes_total' => 0,
		);
		$tmp = self::tmp_dir($name);
		wp_mkdir_p($tmp);
		if ($core) {
			file_put_contents($tmp . '/core.json', wp_json_encode($core));
		}
		backup_guard_log_reset();
		backup_guard_log(sprintf(__('Sauvegarde démarrée : %s', 'backup-guard'), $name));
		self::save($state);
		if (!wp_next_scheduled('backup_guard_watchdog')) {
			wp_schedule_event(time() + 300, 'backup_guard_five_minutes', 'backup_guard_watchdog');
		}
		self::spawn($state);
		return $state;
	}

	/** Bring a backup kept on a storage back to this server. */
	public static function start_fetch($name, array $restore_options) {
		return self::exclusive_start(function () use ($name, $restore_options) {
			return Backup_Guard_Job::begin_fetch($name, $restore_options);
		});
	}

	/** Only called through start_fetch(), under the start lock. */
	public static function begin_fetch($name, array $restore_options) {
		$active = self::current();
		if ($active && empty($active['finished'])) {
			throw new RuntimeException(__('Une tâche est déjà en cours. Attendez sa fin ou arrêtez-la.', 'backup-guard'));
		}
		$info = Backup_Guard_Backups::get($name);
		if (!$info || empty($info['location'])) {
			throw new RuntimeException(__('Sauvegarde introuvable.', 'backup-guard'));
		}
		$state = array(
			'kind'      => 'fetch',
			'token'     => wp_generate_password(40, false),
			'name'      => $name,
			'phase'     => 'fetch',
			'loop'      => 0,
			'started'   => time(),
			'heartbeat' => time(),
			'percent'   => 1,
			'stalls'    => 0,
			'stop'      => 0,
			'finished'  => 0,
			'status'    => 'running',
			'errors'    => array(),
			'location'  => (string) $info['location'],
			'restore'   => $restore_options,
			'download'  => array(),
		);
		backup_guard_log_reset();
		backup_guard_log(__('Récupération de la sauvegarde depuis son emplacement…', 'backup-guard'));
		self::save($state);
		self::spawn($state);
		return $state;
	}

	public static function save(array $state) {
		return backup_guard_write_state(self::state_file(), $state);
	}

	private static function tmp_dir($name) {
		return BACKUP_GUARD_ROOT . 'tmp/' . $name;
	}

	/** Ask the site to run the next slice, without waiting for it. */
	public static function spawn(array $state) {
		wp_remote_post(admin_url('admin-ajax.php'), array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array('action' => 'backup_guard_slice', 'token' => $state['token']),
			'headers'   => array('Cache-Control' => 'no-cache'),
		));
	}

	/** The loopback endpoint that runs one slice. */
	public static function ajax_slice() {
		$token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
		$state = self::current();
		if (!$state || $token === '' || !hash_equals((string) $state['token'], $token)) {
			wp_send_json_error(__('Jeton refusé.', 'backup-guard'), 403);
		}
		ignore_user_abort(true);
		self::run_slice(true);
		wp_die();
	}

	/**
	 * Run one slice unless another one is running.
	 *
	 * @param bool $chain Start the next slice over HTTP when this one isn't enough.
	 * @return array|null The state afterwards.
	 */
	public static function run_slice($chain = true) {
		$lock = @fopen(BACKUP_GUARD_ROOT . 'state/job.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			return self::current();
		}
		@set_time_limit(max(120, BACKUP_GUARD_SLICE_SECONDS * 4));
		$state = self::current();
		if (!$state || !empty($state['finished'])) {
			flock($lock, LOCK_UN);
			fclose($lock);
			return $state;
		}

		$state['loop']++;
		$state['heartbeat'] = time();
		$state['stalls'] = 0;
		self::save($state);
		$deadline = time() + BACKUP_GUARD_SLICE_SECONDS - BACKUP_GUARD_SLICE_MARGIN;

		try {
			while (empty($state['finished'])) {
				if (self::stop_requested()) {
					self::finish_stopped($state);
					break;
				}
				$before = $state['phase'];
				self::run_phase($state, $deadline);
				$state['heartbeat'] = time();
				self::save($state);
				if ($state['phase'] === $before || time() >= $deadline) {
					break;
				}
			}
		} catch (Exception $e) {
			self::fail($state, $e->getMessage());
		} catch (Error $e) {
			self::fail($state, 'Erreur PHP : ' . $e->getMessage());
		}

		flock($lock, LOCK_UN);
		fclose($lock);
		if ($chain && empty($state['finished'])) {
			self::spawn($state);
		}
		return $state;
	}

	private static function stop_requested() {
		return file_exists(BACKUP_GUARD_ROOT . 'state/stop');
	}

	public static function request_stop() {
		@file_put_contents(BACKUP_GUARD_ROOT . 'state/stop', '1');
		$state = self::current();
		if ($state && empty($state['finished'])) {
			self::run_slice(false);
		}
	}

	private static function run_phase(array &$state, $deadline) {
		switch ($state['phase']) {
			case 'database':
				self::phase_database($state, $deadline);
				break;
			case 'scan':
				self::phase_scan($state, $deadline);
				break;
			case 'archive':
				self::phase_archive($state, $deadline);
				break;
			case 'upload':
				self::phase_upload($state, $deadline);
				break;
			case 'fetch':
				self::phase_fetch($state, $deadline);
				break;
			case 'finish':
				self::phase_finish($state);
				break;
		}
	}

	/* ----- Database ----- */

	private static function phase_database(array &$state, $deadline) {
		$file = self::tmp_dir($state['name']) . '/database.sql';
		if (empty($state['dump'])) {
			backup_guard_log(__('Export de la base de données', 'backup-guard'));
			$state['dump'] = Backup_Guard_Database::begin($file, backup_guard_excludes()['db']);
			$state['percent'] = 5;
		}
		$done = Backup_Guard_Database::step($file, $state['dump'], $deadline, function ($cursor) {
			backup_guard_debug(sprintf(__('Table terminée : %s', 'backup-guard'), $cursor['tables'][$cursor['index'] - 1]));
		});
		$count = max(1, count($state['dump']['tables']));
		$state['percent'] = 5 + (int) (20 * min(1, $state['dump']['index'] / $count));
		if ($done) {
			backup_guard_log(sprintf(__('Base de données exportée : %1$d tables, %2$d lignes, %3$s', 'backup-guard'), $count, $state['dump']['rows'], backup_guard_format_size(filesize($file))));
			$state['phase'] = $state['files'] ? 'scan' : 'archive';
			$state['archive'] = null;
		}
	}

	/* ----- Listing the files ----- */

	private static function phase_scan(array &$state, $deadline) {
		$list = self::tmp_dir($state['name']) . '/files.list';
		if (empty($state['scan'])) {
			backup_guard_log(__('Inventaire des fichiers', 'backup-guard'));
			file_put_contents($list, '');
			$state['scan'] = array('stack' => array(''), 'count' => 0, 'bytes' => 0, 'committed' => 0, 'core' => 0);
		}
		$scope = $state['scope'] ?? 'content';
		$core = array();
		if ($scope !== 'all') {
			$core = (array) json_decode((string) @file_get_contents(self::tmp_dir($state['name']) . '/core.json'), true);
		}
		$excludes = backup_guard_excludes();
		$excludes['exact'][] = ltrim(self::relative(BACKUP_GUARD_ROOT), '/');
		$excludes['exact'][] = ltrim(self::relative(WP_CONTENT_DIR . '/backuply'), '/');
		$excludes['exact'][] = ltrim(self::relative(WP_CONTENT_DIR . '/ai1wm-backups'), '/');
		$excludes['exact'][] = ltrim(self::relative(WP_CONTENT_DIR . '/updraft'), '/');

		$settings = backup_guard_settings();
		$root_allowed = array_merge(backup_guard_core_entries(), (array) $settings['additional']);
		$root = backup_guard_clean_path(ABSPATH);

		$handle = fopen($list, 'c+b');
		ftruncate($handle, $state['scan']['committed']);
		fseek($handle, 0, SEEK_END);

		while ($state['scan']['stack']) {
			if (time() >= $deadline) {
				break;
			}
			$relative = array_pop($state['scan']['stack']);
			$items = @scandir($root . ($relative === '' ? '' : '/' . $relative));
			if ($items === false) {
				backup_guard_log(sprintf(__('Dossier illisible, ignoré : %s', 'backup-guard'), $relative), 'warning');
				continue;
			}
			foreach ($items as $item) {
				if ($item === '.' || $item === '..') {
					continue;
				}
				if ($relative === '' && !in_array($item, $root_allowed, true)) {
					continue;
				}
				$child = $relative === '' ? $item : $relative . '/' . $item;
				if (backup_guard_is_excluded($child, $excludes)) {
					continue;
				}
				$full = $root . '/' . $child;
				if ($core && is_file($full) && !is_link($full) && backup_guard_skips_core_file($scope, $child, $full, $core)) {
					$state['scan']['core']++;
					continue;
				}
				if (is_link($full)) {
					fwrite($handle, 'l 0 ' . rawurlencode($child) . "\n");
				} elseif (is_dir($full)) {
					fwrite($handle, 'd 0 ' . rawurlencode($child) . "\n");
					$state['scan']['stack'][] = $child;
				} elseif (is_file($full)) {
					$size = (float) @filesize($full);
					fwrite($handle, 'f ' . $size . ' ' . rawurlencode($child) . "\n");
					$state['scan']['bytes'] += $size;
				}
				$state['scan']['count']++;
			}
			$state['scan']['committed'] = ftell($handle);
		}
		fclose($handle);
		$state['percent'] = max($state['percent'], $state['db'] ? 28 : 8);

		if (!$state['scan']['stack']) {
			if ($scope !== 'all') {
				backup_guard_log($scope === 'content' ? sprintf(__("%d fichiers d'origine de WordPress, non sauvegardés", 'backup-guard'), $state['scan']['core']) : sprintf(__('%d fichiers de WordPress inchangés, non sauvegardés', 'backup-guard'), $state['scan']['core']));
			}
			backup_guard_log(sprintf(__('%1$d éléments à sauvegarder, %2$s', 'backup-guard'), $state['scan']['count'], backup_guard_format_size($state['scan']['bytes'])));
			$state['bytes_total'] = $state['scan']['bytes'];
			$state['phase'] = 'archive';
			$state['archive'] = null;
		}
	}

	private static function relative($path) {
		return substr(backup_guard_clean_path($path), strlen(backup_guard_clean_path(ABSPATH)));
	}

	/* ----- Writing the archive ----- */

	private static function working_archive(array $state) {
		return backup_guard_folder('backups') . '/.' . $state['name'] . '.tar.gz';
	}

	private static function final_archive(array $state) {
		return backup_guard_folder('backups') . '/' . $state['name'] . '.tar.gz';
	}

	private static function build_info(array $state, $size) {
		global $table_prefix;
		return array(
			'name'         => $state['name'],
			'backup_dir'   => $state['files'],
			'backup_db'    => $state['db'],
			'scope'        => $state['scope'] ?? 'content',
			'email'        => $state['user_email'] ?? '',
			'date_time'    => wp_date('Y-m-d H:i:s'),
			'btime'        => (int) $state['started'],
			'auto_backup'  => !empty($state['auto']),
			'reason'       => $state['reason'] ?? '',
			'ext'          => 'tar.gz',
			'size'         => $size,
			'location'     => $state['location'],
			'note'         => $state['note'],
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'site_path'    => backup_guard_clean_path(ABSPATH),
			'table_prefix' => $table_prefix,
			'wp_version'   => get_bloginfo('version'),
			'plugin_version' => BACKUP_GUARD_VERSION,
		);
	}

	private static function phase_archive(array &$state, $deadline) {
		$tmp = self::tmp_dir($state['name']);
		$archive_path = self::working_archive($state);

		if (empty($state['archive'])) {
			backup_guard_log(__('Création de l\'archive', 'backup-guard'));
			file_put_contents($tmp . '/info.json', wp_json_encode(self::build_info($state, 0)));
			$state['archive'] = array(
				'committed' => 0,
				'stage'     => 'meta',     // meta -> files -> done
				'meta_item' => 0,
				'list_pos'  => 0,          // Byte offset of the entry being added in the list file.
				'file_pos'  => 0,          // Payload bytes already written of the entry being added.
				'header'    => 0,          // Its header has been written.
			);
			$state['bytes_done'] = 0;
			$state['added'] = 0;
		}
		$cursor = &$state['archive'];
		$writer = new Backup_Guard_Tar_Writer($archive_path, $cursor['committed'], (int) backup_guard_settings()['gzip_level']);

		$commit = function () use ($writer, &$cursor, &$state) {
			$cursor['committed'] = $writer->flush();
			$state['heartbeat'] = time();
			self::save($state);
		};

		if ($cursor['stage'] === 'meta') {
			$items = array(array($tmp . '/info.json', '.backup-guard/info.json'));
			if ($state['db']) {
				$items[] = array($tmp . '/database.sql', '.backup-guard/database.sql');
			}
			while ($cursor['meta_item'] < count($items)) {
				list($path, $stored) = $items[$cursor['meta_item']];
				$done = self::add_regular_file($writer, $cursor, $path, $stored, $deadline, $commit);
				if (!$done) {
					$writer->flush();
					$cursor['committed'] = $writer->flush();
					$writer->close();
					return;
				}
				$cursor['meta_item']++;
			}
			$cursor['stage'] = $state['files'] ? 'files' : 'done';
			$commit();
		}

		if ($cursor['stage'] === 'files') {
			$list = fopen($tmp . '/files.list', 'rb');
			fseek($list, $cursor['list_pos']);
			$root = backup_guard_clean_path(ABSPATH);
			$bytes_total = max(1, (float) $state['bytes_total']);

			while (true) {
				if (time() >= $deadline) {
					break;
				}
				if (self::stop_requested()) {
					break;
				}
				$line_start = ftell($list);
				$line = fgets($list);
				if ($line === false) {
					$cursor['stage'] = 'done';
					break;
				}
				$cursor['list_pos'] = $line_start;
				list($type, , $encoded) = explode(' ', rtrim($line, "\n"), 3);
				$relative = rawurldecode($encoded);
				$full = $root . '/' . $relative;

				if ($type === 'f') {
					if (!is_readable($full)) {
						backup_guard_log(sprintf(__('Fichier illisible, ignoré : %s', 'backup-guard'), $relative), 'warning');
						$state['skipped']++;
						$cursor['list_pos'] = ftell($list);
						continue;
					}
					$before = $cursor['file_pos'];
					$done = self::add_regular_file($writer, $cursor, $full, $relative, $deadline, $commit);
					$state['bytes_done'] += ($done ? $cursor['declared'] : $cursor['file_pos']) - $before;
					if (!$done) {
						break;
					}
				} elseif ($type === 'd') {
					$writer->header($relative . '/', 0, (int) @filemtime($full), (int) @fileperms($full) ?: 0755, '5');
				} elseif ($type === 'l') {
					$target = @readlink($full);
					if ($target !== false) {
						$writer->header($relative, 0, 0, 0777, '2', $target);
					}
				}
				$state['added']++;
				$cursor['list_pos'] = ftell($list);
				$cursor['file_pos'] = 0;
				$cursor['header'] = 0;

				if ($writer->buffered() >= BACKUP_GUARD_GZ_MEMBER_BYTES) {
					$commit();
				}
			}
			fclose($list);
			$low = $state['db'] ? 30 : 10;
			$high = $state['location'] !== '' ? 80 : 97;
			$state['percent'] = $low + (int) (($high - $low) * min(1, $state['bytes_done'] / $bytes_total));
			if ($state['loop'] % 2 === 0) {
				backup_guard_log(sprintf(__('Archive : %1$d éléments ajoutés, %2$s traités', 'backup-guard'), $state['added'], backup_guard_format_size($state['bytes_done'])));
			}
		}

		if ($cursor['stage'] === 'done') {
			$size = $writer->finish();
			$cursor['committed'] = $size;
			if (!@rename($archive_path, self::final_archive($state))) {
				throw new RuntimeException(__('Impossible de finaliser l\'archive.', 'backup-guard'));
			}
			backup_guard_log(sprintf(__('Archive terminée : %s', 'backup-guard'), backup_guard_format_size($size)));
			$state['size'] = $size;
			$state['phase'] = $state['location'] !== '' ? 'upload' : 'finish';
			$state['upload'] = array();
			$state['percent'] = max($state['percent'], $state['location'] !== '' ? 80 : 97);
			return;
		}
		$cursor['committed'] = $writer->flush();
		$writer->close();
	}

	/**
	 * Add one file, possibly over several slices. Whatever is read stays consistent with its
	 * header: a file that shrinks while being read is padded, one that grows is cut.
	 * Returns false when time ran out before the file was finished.
	 */
	private static function add_regular_file($writer, array &$cursor, $path, $stored, $deadline, $commit) {
		$size = (float) @filesize($path);
		if (empty($cursor['header'])) {
			$cursor['header'] = 1;
			$cursor['declared'] = $size;
			$cursor['file_pos'] = 0;
			$writer->header($stored, $size, (int) @filemtime($path), (int) @fileperms($path) ?: 0644, '0');
		}
		$declared = (float) $cursor['declared'];

		if ($declared > 0) {
			$handle = @fopen($path, 'rb');
			if ($handle && $cursor['file_pos'] > 0) {
				fseek($handle, (int) $cursor['file_pos']);
			}
			while ($cursor['file_pos'] < $declared) {
				if (time() >= $deadline) {
					if ($handle) {
						fclose($handle);
					}
					return false;
				}
				$want = (int) min(1048576, $declared - $cursor['file_pos']);
				$chunk = $handle ? fread($handle, $want) : '';
				if ($chunk === '' || $chunk === false) {
					$chunk = str_repeat("\0", $want);
				}
				$writer->write($chunk);
				$cursor['file_pos'] += strlen($chunk);
				if ($writer->buffered() >= BACKUP_GUARD_GZ_MEMBER_BYTES) {
					$commit();
				}
			}
			if ($handle) {
				fclose($handle);
			}
		}
		$writer->pad_to_block((int) fmod($declared, 512));
		$cursor['header'] = 0;
		$cursor['file_pos'] = 0;
		return true;
	}

	/* ----- Sending to a storage ----- */

	private static function phase_upload(array &$state, $deadline) {
		$location = Backup_Guard_Locations::get($state['location']);
		if (!$location) {
			throw new RuntimeException(__('L\'emplacement choisi n\'existe plus.', 'backup-guard'));
		}
		$storage = Backup_Guard_Storage::make($location);
		$local = self::final_archive($state);
		$remote = $state['name'] . '.tar.gz';
		if (empty($state['upload'])) {
			backup_guard_log(sprintf(__('Envoi vers %s', 'backup-guard'), $location['name']));
			$state['upload'] = array('begun' => 1);
		}
		$self = __CLASS__;
		$beat = function ($done, $total) use (&$state, $self) {
			$state['heartbeat'] = time();
			if ($total > 0) {
				$state['percent'] = 80 + (int) (18 * min(1, $done / $total));
			}
			call_user_func(array($self, 'save'), $state);
		};
		$finished = $storage->upload_step($local, $remote, $state['upload'], $deadline, $beat);
		if ($finished) {
			$info = self::build_info($state, $state['size']);
			// The archive is already stored. This small file only lets another site find the backup
			// by synchronising, so a refusal is reported but doesn't undo a finished upload.
			for ($try = 1; $try <= 2; $try++) {
				try {
					$storage->put_string($state['name'] . '.info', wp_json_encode($info));
					break;
				} catch (RuntimeException $e) {
					if ($try === 2) {
						backup_guard_log(sprintf(__('Le fichier d\'informations n\'a pas pu être envoyé : %s La sauvegarde est bien stockée, mais un autre site ne la retrouvera pas en synchronisant.', 'backup-guard'), $e->getMessage()), 'warning');
					}
				}
			}
			@unlink($local);
			backup_guard_log(__('Envoi terminé', 'backup-guard'));
			$state['phase'] = 'finish';
		}
	}

	/* ----- Bringing a backup back from a storage ----- */

	private static function phase_fetch(array &$state, $deadline) {
		$location = Backup_Guard_Locations::get($state['location']);
		if (!$location) {
			throw new RuntimeException(__('L\'emplacement de cette sauvegarde n\'existe plus.', 'backup-guard'));
		}
		$storage = Backup_Guard_Storage::make($location);
		$local = backup_guard_folder('backups') . '/.' . $state['name'] . '.tar.gz';
		$self = __CLASS__;
		$beat = function ($done, $total) use (&$state, $self) {
			$state['heartbeat'] = time();
			if ($total > 0) {
				$state['percent'] = 1 + (int) (97 * min(1, $done / $total));
			}
			call_user_func(array($self, 'save'), $state);
		};
		$finished = $storage->download_step($state['name'] . '.tar.gz', $local, $state['download'], $deadline, $beat);
		if (!$finished) {
			return;
		}
		$final = backup_guard_folder('backups') . '/' . $state['name'] . '.tar.gz';
		@rename($local, $final);
		backup_guard_log(sprintf(__('Sauvegarde récupérée : %s', 'backup-guard'), backup_guard_format_size(filesize($final))));
		$state['finished'] = 1;
		$state['status'] = 'success';
		$state['percent'] = 100;
		self::save($state);
		@unlink(self::state_file());
		Backup_Guard_Restore::launch($state['name'], $state['restore'], true);
	}

	/* ----- Ending ----- */

	private static function phase_finish(array &$state) {
		$size = (float) ($state['size'] ?? 0);
		$info = self::build_info($state, $size);
		Backup_Guard_Backups::save_info($info);
		update_option('backup_guard_last_backup', time(), false);

		self::keep_log($state);
		if (!empty($state['rotation'])) {
			Backup_Guard_Backups::rotate($state['location'], $state['rotation']);
		}
		backup_guard_rmdir(self::tmp_dir($state['name']));
		@unlink(BACKUP_GUARD_ROOT . 'state/stop');
		backup_guard_log(sprintf(__('Sauvegarde terminée : %1$d éléments, %2$s', 'backup-guard'), $state['added'], backup_guard_format_size($size)), 'success');

		$state['finished'] = 1;
		$state['status'] = 'success';
		$state['percent'] = 100;
		self::save($state);

		$where = $state['location'] !== '' ? (Backup_Guard_Locations::get($state['location'])['name'] ?? '') : __('Dossier local', 'backup-guard');
		$what = ($state['files'] && $state['db']) ? __('Fichiers et base de données', 'backup-guard') : ($state['files'] ? __('Fichiers', 'backup-guard') : __('Base de données', 'backup-guard'));
		$seconds = max(1, time() - (int) $state['started']);
		$title = sprintf(__('Sauvegarde réussie - %s', 'backup-guard'), Backup_Guard_Notifications::site_name());
		self::notify('success', $title,
			sprintf(__("La sauvegarde de votre site s'est bien terminée.\n\nSite : %1\$s\nNom : %2\$s\nContenu : %3\$s\nTaille : %4\$s\nEmplacement : %5\$s\nDurée : %6\$s", 'backup-guard'), home_url(), $state['name'], $what, backup_guard_format_size($size), $where, self::duration($seconds)),
			array(__('Site', 'backup-guard') => home_url(), __('Contenu', 'backup-guard') => $what, __('Taille', 'backup-guard') => backup_guard_format_size($size), __('Emplacement', 'backup-guard') => $where, __('Durée', 'backup-guard') => self::duration($seconds), __('Nom', 'backup-guard') => $state['name'])
		);
	}

	private static function duration($seconds) {
		return $seconds >= 90 ? sprintf(__('%d min', 'backup-guard'), round($seconds / 60)) : sprintf(__('%d s', 'backup-guard'), $seconds);
	}

	/** Keep a copy of the log next to the backup and on its storage. */
	private static function keep_log(array $state) {
		@copy(backup_guard_log_file(), BACKUP_GUARD_ROOT . 'logs/' . $state['name'] . '.log.php');
		if ($state['location'] !== '') {
			$location = Backup_Guard_Locations::get($state['location']);
			if ($location) {
				try {
					$content = preg_replace('/^<\?php exit;\?>\n/', '', (string) file_get_contents(backup_guard_log_file()));
					Backup_Guard_Storage::make($location)->put_string($state['name'] . '.log', $content);
				} catch (Exception $e) {
					backup_guard_debug(sprintf(__('Envoi du journal : %s', 'backup-guard'), $e->getMessage()));
				}
			}
		}
	}

	/** E-mail and every chat or push channel that asked to hear about this outcome. */
	private static function notify($event, $title, $message, array $details = array()) {
		try {
			Backup_Guard_Notifications::event($event, $title, $message, $details);
		} catch (Throwable $e) {
			// Telling the person must never turn a finished backup into a failed one.
			backup_guard_debug(sprintf(__('Notifications : %s', 'backup-guard'), $e->getMessage()));
		}
	}

	private static function clean_partial(array $state) {
		if ($state['kind'] === 'backup') {
			@unlink(self::working_archive($state));
			if ($state['phase'] === 'upload' && $state['location'] !== '') {
				$location = Backup_Guard_Locations::get($state['location']);
				if ($location) {
					try {
						Backup_Guard_Storage::make($location)->upload_abort($state['name'] . '.tar.gz', (array) ($state['upload'] ?? array()));
					} catch (Exception $e) {
						backup_guard_debug($e->getMessage());
					}
				}
				@unlink(self::final_archive($state));
			}
			backup_guard_rmdir(self::tmp_dir($state['name']));
		} else {
			@unlink(backup_guard_folder('backups') . '/.' . $state['name'] . '.tar.gz');
		}
		@unlink(BACKUP_GUARD_ROOT . 'state/stop');
	}

	private static function finish_stopped(array &$state) {
		self::clean_partial($state);
		backup_guard_log(__('Tâche arrêtée à votre demande.', 'backup-guard'), 'warning');
		$state['finished'] = 1;
		$state['status'] = 'stopped';
		self::save($state);
	}

	public static function fail(array &$state, $message) {
		self::clean_partial($state);
		backup_guard_log($message, 'error');
		$state['errors'][] = $message;
		$state['finished'] = 1;
		$state['status'] = 'error';
		self::save($state);
		if ($state['kind'] === 'backup') {
			self::notify('failure', sprintf(__('Échec de la sauvegarde - %s', 'backup-guard'), Backup_Guard_Notifications::site_name()),
				sprintf(__("La sauvegarde n'a pas abouti.\n\nSite : %1\$s\nRaison : %2\$s", 'backup-guard'), home_url(), $message),
				array(__('Site', 'backup-guard') => home_url(), __('Raison', 'backup-guard') => $message, __('Nom', 'backup-guard') => $state['name'] ?? '')
			);
		}
	}

	/** Let go of a job that's stuck, whatever it was doing. */
	public static function force_stop() {
		$state = self::current();
		if ($state && empty($state['finished'])) {
			self::clean_partial($state);
			backup_guard_log(__('Tâche arrêtée de force.', 'backup-guard'), 'warning');
			$state['finished'] = 1;
			$state['status'] = 'stopped';
			self::save($state);
		}
		@unlink(BACKUP_GUARD_ROOT . 'state/stop');
	}

	/**
	 * Push a job that went quiet. Called by WP-Cron and by the page watching the progress.
	 * A job that keeps going quiet is declared dead.
	 */
	public static function watchdog() {
		$state = self::current();
		if (!$state || !empty($state['finished'])) {
			return $state;
		}
		$quiet = time() - (int) $state['heartbeat'];
		// A job that never got its first slice, or whose calls to itself have already failed once,
		// is driven by whoever is watching, so there's no point waiting long.
		$patience = !empty($state['direct']) ? 3 : (empty($state['loop']) ? 8 : BACKUP_GUARD_STALL_SECONDS);
		if ($quiet < $patience) {
			return $state;
		}
		$state['direct'] = 1;
		$state['stalls'] = (int) $state['stalls'] + 1;
		if ($state['stalls'] > BACKUP_GUARD_MAX_STALLS && $quiet >= BACKUP_GUARD_TIMEOUT_SECONDS) {
			self::fail($state, sprintf(__('La tâche ne répond plus depuis %d minutes : le serveur l\'a probablement arrêtée.', 'backup-guard'), round($quiet / 60)) . Backup_Guard_Litespeed::stall_hint());
			return $state;
		}
		self::save($state);
		return self::run_slice(true);
	}
}

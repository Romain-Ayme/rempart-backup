<?php
if (!defined('ABSPATH')) {
	exit;
}

/** What the admin screens ask for while the page is open. Administrators only. */
class Rempart_Ajax {
	public static function init() {
		$actions = array(
			'start_backup', 'stop', 'force_stop', 'status', 'restore_start', 'restore_ack', 'restore_force_stop',
			'delete_backups', 'sync', 'test_location', 'quota', 'exclude_add', 'exclude_delete', 'tree', 'upload_chunk',
			'read_log', 'hide_nag', 'diagnose', 'litespeed', 'test_channel',
		);
		foreach ($actions as $action) {
			add_action('wp_ajax_rempart_' . $action, array(__CLASS__, 'guard'));
		}
		add_action('wp_ajax_rempart_download', array(__CLASS__, 'download'));
	}

	/** Every action passes through here: right person, right page, readable errors. */
	public static function guard() {
		check_ajax_referer('rempart');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Vous n\'avez pas les droits nécessaires.', 403);
		}
		$action = substr(current_action(), strlen('wp_ajax_rempart_'));
		try {
			wp_send_json_success(call_user_func(array(__CLASS__, 'do_' . $action)));
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage());
		}
	}

	private static function post($key, $default = '') {
		return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
	}

	private static function do_start_backup() {
		$state = Rempart_Job::start_backup(array(
			'files'    => !empty($_POST['files']),
			'db'       => !empty($_POST['db']),
			'scope'    => self::post('scope', 'all'),
			'location' => self::post('location'),
			'note'     => self::post('note'),
		));
		return array('name' => $state['name']);
	}

	private static function do_stop() {
		Rempart_Job::request_stop();
		return true;
	}

	private static function do_force_stop() {
		Rempart_Job::force_stop();
		return true;
	}

	/** Progress of the running job. It also pushes a job that went quiet. */
	private static function do_status() {
		$state = Rempart_Job::watchdog();
		$from = isset($_REQUEST['from']) ? (int) $_REQUEST['from'] : 0;
		list($lines, $next) = rempart_log_read($from);
		$out = array('log' => $lines, 'next' => $next);
		if (!$state) {
			$out += array('status' => 'idle', 'percent' => 0, 'kind' => '');
		} else {
			$out += array(
				'status'  => $state['status'],
				'percent' => (int) $state['percent'],
				'kind'    => $state['kind'],
				'phase'   => $state['phase'],
				'errors'  => $state['errors'],
				'name'    => $state['name'],
			);
		}
		// A fetch hands over to the restore program when the file has arrived.
		$restore = Rempart_Restore::current();
		if ($restore) {
			$out['restore'] = array('url' => $restore['runner_url'], 'key' => $restore['key']);
		}
		return $out;
	}

	private static function do_restore_start() {
		$result = Rempart_Restore::start(self::post('name'), array('files' => !empty($_POST['files']), 'db' => !empty($_POST['db'])));
		return $result;
	}

	private static function do_restore_ack() {
		Rempart_Restore::acknowledge();
		return true;
	}

	private static function do_restore_force_stop() {
		Rempart_Restore::force_stop();
		return true;
	}

	private static function do_delete_backups() {
		$names = isset($_POST['names']) ? (array) wp_unslash($_POST['names']) : array();
		$errors = array();
		foreach ($names as $name) {
			try {
				Rempart_Backups::delete(sanitize_text_field($name));
			} catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}
		if ($errors) {
			throw new RuntimeException(implode(' ', $errors));
		}
		return true;
	}

	private static function do_sync() {
		$id = self::post('location');
		if ($id === '' || $id === 'local') {
			return array('added' => Rempart_Backups::sync_local(), 'removed' => 0);
		}
		return Rempart_Backups::sync_location($id);
	}

	private static function do_test_location() {
		$location = Rempart_Locations::get(self::post('id'));
		if (!$location) {
			throw new RuntimeException('Cet emplacement n\'existe plus.');
		}
		Rempart_Storage::make($location)->test();
		return 'Connexion réussie, le dossier est accessible en écriture.';
	}

	private static function do_quota() {
		$id = self::post('id');
		$location = Rempart_Locations::get($id);
		if (!$location) {
			throw new RuntimeException('Cet emplacement n\'existe plus.');
		}
		$quota = Rempart_Storage::make($location)->quota();
		Rempart_Locations::set_quota($id, $quota);
		if (!$quota) {
			return array('text' => '-');
		}
		return array('text' => rempart_format_size($quota['used']) . ($quota['total'] ? ' / ' . rempart_format_size($quota['total']) : ''));
	}

	private static function do_exclude_add() {
		$type = self::post('type');
		$pattern = self::post('pattern');
		if (!in_array($type, array('exact', 'extension', 'beginning', 'end', 'anywhere'), true) || $pattern === '') {
			throw new RuntimeException('Règle incomplète.');
		}
		if ($type === 'exact') {
			$pattern = trim(str_replace(array('..', '\\'), array('', '/'), $pattern), '/');
		}
		if ($type === 'extension') {
			$pattern = ltrim($pattern, '.');
		}
		$excludes = rempart_excludes();
		if (in_array($pattern, $excludes[$type], true)) {
			throw new RuntimeException('Cette règle existe déjà.');
		}
		$key = wp_generate_password(8, false);
		$excludes[$type][$key] = $pattern;
		update_option('rempart_excludes', $excludes, false);
		return array('key' => $key, 'pattern' => $pattern);
	}

	private static function do_exclude_delete() {
		$type = self::post('type');
		$key = self::post('key');
		$excludes = rempart_excludes();
		if (!isset($excludes[$type][$key])) {
			throw new RuntimeException('Règle introuvable.');
		}
		unset($excludes[$type][$key]);
		update_option('rempart_excludes', $excludes, false);
		return true;
	}

	/** One level of the site's folders, for the exclude picker. */
	private static function do_tree() {
		$relative = trim(str_replace(array('..', '\\'), array('', '/'), self::post('path')), '/');
		$root = rempart_clean_path(ABSPATH);
		$dir = $root . ($relative === '' ? '' : '/' . $relative);
		$items = @scandir($dir);
		$out = array();
		if ($items) {
			foreach ($items as $item) {
				if ($item === '.' || $item === '..') {
					continue;
				}
				$path = $relative === '' ? $item : $relative . '/' . $item;
				$out[] = array('name' => $item, 'path' => $path, 'dir' => is_dir($dir . '/' . $item));
			}
			usort($out, function ($a, $b) {
				return $a['dir'] === $b['dir'] ? strcasecmp($a['name'], $b['name']) : ($a['dir'] ? -1 : 1);
			});
		}
		return $out;
	}

	/** A backup file sent from the browser, in pieces. */
	private static function do_upload_chunk() {
		$file_name = self::post('file_name');
		if (!preg_match('/\.tar\.gz$/i', $file_name)) {
			throw new RuntimeException('Ce fichier n\'est pas une sauvegarde : il doit se terminer par .tar.gz');
		}
		$name = rempart_safe_backup_name($file_name);
		if ($name === '') {
			throw new RuntimeException('Nom de fichier invalide.');
		}
		$dir = rempart_folder('backups');
		$working = $dir . '/.' . $name . '.tar.gz';
		$final = $dir . '/' . $name . '.tar.gz';

		if (!empty($_POST['abort'])) {
			@unlink($working);
			return true;
		}
		if (file_exists($final)) {
			throw new RuntimeException('Un fichier du même nom existe déjà.');
		}
		$number = (int) self::post('chunk');
		$total = (int) self::post('total');
		if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
			throw new RuntimeException('Le morceau de fichier n\'est pas arrivé. Le serveur limite peut-être la taille des envois.');
		}
		$data = file_get_contents($_FILES['file']['tmp_name']);
		if (strlen($data) > REMPART_UPLOAD_CHUNK || ($number < $total && strlen($data) !== REMPART_UPLOAD_CHUNK)) {
			throw new RuntimeException('Morceau de fichier de taille inattendue.');
		}
		$handle = fopen($working, 'c+b');
		fseek($handle, ($number - 1) * REMPART_UPLOAD_CHUNK);
		fwrite($handle, $data);
		fclose($handle);

		if ($number !== $total) {
			return array('done' => false);
		}
		$probe = fopen($working, 'rb');
		$magic = fread($probe, 2);
		fclose($probe);
		if ($magic !== "\x1f\x8b") {
			@unlink($working);
			throw new RuntimeException('Ce fichier n\'est pas une archive .tar.gz valide.');
		}
		rename($working, $final);
		$info = Rempart_Backups::read_archive_info($final, $name);
		if (!$info) {
			@unlink($final);
			throw new RuntimeException('Cette archive est illisible.');
		}
		Rempart_Backups::save_info($info);
		return array('done' => true, 'name' => $name);
	}

	private static function do_read_log() {
		$what = self::post('what');
		if ($what === 'backup') {
			$file = rempart_log_file();
		} elseif ($what === 'restore') {
			$file = REMPART_ROOT . 'logs/last-restore.log.php';
			if (!file_exists($file)) {
				$file = rempart_log_file('restore');
			}
		} else {
			$file = REMPART_ROOT . 'logs/' . rempart_safe_backup_name($what) . '.log.php';
		}
		list($lines) = rempart_log_read(0, $file);
		return $lines;
	}

	private static function do_test_channel() {
		Rempart_Notifications::test(self::post('id'));
		return 'Message de test envoyé : vérifiez qu\'il est bien arrivé.';
	}

	/** Adds (form 1 or 2) or removes the LiteSpeed rule in .htaccess. */
	private static function do_litespeed() {
		$what = self::post('what');
		if ($what === 'remove') {
			$result = Rempart_Litespeed::remove();
		} elseif ($what === '1' || $what === '2') {
			$result = Rempart_Litespeed::apply((int) $what);
		} else {
			throw new RuntimeException('Action inconnue.');
		}
		$status = Rempart_Litespeed::status();
		return array('level' => $result[0], 'text' => $result[1], 'status' => $status[1], 'present' => $status[2]);
	}

	private static function do_hide_nag() {
		update_option('rempart_nag_time', time(), false);
		return true;
	}

	/** Checks the conditions backups depend on, in the person's own words where possible. */
	private static function do_diagnose() {
		$checks = array();
		$add = function ($label, $ok, $detail) use (&$checks) {
			$checks[] = array('label' => $label, 'ok' => (bool) $ok, 'detail' => $detail);
		};
		$add('Version de PHP', version_compare(PHP_VERSION, '8.3', '>='), PHP_VERSION);
		$add('Module zlib (compression)', function_exists('inflate_init'), function_exists('inflate_init') ? 'Présent' : 'Absent : les sauvegardes sont impossibles');
		$add('Module cURL (WebDAV)', function_exists('curl_init'), function_exists('curl_init') ? 'Présent' : 'Absent : l\'envoi WebDAV est impossible');
		$add('Dossier de travail', is_writable(REMPART_ROOT), is_writable(REMPART_ROOT) ? 'Accessible en écriture' : 'Impossible d\'écrire dans ' . REMPART_ROOT);

		$free = @disk_free_space(REMPART_ROOT);
		$add('Espace disque libre', $free === false || $free > 104857600, $free === false ? 'Inconnu' : rempart_format_size($free));

		$limit = (int) ini_get('max_execution_time');
		$add('Temps maximum d\'un script', $limit === 0 || $limit >= REMPART_SLICE_SECONDS + 5, $limit === 0 ? 'Illimité' : $limit . ' s');
		$add('Mémoire PHP', wp_convert_hr_to_bytes(ini_get('memory_limit')) >= 67108864 || (int) ini_get('memory_limit') === -1, (string) ini_get('memory_limit'));

		$response = wp_remote_post(admin_url('admin-ajax.php'), array(
			'timeout'   => 10,
			'sslverify' => apply_filters('https_local_ssl_verify', false),
			'body'      => array('action' => 'rempart_slice', 'token' => 'test'),
		));
		$code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
		$add('Le site peut s\'appeler lui-même', $code === 403, $code === 403 ? 'Oui' : 'Non : gardez la page ouverte pendant les sauvegardes, elle prendra le relais (' . (is_wp_error($response) ? $response->get_error_message() : 'code ' . $code) . ')');

		$add('WP-Cron', !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON), (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'Désactivé : utilisez la planification personnalisée' : 'Actif');

		$litespeed = Rempart_Litespeed::status();
		$add('LiteSpeed : règle « noabort »', $litespeed[0] !== 'warning', $litespeed[1]);

		$blocked = false;
		$backups_dir = rempart_folder('backups');
		if ($backups_dir) {
			$public = content_url('rempart/' . basename($backups_dir) . '/index.html');
			$probe = wp_remote_get($public, array('timeout' => 10, 'sslverify' => false));
			$blocked = !is_wp_error($probe) && (int) wp_remote_retrieve_response_code($probe) === 403;
		}
		$add('Dossier des sauvegardes protégé', $blocked, $blocked ? 'Inaccessible depuis le web' : 'Le serveur ignore .htaccess (nginx ?). Le nom du dossier reste aléatoire ; ajoutez une règle pour le bloquer.');
		return $checks;
	}

	/** Send a backup to the browser; one kept on a storage is fetched first, on the way through. */
	public static function download() {
		check_admin_referer('rempart');
		if (!current_user_can('manage_options')) {
			wp_die('Vous n\'avez pas les droits nécessaires.', 403);
		}
		$name = rempart_safe_backup_name(isset($_GET['name']) ? wp_unslash($_GET['name']) : '');
		$info = Rempart_Backups::get($name);
		if (!$info) {
			wp_die('Sauvegarde introuvable.', 404);
		}
		$path = Rempart_Backups::archive_path($name);
		$remove_after = false;
		if (!file_exists($path)) {
			$location = !empty($info['location']) ? Rempart_Locations::get($info['location']) : null;
			if (!$location) {
				wp_die('Le fichier de cette sauvegarde est introuvable.', 404);
			}
			@set_time_limit(0);
			$tmp = rempart_folder('backups') . '/.dl-' . $name . '.tar.gz';
			try {
				$storage = Rempart_Storage::make($location);
				$state = array();
				$noop = function () {
				};
				while (!$storage->download_step($name . '.tar.gz', $tmp, $state, time() + 60, $noop)) {
					continue;
				}
			} catch (Exception $e) {
				@unlink($tmp);
				wp_die(esc_html($e->getMessage()));
			}
			$path = $tmp;
			$remove_after = true;
		}
		while (ob_get_level()) {
			ob_end_clean();
		}
		$size = filesize($path);
		$start = 0;
		$end = $size - 1;
		header('Accept-Ranges: bytes');
		if (!$remove_after && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
			$start = (int) $m[1];
			if ($m[2] !== '') {
				$end = min((int) $m[2], $end);
			}
			if ($start > $end) {
				header('HTTP/1.1 416 Range Not Satisfiable');
				header('Content-Range: bytes */' . $size);
				exit;
			}
			header('HTTP/1.1 206 Partial Content');
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
		}
		header('Content-Type: application/gzip');
		header('Content-Disposition: attachment; filename="' . $name . '.tar.gz"');
		header('Content-Length: ' . ($end - $start + 1));
		header('X-Content-Type-Options: nosniff');
		@set_time_limit(0);
		$handle = fopen($path, 'rb');
		fseek($handle, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($handle) && !connection_aborted()) {
			$chunk = fread($handle, (int) min(1048576, $left));
			echo $chunk;
			flush();
			$left -= strlen($chunk);
		}
		fclose($handle);
		if ($remove_after) {
			@unlink($path);
		}
		exit;
	}
}

<?php
/**
 * Provokes the errors a backup can meet and checks that the plugin reacts properly: a clear message,
 * no job left hanging, nothing half-written kept, and no secret in the text.
 *
 *   npx @wordpress/env@12 run cli wp eval-file wp-content/plugins/rempart-backup/tests/integration/errors.php
 *
 * It restores a database at the end: run it on a test site only (the wp-env one).
 */
if (!defined('ABSPATH') || !class_exists('Rempart_Job')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

foreach (array('ftp', 'sftp', 'webdav', 's3') as $driver) {
	require_once REMPART_DIR . '/includes/storage/class-' . $driver . '.php';
}

$env = function ($name, $default) {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
};

function rempart_it_expect($condition, $text) {
	if (!$condition) {
		throw new RuntimeException($text);
	}
}

/** Run a started job to its end. */
function rempart_it_finish($limit = 120) {
	$end = time() + $limit;
	do {
		$state = Rempart_Job::run_slice(false);
		if ($state && empty($state['finished'])) {
			usleep(100000);
		}
	} while ($state && empty($state['finished']) && time() < $end);
	rempart_it_expect($state && !empty($state['finished']), 'La tâche ne se termine pas.');
	return $state;
}

/** The call must throw, with a readable message that does not contain the given secrets. */
function rempart_it_throws(callable $call, array $secrets = array(), $what = 'L\'appel') {
	try {
		$call();
	} catch (Throwable $e) {
		$message = $e->getMessage();
		rempart_it_expect(trim($message) !== '', $what . ' a échoué sans message.');
		foreach ($secrets as $secret) {
			rempart_it_expect(strpos($message, $secret) === false, 'Le secret apparaît dans le message : ' . $message);
		}
		return $message;
	}
	throw new RuntimeException($what . ' aurait dû échouer.');
}

function rempart_it_run_cli($command) {
	return WP_CLI::runcommand($command, array('return' => 'all', 'launch' => false, 'exit_error' => false));
}

// Keep the backups small and fast, as in backups.php.
$previous_excludes = get_option('rempart_excludes', null);
update_option('rempart_excludes', array('exact' => array('wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes'), 'extension' => array(), 'beginning' => array(), 'end' => array(), 'anywhere' => array()), false);
Rempart_Job::force_stop();
$created_backups = array();

$cases = array(

	'mauvais identifiants SFTP' => function () use ($env) {
		$storage = new Rempart_Storage_Sftp(array('host' => $env('IT_SFTP_HOST', 'rempart-sftp'), 'port' => 22, 'user' => 'bob', 'pass' => 'mauvais-mdp-xyz', 'folder' => 'upload'));
		rempart_it_throws(function () use ($storage) { $storage->test(); }, array('mauvais-mdp-xyz'), 'SFTP');
	},
	'mauvais identifiants FTP' => function () use ($env) {
		$storage = new Rempart_Storage_Ftp(array('host' => $env('IT_FTP_HOST', 'rempart-ftp'), 'port' => 21, 'protocol' => 'ftp', 'user' => 'bob', 'pass' => 'mauvais-mdp-xyz', 'folder' => ''));
		rempart_it_throws(function () use ($storage) { $storage->test(); }, array('mauvais-mdp-xyz'), 'FTP');
	},
	'mauvais identifiants FTPS' => function () use ($env) {
		$storage = new Rempart_Storage_Ftp(array('host' => $env('IT_FTPS_HOST', 'rempart-ftps'), 'port' => 21, 'protocol' => 'ftps', 'insecure_tls' => 1, 'user' => 'bob', 'pass' => 'mauvais-mdp-xyz', 'folder' => ''));
		rempart_it_throws(function () use ($storage) { $storage->test(); }, array('mauvais-mdp-xyz'), 'FTPS');
	},
	'mauvais identifiants WebDAV' => function () use ($env) {
		$storage = new Rempart_Storage_Webdav(array('url' => $env('IT_WEBDAV_URL', 'http://rempart-dav/'), 'user' => 'bob', 'pass' => 'mauvais-mdp-xyz', 'folder' => ''));
		rempart_it_throws(function () use ($storage) { $storage->test(); }, array('mauvais-mdp-xyz'), 'WebDAV');
	},
	'mauvaise clé S3' => function () use ($env) {
		$config = array('endpoint' => $env('IT_S3_ENDPOINT', 'http://rempart-s3:3900'), 'region' => 'us-east-1', 'service' => 'custom', 'bucket' => 'rempart-it', 'access_key' => 'GK00000000000000000000000a', 'secret_key' => 'mauvais-secret-s3-xyz', 'folder' => '');
		rempart_it_throws(function () use ($config) { (new Rempart_Storage_S3($config))->test(); }, array('mauvais-secret-s3-xyz'), 'S3');
	},
	'bucket S3 inexistant' => function () use ($env) {
		$config = array('endpoint' => $env('IT_S3_ENDPOINT', 'http://rempart-s3:3900'), 'region' => 'us-east-1', 'service' => 'custom', 'bucket' => 'rempart-absent', 'access_key' => 'GK00000000000000000000000a', 'secret_key' => '0000000000000000000000000000000000000000000000000000000000000001', 'folder' => '');
		rempart_it_throws(function () use ($config) { (new Rempart_Storage_S3($config))->test(); }, array(), 'S3');
	},

	'wordpress.org injoignable' => function () {
		$sums = function () {
			return strpos(func_get_args()[2], 'api.wordpress.org') !== false ? new WP_Error('http_request_failed', 'Réseau coupé pour le test') : false;
		};
		// The list may already be cached from an earlier run.
		delete_transient('rempart_core_' . md5(get_bloginfo('version') . get_locale()));
		add_filter('pre_http_request', $sums, 10, 3);
		try {
			foreach (array('content', 'modified') as $scope) {
				$message = rempart_it_throws(function () use ($scope) {
					Rempart_Job::start_backup(array('files' => true, 'db' => false, 'location' => '', 'scope' => $scope));
				}, array(), 'Sauvegarde « ' . $scope . ' »');
				rempart_it_expect(strpos($message, 'wordpress.org') !== false, 'Le message ne parle pas de wordpress.org : ' . $message);
				rempart_it_expect(!Rempart_Job::is_active(), 'Une tâche est restée active.');
			}
		} finally {
			remove_filter('pre_http_request', $sums, 10);
		}
	},

	'deuxième sauvegarde refusée' => function () use (&$created_backups) {
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		try {
			$message = rempart_it_throws(function () {
				Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
			}, array(), 'La deuxième sauvegarde');
			rempart_it_expect(preg_match('/déjà en cours|already running/', $message), 'Message inattendu : ' . $message);
		} finally {
			Rempart_Job::force_stop();
		}
		rempart_it_expect(!Rempart_Job::is_active(), 'La tâche est restée active.');
	},

	'deux démarrages en même temps : le verrou refuse le second' => function () use (&$created_backups) {
		$lock = fopen(REMPART_ROOT . 'state/start.lock', 'c');
		rempart_it_expect($lock && flock($lock, LOCK_EX | LOCK_NB), 'Impossible de prendre le verrou de démarrage pour le test.');
		try {
			// Another request is in the middle of starting: this one must be refused, not start a second job.
			$message = rempart_it_throws(function () {
				Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
			}, array(), 'Le second démarrage');
			rempart_it_expect(preg_match('/déjà en cours|already running/', $message), 'Message inattendu : ' . $message);
			rempart_it_expect(!Rempart_Job::is_active(), 'Une tâche a démarré malgré le verrou.');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	},

	'deux processus lancent une sauvegarde ensemble : une seule démarre' => function () use (&$created_backups) {
		$code = 'try { $j = Rempart_Job::start_backup(array("files" => false, "db" => true, "location" => "")); echo "DEMARRE:" . $j["name"]; } catch (Exception $e) { echo "REFUSE"; }';
		$processes = array();
		for ($i = 0; $i < 4; $i++) {
			$command = 'wp eval ' . escapeshellarg($code) . ' --path=' . escapeshellarg(rtrim(ABSPATH, '/')) . ' --skip-themes 2>/dev/null';
			$processes[] = proc_open($command, array(1 => array('pipe', 'w')), $pipes[$i]);
		}
		$started = 0;
		$refused = 0;
		foreach ($processes as $i => $process) {
			$out = stream_get_contents($pipes[$i][1]);
			proc_close($process);
			if (preg_match('/DEMARRE:(\S+)/', $out, $m)) {
				$started++;
				$created_backups[] = $m[1];
			} elseif (strpos($out, 'REFUSE') !== false) {
				$refused++;
			}
		}
		Rempart_Job::force_stop();
		rempart_it_expect($started === 1 && $refused === 3, 'Attendu : 1 démarrée et 3 refusées, obtenu : ' . $started . ' démarrée(s), ' . $refused . ' refusée(s).');
	},

	'tâche bloquée : le chien de garde la relance' => function () use (&$created_backups) {
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		$state = Rempart_Job::current();
		$state['heartbeat'] = time() - 120;
		$state['loop'] = 0;
		Rempart_Job::save($state);
		$state = Rempart_Job::watchdog();
		rempart_it_expect(!empty($state['direct']), 'Le chien de garde n\'a pas pris la main.');
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'success', 'État : ' . $state['status'] . ' ' . implode(' ', $state['errors']));
	},

	'tâche perdue : le chien de garde la déclare en échec' => function () use (&$created_backups) {
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		$state = Rempart_Job::current();
		$state['heartbeat'] = time() - REMPART_TIMEOUT_SECONDS - 10;
		$state['stalls'] = REMPART_MAX_STALLS + 1;
		$state['direct'] = 1;
		Rempart_Job::save($state);
		$state = Rempart_Job::watchdog();
		rempart_it_expect($state['status'] === 'error', 'État attendu : error, obtenu : ' . $state['status']);
		rempart_it_expect(!empty($state['errors']) && preg_match('/ne répond plus|has not responded/', $state['errors'][0]), 'Message d\'erreur inattendu.');
		rempart_it_expect(!Rempart_Job::is_active(), 'La tâche est restée active.');
	},

	'service coupé pendant un gros envoi (S3)' => function () use ($env) {
		$config = array('endpoint' => $env('IT_S3_ENDPOINT', 'http://rempart-s3:3900'), 'region' => 'us-east-1', 'service' => 'custom', 'bucket' => 'rempart-it', 'access_key' => 'GK00000000000000000000000a', 'secret_key' => '0000000000000000000000000000000000000000000000000000000000000001', 'folder' => '');
		$storage = new Rempart_Storage_S3($config);
		$source = tempnam(sys_get_temp_dir(), 'rempart-it-');
		file_put_contents($source, random_bytes(12 * 1024 * 1024));
		$calls = 0;
		// The first requests go through, then the network drops.
		$cut = function ($pre) use (&$calls) {
			return ++$calls > 3 ? new WP_Error('http_request_failed', 'Connexion perdue pour le test') : $pre;
		};
		add_filter('pre_http_request', $cut, 10, 1);
		try {
			$state = array();
			$steps = 0;
			rempart_it_throws(function () use ($storage, $source, &$state, &$steps) {
				while (!$storage->upload_step($source, 'rempart-it-coupure.bin', $state, time() + 60, function () {})) {
					if (++$steps > 100) {
						throw new LogicException('Aucune erreur après 100 étapes : l\'envoi continue malgré la coupure.');
					}
				}
			}, array(), 'L\'envoi');
		} finally {
			remove_filter('pre_http_request', $cut, 10);
			unlink($source);
		}
		// Nothing half-sent may be left listed under that name.
		$names = array_column($storage->list_files(), 'name');
		if (in_array('rempart-it-coupure.bin', $names, true)) {
			$storage->delete('rempart-it-coupure.bin');
			throw new RuntimeException('Un fichier incomplet est resté sur le serveur.');
		}
	},

	'fichier illisible' => function () use (&$created_backups) {
		$file = WP_CONTENT_DIR . '/uploads/rempart-it-illisible.txt';
		wp_mkdir_p(dirname($file));
		file_put_contents($file, 'secret');
		chmod($file, 0000);
		try {
			if (is_readable($file)) {
				WP_CLI::warning('fichier illisible : ignoré (le test tourne avec un utilisateur qui lit tout)');
				return;
			}
			$job = Rempart_Job::start_backup(array('files' => true, 'db' => false, 'location' => ''));
			$created_backups[] = $job['name'];
			$state = rempart_it_finish();
			rempart_it_expect($state['status'] === 'success', 'Un fichier illisible ne doit pas faire échouer la sauvegarde : ' . $state['status'] . ' ' . implode(' ', $state['errors']));
		} finally {
			chmod($file, 0644);
			unlink($file);
		}
	},

	'archive tronquée : lecture et restauration' => function () use (&$created_backups) {
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'success', 'La sauvegarde de départ a échoué.');
		$archive = Rempart_Backups::archive_path($job['name']);
		$whole = file_get_contents($archive);
		file_put_contents($archive, substr($whole, 0, (int) (strlen($whole) * 0.5)));
		$result = rempart_it_run_cli('rempart restore ' . $job['name'] . ' --db-only --yes');
		rempart_it_expect((int) $result->return_code !== 0, 'La restauration d\'une archive tronquée aurait dû échouer.');
		if (Rempart_Restore::current()) {
			Rempart_Restore::acknowledge();
		}
		rempart_it_expect(!Rempart_Restore::is_running(), 'Une restauration est restée en cours.');
	},

	'restauration d\'une sauvegarde inconnue' => function () {
		rempart_it_throws(function () {
			Rempart_Restore::start('wp_inconnu_2000-01-01_00-00-00_abcdef', array('files' => true, 'db' => true));
		}, array(), 'La restauration');
	},

	'restauration de la base : réussite' => function () use (&$created_backups) {
		global $wpdb;
		update_option('rempart_it_marker', 'avant', false);
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'success', 'La sauvegarde de départ a échoué.');
		update_option('rempart_it_marker', 'apres', false);
		$result = rempart_it_run_cli('rempart restore ' . $job['name'] . ' --db-only --yes');
		rempart_it_expect((int) $result->return_code === 0, 'La restauration a échoué : ' . $result->stderr . ' ' . $result->stdout);
		$value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'rempart_it_marker'));
		rempart_it_expect($value === 'avant', 'La base n\'est pas revenue à son état d\'avant : « ' . $value . ' ».');
		$wpdb->delete($wpdb->options, array('option_name' => 'rempart_it_marker'));
	},
);

$failed = 0;
foreach ($cases as $name => $case) {
	try {
		$case();
		WP_CLI::success($name . ' : OK');
	} catch (Throwable $e) {
		$failed++;
		WP_CLI::warning($name . ' : ÉCHEC : ' . $e->getMessage());
	}
	// A case must not leave a job behind for the next one.
	Rempart_Job::force_stop();
}

// Leave the site as found.
foreach ($created_backups as $name) {
	try {
		if (Rempart_Backups::get($name) || is_file(Rempart_Backups::archive_path($name))) {
			Rempart_Backups::delete($name);
		}
	} catch (Throwable $e) {
		WP_CLI::warning('Nettoyage de ' . $name . ' : ' . $e->getMessage());
	}
}
if ($previous_excludes === null) {
	delete_option('rempart_excludes');
} else {
	update_option('rempart_excludes', $previous_excludes, false);
}

if ($failed > 0) {
	WP_CLI::error($failed . ' scénario(s) en échec.');
}

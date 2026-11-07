<?php
/**
 * Runs whole backups in WordPress, against the test services, and checks what comes out:
 * a full local backup, a backup sent to SFTP then deleted, a backup that is refused at the start,
 * one that fails halfway, one that is stopped, and the notification each outcome sends.
 *
 *   npx @wordpress/env@12 run cli wp eval-file wp-content/plugins/rempart-backup/tests/integration/backups.php
 */
if (!defined('ABSPATH') || !class_exists('Rempart_Job')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

$env = function ($name, $default) {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
};
$mock = $env('IT_MOCK_URL', 'http://rempart-mock');

function rempart_it_expect($condition, $text) {
	if (!$condition) {
		throw new RuntimeException($text);
	}
}

/** Run a started job to its end, the way `wp rempart backup` does. */
function rempart_it_finish($limit = 180) {
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

function rempart_it_received($mock) {
	$response = wp_remote_get($mock . '/_requests', array('timeout' => 10));
	$found = array();
	foreach ((array) json_decode(wp_remote_retrieve_body($response), true) as $request) {
		if (strpos($request['path'], '/webhook') === 0) {
			$found[] = json_decode($request['body'], true);
		}
	}
	return $found;
}

function rempart_it_clear($mock) {
	wp_remote_request($mock . '/_requests', array('method' => 'DELETE'));
}

/** Names of the entries of an archive. */
function rempart_it_entries($archive) {
	$names = array();
	$reader = new Rempart_Tar_Reader($archive);
	while ($entry = $reader->next_entry()) {
		$names[] = $entry['name'];
		$reader->read((int) (ceil($entry['size'] / 512) * 512));
	}
	$reader->close();
	return $names;
}

/** Run a files-only backup with a scope and return the names it holds. */
function rempart_it_scoped($scope, array &$created_backups) {
	$job = Rempart_Job::start_backup(array('files' => true, 'db' => false, 'location' => '', 'scope' => $scope));
	$created_backups[] = $job['name'];
	$state = rempart_it_finish();
	rempart_it_expect($state['status'] === 'success', 'État : ' . $state['status'] . ' ' . implode(' ', $state['errors']));
	return rempart_it_entries(Rempart_Backups::archive_path($job['name']));
}

function rempart_it_sftp() {
	return Rempart_Locations::save(array(
		'protocol' => 'sftp', 'name' => 'IT SFTP', 'host' => getenv('IT_SFTP_HOST') ?: 'rempart-sftp', 'port' => 22,
		'user' => 'bob', 'pass' => 'pass123', 'folder' => 'upload',
	));
}

function rempart_it_s3($bucket, $id = null) {
	return Rempart_Locations::save(array(
		'protocol' => 's3', 'name' => 'IT S3', 'service' => 'custom', 'endpoint' => getenv('IT_S3_ENDPOINT') ?: 'http://rempart-s3:3900',
		'region' => 'us-east-1', 'bucket' => $bucket, 'access_key' => 'GK00000000000000000000000a',
		'secret_key' => '0000000000000000000000000000000000000000000000000000000000000001', 'folder' => '',
	), $id);
}

// A whole WordPress is slow to archive on a Docker bind mount: keep only a small part for the test.
$previous_excludes = get_option('rempart_excludes', null);
update_option('rempart_excludes', array('exact' => array('wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes'), 'extension' => array(), 'beginning' => array(), 'end' => array(), 'anywhere' => array()), false);
Rempart_Job::force_stop();

$created_backups = array();
$created_locations = array();
$channel = Rempart_Notifications::save(array('type' => 'webhook', 'name' => 'IT', 'url' => $mock . '/webhook', 'enabled' => 1, 'on_success' => 1, 'on_failure' => 1));

$cases = array(
	'sauvegarde locale complète' => function () use ($mock, &$created_backups) {
		rempart_it_clear($mock);
		$job = Rempart_Job::start_backup(array('files' => true, 'db' => true, 'location' => '', 'note' => 'test de la CI'));
		$created_backups[] = $job['name'];
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'success', 'État : ' . $state['status'] . ' ' . implode(' ', $state['errors']));
		$info = Rempart_Backups::get($job['name']);
		rempart_it_expect(is_array($info), 'La fiche de la sauvegarde manque.');
		$archive = Rempart_Backups::archive_path($job['name']);
		rempart_it_expect(is_file($archive) && filesize($archive) > 1000, 'L\'archive est absente ou vide.');
		rempart_it_expect(file_get_contents($archive, false, null, 0, 2) === "\x1f\x8b", 'L\'archive n\'est pas un gzip.');
		$names = array_column(Rempart_Backups::all(), 'name');
		rempart_it_expect(in_array($job['name'], $names, true), 'La sauvegarde n\'est pas dans la liste.');
		$events = array_column(rempart_it_received($mock), 'event');
		rempart_it_expect(in_array('success', $events, true), 'Aucune notification de réussite reçue.');
	},
	'base de données seule vers SFTP, puis suppression' => function () use (&$created_backups, &$created_locations) {
		$location = rempart_it_sftp();
		$created_locations[] = $location['id'];
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => (string) $location['id']));
		$created_backups[] = $job['name'];
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'success', 'État : ' . $state['status'] . ' ' . implode(' ', $state['errors']));
		$storage = Rempart_Storage::make(Rempart_Locations::get($location['id']));
		$remote = array_column($storage->list_files(), 'name');
		rempart_it_expect(in_array($job['name'] . '.tar.gz', $remote, true), 'L\'archive n\'est pas sur le serveur SFTP.');
		Rempart_Backups::delete($job['name']);
		$remote = array_column($storage->list_files(), 'name');
		rempart_it_expect(!in_array($job['name'] . '.tar.gz', $remote, true), 'L\'archive est restée sur le serveur SFTP après la suppression.');
		rempart_it_expect(Rempart_Backups::get($job['name']) === null, 'La fiche est restée après la suppression.');
	},
	'refus au démarrage (emplacement injoignable)' => function () use (&$created_locations) {
		$location = Rempart_Locations::save(array('protocol' => 'sftp', 'name' => 'IT mort', 'host' => 'rempart-inexistant', 'port' => 22, 'user' => 'bob', 'pass' => 'x', 'folder' => ''));
		$created_locations[] = $location['id'];
		try {
			Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => (string) $location['id']));
		} catch (RuntimeException $e) {
			rempart_it_expect($e->getMessage() !== '', 'Message d\'erreur vide.');
			rempart_it_expect(!Rempart_Job::is_active(), 'Une tâche est restée active.');
			return;
		}
		throw new RuntimeException('La sauvegarde aurait dû être refusée.');
	},
	'échec en cours de route et notification d\'échec' => function () use ($mock, &$created_backups, &$created_locations) {
		rempart_it_clear($mock);
		$location = rempart_it_s3('rempart-it');
		$created_locations[] = $location['id'];
		$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => (string) $location['id']));
		$created_backups[] = $job['name'];
		// The start-up check passed; now the bucket disappears from under the job.
		rempart_it_s3('rempart-absent', $location['id']);
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'error', 'État attendu : error, obtenu : ' . $state['status']);
		rempart_it_expect(!empty($state['errors']), 'Aucune erreur enregistrée.');
		rempart_it_expect(Rempart_Backups::get($job['name']) === null, 'Une sauvegarde ratée a laissé une fiche.');
		$events = array_column(rempart_it_received($mock), 'event');
		rempart_it_expect(in_array('failure', $events, true), 'Aucune notification d\'échec reçue.');
	},
	'fichiers : mes fichiers seulement' => function () use (&$created_backups) {
		$names = rempart_it_scoped('content', $created_backups);
		rempart_it_expect(in_array('wp-config.php', $names, true), 'wp-config.php devrait être gardé.');
		foreach (array('index.php', 'wp-cron.php', 'wp-load.php', 'readme.html') as $core) {
			rempart_it_expect(!in_array($core, $names, true), $core . ' est un fichier de WordPress, il ne devrait pas être gardé.');
		}
	},
	'fichiers : mes fichiers et les fichiers WordPress modifiés' => function () use (&$created_backups) {
		$edited = ABSPATH . 'readme.html';
		$original = file_get_contents($edited);
		rempart_it_expect(is_writable($edited), "readme.html n'est pas modifiable pour le test.");
		file_put_contents($edited, $original . "
<!-- modifié par le test -->");
		try {
			$names = rempart_it_scoped('modified', $created_backups);
		} finally {
			file_put_contents($edited, $original);
		}
		rempart_it_expect(in_array('readme.html', $names, true), 'Le fichier WordPress modifié devrait être gardé.');
		rempart_it_expect(in_array('wp-config.php', $names, true), 'wp-config.php devrait être gardé.');
		foreach (array('index.php', 'wp-cron.php', 'wp-load.php') as $core) {
			rempart_it_expect(!in_array($core, $names, true), $core . ' est inchangé, il ne devrait pas être gardé.');
		}
	},
	'arrêt demandé par la personne' => function () use (&$created_backups) {
		$job = Rempart_Job::start_backup(array('files' => true, 'db' => true, 'location' => ''));
		$created_backups[] = $job['name'];
		Rempart_Job::request_stop();
		$state = rempart_it_finish();
		rempart_it_expect($state['status'] === 'stopped', 'État attendu : stopped, obtenu : ' . $state['status']);
		rempart_it_expect(!Rempart_Job::is_active(), 'La tâche est restée active.');
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
foreach ($created_locations as $id) {
	Rempart_Locations::delete($id);
}
Rempart_Notifications::delete($channel['id']);
if ($previous_excludes === null) {
	delete_option('rempart_excludes');
} else {
	update_option('rempart_excludes', $previous_excludes, false);
}

if ($failed > 0) {
	WP_CLI::error($failed . ' scénario(s) en échec.');
}

<?php
/**
 * Round trip on every destination driver, against real services.
 *
 * Runs inside WordPress, because the WebDAV and S3 drivers use WordPress's HTTP functions:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/backup-guard/tests/integration/destinations.php
 *
 * The services are the ones of tests/docker-compose.yml. An unreachable service is a failure, unless
 * IT_SKIP_UNREACHABLE=1 is set (to test a single destination locally). Any failure exits with status 1.
 */
if (!defined('ABSPATH') || !class_exists('Backup_Guard_Storage')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

require_once BACKUP_GUARD_DIR . '/includes/storage/class-storage.php';
foreach (array('ftp', 'sftp', 'webdav', 's3') as $driver) {
	require_once BACKUP_GUARD_DIR . '/includes/storage/class-' . $driver . '.php';
}

/** Connection settings. The environment can override each host, for CI or other setups. */
$env = function ($name, $default) {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
};

$destinations = array(
	'sftp' => array(
		'class'  => 'Backup_Guard_Storage_Sftp',
		'config' => array('host' => $env('IT_SFTP_HOST', 'backup-guard-sftp'), 'port' => 22, 'user' => 'bob', 'pass' => 'pass123', 'folder' => 'upload'),
	),
	'ftp' => array(
		'class'  => 'Backup_Guard_Storage_Ftp',
		'config' => array('host' => $env('IT_FTP_HOST', 'backup-guard-ftp'), 'port' => 21, 'protocol' => 'ftp', 'user' => 'bob', 'pass' => 'pass123', 'folder' => ''),
	),
	'ftps' => array(
		'class'  => 'Backup_Guard_Storage_Ftp',
		'config' => array('host' => $env('IT_FTPS_HOST', 'backup-guard-ftps'), 'port' => 21, 'protocol' => 'ftps', 'insecure_tls' => 1, 'user' => 'bob', 'pass' => 'pass123', 'folder' => ''),
	),
	'webdav' => array(
		'class'  => 'Backup_Guard_Storage_Webdav',
		'config' => array('url' => $env('IT_WEBDAV_URL', 'http://backup-guard-dav/'), 'user' => 'bob', 'pass' => 'pass123', 'folder' => ''),
	),
	's3' => array(
		'class'  => 'Backup_Guard_Storage_S3',
		'config' => array('endpoint' => $env('IT_S3_ENDPOINT', 'http://backup-guard-s3:3900'), 'region' => 'us-east-1', 'service' => 'custom', 'bucket' => 'backup-guard-it', 'access_key' => 'GK00000000000000000000000a', 'secret_key' => '0000000000000000000000000000000000000000000000000000000000000001', 'folder' => ''),
	),
);

/** Run one destination: connect, write, read back, list, delete, then a 5 MB file in steps. */
function backup_guard_it_roundtrip($class, array $config) {
	$storage = new $class($config);
	$storage->test();

	$small = 'backup-guard-it-small.txt';
	$storage->put_string($small, 'bonjour backup-guard');
	if ($storage->get_string($small) !== 'bonjour backup-guard') {
		throw new RuntimeException('Le contenu relu ne correspond pas.');
	}
	$names = array_column($storage->list_files(), 'name');
	if (!in_array($small, $names, true)) {
		throw new RuntimeException('Le fichier n\'apparaît pas dans la liste.');
	}
	$storage->delete($small);

	$data = random_bytes(5 * 1024 * 1024 + 123);
	$source = tempnam(sys_get_temp_dir(), 'backup-guard-it-');
	$copy = tempnam(sys_get_temp_dir(), 'backup-guard-it-');
	file_put_contents($source, $data);
	$state = array();
	$steps = 0;
	while (!$storage->upload_step($source, 'backup-guard-it-big.bin', $state, time() + 60, function () {})) {
		if (++$steps > 500) {
			throw new RuntimeException('L\'envoi ne se termine pas.');
		}
	}
	@unlink($copy);
	$state = array();
	$steps = 0;
	while (!$storage->download_step('backup-guard-it-big.bin', $copy, $state, time() + 60, function () {})) {
		if (++$steps > 500) {
			throw new RuntimeException('Le téléchargement ne se termine pas.');
		}
	}
	$same = hash_equals(hash('sha256', $data), hash_file('sha256', $copy));
	$storage->delete('backup-guard-it-big.bin');
	unlink($source);
	unlink($copy);
	if (!$same) {
		throw new RuntimeException('Le fichier de 5 Mo n\'est pas identique à l\'arrivée.');
	}
}

$failed = 0;
foreach ($destinations as $name => $destination) {
	try {
		// An FTP server that is restarting (its image sometimes dies on start) gets a few more tries.
		for ($attempt = 1; ; $attempt++) {
			try {
				backup_guard_it_roundtrip($destination['class'], $destination['config']);
				break;
			} catch (Throwable $e) {
				if ($attempt >= 4 || !preg_match('/getaddrinfo|Could not resolve host|Connection refused|Connexion (SFTP )?à \S+ impossible/i', $e->getMessage())) {
					throw $e;
				}
				sleep(5);
			}
		}
		WP_CLI::success($name . ' : aller-retour OK');
	} catch (Throwable $e) {
				$text = $e->getMessage();
		// Opt-in only, and only for connection-level errors: a refused login is always a failure.
		if (getenv('IT_SKIP_UNREACHABLE') && preg_match('/Connexion (SFTP )?à \S+ impossible|Could not connect|cURL error [67]|Connection refused|Could not resolve host|getaddrinfo|Cannot connect to/i', $text)) {
			WP_CLI::warning($name . ' : service injoignable, ignoré (' . substr($text, 0, 120) . ')');
			continue;
		}
		$failed++;
		WP_CLI::warning($name . ' : ÉCHEC : ' . $text);
	}
}

if ($failed > 0) {
	WP_CLI::error($failed . ' destination(s) en échec.');
}

<?php
/**
 * Sends a real notification through every channel type and checks that it arrived.
 *
 * Runs inside WordPress:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/backup-guard/tests/integration/notifications.php
 *
 * - Self-hosted services run for real: Mailpit (e-mail), Gotify, ntfy.
 * - Pushover, Telegram, Discord and Slack have no self-hosted version, and the webhook is a plain POST:
 *   they talk to a mock (tests/mock/server.js) that keeps what it receives. Nothing reaches the real services.
 */
if (!defined('ABSPATH') || !class_exists('Backup_Guard_Notifications')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

$env = function ($name, $default) {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
};
$mail   = $env('IT_MAIL_URL', 'http://backup-guard-mail:8025');
$mock   = $env('IT_MOCK_URL', 'http://backup-guard-mock');
$gotify = $env('IT_GOTIFY_URL', 'http://backup-guard-gotify');
$ntfy   = $env('IT_NTFY_URL', 'http://backup-guard-ntfy');

// Pushover and Telegram have fixed addresses; the plugin lets tests point them elsewhere.
add_filter('backup_guard_notification_url', function ($url, $type) use ($mock) {
	return $mock . '/' . $type;
}, 10, 2);
// E-mail goes to Mailpit, from a valid sender address (WordPress has none on localhost).
add_filter('wp_mail_from', function () {
	return 'qa@backup-guard.test';
});
add_action('phpmailer_init', function ($phpmailer) use ($env) {
	$phpmailer->isSMTP();
	$phpmailer->Host = $env('IT_MAIL_HOST', 'backup-guard-mail');
	$phpmailer->Port = 1025;
	$phpmailer->SMTPAuth = false;
	$phpmailer->SMTPAutoTLS = false;
});

function backup_guard_it_get($url, array $args = array()) {
	$response = wp_remote_get($url, $args + array('timeout' => 10));
	if (is_wp_error($response)) {
		throw new RuntimeException('GET ' . $url . ' : ' . $response->get_error_message());
	}
	return wp_remote_retrieve_body($response);
}

/** What the mock received on a path (and its sub-paths), oldest first. */
function backup_guard_it_mock_requests($mock, $path) {
	$found = array();
	foreach ((array) json_decode(backup_guard_it_get($mock . '/_requests'), true) as $request) {
		if (strpos($request['path'], $path) === 0) {
			$found[] = $request;
		}
	}
	return $found;
}

function backup_guard_it_expect($condition, $text) {
	if (!$condition) {
		throw new RuntimeException($text);
	}
}

/** Save a channel, send one message through it, check it, and always remove the channel. */
function backup_guard_it_channel(array $values, $title, callable $check) {
	$channel = Backup_Guard_Notifications::save($values + array('enabled' => 1, 'on_success' => 1, 'on_failure' => 1));
	try {
		Backup_Guard_Notifications::send($channel, 'success', $title, "Ligne un\nLigne deux", array('Site' => 'test'));
		$check();
	} finally {
		Backup_Guard_Notifications::delete($channel['id']);
	}
}

$cases = array(
	'email' => function ($title) use ($mail) {
		backup_guard_it_channel(array('type' => 'email', 'name' => 'IT', 'address' => 'qa@example.test'), $title, function () use ($mail, $title) {
			$list = json_decode(backup_guard_it_get($mail . '/api/v1/search?query=' . rawurlencode('subject:"' . $title . '"')), true);
			backup_guard_it_expect(!empty($list['messages']), 'Mailpit n\'a pas reçu l\'e-mail.');
		});
	},
	'gotify' => function ($title) use ($gotify) {
		$auth = array('headers' => array('Authorization' => 'Basic ' . base64_encode('admin:admin'), 'Content-Type' => 'application/json'));
		$app = wp_remote_post($gotify . '/application', $auth + array('body' => wp_json_encode(array('name' => 'it-' . wp_generate_password(6, false)))));
		$decoded = json_decode(wp_remote_retrieve_body($app), true);
		$token = is_array($decoded) ? ($decoded['token'] ?? '') : '';
		backup_guard_it_expect($token !== '', 'Gotify : application non créée.');
		// Sending takes an application token, reading takes a client token.
		$client = wp_remote_post($gotify . '/client', $auth + array('body' => wp_json_encode(array('name' => 'it-reader-' . wp_generate_password(6, false)))));
		$reader = (string) (json_decode(wp_remote_retrieve_body($client), true)['token'] ?? '');
		backup_guard_it_expect($reader !== '', 'Gotify : client non créé.');
		backup_guard_it_channel(array('type' => 'gotify', 'name' => 'IT', 'url' => $gotify, 'token' => $token), $title, function () use ($gotify, $reader, $title) {
			$messages = json_decode(backup_guard_it_get($gotify . '/message', array('headers' => array('X-Gotify-Key' => $reader))), true);
			backup_guard_it_expect(($messages['messages'][0]['title'] ?? '') === $title, 'Gotify n\'a pas reçu le message.');
		});
	},
	'ntfy' => function ($title) use ($ntfy) {
		$topic = 'backup-guard-it-' . wp_generate_password(10, false);
		backup_guard_it_channel(array('type' => 'ntfy', 'name' => 'IT', 'server' => $ntfy, 'topic' => $topic), $title, function () use ($ntfy, $topic, $title) {
			$lines = backup_guard_it_get($ntfy . '/' . $topic . '/json?poll=1');
			backup_guard_it_expect(strpos($lines, $title) !== false, 'ntfy n\'a pas reçu le message.');
		});
	},
	'pushover' => function ($title) use ($mock) {
		backup_guard_it_channel(array('type' => 'pushover', 'name' => 'IT', 'token' => 'apptoken123456', 'user' => 'userkey1234567'), $title, function () use ($mock, $title) {
			$requests = backup_guard_it_mock_requests($mock, '/pushover');
			backup_guard_it_expect($requests && strpos($requests[0]['body'], 'apptoken123456') !== false && strpos(urldecode($requests[0]['body']), $title) !== false, 'Requête Pushover absente ou incomplète.');
		});
	},
	'telegram' => function ($title) use ($mock) {
		backup_guard_it_channel(array('type' => 'telegram', 'name' => 'IT', 'bot_token' => '123456:abcdefghij', 'chat_id' => '42'), $title, function () use ($mock, $title) {
			$requests = backup_guard_it_mock_requests($mock, '/telegram');
			$body = $requests ? json_decode($requests[0]['body'], true) : array();
			backup_guard_it_expect(($body['chat_id'] ?? '') === '42' && strpos($body['text'] ?? '', $title) !== false, 'Requête Telegram absente ou incomplète.');
		});
	},
	'discord' => function ($title) use ($mock) {
		backup_guard_it_channel(array('type' => 'discord', 'name' => 'IT', 'webhook_url' => $mock . '/discord'), $title, function () use ($mock, $title) {
			$requests = backup_guard_it_mock_requests($mock, '/discord');
			$body = $requests ? json_decode($requests[0]['body'], true) : array();
			backup_guard_it_expect(($body['embeds'][0]['title'] ?? '') === $title, 'Requête Discord absente ou incomplète.');
		});
	},
	'slack' => function ($title) use ($mock) {
		backup_guard_it_channel(array('type' => 'slack', 'name' => 'IT', 'webhook_url' => $mock . '/slack'), $title, function () use ($mock, $title) {
			$requests = backup_guard_it_mock_requests($mock, '/slack');
			$body = $requests ? json_decode($requests[0]['body'], true) : array();
			backup_guard_it_expect(strpos($body['text'] ?? '', $title) !== false, 'Requête Slack absente ou incomplète.');
		});
	},
	'webhook' => function ($title) use ($mock) {
		backup_guard_it_channel(array('type' => 'webhook', 'name' => 'IT', 'url' => $mock . '/webhook', 'auth' => 'Bearer secret-token-1'), $title, function () use ($mock, $title) {
			$requests = backup_guard_it_mock_requests($mock, '/webhook');
			$body = $requests ? json_decode($requests[0]['body'], true) : array();
			backup_guard_it_expect(($body['title'] ?? '') === $title && ($body['event'] ?? '') === 'success', 'Webhook : contenu inattendu.');
			backup_guard_it_expect(($requests[0]['headers']['authorization'] ?? '') === 'Bearer secret-token-1', 'Webhook : en-tête Authorization absent.');
		});
	},
);

wp_remote_request($mock . '/_requests', array('method' => 'DELETE'));
$failed = 0;
foreach ($cases as $name => $case) {
	$title = 'Test ' . $name . ' ' . wp_generate_password(8, false);
	try {
		$case($title);
		WP_CLI::success($name . ' : message reçu');
	} catch (Throwable $e) {
		$failed++;
		WP_CLI::warning($name . ' : ÉCHEC : ' . $e->getMessage());
	}
	wp_remote_request($mock . '/_requests', array('method' => 'DELETE'));
}

// A refusal from the service must reach the person, with the secret blanked out of the text.
$channel = Backup_Guard_Notifications::save(array('type' => 'webhook', 'name' => 'IT', 'url' => $mock . '/webhook', 'auth' => 'secret-token-1', 'enabled' => 1));
$refuse = function () {
	return array('response' => array('code' => 500, 'message' => 'Error'), 'body' => 'boom secret-token-1', 'headers' => array(), 'cookies' => array(), 'filename' => null);
};
add_filter('pre_http_request', $refuse);
try {
	try {
		Backup_Guard_Notifications::send($channel, 'failure', 'x', 'y');
		throw new LogicException('Une réponse HTTP 500 aurait dû être une erreur.');
	} catch (RuntimeException $e) {
		backup_guard_it_expect(strpos($e->getMessage(), 'HTTP 500') !== false, 'Message d\'erreur inattendu : ' . $e->getMessage());
		backup_guard_it_expect(strpos($e->getMessage(), 'secret-token-1') === false, 'Le secret apparaît dans le message d\'erreur.');
	}
	WP_CLI::success('erreur HTTP : signalée, secret masqué');
} catch (Throwable $e) {
	$failed++;
	WP_CLI::warning('erreur HTTP : ÉCHEC : ' . $e->getMessage());
} finally {
	remove_filter('pre_http_request', $refuse);
	Backup_Guard_Notifications::delete($channel['id']);
}

if ($failed > 0) {
	WP_CLI::error($failed . ' vérification(s) en échec.');
}

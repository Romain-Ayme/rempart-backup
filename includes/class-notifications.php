<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Tells the person how a backup went: by e-mail (the address in the settings) and through any
 * number of chat and push services. Each channel picks which outcomes it wants to hear about.
 */
class Rempart_Notifications {
	const OPTION = 'rempart_channels';
	const TIMEOUT = 8;

	/** Every kind of channel with the fields of its form. */
	public static function types() {
		$username = array('label' => 'Nom affiché (facultatif)', 'default' => 'Rempart Backup');
		return array(
			'email' => array('label' => 'E-mail', 'fields' => array(
				'address' => array('label' => 'Adresse e-mail', 'required' => true, 'help' => 'Utilise la messagerie de WordPress. Pour plusieurs adresses, séparez-les par une virgule.'),
			)),
			'pushover' => array('label' => 'Pushover', 'fields' => array(
				'token'  => array('label' => 'Jeton de l\'application (API token)', 'secret' => true, 'required' => true),
				'user'   => array('label' => 'Clé utilisateur ou groupe', 'secret' => true, 'required' => true),
				'device' => array('label' => 'Appareil (facultatif)', 'help' => 'Laissez vide pour tous vos appareils.'),
			)),
			'gotify' => array('label' => 'Gotify', 'fields' => array(
				'url'   => array('label' => 'Adresse du serveur', 'help' => 'Par exemple https://gotify.mondomaine.fr', 'required' => true),
				'token' => array('label' => 'Jeton de l\'application', 'secret' => true, 'required' => true),
			)),
			'ntfy' => array('label' => 'ntfy', 'fields' => array(
				'server' => array('label' => 'Serveur', 'default' => 'https://ntfy.sh', 'required' => true),
				'topic'  => array('label' => 'Sujet (topic)', 'secret' => true, 'required' => true, 'help' => 'Sur ntfy.sh, le nom du sujet sert de mot de passe : choisissez-en un long.'),
				'token'  => array('label' => 'Jeton d\'accès (facultatif)', 'secret' => true),
			)),
			'telegram' => array('label' => 'Telegram', 'fields' => array(
				'bot_token' => array('label' => 'Jeton du bot', 'secret' => true, 'required' => true, 'help' => 'Obtenu auprès de @BotFather.'),
				'chat_id'   => array('label' => 'Identifiant de la discussion', 'required' => true, 'help' => 'Écrivez d\'abord au bot, puis relevez le « chat id ».'),
			)),
			'discord' => array('label' => 'Discord', 'fields' => array(
				'webhook_url' => array('label' => 'Adresse du webhook', 'secret' => true, 'required' => true, 'help' => 'Réglages du salon, Intégrations, Webhooks.'),
				'username'    => $username,
			)),
			'slack' => array('label' => 'Slack', 'fields' => array(
				'webhook_url' => array('label' => 'Adresse du webhook entrant', 'secret' => true, 'required' => true, 'help' => 'Elle commence par https://hooks.slack.com/'),
			)),
			'webhook' => array('label' => 'Webhook (JSON)', 'fields' => array(
				'url'  => array('label' => 'Adresse à appeler', 'secret' => true, 'required' => true, 'help' => 'Reçoit un POST en JSON : événement, titre, message et détails.'),
				'auth' => array('label' => 'En-tête Authorization (facultatif)', 'secret' => true, 'help' => 'Par exemple Bearer abc123. Envoyé tel quel.'),
			)),
		);
	}

	/**
	 * Before, the e-mail address lived in the general settings. It becomes a channel like the
	 * others, once, with the same address and with both outcomes switched on as they always were.
	 */
	public static function migrate_email() {
		if (get_option('rempart_email_migrated')) {
			return;
		}
		update_option('rempart_email_migrated', 1, false);
		$settings = rempart_settings();
		if (!empty($settings['email']) && is_email($settings['email'])) {
			self::save(array('type' => 'email', 'name' => 'E-mail', 'address' => $settings['email'], 'enabled' => 1, 'on_success' => 1, 'on_failure' => 1));
			$settings['email'] = '';
			update_option('rempart_settings', $settings, false);
		}
	}

	public static function all() {
		return (array) get_option(self::OPTION, array());
	}

	public static function get($id) {
		$all = self::all();
		return isset($all[$id]) ? $all[$id] : null;
	}

	private static function secrets_of(array $channel) {
		if (empty($channel['secrets'])) {
			return array();
		}
		$json = rempart_decrypt($channel['secrets']);
		if ($json === false) {
			throw new RuntimeException('Les identifiants de « ' . $channel['name'] . ' » ne sont plus lisibles (site déplacé ?). Modifiez ce canal et saisissez-les de nouveau.');
		}
		return (array) json_decode($json, true);
	}

	/** The channel's settings with its secrets decrypted, as a sender wants them. */
	public static function config_of(array $channel) {
		return (array) $channel['config'] + self::secrets_of($channel);
	}

	/** Save a channel from submitted values. A secret left empty keeps its old value. */
	public static function save(array $values, $id = null) {
		$types = self::types();
		$type = $values['type'] ?? '';
		if (empty($types[$type])) {
			throw new RuntimeException('Type de notification inconnu.');
		}
		$all = self::all();
		$existing = ($id !== null && isset($all[$id])) ? $all[$id] : null;
		$config = array();
		$secrets = array();
		if ($existing && $existing['type'] === $type) {
			try {
				$secrets = self::secrets_of($existing);
			} catch (RuntimeException $e) {
				$secrets = array();
			}
		}

		foreach ($types[$type]['fields'] as $key => $field) {
			$value = isset($values[$key]) ? trim((string) $values[$key]) : '';
			if (!empty($field['secret'])) {
				if ($value !== '') {
					$secrets[$key] = $value;
				}
				if (!empty($field['required']) && empty($secrets[$key])) {
					throw new RuntimeException('Champ obligatoire manquant : ' . $field['label']);
				}
				continue;
			}
			if (!empty($field['required']) && $value === '') {
				throw new RuntimeException('Champ obligatoire manquant : ' . $field['label']);
			}
			$config[$key] = sanitize_text_field($value);
		}
		foreach (array('url', 'server') as $key) {
			if (!empty($config[$key]) && !preg_match('#^https?://[^\s]+$#i', $config[$key])) {
				throw new RuntimeException('L\'adresse doit commencer par http:// ou https://');
			}
		}
		foreach (array('webhook_url', 'url') as $key) {
			if (!empty($secrets[$key]) && !preg_match('#^https?://[^\s]+$#i', $secrets[$key])) {
				throw new RuntimeException('L\'adresse doit commencer par http:// ou https://');
			}
		}
		if ($type === 'email') {
			$addresses = array_filter(array_map('trim', explode(',', $config['address'])));
			foreach ($addresses as $address) {
				if (!is_email($address)) {
					throw new RuntimeException('Cette adresse e-mail n\'est pas valide : ' . $address);
				}
			}
			$config['address'] = implode(', ', $addresses);
		}
		$name = sanitize_text_field((string) ($values['name'] ?? ''));
		if ($name === '') {
			$name = $types[$type]['label'];
		}
		if ($id === null) {
			$id = $all ? max(array_map('intval', array_keys($all))) + 1 : 1;
		}
		$all[$id] = array(
			'id'         => (int) $id,
			'name'       => $name,
			'type'       => $type,
			'enabled'    => !empty($values['enabled']) ? 1 : 0,
			'on_success' => !empty($values['on_success']) ? 1 : 0,
			'on_failure' => !empty($values['on_failure']) ? 1 : 0,
			'config'     => $config,
			'secrets'    => $secrets ? rempart_encrypt(wp_json_encode($secrets)) : '',
			'last'       => $existing['last'] ?? null,
		);
		update_option(self::OPTION, $all, false);
		return $all[$id];
	}

	public static function delete($id) {
		$all = self::all();
		unset($all[$id]);
		update_option(self::OPTION, $all, false);
	}

	private static function remember_result($id, $ok, $text) {
		$all = self::all();
		if (isset($all[$id])) {
			$all[$id]['last'] = array('t' => time(), 'ok' => (bool) $ok, 'text' => mb_substr((string) $text, 0, 200));
			update_option(self::OPTION, $all, false);
		}
	}

	/**
	 * Announce an outcome everywhere it was asked for.
	 *
	 * @param string $event   success or failure.
	 * @param string $title   Short headline.
	 * @param string $message Details, plain text with line breaks.
	 * @param array  $details Label => value pairs, for services that can lay them out.
	 */
	public static function event($event, $title, $message, array $details = array()) {
		foreach (self::all() as $id => $channel) {
			if (empty($channel['enabled']) || empty($channel['on_' . $event])) {
				continue;
			}
			try {
				self::send($channel, $event, $title, $message, $details);
				self::remember_result($id, true, 'Envoyé');
			} catch (Exception $e) {
				self::remember_result($id, false, $e->getMessage());
				rempart_log('Notification « ' . $channel['name'] . ' » non envoyée : ' . $e->getMessage(), 'warning');
			}
		}
	}

	/** Send a sample message through one channel, whatever its event choices. */
	public static function test($id) {
		$channel = self::get($id);
		if (!$channel) {
			throw new RuntimeException('Ce canal n\'existe plus.');
		}
		$details = array('Site' => home_url(), 'Exemple' => 'Message de test');
		try {
			self::send($channel, 'test', 'Test de notification - ' . self::site_name(), "Si vous lisez ce message, ce canal fonctionne.\n\nSite : " . home_url(), $details);
		} catch (Exception $e) {
			self::remember_result($id, false, $e->getMessage());
			throw $e;
		}
		self::remember_result($id, true, 'Test envoyé');
	}

	public static function site_name() {
		$name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		return $name !== '' ? $name : (string) wp_parse_url(home_url(), PHP_URL_HOST);
	}

	/** Services whose address is fixed can be pointed elsewhere, for tests behind a firewall. */
	private static function endpoint($url, $type) {
		return apply_filters('rempart_notification_url', $url, $type);
	}

	/** Send one message through one channel. Throws a readable error when it fails. */
	public static function send(array $channel, $event, $title, $message, array $details = array()) {
		$c = self::config_of($channel);
		$send = array(__CLASS__, 'send_' . $channel['type']);
		if (!is_callable($send)) {
			throw new RuntimeException('Type de notification inconnu.');
		}
		$context = array('event' => $event, 'title' => $title, 'message' => $message, 'details' => $details);
		try {
			call_user_func($send, $c, $context);
		} catch (RuntimeException $e) {
			// A service's own error text must never carry a key back into the log.
			$text = $e->getMessage();
			foreach (self::secrets_of($channel) as $secret) {
				if (is_string($secret) && strlen($secret) > 5) {
					$text = str_replace($secret, '***', $text);
				}
			}
			throw new RuntimeException($text);
		}
	}

	/** One HTTP call; anything but a 2xx answer is an error carrying what the service said. */
	private static function post($url, $body, array $headers = array()) {
		$response = wp_remote_post($url, array('timeout' => self::TIMEOUT, 'headers' => $headers, 'body' => $body, 'redirection' => 2));
		if (is_wp_error($response)) {
			throw new RuntimeException('Connexion impossible : ' . $response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$said = trim(wp_strip_all_tags(mb_substr((string) wp_remote_retrieve_body($response), 0, 160)));
			throw new RuntimeException('Le service a répondu HTTP ' . $code . ($said !== '' ? ' : ' . $said : '') . '.');
		}
		return wp_remote_retrieve_body($response);
	}

	private static function json($url, array $payload, array $headers = array()) {
		return self::post($url, wp_json_encode($payload), $headers + array('Content-Type' => 'application/json; charset=utf-8'));
	}

	private static function plain($ctx) {
		return $ctx['title'] . "\n\n" . $ctx['message'];
	}

	private static function colour($event) {
		return $event === 'failure' ? 0xb32d2e : ($event === 'success' ? 0x2e9e4f : 0x1f6f8b);
	}

	private static function send_email($c, $ctx) {
		$to = array_filter(array_map('trim', explode(',', (string) $c['address'])));
		if (!$to) {
			throw new RuntimeException('Aucune adresse e-mail renseignée.');
		}
		if (!wp_mail($to, $ctx['title'], $ctx['message'])) {
			throw new RuntimeException('WordPress n\'a pas pu envoyer l\'e-mail : vérifiez la messagerie de ce site.');
		}
	}

	private static function send_pushover($c, $ctx) {
		$body = array(
			'token'   => $c['token'],
			'user'    => $c['user'],
			'title'   => mb_substr($ctx['title'], 0, 250),
			'message' => mb_substr($ctx['message'], 0, 1024),
		);
		if (!empty($c['device'])) {
			$body['device'] = $c['device'];
		}
		if ($ctx['event'] === 'failure') {
			$body['priority'] = 1;
		}
		self::post(self::endpoint('https://api.pushover.net/1/messages.json', 'pushover'), $body);
	}

	private static function send_gotify($c, $ctx) {
		$url = rtrim($c['url'], '/') . '/message';
		self::json($url, array(
			'title'    => $ctx['title'],
			'message'  => $ctx['message'],
			'priority' => $ctx['event'] === 'failure' ? 8 : 4,
		), array('X-Gotify-Key' => $c['token']));
	}

	private static function send_ntfy($c, $ctx) {
		$headers = array();
		if (!empty($c['token'])) {
			$headers['Authorization'] = 'Bearer ' . $c['token'];
		}
		self::json(rtrim($c['server'], '/') . '/', array(
			'topic'    => $c['topic'],
			'title'    => $ctx['title'],
			'message'  => $ctx['message'],
			'priority' => $ctx['event'] === 'failure' ? 4 : 3,
			'tags'     => array($ctx['event'] === 'failure' ? 'rotating_light' : 'white_check_mark'),
		), $headers);
	}

	private static function send_telegram($c, $ctx) {
		$url = self::endpoint('https://api.telegram.org/bot' . rawurlencode($c['bot_token']) . '/sendMessage', 'telegram');
		self::json($url, array('chat_id' => $c['chat_id'], 'text' => mb_substr(self::plain($ctx), 0, 4000), 'disable_web_page_preview' => true));
	}

	private static function send_discord($c, $ctx) {
		$fields = array();
		foreach ($ctx['details'] as $label => $value) {
			$fields[] = array('name' => mb_substr((string) $label, 0, 256), 'value' => mb_substr((string) $value, 0, 1024) ?: '-', 'inline' => true);
		}
		$payload = array(
			'username' => !empty($c['username']) ? $c['username'] : 'Rempart Backup',
			'embeds'   => array(array(
				'title'       => mb_substr($ctx['title'], 0, 256),
				'description' => mb_substr($ctx['message'], 0, 4000),
				'color'       => self::colour($ctx['event']),
				'fields'      => array_slice($fields, 0, 25),
				'timestamp'   => gmdate('c'),
			)),
		);
		self::json($c['webhook_url'], $payload);
	}

	private static function send_slack($c, $ctx) {
		$icon = $ctx['event'] === 'failure' ? ':rotating_light:' : ($ctx['event'] === 'success' ? ':white_check_mark:' : ':information_source:');
		$text = $icon . ' *' . str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), $ctx['title']) . "*\n"
			. str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), $ctx['message']);
		self::json($c['webhook_url'], array('text' => mb_substr($text, 0, 3500)));
	}

	private static function send_webhook($c, $ctx) {
		$headers = array();
		if (!empty($c['auth'])) {
			$headers['Authorization'] = $c['auth'];
		}
		self::json($c['url'], array(
			'event'     => $ctx['event'],
			'title'     => $ctx['title'],
			'message'   => $ctx['message'],
			'details'   => (object) $ctx['details'],
			'site_name' => self::site_name(),
			'site_url'  => home_url(),
			'plugin'    => 'rempart-backup',
			'timestamp' => gmdate('c'),
		), $headers);
	}
}

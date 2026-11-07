<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Some LiteSpeed servers silently kill a long PHP job when the browser connection ends, so a
 * backup stops at a random point with no error. The cure is the `noabort` environment variable,
 * set from the site's .htaccess. Two forms exist because the first has no effect on some setups.
 */
class Backup_Guard_Litespeed {
	const BEGIN = '# BEGIN BackupGuard LiteSpeed';
	const END = '# END BackupGuard LiteSpeed';

	public static function detected() {
		if (function_exists('php_sapi_name') && stripos(php_sapi_name(), 'litespeed') !== false) {
			return true;
		}
		return !empty($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false;
	}

	public static function file() {
		return backup_guard_clean_path(ABSPATH) . '/.htaccess';
	}

	/** The block to add, in the form 1 (SetEnv) or 2 (RewriteRule). */
	public static function block($variant) {
		$rules = (int) $variant === 2
			? "<IfModule Litespeed>\nRewriteEngine On\nRewriteRule .* - [E=noabort:1]\n</IfModule>"
			: "<IfModule Litespeed>\nSetEnv noabort 1\n</IfModule>";
		return self::BEGIN . "\n" . $rules . "\n" . self::END . "\n";
	}

	private static function own_block_pattern() {
		return '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "\n*/s";
	}

	/** @return array [level (ok|warning|info), text, rule present (bool)] */
	public static function status() {
		$file = self::file();
		$content = is_readable($file) ? (string) file_get_contents($file) : null;
		$present = $content !== null && stripos($content, 'noabort') !== false;

		if ($present) {
			$own = $content !== null && strpos($content, self::BEGIN) !== false;
			return array('ok', $own ? __('La règle LiteSpeed est en place (ajoutée par ce plugin).', 'backup-guard') : __('Une règle « noabort » est déjà dans votre .htaccess.', 'backup-guard'), true);
		}
		if (!self::detected()) {
			return array('info', __('LiteSpeed n\'est pas détecté sur ce serveur : cette règle n\'est probablement pas nécessaire. Vous pouvez quand même l\'ajouter si une sauvegarde se bloque sans erreur.', 'backup-guard'), false);
		}
		if ($content === null) {
			return array('warning', __('Ce serveur utilise LiteSpeed, mais le fichier .htaccess du site est introuvable ou illisible.', 'backup-guard'), false);
		}
		return array('warning', __('Ce serveur utilise LiteSpeed, qui interrompt parfois les tâches longues sans message d\'erreur. Ajoutez la règle ci-dessous si une sauvegarde se bloque.', 'backup-guard'), false);
	}

	/** Without the rule on LiteSpeed, a job that went quiet has a likely cause worth naming. */
	public static function stall_hint() {
		$status = self::status();
		if (self::detected() && !$status[2]) {
			return __(' Ce serveur utilise LiteSpeed, qui interrompt parfois les tâches longues : ouvrez l\'onglet Aide, puis ajoutez la règle LiteSpeed.', 'backup-guard');
		}
		return '';
	}

	private static function site_code() {
		$response = wp_remote_get(home_url('/'), array('timeout' => 15, 'sslverify' => apply_filters('https_local_ssl_verify', false), 'redirection' => 0));
		return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
	}

	/**
	 * Add the rule at the start of .htaccess, as the vendor's guide asks.
	 * A copy of the file is kept, and the change is undone if the site starts failing because of it.
	 *
	 * @return array [level, text]
	 */
	public static function apply($variant = 1) {
		$file = self::file();
		if (!file_exists($file)) {
			return array('error', __('Le fichier .htaccess n\'existe pas à la racine du site. Créez-le, puis réessayez.', 'backup-guard'));
		}
		if (!is_writable($file)) {
			return array('error', __('Le fichier .htaccess n\'est pas modifiable : vérifiez ses droits, puis réessayez.', 'backup-guard'));
		}
		$original = (string) file_get_contents($file);
		$without = preg_replace(self::own_block_pattern(), '', $original);
		if (stripos($without, 'noabort') !== false) {
			return array('ok', __('Une règle « noabort » est déjà dans votre .htaccess : rien n\'a été changé.', 'backup-guard'));
		}

		$backup = BACKUP_GUARD_ROOT . 'tmp/htaccess-avant-litespeed.bak';
		@file_put_contents($backup, $original);

		$before = self::site_code();
		if (file_put_contents($file, self::block($variant) . "\n" . $without, LOCK_EX) === false) {
			return array('error', __('Impossible d\'écrire dans le fichier .htaccess : vérifiez ses droits.', 'backup-guard'));
		}
		$after = self::site_code();
		if ($after >= 500 && $before < 500) {
			file_put_contents($file, $original, LOCK_EX);
			return array('error', sprintf(__('Le site a répondu par une erreur (code %s) avec cette règle : elle a été retirée et votre .htaccess est revenu comme avant.', 'backup-guard'), $after) . ((int) $variant === 1 ? ' ' . __("Essayez l'autre forme de la règle.", 'backup-guard') : ''));
		}
		return array('ok', sprintf(__('Règle ajoutée au début du fichier .htaccess (forme %d). Relancez la sauvegarde qui se bloquait.', 'backup-guard'), (int) $variant) . ((int) $variant === 1 ? ' ' . __("Si elle se bloque encore, essayez l'autre forme.", 'backup-guard') : ''));
	}

	public static function remove() {
		$file = self::file();
		if (!is_readable($file) || !is_writable($file)) {
			return array('error', __('Le fichier .htaccess n\'est pas modifiable.', 'backup-guard'));
		}
		$content = (string) file_get_contents($file);
		if (strpos($content, self::BEGIN) === false) {
			return array('info', __('Ce plugin n\'a pas ajouté de règle : rien à retirer.', 'backup-guard'));
		}
		file_put_contents($file, preg_replace(self::own_block_pattern(), '', $content), LOCK_EX);
		return array('ok', __('La règle a été retirée du fichier .htaccess.', 'backup-guard'));
	}
}

<?php
if (!defined('ABSPATH')) {
	exit;
}

/** The plugin's screens in the WordPress admin. */
class Rempart_Admin {
	private static $notice = null;

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
		add_action('admin_init', array(__CLASS__, 'handle_forms'));
		add_action('admin_notices', array(__CLASS__, 'nag'));
	}

	public static function menu() {
		add_menu_page('Rempart Backup', 'Rempart Backup', 'manage_options', 'rempart-backup', array(__CLASS__, 'page'), 'dashicons-shield', 76);
	}

	public static function assets($hook) {
		if ($hook !== 'toplevel_page_rempart-backup') {
			return;
		}
		wp_enqueue_style('rempart-admin', REMPART_URL . '/assets/admin.css', array(), REMPART_VERSION);
		wp_enqueue_script('rempart-admin', REMPART_URL . '/assets/admin.js', array(), REMPART_VERSION, true);
		wp_localize_script('rempart-admin', 'RempartData', array(
			'ajax'      => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('rempart'),
			'login'     => wp_login_url(admin_url('admin.php?page=rempart-backup')),
			'chunk'     => REMPART_UPLOAD_CHUNK,
			'active'    => Rempart_Job::is_active(),
			'restore'   => self::restore_info(),
			'i18n'      => self::js_strings(),
		));
	}

	/** Texts the admin script shows, in this site's language (the script falls back to the French text). */
	private static function js_strings() {
		return array(
			'%1$s sauvegarde(s) ajoutée(s), %2$s retirée(s) de la liste.' => __('%1$s sauvegarde(s) ajoutée(s), %2$s retirée(s) de la liste.', 'rempart-backup'),
			'Ajouter un emplacement' => __('Ajouter un emplacement', 'rempart-backup'),
			'Ajouter un service' => __('Ajouter un service', 'rempart-backup'),
			'Ce journal est vide.' => __('Ce journal est vide.', 'rempart-backup'),
			'Cette sauvegarde vient de %s. Son adresse sera remplacée par celle de ce site.' => __('Cette sauvegarde vient de %s. Son adresse sera remplacée par celle de ce site.', 'rempart-backup'),
			'Chargement…' => __('Chargement…', 'rempart-backup'),
			'Commence par' => __('Commence par', 'rempart-backup'),
			'Contient' => __('Contient', 'rempart-backup'),
			'Copié' => __('Copié', 'rempart-backup'),
			'Enregistrer' => __('Enregistrer', 'rempart-backup'),
			'Enregistrer et connecter le compte' => __('Enregistrer et connecter le compte', 'rempart-backup'),
			'Envoi : %s' => __('Envoi : %s', 'rempart-backup'),
			'Envoi…' => __('Envoi…', 'rempart-backup'),
			'Erreur inconnue.' => __('Erreur inconnue.', 'rempart-backup'),
			'Exclu' => __('Exclu', 'rempart-backup'),
			'Exclure' => __('Exclure', 'rempart-backup'),
			'Extension' => __('Extension', 'rempart-backup'),
			'Finit par' => __('Finit par', 'rempart-backup'),
			'Gardez cette page ouverte jusqu\'à la fin.' => __('Gardez cette page ouverte jusqu\'à la fin.', 'rempart-backup'),
			'La restauration n\'a pas abouti' => __('La restauration n\'a pas abouti', 'rempart-backup'),
			'La tâche n\'a pas abouti' => __('La tâche n\'a pas abouti', 'rempart-backup'),
			'Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.' => __('Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.', 'rempart-backup'),
			'Modifier l\'emplacement' => __('Modifier l\'emplacement', 'rempart-backup'),
			'Modifier le service' => __('Modifier le service', 'rempart-backup'),
			'Restauration arrêtée' => __('Restauration arrêtée', 'rempart-backup'),
			'Restauration en cours' => __('Restauration en cours', 'rempart-backup'),
			'Restauration terminée' => __('Restauration terminée', 'rempart-backup'),
			'Retirer' => __('Retirer', 'rempart-backup'),
			'Récupération de la sauvegarde' => __('Récupération de la sauvegarde', 'rempart-backup'),
			'Réponse illisible du serveur.' => __('Réponse illisible du serveur.', 'rempart-backup'),
			'Sauvegarde en cours' => __('Sauvegarde en cours', 'rempart-backup'),
			'Sauvegarde terminée' => __('Sauvegarde terminée', 'rempart-backup'),
			'Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.' => __('Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.', 'rempart-backup'),
			'Supprimer définitivement %s sauvegarde(s) ?' => __('Supprimer définitivement %s sauvegarde(s) ?', 'rempart-backup'),
			'Supprimer définitivement cette sauvegarde ?' => __('Supprimer définitivement cette sauvegarde ?', 'rempart-backup'),
			'Sélectionnez au moins une sauvegarde.' => __('Sélectionnez au moins une sauvegarde.', 'rempart-backup'),
			'Test…' => __('Test…', 'rempart-backup'),
			'Tâche arrêtée' => __('Tâche arrêtée', 'rempart-backup'),
			'Tâche en cours' => __('Tâche en cours', 'rempart-backup'),
			'par exemple %s' => __('par exemple %s', 'rempart-backup'),
		);
	}

	private static function restore_info() {
		$restore = Rempart_Restore::current();
		return $restore ? array('url' => $restore['runner_url'], 'key' => $restore['key']) : null;
	}

	/** The reminder shown across the admin when no backup was made for a while. */
	public static function nag() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$nag_time = (int) get_option('rempart_nag_time', 0);
		if (!$nag_time) {
			$nag_time = time() - REMPART_NAG_FIRST_OFFSET;
			update_option('rempart_nag_time', $nag_time, false);
		}
		$screen = get_current_screen();
		if ($screen && $screen->id === 'toplevel_page_rempart-backup') {
			return;
		}
		$last = (int) get_option('rempart_last_backup', 0);
		if ((time() - $last) < REMPART_NAG_SECONDS || (time() - $nag_time) < REMPART_NAG_SECONDS) {
			return;
		}
		$url = esc_url(admin_url('admin.php?page=rempart-backup'));
		$message = $last
			? sprintf(__('Votre dernière sauvegarde date de %d jours.', 'rempart-backup'), floor((time() - $last) / DAY_IN_SECONDS))
			: __('Vous n\'avez encore aucune sauvegarde de ce site.', 'rempart-backup');
		$nonce = wp_create_nonce('rempart');
		echo '<div class="notice notice-warning is-dismissible" id="rempart-nag"><p>' . esc_html($message) . ' <a href="' . $url . '">' . esc_html__('Faire une sauvegarde', 'rempart-backup') . '</a></p></div>';
		echo '<script>document.addEventListener("click",function(e){if(e.target.closest&&e.target.closest("#rempart-nag .notice-dismiss")){var b=new URLSearchParams({action:"rempart_hide_nag",_ajax_nonce:' . wp_json_encode($nonce) . '});fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",credentials:"same-origin",body:b})}});</script>';
	}

	/* ----- Submitted forms ----- */

	public static function handle_forms() {
		rempart_self_heal();
		if (!isset($_GET['page']) || $_GET['page'] !== 'rempart-backup' || !current_user_can('manage_options')) {
			return;
		}

		// The cloud account's page sends the person back here with a code.
		if (isset($_GET['state'], $_GET['code'])) {
			self::finish_oauth();
			return;
		}
		if (isset($_GET['state'], $_GET['error'])) {
			self::redirect_with('location', __('L\'autorisation a été annulée.', 'rempart-backup'), 'error');
		}

		if (empty($_POST['rempart_form'])) {
			if (isset($_GET['rempart_msg'])) {
				self::$notice = array(sanitize_text_field(wp_unslash($_GET['rempart_msg'])), isset($_GET['rempart_type']) ? sanitize_key($_GET['rempart_type']) : 'success');
			}
			return;
		}
		check_admin_referer('rempart_form');
		$form = sanitize_key($_POST['rempart_form']);
		try {
			switch ($form) {
				case 'settings':
					self::save_settings();
					self::redirect_with('settings', __('Réglages enregistrés.', 'rempart-backup'));
					break;
				case 'location':
					self::save_location();
					break;
				case 'delete_location':
					Rempart_Locations::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('location', __('Emplacement supprimé.', 'rempart-backup'));
					break;
				case 'channel':
					$id = !empty($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;
					$channel = Rempart_Notifications::save(wp_unslash($_POST), $id);
					self::redirect_with('notifications', sprintf(__('Canal « %s » enregistré. Utilisez « Envoyer un test » pour vérifier qu\'il fonctionne.', 'rempart-backup'), $channel['name']));
					break;
				case 'delete_channel':
					Rempart_Notifications::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('notifications', __('Canal supprimé.', 'rempart-backup'));
					break;
				case 'connect':
					$location = Rempart_Locations::get(sanitize_text_field(wp_unslash($_POST['id'])));
					if ($location) {
						wp_redirect(Rempart_Locations::oauth_start($location));
						exit;
					}
					break;
			}
		} catch (Exception $e) {
			self::$notice = array($e->getMessage(), 'error');
		}
	}

	private static function redirect_with($tab, $message, $type = 'success') {
		wp_safe_redirect(add_query_arg(array('page' => 'rempart-backup', 'rempart_msg' => rawurlencode($message), 'rempart_type' => $type), admin_url('admin.php')) . '#' . $tab);
		exit;
	}

	private static function finish_oauth() {
		try {
			$state = sanitize_text_field(wp_unslash($_GET['state']));
			$code = sanitize_text_field(wp_unslash($_GET['code']));
			$location = Rempart_Locations::oauth_finish($state, $code, wp_unslash($_GET));
			self::redirect_with('location', sprintf(__('« %s » est connecté.', 'rempart-backup'), $location['name']));
		} catch (Exception $e) {
			self::redirect_with('location', $e->getMessage(), 'error');
		}
	}

	private static function save_settings() {
		$settings = rempart_settings();
		$settings['backup_files'] = !empty($_POST['backup_files']) ? 1 : 0;
		$settings['backup_db'] = !empty($_POST['backup_db']) ? 1 : 0;
		$settings['backup_scope'] = rempart_valid_scope(isset($_POST['backup_scope']) ? sanitize_key($_POST['backup_scope']) : 'all');
		$settings['auto_scope'] = rempart_valid_scope(isset($_POST['auto_scope']) ? sanitize_key($_POST['auto_scope']) : 'all');
		$settings['location'] = isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
		$settings['pre_update_backup'] = !empty($_POST['pre_update_backup']) ? 1 : 0;
		$settings['auto_update_backup'] = !empty($_POST['auto_update_backup']) ? 1 : 0;

		$schedule = isset($_POST['schedule']) ? sanitize_key($_POST['schedule']) : '';
		$settings['schedule'] = ($schedule === 'custom' || isset(Rempart_Schedule::intervals()[$schedule])) ? $schedule : '';
		$rotation = isset($_POST['rotation']) ? sanitize_key($_POST['rotation']) : '';
		$settings['rotation'] = $rotation === 'custom' ? max(1, (int) ($_POST['rotation_custom'] ?? 1)) : (int) $rotation;
		$settings['auto_files'] = !empty($_POST['auto_files']) ? 1 : 0;
		$settings['auto_db'] = !empty($_POST['auto_db']) ? 1 : 0;
		$settings['auto_location'] = isset($_POST['auto_location']) ? sanitize_text_field(wp_unslash($_POST['auto_location'])) : '';
		if ($settings['schedule'] !== '' && !$settings['auto_files'] && !$settings['auto_db']) {
			throw new RuntimeException(__('Pour les sauvegardes automatiques, choisissez les fichiers, la base de données ou les deux.', 'rempart-backup'));
		}

		$settings['additional'] = isset($_POST['additional']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['additional'])) : array();
		$settings['debug'] = !empty($_POST['debug']) ? 1 : 0;
		$settings['gzip_level'] = min(9, max(1, (int) ($_POST['gzip_level'] ?? 9)));
		update_option('rempart_settings', $settings, false);

		$excludes = rempart_excludes();
		$excludes['db'] = isset($_POST['exclude_db']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['exclude_db'])) : array();
		update_option('rempart_excludes', $excludes, false);

		Rempart_Schedule::sync();
	}

	private static function save_location() {
		$id = !empty($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;
		$values = wp_unslash($_POST);
		$location = Rempart_Locations::save($values, $id);

		$protocols = Rempart_Locations::protocols();
		if (!empty($protocols[$location['protocol']]['oauth'])) {
			$secrets = Rempart_Locations::config_of($location);
			if (empty($secrets['refresh_token'])) {
				wp_redirect(Rempart_Locations::oauth_start($location));
				exit;
			}
			self::redirect_with('location', __('Emplacement enregistré.', 'rempart-backup'));
		}
		try {
			Rempart_Storage::make($location)->test();
		} catch (Exception $e) {
			self::redirect_with('location', sprintf(__('Emplacement enregistré, mais la connexion a échoué : %s', 'rempart-backup'), $e->getMessage()), 'error');
		}
		self::redirect_with('location', __('Emplacement enregistré. La connexion fonctionne.', 'rempart-backup'));
	}

	/* ----- The page ----- */

	public static function page() {
		rempart_prepare_folders();
		$settings = rempart_settings();
		$locations = Rempart_Locations::all();
		$protocols = Rempart_Locations::protocols();
		?>
		<div class="wrap rempart">
			<header class="rempart-top">
				<svg class="rempart-logo" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 3 6 10v13c0 11 7.5 18.6 18 22 10.500-3.400 18-11 18-22V10z" fill="#1f6f8b"/><path d="M15 17h4v-4h3v4h4v-4h3v4h4v8c0 6-4 10-9 12-5-2-9-6-9-12z" fill="#fff"/></svg>
				<div>
					<h1><?php echo esc_html__('Rempart Backup', 'rempart-backup'); ?></h1>
					<p><?php echo esc_html__('Sauvegarde, restauration, migration et clonage. Tout est inclus, sans licence.', 'rempart-backup'); ?></p>
				</div>
			</header>

			<?php if (self::$notice) : ?>
				<div class="notice notice-<?php echo self::$notice[1] === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html(self::$notice[0]); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper rempart-tabs">
				<a class="nav-tab" href="#dashboard"><?php echo esc_html__('Tableau de bord', 'rempart-backup'); ?></a>
				<a class="nav-tab" href="#settings"><?php echo esc_html__('Réglages', 'rempart-backup'); ?></a>
				<a class="nav-tab" href="#location"><?php echo esc_html__('Emplacements', 'rempart-backup'); ?></a>
				<a class="nav-tab" href="#history"><?php echo esc_html__('Historique', 'rempart-backup'); ?></a>
				<a class="nav-tab" href="#notifications"><?php echo esc_html__('Notifications', 'rempart-backup'); ?></a>
				<a class="nav-tab" href="#help"><?php echo esc_html__('Aide', 'rempart-backup'); ?></a>
			</nav>

			<?php
			self::tab_dashboard($settings, $locations, $protocols);
			self::tab_settings($settings, $locations, $protocols);
			self::tab_locations($locations, $protocols);
			self::tab_history($locations, $protocols);
			self::tab_notifications();
			self::tab_help();
			self::modals();
			?>
		</div>
		<?php
	}

	private static function scope_options($selected) {
		foreach (rempart_scopes() as $key => $scope) {
			printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($selected, $key, false), esc_html($scope['label']));
		}
	}

	private static function location_options($selected, $locations, $protocols) {
		echo '<option value="">' . esc_html__('Dossier local (par défaut)', 'rempart-backup') . '</option>';
		foreach ($locations as $id => $location) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr($id),
				selected((string) $selected, (string) $id, false),
				esc_html($location['name']),
				esc_html($protocols[$location['protocol']]['label'] ?? $location['protocol'])
			);
		}
	}

	private static function tab_dashboard($settings, $locations, $protocols) {
		$last_backup = (int) get_option('rempart_last_backup', 0);
		$last_restore = (int) get_option('rempart_last_restore', 0);
		$next = wp_next_scheduled('rempart_auto_backup');
		$date_format = _x('j F Y à H:i', 'date and time format', 'rempart-backup');
		?>
		<section id="tab-dashboard" class="rempart-tab">
			<div class="rempart-facts">
				<div class="rempart-fact">
					<span><?php echo esc_html__('Dernière sauvegarde', 'rempart-backup'); ?></span>
					<strong><?php echo $last_backup ? esc_html(wp_date($date_format, $last_backup)) : esc_html__('Aucune', 'rempart-backup'); ?></strong>
					<?php if ($last_backup) : ?><button type="button" class="button-link rempart-show-log" data-log="backup"><?php echo esc_html__('Voir le journal', 'rempart-backup'); ?></button><?php endif; ?>
				</div>
				<div class="rempart-fact">
					<span><?php echo esc_html__('Dernière restauration', 'rempart-backup'); ?></span>
					<strong><?php echo $last_restore ? esc_html(wp_date($date_format, $last_restore)) : esc_html__('Aucune', 'rempart-backup'); ?></strong>
					<?php if ($last_restore) : ?><button type="button" class="button-link rempart-show-log" data-log="restore"><?php echo esc_html__('Voir le journal', 'rempart-backup'); ?></button><?php endif; ?>
				</div>
				<div class="rempart-fact">
					<span><?php echo esc_html__('Prochaine sauvegarde automatique', 'rempart-backup'); ?></span>
					<strong><?php
					if ($next) {
						echo esc_html(wp_date($date_format, $next));
						if (time() - $next > 300) {
							echo ' (en retard)';
						}
					} elseif ($settings['schedule'] === 'custom') {
						echo __('Tâche cron personnalisée', 'rempart-backup');
					} else {
						echo __('Rien de prévu', 'rempart-backup');
					}
					?></strong>
				</div>
			</div>

			<?php $ls = Rempart_Litespeed::status(); ?>
			<?php if ($ls[0] === 'warning') : ?>
				<div class="notice notice-warning inline"><p><strong><?php echo esc_html__('Serveur LiteSpeed détecté.', 'rempart-backup'); ?></strong> <?php echo esc_html__('Si une sauvegarde se bloque sans erreur,', 'rempart-backup'); ?> <a href="#help"><?php echo esc_html__('ajoutez la règle LiteSpeed dans l\'onglet Aide', 'rempart-backup'); ?></a>.</p></div>
			<?php endif; ?>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Créer une sauvegarde', 'rempart-backup'); ?></h2>
				<form id="rempart-backup-form">
					<p class="rempart-checks">
						<label><input type="checkbox" name="files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'rempart-backup'); ?></label>
						<label><input type="checkbox" name="db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> <?php echo esc_html__('Base de données', 'rempart-backup'); ?></label>
					</p>
					<p>
						<label for="rempart-backup-scope" class="rempart-label"><?php echo esc_html__('Fichiers à garder', 'rempart-backup'); ?></label>
						<select name="scope" id="rempart-backup-scope"><?php self::scope_options($settings['backup_scope']); ?></select>
						<span class="description"><?php echo esc_html__('Sans le cœur de WordPress, la sauvegarde se restaure sur un WordPress déjà installé, de la même version.', 'rempart-backup'); ?></span>
					</p>
					<p>
						<label for="rempart-backup-location" class="rempart-label"><?php echo esc_html__('Où la garder', 'rempart-backup'); ?></label>
						<select name="location" id="rempart-backup-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</p>
					<details class="rempart-more">
						<summary><?php echo esc_html__('Options supplémentaires', 'rempart-backup'); ?></summary>
						<label for="rempart-note" class="rempart-label"><?php echo esc_html__('Note (facultatif)', 'rempart-backup'); ?></label>
						<textarea name="note" id="rempart-note" maxlength="<?php echo (int) REMPART_NOTE_MAX; ?>" rows="2" placeholder="<?php echo esc_attr__('80 caractères au maximum', 'rempart-backup'); ?>"></textarea>
					</details>
					<p>
						<button type="submit" class="button button-primary button-hero rempart-go"><?php echo esc_html__('Créer la sauvegarde', 'rempart-backup'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	private static function tab_settings($settings, $locations, $protocols) {
		global $wpdb;
		$excludes = rempart_excludes();
		$rotation = (int) $settings['rotation'];
		?>
		<section id="tab-settings" class="rempart-tab" hidden>
			<form method="post">
				<?php wp_nonce_field('rempart_form'); ?>
				<input type="hidden" name="rempart_form" value="settings">

				<div class="rempart-card">
					<h2><?php echo esc_html__('Sauvegardes', 'rempart-backup'); ?></h2>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Par défaut, sauvegarder', 'rempart-backup'); ?></div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="backup_files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'rempart-backup'); ?></label>
							<label><input type="checkbox" name="backup_db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> <?php echo esc_html__('Base de données', 'rempart-backup'); ?></label>
						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-default-scope"><?php echo esc_html__('Fichiers à garder par défaut', 'rempart-backup'); ?></label>
						<select name="backup_scope" id="rempart-default-scope"><?php self::scope_options($settings['backup_scope']); ?></select>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-default-location"><?php echo esc_html__('Emplacement par défaut', 'rempart-backup'); ?></label>
						<select name="location" id="rempart-default-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Avant les mises à jour de WordPress', 'rempart-backup'); ?></div>
						<div class="rempart-checks rempart-stack">
							<label><input type="checkbox" name="pre_update_backup" value="1" <?php checked(!empty($settings['pre_update_backup'])); ?>> <?php echo esc_html__('Sauvegarder avant une mise à jour manuelle', 'rempart-backup'); ?></label>
							<label><input type="checkbox" name="auto_update_backup" value="1" <?php checked(!empty($settings['auto_update_backup'])); ?>> <?php echo esc_html__('Retenir les mises à jour automatiques jusqu\'à la fin d\'une sauvegarde', 'rempart-backup'); ?></label>
						</div>
					</div>
				</div>

				<div class="rempart-card">
					<h2><?php echo esc_html__('Sauvegardes automatiques', 'rempart-backup'); ?></h2>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-schedule"><?php echo esc_html__('Fréquence', 'rempart-backup'); ?></label>
						<select name="schedule" id="rempart-schedule">
							<option value=""><?php echo esc_html__('Pas de sauvegarde automatique', 'rempart-backup'); ?></option>
							<?php foreach (Rempart_Schedule::intervals() as $key => $interval) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($settings['schedule'], $key); ?>><?php echo esc_html($interval['display']); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php selected($settings['schedule'], 'custom'); ?>><?php echo esc_html__('Personnalisée (tâche cron du serveur)', 'rempart-backup'); ?></option>
						</select>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Comment ça marche', 'rempart-backup'); ?></div>
						<div class="rempart-cron-note">
							<p data-for="none"><?php echo esc_html__('Aucune sauvegarde automatique.', 'rempart-backup'); ?></p>
							<p data-for="auto"><strong><?php echo esc_html__('Automatique :', 'rempart-backup'); ?></strong> <?php echo esc_html__('rien d\'autre à configurer. Elle part à la première visite du site après l\'heure prévue. Pour une heure précise, choisissez « Personnalisée ».', 'rempart-backup'); ?></p>
							<p data-for="custom"><?php echo esc_html__('Ajoutez la commande ci-dessous aux tâches cron de votre hébergement.', 'rempart-backup'); ?></p>
							<?php
							$cron_status = self::cron_status($settings);
							if ($cron_status) :
								?>
								<p class="rempart-cron-status rempart-cron-<?php echo esc_attr($cron_status[0]); ?>"><?php echo esc_html($cron_status[1]); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<div class="rempart-row" id="rempart-cron-row">
						<div class="rempart-row-label"><?php echo esc_html__('Commande à planifier', 'rempart-backup'); ?></div>
						<div>
							<code class="rempart-code" id="rempart-cron-command">wget -q -O /dev/null "<?php echo esc_html(Rempart_Schedule::cron_url()); ?>"</code>
							<button type="button" class="button rempart-copy" data-copy="#rempart-cron-command"><?php echo esc_html__('Copier', 'rempart-backup'); ?></button>						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-rotation"><?php echo esc_html__('Sauvegardes automatiques à conserver', 'rempart-backup'); ?></label>
						<div class="rempart-inline">
							<select name="rotation" id="rempart-rotation">
								<option value="0" <?php selected($rotation, 0); ?>><?php echo esc_html__('Toutes', 'rempart-backup'); ?></option>
								<?php for ($i = 1; $i <= 10; $i++) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected($rotation, $i); ?>><?php echo (int) $i; ?></option>
								<?php endfor; ?>
								<option value="custom" <?php selected($rotation > 10); ?>><?php echo esc_html__('Autre nombre', 'rempart-backup'); ?></option>
							</select>
							<input type="number" min="1" name="rotation_custom" id="rempart-rotation-custom" value="<?php echo $rotation > 10 ? (int) $rotation : ''; ?>">
							<span id="rempart-rotation-warning" class="rempart-warn"><?php echo esc_html__('Plus de 30 sauvegardes peuvent occuper beaucoup de place.', 'rempart-backup'); ?></span>
						</div>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Contenu', 'rempart-backup'); ?></div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="auto_files" value="1" <?php checked(!empty($settings['auto_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'rempart-backup'); ?></label>
							<label><input type="checkbox" name="auto_db" value="1" <?php checked(!empty($settings['auto_db'])); ?>> <?php echo esc_html__('Base de données', 'rempart-backup'); ?></label>
						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-auto-scope"><?php echo esc_html__('Fichiers à garder', 'rempart-backup'); ?></label>
						<select name="auto_scope" id="rempart-auto-scope"><?php self::scope_options($settings['auto_scope']); ?></select>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-auto-location"><?php echo esc_html__('Emplacement', 'rempart-backup'); ?></label>
						<select name="auto_location" id="rempart-auto-location"><?php self::location_options($settings['auto_location'], $locations, $protocols); ?></select>
					</div>
				</div>

				<div class="rempart-card">
					<h2><?php echo esc_html__('Exclusions', 'rempart-backup'); ?></h2>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Tables de la base à exclure', 'rempart-backup'); ?></div>
						<details class="rempart-more">
							<summary><?php echo esc_html__('Choisir dans la liste', 'rempart-backup'); ?></summary>
							<div class="rempart-scroll">
								<?php foreach ((array) $wpdb->get_col('SHOW TABLES') as $table) : ?>
									<label class="rempart-line"><input type="checkbox" name="exclude_db[]" value="<?php echo esc_attr($table); ?>" <?php checked(in_array($table, $excludes['db'], true)); ?>> <?php echo esc_html($table); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Fichiers et dossiers à exclure', 'rempart-backup'); ?></div>
						<div>
							<div class="rempart-rule-box">
								<strong><?php echo esc_html__('Par nom', 'rempart-backup'); ?></strong>
								<div class="rempart-inline">
									<select id="rempart-rule-type">
										<option value="extension"><?php echo esc_html__('Avec l\'extension', 'rempart-backup'); ?></option>
										<option value="beginning"><?php echo esc_html__('Dont le nom commence par', 'rempart-backup'); ?></option>
										<option value="end"><?php echo esc_html__('Dont le nom finit par', 'rempart-backup'); ?></option>
										<option value="anywhere"><?php echo esc_html__('Dont le chemin contient', 'rempart-backup'); ?></option>
									</select>
									<input type="text" id="rempart-rule-pattern" placeholder="<?php echo esc_attr__('par exemple zip', 'rempart-backup'); ?>">
									<button type="button" class="button" id="rempart-rule-add"><?php echo esc_html__('Ajouter', 'rempart-backup'); ?></button>
								</div>
								<ul class="rempart-rules" id="rempart-rules-pattern">
									<?php
									$labels = array('extension' => 'Extension', 'beginning' => 'Commence par', 'end' => 'Finit par', 'anywhere' => 'Contient');
									foreach ($labels as $type => $label) {
										foreach ($excludes[$type] as $key => $pattern) {
											self::rule_item($type, $key, $label . ' : ' . $pattern);
										}
									}
									?>
								</ul>
							</div>
							<div class="rempart-rule-box">
								<strong><?php echo esc_html__('Dossiers ou fichiers précis', 'rempart-backup'); ?></strong>
								<button type="button" class="button" id="rempart-tree-open"><?php echo esc_html__('Parcourir le site…', 'rempart-backup'); ?></button>
								<ul class="rempart-rules" id="rempart-rules-exact">
									<?php
									foreach ($excludes['exact'] as $key => $pattern) {
										self::rule_item('exact', $key, $pattern);
									}
									?>
								</ul>
							</div>
						</div>
					</div>
				</div>

				<div class="rempart-card">
					<h2><?php echo esc_html__('Autres réglages', 'rempart-backup'); ?></h2>
					<?php
					$extra = self::additional_entries();
					if ($extra) :
						?>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Autres éléments à la racine du site', 'rempart-backup'); ?></div>
						<details class="rempart-more">
							<summary><?php echo esc_html__('Choisir ceux à inclure', 'rempart-backup'); ?></summary>
							<div class="rempart-scroll">
								<?php foreach ($extra as $item) : ?>
									<label class="rempart-line"><input type="checkbox" name="additional[]" value="<?php echo esc_attr($item); ?>" <?php checked(in_array($item, (array) $settings['additional'], true)); ?>> <?php echo esc_html($item); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<?php endif; ?>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Compression', 'rempart-backup'); ?></div>
						<label><?php echo esc_html__('Niveau', 'rempart-backup'); ?> <select name="gzip_level">
							<?php for ($level = 1; $level <= 9; $level++) : ?>
								<option value="<?php echo $level; ?>" <?php selected((int) $settings['gzip_level'], $level); ?>><?php echo $level; ?><?php echo $level === 9 ? ' ' . __('(archive la plus petite, la plus lente)', 'rempart-backup') : ($level === 1 ? ' ' . __('(la plus rapide, archive plus grosse)', 'rempart-backup') : ''); ?></option>
							<?php endfor; ?>
						</select></label>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Mode débogage', 'rempart-backup'); ?></div>
						<label><input type="checkbox" name="debug" value="1" <?php checked(!empty($settings['debug'])); ?>> <?php echo esc_html__('Écrire plus de détails dans le journal (ne le laissez pas activé)', 'rempart-backup'); ?></label>
					</div>
				</div>

				<p><button type="submit" class="button button-primary button-large"><?php echo esc_html__('Enregistrer les réglages', 'rempart-backup'); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * What the person should know about their schedule right now: when it runs next, and whether
	 * WordPress's scheduler can be counted on. Returns [level, text] or null.
	 */
	private static function cron_status($settings) {
		$cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		if ($settings['schedule'] === '') {
			return null;
		}
		if ($settings['schedule'] === 'custom') {
			return null;
		}
		if ($cron_disabled) {
			return array('warning', __('WP-Cron est désactivé sur ce site : rien ne partira seul. Choisissez « Personnalisée ».', 'rempart-backup'));
		}
		$next = wp_next_scheduled('rempart_auto_backup');
		if (!$next) {
			return array('warning', __('Cliquez sur « Enregistrer les réglages » pour activer la planification.', 'rempart-backup'));
		}
		if (time() - $next > 300) {
			return array('warning', sprintf(__('En retard de %s : en attente d\'une visite du site.', 'rempart-backup'), human_time_diff($next)));
		}
		return array('ok', sprintf(__('Prochaine sauvegarde automatique : %s.', 'rempart-backup'), wp_date(_x('j F Y à H:i', 'date and time format', 'rempart-backup'), $next)));
	}

	private static function rule_item($type, $key, $text) {
		printf(
			'<li data-type="%s" data-key="%s"><span>%s</span> <button type="button" class="button-link rempart-rule-del" aria-label="Retirer"><span class="dashicons dashicons-no-alt"></span></button></li>',
			esc_attr($type),
			esc_attr($key),
			esc_html($text)
		);
	}

	private static function additional_entries() {
		$items = @scandir(ABSPATH);
		$out = array();
		foreach ((array) $items as $item) {
			if ($item !== '.' && $item !== '..' && !in_array($item, rempart_core_entries(), true)) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function tab_locations($locations, $protocols) {
		// Space taken by our backups in each place. The local folder is keyed by ''.
		$stored_by_location = array();
		foreach (Rempart_Backups::all() as $backup) {
			$where = (string) ($backup['location'] ?? '');
			$stored_by_location[$where] = ($stored_by_location[$where] ?? 0) + (float) ($backup['size'] ?? 0);
		}
		$local_stored = $stored_by_location[''] ?? 0;
		$local_text = rempart_format_size($local_stored);
		?>
		<section id="tab-location" class="rempart-tab" hidden>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Emplacements de sauvegarde', 'rempart-backup'); ?></h2>
				<table class="widefat striped rempart-table">
					<thead><tr><th><?php echo esc_html__('Nom', 'rempart-backup'); ?></th><th><?php echo esc_html__('Type', 'rempart-backup'); ?></th><th><?php echo esc_html__('Serveur', 'rempart-backup'); ?></th><th><?php echo esc_html__('Dossier', 'rempart-backup'); ?></th><th><?php echo esc_html__('Espace utilisé', 'rempart-backup'); ?></th><th><?php echo esc_html__('Actions', 'rempart-backup'); ?></th></tr></thead>
					<tbody>
						<tr>
							<td><?php echo esc_html__('Dossier local', 'rempart-backup'); ?></td><td>-</td><td>-</td>
							<td><code><?php echo esc_html(rempart_clean_path((string) rempart_folder('backups'))); ?></code></td>
							<td><?php echo esc_html($local_text); ?></td><td>-</td>
						</tr>
						<?php
						foreach ($locations as $id => $location) :
							$config = (array) $location['config'];
							$is_oauth = !empty($protocols[$location['protocol']]['oauth']);
							$connected = false;
							if ($is_oauth) {
								try {
									$connected = !empty(Rempart_Locations::config_of($location)['refresh_token']);
								} catch (Exception $e) {
									$connected = false;
								}
							}
							// The provider's own usage when it reports one (OAuth services), otherwise what our backups take up there.
							$quota = $location['quota'] ?? null;
							$stored = $stored_by_location[(string) $id] ?? 0;
							if ($quota) {
								$quota_text = rempart_format_size($quota['used']) . (!empty($quota['total']) ? ' / ' . rempart_format_size($quota['total']) : '');
							} else {
								$quota_text = rempart_format_size($stored);
							}
							?>
							<tr>
								<td><strong><?php echo esc_html($location['name']); ?></strong></td>
								<td><?php echo esc_html($protocols[$location['protocol']]['label'] ?? $location['protocol']); ?></td>
								<td><?php echo esc_html($config['host'] ?? ($config['url'] ?? ($config['endpoint'] ?? '-'))); ?></td>
								<td><?php echo esc_html(($config['bucket'] ?? '') . (isset($config['bucket']) ? '/' : '') . ($config['folder'] ?? '')); ?></td>
								<td>
									<?php if ($is_oauth) : ?>
										<button type="button" class="button-link rempart-quota" data-id="<?php echo esc_attr($id); ?>" title="<?php echo esc_attr__('Actualiser', 'rempart-backup'); ?>"><?php echo esc_html($quota_text); ?></button>
									<?php else : ?>
										<?php echo esc_html($quota_text); ?>
									<?php endif; ?>
								</td>
								<td class="rempart-actions">
									<?php if ($is_oauth && !$connected) : ?>
										<form method="post"><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="connect"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button button-primary"><?php echo esc_html__('Connecter le compte', 'rempart-backup'); ?></button></form>
									<?php else : ?>
										<button type="button" class="button rempart-test" data-id="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Tester', 'rempart-backup'); ?></button>
									<?php endif; ?>
									<button type="button" class="button rempart-edit" data-location="<?php echo esc_attr(wp_json_encode(array('id' => $location['id'], 'name' => $location['name'], 'protocol' => $location['protocol']) + $config)); ?>"><?php echo esc_html__('Modifier', 'rempart-backup'); ?></button>
									<form method="post" class="rempart-confirm" data-confirm="<?php echo esc_attr__('Supprimer cet emplacement ? Les sauvegardes déjà envoyées y restent.', 'rempart-backup'); ?>"><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="delete_location"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button rempart-danger"><?php echo esc_html__('Supprimer', 'rempart-backup'); ?></button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="rempart-add-location"><?php echo esc_html__('Ajouter un emplacement', 'rempart-backup'); ?></button></p>
			</div>

			<div class="rempart-card" id="rempart-location-form-card" hidden>
				<h2 id="rempart-location-form-title"><?php echo esc_html__('Ajouter un emplacement', 'rempart-backup'); ?></h2>
				<form method="post" id="rempart-location-form" autocomplete="off">
					<?php wp_nonce_field('rempart_form'); ?>
					<input type="hidden" name="rempart_form" value="location">
					<input type="hidden" name="id" value="">
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-loc-name"><?php echo esc_html__('Nom', 'rempart-backup'); ?></label>
						<input type="text" name="name" id="rempart-loc-name" class="regular-text" required>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-loc-protocol"><?php echo esc_html__('Type', 'rempart-backup'); ?></label>
						<select name="protocol" id="rempart-loc-protocol">
							<?php foreach ($protocols as $key => $protocol) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($protocol['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php self::location_fields($protocols); ?>
					<div class="rempart-oauth-help" hidden>
						<p><strong><?php echo esc_html__('Adresse de retour à déclarer dans votre application :', 'rempart-backup'); ?></strong><br><code class="rempart-code" id="rempart-redirect"><?php echo esc_html(admin_url('admin.php?page=rempart-backup')); ?></code> <button type="button" class="button rempart-copy" data-copy="#rempart-redirect"><?php echo esc_html__('Copier', 'rempart-backup'); ?></button></p>
						<p class="description"><?php echo esc_html__('Ce service demande votre propre application : l\'onglet Aide explique comment la créer. Après l\'enregistrement, vous serez envoyé sur sa page pour accepter l\'accès.', 'rempart-backup'); ?></p>
					</div>
					<p>
						<button type="submit" class="button button-primary" id="rempart-loc-submit"><?php echo esc_html__('Enregistrer', 'rempart-backup'); ?></button>
						<button type="button" class="button" id="rempart-loc-cancel"><?php echo esc_html__('Annuler', 'rempart-backup'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	/** One set of fields per storage type; the script shows those of the chosen type. */
	private static function location_fields($protocols) {
		foreach ($protocols as $key => $protocol) {
			echo '<div class="rempart-loc-fields" data-protocol="' . esc_attr($key) . '" data-oauth="' . (!empty($protocol['oauth']) ? '1' : '0') . '" hidden>';
			foreach ($protocol['fields'] as $name => $field) {
				$type = $field['type'] ?? 'text';
				$input_id = 'rempart-' . $key . '-' . $name;
				$required = !empty($field['required']) && empty($field['secret']);
				$default = $field['default'] ?? '';
				if ($name === 'port' && !empty($protocol['default_port'])) {
					$default = $protocol['default_port'];
				}
				echo '<div class="rempart-row"><label class="rempart-row-label" for="' . esc_attr($input_id) . '">' . esc_html($field['label']) . '</label><div>';
				$common = 'name="' . esc_attr($name) . '" id="' . esc_attr($input_id) . '" data-default="' . esc_attr($default) . '"' . ($required ? ' data-required="1"' : '') . (!empty($field['secret']) ? ' data-secret="1"' : '');
				if ($type === 'select') {
					$presets = ($key === 's3' && $name === 'service') ? ' data-presets="' . esc_attr(wp_json_encode(Rempart_Locations::s3_presets())) . '"' : '';
					echo '<select ' . $common . $presets . '>';
					foreach ($field['options'] as $value => $label) {
						echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
					}
					echo '</select>';
				} elseif ($type === 'textarea') {
					echo '<textarea rows="5" class="large-text code" ' . $common . '></textarea>';
				} elseif ($type === 'checkbox') {
					echo '<input type="checkbox" value="1" ' . $common . '>';
				} else {
					$html_type = $type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text');
					echo '<input type="' . esc_attr($html_type) . '" class="regular-text" autocomplete="new-password" ' . $common . '>';
				}
				if (!empty($field['help'])) {
					echo '<p class="description">' . esc_html($field['help']) . '</p>';
				}
				if (!empty($field['secret'])) {
					echo '<p class="description rempart-secret-note" hidden>' . esc_html__('Laissez vide pour garder la valeur enregistrée.', 'rempart-backup') . '</p>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}
	}

	private static function tab_history($locations, $protocols) {
		Rempart_Backups::sync_local();
		// 'all', 'local' (the local folder, stored as location '') or the id of a location.
		$filter = isset($_GET['history_location']) ? sanitize_text_field(wp_unslash($_GET['history_location'])) : 'all';
		$all = array_values(array_filter(Rempart_Backups::all(), function ($info) use ($filter) {
			if ($filter === 'all') {
				return true;
			}
			$where = (string) ($info['location'] ?? '');
			return $filter === 'local' ? $where === '' : $where === $filter;
		}));
		$filter_query = $filter === 'all' ? '' : '&history_location=' . rawurlencode($filter);
		$total = count($all);
		$pages = max(1, (int) ceil($total / REMPART_HISTORY_PER_PAGE));
		$page = isset($_GET['history_page']) ? max(1, min($pages, (int) $_GET['history_page'])) : 1;
		$rows = array_slice($all, ($page - 1) * REMPART_HISTORY_PER_PAGE, REMPART_HISTORY_PER_PAGE);
		?>
		<section id="tab-history" class="rempart-tab" hidden>
			<div class="rempart-card">
				<div class="rempart-history-tools">
					<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>#history" class="rempart-inline">
						<input type="hidden" name="page" value="rempart-backup">
						<label><?php echo esc_html__('Afficher :', 'rempart-backup'); ?>
							<select name="history_location">
								<option value="all" <?php selected($filter, 'all'); ?>><?php echo esc_html__('Tous les emplacements', 'rempart-backup'); ?></option>
								<option value="local" <?php selected($filter, 'local'); ?>><?php echo esc_html__('Dossier local', 'rempart-backup'); ?></option>
								<?php foreach ($locations as $id => $location) : ?>
									<option value="<?php echo esc_attr($id); ?>" <?php selected($filter, (string) $id); ?>><?php echo esc_html($location['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="submit" class="button"><?php echo esc_html__('Filtrer', 'rempart-backup'); ?></button>
					</form>
					<div class="rempart-inline">
						<label><?php echo esc_html__('Synchroniser depuis :', 'rempart-backup'); ?>
						<select id="rempart-sync-location">
							<option value="local"><?php echo esc_html__('Dossier local', 'rempart-backup'); ?></option>
							<?php foreach ($locations as $id => $location) : ?>
								<option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($location['name']); ?></option>
							<?php endforeach; ?>
						</select>
						</label>
						<button type="button" class="button" id="rempart-sync"><?php echo esc_html__('Synchroniser', 'rempart-backup'); ?></button>
						<button type="button" class="button" id="rempart-upload-open"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Importer une sauvegarde', 'rempart-backup'); ?></button>
						<input type="file" id="rempart-upload-file" accept=".gz" hidden>
					</div>
					<div class="rempart-inline">
						<button type="button" class="button" id="rempart-select-all"><?php echo esc_html__('Tout sélectionner', 'rempart-backup'); ?></button>
						<button type="button" class="button rempart-danger" id="rempart-delete-selected"><?php echo esc_html__('Supprimer', 'rempart-backup'); ?></button>
					</div>
				</div>
				<div id="rempart-upload-progress" hidden><div class="rempart-bar"><i></i></div><span></span></div>

				<table class="widefat striped rempart-table" id="rempart-history-table">
					<thead><tr><th class="check-column"></th><th><?php echo esc_html__('Date', 'rempart-backup'); ?></th><th><?php echo esc_html__('Emplacement', 'rempart-backup'); ?></th><th><?php echo esc_html__('Taille', 'rempart-backup'); ?></th><th><?php echo esc_html__('Contenu', 'rempart-backup'); ?></th><th><?php echo esc_html__('Note', 'rempart-backup'); ?></th><th><?php echo esc_html__('Actions', 'rempart-backup'); ?></th></tr></thead>
					<tbody>
						<?php if (!$rows) : ?>
							<tr><td colspan="7" class="rempart-empty"><?php echo $filter === 'all' ? esc_html__('Aucune sauvegarde pour le moment.', 'rempart-backup') : esc_html__('Aucune sauvegarde à cet emplacement.', 'rempart-backup'); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ($rows as $info) :
							$location = !empty($info['location']) ? ($locations[$info['location']] ?? null) : null;
							$location_label = empty($info['location']) ? __('Local', 'rempart-backup') : ($location ? $location['name'] : __('Emplacement supprimé', 'rempart-backup'));
							$has_log = file_exists(REMPART_ROOT . 'logs/' . $info['name'] . '.log.php');
							$content = array();
							if (!empty($info['backup_dir'])) {
								$content[] = __('Fichiers', 'rempart-backup');
							}
							if (!empty($info['backup_db'])) {
								$content[] = __('Base de données', 'rempart-backup');
							}
							$note = $info['note'] ?? ($info['backup_note'] ?? '');
							$download = wp_nonce_url(admin_url('admin-ajax.php?action=rempart_download&name=' . rawurlencode($info['name'])), 'rempart');
							$unreachable = !empty($info['location']) && !$location;
							?>
							<tr>
								<td><input type="checkbox" class="rempart-pick" value="<?php echo esc_attr($info['name']); ?>"></td>
								<td>
									<?php echo esc_html(wp_date('j M Y H:i', (int) $info['btime'])); ?>
									<?php if (!empty($info['auto_backup'])) : ?><span class="rempart-tag"><?php echo esc_html__('Auto', 'rempart-backup'); ?></span><?php endif; ?>
								</td>
								<td><?php echo esc_html($location_label); ?></td>
								<td><?php echo esc_html(rempart_format_size($info['size'] ?? 0)); ?></td>
								<td><?php echo esc_html(implode(' + ', $content)); ?></td>
								<td><?php echo esc_html($note); ?></td>
								<td class="rempart-actions">
									<button type="button" class="button button-primary rempart-restore" <?php disabled($unreachable); ?>
										data-name="<?php echo esc_attr($info['name']); ?>"
										data-files="<?php echo !empty($info['backup_dir']) ? '1' : '0'; ?>"
										data-db="<?php echo !empty($info['backup_db']) ? '1' : '0'; ?>"
										data-source="<?php echo esc_attr($info['site_url'] ?? ($info['backup_site_url'] ?? '')); ?>"><?php echo esc_html__('Restaurer', 'rempart-backup'); ?></button>
									<?php if (!$unreachable) : ?><a class="button" href="<?php echo esc_url($download); ?>"><?php echo esc_html__('Télécharger', 'rempart-backup'); ?></a><?php endif; ?>
									<?php if ($has_log) : ?><button type="button" class="button rempart-show-log" data-log="<?php echo esc_attr($info['name']); ?>"><?php echo esc_html__('Journal', 'rempart-backup'); ?></button><?php endif; ?>
									<button type="button" class="button rempart-danger rempart-delete" data-name="<?php echo esc_attr($info['name']); ?>"><?php echo esc_html__('Supprimer', 'rempart-backup'); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ($pages > 1) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<span class="displaying-num"><?php echo (int) $total; ?> <?php echo esc_html__('sauvegardes', 'rempart-backup'); ?></span>
						<?php for ($i = 1; $i <= $pages; $i++) : ?>
							<a class="button <?php echo $i === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rempart-backup&history_page=' . $i . $filter_query . '#history')); ?>"><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</div></div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private static function tab_notifications() {
		$types = Rempart_Notifications::types();
		$channels = Rempart_Notifications::all();
		?>
		<section id="tab-notifications" class="rempart-tab" hidden>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Être prévenu', 'rempart-backup'); ?></h2>
				<p><?php echo esc_html__('Chaque sauvegarde réussie ou ratée peut envoyer un message. L\'e-mail est un service comme les autres : ajoutez-en autant que vous voulez, de toutes sortes, et choisissez pour chacun s\'il veut les réussites, les échecs ou les deux.', 'rempart-backup'); ?></p>
				<table class="widefat striped rempart-table">
					<thead><tr><th><?php echo esc_html__('Nom', 'rempart-backup'); ?></th><th><?php echo esc_html__('Service', 'rempart-backup'); ?></th><th><?php echo esc_html__('Prévenir en cas de', 'rempart-backup'); ?></th><th><?php echo esc_html__('Dernier envoi', 'rempart-backup'); ?></th><th><?php echo esc_html__('Actions', 'rempart-backup'); ?></th></tr></thead>
					<tbody>
						<?php if (!$channels) : ?>
							<tr><td colspan="5" class="rempart-empty"><?php echo esc_html__('Aucune notification pour le moment. Ajoutez un e-mail ou un service.', 'rempart-backup'); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ($channels as $id => $channel) :
							$last = $channel['last'] ?? null;
							$events = array();
							if (!empty($channel['on_success'])) {
								$events[] = __('réussite', 'rempart-backup');
							}
							if (!empty($channel['on_failure'])) {
								$events[] = __('échec', 'rempart-backup');
							}
							$edit = array('id' => $channel['id'], 'name' => $channel['name'], 'type' => $channel['type'], 'enabled' => !empty($channel['enabled']) ? 1 : 0, 'on_success' => !empty($channel['on_success']) ? 1 : 0, 'on_failure' => !empty($channel['on_failure']) ? 1 : 0) + (array) $channel['config'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html($channel['name']); ?></strong><?php echo empty($channel['enabled']) ? ' <span class="rempart-tag">' . esc_html__('En pause', 'rempart-backup') . '</span>' : ''; ?>
									<?php if ($channel['type'] === 'email' && !empty($channel['config']['address'])) : ?><br><small><?php echo esc_html($channel['config']['address']); ?></small><?php endif; ?>
								</td>
								<td><?php echo esc_html($types[$channel['type']]['label'] ?? $channel['type']); ?></td>
								<td><?php echo esc_html($events ? implode(' et ', $events) : 'rien'); ?></td>
								<td>
									<?php if ($last) : ?>
										<span class="<?php echo !empty($last['ok']) ? 'rempart-ok' : 'rempart-bad'; ?>"><?php echo esc_html(!empty($last['ok']) ? _x('Réussi', 'result of the last send', 'rempart-backup') : _x('Échec', 'result of the last send', 'rempart-backup')); ?></span>
										<?php echo esc_html(wp_date('j M H:i', (int) $last['t'])); ?>
										<?php if (empty($last['ok'])) : ?><br><small><?php echo esc_html($last['text']); ?></small><?php endif; ?>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td class="rempart-actions">
									<button type="button" class="button rempart-ch-test" data-id="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Envoyer un test', 'rempart-backup'); ?></button>
									<button type="button" class="button rempart-ch-edit" data-channel="<?php echo esc_attr(wp_json_encode($edit)); ?>"><?php echo esc_html__('Modifier', 'rempart-backup'); ?></button>
									<form method="post" class="rempart-confirm" data-confirm="<?php echo esc_attr__('Supprimer ce canal de notification ?', 'rempart-backup'); ?>"><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="delete_channel"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button rempart-danger"><?php echo esc_html__('Supprimer', 'rempart-backup'); ?></button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="rempart-ch-add"><?php echo esc_html__('Ajouter une notification', 'rempart-backup'); ?></button></p>
			</div>

			<div class="rempart-card" id="rempart-ch-card" hidden>
				<h2 id="rempart-ch-title"><?php echo esc_html__('Ajouter un service', 'rempart-backup'); ?></h2>
				<form method="post" id="rempart-ch-form" autocomplete="off">
					<?php wp_nonce_field('rempart_form'); ?>
					<input type="hidden" name="rempart_form" value="channel">
					<input type="hidden" name="id" value="">
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-ch-type"><?php echo esc_html__('Service', 'rempart-backup'); ?></label>
						<select name="type" id="rempart-ch-type">
							<?php foreach ($types as $key => $type) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($type['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-ch-name"><?php echo esc_html__('Nom (facultatif)', 'rempart-backup'); ?></label>
						<input type="text" name="name" id="rempart-ch-name" class="regular-text" placeholder="<?php echo esc_attr__('Par exemple : Salon alertes', 'rempart-backup'); ?>">
					</div>
					<?php
					foreach ($types as $key => $type) {
						echo '<div class="rempart-ch-fields" data-type="' . esc_attr($key) . '" hidden>';
						foreach ($type['fields'] as $name => $field) {
							$input_id = 'rempart-ch-' . $key . '-' . $name;
							$required = !empty($field['required']) && empty($field['secret']);
							echo '<div class="rempart-row"><label class="rempart-row-label" for="' . esc_attr($input_id) . '">' . esc_html($field['label']) . '</label><div>';
							echo '<input type="' . (!empty($field['secret']) ? 'password' : 'text') . '" class="regular-text" autocomplete="new-password" name="' . esc_attr($name) . '" id="' . esc_attr($input_id) . '" data-default="' . esc_attr($field['default'] ?? '') . '"' . ($required ? ' data-required="1"' : '') . '>';
							if (!empty($field['help'])) {
								echo '<p class="description">' . esc_html($field['help']) . '</p>';
							}
							if (!empty($field['secret'])) {
								echo '<p class="description rempart-secret-note" hidden>' . esc_html__('Laissez vide pour garder la valeur enregistrée.', 'rempart-backup') . '</p>';
							}
							echo '</div></div>';
						}
						echo '</div>';
					}
					?>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('Prévenir en cas de', 'rempart-backup'); ?></div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="on_success" value="1" checked> <?php echo esc_html__('Réussite', 'rempart-backup'); ?></label>
							<label><input type="checkbox" name="on_failure" value="1" checked> <?php echo esc_html__('Échec', 'rempart-backup'); ?></label>
						</div>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label"><?php echo esc_html__('État', 'rempart-backup'); ?></div>
						<label><input type="checkbox" name="enabled" value="1" checked> <?php echo esc_html__('Actif', 'rempart-backup'); ?></label>
					</div>
					<p>
						<button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer', 'rempart-backup'); ?></button>
						<button type="button" class="button" id="rempart-ch-cancel"><?php echo esc_html__('Annuler', 'rempart-backup'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	private static function tab_help() {
		?>
		<section id="tab-help" class="rempart-tab" hidden>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Vérifier mon site', 'rempart-backup'); ?></h2>
				<p><?php echo esc_html__('Contrôle ce dont les sauvegardes ont besoin sur votre hébergement.', 'rempart-backup'); ?></p>
				<p><button type="button" class="button button-primary" id="rempart-diagnose"><?php echo esc_html__('Lancer la vérification', 'rempart-backup'); ?></button></p>
				<ul id="rempart-diagnose-list" class="rempart-checklist"></ul>
			</div>
			<?php $litespeed = Rempart_Litespeed::status(); ?>
			<div class="rempart-card" id="rempart-litespeed">
				<h2><?php echo esc_html__('Sauvegarde qui se bloque sans erreur (LiteSpeed)', 'rempart-backup'); ?></h2>
				<p><?php echo esc_html__('Sur certains serveurs LiteSpeed, une sauvegarde est tuée en route, sans message : elle reste coincée à un pourcentage fixe. La parade est une règle « noabort » dans le fichier .htaccess du site.', 'rempart-backup'); ?></p>
				<p class="rempart-cron-status rempart-cron-<?php echo $litespeed[0] === 'warning' ? 'warning' : ($litespeed[0] === 'ok' ? 'ok' : 'info'); ?>" id="rempart-litespeed-status"><?php echo esc_html($litespeed[1]); ?></p>
				<p class="rempart-inline">
					<button type="button" class="button button-primary rempart-litespeed" data-what="1"><?php echo esc_html__('Ajouter la règle', 'rempart-backup'); ?></button>
					<button type="button" class="button rempart-litespeed" data-what="2"><?php echo esc_html__('Essayer l\'autre forme', 'rempart-backup'); ?></button>
					<button type="button" class="button rempart-litespeed" data-what="remove"><?php echo esc_html__('Retirer la règle', 'rempart-backup'); ?></button>
				</p>
				<p class="description"><?php echo esc_html__('La règle est ajoutée au début du .htaccess, après en avoir gardé une copie. Si le site se met à planter à cause d\'elle, elle est retirée toute seule. L\'autre forme sert quand la première reste sans effet.', 'rempart-backup'); ?></p>
			</div>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Déplacer ou cloner un site', 'rempart-backup'); ?></h2>
				<ol>
					<li><?php echo esc_html__('Sur le site d\'origine, créez une sauvegarde complète et téléchargez-la, ou gardez-la sur un emplacement distant.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Sur le nouveau site, installez WordPress puis ce plugin.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Dans Historique, importez le fichier, ou ajoutez le même emplacement et cliquez sur Synchroniser.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Cliquez sur Restaurer. L\'adresse du site est remplacée partout dans la base de données ; la connexion à la base et les règles du serveur du nouveau site sont conservées.', 'rempart-backup'); ?></li>
				</ol>
				<p class="description"><?php echo esc_html__('Les sauvegardes faites avec le plugin Backuply peuvent aussi être importées et restaurées ici.', 'rempart-backup'); ?></p>
			</div>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Connecter un compte cloud', 'rempart-backup'); ?></h2>
				<p><?php echo esc_html__('Google Drive, OneDrive, Dropbox et pCloud demandent une application à votre nom : c\'est gratuit, et vos autorisations ne passent par aucun service tiers. L\'adresse de retour à déclarer est :', 'rempart-backup'); ?></p>
				<p><code class="rempart-code"><?php echo esc_html(admin_url('admin.php?page=rempart-backup')); ?></code></p>
				<details class="rempart-more"><summary>Google Drive</summary><ol>
					<li><?php echo esc_html__('Sur console.cloud.google.com, créez un projet et activez l\'API Google Drive.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Configurez l\'écran de consentement OAuth, avec votre adresse comme utilisateur de test.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Créez des identifiants « ID client OAuth », de type Application Web, avec l\'adresse de retour ci-dessus.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Copiez l\'identifiant et le secret dans le formulaire de l\'emplacement.', 'rempart-backup'); ?></li>
				</ol><p class="description"><?php echo esc_html__('Tant que l\'application reste en mode Test, Google retire son autorisation au bout de 7 jours. Publiez-la pour une connexion durable.', 'rempart-backup'); ?></p></details>
				<details class="rempart-more"><summary><?php echo esc_html__('Microsoft OneDrive', 'rempart-backup'); ?></summary><ol>
					<li><?php echo esc_html__('Sur entra.microsoft.com, ouvrez « Inscriptions d\'applications » et créez-en une pour tous les comptes Microsoft.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Ajoutez l\'adresse de retour ci-dessus, de type Web.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Dans « Certificats et secrets », créez un secret. Copiez sa valeur et l\'ID d\'application dans le formulaire.', 'rempart-backup'); ?></li>
				</ol></details>
				<details class="rempart-more"><summary>Dropbox</summary><ol>
					<li><?php echo esc_html__('Sur dropbox.com/developers/apps, créez une application avec l\'accès « Scoped », type « Full Dropbox ».', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Dans Permissions, cochez files.content.read, files.content.write et files.metadata.read.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Dans Settings, ajoutez l\'adresse de retour puis copiez App key et App secret dans le formulaire.', 'rempart-backup'); ?></li>
				</ol></details>
				<details class="rempart-more"><summary>pCloud</summary><ol>
					<li><?php echo esc_html__('Sur docs.pcloud.com/my_apps, créez une application avec l\'accès aux fichiers.', 'rempart-backup'); ?></li>
					<li><?php echo esc_html__('Ajoutez l\'adresse de retour puis copiez Client ID et Client secret dans le formulaire.', 'rempart-backup'); ?></li>
				</ol></details>
			</div>
			<div class="rempart-card">
				<h2><?php echo esc_html__('Recevoir les notifications sur son téléphone ou un salon', 'rempart-backup'); ?></h2>
				<p><?php echo esc_html__('Dans l\'onglet Notifications, ajoutez un e-mail ou un service, collez l\'adresse ou le jeton qu\'il vous donne, puis cliquez sur « Envoyer un test ».', 'rempart-backup'); ?></p>
				<ul class="rempart-bullets">
					<li><strong>Pushover</strong> <?php echo esc_html__(': le jeton d\'application se crée sur pushover.net/apps, la clé utilisateur est sur votre tableau de bord.', 'rempart-backup'); ?></li>
					<li><strong>Gotify</strong> <?php echo esc_html__(': adresse de votre serveur, puis un jeton d\'application créé dans son interface.', 'rempart-backup'); ?></li>
					<li><strong>ntfy</strong> <?php echo esc_html__(': choisissez un sujet long et secret, abonnez-vous-y dans l\'application ntfy.', 'rempart-backup'); ?></li>
					<li><strong>Telegram</strong> <?php echo esc_html__(': créez un bot avec @BotFather, écrivez-lui, puis relevez l\'identifiant de la discussion.', 'rempart-backup'); ?></li>
					<li><strong>Discord</strong> <?php echo esc_html__(': réglages du salon, Intégrations, Webhooks, Copier l\'URL.', 'rempart-backup'); ?></li>
					<li><strong>Slack</strong> <?php echo esc_html__(': créez un « Incoming Webhook » dans votre espace de travail.', 'rempart-backup'); ?></li>
					<li><strong>Webhook</strong> <?php echo esc_html__(': n\'importe quelle adresse qui accepte un POST en JSON (Zapier, n8n, Home Assistant...).', 'rempart-backup'); ?></li>
				</ul>
			</div>
			<div class="rempart-card">
				<h2><?php echo esc_html__('En ligne de commande', 'rempart-backup'); ?></h2>
				<pre class="rempart-pre">wp rempart backup [--files-only] [--db-only] [--location=&lt;id&gt;] [--note=&lt;texte&gt;]
wp rempart list
wp rempart locations
wp rempart restore &lt;nom&gt; [--files-only] [--db-only] [--yes]
wp rempart delete &lt;nom&gt;</pre>
			</div>
		</section>
		<?php
	}

	private static function modals() {
		?>
		<div class="rempart-modal" id="rempart-progress" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-progress-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-progress-title"><?php echo esc_html__('Sauvegarde en cours', 'rempart-backup'); ?></h2>
				<div class="rempart-bar"><i id="rempart-progress-bar"></i></div>
				<p class="rempart-percent"><span id="rempart-progress-percent">0</span> %</p>
				<p class="rempart-hint" id="rempart-progress-hint"><?php echo esc_html__('Vous pouvez fermer cette page, sauf si l\'onglet Aide indique que le site ne peut pas s\'appeler lui-même.', 'rempart-backup'); ?></p>
				<div class="rempart-log" id="rempart-progress-log" tabindex="0"></div>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-progress-stop"><?php echo esc_html__('Arrêter', 'rempart-backup'); ?></button>
					<button type="button" class="button" id="rempart-progress-force" hidden><?php echo esc_html__('Forcer l\'arrêt', 'rempart-backup'); ?></button>
					<button type="button" class="button button-primary" id="rempart-progress-close" hidden><?php echo esc_html__('Fermer', 'rempart-backup'); ?></button>
				</p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-restore-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-restore-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-restore-title"><?php echo esc_html__('Restaurer cette sauvegarde', 'rempart-backup'); ?></h2>
				<p class="rempart-checks">
					<label id="rempart-restore-files-wrap"><input type="checkbox" id="rempart-restore-files" checked> <?php echo esc_html__('Fichiers du site', 'rempart-backup'); ?></label>
					<label id="rempart-restore-db-wrap"><input type="checkbox" id="rempart-restore-db" checked> <?php echo esc_html__('Base de données', 'rempart-backup'); ?></label>
				</p>
				<p class="rempart-migrate" id="rempart-restore-migrate" hidden></p>
				<p class="rempart-warn-box"><strong><?php echo esc_html__('Le contenu actuel sera remplacé', 'rempart-backup'); ?></strong> <?php echo esc_html__('par celui de cette sauvegarde. Le site sera en maintenance pendant l\'opération.', 'rempart-backup'); ?></p>
				<p><label><input type="checkbox" id="rempart-restore-confirm"> <?php echo esc_html__('J\'ai compris', 'rempart-backup'); ?></label></p>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-restore-cancel"><?php echo esc_html__('Annuler', 'rempart-backup'); ?></button>
					<button type="button" class="button button-primary" id="rempart-restore-go" disabled><?php echo esc_html__('Restaurer', 'rempart-backup'); ?></button>
				</p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-log-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-log-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-log-title"><?php echo esc_html__('Journal', 'rempart-backup'); ?></h2>
				<div class="rempart-log" id="rempart-log-content" tabindex="0"></div>
				<p class="rempart-modal-actions"><button type="button" class="button button-primary" id="rempart-log-close"><?php echo esc_html__('Fermer', 'rempart-backup'); ?></button></p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-tree-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-tree-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-tree-title"><?php echo esc_html__('Choisir ce qu\'il faut exclure', 'rempart-backup'); ?></h2>
				<ul class="rempart-tree" id="rempart-tree-root"></ul>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-tree-close"><?php echo esc_html__('Fermer', 'rempart-backup'); ?></button>
				</p>
			</div>
		</div>
		<?php
	}
}

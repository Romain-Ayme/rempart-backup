<?php
if (!defined('ABSPATH')) {
	exit;
}

/** The plugin's screens in the WordPress admin. */
class Backup_Guard_Admin {
	private static $notice = null;

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
		add_action('admin_init', array(__CLASS__, 'handle_forms'));
		add_action('admin_notices', array(__CLASS__, 'nag'));
	}

	public static function menu() {
		$icon = BACKUP_GUARD_DIR . '/assets/menu-icon.svg';
		$menu_icon = is_readable($icon) ? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($icon)) : 'dashicons-database';
		add_menu_page('Backup Guard', 'Backup Guard', 'manage_options', 'backup-guard', array(__CLASS__, 'page'), $menu_icon, 76);
	}

	public static function assets($hook) {
		if ($hook !== 'toplevel_page_backup-guard') {
			return;
		}
		wp_enqueue_style('backup-guard-admin', BACKUP_GUARD_URL . '/assets/admin.css', array(), BACKUP_GUARD_VERSION);
		wp_enqueue_script('backup-guard-admin', BACKUP_GUARD_URL . '/assets/admin.js', array(), BACKUP_GUARD_VERSION, true);
		wp_localize_script('backup-guard-admin', 'BackupGuardData', array(
			'ajax'      => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('backup-guard'),
			'login'     => wp_login_url(admin_url('admin.php?page=backup-guard')),
			'chunk'     => BACKUP_GUARD_UPLOAD_CHUNK,
			'active'    => Backup_Guard_Job::is_active(),
			'restore'   => self::restore_info(),
			'i18n'      => self::js_strings(),
		));
	}

	/** Texts the admin script shows, in this site's language (the script falls back to the French text). */
	private static function js_strings() {
		return array(
			'%1$s sauvegarde(s) ajoutée(s), %2$s retirée(s) de la liste.' => __('%1$s sauvegarde(s) ajoutée(s), %2$s retirée(s) de la liste.', 'backup-guard'),
			'Ajouter un emplacement' => __('Ajouter un emplacement', 'backup-guard'),
			'Ajouter un service' => __('Ajouter un service', 'backup-guard'),
			'Ce journal est vide.' => __('Ce journal est vide.', 'backup-guard'),
			'Cette sauvegarde vient de %s. Son adresse sera remplacée par celle de ce site.' => __('Cette sauvegarde vient de %s. Son adresse sera remplacée par celle de ce site.', 'backup-guard'),
			'Chargement…' => __('Chargement…', 'backup-guard'),
			'Commence par' => __('Commence par', 'backup-guard'),
			'Contient' => __('Contient', 'backup-guard'),
			'Copié' => __('Copié', 'backup-guard'),
			'Enregistrer' => __('Enregistrer', 'backup-guard'),
			'Enregistrer et connecter le compte' => __('Enregistrer et connecter le compte', 'backup-guard'),
			'Envoi : %s' => __('Envoi : %s', 'backup-guard'),
			'Envoi…' => __('Envoi…', 'backup-guard'),
			'Erreur inconnue.' => __('Erreur inconnue.', 'backup-guard'),
			'Exclu' => __('Exclu', 'backup-guard'),
			'Exclure' => __('Exclure', 'backup-guard'),
			'Extension' => __('Extension', 'backup-guard'),
			'Finit par' => __('Finit par', 'backup-guard'),
			'Gardez cette page ouverte jusqu\'à la fin.' => __('Gardez cette page ouverte jusqu\'à la fin.', 'backup-guard'),
			'La restauration n\'a pas abouti' => __('La restauration n\'a pas abouti', 'backup-guard'),
			'La tâche n\'a pas abouti' => __('La tâche n\'a pas abouti', 'backup-guard'),
			'Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.' => __('Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.', 'backup-guard'),
			'Modifier l\'emplacement' => __('Modifier l\'emplacement', 'backup-guard'),
			'Modifier le service' => __('Modifier le service', 'backup-guard'),
			'Restauration arrêtée' => __('Restauration arrêtée', 'backup-guard'),
			'Restauration en cours' => __('Restauration en cours', 'backup-guard'),
			'Restauration terminée' => __('Restauration terminée', 'backup-guard'),
			'Retirer' => __('Retirer', 'backup-guard'),
			'Récupération de la sauvegarde' => __('Récupération de la sauvegarde', 'backup-guard'),
			'Réponse illisible du serveur.' => __('Réponse illisible du serveur.', 'backup-guard'),
			'Sauvegarde en cours' => __('Sauvegarde en cours', 'backup-guard'),
			'Sauvegarde terminée' => __('Sauvegarde terminée', 'backup-guard'),
			'Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.' => __('Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.', 'backup-guard'),
			'Supprimer définitivement %s sauvegarde(s) ?' => __('Supprimer définitivement %s sauvegarde(s) ?', 'backup-guard'),
			'Supprimer définitivement cette sauvegarde ?' => __('Supprimer définitivement cette sauvegarde ?', 'backup-guard'),
			'Sélectionnez au moins une sauvegarde.' => __('Sélectionnez au moins une sauvegarde.', 'backup-guard'),
			'Test…' => __('Test…', 'backup-guard'),
			'Tâche arrêtée' => __('Tâche arrêtée', 'backup-guard'),
			'Tâche en cours' => __('Tâche en cours', 'backup-guard'),
			'par exemple %s' => __('par exemple %s', 'backup-guard'),
		);
	}

	private static function restore_info() {
		$restore = Backup_Guard_Restore::current();
		return $restore ? array('url' => $restore['runner_url'], 'key' => $restore['key']) : null;
	}

	/** The reminder shown across the admin when no backup was made for a while. */
	public static function nag() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$nag_time = (int) get_option('backup_guard_nag_time', 0);
		if (!$nag_time) {
			$nag_time = time() - BACKUP_GUARD_NAG_FIRST_OFFSET;
			update_option('backup_guard_nag_time', $nag_time, false);
		}
		$screen = get_current_screen();
		if ($screen && $screen->id === 'toplevel_page_backup-guard') {
			return;
		}
		$last = (int) get_option('backup_guard_last_backup', 0);
		if ((time() - $last) < BACKUP_GUARD_NAG_SECONDS || (time() - $nag_time) < BACKUP_GUARD_NAG_SECONDS) {
			return;
		}
		$url = esc_url(admin_url('admin.php?page=backup-guard'));
		$message = $last
			? sprintf(__('Votre dernière sauvegarde date de %d jours.', 'backup-guard'), floor((time() - $last) / DAY_IN_SECONDS))
			: __('Vous n\'avez encore aucune sauvegarde de ce site.', 'backup-guard');
		$nonce = wp_create_nonce('backup-guard');
		echo '<div class="notice notice-warning is-dismissible" id="backup-guard-nag"><p>' . esc_html($message) . ' <a href="' . $url . '">' . esc_html__('Faire une sauvegarde', 'backup-guard') . '</a></p></div>';
		echo '<script>document.addEventListener("click",function(e){if(e.target.closest&&e.target.closest("#backup-guard-nag .notice-dismiss")){var b=new URLSearchParams({action:"backup_guard_hide_nag",_ajax_nonce:' . wp_json_encode($nonce) . '});fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",credentials:"same-origin",body:b})}});</script>';
	}

	/* ----- Submitted forms ----- */

	public static function handle_forms() {
		backup_guard_self_heal();
		if (!isset($_GET['page']) || $_GET['page'] !== 'backup-guard' || !current_user_can('manage_options')) {
			return;
		}

		// The cloud account's page sends the person back here with a code.
		if (isset($_GET['state'], $_GET['code'])) {
			self::finish_oauth();
			return;
		}
		if (isset($_GET['state'], $_GET['error'])) {
			self::redirect_with('location', __('L\'autorisation a été annulée.', 'backup-guard'), 'error');
		}

		if (empty($_POST['backup_guard_form'])) {
			if (isset($_GET['backup_guard_msg'])) {
				self::$notice = array(sanitize_text_field(wp_unslash($_GET['backup_guard_msg'])), isset($_GET['backup_guard_type']) ? sanitize_key($_GET['backup_guard_type']) : 'success');
			}
			return;
		}
		check_admin_referer('backup_guard_form');
		$form = sanitize_key($_POST['backup_guard_form']);
		try {
			switch ($form) {
				case 'settings':
					self::save_settings();
					self::redirect_with('settings', __('Réglages enregistrés.', 'backup-guard'));
					break;
				case 'location':
					self::save_location();
					break;
				case 'delete_location':
					Backup_Guard_Locations::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('location', __('Emplacement supprimé.', 'backup-guard'));
					break;
				case 'channel':
					$id = !empty($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;
					$channel = Backup_Guard_Notifications::save(wp_unslash($_POST), $id);
					self::redirect_with('notifications', sprintf(__('Canal « %s » enregistré. Utilisez « Envoyer un test » pour vérifier qu\'il fonctionne.', 'backup-guard'), $channel['name']));
					break;
				case 'delete_channel':
					Backup_Guard_Notifications::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('notifications', __('Canal supprimé.', 'backup-guard'));
					break;
				case 'connect':
					$location = Backup_Guard_Locations::get(sanitize_text_field(wp_unslash($_POST['id'])));
					if ($location) {
						wp_redirect(Backup_Guard_Locations::oauth_start($location));
						exit;
					}
					break;
			}
		} catch (Exception $e) {
			self::$notice = array($e->getMessage(), 'error');
		}
	}

	private static function redirect_with($tab, $message, $type = 'success') {
		wp_safe_redirect(add_query_arg(array('page' => 'backup-guard', 'backup_guard_msg' => rawurlencode($message), 'backup_guard_type' => $type), admin_url('admin.php')) . '#' . $tab);
		exit;
	}

	private static function finish_oauth() {
		try {
			$state = sanitize_text_field(wp_unslash($_GET['state']));
			$code = sanitize_text_field(wp_unslash($_GET['code']));
			$location = Backup_Guard_Locations::oauth_finish($state, $code, wp_unslash($_GET));
			self::redirect_with('location', sprintf(__('« %s » est connecté.', 'backup-guard'), $location['name']));
		} catch (Exception $e) {
			self::redirect_with('location', $e->getMessage(), 'error');
		}
	}

	private static function save_settings() {
		$settings = backup_guard_settings();
		$settings['backup_files'] = !empty($_POST['backup_files']) ? 1 : 0;
		$settings['backup_db'] = !empty($_POST['backup_db']) ? 1 : 0;
		$settings['backup_scope'] = backup_guard_valid_scope(isset($_POST['backup_scope']) ? sanitize_key($_POST['backup_scope']) : 'all');
		$settings['auto_scope'] = backup_guard_valid_scope(isset($_POST['auto_scope']) ? sanitize_key($_POST['auto_scope']) : 'all');
		$settings['location'] = isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
		$settings['pre_update_backup'] = !empty($_POST['pre_update_backup']) ? 1 : 0;
		$settings['auto_update_backup'] = !empty($_POST['auto_update_backup']) ? 1 : 0;

		$schedule = isset($_POST['schedule']) ? sanitize_key($_POST['schedule']) : '';
		$settings['schedule'] = ($schedule === 'custom' || isset(Backup_Guard_Schedule::intervals()[$schedule])) ? $schedule : '';
		$rotation = isset($_POST['rotation']) ? sanitize_key($_POST['rotation']) : '';
		$settings['rotation'] = $rotation === 'custom' ? max(1, (int) ($_POST['rotation_custom'] ?? 1)) : (int) $rotation;
		$settings['auto_files'] = !empty($_POST['auto_files']) ? 1 : 0;
		$settings['auto_db'] = !empty($_POST['auto_db']) ? 1 : 0;
		$settings['auto_location'] = isset($_POST['auto_location']) ? sanitize_text_field(wp_unslash($_POST['auto_location'])) : '';
		if ($settings['schedule'] !== '' && !$settings['auto_files'] && !$settings['auto_db']) {
			throw new RuntimeException(__('Pour les sauvegardes automatiques, choisissez les fichiers, la base de données ou les deux.', 'backup-guard'));
		}

		$settings['additional'] = isset($_POST['additional']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['additional'])) : array();
		$settings['debug'] = !empty($_POST['debug']) ? 1 : 0;
		$settings['gzip_level'] = min(9, max(1, (int) ($_POST['gzip_level'] ?? 9)));
		update_option('backup_guard_settings', $settings, false);

		$excludes = backup_guard_excludes();
		$excludes['db'] = isset($_POST['exclude_db']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['exclude_db'])) : array();
		update_option('backup_guard_excludes', $excludes, false);

		Backup_Guard_Schedule::sync();
	}

	private static function save_location() {
		$id = !empty($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;
		$values = wp_unslash($_POST);
		$location = Backup_Guard_Locations::save($values, $id);

		$protocols = Backup_Guard_Locations::protocols();
		if (!empty($protocols[$location['protocol']]['oauth'])) {
			$secrets = Backup_Guard_Locations::config_of($location);
			if (empty($secrets['refresh_token'])) {
				wp_redirect(Backup_Guard_Locations::oauth_start($location));
				exit;
			}
			self::redirect_with('location', __('Emplacement enregistré.', 'backup-guard'));
		}
		try {
			Backup_Guard_Storage::make($location)->test();
		} catch (Exception $e) {
			self::redirect_with('location', sprintf(__('Emplacement enregistré, mais la connexion a échoué : %s', 'backup-guard'), $e->getMessage()), 'error');
		}
		self::redirect_with('location', __('Emplacement enregistré. La connexion fonctionne.', 'backup-guard'));
	}

	/* ----- The page ----- */

	public static function page() {
		backup_guard_prepare_folders();
		$settings = backup_guard_settings();
		$locations = Backup_Guard_Locations::all();
		$protocols = Backup_Guard_Locations::protocols();
		?>
		<div class="wrap backup-guard">
			<header class="backup-guard-top">
				<img class="backup-guard-logo" src="<?php echo esc_url(BACKUP_GUARD_URL . '/assets/icon.svg'); ?>" alt="" width="46" height="46">
				<div>
					<h1><?php echo esc_html__('Backup Guard', 'backup-guard'); ?></h1>
					<p><?php echo esc_html__('Sauvegarde, restauration, migration et clonage. Tout est inclus, sans licence.', 'backup-guard'); ?></p>
				</div>
			</header>

			<?php if (self::$notice) : ?>
				<div class="notice notice-<?php echo self::$notice[1] === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html(self::$notice[0]); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper backup-guard-tabs">
				<a class="nav-tab" href="#dashboard"><?php echo esc_html__('Tableau de bord', 'backup-guard'); ?></a>
				<a class="nav-tab" href="#settings"><?php echo esc_html__('Réglages', 'backup-guard'); ?></a>
				<a class="nav-tab" href="#location"><?php echo esc_html__('Emplacements', 'backup-guard'); ?></a>
				<a class="nav-tab" href="#history"><?php echo esc_html__('Historique', 'backup-guard'); ?></a>
				<a class="nav-tab" href="#notifications"><?php echo esc_html__('Notifications', 'backup-guard'); ?></a>
				<a class="nav-tab" href="#help"><?php echo esc_html__('Aide', 'backup-guard'); ?></a>
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
		foreach (backup_guard_scopes() as $key => $scope) {
			printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($selected, $key, false), esc_html($scope['label']));
		}
	}

	private static function location_options($selected, $locations, $protocols) {
		echo '<option value="">' . esc_html__('Dossier local (par défaut)', 'backup-guard') . '</option>';
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
		$last_backup = (int) get_option('backup_guard_last_backup', 0);
		$last_restore = (int) get_option('backup_guard_last_restore', 0);
		$next = wp_next_scheduled('backup_guard_auto_backup');
		$date_format = _x('j F Y à H:i', 'date and time format', 'backup-guard');
		?>
		<section id="tab-dashboard" class="backup-guard-tab">
			<div class="backup-guard-facts">
				<div class="backup-guard-fact">
					<span><?php echo esc_html__('Dernière sauvegarde', 'backup-guard'); ?></span>
					<strong><?php echo $last_backup ? esc_html(wp_date($date_format, $last_backup)) : esc_html__('Aucune', 'backup-guard'); ?></strong>
					<?php if ($last_backup) : ?><button type="button" class="button-link backup-guard-show-log" data-log="backup"><?php echo esc_html__('Voir le journal', 'backup-guard'); ?></button><?php endif; ?>
				</div>
				<div class="backup-guard-fact">
					<span><?php echo esc_html__('Dernière restauration', 'backup-guard'); ?></span>
					<strong><?php echo $last_restore ? esc_html(wp_date($date_format, $last_restore)) : esc_html__('Aucune', 'backup-guard'); ?></strong>
					<?php if ($last_restore) : ?><button type="button" class="button-link backup-guard-show-log" data-log="restore"><?php echo esc_html__('Voir le journal', 'backup-guard'); ?></button><?php endif; ?>
				</div>
				<div class="backup-guard-fact">
					<span><?php echo esc_html__('Prochaine sauvegarde automatique', 'backup-guard'); ?></span>
					<strong><?php
					if ($next) {
						echo esc_html(wp_date($date_format, $next));
						if (time() - $next > 300) {
							echo ' (en retard)';
						}
					} elseif ($settings['schedule'] === 'custom') {
						echo __('Tâche cron personnalisée', 'backup-guard');
					} else {
						echo __('Rien de prévu', 'backup-guard');
					}
					?></strong>
				</div>
			</div>

			<?php $ls = Backup_Guard_Litespeed::status(); ?>
			<?php if ($ls[0] === 'warning') : ?>
				<div class="notice notice-warning inline"><p><strong><?php echo esc_html__('Serveur LiteSpeed détecté.', 'backup-guard'); ?></strong> <?php echo esc_html__('Si une sauvegarde se bloque sans erreur,', 'backup-guard'); ?> <a href="#help"><?php echo esc_html__('ajoutez la règle LiteSpeed dans l\'onglet Aide', 'backup-guard'); ?></a>.</p></div>
			<?php endif; ?>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Créer une sauvegarde', 'backup-guard'); ?></h2>
				<form id="backup-guard-form">
					<p class="backup-guard-checks">
						<label><input type="checkbox" name="files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'backup-guard'); ?></label>
						<label><input type="checkbox" name="db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> <?php echo esc_html__('Base de données', 'backup-guard'); ?></label>
					</p>
					<p>
						<label for="backup-guard-scope" class="backup-guard-label"><?php echo esc_html__('Fichiers à garder', 'backup-guard'); ?></label>
						<select name="scope" id="backup-guard-scope"><?php self::scope_options($settings['backup_scope']); ?></select>
						<span class="description"><?php echo esc_html__('Sans le cœur de WordPress, la sauvegarde se restaure sur un WordPress déjà installé, de la même version.', 'backup-guard'); ?></span>
					</p>
					<p>
						<label for="backup-guard-location" class="backup-guard-label"><?php echo esc_html__('Où la garder', 'backup-guard'); ?></label>
						<select name="location" id="backup-guard-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</p>
					<details class="backup-guard-more">
						<summary><?php echo esc_html__('Options supplémentaires', 'backup-guard'); ?></summary>
						<label for="backup-guard-note" class="backup-guard-label"><?php echo esc_html__('Note (facultatif)', 'backup-guard'); ?></label>
						<textarea name="note" id="backup-guard-note" maxlength="<?php echo (int) BACKUP_GUARD_NOTE_MAX; ?>" rows="2" placeholder="<?php echo esc_attr__('80 caractères au maximum', 'backup-guard'); ?>"></textarea>
					</details>
					<p>
						<button type="submit" class="button button-primary button-hero backup-guard-go"><?php echo esc_html__('Créer la sauvegarde', 'backup-guard'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	private static function tab_settings($settings, $locations, $protocols) {
		global $wpdb;
		$excludes = backup_guard_excludes();
		$rotation = (int) $settings['rotation'];
		?>
		<section id="tab-settings" class="backup-guard-tab" hidden>
			<form method="post">
				<?php wp_nonce_field('backup_guard_form'); ?>
				<input type="hidden" name="backup_guard_form" value="settings">

				<div class="backup-guard-card">
					<h2><?php echo esc_html__('Sauvegardes', 'backup-guard'); ?></h2>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Par défaut, sauvegarder', 'backup-guard'); ?></div>
						<div class="backup-guard-checks">
							<label><input type="checkbox" name="backup_files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'backup-guard'); ?></label>
							<label><input type="checkbox" name="backup_db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> <?php echo esc_html__('Base de données', 'backup-guard'); ?></label>
						</div>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-default-scope"><?php echo esc_html__('Fichiers à garder par défaut', 'backup-guard'); ?></label>
						<select name="backup_scope" id="backup-guard-default-scope"><?php self::scope_options($settings['backup_scope']); ?></select>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-default-location"><?php echo esc_html__('Emplacement par défaut', 'backup-guard'); ?></label>
						<select name="location" id="backup-guard-default-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Avant les mises à jour de WordPress', 'backup-guard'); ?></div>
						<div class="backup-guard-checks backup-guard-stack">
							<label><input type="checkbox" name="pre_update_backup" value="1" <?php checked(!empty($settings['pre_update_backup'])); ?>> <?php echo esc_html__('Sauvegarder avant une mise à jour manuelle', 'backup-guard'); ?></label>
							<label><input type="checkbox" name="auto_update_backup" value="1" <?php checked(!empty($settings['auto_update_backup'])); ?>> <?php echo esc_html__('Retenir les mises à jour automatiques jusqu\'à la fin d\'une sauvegarde', 'backup-guard'); ?></label>
						</div>
					</div>
				</div>

				<div class="backup-guard-card">
					<h2><?php echo esc_html__('Sauvegardes automatiques', 'backup-guard'); ?></h2>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-schedule"><?php echo esc_html__('Fréquence', 'backup-guard'); ?></label>
						<select name="schedule" id="backup-guard-schedule">
							<option value=""><?php echo esc_html__('Pas de sauvegarde automatique', 'backup-guard'); ?></option>
							<?php foreach (Backup_Guard_Schedule::intervals() as $key => $interval) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($settings['schedule'], $key); ?>><?php echo esc_html($interval['display']); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php selected($settings['schedule'], 'custom'); ?>><?php echo esc_html__('Personnalisée (tâche cron du serveur)', 'backup-guard'); ?></option>
						</select>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Comment ça marche', 'backup-guard'); ?></div>
						<div class="backup-guard-cron-note">
							<p data-for="none"><?php echo esc_html__('Aucune sauvegarde automatique.', 'backup-guard'); ?></p>
							<p data-for="auto"><strong><?php echo esc_html__('Automatique :', 'backup-guard'); ?></strong> <?php echo esc_html__('rien d\'autre à configurer. Elle part à la première visite du site après l\'heure prévue. Pour une heure précise, choisissez « Personnalisée ».', 'backup-guard'); ?></p>
							<p data-for="custom"><?php echo esc_html__('Ajoutez la commande ci-dessous aux tâches cron de votre hébergement.', 'backup-guard'); ?></p>
							<?php
							$cron_status = self::cron_status($settings);
							if ($cron_status) :
								?>
								<p class="backup-guard-cron-status backup-guard-cron-<?php echo esc_attr($cron_status[0]); ?>"><?php echo esc_html($cron_status[1]); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<div class="backup-guard-row" id="backup-guard-cron-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Commande à planifier', 'backup-guard'); ?></div>
						<div>
							<code class="backup-guard-code" id="backup-guard-cron-command">wget -q -O /dev/null "<?php echo esc_html(Backup_Guard_Schedule::cron_url()); ?>"</code>
							<button type="button" class="button backup-guard-copy" data-copy="#backup-guard-cron-command"><?php echo esc_html__('Copier', 'backup-guard'); ?></button>						</div>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-rotation"><?php echo esc_html__('Sauvegardes automatiques à conserver', 'backup-guard'); ?></label>
						<div class="backup-guard-inline">
							<select name="rotation" id="backup-guard-rotation">
								<option value="0" <?php selected($rotation, 0); ?>><?php echo esc_html__('Toutes', 'backup-guard'); ?></option>
								<?php for ($i = 1; $i <= 10; $i++) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected($rotation, $i); ?>><?php echo (int) $i; ?></option>
								<?php endfor; ?>
								<option value="custom" <?php selected($rotation > 10); ?>><?php echo esc_html__('Autre nombre', 'backup-guard'); ?></option>
							</select>
							<input type="number" min="1" name="rotation_custom" id="backup-guard-rotation-custom" value="<?php echo $rotation > 10 ? (int) $rotation : ''; ?>">
							<span id="backup-guard-rotation-warning" class="backup-guard-warn"><?php echo esc_html__('Plus de 30 sauvegardes peuvent occuper beaucoup de place.', 'backup-guard'); ?></span>
						</div>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Contenu', 'backup-guard'); ?></div>
						<div class="backup-guard-checks">
							<label><input type="checkbox" name="auto_files" value="1" <?php checked(!empty($settings['auto_files'])); ?>> <?php echo esc_html__('Fichiers du site', 'backup-guard'); ?></label>
							<label><input type="checkbox" name="auto_db" value="1" <?php checked(!empty($settings['auto_db'])); ?>> <?php echo esc_html__('Base de données', 'backup-guard'); ?></label>
						</div>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-auto-scope"><?php echo esc_html__('Fichiers à garder', 'backup-guard'); ?></label>
						<select name="auto_scope" id="backup-guard-auto-scope"><?php self::scope_options($settings['auto_scope']); ?></select>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-auto-location"><?php echo esc_html__('Emplacement', 'backup-guard'); ?></label>
						<select name="auto_location" id="backup-guard-auto-location"><?php self::location_options($settings['auto_location'], $locations, $protocols); ?></select>
					</div>
				</div>

				<div class="backup-guard-card">
					<h2><?php echo esc_html__('Exclusions', 'backup-guard'); ?></h2>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Tables de la base à exclure', 'backup-guard'); ?></div>
						<details class="backup-guard-more">
							<summary><?php echo esc_html__('Choisir dans la liste', 'backup-guard'); ?></summary>
							<div class="backup-guard-scroll">
								<?php foreach ((array) $wpdb->get_col('SHOW TABLES') as $table) : ?>
									<label class="backup-guard-line"><input type="checkbox" name="exclude_db[]" value="<?php echo esc_attr($table); ?>" <?php checked(in_array($table, $excludes['db'], true)); ?>> <?php echo esc_html($table); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Fichiers et dossiers à exclure', 'backup-guard'); ?></div>
						<div>
							<div class="backup-guard-rule-box">
								<strong><?php echo esc_html__('Par nom', 'backup-guard'); ?></strong>
								<div class="backup-guard-inline">
									<select id="backup-guard-rule-type">
										<option value="extension"><?php echo esc_html__('Avec l\'extension', 'backup-guard'); ?></option>
										<option value="beginning"><?php echo esc_html__('Dont le nom commence par', 'backup-guard'); ?></option>
										<option value="end"><?php echo esc_html__('Dont le nom finit par', 'backup-guard'); ?></option>
										<option value="anywhere"><?php echo esc_html__('Dont le chemin contient', 'backup-guard'); ?></option>
									</select>
									<input type="text" id="backup-guard-rule-pattern" placeholder="<?php echo esc_attr__('par exemple zip', 'backup-guard'); ?>">
									<button type="button" class="button" id="backup-guard-rule-add"><?php echo esc_html__('Ajouter', 'backup-guard'); ?></button>
								</div>
								<ul class="backup-guard-rules" id="backup-guard-rules-pattern">
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
							<div class="backup-guard-rule-box">
								<strong><?php echo esc_html__('Dossiers ou fichiers précis', 'backup-guard'); ?></strong>
								<button type="button" class="button" id="backup-guard-tree-open"><?php echo esc_html__('Parcourir le site…', 'backup-guard'); ?></button>
								<ul class="backup-guard-rules" id="backup-guard-rules-exact">
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

				<div class="backup-guard-card">
					<h2><?php echo esc_html__('Autres réglages', 'backup-guard'); ?></h2>
					<?php
					$extra = self::additional_entries();
					if ($extra) :
						?>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Autres éléments à la racine du site', 'backup-guard'); ?></div>
						<details class="backup-guard-more">
							<summary><?php echo esc_html__('Choisir ceux à inclure', 'backup-guard'); ?></summary>
							<div class="backup-guard-scroll">
								<?php foreach ($extra as $item) : ?>
									<label class="backup-guard-line"><input type="checkbox" name="additional[]" value="<?php echo esc_attr($item); ?>" <?php checked(in_array($item, (array) $settings['additional'], true)); ?>> <?php echo esc_html($item); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<?php endif; ?>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Compression', 'backup-guard'); ?></div>
						<label><?php echo esc_html__('Niveau', 'backup-guard'); ?> <select name="gzip_level">
							<?php for ($level = 1; $level <= 9; $level++) : ?>
								<option value="<?php echo $level; ?>" <?php selected((int) $settings['gzip_level'], $level); ?>><?php echo $level; ?><?php echo $level === 9 ? ' ' . __('(archive la plus petite, la plus lente)', 'backup-guard') : ($level === 1 ? ' ' . __('(la plus rapide, archive plus grosse)', 'backup-guard') : ''); ?></option>
							<?php endfor; ?>
						</select></label>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Mode débogage', 'backup-guard'); ?></div>
						<label><input type="checkbox" name="debug" value="1" <?php checked(!empty($settings['debug'])); ?>> <?php echo esc_html__('Écrire plus de détails dans le journal (ne le laissez pas activé)', 'backup-guard'); ?></label>
					</div>
				</div>

				<p><button type="submit" class="button button-primary button-large"><?php echo esc_html__('Enregistrer les réglages', 'backup-guard'); ?></button></p>
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
			return array('warning', __('WP-Cron est désactivé sur ce site : rien ne partira seul. Choisissez « Personnalisée ».', 'backup-guard'));
		}
		$next = wp_next_scheduled('backup_guard_auto_backup');
		if (!$next) {
			return array('warning', __('Cliquez sur « Enregistrer les réglages » pour activer la planification.', 'backup-guard'));
		}
		if (time() - $next > 300) {
			return array('warning', sprintf(__('En retard de %s : en attente d\'une visite du site.', 'backup-guard'), human_time_diff($next)));
		}
		return array('ok', sprintf(__('Prochaine sauvegarde automatique : %s.', 'backup-guard'), wp_date(_x('j F Y à H:i', 'date and time format', 'backup-guard'), $next)));
	}

	private static function rule_item($type, $key, $text) {
		printf(
			'<li data-type="%s" data-key="%s"><span>%s</span> <button type="button" class="button-link backup-guard-rule-del" aria-label="Retirer"><span class="dashicons dashicons-no-alt"></span></button></li>',
			esc_attr($type),
			esc_attr($key),
			esc_html($text)
		);
	}

	private static function additional_entries() {
		$items = @scandir(ABSPATH);
		$out = array();
		foreach ((array) $items as $item) {
			if ($item !== '.' && $item !== '..' && !in_array($item, backup_guard_core_entries(), true)) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function tab_locations($locations, $protocols) {
		// Space taken by our backups in each place. The local folder is keyed by ''.
		$stored_by_location = array();
		foreach (Backup_Guard_Backups::all() as $backup) {
			$where = (string) ($backup['location'] ?? '');
			$stored_by_location[$where] = ($stored_by_location[$where] ?? 0) + (float) ($backup['size'] ?? 0);
		}
		$local_stored = $stored_by_location[''] ?? 0;
		$local_text = backup_guard_format_size($local_stored);
		?>
		<section id="tab-location" class="backup-guard-tab" hidden>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Emplacements de sauvegarde', 'backup-guard'); ?></h2>
				<table class="widefat striped backup-guard-table">
					<thead><tr><th><?php echo esc_html__('Nom', 'backup-guard'); ?></th><th><?php echo esc_html__('Type', 'backup-guard'); ?></th><th><?php echo esc_html__('Serveur', 'backup-guard'); ?></th><th><?php echo esc_html__('Dossier', 'backup-guard'); ?></th><th><?php echo esc_html__('Espace utilisé', 'backup-guard'); ?></th><th><?php echo esc_html__('Actions', 'backup-guard'); ?></th></tr></thead>
					<tbody>
						<tr>
							<td><?php echo esc_html__('Dossier local', 'backup-guard'); ?></td><td>-</td><td>-</td>
							<td><code><?php echo esc_html(backup_guard_clean_path((string) backup_guard_folder('backups'))); ?></code></td>
							<td><?php echo esc_html($local_text); ?></td><td>-</td>
						</tr>
						<?php
						foreach ($locations as $id => $location) :
							$config = (array) $location['config'];
							$is_oauth = !empty($protocols[$location['protocol']]['oauth']);
							$connected = false;
							if ($is_oauth) {
								try {
									$connected = !empty(Backup_Guard_Locations::config_of($location)['refresh_token']);
								} catch (Exception $e) {
									$connected = false;
								}
							}
							// The provider's own usage when it reports one (OAuth services), otherwise what our backups take up there.
							$quota = $location['quota'] ?? null;
							$stored = $stored_by_location[(string) $id] ?? 0;
							if ($quota) {
								$quota_text = backup_guard_format_size($quota['used']) . (!empty($quota['total']) ? ' / ' . backup_guard_format_size($quota['total']) : '');
							} else {
								$quota_text = backup_guard_format_size($stored);
							}
							?>
							<tr>
								<td><strong><?php echo esc_html($location['name']); ?></strong></td>
								<td><?php echo esc_html($protocols[$location['protocol']]['label'] ?? $location['protocol']); ?></td>
								<td><?php echo esc_html($config['host'] ?? ($config['url'] ?? ($config['endpoint'] ?? '-'))); ?></td>
								<td><?php echo esc_html(($config['bucket'] ?? '') . (isset($config['bucket']) ? '/' : '') . ($config['folder'] ?? '')); ?></td>
								<td>
									<?php if ($is_oauth) : ?>
										<button type="button" class="button-link backup-guard-quota" data-id="<?php echo esc_attr($id); ?>" title="<?php echo esc_attr__('Actualiser', 'backup-guard'); ?>"><?php echo esc_html($quota_text); ?></button>
									<?php else : ?>
										<?php echo esc_html($quota_text); ?>
									<?php endif; ?>
								</td>
								<td class="backup-guard-actions">
									<?php if ($is_oauth && !$connected) : ?>
										<form method="post"><?php wp_nonce_field('backup_guard_form'); ?><input type="hidden" name="backup_guard_form" value="connect"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button button-primary"><?php echo esc_html__('Connecter le compte', 'backup-guard'); ?></button></form>
									<?php else : ?>
										<button type="button" class="button backup-guard-test" data-id="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Tester', 'backup-guard'); ?></button>
									<?php endif; ?>
									<button type="button" class="button backup-guard-edit" data-location="<?php echo esc_attr(wp_json_encode(array('id' => $location['id'], 'name' => $location['name'], 'protocol' => $location['protocol']) + $config)); ?>"><?php echo esc_html__('Modifier', 'backup-guard'); ?></button>
									<form method="post" class="backup-guard-confirm" data-confirm="<?php echo esc_attr__('Supprimer cet emplacement ? Les sauvegardes déjà envoyées y restent.', 'backup-guard'); ?>"><?php wp_nonce_field('backup_guard_form'); ?><input type="hidden" name="backup_guard_form" value="delete_location"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button backup-guard-danger"><?php echo esc_html__('Supprimer', 'backup-guard'); ?></button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="backup-guard-add-location"><?php echo esc_html__('Ajouter un emplacement', 'backup-guard'); ?></button></p>
			</div>

			<div class="backup-guard-card" id="backup-guard-location-form-card" hidden>
				<h2 id="backup-guard-location-form-title"><?php echo esc_html__('Ajouter un emplacement', 'backup-guard'); ?></h2>
				<form method="post" id="backup-guard-location-form" autocomplete="off">
					<?php wp_nonce_field('backup_guard_form'); ?>
					<input type="hidden" name="backup_guard_form" value="location">
					<input type="hidden" name="id" value="">
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-loc-name"><?php echo esc_html__('Nom', 'backup-guard'); ?></label>
						<input type="text" name="name" id="backup-guard-loc-name" class="regular-text" required>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-loc-protocol"><?php echo esc_html__('Type', 'backup-guard'); ?></label>
						<select name="protocol" id="backup-guard-loc-protocol">
							<?php foreach ($protocols as $key => $protocol) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($protocol['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php self::location_fields($protocols); ?>
					<div class="backup-guard-oauth-help" hidden>
						<p><strong><?php echo esc_html__('Adresse de retour à déclarer dans votre application :', 'backup-guard'); ?></strong><br><code class="backup-guard-code" id="backup-guard-redirect"><?php echo esc_html(admin_url('admin.php?page=backup-guard')); ?></code> <button type="button" class="button backup-guard-copy" data-copy="#backup-guard-redirect"><?php echo esc_html__('Copier', 'backup-guard'); ?></button></p>
						<p class="description"><?php echo esc_html__('Ce service demande votre propre application : l\'onglet Aide explique comment la créer. Après l\'enregistrement, vous serez envoyé sur sa page pour accepter l\'accès.', 'backup-guard'); ?></p>
					</div>
					<p>
						<button type="submit" class="button button-primary" id="backup-guard-loc-submit"><?php echo esc_html__('Enregistrer', 'backup-guard'); ?></button>
						<button type="button" class="button" id="backup-guard-loc-cancel"><?php echo esc_html__('Annuler', 'backup-guard'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	/** One set of fields per storage type; the script shows those of the chosen type. */
	private static function location_fields($protocols) {
		foreach ($protocols as $key => $protocol) {
			echo '<div class="backup-guard-loc-fields" data-protocol="' . esc_attr($key) . '" data-oauth="' . (!empty($protocol['oauth']) ? '1' : '0') . '" hidden>';
			foreach ($protocol['fields'] as $name => $field) {
				$type = $field['type'] ?? 'text';
				$input_id = 'backup-guard-' . $key . '-' . $name;
				$required = !empty($field['required']) && empty($field['secret']);
				$default = $field['default'] ?? '';
				if ($name === 'port' && !empty($protocol['default_port'])) {
					$default = $protocol['default_port'];
				}
				echo '<div class="backup-guard-row"><label class="backup-guard-row-label" for="' . esc_attr($input_id) . '">' . esc_html($field['label']) . '</label><div>';
				$common = 'name="' . esc_attr($name) . '" id="' . esc_attr($input_id) . '" data-default="' . esc_attr($default) . '"' . ($required ? ' data-required="1"' : '') . (!empty($field['secret']) ? ' data-secret="1"' : '');
				if ($type === 'select') {
					$presets = ($key === 's3' && $name === 'service') ? ' data-presets="' . esc_attr(wp_json_encode(Backup_Guard_Locations::s3_presets())) . '"' : '';
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
					echo '<p class="description backup-guard-secret-note" hidden>' . esc_html__('Laissez vide pour garder la valeur enregistrée.', 'backup-guard') . '</p>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}
	}

	private static function tab_history($locations, $protocols) {
		Backup_Guard_Backups::sync_local();
		// 'all', 'local' (the local folder, stored as location '') or the id of a location.
		$filter = isset($_GET['history_location']) ? sanitize_text_field(wp_unslash($_GET['history_location'])) : 'all';
		$all = array_values(array_filter(Backup_Guard_Backups::all(), function ($info) use ($filter) {
			if ($filter === 'all') {
				return true;
			}
			$where = (string) ($info['location'] ?? '');
			return $filter === 'local' ? $where === '' : $where === $filter;
		}));
		$filter_query = $filter === 'all' ? '' : '&history_location=' . rawurlencode($filter);
		$total = count($all);
		$pages = max(1, (int) ceil($total / BACKUP_GUARD_HISTORY_PER_PAGE));
		$page = isset($_GET['history_page']) ? max(1, min($pages, (int) $_GET['history_page'])) : 1;
		$rows = array_slice($all, ($page - 1) * BACKUP_GUARD_HISTORY_PER_PAGE, BACKUP_GUARD_HISTORY_PER_PAGE);
		?>
		<section id="tab-history" class="backup-guard-tab" hidden>
			<div class="backup-guard-card">
				<div class="backup-guard-history-tools">
					<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>#history" class="backup-guard-inline">
						<input type="hidden" name="page" value="backup-guard">
						<label><?php echo esc_html__('Afficher :', 'backup-guard'); ?>
							<select name="history_location">
								<option value="all" <?php selected($filter, 'all'); ?>><?php echo esc_html__('Tous les emplacements', 'backup-guard'); ?></option>
								<option value="local" <?php selected($filter, 'local'); ?>><?php echo esc_html__('Dossier local', 'backup-guard'); ?></option>
								<?php foreach ($locations as $id => $location) : ?>
									<option value="<?php echo esc_attr($id); ?>" <?php selected($filter, (string) $id); ?>><?php echo esc_html($location['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="submit" class="button"><?php echo esc_html__('Filtrer', 'backup-guard'); ?></button>
					</form>
					<div class="backup-guard-inline">
						<label><?php echo esc_html__('Synchroniser depuis :', 'backup-guard'); ?>
						<select id="backup-guard-sync-location">
							<option value="local"><?php echo esc_html__('Dossier local', 'backup-guard'); ?></option>
							<?php foreach ($locations as $id => $location) : ?>
								<option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($location['name']); ?></option>
							<?php endforeach; ?>
						</select>
						</label>
						<button type="button" class="button" id="backup-guard-sync"><?php echo esc_html__('Synchroniser', 'backup-guard'); ?></button>
						<button type="button" class="button" id="backup-guard-upload-open"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Importer une sauvegarde', 'backup-guard'); ?></button>
						<input type="file" id="backup-guard-upload-file" accept=".gz" hidden>
					</div>
					<div class="backup-guard-inline">
						<button type="button" class="button" id="backup-guard-select-all"><?php echo esc_html__('Tout sélectionner', 'backup-guard'); ?></button>
						<button type="button" class="button backup-guard-danger" id="backup-guard-delete-selected"><?php echo esc_html__('Supprimer', 'backup-guard'); ?></button>
					</div>
				</div>
				<div id="backup-guard-upload-progress" hidden><div class="backup-guard-bar"><i></i></div><span></span></div>

				<table class="widefat striped backup-guard-table" id="backup-guard-history-table">
					<thead><tr><th class="check-column"></th><th><?php echo esc_html__('Date', 'backup-guard'); ?></th><th><?php echo esc_html__('Emplacement', 'backup-guard'); ?></th><th><?php echo esc_html__('Taille', 'backup-guard'); ?></th><th><?php echo esc_html__('Contenu', 'backup-guard'); ?></th><th><?php echo esc_html__('Note', 'backup-guard'); ?></th><th><?php echo esc_html__('Actions', 'backup-guard'); ?></th></tr></thead>
					<tbody>
						<?php if (!$rows) : ?>
							<tr><td colspan="7" class="backup-guard-empty"><?php echo $filter === 'all' ? esc_html__('Aucune sauvegarde pour le moment.', 'backup-guard') : esc_html__('Aucune sauvegarde à cet emplacement.', 'backup-guard'); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ($rows as $info) :
							$location = !empty($info['location']) ? ($locations[$info['location']] ?? null) : null;
							$location_label = empty($info['location']) ? __('Local', 'backup-guard') : ($location ? $location['name'] : __('Emplacement supprimé', 'backup-guard'));
							$has_log = file_exists(BACKUP_GUARD_ROOT . 'logs/' . $info['name'] . '.log.php');
							$content = array();
							if (!empty($info['backup_dir'])) {
								$content[] = __('Fichiers', 'backup-guard');
							}
							if (!empty($info['backup_db'])) {
								$content[] = __('Base de données', 'backup-guard');
							}
							$note = $info['note'] ?? ($info['backup_note'] ?? '');
							$download = wp_nonce_url(admin_url('admin-ajax.php?action=backup_guard_download&name=' . rawurlencode($info['name'])), 'backup-guard');
							$unreachable = !empty($info['location']) && !$location;
							?>
							<tr>
								<td><input type="checkbox" class="backup-guard-pick" value="<?php echo esc_attr($info['name']); ?>"></td>
								<td>
									<?php echo esc_html(wp_date('j M Y H:i', (int) $info['btime'])); ?>
									<?php if (!empty($info['auto_backup'])) : ?><span class="backup-guard-tag"><?php echo esc_html__('Auto', 'backup-guard'); ?></span><?php endif; ?>
								</td>
								<td><?php echo esc_html($location_label); ?></td>
								<td><?php echo esc_html(backup_guard_format_size($info['size'] ?? 0)); ?></td>
								<td><?php echo esc_html(implode(' + ', $content)); ?></td>
								<td><?php echo esc_html($note); ?></td>
								<td class="backup-guard-actions">
									<button type="button" class="button button-primary backup-guard-restore" <?php disabled($unreachable); ?>
										data-name="<?php echo esc_attr($info['name']); ?>"
										data-files="<?php echo !empty($info['backup_dir']) ? '1' : '0'; ?>"
										data-db="<?php echo !empty($info['backup_db']) ? '1' : '0'; ?>"
										data-source="<?php echo esc_attr($info['site_url'] ?? ($info['backup_site_url'] ?? '')); ?>"><?php echo esc_html__('Restaurer', 'backup-guard'); ?></button>
									<?php if (!$unreachable) : ?><a class="button" href="<?php echo esc_url($download); ?>"><?php echo esc_html__('Télécharger', 'backup-guard'); ?></a><?php endif; ?>
									<?php if ($has_log) : ?><button type="button" class="button backup-guard-show-log" data-log="<?php echo esc_attr($info['name']); ?>"><?php echo esc_html__('Journal', 'backup-guard'); ?></button><?php endif; ?>
									<button type="button" class="button backup-guard-danger backup-guard-delete" data-name="<?php echo esc_attr($info['name']); ?>"><?php echo esc_html__('Supprimer', 'backup-guard'); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ($pages > 1) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<span class="displaying-num"><?php echo (int) $total; ?> <?php echo esc_html__('sauvegardes', 'backup-guard'); ?></span>
						<?php for ($i = 1; $i <= $pages; $i++) : ?>
							<a class="button <?php echo $i === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=backup-guard&history_page=' . $i . $filter_query . '#history')); ?>"><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</div></div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private static function tab_notifications() {
		$types = Backup_Guard_Notifications::types();
		$channels = Backup_Guard_Notifications::all();
		?>
		<section id="tab-notifications" class="backup-guard-tab" hidden>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Être prévenu', 'backup-guard'); ?></h2>
				<p><?php echo esc_html__('Chaque sauvegarde réussie ou ratée peut envoyer un message. L\'e-mail est un service comme les autres : ajoutez-en autant que vous voulez, de toutes sortes, et choisissez pour chacun s\'il veut les réussites, les échecs ou les deux.', 'backup-guard'); ?></p>
				<table class="widefat striped backup-guard-table">
					<thead><tr><th><?php echo esc_html__('Nom', 'backup-guard'); ?></th><th><?php echo esc_html__('Service', 'backup-guard'); ?></th><th><?php echo esc_html__('Prévenir en cas de', 'backup-guard'); ?></th><th><?php echo esc_html__('Dernier envoi', 'backup-guard'); ?></th><th><?php echo esc_html__('Actions', 'backup-guard'); ?></th></tr></thead>
					<tbody>
						<?php if (!$channels) : ?>
							<tr><td colspan="5" class="backup-guard-empty"><?php echo esc_html__('Aucune notification pour le moment. Ajoutez un e-mail ou un service.', 'backup-guard'); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ($channels as $id => $channel) :
							$last = $channel['last'] ?? null;
							$events = array();
							if (!empty($channel['on_success'])) {
								$events[] = __('réussite', 'backup-guard');
							}
							if (!empty($channel['on_failure'])) {
								$events[] = __('échec', 'backup-guard');
							}
							$edit = array('id' => $channel['id'], 'name' => $channel['name'], 'type' => $channel['type'], 'enabled' => !empty($channel['enabled']) ? 1 : 0, 'on_success' => !empty($channel['on_success']) ? 1 : 0, 'on_failure' => !empty($channel['on_failure']) ? 1 : 0) + (array) $channel['config'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html($channel['name']); ?></strong><?php echo empty($channel['enabled']) ? ' <span class="backup-guard-tag">' . esc_html__('En pause', 'backup-guard') . '</span>' : ''; ?>
									<?php if ($channel['type'] === 'email' && !empty($channel['config']['address'])) : ?><br><small><?php echo esc_html($channel['config']['address']); ?></small><?php endif; ?>
								</td>
								<td><?php echo esc_html($types[$channel['type']]['label'] ?? $channel['type']); ?></td>
								<td><?php echo esc_html($events ? implode(' et ', $events) : 'rien'); ?></td>
								<td>
									<?php if ($last) : ?>
										<span class="<?php echo !empty($last['ok']) ? 'backup-guard-ok' : 'backup-guard-bad'; ?>"><?php echo esc_html(!empty($last['ok']) ? _x('Réussi', 'result of the last send', 'backup-guard') : _x('Échec', 'result of the last send', 'backup-guard')); ?></span>
										<?php echo esc_html(wp_date('j M H:i', (int) $last['t'])); ?>
										<?php if (empty($last['ok'])) : ?><br><small><?php echo esc_html($last['text']); ?></small><?php endif; ?>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td class="backup-guard-actions">
									<button type="button" class="button backup-guard-ch-test" data-id="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Envoyer un test', 'backup-guard'); ?></button>
									<button type="button" class="button backup-guard-ch-edit" data-channel="<?php echo esc_attr(wp_json_encode($edit)); ?>"><?php echo esc_html__('Modifier', 'backup-guard'); ?></button>
									<form method="post" class="backup-guard-confirm" data-confirm="<?php echo esc_attr__('Supprimer ce canal de notification ?', 'backup-guard'); ?>"><?php wp_nonce_field('backup_guard_form'); ?><input type="hidden" name="backup_guard_form" value="delete_channel"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button backup-guard-danger"><?php echo esc_html__('Supprimer', 'backup-guard'); ?></button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="backup-guard-ch-add"><?php echo esc_html__('Ajouter une notification', 'backup-guard'); ?></button></p>
			</div>

			<div class="backup-guard-card" id="backup-guard-ch-card" hidden>
				<h2 id="backup-guard-ch-title"><?php echo esc_html__('Ajouter un service', 'backup-guard'); ?></h2>
				<form method="post" id="backup-guard-ch-form" autocomplete="off">
					<?php wp_nonce_field('backup_guard_form'); ?>
					<input type="hidden" name="backup_guard_form" value="channel">
					<input type="hidden" name="id" value="">
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-ch-type"><?php echo esc_html__('Service', 'backup-guard'); ?></label>
						<select name="type" id="backup-guard-ch-type">
							<?php foreach ($types as $key => $type) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($type['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="backup-guard-row">
						<label class="backup-guard-row-label" for="backup-guard-ch-name"><?php echo esc_html__('Nom (facultatif)', 'backup-guard'); ?></label>
						<input type="text" name="name" id="backup-guard-ch-name" class="regular-text" placeholder="<?php echo esc_attr__('Par exemple : Salon alertes', 'backup-guard'); ?>">
					</div>
					<?php
					foreach ($types as $key => $type) {
						echo '<div class="backup-guard-ch-fields" data-type="' . esc_attr($key) . '" hidden>';
						foreach ($type['fields'] as $name => $field) {
							$input_id = 'backup-guard-ch-' . $key . '-' . $name;
							$required = !empty($field['required']) && empty($field['secret']);
							echo '<div class="backup-guard-row"><label class="backup-guard-row-label" for="' . esc_attr($input_id) . '">' . esc_html($field['label']) . '</label><div>';
							echo '<input type="' . (!empty($field['secret']) ? 'password' : 'text') . '" class="regular-text" autocomplete="new-password" name="' . esc_attr($name) . '" id="' . esc_attr($input_id) . '" data-default="' . esc_attr($field['default'] ?? '') . '"' . ($required ? ' data-required="1"' : '') . '>';
							if (!empty($field['help'])) {
								echo '<p class="description">' . esc_html($field['help']) . '</p>';
							}
							if (!empty($field['secret'])) {
								echo '<p class="description backup-guard-secret-note" hidden>' . esc_html__('Laissez vide pour garder la valeur enregistrée.', 'backup-guard') . '</p>';
							}
							echo '</div></div>';
						}
						echo '</div>';
					}
					?>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('Prévenir en cas de', 'backup-guard'); ?></div>
						<div class="backup-guard-checks">
							<label><input type="checkbox" name="on_success" value="1" checked> <?php echo esc_html__('Réussite', 'backup-guard'); ?></label>
							<label><input type="checkbox" name="on_failure" value="1" checked> <?php echo esc_html__('Échec', 'backup-guard'); ?></label>
						</div>
					</div>
					<div class="backup-guard-row">
						<div class="backup-guard-row-label"><?php echo esc_html__('État', 'backup-guard'); ?></div>
						<label><input type="checkbox" name="enabled" value="1" checked> <?php echo esc_html__('Actif', 'backup-guard'); ?></label>
					</div>
					<p>
						<button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer', 'backup-guard'); ?></button>
						<button type="button" class="button" id="backup-guard-ch-cancel"><?php echo esc_html__('Annuler', 'backup-guard'); ?></button>
					</p>
				</form>
			</div>
		</section>
		<?php
	}

	private static function tab_help() {
		?>
		<section id="tab-help" class="backup-guard-tab" hidden>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Vérifier mon site', 'backup-guard'); ?></h2>
				<p><?php echo esc_html__('Contrôle ce dont les sauvegardes ont besoin sur votre hébergement.', 'backup-guard'); ?></p>
				<p><button type="button" class="button button-primary" id="backup-guard-diagnose"><?php echo esc_html__('Lancer la vérification', 'backup-guard'); ?></button></p>
				<ul id="backup-guard-diagnose-list" class="backup-guard-checklist"></ul>
			</div>
			<?php $litespeed = Backup_Guard_Litespeed::status(); ?>
			<div class="backup-guard-card" id="backup-guard-litespeed">
				<h2><?php echo esc_html__('Sauvegarde qui se bloque sans erreur (LiteSpeed)', 'backup-guard'); ?></h2>
				<p><?php echo esc_html__('Sur certains serveurs LiteSpeed, une sauvegarde est tuée en route, sans message : elle reste coincée à un pourcentage fixe. La parade est une règle « noabort » dans le fichier .htaccess du site.', 'backup-guard'); ?></p>
				<p class="backup-guard-cron-status backup-guard-cron-<?php echo $litespeed[0] === 'warning' ? 'warning' : ($litespeed[0] === 'ok' ? 'ok' : 'info'); ?>" id="backup-guard-litespeed-status"><?php echo esc_html($litespeed[1]); ?></p>
				<p class="backup-guard-inline">
					<button type="button" class="button button-primary backup-guard-litespeed" data-what="1"><?php echo esc_html__('Ajouter la règle', 'backup-guard'); ?></button>
					<button type="button" class="button backup-guard-litespeed" data-what="2"><?php echo esc_html__('Essayer l\'autre forme', 'backup-guard'); ?></button>
					<button type="button" class="button backup-guard-litespeed" data-what="remove"><?php echo esc_html__('Retirer la règle', 'backup-guard'); ?></button>
				</p>
				<p class="description"><?php echo esc_html__('La règle est ajoutée au début du .htaccess, après en avoir gardé une copie. Si le site se met à planter à cause d\'elle, elle est retirée toute seule. L\'autre forme sert quand la première reste sans effet.', 'backup-guard'); ?></p>
			</div>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Déplacer ou cloner un site', 'backup-guard'); ?></h2>
				<ol>
					<li><?php echo esc_html__('Sur le site d\'origine, créez une sauvegarde complète et téléchargez-la, ou gardez-la sur un emplacement distant.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Sur le nouveau site, installez WordPress puis ce plugin.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Dans Historique, importez le fichier, ou ajoutez le même emplacement et cliquez sur Synchroniser.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Cliquez sur Restaurer. L\'adresse du site est remplacée partout dans la base de données ; la connexion à la base et les règles du serveur du nouveau site sont conservées.', 'backup-guard'); ?></li>
				</ol>
				<p class="description"><?php echo esc_html__('Les sauvegardes faites avec le plugin Backuply peuvent aussi être importées et restaurées ici.', 'backup-guard'); ?></p>
			</div>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Connecter un compte cloud', 'backup-guard'); ?></h2>
				<p><?php echo esc_html__('Google Drive, OneDrive, Dropbox et pCloud demandent une application à votre nom : c\'est gratuit, et vos autorisations ne passent par aucun service tiers. L\'adresse de retour à déclarer est :', 'backup-guard'); ?></p>
				<p><code class="backup-guard-code"><?php echo esc_html(admin_url('admin.php?page=backup-guard')); ?></code></p>
				<details class="backup-guard-more"><summary>Google Drive</summary><ol>
					<li><?php echo esc_html__('Sur console.cloud.google.com, créez un projet et activez l\'API Google Drive.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Configurez l\'écran de consentement OAuth, avec votre adresse comme utilisateur de test.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Créez des identifiants « ID client OAuth », de type Application Web, avec l\'adresse de retour ci-dessus.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Copiez l\'identifiant et le secret dans le formulaire de l\'emplacement.', 'backup-guard'); ?></li>
				</ol><p class="description"><?php echo esc_html__('Tant que l\'application reste en mode Test, Google retire son autorisation au bout de 7 jours. Publiez-la pour une connexion durable.', 'backup-guard'); ?></p></details>
				<details class="backup-guard-more"><summary><?php echo esc_html__('Microsoft OneDrive', 'backup-guard'); ?></summary><ol>
					<li><?php echo esc_html__('Sur entra.microsoft.com, ouvrez « Inscriptions d\'applications » et créez-en une pour tous les comptes Microsoft.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Ajoutez l\'adresse de retour ci-dessus, de type Web.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Dans « Certificats et secrets », créez un secret. Copiez sa valeur et l\'ID d\'application dans le formulaire.', 'backup-guard'); ?></li>
				</ol></details>
				<details class="backup-guard-more"><summary>Dropbox</summary><ol>
					<li><?php echo esc_html__('Sur dropbox.com/developers/apps, créez une application avec l\'accès « Scoped », type « Full Dropbox ».', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Dans Permissions, cochez files.content.read, files.content.write et files.metadata.read.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Dans Settings, ajoutez l\'adresse de retour puis copiez App key et App secret dans le formulaire.', 'backup-guard'); ?></li>
				</ol></details>
				<details class="backup-guard-more"><summary>pCloud</summary><ol>
					<li><?php echo esc_html__('Sur docs.pcloud.com/my_apps, créez une application avec l\'accès aux fichiers.', 'backup-guard'); ?></li>
					<li><?php echo esc_html__('Ajoutez l\'adresse de retour puis copiez Client ID et Client secret dans le formulaire.', 'backup-guard'); ?></li>
				</ol></details>
			</div>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('Recevoir les notifications sur son téléphone ou un salon', 'backup-guard'); ?></h2>
				<p><?php echo esc_html__('Dans l\'onglet Notifications, ajoutez un e-mail ou un service, collez l\'adresse ou le jeton qu\'il vous donne, puis cliquez sur « Envoyer un test ».', 'backup-guard'); ?></p>
				<ul class="backup-guard-bullets">
					<li><strong>Pushover</strong> <?php echo esc_html__(': le jeton d\'application se crée sur pushover.net/apps, la clé utilisateur est sur votre tableau de bord.', 'backup-guard'); ?></li>
					<li><strong>Gotify</strong> <?php echo esc_html__(': adresse de votre serveur, puis un jeton d\'application créé dans son interface.', 'backup-guard'); ?></li>
					<li><strong>ntfy</strong> <?php echo esc_html__(': choisissez un sujet long et secret, abonnez-vous-y dans l\'application ntfy.', 'backup-guard'); ?></li>
					<li><strong>Telegram</strong> <?php echo esc_html__(': créez un bot avec @BotFather, écrivez-lui, puis relevez l\'identifiant de la discussion.', 'backup-guard'); ?></li>
					<li><strong>Discord</strong> <?php echo esc_html__(': réglages du salon, Intégrations, Webhooks, Copier l\'URL.', 'backup-guard'); ?></li>
					<li><strong>Slack</strong> <?php echo esc_html__(': créez un « Incoming Webhook » dans votre espace de travail.', 'backup-guard'); ?></li>
					<li><strong>Webhook</strong> <?php echo esc_html__(': n\'importe quelle adresse qui accepte un POST en JSON (Zapier, n8n, Home Assistant...).', 'backup-guard'); ?></li>
				</ul>
			</div>
			<div class="backup-guard-card">
				<h2><?php echo esc_html__('En ligne de commande', 'backup-guard'); ?></h2>
				<pre class="backup-guard-pre">wp backup-guard backup [--files-only] [--db-only] [--location=&lt;id&gt;] [--note=&lt;texte&gt;]
wp backup-guard list
wp backup-guard locations
wp backup-guard restore &lt;nom&gt; [--files-only] [--db-only] [--yes]
wp backup-guard delete &lt;nom&gt;</pre>
			</div>
		</section>
		<?php
	}

	private static function modals() {
		?>
		<div class="backup-guard-modal" id="backup-guard-progress" hidden role="dialog" aria-modal="true" aria-labelledby="backup-guard-progress-title">
			<div class="backup-guard-modal-box">
				<h2 id="backup-guard-progress-title"><?php echo esc_html__('Sauvegarde en cours', 'backup-guard'); ?></h2>
				<div class="backup-guard-bar"><i id="backup-guard-progress-bar"></i></div>
				<p class="backup-guard-percent"><span id="backup-guard-progress-percent">0</span> %</p>
				<p class="backup-guard-hint" id="backup-guard-progress-hint"><?php echo esc_html__('Vous pouvez fermer cette page, sauf si l\'onglet Aide indique que le site ne peut pas s\'appeler lui-même.', 'backup-guard'); ?></p>
				<div class="backup-guard-log" id="backup-guard-progress-log" tabindex="0"></div>
				<p class="backup-guard-modal-actions">
					<button type="button" class="button" id="backup-guard-progress-stop"><?php echo esc_html__('Arrêter', 'backup-guard'); ?></button>
					<button type="button" class="button" id="backup-guard-progress-force" hidden><?php echo esc_html__('Forcer l\'arrêt', 'backup-guard'); ?></button>
					<button type="button" class="button button-primary" id="backup-guard-progress-close" hidden><?php echo esc_html__('Fermer', 'backup-guard'); ?></button>
				</p>
			</div>
		</div>

		<div class="backup-guard-modal" id="backup-guard-restore-box" hidden role="dialog" aria-modal="true" aria-labelledby="backup-guard-restore-title">
			<div class="backup-guard-modal-box">
				<h2 id="backup-guard-restore-title"><?php echo esc_html__('Restaurer cette sauvegarde', 'backup-guard'); ?></h2>
				<p class="backup-guard-checks">
					<label id="backup-guard-restore-files-wrap"><input type="checkbox" id="backup-guard-restore-files" checked> <?php echo esc_html__('Fichiers du site', 'backup-guard'); ?></label>
					<label id="backup-guard-restore-db-wrap"><input type="checkbox" id="backup-guard-restore-db" checked> <?php echo esc_html__('Base de données', 'backup-guard'); ?></label>
				</p>
				<p class="backup-guard-migrate" id="backup-guard-restore-migrate" hidden></p>
				<p class="backup-guard-warn-box"><strong><?php echo esc_html__('Le contenu actuel sera remplacé', 'backup-guard'); ?></strong> <?php echo esc_html__('par celui de cette sauvegarde. Le site sera en maintenance pendant l\'opération.', 'backup-guard'); ?></p>
				<p><label><input type="checkbox" id="backup-guard-restore-confirm"> <?php echo esc_html__('J\'ai compris', 'backup-guard'); ?></label></p>
				<p class="backup-guard-modal-actions">
					<button type="button" class="button" id="backup-guard-restore-cancel"><?php echo esc_html__('Annuler', 'backup-guard'); ?></button>
					<button type="button" class="button button-primary" id="backup-guard-restore-go" disabled><?php echo esc_html__('Restaurer', 'backup-guard'); ?></button>
				</p>
			</div>
		</div>

		<div class="backup-guard-modal" id="backup-guard-log-box" hidden role="dialog" aria-modal="true" aria-labelledby="backup-guard-log-title">
			<div class="backup-guard-modal-box">
				<h2 id="backup-guard-log-title"><?php echo esc_html__('Journal', 'backup-guard'); ?></h2>
				<div class="backup-guard-log" id="backup-guard-log-content" tabindex="0"></div>
				<p class="backup-guard-modal-actions"><button type="button" class="button button-primary" id="backup-guard-log-close"><?php echo esc_html__('Fermer', 'backup-guard'); ?></button></p>
			</div>
		</div>

		<div class="backup-guard-modal" id="backup-guard-tree-box" hidden role="dialog" aria-modal="true" aria-labelledby="backup-guard-tree-title">
			<div class="backup-guard-modal-box">
				<h2 id="backup-guard-tree-title"><?php echo esc_html__('Choisir ce qu\'il faut exclure', 'backup-guard'); ?></h2>
				<ul class="backup-guard-tree" id="backup-guard-tree-root"></ul>
				<p class="backup-guard-modal-actions">
					<button type="button" class="button" id="backup-guard-tree-close"><?php echo esc_html__('Fermer', 'backup-guard'); ?></button>
				</p>
			</div>
		</div>
		<?php
	}
}

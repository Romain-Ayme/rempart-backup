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
		));
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
			? sprintf('Votre dernière sauvegarde date de %d jours.', floor((time() - $last) / DAY_IN_SECONDS))
			: 'Vous n\'avez encore aucune sauvegarde de ce site.';
		$nonce = wp_create_nonce('rempart');
		echo '<div class="notice notice-warning is-dismissible" id="rempart-nag"><p>' . esc_html($message) . ' <a href="' . $url . '">Faire une sauvegarde</a></p></div>';
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
			self::redirect_with('location', 'L\'autorisation a été annulée.', 'error');
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
					self::redirect_with('settings', 'Réglages enregistrés.');
					break;
				case 'location':
					self::save_location();
					break;
				case 'delete_location':
					Rempart_Locations::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('location', 'Emplacement supprimé.');
					break;
				case 'channel':
					$id = !empty($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;
					$channel = Rempart_Notifications::save(wp_unslash($_POST), $id);
					self::redirect_with('notifications', 'Canal « ' . $channel['name'] . ' » enregistré. Utilisez « Envoyer un test » pour vérifier qu\'il fonctionne.');
					break;
				case 'delete_channel':
					Rempart_Notifications::delete(sanitize_text_field(wp_unslash($_POST['id'])));
					self::redirect_with('notifications', 'Canal supprimé.');
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
			self::redirect_with('location', '« ' . $location['name'] . ' » est connecté.');
		} catch (Exception $e) {
			self::redirect_with('location', $e->getMessage(), 'error');
		}
	}

	private static function save_settings() {
		$settings = rempart_settings();
		$settings['backup_files'] = !empty($_POST['backup_files']) ? 1 : 0;
		$settings['backup_db'] = !empty($_POST['backup_db']) ? 1 : 0;
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
			throw new RuntimeException('Pour les sauvegardes automatiques, choisissez les fichiers, la base de données ou les deux.');
		}

		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		if (!empty($_POST['email']) && $email === '') {
			throw new RuntimeException('Cette adresse e-mail n\'est pas valide.');
		}
		$settings['email'] = $email;
		$settings['additional'] = isset($_POST['additional']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['additional'])) : array();
		$settings['debug'] = !empty($_POST['debug']) ? 1 : 0;
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
			self::redirect_with('location', 'Emplacement enregistré.');
		}
		try {
			Rempart_Storage::make($location)->test();
		} catch (Exception $e) {
			self::redirect_with('location', 'Emplacement enregistré, mais la connexion a échoué : ' . $e->getMessage(), 'error');
		}
		self::redirect_with('location', 'Emplacement enregistré. La connexion fonctionne.');
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
					<h1>Rempart Backup</h1>
					<p>Sauvegarde, restauration, migration et clonage. Tout est inclus, sans licence.</p>
				</div>
			</header>

			<?php if (self::$notice) : ?>
				<div class="notice notice-<?php echo self::$notice[1] === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html(self::$notice[0]); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper rempart-tabs">
				<a class="nav-tab" href="#dashboard">Tableau de bord</a>
				<a class="nav-tab" href="#settings">Réglages</a>
				<a class="nav-tab" href="#location">Emplacements</a>
				<a class="nav-tab" href="#history">Historique</a>
				<a class="nav-tab" href="#notifications">Notifications</a>
				<a class="nav-tab" href="#help">Aide</a>
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

	private static function location_options($selected, $locations, $protocols) {
		echo '<option value="">Dossier local (par défaut)</option>';
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
		$date_format = 'j F Y à H:i';
		?>
		<section id="tab-dashboard" class="rempart-tab">
			<div class="rempart-facts">
				<div class="rempart-fact">
					<span>Dernière sauvegarde</span>
					<strong><?php echo $last_backup ? esc_html(wp_date($date_format, $last_backup)) : 'Aucune'; ?></strong>
					<?php if ($last_backup) : ?><button type="button" class="button-link rempart-show-log" data-log="backup">Voir le journal</button><?php endif; ?>
				</div>
				<div class="rempart-fact">
					<span>Dernière restauration</span>
					<strong><?php echo $last_restore ? esc_html(wp_date($date_format, $last_restore)) : 'Aucune'; ?></strong>
					<?php if ($last_restore) : ?><button type="button" class="button-link rempart-show-log" data-log="restore">Voir le journal</button><?php endif; ?>
				</div>
				<div class="rempart-fact">
					<span>Prochaine sauvegarde automatique</span>
					<strong><?php
					if ($next) {
						echo esc_html(wp_date($date_format, $next));
						if (time() - $next > 300) {
							echo ' (en retard : attend une visite du site)';
						}
					} elseif ($settings['schedule'] === 'custom') {
						echo 'Tâche cron personnalisée';
					} else {
						echo 'Rien de prévu';
					}
					?></strong>
				</div>
			</div>

			<?php $ls = Rempart_Litespeed::status(); ?>
			<?php if ($ls[0] === 'warning') : ?>
				<div class="notice notice-warning inline"><p><strong>Serveur LiteSpeed détecté.</strong> Si une sauvegarde se bloque sans erreur, <a href="#help">ajoutez la règle LiteSpeed dans l'onglet Aide</a>.</p></div>
			<?php endif; ?>
			<div class="rempart-card">
				<h2>Créer une sauvegarde</h2>
				<form id="rempart-backup-form">
					<p class="rempart-checks">
						<label><input type="checkbox" name="files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> Fichiers du site</label>
						<label><input type="checkbox" name="db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> Base de données</label>
					</p>
					<p>
						<label for="rempart-backup-location" class="rempart-label">Où la garder</label>
						<select name="location" id="rempart-backup-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</p>
					<details class="rempart-more">
						<summary>Options supplémentaires</summary>
						<label for="rempart-note" class="rempart-label">Note (facultatif)</label>
						<textarea name="note" id="rempart-note" maxlength="<?php echo (int) REMPART_NOTE_MAX; ?>" rows="2" placeholder="80 caractères au maximum"></textarea>
					</details>
					<p>
						<button type="submit" class="button button-primary button-hero rempart-go">Créer la sauvegarde</button>
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
					<h2>Sauvegardes</h2>
					<div class="rempart-row">
						<div class="rempart-row-label">Par défaut, sauvegarder</div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="backup_files" value="1" <?php checked(!empty($settings['backup_files'])); ?>> Fichiers du site</label>
							<label><input type="checkbox" name="backup_db" value="1" <?php checked(!empty($settings['backup_db'])); ?>> Base de données</label>
						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-default-location">Emplacement par défaut</label>
						<select name="location" id="rempart-default-location"><?php self::location_options($settings['location'], $locations, $protocols); ?></select>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label">Avant les mises à jour de WordPress</div>
						<div class="rempart-checks rempart-stack">
							<label><input type="checkbox" name="pre_update_backup" value="1" <?php checked(!empty($settings['pre_update_backup'])); ?>> Sauvegarder avant une mise à jour manuelle</label>
							<label><input type="checkbox" name="auto_update_backup" value="1" <?php checked(!empty($settings['auto_update_backup'])); ?>> Retenir les mises à jour automatiques jusqu'à la fin d'une sauvegarde</label>
						</div>
					</div>
				</div>

				<div class="rempart-card">
					<h2>Sauvegardes automatiques</h2>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-schedule">Fréquence</label>
						<select name="schedule" id="rempart-schedule">
							<option value="">Pas de sauvegarde automatique</option>
							<?php foreach (Rempart_Schedule::intervals() as $key => $interval) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($settings['schedule'], $key); ?>><?php echo esc_html($interval['display']); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php selected($settings['schedule'], 'custom'); ?>>Personnalisée (tâche cron du serveur)</option>
						</select>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label">Comment ça marche</div>
						<div class="rempart-cron-note">
							<p data-for="none">Aucune sauvegarde automatique n'est prévue. Choisissez une fréquence, puis enregistrez.</p>
							<p data-for="auto">
								<strong>C'est automatique.</strong> Une fois la fréquence enregistrée, WordPress lance les sauvegardes tout seul : il n'y a rien d'autre à faire ni à configurer.
								Il s'appuie sur ses tâches planifiées (WP-Cron), qui se réveillent quand quelqu'un visite le site. Sur un site peu visité, une sauvegarde peut donc partir avec un peu de retard.
								Pour une heure précise, choisissez « Personnalisée » et ajoutez la commande proposée aux tâches cron de votre hébergement.
							</p>
							<p data-for="custom">Rien ne se lance tout seul ici : c'est la tâche cron de votre hébergement qui appelle l'adresse ci-dessous, à l'heure que vous avez choisie.</p>
							<?php
							$cron_status = self::cron_status($settings);
							if ($cron_status) :
								?>
								<p class="rempart-cron-status rempart-cron-<?php echo esc_attr($cron_status[0]); ?>"><?php echo esc_html($cron_status[1]); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<div class="rempart-row" id="rempart-cron-row">
						<div class="rempart-row-label">Commande à planifier</div>
						<div>
							<code class="rempart-code" id="rempart-cron-command">wget -q -O /dev/null "<?php echo esc_html(Rempart_Schedule::cron_url()); ?>"</code>
							<button type="button" class="button rempart-copy" data-copy="#rempart-cron-command">Copier</button>
							<p class="description">Ajoutez-la aux tâches cron de votre hébergement, à l'heure que vous voulez.</p>
						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-rotation">Sauvegardes automatiques à conserver</label>
						<div class="rempart-inline">
							<select name="rotation" id="rempart-rotation">
								<option value="0" <?php selected($rotation, 0); ?>>Toutes</option>
								<?php for ($i = 1; $i <= 10; $i++) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected($rotation, $i); ?>><?php echo (int) $i; ?></option>
								<?php endfor; ?>
								<option value="custom" <?php selected($rotation > 10); ?>>Autre nombre</option>
							</select>
							<input type="number" min="1" name="rotation_custom" id="rempart-rotation-custom" value="<?php echo $rotation > 10 ? (int) $rotation : ''; ?>">
							<span id="rempart-rotation-warning" class="rempart-warn">Plus de 30 sauvegardes peuvent occuper beaucoup de place.</span>
						</div>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label">Contenu</div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="auto_files" value="1" <?php checked(!empty($settings['auto_files'])); ?>> Fichiers du site</label>
							<label><input type="checkbox" name="auto_db" value="1" <?php checked(!empty($settings['auto_db'])); ?>> Base de données</label>
						</div>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-auto-location">Emplacement</label>
						<select name="auto_location" id="rempart-auto-location"><?php self::location_options($settings['auto_location'], $locations, $protocols); ?></select>
					</div>
				</div>

				<div class="rempart-card">
					<h2>Exclusions</h2>
					<div class="rempart-row">
						<div class="rempart-row-label">Tables de la base à exclure</div>
						<details class="rempart-more">
							<summary>Choisir dans la liste</summary>
							<div class="rempart-scroll">
								<?php foreach ((array) $wpdb->get_col('SHOW TABLES') as $table) : ?>
									<label class="rempart-line"><input type="checkbox" name="exclude_db[]" value="<?php echo esc_attr($table); ?>" <?php checked(in_array($table, $excludes['db'], true)); ?>> <?php echo esc_html($table); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label">Fichiers et dossiers à exclure</div>
						<div>
							<div class="rempart-rule-box">
								<strong>Par nom</strong>
								<div class="rempart-inline">
									<select id="rempart-rule-type">
										<option value="extension">Avec l'extension</option>
										<option value="beginning">Dont le nom commence par</option>
										<option value="end">Dont le nom finit par</option>
										<option value="anywhere">Dont le chemin contient</option>
									</select>
									<input type="text" id="rempart-rule-pattern" placeholder="par exemple zip">
									<button type="button" class="button" id="rempart-rule-add">Ajouter</button>
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
								<strong>Dossiers ou fichiers précis</strong>
								<button type="button" class="button" id="rempart-tree-open">Parcourir le site…</button>
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
					<h2>Autres réglages</h2>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-email">E-mail de notification</label>
						<div>
							<input type="email" name="email" id="rempart-email" class="regular-text" value="<?php echo esc_attr($settings['email']); ?>">
							<p class="description">Reçoit le résultat de chaque sauvegarde. Laissez vide pour ne rien recevoir.</p>
						</div>
					</div>
					<?php
					$extra = self::additional_entries();
					if ($extra) :
						?>
					<div class="rempart-row">
						<div class="rempart-row-label">Autres éléments à la racine du site</div>
						<details class="rempart-more">
							<summary>Choisir ceux à inclure</summary>
							<div class="rempart-scroll">
								<?php foreach ($extra as $item) : ?>
									<label class="rempart-line"><input type="checkbox" name="additional[]" value="<?php echo esc_attr($item); ?>" <?php checked(in_array($item, (array) $settings['additional'], true)); ?>> <?php echo esc_html($item); ?></label>
								<?php endforeach; ?>
							</div>
						</details>
					</div>
					<?php endif; ?>
					<div class="rempart-row">
						<div class="rempart-row-label">Mode débogage</div>
						<label><input type="checkbox" name="debug" value="1" <?php checked(!empty($settings['debug'])); ?>> Écrire plus de détails dans le journal (ne le laissez pas activé)</label>
					</div>
				</div>

				<p><button type="submit" class="button button-primary button-large">Enregistrer les réglages</button></p>
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
			return $cron_disabled ? null : array('info', 'La tâche cron de votre hébergement doit appeler l\'adresse ci-dessous : vérifiez qu\'elle est bien créée.');
		}
		if ($cron_disabled) {
			return array('warning', 'Les tâches planifiées de WordPress sont désactivées sur ce site (DISABLE_WP_CRON). Rien ne partira tout seul tant qu\'une tâche cron de l\'hébergement n\'appelle pas wp-cron.php. Le plus simple : choisissez « Personnalisée ».');
		}
		$next = wp_next_scheduled('rempart_auto_backup');
		if (!$next) {
			return array('warning', 'La planification n\'est pas encore enregistrée : cliquez sur « Enregistrer les réglages ».');
		}
		$late = time() - $next;
		if ($late > 300) {
			return array('warning', sprintf('La prochaine sauvegarde aurait dû partir il y a %s : WordPress attend une visite du site pour la lancer. Si cela se répète, utilisez « Personnalisée » avec une tâche cron de l\'hébergement.', human_time_diff($next)));
		}
		return array('ok', 'Prochaine sauvegarde automatique : ' . wp_date('j F Y à H:i', $next) . '.');
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
		?>
		<section id="tab-location" class="rempart-tab" hidden>
			<div class="rempart-card">
				<h2>Emplacements de sauvegarde</h2>
				<table class="widefat striped rempart-table">
					<thead><tr><th>Nom</th><th>Type</th><th>Serveur</th><th>Dossier</th><th>Espace utilisé</th><th>Actions</th></tr></thead>
					<tbody>
						<tr>
							<td>Dossier local</td><td>-</td><td>-</td>
							<td><code><?php echo esc_html(rempart_clean_path((string) rempart_folder('backups'))); ?></code></td>
							<td>-</td><td>-</td>
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
							$quota = $location['quota'] ?? null;
							$quota_text = $quota ? rempart_format_size($quota['used']) . (!empty($quota['total']) ? ' / ' . rempart_format_size($quota['total']) : '') : '-';
							?>
							<tr>
								<td><strong><?php echo esc_html($location['name']); ?></strong></td>
								<td><?php echo esc_html($protocols[$location['protocol']]['label'] ?? $location['protocol']); ?></td>
								<td><?php echo esc_html($config['host'] ?? ($config['url'] ?? ($config['endpoint'] ?? '-'))); ?></td>
								<td><?php echo esc_html(($config['bucket'] ?? '') . (isset($config['bucket']) ? '/' : '') . ($config['folder'] ?? '')); ?></td>
								<td>
									<?php if ($is_oauth) : ?>
										<button type="button" class="button-link rempart-quota" data-id="<?php echo esc_attr($id); ?>" title="Actualiser"><?php echo esc_html($quota_text); ?></button>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td class="rempart-actions">
									<?php if ($is_oauth && !$connected) : ?>
										<form method="post"><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="connect"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button button-primary">Connecter le compte</button></form>
									<?php else : ?>
										<button type="button" class="button rempart-test" data-id="<?php echo esc_attr($id); ?>">Tester</button>
									<?php endif; ?>
									<button type="button" class="button rempart-edit" data-location="<?php echo esc_attr(wp_json_encode(array('id' => $location['id'], 'name' => $location['name'], 'protocol' => $location['protocol']) + $config)); ?>">Modifier</button>
									<form method="post" class="rempart-confirm" data-confirm="Supprimer cet emplacement ? Les sauvegardes déjà envoyées y restent."><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="delete_location"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button rempart-danger">Supprimer</button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="rempart-add-location">Ajouter un emplacement</button></p>
			</div>

			<div class="rempart-card" id="rempart-location-form-card" hidden>
				<h2 id="rempart-location-form-title">Ajouter un emplacement</h2>
				<form method="post" id="rempart-location-form" autocomplete="off">
					<?php wp_nonce_field('rempart_form'); ?>
					<input type="hidden" name="rempart_form" value="location">
					<input type="hidden" name="id" value="">
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-loc-name">Nom</label>
						<input type="text" name="name" id="rempart-loc-name" class="regular-text" required>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-loc-protocol">Type</label>
						<select name="protocol" id="rempart-loc-protocol">
							<?php foreach ($protocols as $key => $protocol) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($protocol['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php self::location_fields($protocols); ?>
					<div class="rempart-oauth-help" hidden>
						<p><strong>Adresse de retour à déclarer dans votre application :</strong><br><code class="rempart-code" id="rempart-redirect"><?php echo esc_html(admin_url('admin.php?page=rempart-backup')); ?></code> <button type="button" class="button rempart-copy" data-copy="#rempart-redirect">Copier</button></p>
						<p class="description">Ce service demande votre propre application : l'onglet Aide explique comment la créer. Après l'enregistrement, vous serez envoyé sur sa page pour accepter l'accès.</p>
					</div>
					<p>
						<button type="submit" class="button button-primary" id="rempart-loc-submit">Enregistrer</button>
						<button type="button" class="button" id="rempart-loc-cancel">Annuler</button>
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
					echo '<select ' . $common . '>';
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
					echo '<p class="description rempart-secret-note" hidden>Laissez vide pour garder la valeur enregistrée.</p>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}
	}

	private static function tab_history($locations, $protocols) {
		Rempart_Backups::sync_local();
		$all = Rempart_Backups::all();
		$total = count($all);
		$pages = max(1, (int) ceil($total / REMPART_HISTORY_PER_PAGE));
		$page = isset($_GET['history_page']) ? max(1, min($pages, (int) $_GET['history_page'])) : 1;
		$rows = array_slice($all, ($page - 1) * REMPART_HISTORY_PER_PAGE, REMPART_HISTORY_PER_PAGE);
		?>
		<section id="tab-history" class="rempart-tab" hidden>
			<div class="rempart-card">
				<div class="rempart-history-tools">
					<div class="rempart-inline">
						<select id="rempart-sync-location">
							<option value="local">Dossier local</option>
							<?php foreach ($locations as $id => $location) : ?>
								<option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($location['name']); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="rempart-sync">Synchroniser</button>
						<button type="button" class="button" id="rempart-upload-open"><span class="dashicons dashicons-upload"></span> Importer une sauvegarde</button>
						<input type="file" id="rempart-upload-file" accept=".gz" hidden>
					</div>
					<div class="rempart-inline">
						<button type="button" class="button" id="rempart-select-all">Tout sélectionner</button>
						<button type="button" class="button rempart-danger" id="rempart-delete-selected">Supprimer</button>
					</div>
				</div>
				<div id="rempart-upload-progress" hidden><div class="rempart-bar"><i></i></div><span></span></div>

				<table class="widefat striped rempart-table" id="rempart-history-table">
					<thead><tr><th class="check-column"></th><th>Date</th><th>Emplacement</th><th>Taille</th><th>Contenu</th><th>Note</th><th>Actions</th></tr></thead>
					<tbody>
						<?php if (!$rows) : ?>
							<tr><td colspan="7" class="rempart-empty">Aucune sauvegarde pour le moment.</td></tr>
						<?php endif; ?>
						<?php
						foreach ($rows as $info) :
							$location = !empty($info['location']) ? ($locations[$info['location']] ?? null) : null;
							$location_label = empty($info['location']) ? 'Local' : ($location ? $location['name'] : 'Emplacement supprimé');
							$has_log = file_exists(REMPART_ROOT . 'logs/' . $info['name'] . '.log.php');
							$content = array();
							if (!empty($info['backup_dir'])) {
								$content[] = 'Fichiers';
							}
							if (!empty($info['backup_db'])) {
								$content[] = 'Base de données';
							}
							$note = $info['note'] ?? ($info['backup_note'] ?? '');
							$download = wp_nonce_url(admin_url('admin-ajax.php?action=rempart_download&name=' . rawurlencode($info['name'])), 'rempart');
							$unreachable = !empty($info['location']) && !$location;
							?>
							<tr>
								<td><input type="checkbox" class="rempart-pick" value="<?php echo esc_attr($info['name']); ?>"></td>
								<td>
									<?php echo esc_html(wp_date('j M Y H:i', (int) $info['btime'])); ?>
									<?php if (!empty($info['auto_backup'])) : ?><span class="rempart-tag">Auto</span><?php endif; ?>
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
										data-source="<?php echo esc_attr($info['site_url'] ?? ($info['backup_site_url'] ?? '')); ?>">Restaurer</button>
									<?php if (!$unreachable) : ?><a class="button" href="<?php echo esc_url($download); ?>">Télécharger</a><?php endif; ?>
									<?php if ($has_log) : ?><button type="button" class="button rempart-show-log" data-log="<?php echo esc_attr($info['name']); ?>">Journal</button><?php endif; ?>
									<button type="button" class="button rempart-danger rempart-delete" data-name="<?php echo esc_attr($info['name']); ?>">Supprimer</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ($pages > 1) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<span class="displaying-num"><?php echo (int) $total; ?> sauvegardes</span>
						<?php for ($i = 1; $i <= $pages; $i++) : ?>
							<a class="button <?php echo $i === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rempart-backup&history_page=' . $i . '#history')); ?>"><?php echo (int) $i; ?></a>
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
		$settings = rempart_settings();
		?>
		<section id="tab-notifications" class="rempart-tab" hidden>
			<div class="rempart-card">
				<h2>Être prévenu</h2>
				<p>Chaque sauvegarde réussie ou ratée peut envoyer un message. L'e-mail se règle dans <a href="#settings">Réglages</a>
					<?php echo $settings['email'] !== '' ? '(actuellement : ' . esc_html($settings['email']) . ')' : '(aucune adresse pour le moment)'; ?>.
					Les services ci-dessous s'ajoutent à l'e-mail : vous pouvez en cumuler autant que vous voulez.</p>
				<table class="widefat striped rempart-table">
					<thead><tr><th>Nom</th><th>Service</th><th>Prévenir en cas de</th><th>Dernier envoi</th><th>Actions</th></tr></thead>
					<tbody>
						<?php if (!$channels) : ?>
							<tr><td colspan="5" class="rempart-empty">Aucun service de notification pour le moment.</td></tr>
						<?php endif; ?>
						<?php
						foreach ($channels as $id => $channel) :
							$last = $channel['last'] ?? null;
							$events = array();
							if (!empty($channel['on_success'])) {
								$events[] = 'réussite';
							}
							if (!empty($channel['on_failure'])) {
								$events[] = 'échec';
							}
							$edit = array('id' => $channel['id'], 'name' => $channel['name'], 'type' => $channel['type'], 'enabled' => !empty($channel['enabled']) ? 1 : 0, 'on_success' => !empty($channel['on_success']) ? 1 : 0, 'on_failure' => !empty($channel['on_failure']) ? 1 : 0) + (array) $channel['config'];
							?>
							<tr>
								<td><strong><?php echo esc_html($channel['name']); ?></strong><?php echo empty($channel['enabled']) ? ' <span class="rempart-tag">En pause</span>' : ''; ?></td>
								<td><?php echo esc_html($types[$channel['type']]['label'] ?? $channel['type']); ?></td>
								<td><?php echo esc_html($events ? implode(' et ', $events) : 'rien'); ?></td>
								<td>
									<?php if ($last) : ?>
										<span class="<?php echo !empty($last['ok']) ? 'rempart-ok' : 'rempart-bad'; ?>"><?php echo esc_html(!empty($last['ok']) ? 'Réussi' : 'Échec'); ?></span>
										<?php echo esc_html(wp_date('j M H:i', (int) $last['t'])); ?>
										<?php if (empty($last['ok'])) : ?><br><small><?php echo esc_html($last['text']); ?></small><?php endif; ?>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td class="rempart-actions">
									<button type="button" class="button rempart-ch-test" data-id="<?php echo esc_attr($id); ?>">Envoyer un test</button>
									<button type="button" class="button rempart-ch-edit" data-channel="<?php echo esc_attr(wp_json_encode($edit)); ?>">Modifier</button>
									<form method="post" class="rempart-confirm" data-confirm="Supprimer ce canal de notification ?"><?php wp_nonce_field('rempart_form'); ?><input type="hidden" name="rempart_form" value="delete_channel"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><button class="button rempart-danger">Supprimer</button></form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-primary" id="rempart-ch-add">Ajouter un service</button></p>
			</div>

			<div class="rempart-card" id="rempart-ch-card" hidden>
				<h2 id="rempart-ch-title">Ajouter un service</h2>
				<form method="post" id="rempart-ch-form" autocomplete="off">
					<?php wp_nonce_field('rempart_form'); ?>
					<input type="hidden" name="rempart_form" value="channel">
					<input type="hidden" name="id" value="">
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-ch-type">Service</label>
						<select name="type" id="rempart-ch-type">
							<?php foreach ($types as $key => $type) : ?>
								<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($type['label']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="rempart-row">
						<label class="rempart-row-label" for="rempart-ch-name">Nom (facultatif)</label>
						<input type="text" name="name" id="rempart-ch-name" class="regular-text" placeholder="Par exemple : Salon alertes">
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
								echo '<p class="description rempart-secret-note" hidden>Laissez vide pour garder la valeur enregistrée.</p>';
							}
							echo '</div></div>';
						}
						echo '</div>';
					}
					?>
					<div class="rempart-row">
						<div class="rempart-row-label">Prévenir en cas de</div>
						<div class="rempart-checks">
							<label><input type="checkbox" name="on_success" value="1" checked> Réussite</label>
							<label><input type="checkbox" name="on_failure" value="1" checked> Échec</label>
						</div>
					</div>
					<div class="rempart-row">
						<div class="rempart-row-label">État</div>
						<label><input type="checkbox" name="enabled" value="1" checked> Actif</label>
					</div>
					<p>
						<button type="submit" class="button button-primary">Enregistrer</button>
						<button type="button" class="button" id="rempart-ch-cancel">Annuler</button>
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
				<h2>Vérifier mon site</h2>
				<p>Contrôle ce dont les sauvegardes ont besoin sur votre hébergement.</p>
				<p><button type="button" class="button button-primary" id="rempart-diagnose">Lancer la vérification</button></p>
				<ul id="rempart-diagnose-list" class="rempart-checklist"></ul>
			</div>
			<?php $litespeed = Rempart_Litespeed::status(); ?>
			<div class="rempart-card" id="rempart-litespeed">
				<h2>Sauvegarde qui se bloque sans erreur (LiteSpeed)</h2>
				<p>Sur certains serveurs LiteSpeed, une sauvegarde est tuée en route, sans message : elle reste coincée à un pourcentage fixe. La parade est une règle « noabort » dans le fichier .htaccess du site.</p>
				<p class="rempart-cron-status rempart-cron-<?php echo $litespeed[0] === 'warning' ? 'warning' : ($litespeed[0] === 'ok' ? 'ok' : 'info'); ?>" id="rempart-litespeed-status"><?php echo esc_html($litespeed[1]); ?></p>
				<p class="rempart-inline">
					<button type="button" class="button button-primary rempart-litespeed" data-what="1">Ajouter la règle</button>
					<button type="button" class="button rempart-litespeed" data-what="2">Essayer l'autre forme</button>
					<button type="button" class="button rempart-litespeed" data-what="remove">Retirer la règle</button>
				</p>
				<p class="description">La règle est ajoutée au début du .htaccess, après en avoir gardé une copie. Si le site se met à planter à cause d'elle, elle est retirée toute seule. L'autre forme sert quand la première reste sans effet.</p>
			</div>
			<div class="rempart-card">
				<h2>Déplacer ou cloner un site</h2>
				<ol>
					<li>Sur le site d'origine, créez une sauvegarde complète et téléchargez-la, ou gardez-la sur un emplacement distant.</li>
					<li>Sur le nouveau site, installez WordPress puis ce plugin.</li>
					<li>Dans Historique, importez le fichier, ou ajoutez le même emplacement et cliquez sur Synchroniser.</li>
					<li>Cliquez sur Restaurer. L'adresse du site est remplacée partout dans la base de données ; la connexion à la base et les règles du serveur du nouveau site sont conservées.</li>
				</ol>
				<p class="description">Les sauvegardes faites avec le plugin Backuply peuvent aussi être importées et restaurées ici.</p>
			</div>
			<div class="rempart-card">
				<h2>Connecter un compte cloud</h2>
				<p>Google Drive, OneDrive, Dropbox et pCloud demandent une application à votre nom : c'est gratuit, et vos autorisations ne passent par aucun service tiers. L'adresse de retour à déclarer est :</p>
				<p><code class="rempart-code"><?php echo esc_html(admin_url('admin.php?page=rempart-backup')); ?></code></p>
				<details class="rempart-more"><summary>Google Drive</summary><ol>
					<li>Sur console.cloud.google.com, créez un projet et activez l'API Google Drive.</li>
					<li>Configurez l'écran de consentement OAuth, avec votre adresse comme utilisateur de test.</li>
					<li>Créez des identifiants « ID client OAuth », de type Application Web, avec l'adresse de retour ci-dessus.</li>
					<li>Copiez l'identifiant et le secret dans le formulaire de l'emplacement.</li>
				</ol><p class="description">Tant que l'application reste en mode Test, Google retire son autorisation au bout de 7 jours. Publiez-la pour une connexion durable.</p></details>
				<details class="rempart-more"><summary>Microsoft OneDrive</summary><ol>
					<li>Sur entra.microsoft.com, ouvrez « Inscriptions d'applications » et créez-en une pour tous les comptes Microsoft.</li>
					<li>Ajoutez l'adresse de retour ci-dessus, de type Web.</li>
					<li>Dans « Certificats et secrets », créez un secret. Copiez sa valeur et l'ID d'application dans le formulaire.</li>
				</ol></details>
				<details class="rempart-more"><summary>Dropbox</summary><ol>
					<li>Sur dropbox.com/developers/apps, créez une application avec l'accès « Scoped », type « Full Dropbox ».</li>
					<li>Dans Permissions, cochez files.content.read, files.content.write et files.metadata.read.</li>
					<li>Dans Settings, ajoutez l'adresse de retour puis copiez App key et App secret dans le formulaire.</li>
				</ol></details>
				<details class="rempart-more"><summary>pCloud</summary><ol>
					<li>Sur docs.pcloud.com/my_apps, créez une application avec l'accès aux fichiers.</li>
					<li>Ajoutez l'adresse de retour puis copiez Client ID et Client secret dans le formulaire.</li>
				</ol></details>
			</div>
			<div class="rempart-card">
				<h2>Recevoir les notifications sur son téléphone ou un salon</h2>
				<p>Dans l'onglet Notifications, ajoutez un service, collez l'adresse ou le jeton qu'il vous donne, puis cliquez sur « Envoyer un test ».</p>
				<ul class="rempart-bullets">
					<li><strong>Pushover</strong> : le jeton d'application se crée sur pushover.net/apps, la clé utilisateur est sur votre tableau de bord.</li>
					<li><strong>Gotify</strong> : adresse de votre serveur, puis un jeton d'application créé dans son interface.</li>
					<li><strong>ntfy</strong> : choisissez un sujet long et secret, abonnez-vous-y dans l'application ntfy.</li>
					<li><strong>Telegram</strong> : créez un bot avec @BotFather, écrivez-lui, puis relevez l'identifiant de la discussion.</li>
					<li><strong>Discord</strong> : réglages du salon, Intégrations, Webhooks, Copier l'URL.</li>
					<li><strong>Slack</strong> : créez un « Incoming Webhook » dans votre espace de travail.</li>
					<li><strong>Webhook</strong> : n'importe quelle adresse qui accepte un POST en JSON (Zapier, n8n, Home Assistant...).</li>
				</ul>
			</div>
			<div class="rempart-card">
				<h2>En ligne de commande</h2>
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
				<h2 id="rempart-progress-title">Sauvegarde en cours</h2>
				<div class="rempart-bar"><i id="rempart-progress-bar"></i></div>
				<p class="rempart-percent"><span id="rempart-progress-percent">0</span> %</p>
				<p class="rempart-hint" id="rempart-progress-hint">Vous pouvez fermer cette page, sauf si l'onglet Aide indique que le site ne peut pas s'appeler lui-même.</p>
				<div class="rempart-log" id="rempart-progress-log" tabindex="0"></div>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-progress-stop">Arrêter</button>
					<button type="button" class="button" id="rempart-progress-force" hidden>Forcer l'arrêt</button>
					<button type="button" class="button button-primary" id="rempart-progress-close" hidden>Fermer</button>
				</p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-restore-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-restore-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-restore-title">Restaurer cette sauvegarde</h2>
				<p class="rempart-checks">
					<label id="rempart-restore-files-wrap"><input type="checkbox" id="rempart-restore-files" checked> Fichiers du site</label>
					<label id="rempart-restore-db-wrap"><input type="checkbox" id="rempart-restore-db" checked> Base de données</label>
				</p>
				<p class="rempart-migrate" id="rempart-restore-migrate" hidden></p>
				<p class="rempart-warn-box"><strong>Le contenu actuel sera remplacé</strong> par celui de cette sauvegarde. Le site sera en maintenance pendant l'opération.</p>
				<p><label><input type="checkbox" id="rempart-restore-confirm"> J'ai compris</label></p>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-restore-cancel">Annuler</button>
					<button type="button" class="button button-primary" id="rempart-restore-go" disabled>Restaurer</button>
				</p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-log-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-log-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-log-title">Journal</h2>
				<div class="rempart-log" id="rempart-log-content" tabindex="0"></div>
				<p class="rempart-modal-actions"><button type="button" class="button button-primary" id="rempart-log-close">Fermer</button></p>
			</div>
		</div>

		<div class="rempart-modal" id="rempart-tree-box" hidden role="dialog" aria-modal="true" aria-labelledby="rempart-tree-title">
			<div class="rempart-modal-box">
				<h2 id="rempart-tree-title">Choisir ce qu'il faut exclure</h2>
				<ul class="rempart-tree" id="rempart-tree-root"></ul>
				<p class="rempart-modal-actions">
					<button type="button" class="button" id="rempart-tree-close">Fermer</button>
				</p>
			</div>
		</div>
		<?php
	}
}

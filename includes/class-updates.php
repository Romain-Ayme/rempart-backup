<?php
if (!defined('ABSPATH')) {
	exit;
}

/** Backups taken before WordPress core updates, manual or automatic. */
class Rempart_Updates {
	public static function init() {
		add_filter('auto_update_core', array(__CLASS__, 'hold_auto_update'), 100, 2);
		add_action('load-update-core.php', array(__CLASS__, 'before_manual_update'), 1);
	}

	private static function has_fresh_backup() {
		$last = (int) get_option('rempart_last_backup', 0);
		return $last && (time() - $last) < REMPART_PREUPDATE_FRESH_SECONDS;
	}

	private static function start($reason) {
		$settings = rempart_settings();
		Rempart_Job::start_backup(array(
			'files'    => 1,
			'db'       => 1,
			'location' => $settings['location'],
			'note'     => 'Avant mise à jour de WordPress',
			'reason'   => $reason,
		));
	}

	/**
	 * Automatic updates wait until a recent backup exists: the update is refused now, and
	 * WordPress asks again at its next check, by which time the backup has finished.
	 */
	public static function hold_auto_update($update, $item = null) {
		$settings = rempart_settings();
		if (!$update || empty($settings['auto_update_backup']) || self::has_fresh_backup()) {
			return $update;
		}
		if (!Rempart_Job::is_active() && !Rempart_Restore::is_running()) {
			try {
				self::start('auto-update');
			} catch (Exception $e) {
				rempart_debug('Sauvegarde avant mise à jour : ' . $e->getMessage());
				return $update; // A broken backup setup shouldn't block security updates forever.
			}
		}
		return false;
	}

	/** Manual core update: run the backup on a waiting page, then send the update form on. */
	public static function before_manual_update() {
		$settings = rempart_settings();
		$action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
		if (empty($settings['pre_update_backup']) || !in_array($action, array('do-core-upgrade', 'do-core-reinstall'), true)) {
			return;
		}
		if (!current_user_can('update_core') || empty($_POST) || self::has_fresh_backup()) {
			return;
		}
		check_admin_referer('upgrade-core');

		if (!Rempart_Job::is_active()) {
			try {
				self::start('manual-update');
			} catch (Exception $e) {
				wp_die(esc_html('La sauvegarde avant mise à jour a échoué : ' . $e->getMessage()), '', array('back_link' => true));
			}
		}
		self::waiting_page($action);
		exit;
	}

	private static function waiting_page($action) {
		$fields = '';
		foreach (wp_unslash($_POST) as $key => $value) {
			if (is_string($value)) {
				$fields .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
			}
		}
		$form_url = esc_url(add_query_arg('action', $action, network_admin_url('update-core.php')));
		$status_url = esc_url_raw(admin_url('admin-ajax.php'));
		$nonce = wp_create_nonce('rempart');
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Sauvegarde avant mise à jour</title>'
			. '<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#f0f0f1;margin:0;padding:60px 20px;text-align:center;color:#1d2327}'
			. '.box{max-width:480px;margin:auto;background:#fff;padding:32px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1)}'
			. '.bar{height:10px;background:#dcdcde;border-radius:5px;overflow:hidden;margin:20px 0}.bar i{display:block;height:100%;width:1%;background:#2271b1;transition:width .4s}</style></head><body>'
			. '<div class="box"><h1 style="font-size:20px">Sauvegarde avant la mise à jour</h1><p id="msg">Votre site est sauvegardé. La mise à jour de WordPress commencera toute seule ensuite.</p>'
			. '<div class="bar"><i id="bar"></i></div><form id="go" method="post" action="' . $form_url . '">' . $fields . '<button class="button" id="anyway" style="display:none">Mettre à jour quand même</button></form></div>'
			. '<script>(function(){var f=document.getElementById("go"),b=document.getElementById("bar"),m=document.getElementById("msg"),a=document.getElementById("anyway");'
			. 'function tick(){fetch(' . wp_json_encode($status_url) . '+"?action=rempart_status&from=0&_ajax_nonce=' . esc_js($nonce) . '",{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){var d=j.data||{};b.style.width=(d.percent||1)+"%";'
			. 'if(d.status==="success"){m.textContent="Sauvegarde terminée. Mise à jour en cours…";f.submit();return}'
			. 'if(d.status==="error"||d.status==="stopped"){m.textContent="La sauvegarde n’a pas abouti. Vous pouvez mettre à jour quand même ou revenir en arrière.";a.style.display="inline-block";return}'
			. 'setTimeout(tick,2500)}).catch(function(){setTimeout(tick,5000)})}tick()})();</script></body></html>';
	}
}

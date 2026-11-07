(function () {
	'use strict';

	// Texts come from the page in the site's language; the French text itself is the fallback.
	function T(text) {
		var map = (window.BackupGuardData && BackupGuardData.i18n) || {};
		var out = Object.prototype.hasOwnProperty.call(map, text) ? map[text] : text;
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return out.replace(/%(?:(\d+)\$)?s/g, function (m, n) { return String(args[n ? n - 1 : next++]); });
	}


	var D = window.BackupGuardData;
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	function call(action, params, file) {
		var body = new FormData();
		body.append('action', 'backup_guard_' + action);
		body.append('_ajax_nonce', D.nonce);
		Object.keys(params || {}).forEach(function (k) {
			var v = params[k];
			if (Array.isArray(v)) {
				v.forEach(function (x) { body.append(k + '[]', x); });
			} else if (v !== undefined && v !== null) {
				body.append(k, v);
			}
		});
		if (file) { body.append('file', file, 'chunk'); }
		return fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.text(); })
			.then(function (t) {
				var j;
				try { j = JSON.parse(t); } catch (e) { throw new Error(T('Réponse illisible du serveur.')); }
				if (!j.success) { throw new Error(typeof j.data === 'string' ? j.data : T('Erreur inconnue.')); }
				return j.data;
			});
	}

	/* ----- Tabs ----- */

	function showTab() {
		var name = (location.hash || '#dashboard').slice(1);
		if (!$('#tab-' + name)) { name = 'dashboard'; }
		$$('.backup-guard-tab').forEach(function (t) { t.hidden = t.id !== 'tab-' + name; });
		$$('.backup-guard-tabs a').forEach(function (a) {
			a.classList.toggle('nav-tab-active', a.getAttribute('href') === '#' + name);
		});
	}
	window.addEventListener('hashchange', showTab);
	showTab();

	/* ----- Progress window ----- */

	var P = {
		box: $('#backup-guard-progress'), title: $('#backup-guard-progress-title'), bar: $('#backup-guard-progress-bar'), percent: $('#backup-guard-progress-percent'),
		log: $('#backup-guard-progress-log'), stop: $('#backup-guard-progress-stop'), force: $('#backup-guard-progress-force'), close: $('#backup-guard-progress-close'), hint: $('#backup-guard-progress-hint')
	};
	var from = 0;
	var timer = null;

	function addLog(box, lines) {
		lines.forEach(function (l) {
			var row = document.createElement('div');
			row.className = 'l-' + l.l;
			row.textContent = new Date(l.t * 1000).toLocaleTimeString() + '  ' + l.m;
			box.appendChild(row);
		});
		box.scrollTop = box.scrollHeight;
	}

	function setPercent(p) {
		P.bar.style.width = p + '%';
		P.percent.textContent = p;
	}

	function openProgress(title) {
		from = 0;
		P.log.textContent = '';
		P.title.textContent = title;
		P.stop.hidden = false;
		P.stop.disabled = false;
		P.force.hidden = true;
		P.close.hidden = true;
		setPercent(0);
		P.box.hidden = false;
	}

	function finishProgress(title) {
		P.title.textContent = title;
		P.stop.hidden = true;
		P.force.hidden = true;
		P.close.hidden = false;
		P.hint.hidden = true;
		clearTimeout(timer);
	}

	function pollJob() {
		call('status', { from: from }).then(function (d) {
			addLog(P.log, d.log || []);
			from = d.next || from;
			setPercent(d.percent || 0);
			if (d.kind === 'fetch' && d.status === 'success' && d.restore) {
				from = 0;
				pollRestore(d.restore);
				return;
			}
			if (d.status === 'success') {
				finishProgress(T('Sauvegarde terminée'));
				return;
			}
			if (d.status === 'error') {
				finishProgress(T('La tâche n\'a pas abouti'));
				return;
			}
			if (d.status === 'stopped') {
				finishProgress(T('Tâche arrêtée'));
				return;
			}
			timer = setTimeout(pollJob, 2000);
		}).catch(function () {
			timer = setTimeout(pollJob, 5000);
		});
	}

	var restoreFailures = 0;
	function pollRestore(r) {
		P.title.textContent = T('Restauration en cours');
		P.stop.hidden = true;
		P.hint.textContent = T('Gardez cette page ouverte jusqu\'à la fin.');
		P.hint.hidden = false;
		var url = r.url + '?op=status&key=' + encodeURIComponent(r.key) + '&from=' + from;
		fetch(url, { credentials: 'omit', cache: 'no-store' }).then(function (res) {
			if (!res.ok) { throw new Error('http'); }
			return res.json();
		}).then(function (d) {
			restoreFailures = 0;
			P.force.hidden = true;
			addLog(P.log, d.log || []);
			from = d.next || from;
			setPercent(d.percent || 0);
			if (d.finished) {
				finishProgress(d.status === 'success' ? T('Restauration terminée') : T('La restauration n\'a pas abouti'));
				P.close.dataset.reload = '1';
				if (d.status === 'success') {
					var row = document.createElement('div');
					row.className = 'l-success';
					row.textContent = T('Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.');
					P.log.appendChild(row);
					P.log.scrollTop = P.log.scrollHeight;
				}
				call('restore_ack').catch(function () {});
				return;
			}
			timer = setTimeout(function () { pollRestore(r); }, 2000);
		}).catch(function () {
			restoreFailures++;
			if (restoreFailures >= 8) {
				P.force.hidden = false;
				P.force.dataset.mode = 'restore';
				if (restoreFailures === 8) {
					addLog(P.log, [{ t: Date.now() / 1000, l: 'warning', m: T('Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.') }]);
				}
			}
			timer = setTimeout(function () { pollRestore(r); }, 4000);
		});
	}

	P.stop.addEventListener('click', function () {
		P.stop.disabled = true;
		P.force.hidden = false;
		P.force.dataset.mode = 'job';
		call('stop').catch(function () {});
	});
	P.force.addEventListener('click', function () {
		var action = P.force.dataset.mode === 'restore' ? 'restore_force_stop' : 'force_stop';
		call(action).then(function () {
			if (P.force.dataset.mode === 'restore') { P.close.dataset.reload = '1'; finishProgress(T('Restauration arrêtée')); }
		}).catch(function (e) { alert(e.message); });
	});
	P.close.addEventListener('click', function () { location.reload(); });

	var form = $('#backup-guard-form');
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = {
				files: form.elements.files.checked ? 1 : '',
				db: form.elements.db.checked ? 1 : '',
				scope: form.elements.scope.value,
				location: form.elements.location.value,
				note: form.elements.note.value
			};
			var btn = $('.backup-guard-go', form);
			btn.disabled = true;
			P.hint.hidden = false;
			call('start_backup', data).then(function () {
				openProgress(T('Sauvegarde en cours'));
				pollJob();
			}).catch(function (err) {
				alert(err.message);
			}).then(function () { btn.disabled = false; });
		});
	}

	/* ----- Rules, schedule ----- */

	var schedule = $('#backup-guard-schedule');
	function syncSchedule() {
		$('#backup-guard-cron-row').hidden = schedule.value !== 'custom';
		var kind = schedule.value === '' ? 'none' : (schedule.value === 'custom' ? 'custom' : 'auto');
		$$('.backup-guard-cron-note p[data-for]').forEach(function (p) { p.hidden = p.dataset.for !== kind; });
	}
	if (schedule) {
		schedule.addEventListener('change', syncSchedule);
		syncSchedule();
	}
	var rotation = $('#backup-guard-rotation');
	var rotationCustom = $('#backup-guard-rotation-custom');
	var rotationWarn = $('#backup-guard-rotation-warning');
	function syncRotation() {
		var custom = rotation.value === 'custom';
		rotationCustom.hidden = !custom;
		rotationWarn.hidden = !(custom && parseInt(rotationCustom.value, 10) > 30);
	}
	if (rotation) {
		rotation.addEventListener('change', syncRotation);
		rotationCustom.addEventListener('input', syncRotation);
		syncRotation();
	}

	$$('.backup-guard-copy').forEach(function (b) {
		b.addEventListener('click', function () {
			var text = $(b.dataset.copy).textContent;
			navigator.clipboard.writeText(text).then(function () {
				var old = b.textContent;
				b.textContent = T('Copié');
				setTimeout(function () { b.textContent = old; }, 1500);
			});
		});
	});

	function ruleRow(type, key, text) {
		var li = document.createElement('li');
		li.dataset.type = type;
		li.dataset.key = key;
		var span = document.createElement('span');
		span.textContent = text;
		var del = document.createElement('button');
		del.type = 'button';
		del.className = 'button-link backup-guard-rule-del';
		del.setAttribute('aria-label', T('Retirer'));
		del.innerHTML = '<span class="dashicons dashicons-no-alt"></span>';
		li.appendChild(span);
		li.appendChild(document.createTextNode(' '));
		li.appendChild(del);
		return li;
	}

	var ruleLabels = { extension: T('Extension'), beginning: T('Commence par'), end: T('Finit par'), anywhere: T('Contient') };
	var ruleAdd = $('#backup-guard-rule-add');
	if (ruleAdd) {
		ruleAdd.addEventListener('click', function () {
			var type = $('#backup-guard-rule-type').value;
			var input = $('#backup-guard-rule-pattern');
			if (!input.value.trim()) { return; }
			call('exclude_add', { type: type, pattern: input.value.trim() }).then(function (d) {
				$('#backup-guard-rules-pattern').appendChild(ruleRow(type, d.key, ruleLabels[type] + ' : ' + d.pattern));
				input.value = '';
			}).catch(function (e) { alert(e.message); });
		});
	}
	document.addEventListener('click', function (e) {
		var del = e.target.closest ? e.target.closest('.backup-guard-rule-del') : null;
		if (!del) { return; }
		var li = del.closest('li');
		call('exclude_delete', { type: li.dataset.type, key: li.dataset.key }).then(function () { li.remove(); }).catch(function (x) { alert(x.message); });
	});

	/* ----- Folder picker ----- */

	function renderTree(ul, path) {
		ul.textContent = T('Chargement…');
		call('tree', { path: path }).then(function (items) {
			ul.textContent = '';
			items.forEach(function (item) {
				var li = document.createElement('li');
				var node = document.createElement('div');
				node.className = 'backup-guard-node';
				var toggle = document.createElement('button');
				toggle.type = 'button';
				toggle.className = 'toggle';
				toggle.textContent = item.dir ? '▸' : '';
				var label = document.createElement('span');
				label.className = 'label';
				label.textContent = item.name;
				var add = document.createElement('button');
				add.type = 'button';
				add.className = 'button button-small';
				add.textContent = T('Exclure');
				add.addEventListener('click', function () {
					call('exclude_add', { type: 'exact', pattern: item.path }).then(function (d) {
						$('#backup-guard-rules-exact').appendChild(ruleRow('exact', d.key, d.pattern));
						add.disabled = true;
						add.textContent = T('Exclu');
					}).catch(function (e) { alert(e.message); });
				});
				node.appendChild(toggle);
				node.appendChild(label);
				node.appendChild(add);
				li.appendChild(node);
				if (item.dir) {
					var sub = null;
					toggle.addEventListener('click', function () {
						if (sub) {
							sub.hidden = !sub.hidden;
						} else {
							sub = document.createElement('ul');
							li.appendChild(sub);
							renderTree(sub, item.path);
						}
						toggle.textContent = sub.hidden ? '▸' : '▾';
					});
				}
				ul.appendChild(li);
			});
		}).catch(function (e) { ul.textContent = e.message; });
	}

	var treeOpen = $('#backup-guard-tree-open');
	if (treeOpen) {
		treeOpen.addEventListener('click', function () {
			$('#backup-guard-tree-box').hidden = false;
			renderTree($('#backup-guard-tree-root'), '');
		});
		$('#backup-guard-tree-close').addEventListener('click', function () { $('#backup-guard-tree-box').hidden = true; });
	}

	/* ----- Locations ----- */

	var locCard = $('#backup-guard-location-form-card');
	var locForm = $('#backup-guard-location-form');
	var locProtocol = $('#backup-guard-loc-protocol');

	/** S3 services: show the example region, the address pattern and the hint of the chosen one. */
	function applyS3Preset() {
		var service = $('#backup-guard-s3-service');
		if (!service) { return; }
		var all = JSON.parse(service.dataset.presets || '{}');
		var preset = all[service.value] || {};
		$('#backup-guard-s3-region').placeholder = preset.region ? T('par exemple %s', preset.region) : '';
		$('#backup-guard-s3-endpoint').placeholder = preset.example || '';
		var hint = $('#backup-guard-s3-hint');
		if (!hint) {
			hint = document.createElement('p');
			hint.id = 'backup-guard-s3-hint';
			hint.className = 'description';
			service.parentNode.appendChild(hint);
		}
		hint.textContent = preset.hint || '';
	}

	function showLocationFields() {
		var chosen = locProtocol.value;
		var oauth = false;
		$$('.backup-guard-loc-fields').forEach(function (g) {
			var on = g.dataset.protocol === chosen;
			g.hidden = !on;
			// Fields of hidden types must not be sent, nor block the form.
			$$('input,select,textarea', g).forEach(function (el) {
				el.disabled = !on;
				el.required = on && el.dataset.required === '1';
			});
			if (on) { oauth = g.dataset.oauth === '1'; }
		});
		applyS3Preset();
		$('.backup-guard-oauth-help', locForm).hidden = !oauth;
		$('#backup-guard-loc-submit').textContent = oauth && !locForm.elements.id.value ? T('Enregistrer et connecter le compte') : T('Enregistrer');
	}

	function resetLocationForm(values) {
		locForm.elements.id.value = values ? values.id : '';
		$('#backup-guard-loc-name').value = values ? values.name : '';
		locProtocol.value = values ? values.protocol : 'ftp';
		locProtocol.disabled = false;
		$$('.backup-guard-loc-fields input,.backup-guard-loc-fields select,.backup-guard-loc-fields textarea', locForm).forEach(function (el) {
			var group = el.closest('.backup-guard-loc-fields');
			var mine = values && group.dataset.protocol === values.protocol;
			var val = mine && values[el.name] !== undefined ? values[el.name] : (el.dataset.default || '');
			if (el.type === 'checkbox') {
				el.checked = !!(mine && parseInt(values[el.name], 10));
			} else if (el.tagName === 'SELECT') {
				el.value = val || el.options[0].value;
			} else {
				el.value = val;
			}
		});
		$$('.backup-guard-secret-note', locForm).forEach(function (n) { n.hidden = !values; });
		$('#backup-guard-location-form-title').textContent = values ? T('Modifier l\'emplacement') : T('Ajouter un emplacement');
		showLocationFields();
		locCard.hidden = false;
		locCard.scrollIntoView({ behavior: 'smooth' });
	}

	if (locForm) {
		locProtocol.addEventListener('change', showLocationFields);
		var s3Service = $('#backup-guard-s3-service');
		if (s3Service) { s3Service.addEventListener('change', applyS3Preset); }
		$('#backup-guard-add-location').addEventListener('click', function () { resetLocationForm(null); });
		$('#backup-guard-loc-cancel').addEventListener('click', function () { locCard.hidden = true; });
		$$('.backup-guard-edit').forEach(function (b) {
			b.addEventListener('click', function () { resetLocationForm(JSON.parse(b.dataset.location)); });
		});
		$$('.backup-guard-test').forEach(function (b) {
			b.addEventListener('click', function () {
				var old = b.textContent;
				b.disabled = true;
				b.textContent = T('Test…');
				call('test_location', { id: b.dataset.id }).then(function (m) { alert(m); }).catch(function (e) { alert(e.message); }).then(function () {
					b.disabled = false;
					b.textContent = old;
				});
			});
		});
		$$('.backup-guard-quota').forEach(function (b) {
			b.addEventListener('click', function () {
				b.textContent = '…';
				call('quota', { id: b.dataset.id }).then(function (d) { b.textContent = d.text; }).catch(function (e) { b.textContent = '-'; alert(e.message); });
			});
		});
		$$('.backup-guard-confirm').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				if (!confirm(f.dataset.confirm)) { e.preventDefault(); }
			});
		});
	}

	/* ----- Notification channels ----- */

	var chForm = $('#backup-guard-ch-form');
	if (chForm) {
		var chCard = $('#backup-guard-ch-card');
		var chType = $('#backup-guard-ch-type');

		var showChannelFields = function () {
			$$('.backup-guard-ch-fields', chForm).forEach(function (g) {
				var on = g.dataset.type === chType.value;
				g.hidden = !on;
				// Fields of other services must neither be sent nor block the form.
				$$('input', g).forEach(function (el) {
					el.disabled = !on;
					el.required = on && el.dataset.required === '1';
				});
			});
		};

		var openChannelForm = function (values) {
			chForm.elements.id.value = values ? values.id : '';
			chForm.elements.name.value = values ? values.name : '';
			chType.value = values ? values.type : chType.options[0].value;
			chForm.elements.on_success.checked = values ? !!values.on_success : true;
			chForm.elements.on_failure.checked = values ? !!values.on_failure : true;
			chForm.elements.enabled.checked = values ? !!values.enabled : true;
			$$('.backup-guard-ch-fields input', chForm).forEach(function (el) {
				var mine = values && el.closest('.backup-guard-ch-fields').dataset.type === values.type;
				el.value = mine && values[el.name] !== undefined ? values[el.name] : (el.dataset.default || '');
			});
			$$('.backup-guard-secret-note', chForm).forEach(function (n) { n.hidden = !values; });
			$('#backup-guard-ch-title').textContent = values ? T('Modifier le service') : T('Ajouter un service');
			showChannelFields();
			chCard.hidden = false;
			chCard.scrollIntoView({ behavior: 'smooth' });
		};

		chType.addEventListener('change', showChannelFields);
		$('#backup-guard-ch-add').addEventListener('click', function () { openChannelForm(null); });
		$('#backup-guard-ch-cancel').addEventListener('click', function () { chCard.hidden = true; });
		$$('.backup-guard-ch-edit').forEach(function (b) {
			b.addEventListener('click', function () { openChannelForm(JSON.parse(b.dataset.channel)); });
		});
		$$('.backup-guard-ch-test').forEach(function (b) {
			b.addEventListener('click', function () {
				var old = b.textContent;
				b.disabled = true;
				b.textContent = T('Envoi…');
				call('test_channel', { id: b.dataset.id }).then(function (m) {
					alert(m);
					location.reload();
				}).catch(function (e) {
					alert(e.message);
					location.reload();
				});
				setTimeout(function () { b.textContent = old; }, 30000);
			});
		});
	}

	/* ----- History ----- */

	$$('.backup-guard-delete').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm(T('Supprimer définitivement cette sauvegarde ?'))) { return; }
			b.disabled = true;
			call('delete_backups', { names: [b.dataset.name] }).then(function () { location.reload(); }).catch(function (e) { b.disabled = false; alert(e.message); });
		});
	});

	var selectAll = $('#backup-guard-select-all');
	if (selectAll) {
		selectAll.addEventListener('click', function () {
			var picks = $$('.backup-guard-pick');
			var on = picks.some(function (p) { return !p.checked; });
			picks.forEach(function (p) { p.checked = on; });
		});
		$('#backup-guard-delete-selected').addEventListener('click', function () {
			var names = $$('.backup-guard-pick').filter(function (p) { return p.checked; }).map(function (p) { return p.value; });
			if (!names.length) { alert(T('Sélectionnez au moins une sauvegarde.')); return; }
			if (!confirm(T('Supprimer définitivement %s sauvegarde(s) ?', names.length))) { return; }
			call('delete_backups', { names: names }).then(function () { location.reload(); }).catch(function (e) { alert(e.message); });
		});
		$('#backup-guard-sync').addEventListener('click', function () {
			var b = this;
			b.disabled = true;
			call('sync', { location: $('#backup-guard-sync-location').value }).then(function (d) {
				alert(T('%1$s sauvegarde(s) ajoutée(s), %2$s retirée(s) de la liste.', d.added, d.removed));
				location.reload();
			}).catch(function (e) { alert(e.message); b.disabled = false; });
		});
		var fileInput = $('#backup-guard-upload-file');
		$('#backup-guard-upload-open').addEventListener('click', function () { fileInput.click(); });
		fileInput.addEventListener('change', function () {
			var file = fileInput.files[0];
			if (file) { uploadBackup(file); }
		});
	}

	function uploadBackup(file) {
		var box = $('#backup-guard-upload-progress');
		var bar = $('i', box);
		var text = $('span:last-of-type', box);
		box.hidden = false;
		var size = D.chunk;
		var total = Math.max(1, Math.ceil(file.size / size));
		var n = 0;
		function next() {
			n++;
			var piece = file.slice((n - 1) * size, n * size);
			call('upload_chunk', { file_name: file.name, chunk: n, total: total }, piece).then(function (d) {
				bar.style.width = Math.round(n / total * 100) + '%';
				text.textContent = T('Envoi : %s', Math.round(n / total * 100) + ' %');
				if (d.done) {
					location.reload();
				} else {
					next();
				}
			}).catch(function (e) {
				call('upload_chunk', { file_name: file.name, abort: 1 }).catch(function () {});
				box.hidden = true;
				alert(e.message);
			});
		}
		next();
	}

	/* ----- Restore ----- */

	var R = {
		box: $('#backup-guard-restore-box'), files: $('#backup-guard-restore-files'), db: $('#backup-guard-restore-db'), confirm: $('#backup-guard-restore-confirm'),
		go: $('#backup-guard-restore-go'), migrate: $('#backup-guard-restore-migrate'), name: ''
	};
	if (R.box) {
		$$('.backup-guard-restore').forEach(function (b) {
			b.addEventListener('click', function () {
				R.name = b.dataset.name;
				$('#backup-guard-restore-files-wrap').hidden = b.dataset.files !== '1';
				$('#backup-guard-restore-db-wrap').hidden = b.dataset.db !== '1';
				R.files.checked = b.dataset.files === '1';
				R.db.checked = b.dataset.db === '1';
				R.confirm.checked = false;
				R.go.disabled = true;
				var source = b.dataset.source;
				var here = location.origin;
				var moving = source && source.replace(/\/$/, '').indexOf(here) !== 0;
				R.migrate.hidden = !moving;
				if (moving) {
					R.migrate.textContent = T('Cette sauvegarde vient de %s. Son adresse sera remplacée par celle de ce site.', source);
				}
				R.box.hidden = false;
			});
		});
		R.confirm.addEventListener('change', function () { R.go.disabled = !R.confirm.checked; });
		$('#backup-guard-restore-cancel').addEventListener('click', function () { R.box.hidden = true; });
		R.go.addEventListener('click', function () {
			var wanted = { name: R.name, files: R.files.checked ? 1 : '', db: R.db.checked ? 1 : '' };
			R.go.disabled = true;
			call('restore_start', wanted).then(function (d) {
				R.box.hidden = true;
				openProgress(d.mode === 'fetch' ? T('Récupération de la sauvegarde') : T('Restauration en cours'));
				if (d.mode === 'fetch') {
					pollJob();
				} else {
					pollRestore(d);
				}
			}).catch(function (e) { R.go.disabled = false; alert(e.message); });
		});
	}

	/* ----- Logs and checks ----- */

	var logBox = $('#backup-guard-log-box');
	$$('.backup-guard-show-log').forEach(function (b) {
		b.addEventListener('click', function () {
			var out = $('#backup-guard-log-content');
			out.textContent = T('Chargement…');
			logBox.hidden = false;
			call('read_log', { what: b.dataset.log }).then(function (lines) {
				out.textContent = lines.length ? '' : T('Ce journal est vide.');
				addLog(out, lines);
			}).catch(function (e) { out.textContent = e.message; });
		});
	});
	$('#backup-guard-log-close').addEventListener('click', function () { logBox.hidden = true; });

	$$('.backup-guard-litespeed').forEach(function (b) {
		b.addEventListener('click', function () {
			var buttons = $$('.backup-guard-litespeed');
			buttons.forEach(function (x) { x.disabled = true; });
			call('litespeed', { what: b.dataset.what }).then(function (d) {
				var box = $('#backup-guard-litespeed-status');
				box.textContent = d.text;
				box.className = 'backup-guard-cron-status backup-guard-cron-' + (d.level === 'error' ? 'warning' : (d.level === 'ok' ? 'ok' : 'info'));
			}).catch(function (e) { alert(e.message); }).then(function () {
				buttons.forEach(function (x) { x.disabled = false; });
			});
		});
	});

	var diagnose = $('#backup-guard-diagnose');
	if (diagnose) {
		diagnose.addEventListener('click', function () {
			var list = $('#backup-guard-diagnose-list');
			list.textContent = '';
			diagnose.disabled = true;
			call('diagnose').then(function (checks) {
				checks.forEach(function (c) {
					var li = document.createElement('li');
					var mark = document.createElement('span');
					mark.className = c.ok ? 'ok dashicons dashicons-yes-alt' : 'no dashicons dashicons-warning';
					var what = document.createElement('span');
					what.className = 'what';
					what.textContent = c.label;
					var detail = document.createElement('span');
					detail.textContent = c.detail;
					li.appendChild(mark);
					li.appendChild(what);
					li.appendChild(detail);
					list.appendChild(li);
				});
			}).catch(function (e) { alert(e.message); }).then(function () { diagnose.disabled = false; });
		});
	}

	/* ----- Back to a job already running ----- */

	if (D.restore) {
		openProgress(T('Restauration en cours'));
		pollRestore(D.restore);
	} else if (D.active) {
		openProgress(T('Tâche en cours'));
		pollJob();
	}
})();

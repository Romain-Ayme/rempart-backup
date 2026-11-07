(function () {
	'use strict';

	var D = window.RempartData;
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	function call(action, params, file) {
		var body = new FormData();
		body.append('action', 'rempart_' + action);
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
				try { j = JSON.parse(t); } catch (e) { throw new Error('Réponse illisible du serveur.'); }
				if (!j.success) { throw new Error(typeof j.data === 'string' ? j.data : 'Erreur inconnue.'); }
				return j.data;
			});
	}

	/* ----- Tabs ----- */

	function showTab() {
		var name = (location.hash || '#dashboard').slice(1);
		if (!$('#tab-' + name)) { name = 'dashboard'; }
		$$('.rempart-tab').forEach(function (t) { t.hidden = t.id !== 'tab-' + name; });
		$$('.rempart-tabs a').forEach(function (a) {
			a.classList.toggle('nav-tab-active', a.getAttribute('href') === '#' + name);
		});
	}
	window.addEventListener('hashchange', showTab);
	showTab();

	/* ----- Progress window ----- */

	var P = {
		box: $('#rempart-progress'), title: $('#rempart-progress-title'), bar: $('#rempart-progress-bar'), percent: $('#rempart-progress-percent'),
		log: $('#rempart-progress-log'), stop: $('#rempart-progress-stop'), force: $('#rempart-progress-force'), close: $('#rempart-progress-close'), hint: $('#rempart-progress-hint')
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
				finishProgress('Sauvegarde terminée');
				return;
			}
			if (d.status === 'error') {
				finishProgress('La tâche n\'a pas abouti');
				return;
			}
			if (d.status === 'stopped') {
				finishProgress('Tâche arrêtée');
				return;
			}
			timer = setTimeout(pollJob, 2000);
		}).catch(function () {
			timer = setTimeout(pollJob, 5000);
		});
	}

	var restoreFailures = 0;
	function pollRestore(r) {
		P.title.textContent = 'Restauration en cours';
		P.stop.hidden = true;
		P.hint.textContent = 'Gardez cette page ouverte jusqu\'à la fin.';
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
				finishProgress(d.status === 'success' ? 'Restauration terminée' : 'La restauration n\'a pas abouti');
				P.close.dataset.reload = '1';
				if (d.status === 'success') {
					var row = document.createElement('div');
					row.className = 'l-success';
					row.textContent = 'Si la page vous demande de vous connecter, c\'est normal : vos comptes sont ceux de la sauvegarde.';
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
					addLog(P.log, [{ t: Date.now() / 1000, l: 'warning', m: 'Le programme de restauration ne répond plus. Un pare-feu bloque peut-être les fichiers PHP dans wp-content. Attendez encore, ou forcez l\'arrêt.' }]);
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
			if (P.force.dataset.mode === 'restore') { P.close.dataset.reload = '1'; finishProgress('Restauration arrêtée'); }
		}).catch(function (e) { alert(e.message); });
	});
	P.close.addEventListener('click', function () { location.reload(); });

	var form = $('#rempart-backup-form');
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = {
				files: form.elements.files.checked ? 1 : '',
				db: form.elements.db.checked ? 1 : '',
				location: form.elements.location.value,
				note: form.elements.note.value
			};
			var btn = $('.rempart-go', form);
			btn.disabled = true;
			P.hint.hidden = false;
			call('start_backup', data).then(function () {
				openProgress('Sauvegarde en cours');
				pollJob();
			}).catch(function (err) {
				alert(err.message);
			}).then(function () { btn.disabled = false; });
		});
	}

	/* ----- Rules, schedule ----- */

	var schedule = $('#rempart-schedule');
	function syncSchedule() {
		$('#rempart-cron-row').hidden = schedule.value !== 'custom';
		var kind = schedule.value === '' ? 'none' : (schedule.value === 'custom' ? 'custom' : 'auto');
		$$('.rempart-cron-note p[data-for]').forEach(function (p) { p.hidden = p.dataset.for !== kind; });
	}
	if (schedule) {
		schedule.addEventListener('change', syncSchedule);
		syncSchedule();
	}
	var rotation = $('#rempart-rotation');
	var rotationCustom = $('#rempart-rotation-custom');
	var rotationWarn = $('#rempart-rotation-warning');
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

	$$('.rempart-copy').forEach(function (b) {
		b.addEventListener('click', function () {
			var text = $(b.dataset.copy).textContent;
			navigator.clipboard.writeText(text).then(function () {
				var old = b.textContent;
				b.textContent = 'Copié';
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
		del.className = 'button-link rempart-rule-del';
		del.setAttribute('aria-label', 'Retirer');
		del.innerHTML = '<span class="dashicons dashicons-no-alt"></span>';
		li.appendChild(span);
		li.appendChild(document.createTextNode(' '));
		li.appendChild(del);
		return li;
	}

	var ruleLabels = { extension: 'Extension', beginning: 'Commence par', end: 'Finit par', anywhere: 'Contient' };
	var ruleAdd = $('#rempart-rule-add');
	if (ruleAdd) {
		ruleAdd.addEventListener('click', function () {
			var type = $('#rempart-rule-type').value;
			var input = $('#rempart-rule-pattern');
			if (!input.value.trim()) { return; }
			call('exclude_add', { type: type, pattern: input.value.trim() }).then(function (d) {
				$('#rempart-rules-pattern').appendChild(ruleRow(type, d.key, ruleLabels[type] + ' : ' + d.pattern));
				input.value = '';
			}).catch(function (e) { alert(e.message); });
		});
	}
	document.addEventListener('click', function (e) {
		var del = e.target.closest ? e.target.closest('.rempart-rule-del') : null;
		if (!del) { return; }
		var li = del.closest('li');
		call('exclude_delete', { type: li.dataset.type, key: li.dataset.key }).then(function () { li.remove(); }).catch(function (x) { alert(x.message); });
	});

	/* ----- Folder picker ----- */

	function renderTree(ul, path) {
		ul.textContent = 'Chargement…';
		call('tree', { path: path }).then(function (items) {
			ul.textContent = '';
			items.forEach(function (item) {
				var li = document.createElement('li');
				var node = document.createElement('div');
				node.className = 'rempart-node';
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
				add.textContent = 'Exclure';
				add.addEventListener('click', function () {
					call('exclude_add', { type: 'exact', pattern: item.path }).then(function (d) {
						$('#rempart-rules-exact').appendChild(ruleRow('exact', d.key, d.pattern));
						add.disabled = true;
						add.textContent = 'Exclu';
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

	var treeOpen = $('#rempart-tree-open');
	if (treeOpen) {
		treeOpen.addEventListener('click', function () {
			$('#rempart-tree-box').hidden = false;
			renderTree($('#rempart-tree-root'), '');
		});
		$('#rempart-tree-close').addEventListener('click', function () { $('#rempart-tree-box').hidden = true; });
	}

	/* ----- Locations ----- */

	var locCard = $('#rempart-location-form-card');
	var locForm = $('#rempart-location-form');
	var locProtocol = $('#rempart-loc-protocol');

	/** S3 services: show the example region, the address pattern and the hint of the chosen one. */
	function applyS3Preset() {
		var service = $('#rempart-s3-service');
		if (!service) { return; }
		var all = JSON.parse(service.dataset.presets || '{}');
		var preset = all[service.value] || {};
		$('#rempart-s3-region').placeholder = preset.region ? 'par exemple ' + preset.region : '';
		$('#rempart-s3-endpoint').placeholder = preset.example || '';
		var hint = $('#rempart-s3-hint');
		if (!hint) {
			hint = document.createElement('p');
			hint.id = 'rempart-s3-hint';
			hint.className = 'description';
			service.parentNode.appendChild(hint);
		}
		hint.textContent = preset.hint || '';
	}

	function showLocationFields() {
		var chosen = locProtocol.value;
		var oauth = false;
		$$('.rempart-loc-fields').forEach(function (g) {
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
		$('.rempart-oauth-help', locForm).hidden = !oauth;
		$('#rempart-loc-submit').textContent = oauth && !locForm.elements.id.value ? 'Enregistrer et connecter le compte' : 'Enregistrer';
	}

	function resetLocationForm(values) {
		locForm.elements.id.value = values ? values.id : '';
		$('#rempart-loc-name').value = values ? values.name : '';
		locProtocol.value = values ? values.protocol : 'ftp';
		locProtocol.disabled = false;
		$$('.rempart-loc-fields input,.rempart-loc-fields select,.rempart-loc-fields textarea', locForm).forEach(function (el) {
			var group = el.closest('.rempart-loc-fields');
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
		$$('.rempart-secret-note', locForm).forEach(function (n) { n.hidden = !values; });
		$('#rempart-location-form-title').textContent = values ? 'Modifier l\'emplacement' : 'Ajouter un emplacement';
		showLocationFields();
		locCard.hidden = false;
		locCard.scrollIntoView({ behavior: 'smooth' });
	}

	if (locForm) {
		locProtocol.addEventListener('change', showLocationFields);
		var s3Service = $('#rempart-s3-service');
		if (s3Service) { s3Service.addEventListener('change', applyS3Preset); }
		$('#rempart-add-location').addEventListener('click', function () { resetLocationForm(null); });
		$('#rempart-loc-cancel').addEventListener('click', function () { locCard.hidden = true; });
		$$('.rempart-edit').forEach(function (b) {
			b.addEventListener('click', function () { resetLocationForm(JSON.parse(b.dataset.location)); });
		});
		$$('.rempart-test').forEach(function (b) {
			b.addEventListener('click', function () {
				var old = b.textContent;
				b.disabled = true;
				b.textContent = 'Test…';
				call('test_location', { id: b.dataset.id }).then(function (m) { alert(m); }).catch(function (e) { alert(e.message); }).then(function () {
					b.disabled = false;
					b.textContent = old;
				});
			});
		});
		$$('.rempart-quota').forEach(function (b) {
			b.addEventListener('click', function () {
				b.textContent = '…';
				call('quota', { id: b.dataset.id }).then(function (d) { b.textContent = d.text; }).catch(function (e) { b.textContent = '-'; alert(e.message); });
			});
		});
		$$('.rempart-confirm').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				if (!confirm(f.dataset.confirm)) { e.preventDefault(); }
			});
		});
	}

	/* ----- Notification channels ----- */

	var chForm = $('#rempart-ch-form');
	if (chForm) {
		var chCard = $('#rempart-ch-card');
		var chType = $('#rempart-ch-type');

		var showChannelFields = function () {
			$$('.rempart-ch-fields', chForm).forEach(function (g) {
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
			$$('.rempart-ch-fields input', chForm).forEach(function (el) {
				var mine = values && el.closest('.rempart-ch-fields').dataset.type === values.type;
				el.value = mine && values[el.name] !== undefined ? values[el.name] : (el.dataset.default || '');
			});
			$$('.rempart-secret-note', chForm).forEach(function (n) { n.hidden = !values; });
			$('#rempart-ch-title').textContent = values ? 'Modifier le service' : 'Ajouter un service';
			showChannelFields();
			chCard.hidden = false;
			chCard.scrollIntoView({ behavior: 'smooth' });
		};

		chType.addEventListener('change', showChannelFields);
		$('#rempart-ch-add').addEventListener('click', function () { openChannelForm(null); });
		$('#rempart-ch-cancel').addEventListener('click', function () { chCard.hidden = true; });
		$$('.rempart-ch-edit').forEach(function (b) {
			b.addEventListener('click', function () { openChannelForm(JSON.parse(b.dataset.channel)); });
		});
		$$('.rempart-ch-test').forEach(function (b) {
			b.addEventListener('click', function () {
				var old = b.textContent;
				b.disabled = true;
				b.textContent = 'Envoi…';
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

	$$('.rempart-delete').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('Supprimer définitivement cette sauvegarde ?')) { return; }
			b.disabled = true;
			call('delete_backups', { names: [b.dataset.name] }).then(function () { location.reload(); }).catch(function (e) { b.disabled = false; alert(e.message); });
		});
	});

	var selectAll = $('#rempart-select-all');
	if (selectAll) {
		selectAll.addEventListener('click', function () {
			var picks = $$('.rempart-pick');
			var on = picks.some(function (p) { return !p.checked; });
			picks.forEach(function (p) { p.checked = on; });
		});
		$('#rempart-delete-selected').addEventListener('click', function () {
			var names = $$('.rempart-pick').filter(function (p) { return p.checked; }).map(function (p) { return p.value; });
			if (!names.length) { alert('Sélectionnez au moins une sauvegarde.'); return; }
			if (!confirm('Supprimer définitivement ' + names.length + ' sauvegarde(s) ?')) { return; }
			call('delete_backups', { names: names }).then(function () { location.reload(); }).catch(function (e) { alert(e.message); });
		});
		$('#rempart-sync').addEventListener('click', function () {
			var b = this;
			b.disabled = true;
			call('sync', { location: $('#rempart-sync-location').value }).then(function (d) {
				alert(d.added + ' sauvegarde(s) ajoutée(s), ' + d.removed + ' retirée(s) de la liste.');
				location.reload();
			}).catch(function (e) { alert(e.message); b.disabled = false; });
		});
		var fileInput = $('#rempart-upload-file');
		$('#rempart-upload-open').addEventListener('click', function () { fileInput.click(); });
		fileInput.addEventListener('change', function () {
			var file = fileInput.files[0];
			if (file) { uploadBackup(file); }
		});
	}

	function uploadBackup(file) {
		var box = $('#rempart-upload-progress');
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
				text.textContent = 'Envoi : ' + Math.round(n / total * 100) + ' %';
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
		box: $('#rempart-restore-box'), files: $('#rempart-restore-files'), db: $('#rempart-restore-db'), confirm: $('#rempart-restore-confirm'),
		go: $('#rempart-restore-go'), migrate: $('#rempart-restore-migrate'), name: ''
	};
	if (R.box) {
		$$('.rempart-restore').forEach(function (b) {
			b.addEventListener('click', function () {
				R.name = b.dataset.name;
				$('#rempart-restore-files-wrap').hidden = b.dataset.files !== '1';
				$('#rempart-restore-db-wrap').hidden = b.dataset.db !== '1';
				R.files.checked = b.dataset.files === '1';
				R.db.checked = b.dataset.db === '1';
				R.confirm.checked = false;
				R.go.disabled = true;
				var source = b.dataset.source;
				var here = location.origin;
				var moving = source && source.replace(/\/$/, '').indexOf(here) !== 0;
				R.migrate.hidden = !moving;
				if (moving) {
					R.migrate.textContent = 'Cette sauvegarde vient de ' + source + '. Son adresse sera remplacée par celle de ce site.';
				}
				R.box.hidden = false;
			});
		});
		R.confirm.addEventListener('change', function () { R.go.disabled = !R.confirm.checked; });
		$('#rempart-restore-cancel').addEventListener('click', function () { R.box.hidden = true; });
		R.go.addEventListener('click', function () {
			var wanted = { name: R.name, files: R.files.checked ? 1 : '', db: R.db.checked ? 1 : '' };
			R.go.disabled = true;
			call('restore_start', wanted).then(function (d) {
				R.box.hidden = true;
				openProgress(d.mode === 'fetch' ? 'Récupération de la sauvegarde' : 'Restauration en cours');
				if (d.mode === 'fetch') {
					pollJob();
				} else {
					pollRestore(d);
				}
			}).catch(function (e) { R.go.disabled = false; alert(e.message); });
		});
	}

	/* ----- Logs and checks ----- */

	var logBox = $('#rempart-log-box');
	$$('.rempart-show-log').forEach(function (b) {
		b.addEventListener('click', function () {
			var out = $('#rempart-log-content');
			out.textContent = 'Chargement…';
			logBox.hidden = false;
			call('read_log', { what: b.dataset.log }).then(function (lines) {
				out.textContent = lines.length ? '' : 'Ce journal est vide.';
				addLog(out, lines);
			}).catch(function (e) { out.textContent = e.message; });
		});
	});
	$('#rempart-log-close').addEventListener('click', function () { logBox.hidden = true; });

	$$('.rempart-litespeed').forEach(function (b) {
		b.addEventListener('click', function () {
			var buttons = $$('.rempart-litespeed');
			buttons.forEach(function (x) { x.disabled = true; });
			call('litespeed', { what: b.dataset.what }).then(function (d) {
				var box = $('#rempart-litespeed-status');
				box.textContent = d.text;
				box.className = 'rempart-cron-status rempart-cron-' + (d.level === 'error' ? 'warning' : (d.level === 'ok' ? 'ok' : 'info'));
			}).catch(function (e) { alert(e.message); }).then(function () {
				buttons.forEach(function (x) { x.disabled = false; });
			});
		});
	});

	var diagnose = $('#rempart-diagnose');
	if (diagnose) {
		diagnose.addEventListener('click', function () {
			var list = $('#rempart-diagnose-list');
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
		openProgress('Restauration en cours');
		pollRestore(D.restore);
	} else if (D.active) {
		openProgress('Tâche en cours');
		pollJob();
	}
})();

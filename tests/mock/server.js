// Faux service HTTP pour les notifications qui n'ont pas de version auto-hébergée
// (Pushover, Telegram, Discord, Slack) et pour le webhook JSON.
// Il répond 200 à tout et garde chaque requête reçue :
//   GET /_requests    -> la liste, en JSON
//   DELETE /_requests -> la vide
const http = require('http');

let received = [];

http.createServer((request, response) => {
	let body = '';
	request.on('data', (chunk) => { body += chunk; });
	request.on('end', () => {
		if (request.url === '/_requests') {
			if (request.method === 'DELETE') {
				received = [];
			}
			response.setHeader('Content-Type', 'application/json');
			response.end(JSON.stringify(request.method === 'DELETE' ? [] : received));
			return;
		}
		received.push({ method: request.method, path: request.url, headers: request.headers, body });
		response.setHeader('Content-Type', 'application/json');
		response.end('{"ok":true,"status":1}');
	});
}).listen(80);

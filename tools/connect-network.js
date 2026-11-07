// Plugs the wp-env containers into the network of the test services, so WordPress can reach them by name.
// Run after `npm run env:start` and `npm run services:up`.
const { execFileSync } = require('child_process');

const network = 'backup-guard-tests';
const names = execFileSync('docker', ['ps', '--format', '{{.Names}}'], { encoding: 'utf8' })
	.split(/\r?\n/)
	.filter((name) => /^wp-env-.*-(cli|wordpress)-1$/.test(name) && !name.includes('-tests-'));

if (names.length === 0) {
	console.error('Aucun conteneur wp-env en cours : lancez d\'abord `npm run env:start`.');
	process.exit(1);
}
for (const name of names) {
	try {
		execFileSync('docker', ['network', 'connect', network, name], { stdio: 'pipe' });
		console.log(name + ' relié à ' + network);
	} catch (error) {
		// Already connected is fine.
		if (!/already exists/i.test(String(error.stderr))) {
			console.error(String(error.stderr));
			process.exit(1);
		}
		console.log(name + ' déjà relié à ' + network);
	}
}

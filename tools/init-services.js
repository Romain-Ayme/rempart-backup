// Prépare les services de test qui ne sont pas utilisables dès leur démarrage :
// Garage a besoin d'une disposition de stockage, d'un bucket et d'une clé.
//   node tools/init-services.js        (après `docker compose up -d`, voir `npm run services:up`)
const { execFileSync } = require('child_process');
const path = require('path');

const compose = ['compose', '-f', path.join(__dirname, '..', 'tests', 'docker-compose.yml')];
const garage = (...args) => execFileSync('docker', [...compose, 'exec', '-T', 's3', '/garage', ...args], { encoding: 'utf8' });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
	// Garage met quelques secondes à répondre.
	let id = '';
	for (let i = 0; i < 30 && !id; i++) {
		try {
			id = garage('node', 'id', '-q').trim().split('@')[0];
		} catch (error) {
			await sleep(2000);
		}
	}
	if (!id) {
		throw new Error('Garage ne répond pas.');
	}
	try {
		garage('layout', 'assign', '-z', 'dc1', '-c', '1G', id);
		garage('layout', 'apply', '--version', '1');
	} catch (error) {
		// Déjà configuré lors d'un lancement précédent.
	}
	await sleep(2000);
	try {
		garage('bucket', 'create', 'backup-guard-it');
		garage('key', 'import', '--yes', '-n', 'backup-guard-it', 'GK00000000000000000000000a', '0000000000000000000000000000000000000000000000000000000000000001');
		garage('bucket', 'allow', '--read', '--write', '--owner', 'backup-guard-it', '--key', 'backup-guard-it');
	} catch (error) {
		console.error(String(error.stderr || error.message));
		throw error;
	}
	console.log('Garage prêt : bucket backup-guard-it.');
}

main().catch((error) => {
	console.error(error.message);
	process.exit(1);
});

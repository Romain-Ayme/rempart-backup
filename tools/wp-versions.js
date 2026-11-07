// Lists the WordPress versions to test: the latest release of every branch from `minimum` to `branch`
// (see tests/wordpress.json). When a new branch comes out, change `branch` (and `minimum` to drop old ones).
//   node tools/wp-versions.js          -> {"include":[{"wp":"7.0.8","zip":"https://…"},…]} for a CI matrix
const fs = require('fs');
const path = require('path');
const https = require('https');

const config = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'tests', 'wordpress.json'), 'utf8'));

function get(url) {
	return new Promise((resolve, reject) => {
		https.get(url, { headers: { 'User-Agent': 'rempart-backup-ci' } }, (response) => {
			let body = '';
			response.on('data', (chunk) => { body += chunk; });
			response.on('end', () => (response.statusCode === 200 ? resolve(JSON.parse(body)) : reject(new Error('HTTP ' + response.statusCode))));
		}).on('error', reject);
	});
}

const parse = (version) => version.split('.').map(Number);
const compare = (a, b) => {
	const [x, y] = [parse(a), parse(b)];
	for (let i = 0; i < Math.max(x.length, y.length); i++) {
		if ((x[i] || 0) !== (y[i] || 0)) {
			return (x[i] || 0) - (y[i] || 0);
		}
	}
	return 0;
};
const branchOf = (version) => version.split('.').slice(0, 2).join('.');

get('https://api.wordpress.org/core/stable-check/1.0/').then((all) => {
	const releases = Object.keys(all).filter((version) => /^\d+\.\d+(\.\d+)?$/.test(version));
	const wanted = new Map();
	for (const version of releases) {
		const branch = branchOf(version);
		if (compare(branch, config.minimum) >= 0 && compare(branch, config.branch) <= 0) {
			if (!wanted.has(branch) || compare(version, wanted.get(branch)) > 0) {
				wanted.set(branch, version);
			}
		}
	}
	const include = [...wanted.values()].sort(compare).map((wp) => ({ wp, zip: 'https://wordpress.org/wordpress-' + wp + '.zip' }));
	if (include.length === 0) {
		throw new Error('Aucune version de WordPress entre ' + config.minimum + ' et ' + config.branch);
	}
	console.log(JSON.stringify({ include }));
}).catch((error) => {
	console.error(error.message);
	process.exit(1);
});

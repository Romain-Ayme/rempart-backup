// Packs the plugin into dist/rempart-backup.zip, ready for WordPress's "Upload plugin" screen.
// Checks first that the files the plugin can't start without are there, so a bad package fails here.
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const pluginDir = path.join(__dirname, '..');
const output = path.join(pluginDir, 'dist', 'rempart-backup.zip');
const folder = 'rempart-backup';

const required = [
	'rempart-backup.php', 'uninstall.php', 'readme.txt', 'runner/restore-runner.php',
	'includes/class-job.php', 'includes/class-restore.php', 'includes/class-admin.php',
	'includes/storage/class-oauth.php', 'assets/admin.js', 'assets/admin.css', 'vendor/autoload.php',
];
for (const file of required) {
	if (!fs.existsSync(path.join(pluginDir, file))) {
		console.error('Fichier manquant : ' + file);
		process.exit(1);
	}
}

// Development files are not shipped; the test tools and their dependencies are not even read.
const skip = new Set(['composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.wp-env.json', '.gitignore', 'phpcs.xml.dist']);
const developmentOnly = new Set(['.git', '.github', 'tests', 'tools', 'dist']);
function walk(dir, base) {
	let out = [];
	for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
		if (skip.has(item.name) || (base === folder && developmentOnly.has(item.name))) {
			continue;
		}
		const full = path.join(dir, item.name);
		const relative = base + '/' + item.name;
		// OneDrive folders show up as links to Node: ask the file system what they really are.
		const isDirectory = item.isSymbolicLink() ? fs.statSync(full).isDirectory() : item.isDirectory();
		out = out.concat(isDirectory ? walk(full, relative) : [[full, relative]]);
	}
	return out;
}

const crcTable = Array.from({ length: 256 }, (_, n) => {
	let c = n;
	for (let k = 0; k < 8; k++) {
		c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	}
	return c >>> 0;
});
function crc32(data) {
	let c = 0xffffffff;
	for (const byte of data) {
		c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
	}
	return (c ^ 0xffffffff) >>> 0;
}

const locals = [];
const centrals = [];
let offset = 0;
const files = walk(pluginDir, folder);
for (const [full, relative] of files) {
	const data = fs.readFileSync(full);
	const packed = zlib.deflateRawSync(data, { level: 9 });
	const name = Buffer.from(relative, 'utf8');
	const crc = crc32(data);

	const local = Buffer.alloc(30);
	local.writeUInt32LE(0x04034b50, 0);
	local.writeUInt16LE(20, 4);
	local.writeUInt16LE(0x0800, 6); // Names are UTF-8.
	local.writeUInt16LE(8, 8);
	local.writeUInt32LE(crc, 14);
	local.writeUInt32LE(packed.length, 18);
	local.writeUInt32LE(data.length, 22);
	local.writeUInt16LE(name.length, 26);
	locals.push(local, name, packed);

	const central = Buffer.alloc(46);
	central.writeUInt32LE(0x02014b50, 0);
	central.writeUInt16LE(20, 4);
	central.writeUInt16LE(20, 6);
	central.writeUInt16LE(0x0800, 8);
	central.writeUInt16LE(8, 10);
	central.writeUInt32LE(crc, 16);
	central.writeUInt32LE(packed.length, 20);
	central.writeUInt32LE(data.length, 24);
	central.writeUInt16LE(name.length, 28);
	central.writeUInt32LE(offset, 42);
	centrals.push(central, name);

	offset += local.length + name.length + packed.length;
}

const centralData = Buffer.concat(centrals);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(files.length, 8);
end.writeUInt16LE(files.length, 10);
end.writeUInt32LE(centralData.length, 12);
end.writeUInt32LE(offset, 16);

fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, Buffer.concat([...locals, centralData, end]));
console.log(files.length + ' fichiers emballés dans dist/rempart-backup.zip (' + Math.round(fs.statSync(output).size / 1024) + ' Ko)');

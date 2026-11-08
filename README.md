# Backup Guard

Par [Karom Studio](https://karom.studio) - Romain AYME.

Plugin WordPress de sauvegarde, restauration, migration et clonage, **sans licence et sans fonction réservée**.

- PHP 8.3 ou plus, WordPress 7.1 ou plus.
- Licence LGPL v2.1.

## Fonctionnalités

### Langues

Le plugin est écrit en français. Un site en français affiche les textes tels qu'ils sont dans le code ; tous les autres sites (anglais et autres langues) reçoivent la traduction anglaise, `languages/backup-guard-en_US.mo`. Les textes sont traduisibles avec les fonctions WordPress habituelles (`__()`, `esc_html__()`, etc.), domaine `backup-guard`.

### Sauvegarde

- Fichiers, base de données, ou les deux.
- **Fichiers du site** (les vôtres : `wp-content`, `wp-config.php`, `.htaccess`, tout fichier que WordPress ne fournit pas) et, en option (dans « Options supplémentaires »), **Fichiers de WordPress** (`wp-admin`, `wp-includes`, `index.php`, etc.). Par défaut, cette seconde case n'est pas cochée : la sauvegarde est plus légère et se restaure sur un WordPress déjà installé, de la même version. Les empreintes officielles de votre version viennent de wordpress.org : si elles sont injoignables, la sauvegarde est refusée tout de suite plutôt que de produire une archive incomplète.
- Exclusions : tables, dossiers exacts, extensions, début ou fin de nom, motif n'importe où.
- Niveau de compression réglable (1 à 9, 9 par défaut).
- **Verrouillage** : un cadenas protège une sauvegarde de la suppression (bouton, sélection multiple, WP-CLI) et de la rotation. Une sauvegarde verrouillée ne compte pas dans le nombre à conserver.
- Note libre sur chaque sauvegarde, historique filtrable par emplacement, espace utilisé par emplacement.
- Travail découpé en tranches de 25 secondes : pas de limite de temps PHP à craindre, reprise possible, arrêt à la demande.
- Une seule tâche à la fois : un second démarrage, y compris simultané, est refusé.

### Automatisation

- Planification : en mode simple, toutes les X minutes (5 au minimum) ; en mode cron, une expression comme `5 4 * * *` (tous les jours à 4 h 05, fuseau horaire du site) ; ou une tâche cron du serveur qui appelle une adresse du site.
- Rotation : ne garder que les N dernières sauvegardes automatiques (0 = toutes).
- Sauvegarde avant les mises à jour de WordPress, manuelles ou automatiques.
- Chien de garde : une tâche bloquée est relancée, puis déclarée en échec avec un message clair si elle ne répond plus.

### Emplacements

Dossier local, FTP, FTPS, SFTP (mot de passe ou clé privée, empreinte du serveur mémorisée), WebDAV, Amazon S3 et compatibles (Hetzner Object Storage, Backblaze B2, DigitalOcean Spaces, Linode, Vultr, Cloudflare R2, Wasabi, Garage, etc.), Google Drive, OneDrive, Dropbox et pCloud.

Les identifiants sont chiffrés dans la base. Google Drive, OneDrive, Dropbox et pCloud demandent une application créée à votre nom (gratuit) : l'onglet Aide du plugin donne la marche à suivre.

### Restauration, migration, clonage

- Restauration en un clic, des fichiers, de la base, ou des deux.
- Migration et clonage vers une autre adresse : les adresses sont remplacées dans la base, y compris dans les données sérialisées.
- La restauration est faite par un petit programme indépendant de WordPress, qui continue de fonctionner pendant que les fichiers et les tables de WordPress sont remplacés.
- Importe aussi les sauvegardes `.tar.gz` faites avec Backuply.

### Notifications

Au choix pour une réussite, un échec, ou les deux, par canal : e-mail, Pushover, Gotify, ntfy, Telegram, Discord, Slack et webhook JSON. Les secrets sont masqués dans les messages d'erreur.

### WP-CLI

```
wp backup-guard backup [--files-only] [--db-only] [--scope=<content|all|modified>] [--location=<id>] [--note=<texte>]
wp backup-guard list
wp backup-guard locations
wp backup-guard delete <nom>
wp backup-guard lock <nom>
wp backup-guard unlock <nom>
wp backup-guard restore <nom> [--files-only] [--db-only] [--yes]
```

## Installation

Dans WordPress : Extensions, Ajouter, Téléverser une extension, puis choisir `backup-guard.zip` et l'activer. Le zip se télécharge dans les [releases](../../releases) ou se construit avec `npm run build` (il est alors dans `dist/`).

## Développement

### Dépendances

Le plugin embarque deux bibliothèques, gérées par Composer (`composer.json` à la racine) et livrées dans `vendor/` : `phpseclib/phpseclib` 4 (SFTP) et `paragonie/constant_time_encoding` 3. Pour les mettre à jour :

```
composer update --no-dev
```

Les outils de contrôle sont à part, dans `tests/composer.json`, et ne sont pas livrés avec le plugin.

### Contrôles

```
composer --working-dir=tests install
composer --working-dir=tests run check     # tout ce qui suit
composer --working-dir=tests run lint      # syntaxe
composer --working-dir=tests run compat    # compatibilité PHP 8.3 à 8.5 (PHPCompatibilityWP)
composer --working-dir=tests run stan      # analyse statique (PHPStan niveau 3, stubs WordPress)
composer --working-dir=tests run unit      # tests unitaires (PHPUnit)
npm run lint:js                           # syntaxe du JavaScript de l'administration
```

Sans PHP installé, on peut passer par Docker :

```
docker run --rm -v "$PWD:/app" -w /app composer:2 --working-dir=tests run check
```

### Tests avec de vrais services

Ils tournent dans un WordPress de test ([wp-env](https://www.npmjs.com/package/@wordpress/env)) et des services Docker locaux (`tests/docker-compose.yml`) : SFTP, FTP, FTPS, WebDAV, S3 sur [Garage](https://garagehq.deuxfleurs.fr/), Gotify, ntfy, Mailpit, et un faux serveur (`tests/mock/`) pour Pushover, Telegram, Discord, Slack et le webhook, qui n'ont pas de version auto-hébergée : rien n'est envoyé aux vrais services.

Il faut Docker et Node 24.18 ou plus.

```
npm run services:up          # démarre les services et prépare Garage
npm run env:start            # WordPress de test
npm run test:destinations    # aller-retour (petit fichier, 5 Mo) sur chaque destination
npm run test:notifications   # un message par type de canal, e-mail compris
npm run test:backups         # sauvegardes complètes : locale, SFTP, refus, échec, arrêt, modes de fichiers
npm run test:errors          # erreurs provoquées (voir ci-dessous)
npm run test:i18n            # traductions : anglais, français, fichiers à jour avec le code
npm run services:down
npm run env:destroy
```

Les erreurs provoquées par `test:errors` : mauvais identifiants (SFTP, FTP, FTPS, WebDAV, S3), bucket inexistant, wordpress.org injoignable, deuxième sauvegarde refusée, démarrages simultanés (4 processus, un seul démarre), tâche bloquée relancée par le chien de garde, tâche perdue déclarée en échec, réseau coupé pendant l'envoi d'un gros fichier, fichier illisible, archive tronquée, sauvegarde inconnue, restauration de la base réussie.

Un service injoignable est un échec. Pour tester une seule destination en local, mettre `IT_SKIP_UNREACHABLE=1`.

Choisir les versions de PHP et de WordPress avant `npm run env:start` :

```
WP_ENV_PHP_VERSION=8.4
WP_ENV_CORE=https://wordpress.org/wordpress-7.1.4.zip
```

### Traductions

Les textes sont écrits en français dans le code. Après avoir ajouté ou modifié un texte :

1. `npm run i18n:build` (avec `npm run env:start` lancé) régénère `languages/backup-guard.pot` et recompile les `.mo`.
2. Ajouter la traduction anglaise du nouveau texte dans `languages/backup-guard-en_US.po` (un texte sans traduction ou des `%s` qui ne correspondent pas font échouer `npm run test:i18n`).
3. `npm run i18n:build` une seconde fois pour recompiler le `.mo`.

Les textes du script de l'administration (`assets/admin.js`) passent par `T('texte')` et la liste `js_strings()` de `includes/class-admin.php`. Le programme de restauration n'a pas WordPress : ses messages utilisent `__()` et sont traduits au lancement de la restauration.

### Intégration continue (GitHub Actions)

À chaque pull request et à chaque push sur `main` (`.github/workflows/ci.yml`) :

| Job | Ce qu'il fait |
|---|---|
| Contrôles PHP 8.3 / 8.4 / 8.5 | syntaxe, compatibilité, PHPStan, PHPUnit |
| Paquet du plugin | construit le zip et le garde comme artefact |
| Services sur WordPress / PHP | destinations, notifications, sauvegardes et erreurs, dans wp-env avec les services Docker |

Les versions testées sont dans `tests/wordpress.json` : `branch` (la branche de WordPress la plus récente), `minimum`, et `php` (la première version de PHP pour chaque WordPress, les suivantes pour le plus récent). La CI en déduit la matrice avec `tools/wp-versions.js`, qui prend la dernière version corrective de chaque branche. **Quand WordPress 7.2 sort, il suffit de changer `branch`** (et `minimum` pour abandonner une branche).

Les composants Composer et les actions GitHub sont surveillés chaque semaine par Dependabot (`.github/dependabot.yml`).

### Travailler avec des branches

On ne pousse pas sur `main` : on crée une branche, on ouvre une pull request, et on fusionne quand la CI est verte.

### Release

1. Changer la version à deux endroits, qui doivent être identiques : `Version:` dans `backup-guard.php` (et `BACKUP_GUARD_VERSION`) et `Stable tag:` dans `readme.txt`.
2. Fusionner sur `main`, puis créer et pousser le tag :

   ```
   git tag v1.0.1
   git push origin v1.0.1
   ```

3. Le workflow `.github/workflows/release.yml` vérifie que le tag, `Version:` et `Stable tag:` concordent, construit `backup-guard.zip` et crée la release GitHub avec les notes générées.

### Organisation du dépôt

```
backup-guard.php     point d'entrée du plugin
includes/              le plugin (tâches, archive tar.gz, destinations, notifications, admin, WP-CLI)
includes/storage/      un fichier par type d'emplacement
runner/                programme de restauration, indépendant de WordPress
assets/                CSS et JS de l'administration
vendor/                bibliothèques embarquées (Composer)
tests/                 contrôles, tests unitaires, tests d'intégration, services Docker
tools/                 construction du zip, versions de WordPress, préparation des services
.github/               CI, release, Dependabot
```

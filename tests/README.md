# Contrôles du plugin

Ce dossier contient les outils de contrôle du plugin. Il n'est pas livré avec le plugin.

- `lint.php` : vérifie la syntaxe de chaque fichier PHP du plugin.
- `phpcs.xml.dist` : compatibilité avec PHP 8.3 à 8.5 (PHPCompatibilityWP).
- `phpstan.neon` : analyse statique (PHPStan, niveau 3, avec les stubs WordPress).
- `unit/` : tests unitaires (PHPUnit).

## Lancer les contrôles

Les dépendances de développement sont dans `tests/composer.json`, installées dans `tests/vendor/`.

```
composer --working-dir=tests install
composer --working-dir=tests run check     # tout
composer --working-dir=tests run lint      # syntaxe seule
composer --working-dir=tests run compat    # compatibilité PHP 8.3 à 8.5
composer --working-dir=tests run stan      # analyse statique
composer --working-dir=tests run unit      # tests unitaires
```

Il faut PHP 8.3 ou plus et Composer. Sans PHP installé, on peut passer par Docker :

```
docker run --rm -v "$PWD:/app" -w /app composer:2 --working-dir=tests run check
```

## Dépendances du plugin

Les bibliothèques livrées (phpseclib, constant_time_encoding) sont dans `composer.json` à la racine,
installées avec `composer install --no-dev`. Le script `tools/build-zip.js` n'embarque pas `tests/`.

## Tests avec de vrais services

Ils tournent dans un WordPress de test (wp-env) et des services Docker locaux (`tests/docker-compose.yml`) :
SFTP, FTP, FTPS, WebDAV, S3 (Garage), Gotify, ntfy, Mailpit et un faux serveur (`tests/mock/`) pour Pushover,
Telegram, Discord, Slack et le webhook, qui n'ont pas de version auto-hébergée : rien n'est envoyé aux vrais services.

```
npm run services:up          # démarre les services et prépare Garage
npm run env:start            # WordPress de test (PHP et WordPress au choix, voir ci-dessous)
npm run test:destinations    # aller-retour sur chaque destination
npm run test:notifications   # un message par type de canal
npm run services:down
```

Choisir les versions : `WP_ENV_PHP_VERSION=8.4` et `WP_ENV_CORE=https://wordpress.org/wordpress-7.0.8.zip`
avant `npm run env:start`. La CI les fait varier selon `tests/wordpress.json`.

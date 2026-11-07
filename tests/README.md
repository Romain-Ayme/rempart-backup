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

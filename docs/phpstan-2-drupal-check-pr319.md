# PHPStan 2 et drupal-check : override temporaire basé sur upstream #319

## Décision

L'issue #362 conserve temporairement `mglaman/drupal-check` 1.5.0 pendant la migration vers PHPStan 2 et `mglaman/phpstan-drupal` 2.x. Cette exception a été explicitement acceptée le 14 août 2026.

La release officielle `drupal-check` 1.5.0 impose encore des dépendances PHPStan 1.x. L'upstream PR `mglaman/drupal-check#319` (`update to phpstan 2`) propose les métadonnées permettant PHPStan 2, mais elle est encore non mergée. Elle sert de référence technique, pas de source de code mutable.

## Implémentation

Le projet continue d'installer le code officiel du tag `mglaman/drupal-check` 1.5.0, pinné au commit `4011f1f357bdd89793d13b1f8536625eb9d3cce7`.

Un repository Composer root-only de type `package` surcharge uniquement les métadonnées de cette version selon #319 : PHP >= 8.1, `phpstan-drupal` 1.x ou 2.x, `phpstan-deprecation-rules` 1.x ou 2.x et `phpstan-prophecy` 1.x ou 2.x. Un patch `composer-patches` sur le `composer.json` de la dépendance ne conviendrait pas : il serait appliqué après la résolution des dépendances.

## Diagnostics PHPStan 2

`phpstan-drupal` 2.x a révélé des incompatibilités réelles qui sont corrigées dans #362 :

- signature de `hook_entity_operation()` depuis Drupal 11.3 ;
- propriétés héritant de `DependencySerializationTrait` qui ne peuvent pas être `private` et, avec la plateforme PHP 8.3, ne doivent pas être `readonly` lorsqu'elles sont restaurées par un parent ;
- visibilité des propriétés de deux faux objets `Key` dans les tests.

PHPStan 2 peut également prouver statiquement certaines vérifications défensives grâce aux métadonnées Drupal/PHPDoc. Ces contrôles runtime sont conservés. Les exceptions de `phpstan.neon` sont limitées à leurs identifiants et fichiers précis ; il n'y a ni baseline ni ignore global du code applicatif.

Les assertions PHPUnit statiquement redondantes sont également conservées pour leur valeur de régression runtime et l'exception correspondante est limitée aux répertoires de tests.

## Garanties

- aucun fork long terme de `drupal-check` ;
- aucun HEAD de PR mutable installé ;
- code `drupal-check` officiel 1.5.0 pinné ;
- aucune baseline PHPStan ;
- `lint:phpstan` et `lint:drupal-check` restent des gates ;
- plateforme Composer PHP 8.3 inchangée ; CI PHP 8.4 inchangée.

## Retrait de l'override

Dès qu'une release stable officielle de `mglaman/drupal-check` accepte PHPStan 2 / `mglaman/phpstan-drupal` 2.x avec une compatibilité équivalente :

1. remettre `mglaman/drupal-check` sur une contrainte de release normale ;
2. supprimer le repository `package` d'override ;
3. mettre à jour `composer.lock` ;
4. revalider Composer, audit, PHPCS, PHPStan, drupal-check et la CI complète.

## Références

- projet : #362 ;
- upstream : `mglaman/drupal-check#319` ;
- upstream liée : `mglaman/drupal-check#318`.

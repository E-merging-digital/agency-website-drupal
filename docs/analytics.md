# Analytics (GA4) via Drupal Google Tag + Config Split

## Pourquoi `google_tag` et pas `google_analytics`

Le projet utilise le module **Google Tag** (`drupal/google_tag`) car il est aligné avec les usages GA4 récents et la gestion moderne des tags côté Google.
Le module historique `google_analytics` n'est pas retenu ici pour éviter une approche legacy.

> Règle projet : **aucun script GA4 en dur dans Twig** et **aucun JS custom** pour injecter le tracking.

## Obtenir un Measurement ID GA4 (`G-XXXXXXXXXX`)

1. Ouvrir Google Analytics.
2. Aller dans **Admin** (roue dentée).
3. Dans la colonne **Property**, ouvrir **Data streams**.
4. Choisir le flux Web du site.
5. Copier le **Measurement ID** au format `G-XXXXXXXXXX`.

## Configuration Drupal retenue

- Le module `google_tag` est activé dans la config Drupal.
- La configuration `google_tag.container.default` existe en base mais est **inactive par défaut** dans `config/sync` (local/DDEV).
- Le split `production` porte la configuration active `google_tag.settings` et
  le conteneur `google_tag.container.G-K5TDNZCPTY.69f8b7287a84a3.47771255`.

Valeur placeholder utilisée si l'ID réel n'est pas encore fourni :

- `G-XXXXXXXXXX`

ID actuellement prévu pour la production : `G-K5TDNZCPTY` (à conserver uniquement dans la config de split production).

## Format de configuration attendu (`google_tag` 2.x)

Le module `google_tag` en version 2.x lit les identifiants via la clé `tag_container_ids`.
Le format historique `container_id` (et `html_container`) est obsolète et n'est pas appliqué dans cette version.
La configuration exportée en 2.x inclut aussi des blocs comme `weight`, `advanced_settings` et `dimensions_metrics` : conserver cette structure évite un état `Different` après `drush cim`.

Exemple attendu pour GA4 en production :

```yaml
tag_container_ids:
  - G-K5TDNZCPTY
```

⚠️ Attention : selon la version majeure du module, la structure YAML peut différer. Toujours vérifier le schéma attendu par la version installée avant de modifier la config.

## Activation du split production

Le split concerné est : `config_split.config_split.production`.

- Dans `config/sync`, ce split reste avec `status: false`.
- Dans le `settings.php` versionné, ce split est explicitement désactivé pour
  garantir l'absence de tracking en local/DDEV.
- En production uniquement, le `settings.php` propre au serveur doit activer le
  split avec :

```php
$config['config_split.config_split.production']['status'] = TRUE;
```

Ce mécanisme permet de conserver une base locale/dev sûre (tracking désactivé), puis d'activer GA4 uniquement en production au moment du `drush cim`.

### Important

- `settings.php` de production n'est **pas versionné** dans ce dépôt.
- La ligne d'activation doit rester dans le `settings.php` de production, pas
  dans le `settings.php` local/versionné.
- En local (DDEV), le split `production` doit rester désactivé.

### Effet attendu

- En production (split activé) : Google Tag est injecté selon la config du split.
- En local/DDEV (split désactivé) : aucun script Google Tag n'est injecté.

## Vérifier que le script est présent en production

Méthodes recommandées :

1. Ouvrir la home en production puis inspecter le HTML rendu.
2. Vérifier la présence des marqueurs Google Tag (`gtag` / chargement Google tag).
3. Utiliser Tag Assistant (ou DevTools réseau) pour confirmer le chargement.
4. Après déploiement, contrôler aussi les pages publiques FR/EN avec :

```powershell
(Invoke-WebRequest "https://emergingdigital.be/fr").Content | Select-String "googletagmanager|gtag|G-K5TDNZCPTY"
(Invoke-WebRequest "https://emergingdigital.be/en").Content | Select-String "googletagmanager|gtag|G-K5TDNZCPTY"
```

## Vérifier qu'il est absent en local/DDEV

1. Ouvrir la home en local.
2. Inspecter le HTML source.
3. Vérifier qu'aucun script Google Tag/GA4 n'est injecté.

## Désactiver le tracking

Deux options propres :

1. Désactiver le split `production` pour l'environnement concerné.
2. Ou forcer `status: false` sur `google_tag.container.default` dans la config appliquée.

## Rappel important

- **Ne jamais** coder GA4 en Twig.
- **Ne jamais** injecter GA4 via JS custom.
- Toute gestion Analytics doit passer par la config Drupal + Config Split.

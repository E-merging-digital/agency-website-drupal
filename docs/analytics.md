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
- Le split `production` porte la version active de `google_tag.container.default` avec un ID de mesure.

Valeur placeholder utilisée si l'ID réel n'est pas encore fourni :

- `G-XXXXXXXXXX`

## Config Split production

Le split `production` doit être activé uniquement en environnement de production (via `settings.php`/`settings.prod.php` selon la convention projet).

Quand le split est actif :

- `google_tag.container.default` est surchargé avec `status: true`
- `container_id` contient l'ID GA4 réel

Quand le split est inactif (local/DDEV) :

- `google_tag.container.default` reste `status: false`
- aucun script Google Tag n'est injecté

## Vérifier que le script est présent en production

Méthodes recommandées :

1. Ouvrir la home en production puis inspecter le HTML rendu.
2. Vérifier la présence des marqueurs Google Tag (`gtag` / chargement Google tag).
3. Utiliser Tag Assistant (ou DevTools réseau) pour confirmer le chargement.

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

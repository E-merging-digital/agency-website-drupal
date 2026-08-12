# Drupal 12/13 upstream deprecation inventory

Date de référence : 12 août 2026.

Cette note suit les dépréciations observées par les tests du projet sous Drupal 11.4.5. Elle ne constitue pas une autorisation de patcher directement `web/core`, `web/modules/contrib` ou `vendor`.

## Règle de traitement

- Une correction locale n'est appliquée que si elle passe par une API/configuration projet supportée ou une release stable compatible.
- Aucun warning n'est masqué globalement pour obtenir un faux GREEN.
- Un warning sans correction locale supportée reste documenté comme dépendance upstream.
- Les suppressions Drupal 12 sont prioritaires par rapport aux suppressions Drupal 13.

## Baseline observée

Après #361 / PR #364, les validations locales restent GREEN mais déclenchent notamment :

- `test:homepage-smoke` : 17 dépréciations ;
- `test:contact` : 45 dépréciations ;
- `test:project-functional` : 20 dépréciations.

Les deux dépréciations appartenant directement au projet ont été retirées dans #361 : le faux test du module core Contact et l'annotation `@Block` du chatbot custom.

## Drupal 12 — blockers potentiels

| Dépréciation | Propriétaire / version observée | État upstream au 12/08/2026 | Correction projet supportée maintenant | Décision |
| --- | --- | --- | --- | --- |
| `google_tag_module_implements_alter` | `drupal/google_tag` 2.0.9 | 2.0.9 est la stable observée ; ticket Drupal 12 dédié en `Needs review` sur `2.0.x-dev` | Non | Attendre une stable contenant le correctif ; ne pas patcher contrib |
| `metatag_module_implements_alter` | `drupal/metatag` 2.2.0 | 2.2.0 est la stable observée ; warning toujours reproduit | Non démontrée | Surveiller upstream ; ne pas modifier contrib |
| `paragraphs_module_implements_alter` | `drupal/paragraphs` 1.23.0 | warning toujours reproduit sur la version installée | Non démontrée | Surveiller upstream ; ne pas modifier contrib |
| `plugin.manager.archiver` / `Drupal\Core\Archiver\ArchiverManager` | Drupal core 11.4.5 | Core déprécie explicitement ces API en 11.3 pour suppression en 12 ; pas de remplacement générique | Non dans le projet : apparition observée dans l'infrastructure BrowserTestBase | Classer core/test-infrastructure ; ne pas ajouter d'usage projet |
| `llms_txt.tokens.inc` autoloading hooks | `drupal/llms_txt` 1.0.7 | 1.0.7 est la stable observée ; tâche de compatibilité Drupal 12 en `Needs review` sur `1.x-dev` | Non | Attendre une stable |
| Webform managers sans attribute discovery | `drupal/webform` 6.3.0 | 6.3.0 est la stable Drupal 11 ; tâche automatisée de compatibilité Drupal 12 en `Needs review` sur la branche de développement | Non | Attendre une stable compatible |
| anciens preprocess/theme hooks Webform | `drupal/webform` 6.3.0 | couverts par le chantier Drupal 12 upstream encore en review | Non | Attendre upstream |
| `webform.tokens.inc` autoloading hooks | `drupal/webform` 6.3.0 | couvert par le chantier Drupal 12 upstream | Non | Attendre upstream |
| `template_preprocess_form()` | Drupal core / chemin Webform | suppression annoncée en Drupal 12 ; déclenché pendant le rendu Webform | Non sans modifier upstream | Classer core/contrib |
| accès à l'ancienne propriété entity `original` | chemin Webform/core | suppression annoncée en Drupal 12 | Non démontrée | Attendre upstream |

## Drupal 13 — dette à suivre, non bloquante pour Drupal 12 immédiat

| Dépréciation | Propriétaire / version observée | Décision actuelle |
| --- | --- | --- |
| `ai_requirements` | `drupal/ai` 1.4.6 | Surveiller release upstream ; pas de patch local |
| `google_tag_requirements` | `drupal/google_tag` 2.0.9 | Surveiller upstream |
| `metatag_requirements` | `drupal/metatag` 2.2.0 | Surveiller upstream |
| `quicklink_requirements` | `drupal/quicklink` 3.0.0 | Surveiller upstream |
| `simple_sitemap_requirements` | `drupal/simple_sitemap` 4.2.3 | Surveiller upstream |
| `token_requirements` | `drupal/token` 1.17.0 | Une tâche de compatibilité Drupal 12 est déjà en cours upstream ; surveiller prochaine stable |
| `webform_requirements` | `drupal/webform` 6.3.0 | Surveiller chantier de compatibilité upstream |
| `cache.static` | Drupal core/test infrastructure | Aucun usage custom identifié ; ne pas ajouter de contournement projet |
| `cache.backend.memory` | Drupal core/test infrastructure | Aucun usage custom identifié ; ne pas ajouter de contournement projet |
| `@EntityType` `llms_txt_section` | `drupal/llms_txt` 1.0.7 | Surveiller la tâche de compatibilité Drupal 12/13 upstream |
| annotations Webform `@EntityType`, `@FieldType`, `@Action`, `@Mail`, `@EntityReferenceSelection` | `drupal/webform` 6.3.0 | Surveiller upstream ; pas de patch contrib |

## Releases stables déjà vérifiées

Au moment de l'inventaire, le projet utilise déjà les stables pertinentes observées pour les principaux paquets concernés :

- Google Tag 2.0.9 ;
- Metatag 2.2.0 ;
- llms_txt 1.0.7 ;
- Webform 6.3.0 ;
- Paragraphs 1.23.0 selon le lock du projet.

Il n'existe donc pas, pour ces warnings, de simple mise à jour stable actuellement démontrée qui réduirait immédiatement la baseline.

## Corrections locales retenues

À cette étape : **aucune correction de code/configuration projet supplémentaire n'est justifiée**.

Les warnings restant appartiennent au core, à son infrastructure de test ou à des modules contrib. Les supprimer localement nécessiterait soit de modifier upstream, soit de masquer la dépréciation, deux options interdites par #363.

## Conditions de reprise

Réévaluer cet inventaire lors de toute nouvelle release stable des paquets concernés, en priorité avant une qualification Drupal 12. Pour chaque nouvelle stable :

1. mettre à jour via Composer sur une branche dédiée ou dans #363 si elle est encore active ;
2. rejouer `composer audit`, PHPCS, PHPStan, drupal-check et les tests fonctionnels ;
3. mesurer le delta de dépréciations ;
4. conserver uniquement les mises à jour compatibles et réellement utiles.

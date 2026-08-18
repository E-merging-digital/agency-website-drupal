# Inventaire SDC gouverné — #519

Statut : **IMPLEMENTATION EN COURS — CANDIDATES**  
Décision : `docs/decisions/ADR-001-governed-ai-experience.md`  
Contrat : `DESIGN.md`  
Registre d’admission : `docs/design-system/component-catalog.yml`

## Objectif

Ce document inventorie les primitives Paragraphs/Twig existantes et matérialise le premier vertical slice SDC sans migration big-bang.

Invariant : **COMPOSE BEFORE CREATE**.

Un composant déclaré `candidate` n’est pas disponible pour une composition IA destinée à publication. Seul le statut `approved`, après les preuves définies dans le registre, autorise cette composition.

## Inventaire actuel

| Primitive existante | Template actuel | Classification | Décision #519 |
| --- | --- | --- | --- |
| Hero | `paragraph--hero.html.twig` | `WRAP_OR_MIGRATE_TO_SDC` | **Vertical slice** : adapter le Paragraph vers `emerging_digital:hero`. |
| CTA | `paragraph--cta.html.twig` | `WRAP_OR_MIGRATE_TO_SDC` | **Vertical slice** : adapter le Paragraph vers `emerging_digital:cta`. |
| Trust list | `paragraph--trust-list.html.twig` | `WRAP_OR_MIGRATE_TO_SDC` | **Vertical slice** : liste textuelle strictement typée, sans HTML libre. |
| Text block | `paragraph--text-block.html.twig` | `KEEP_AS_IS` | Différé : le template contient encore le webform contact et des variantes couplées à des UUID. Ne pas mélanger cette dette avec le slice SDC. |
| Services | `paragraph--services.html.twig` | `KEEP_AS_IS` puis réévaluation | Le rendu dépend d’un preprocess de classification/groupement métier. Extraire le contrat séparément avant migration. |
| AI features | `paragraph--ai-features.html.twig` | `WRAP_OR_MIGRATE_TO_SDC` plus tard | Réutilisable, mais non nécessaire pour prouver le premier catalogue. |
| Case clients | `paragraph--case-clients.html.twig` | `WRAP_OR_MIGRATE_TO_SDC` plus tard | Réutilisable, mais plus riche que le slice minimal retenu. |

## Pourquoi Hero + CTA + Trust list

Ce trio couvre trois contrats différents sans refonte visuelle :

1. **Hero** : props gouvernées + slots de contenu/actions + media/fallback ;
2. **CTA** : section de conversion avec slots Drupal existants ;
3. **Trust list** : données structurées simples et strictement typées.

Les SDC réutilisent les classes CSS actuelles. Aucun nouveau framework CSS, aucune nouvelle palette et aucune génération de markup arbitraire ne sont introduits.

## Baseline Canvas

Les métadonnées suivent les schémas SDC natifs et les contraintes actuelles de Canvas :

- titre humain pour chaque prop ;
- `examples` sur les props nécessaires à l’initialisation ;
- enums pour les variantes gouvernées ;
- `minItems: 1` pour la liste requise ;
- `uri-reference` pour l’URL d’illustration ;
- slots pour les renderables Drupal dont la structure ne doit pas être aplatie en chaînes HTML.

Cette baseline **n’installe pas Canvas** et ne dépend pas d’un `$ref` fourni uniquement par Canvas. Elle rend les composants prêts à être évalués par Canvas sans créer un format parallèle.

## Admission

Cycle obligatoire :

```text
candidate
-> schéma/test de contrat
-> CI hosted
-> Playwright desktop + mobile
-> sémantique/accessibilité de base
-> revue humaine
-> approved
```

La preuve navigateur cible `/fr/ia-drupal`, qui contient déjà les trois Paragraphs concernés dans le Default Content du repository. Le test vérifie les trois identités de composants, les rôles sémantiques principaux, l’absence de débordement horizontal et les erreurs console/page.

Le passage à `approved` doit inscrire la référence du run navigateur exact dans `component-catalog.yml`. Tant que `browser_run: pending`, `approved_for_ai_composition` reste `false`.

## Suite après admission

Après admission des trois composants seulement :

1. vérifier leur découverte/éligibilité dans une installation Canvas bornée ;
2. prouver une composition visuelle avec **uniquement** des composants `approved` ;
3. ouvrir un gap distinct si Canvas nécessite une métadonnée ou un adapter manquant ;
4. ne commencer Canvas AI qu’après cette preuve déterministe.

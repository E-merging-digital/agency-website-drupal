# Baseline Guardrails Drupal AI

Issue : #379  
Statut : baseline déterministe du projet

## Objectif

Le site applique un garde-fou minimal avant les appels qui passent par
l'abstraction Drupal AI. Cette baseline limite la taille d'une entrée sans
ajouter d'appel LLM, sans dépendre d'un modèle de tokenisation et sans modifier
le workflow éditorial Drupal.

Cette protection complète les permissions, révisions et validations humaines ;
elle ne les remplace pas.

## Configuration versionnée

Le guardrail `agency_editorial_input_length` utilise le plugin AI Core
`input_length_limit` avec :

- limite : **20 000 caractères** ;
- comptage : caractères, pas tokens ;
- portée : dernier message uniquement ;
- phase : pre-generate uniquement ;
- seuil du set : `1.0` ;
- set global : `agency_editorial_baseline`.

Fichiers :

```text
config/sync/ai.ai_guardrail.agency_editorial_input_length.yml
config/sync/ai.ai_guardrail_set.agency_editorial_baseline.yml
config/sync/ai.settings.yml
```

La limite de 20 000 caractères est volontairement permissive pour un usage
éditorial : elle ne doit pas bloquer un fragment d'article réaliste, tout en
arrêtant les entrées anormalement volumineuses avant le provider. Elle pourra
être ajustée sur preuve après les premiers usages AI CKEditor/Automators.

Le comptage par tokens reste désactivé afin que ce guardrail ne dépende ni d'un
modèle ni d'une approximation de tokenizer spécifique au provider.

## Portée globale

`ai.settings:global_guardrails` référence `agency_editorial_baseline`. Les appels
qui passent par Drupal AI héritent donc de cette baseline sans que chaque
intégration doive sélectionner manuellement le set.

Le set ne contient aucun guardrail post-generate dans cette première version.
Il s'agit d'une limite de coût/contexte d'entrée, pas d'un filtre sémantique de
sortie.

## Guardrails explicitement non activés

`Restrict to Topic` n'est pas activé dans cette baseline. Ce guardrail est
non déterministe et utilise lui-même un provider LLM pour classifier le sujet.
Il ajouterait coût et latence sans besoin projet démontré pour les usages
éditoriaux authentifiés.

Aucun regexp générique de prompt injection n'est ajouté par défaut : ces règles
ont un risque de faux positifs et doivent répondre à une menace ou un point
d'entrée concret avant activation.

## Permissions

La gestion des guardrails reste une responsabilité d'administration Drupal.
Le rôle versionné `content_editor` ne reçoit pas :

```text
administer guardrails
administer guardrail sets
```

Les éditeurs utilisent les fonctionnalités IA autorisées sans pouvoir modifier
la politique de garde-fous.

## Limite connue : traduction legacy

`agency_ai_translation` contient encore un client HTTP provider direct. Les
appels effectués par cette implémentation ne traversent pas l'abstraction Drupal
AI et ne sont donc **pas couverts** par cette baseline.

Cette exception est temporaire et doit être traitée par la preuve de parité
AI Translate prévue dans #382. Ne pas étendre le client provider direct legacy
avec de nouveaux usages.

## Dégradation et rollback

Une violation de longueur doit arrêter l'appel avant provider et retourner un
message contrôlé. Elle ne doit pas empêcher l'éditeur de continuer à modifier ou
sauvegarder son contenu Drupal manuellement.

Pour désactiver la baseline globale sans supprimer les entités de configuration,
retirer `agency_editorial_baseline` de `ai.settings:global_guardrails`, exporter
la configuration et repasser les validations.

## Validation

Tests versionnés :

- `AiGuardrailsConfigTest` vérifie le contrat YAML, la portée globale et les
  permissions du rôle éditorial ;
- `AiInputLengthGuardrailTest` exécute directement le plugin déterministe, sans
  provider ni secret, et vérifie une entrée à la limite puis une violation.

Validation locale de configuration :

```bash
ddev drush cim -y
ddev drush cr
ddev drush config:status
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
ddev composer test:project-functional
git diff --check
```

Après import, vérifier également dans l'administration :

```text
/admin/config/ai/guardrails
/admin/config/ai/guardrails/guardrail-sets
/admin/config/ai/guardrails/global
```

Attendu : le guardrail de longueur, le set `Agency editorial baseline` et sa
sélection globale sont visibles, sans configuration provider supplémentaire.

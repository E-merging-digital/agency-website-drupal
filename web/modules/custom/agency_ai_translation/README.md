# Agency AI Translation (multi-langue)

## Statut

Ce module reste temporairement la couche de compatibilité de traduction éditoriale de l'Agency.

La preuve de parité #382 a évalué `drupal/ai_translate 1.3.1` mais conclut que le remplacement n'est pas encore possible sans régression. Voir `docs/drupal-ai-translate-parity.md`.

Aucune nouvelle intégration directe à un provider ne doit être ajoutée ici. Le client OpenAI-compatible existant est une dette à supprimer dès qu'une trajectoire Drupal AI native atteint la parité requise.

## Objectif
Ce module ajoute un **déclenchement manuel** pour pré-traduire un contenu Drupal vers une langue cible configurable via une IA compatible API Chat Completions.

- La source est la langue courante du contenu.
- La cible est choisie au déclenchement.
- Aucun déclenchement automatique à la sauvegarde.
- Toute traduction générée ou régénérée par IA est sauvegardée **non publiée**.
- Contrôle humain et publication manuelle de la traduction cible.

## Workflow éditeur
1. Ouvrir un contenu source.
2. Dans les opérations du contenu, cliquer **Générer [langue] (IA)**.
3. Confirmer explicitement l’action, y compris lorsqu'une traduction cible existe déjà.
4. Le module crée/met à jour la traduction cible et la remet en brouillon/non publiée.
5. L’éditeur est redirigé vers l’édition de la langue cible pour relecture/corrections.
6. Publication manuelle uniquement.

L'exécution unitaire vérifie à la fois le droit de mise à jour de l'entité, le droit IA dédié et le droit natif Content Translation du bundle (les administrateurs Drupal conservent leur bypass administratif).

## Champs traités
Le module traduit uniquement les champs translatables de type éditorial :
- `string`, `string_long`
- `text`, `text_long`, `text_with_summary`
- `link` (titre du lien uniquement)
- Paragraphs via `entity_reference_revisions` (récursif sur les champs éditoriaux translatables)

## Champs exclus
Par conception, les champs techniques ne sont pas traduits automatiquement, notamment :
- images/fichiers
- références d’entités hors paragraphs
- dates, booléens, nombres, statuts, métadonnées système

## Configuration
Route d’admin : `/admin/config/content/agency-ai-translation`

Paramètres exportés :
- endpoint
- model
- system prompt

### Gestion de la clé API (sans secret dans le code)
Recommandé : `settings.php`

```php
$settings['agency_ai_translation.api_key'] = '...';
```

Alternative : variable d’environnement

```bash
export AGENCY_AI_TRANSLATION_API_KEY="..."
```

Fallback possible (non exporté) : champ mot de passe de la page de config (stocké en `state`).

## Limites connues
- Couche temporaire maintenue uniquement jusqu'à parité d'une solution Drupal AI native.
- Traduction champ à champ.
- La qualité dépend du modèle IA et du prompt configuré.
- Le client HTTP direct ne doit pas être étendu ; il doit disparaître lors de la migration.

## Alias d’URL (Pathauto)
- Si le module **Pathauto** est actif, l’alias de la traduction cible est régénéré après traduction.
- Le champ `path` de la traduction cible est préparé avec `pathauto = 1` pour éviter de rester sur `/[lang]/node/[nid]`.
- Les patterns Pathauto doivent être configurés par langue (ex: `node_page_fr`, `node_page_en`) pour des alias cohérents en multilingue.

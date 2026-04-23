# Agency AI Translation (multi-langue)

## Objectif
Ce module ajoute un **déclenchement manuel** pour pré-traduire un contenu Drupal vers une langue cible configurable via une IA compatible API Chat Completions.

- La source est la langue courante du contenu.
- La cible est choisie au déclenchement.
- Aucun déclenchement automatique à la sauvegarde.
- Contrôle humain avant publication EN.

## Workflow éditeur
1. Ouvrir un contenu source.
2. Dans les opérations du contenu, cliquer **Générer [langue] (IA)**.
3. Confirmer l’action.
4. Le module crée/met à jour la traduction cible.
5. L’éditeur est redirigé vers l’édition de la langue cible pour relecture/corrections.
6. Publication manuelle uniquement.

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
- Première version volontairement simple : traduction champ à champ.
- La qualité dépend du modèle IA et du prompt configuré.

## Alias d’URL (Pathauto)
- Si le module **Pathauto** est actif, l’alias de la traduction cible est régénéré après traduction.
- Le champ `path` de la traduction cible est préparé avec `pathauto = 1` pour éviter de rester sur `/[lang]/node/[nid]`.

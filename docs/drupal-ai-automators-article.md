# AI Automators manuels pour Article

Statut : **implémenté par #381**  
Doctrine : `docs/drupal-ai-architecture.md` et `docs/drupal-ai-guardrails.md`

## Périmètre

La première phase d'AI Automators sur le bundle `Article` reste strictement human-in-the-loop. Elle utilise `ai_automators` avec `field_widget_actions` et ne configure ni worker Direct, ni Batch, ni Queue/Cron.

Deux enrichissements sont exposés comme actions explicites dans le formulaire Article :

- `field_short_description` utilise `llm_simple_text_long`, prend le champ `body` comme source et propose un résumé éditorial court ;
- `field_feature_image` utilise `llm_image_alt_text` et propose le texte alternatif de l'image principale.

Les deux Automators utilisent le worker `field_widget_actions`. Les actions de formulaire ont `automatic: false` : aucune génération n'est lancée lors du chargement, de la consultation ou de la sauvegarde d'un Article.

## Provider et secrets

La configuration versionnée conserve `automator_ai_provider: default_json` et ne fixe aucun modèle concret. Le choix du provider/modèle et les credentials restent propres à l'environnement d'exécution selon la doctrine Drupal AI du projet. Aucun secret n'est stocké dans Git.

Le résumé nécessite un provider capable de chat/texte. L'alt text nécessite un provider capable de vision/image. Une indisponibilité ou une absence de provider ne doit pas empêcher la saisie ni la sauvegarde manuelle d'un Article.

## Gouvernance éditoriale

Le résultat IA est une proposition dans le formulaire. L'éditeur reste responsable de sa relecture, de sa modification éventuelle et de la sauvegarde du contenu. Il n'existe aucun auto-save ni auto-publish introduit par #381.

Le champ technique `ai_automator_status`, créé par AI Automators, reste caché des affichages publics Article.

## SEO / Metatag

L'Automator Metatag officiel a été évalué pendant #381. Une capacité upstream existe ; aucune logique SEO custom n'est donc justifiée dans cette phase. Son activation éventuelle doit faire l'objet d'un ticket séparé avec périmètre, prompts, coût et preuve de revue éditoriale.

## Preuves

Le contrat est protégé par :

- un test de configuration qui vérifie les plugins, workers, l'absence d'exécution automatique et l'abstraction provider ;
- un fresh install depuis `config/sync`, suivi de `drush cim`, `drush cr` et `drush config:status` sans dérive ;
- une Browser Validation authentifiée du formulaire Article, sans provider réel ni secret persistant.

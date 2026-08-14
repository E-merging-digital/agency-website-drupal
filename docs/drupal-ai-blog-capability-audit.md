# Audit des capacités Drupal AI pour le blog

Statut : **ACCEPTE**  
Date d'audit : **2026-08-14**  
Tickets : #32, #378, #355, #356

## 1. Objet

Cet audit applique `docs/drupal-ai-architecture.md` au chantier blog. Il détermine quelles capacités Drupal AI doivent être adoptées, reportées ou évitées avec la pile réellement verrouillée par le projet.

Il ne constitue pas une autorisation d'activer les fonctionnalités : chaque activation reste une tâche dédiée avec configuration exportée, permissions, tests et validation de dégradation.

## 2. Méthode et preuve reproductible

Un workflow GitHub Actions temporaire et read-only a été exécuté sur la branche #378 sans secret et sans appel à un provider IA.

Run de preuve final : `31832224068`, job `94870357347`.

Le run a :

1. installé strictement le `composer.lock` ;
2. affiché les versions réellement installées ;
3. inventorié les sous-modules livrés dans `drupal/ai` ;
4. lu les `.info.yml` des capacités ciblées ;
5. vérifié les modules IA activés dans la configuration exportée ;
6. vérifié seulement les noms de clés de configuration provider, sans exposer de secret ;
7. exécuté des `composer require --dry-run` pour les remplaçants standalone ;
8. restauré le `web/robots.txt` custom que Drupal Composer Scaffold remplace dans un workspace propre ;
9. terminé avec un workspace Git propre.

Aucune dépendance, configuration Drupal, clé, modèle ou donnée de production n'a été modifiée par cet audit.

## 3. Etat exact du projet

### Packages verrouillés

- Drupal core : `11.4.5`.
- `drupal/ai` : `1.4.6`.
- `drupal/ai_provider_openai` : `1.2.4`.
- `drupal/key` : `1.22.0`.
- `drupal/token` : `1.17.0`.

Le projet reste sur une release stable 1.4.x de Drupal AI. Au moment de l'audit, aucune nécessité fonctionnelle ne justifie de passer la production sur une branche `1.5.x` pré-release/dev ou `2.0.x-dev`.

Le provider OpenAI verrouillé est `1.2.4`. Le projet upstream possède aussi une ligne 1.3 pré-release utilisant la Responses API, mais l'audit ne justifie pas une migration vers une pré-release pour le blog.

### Modules IA activés dans `config/sync`

Seuls les modules suivants sont activés :

- `ai` ;
- `ai_provider_openai` ;
- `key`.

Les capacités éditoriales auditées ne sont donc pas encore actives.

### Provider et secret

La configuration exportée `ai_provider_openai.settings.yml` contient une référence `api_key` vers une Key Drupal. La valeur du secret n'est pas versionnée.

Cette architecture est conforme à la doctrine du projet et doit être conservée.

## 4. Inventaire du paquet `drupal/ai 1.4.6`

Le paquet verrouillé livre notamment :

- AI CKEditor ;
- AI Automators ;
- AI Content Suggestions ;
- AI Translate ;
- Field Widget Actions ;
- AI API Explorer ;
- AI Assistant API ;
- AI Chatbot ;
- AI ECA ;
- AI Observability ;
- AI Validations ;
- AI Search.

La présence dans le paquet ne signifie pas que chaque sous-module doit être activé. Plusieurs fonctionnalités sont en cours d'extraction vers des projets contrib standalone.

## 5. Décision par capacité

### 5.1 AI CKEditor — ADOPTER, première vague

Etat observé dans `drupal/ai 1.4.6` :

- sous-module présent ;
- non marqué deprecated ;
- compatible Drupal `^10.4 || ^11` ;
- dépend de CKEditor 5 et AI Core.

La documentation officielle 1.4.x indique que les plugins CKEditor transmettent du contenu à un LLM pour proposer génération, reformulation, ton, complétion ou traduction de fragments. L'action est déclenchée depuis la barre d'outils de l'éditeur.

#### Décision projet

Adopter AI CKEditor après la fondation Article #356, avec un périmètre initial volontairement réduit :

- reformulation ;
- correction/amélioration éditoriale ;
- aide à la rédaction de fragments ;
- éventuellement résumé d'un passage si la fonctionnalité stable le permet.

Ne pas utiliser le plugin de traduction CKEditor comme mécanisme de traduction de l'entité Article : Content Translation / AI Translate reste le bon niveau pour une traduction FR/EN complète.

Ne pas créer dans #356 de vocabulaires artificiels `Tone of voice` ou `Languages` uniquement pour AI CKEditor. Ils ne seront ajoutés que si un besoin éditorial réel le justifie.

#### Gouvernance

- déclenchement explicite par l'éditeur ;
- résultat relu/modifiable avant sauvegarde ;
- aucune publication automatique ;
- panne provider = édition Drupal normale toujours possible ;
- coût = un ou plusieurs appels LLM uniquement lorsque l'éditeur demande une action.

### 5.2 AI Automators — ADOPTER SELECTIVEMENT, première vague

Etat observé dans `drupal/ai 1.4.6` :

- sous-module présent ;
- non marqué deprecated ;
- dépend de AI Core et Token ;
- supporte Drupal `^10.4 || ^11`.

La documentation officielle permet plusieurs workers : Direct, Batch, Queue/Cron et Field Widget. Les Field Widget Actions permettent à l'éditeur de déclencher une génération à la demande depuis un champ et de conserver le contrôle avant sauvegarde.

#### Décision projet

Pour la première version du blog, utiliser **uniquement des actions manuelles Field Widget / preview**. Ne pas activer d'automator automatique sur `entity save`, cron ou batch pour la publication éditoriale initiale.

Candidats prioritaires après #356 :

1. `field_short_description` : proposer un résumé/chapeau depuis le corps ;
2. texte alternatif de l'image principale : proposer un alt descriptif, toujours relu ;
3. métadonnées : évaluer l'Automator Metatag officiel avant tout développement custom.

A reporter :

- génération automatique de l'article complet ;
- création automatique de catégories/taxonomies ;
- changement automatique de moderation state ;
- génération automatique d'image de couverture en production.

Ces usages ont davantage d'effets métier, de coût ou de risque de contenu inattendu.

### 5.3 Field Widget Actions — UTILISER LA TRAJECTOIRE STANDALONE

Field Widget Actions a été développé dans AI puis extrait vers un projet standalone. L'issue upstream de migration est clôturée.

Le dry-run du projet standalone AI Content Suggestions sur notre pile a résolu :

- `drupal/field_widget_actions 1.4.1` ;
- sans mise à jour de `drupal/ai 1.4.6`.

#### Décision projet

Lorsqu'une tâche d'implémentation aura besoin de Field Widget Actions, vérifier à nouveau la release stable courante et préférer le projet standalone plutôt que d'investir dans le sous-module voué à disparaître de AI 2.x.

Ne pas ajouter la dépendance dans #356 : elle appartient à la tâche AI Automators/édition assistée.

### 5.4 AI Content Suggestions — REPORTER / STANDALONE UNIQUEMENT

Etat observé du sous-module embarqué dans `drupal/ai 1.4.6` :

- présent ;
- **`lifecycle: deprecated`** ;
- lien de dépréciation vers l'extraction du module hors du paquet AI.

Le projet standalone `AI Content Suggestions` existe et possède des releases stables couvertes par la politique de sécurité Drupal.

Preuve Composer du 2026-08-14 :

```text
composer require 'drupal/ai_content_suggestions:^1.4' --dry-run --with-all-dependencies
```

s'est résolu sans modifier AI Core et a sélectionné au moment du run :

- `drupal/ai_content_suggestions 1.5.0` ;
- `drupal/field_widget_actions 1.4.1`.

Cette preuve confirme l'installabilité sur la pile verrouillée ; elle ne dispense pas de revalider la release/support au moment d'une future installation.

#### Décision projet

Ne jamais activer le sous-module deprecated embarqué.

Reporter l'adoption du standalone après AI CKEditor et les Automators : son bénéfice (feedback, titres, ton, suggestions) chevauche partiellement CKEditor. Une deuxième vague permettra de mesurer si son UX apporte une vraie valeur supplémentaire au flux éditorial.

### 5.5 AI Translate — NE PAS ACTIVER LE SOUS-MODULE ; AUDIT DE PARITE STANDALONE

Etat observé du sous-module embarqué dans `drupal/ai 1.4.6` :

- présent ;
- **`lifecycle: deprecated`** ;
- dépend de AI Core et Content Translation.

Upstream indique explicitement que la fonctionnalité a été déplacée vers le projet standalone AI Translate.

Le projet standalone propose `1.3.1`, release stable couverte par la Drupal Security Team, compatible Drupal `^10.4 || ^11`.

Preuve Composer du 2026-08-14 :

```text
composer require 'drupal/ai_translate:^1.3' --dry-run --with-all-dependencies
```

résout exactement `drupal/ai_translate 1.3.1`, sans mise à jour ou suppression du reste de la pile.

#### Décision projet

- ne pas activer le sous-module deprecated embarqué ;
- conserver `agency_ai_translation` tant que la parité n'est pas prouvée ;
- créer une tâche dédiée pour tester le standalone AI Translate contre le workflow réel du site.

La preuve de parité devra couvrir au minimum :

- champs traduisibles du futur Article ;
- Paragraphs utilisés par les autres bundles ;
- create/update d'une traduction ;
- source/cible ;
- Pathauto/aliases ;
- redirection vers relecture ;
- publication humaine ;
- erreurs provider sans perte de contenu ;
- tests fonctionnels existants.

Le client HTTP direct de `agency_ai_translation` ne doit plus recevoir de nouvelles fonctionnalités fournisseur en attendant cette convergence.

### 5.6 Guardrails — ADOPTER UN SOCLE DETERMINISTE AVANT LES NOUVEAUX USAGES

L'inventaire du paquet montre que Guardrails fait partie de **AI Core** : ce n'est pas un sous-module à activer séparément.

AI Core fournit des entités/configurations de guardrails, des sets, des subscribers pre/post generation et des plugins. La documentation 1.4.x décrit notamment :

- Input Length Limit ;
- Regexp Guardrail ;
- Restrict to Topic ;
- guardrails custom.

`Restrict to Topic` est non déterministe et déclenche lui-même une classification via un LLM : il ajoute donc latence et coût provider.

#### Décision projet

Avant d'activer CKEditor/Automators pour le blog, créer une tâche de guardrails bornée et commencer par les contrôles déterministes utiles, en priorité une limite de longueur d'entrée raisonnable.

Ne pas activer `Restrict to Topic` par défaut pour le blog : les éditeurs sont authentifiés et le coût d'un deuxième appel LLM n'est pas justifié sans menace/use case concret.

Point critique : les Guardrails Drupal AI ne peuvent gouverner que les appels qui passent par l'abstraction Drupal AI. Le client HTTP direct actuel de `agency_ai_translation` les contourne. C'est une raison supplémentaire de converger la traduction vers Drupal AI, sans précipiter la migration.

## 6. Données envoyées et comportement de dégradation

### AI CKEditor

Données : texte sélectionné/contexte CKEditor et prompt/configuration de l'action.

Dégradation : si le provider est indisponible, l'action IA échoue mais l'éditeur et le contenu Drupal restent utilisables.

### AI Automators

Données : champs/contextes explicitement configurés, éventuellement injectés par Token, et prompt de l'automator.

Dégradation recommandée : mode manuel Field Widget dans la première phase ; un échec ne doit pas empêcher la sauvegarde manuelle du champ.

### Content Suggestions

Données : contenu/field values visés par la suggestion.

Dégradation : suggestion indisponible, contenu source inchangé.

### AI Translate

Données : contenu traduisible envoyé au provider selon la configuration.

Dégradation exigée : aucune suppression/écrasement de la source ou d'une traduction existante ; traduction générée toujours relue avant publication.

### Guardrails

Les guardrails déterministes n'ajoutent pas nécessairement d'appel provider. Les guardrails non déterministes peuvent ajouter un appel et donc coût/latence.

## 7. Impact sur la fondation Article #356

L'audit **ne nécessite aucun champ IA spécifique** dans Article.

#356 doit rester une tâche Drupal pure et fournir :

- titre natif ;
- `field_short_description` traduisible ;
- `body` avec résumé, traduisible et utilisant un format CKEditor 5 approprié ;
- image principale avec alt obligatoire ;
- catégorie principale via taxonomy/entity reference ;
- révisions et traductions Drupal ;
- displays/form displays propres.

Ces primitives sont naturellement exploitables ensuite par CKEditor, Automators et AI Translate.

Ne pas ajouter dans #356 :

- champs de prompt ;
- champs de réponse IA ;
- taxonomy `Tone of voice` ou `Languages` uniquement pour l'IA ;
- configuration Automator ;
- logique provider ;
- auto-publication.

Conclusion : **#356 peut reprendre dès la fusion de cet audit sans attendre l'implémentation des fonctionnalités IA.**

## 8. Séquence recommandée après l'audit

1. #356 — fondation éditoriale Article, sans dépendance IA.
2. Guardrails — baseline déterministe pour les nouveaux appels Drupal AI.
3. AI CKEditor — assistance éditoriale explicite.
4. AI Automators + Field Widget Actions standalone — résumé/alt/metatag manuels.
5. AI Translate standalone — preuve de parité puis migration éventuelle de `agency_ai_translation`.
6. AI Content Suggestions standalone — deuxième vague seulement si son UX apporte une valeur distincte.

Ces étapes doivent rester des tickets séparés : l'échec ou le report d'une capacité IA ne doit pas bloquer le blog Drupal de base.

## 9. Outside AI

Aucune nouvelle surface agentique n'est nécessaire pour le blog à ce stade.

La bonne préparation Outside AI reste :

- contenu Article structuré ;
- Schema.org Article ;
- Metatag ;
- hreflang/canonical ;
- sitemap ;
- `llms.txt` ;
- URLs stables ;
- permissions Drupal explicites.

Toute écriture future par agent externe nécessite une tâche de gouvernance distincte conformément à `docs/drupal-ai-architecture.md`.

## 10. Sources officielles vérifiées le 2026-08-14

- Projet Drupal AI : `https://www.drupal.org/project/ai`
- Documentation Drupal AI 1.4.x : `https://project.pages.drupalcode.org/ai/1.4.x/`
- AI CKEditor : `https://project.pages.drupalcode.org/ai/1.4.x/modules/ai_ckeditor/`
- AI Automators : `https://project.pages.drupalcode.org/ai/1.4.x/modules/ai_automators/`
- Guardrails : `https://project.pages.drupalcode.org/ai/1.4.x/developers/guardrails/`
- Extraction AI Content Suggestions : `https://www.drupal.org/project/ai/issues/3552885`
- Projet AI Content Suggestions : `https://www.drupal.org/project/ai_content_suggestions`
- Dépréciation/extraction AI Translate : `https://www.drupal.org/project/ai/issues/3554535`
- Projet AI Translate : `https://www.drupal.org/project/ai_translate`
- Extraction Field Widget Actions : `https://www.drupal.org/project/ai/issues/3552904`
- Projet Field Widget Actions : `https://www.drupal.org/project/field_widget_actions`
- Projet OpenAI Provider : `https://www.drupal.org/project/ai_provider_openai`

## 11. Règle de réévaluation

Avant chaque ticket d'activation :

1. revalider la release stable courante ;
2. revalider le statut deprecated/moved-out ;
3. exécuter `composer require --dry-run` si une nouvelle dépendance est nécessaire ;
4. vérifier les advisories de sécurité ;
5. ne pas supposer qu'une recommandation datée du présent audit est encore optimale après une évolution de Drupal AI.

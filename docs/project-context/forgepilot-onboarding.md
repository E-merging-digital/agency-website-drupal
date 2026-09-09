# Onboarding ForgePilot — site agence Drupal

## Objet

Ce document décrit le premier niveau d’intégration de ForgePilot dans le dépôt `E-merging-digital/agency-website-drupal`.

ForgePilot reste un outil externe d’orchestration locale. Il n’est pas installé par Composer, n’est pas chargé par Drupal, n’est pas déployé en production et ne remplace ni GitHub ni les règles du dépôt.

## Phase actuelle

Statut : **pilote manuel supervisé**.

Cette phase autorise :

- le chargement de la configuration partagée `forgepilot.toml` ;
- le diagnostic d’onboarding en lecture seule ;
- le contrôle des sources de mémoire durable ;
- le calcul d’une prochaine action sûre ;
- la préparation d’un contexte pour un assistant ;
- les validations déterministes en lecture seule ou explicitement lancées par l’opérateur.

Cette phase n’autorise pas :

- le merge ou l’approbation automatique d’une Pull Request ;
- la fermeture automatique d’une issue ;
- le lancement de séquences ou batchs autonomes ;
- la modification directe de `main` ;
- l’automatisation d’une opération de production ;
- la modification des menus, de la homepage, du tracking, d’OpenAI, du chatbot, du `settings.php` de production ou du script de déploiement sans ticket explicite.

## Sources d’autorité chargées

ForgePilot doit adopter les sources existantes et ne pas recréer une gouvernance parallèle.

Ordre attendu :

1. `AGENTS.md` ;
2. `docs/decisions/README.md` et les décisions acceptées pertinentes ;
3. l’issue GitHub active ;
4. le code, la configuration et les tests du dépôt ;
5. l’état réel Git et GitHub ;
6. `PROJECT_BRIEF.md` ;
7. les documents ciblés dans `docs/project-context/` et `docs/operations/` ;
8. le contexte temporaire de l’assistant.

Une contradiction doit produire un arrêt ou un rapport explicite. ForgePilot ne doit pas choisir silencieusement la source la plus pratique.

## Configuration partagée

`forgepilot.toml` contient uniquement des conventions non sensibles :

- le dépôt GitHub ;
- la branche par défaut ;
- le niveau d’autonomie ;
- les actions autorisées, gated ou bloquées.

Le mode initial reste `manual`.

Les actions de validation et de sélection de prochaine tâche peuvent être exposées en lecture seule. Les changements Git, les lancements d’agents et les changements de session ou de fournisseur restent gated. Les merges, approbations, fermetures et batchs restent bloqués.

Cette policy exprime une limite de workflow ; elle n’exécute aucune action par elle-même.

## Configuration locale utilisateur

Les chemins locaux et réglages propres à une machine ne doivent jamais être placés dans `forgepilot.toml`.

ForgePilot lit la configuration utilisateur dans :

```text
~/.config/forgepilot/config.toml
```

Exemple Windows à adapter localement, sans le committer :

```toml
[projects."E-merging-digital/agency-website-drupal"]
path = "D:/www/agency-website-drupal"
python_utf8 = true
```

Les paramètres d’un backend Codex ou Claude restent également locaux. Les tokens, clés et secrets doivent rester dans leurs mécanismes d’authentification dédiés.

## Diagnostic initial

Depuis la racine du dépôt :

```powershell
forgepilot onboarding status --repo E-merging-digital/agency-website-drupal
forgepilot onboarding preview --repo E-merging-digital/agency-website-drupal
forgepilot onboarding check --repo E-merging-digital/agency-website-drupal
```

Sortie JSON exploitable par un futur dashboard ou un script de contrôle :

```powershell
forgepilot onboarding preview --repo E-merging-digital/agency-website-drupal --format json
forgepilot onboarding check --repo E-merging-digital/agency-website-drupal --format json
```

Le contrôle d’onboarding doit trouver :

- `AGENTS.md` ;
- `PROJECT_BRIEF.md` ;
- `docs/decisions/` ;
- `docs/decisions/README.md` ;
- `docs/project-context/`.

Aucune commande `create-skeletons` n’est nécessaire lorsque ces sources existent déjà. ForgePilot ne doit jamais écraser leur contenu.

## Répertoire local `.forgepilot/`

Le répertoire `.forgepilot/` contient des artefacts opérationnels locaux tels qu’un ledger d’usage ou de futurs états de session. Il est ignoré par Git.

Vérification :

```powershell
New-Item -ItemType Directory -Force .forgepilot | Out-Null
git check-ignore -v .forgepilot/
```

Ces fichiers ne constituent pas la mémoire canonique du projet. Une information durable doit être placée dans une source versionnée appropriée ou dans GitHub.

## Workflow du premier pilote

Le premier travail réellement piloté par ForgePilot doit rester une tâche documentaire ou technique à faible risque.

1. Synchroniser `main` et vérifier une copie de travail propre.
2. Lire l’issue bornée, `AGENTS.md`, `PROJECT_BRIEF.md` et les fichiers concernés.
3. Exécuter `forgepilot onboarding check` et conserver le verdict.
4. Examiner la prochaine action sûre sans l’interpréter comme une autorisation automatique.
5. Préparer une branche dédiée seulement lorsque l’issue et la policy le permettent.
6. Lancer au maximum un assistant sur la tâche bornée, avec contrôle humain.
7. Exécuter les validations déterministes adaptées.
8. Rapporter séparément : actions exécutées, validations, limites, risques et propositions.
9. Créer une PR révisable ; ne pas la merger automatiquement pendant le pilote.
10. Comparer le résultat avec le workflow manuel habituel.

## Critères de réussite du pilote

Le pilote est réussi lorsque :

- ForgePilot résout le bon dépôt et le bon chemin local ;
- les prérequis d’onboarding sont tous détectés ;
- `AGENTS.md` reste la règle obligatoire principale ;
- aucune modification hors issue n’est introduite ;
- la branche et la PR respectent le workflow existant ;
- les actions réellement exécutées sont distinguées des propositions ;
- aucun secret ni artefact `.forgepilot/` n’est committé ;
- le résultat est au moins aussi fiable et lisible que le workflow manuel.

## Conditions d’arrêt

Arrêter le workflow et produire un rapport lorsqu’une des situations suivantes apparaît :

- dépôt ou chemin local contradictoire ;
- `main` non synchronisé ou copie de travail contenant des changements sans rapport ;
- issue trop large ou non bornée ;
- règle durable manquante ou contradictoire ;
- action suivante plus risquée que le niveau autorisé ;
- validation obligatoire indisponible ou en échec ;
- tentative de modifier un élément explicitement hors périmètre ;
- demande d’opération de production sans procédure et validation humaine dédiées.

## Étapes ultérieures, hors de ce bootstrap

Les étapes suivantes feront l’objet de tâches séparées :

- exécuter un premier pilote réel sur une issue à faible risque ;
- vérifier la reprise de contexte entre Codex et Claude Code ;
- intégrer un rapport d’avancement explicable ;
- définir le profil technologique Drupal partagé ;
- synchroniser plus finement issues, branches, PR, reviews et handoffs ;
- décider si certaines actions gated peuvent passer en mode supervisé après plusieurs pilotes réussis.

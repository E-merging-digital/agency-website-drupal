# Registre des décisions

Ce dossier contient les décisions structurantes durables du projet lorsqu’un arbitrage dépasse le périmètre d’une tâche technique ordinaire.

## Principes

- Une décision doit être liée à une issue GitHub dédiée ou à un arbitrage explicitement validé.
- Le statut doit être visible : proposée, acceptée, rejetée ou remplacée.
- Une décision ne remplace pas `AGENTS.md` ; elle précise un choix structurant dans le respect des règles obligatoires du dépôt.
- Une conversation, un commentaire ou une proposition d’assistant ne devient pas automatiquement une décision acceptée.
- Les décisions doivent rester indépendantes du fournisseur d’IA lorsque le sujet concerne la gouvernance ou le workflow.
- Les secrets, chemins locaux personnels et paramètres de production sensibles sont interdits.

## Format recommandé

Nom de fichier :

```text
NNN-slug-decision.md
```

Structure minimale :

```markdown
# Décision NNN — Titre

- Statut : proposée | acceptée | rejetée | remplacée
- Date : AAAA-MM-JJ
- Issue : #...

## Contexte

## Décision

## Conséquences

## Alternatives considérées
```

## État initial

Aucune décision historique n’est recréée artificiellement pendant l’onboarding ForgePilot.

Les règles existantes restent portées par `AGENTS.md`, `PROJECT_BRIEF.md`, les issues, le code et la documentation déjà versionnée. Une première décision sera ajoutée uniquement lorsqu’un arbitrage structurant distinct le justifiera.

Le chantier d’intégration progressive de ForgePilot est suivi par l’issue #352. Son premier incrément technique est l’issue #353.

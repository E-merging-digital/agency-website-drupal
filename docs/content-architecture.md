# Ticket #3 — Architecture éditoriale Drupal

## Objectif
Structurer le contenu du site pour une édition claire et évolutive, avec une mise en avant éditoriale des fonctionnalités IA.

## Types de contenu
- Page (type Drupal standard conservé)
- Service
- Article (type Drupal standard conservé)
- Cas client
- Fonctionnalité IA

## Taxonomies
- Secteur
- Type de service
- Localisation
- Type de fonctionnalité IA

## Médias
- Vérification faite : le modèle de contenu utilise des champs image sur les nœuds.
- Les types de média ne sont pas forcés dans ce ticket pour éviter une dépendance d’import sur le module `media` quand il n’est pas activé sur tous les environnements.

## Relations métier
- **Service** → référence plusieurs **Fonctionnalités IA** (`field_related_ai_features`)
- **Fonctionnalité IA** → référence un ou plusieurs **Services** (`field_related_services`)
- **Cas client** → peut référencer des **Services** et des **Fonctionnalités IA**

## Stratégie Paragraphs (préparation)
Le ticket prépare la base éditoriale pour une édition structurée orientée Paragraphs.

Proposition de paragraphes à mettre en place dans un ticket dédié :
- Hero (titre, sous-titre, CTA, image)
- Bloc de texte riche
- Liste d’arguments / bénéfices
- Grille de cartes services
- Bloc « cas d’usage IA »
- FAQ
- CTA final

Cette stratégie est volontairement séparée pour garder un périmètre propre sur le ticket #3 :
- architecture de contenu et taxonomies
- champs métier IA
- relations entre contenus

# Direction visuelle et plan d’intégration des assets

## Direction visuelle commune

- **Positionnement** : sobre, premium, professionnel, orienté agence Drupal + IA pour PME/ASBL.
- **Style visuel** : photo-réalisme éditorial, lumière naturelle, profondeur de champ douce, interfaces visibles mais discrètes.
- **Palette suggérée** : bleus profonds, gris froids, blancs lumineux, accents sobres.
- **Composition** : scènes de collaboration, cadrages larges pour les hero, détails métiers pour les visuels secondaires.
- **Traitement** : contraste modéré, rendu propre, pas d’effets futuristes excessifs, pas de clichés “IA gadget”.

## Catalogue des visuels

### agency-v2-meeting-ai
- **Usage** : hero de la homepage V2, scène de réunion illustrée autour d'un projet numérique.
- **Origine** : illustration générée par IA ; participants fictifs, sans identité réelle revendiquée.
- **Texte alternatif FR** : Illustration générée par IA d’une réunion autour d’un projet numérique, avec des participants fictifs.
- **Texte alternatif EN** : AI-generated illustration of a meeting about a digital project, with fictional participants.
- **Emplacement** : `web/themes/custom/emerging_digital/images/home/agency-v2-meeting-ai.jpg` (JPEG existant, inchangé).
- **Légende visible FR/EN** : « Illustration générée par IA · participants fictifs » / « AI-generated illustration · fictional participants ».

### home-services-overview
- **Usage** : Intro section “Nos services” sur homepage.
- **Prompt** : `Illustration photo-réaliste d’un plan de services digitaux structuré sur table de réunion, post-its neutres, schémas de parcours utilisateur, ordinateur affichant Drupal, atmosphère professionnelle, palette bleu/gris, rendu premium, cadrage 3:2, sans texte lisible.`
- **Alt text** : Vue synthétique d’un dispositif de services digitaux structurés autour de Drupal.
- **Style** : Minimal business, structuré, net.
- **Emplacement** : `web/themes/custom/emerging_digital/images/home/home-services-overview.svg`.

### services-page-hero
- **Usage** : Hero page Services.
- **Prompt** : `Scène professionnelle d’une équipe conseil présentant une roadmap Drupal à un client PME, écran avec architecture de contenus et étapes projet, bureau contemporain, lumière douce, tonalité premium, cadrage horizontal 16:9, rendu photo réaliste, sans logos, sans texte incrusté.`
- **Alt text** : Consultants préparant une stratégie Drupal sur une table de travail premium.
- **Style** : Conseil stratégique, confiance, clarté.
- **Emplacement** : `web/themes/custom/emerging_digital/images/services/services-page-hero.svg`.

### services-method-blueprint
- **Usage** : Bloc “Pourquoi Drupal” sur page Services.
- **Prompt** : `Visualisation premium d’un blueprint de méthode projet digital, étapes claires (audit, architecture, production, optimisation) représentées par éléments graphiques abstraits, rendu propre et élégant, palette bleu pétrole et gris, lumière diffuse, style professionnel minimal, format paysage.`
- **Alt text** : Schéma visuel d’une méthode projet Drupal structurée et progressive.
- **Style** : Infographie visuelle haut de gamme, abstraite et lisible.
- **Emplacement** : `web/themes/custom/emerging_digital/images/services/services-method-blueprint.svg`.

### ia-drupal-page-hero
- **Usage** : Hero page IA & Drupal.
- **Prompt** : `Photo réaliste d’un éditeur de contenu utilisant Drupal avec assistance IA contextuelle (suggestions, reformulation, tags), interface visible mais non lisible, bureau propre, ambiance sereine, teintes bleu/gris, rendu premium, cadrage 16:9, sans éléments futuristes excessifs.`
- **Alt text** : Spécialiste contenu utilisant des suggestions IA dans une interface Drupal moderne.
- **Style** : Technologie pragmatique, humaine, crédible.
- **Emplacement** : `web/themes/custom/emerging_digital/images/ia-drupal/ia-drupal-page-hero.svg`.

### ia-drupal-workflow
- **Usage** : Bloc “Comment nous l’intégrons” + rappel homepage section IA.
- **Prompt** : `Composition visuelle d’un workflow éditorial collaboratif: briefing, rédaction assistée IA, validation humaine, publication Drupal, ambiance agence digitale premium, style réaliste semi-abstrait, couleurs sobres, lisibilité forte, format horizontal.`
- **Alt text** : Workflow collaboratif entre équipe éditoriale et modules IA dans Drupal.
- **Style** : Processus clair, orienté impact métier.
- **Emplacement** : `web/themes/custom/emerging_digital/images/ia-drupal/ia-drupal-workflow.svg`.

### case-clients-page-hero
- **Usage** : Hero page Cas clients.
- **Prompt** : `Réunion de pilotage présentant des résultats de projet Drupal sur un écran (courbes et indicateurs non lisibles), équipe mixte agence/client, ambiance confiante et professionnelle, lumière naturelle, palette bleu profond et neutres, rendu éditorial premium, cadrage 16:9.`
- **Alt text** : Présentation de résultats clients Drupal dans une réunion de pilotage.
- **Style** : Preuve, crédibilité, relation client.
- **Emplacement** : `web/themes/custom/emerging_digital/images/case-clients/case-clients-page-hero.svg`.

### case-clients-results-strip
- **Usage** : Intro visuelle section Cas clients sur homepage.
- **Prompt** : `Visuel corporate montrant des indicateurs de performance positifs sur supports digitaux, contexte agence web, aucun texte lisible, style premium sobre, contrastes modérés, ton bleu/gris, format horizontal 3:2.`
- **Alt text** : Mise en scène de résultats mesurables de projets Drupal pour organisations locales.
- **Style** : Résultats et impact business, sans exagération.
- **Emplacement** : `web/themes/custom/emerging_digital/images/case-clients/case-clients-results-strip.svg`.

## Intégration technique prévue

- Les composants Twig référencent des **visuels de fallback versionnés** ; la homepage V2 utilise l'illustration IA historique et les autres contextes conservent leurs SVG.
- Les remplacements passent par les définitions de visuels du thème ou par Drupal Media, en conservant les contrats Twig/CSS.
- Les attributs `alt`, `loading`, `width`, `height` sont déjà en place pour préparer accessibilité et performance.
- Les classes CSS `.visual-block` et `.visual-chip` standardisent le rendu (bordure, fond, ratio flexible).

# Direction visuelle et plan d’intégration des assets

## Direction visuelle commune

- **Positionnement** : sobre, premium, professionnel, orienté agence Drupal + IA pour PME/ASBL.
- **Style visuel** : photo-réalisme éditorial, lumière naturelle, profondeur de champ douce, interfaces visibles mais discrètes.
- **Palette suggérée** : bleus profonds, gris froids, blancs lumineux, accents sobres.
- **Composition** : scènes de collaboration, cadrages larges pour les hero, détails métiers pour les visuels secondaires.
- **Traitement** : contraste modéré, rendu propre, pas d’effets futuristes excessifs, pas de clichés “IA gadget”.

## Catalogue des visuels

### home-hero-collaboration
- **Usage** : hero de la homepage V2, illustration d'une collaboration professionnelle, sans prétendre montrer l'équipe E-merging Digital.
- **Origine** : photographie réelle de Mikhail Nilov, [Pexels #7731361](https://www.pexels.com/photo/a-person-using-a-laptop-7731361/), sous [licence Pexels](https://www.pexels.com/license/).
- **Texte alternatif FR** : Personnes travaillant ensemble autour d'un ordinateur portable et de documents.
- **Texte alternatif EN** : People working together around a laptop and documents.
- **Emplacement** : `web/themes/custom/emerging_digital/images/home/home-hero-collaboration.jpg` (1200 × 1028, JPEG optimisé).
- **Légende visible FR/EN** : « Photographie d’illustration » / « Illustrative stock photograph ». La scène ne représente ni une équipe salariée ni un projet client certifié.

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

- Les composants Twig référencent des **visuels de fallback versionnés** ; la homepage V2 utilise une photographie et les autres contextes conservent leurs SVG.
- Les remplacements passent par les définitions de visuels du thème ou par Drupal Media, en conservant les contrats Twig/CSS.
- Les attributs `alt`, `loading`, `width`, `height` sont déjà en place pour préparer accessibilité et performance.
- Les classes CSS `.visual-block` et `.visual-chip` standardisent le rendu (bordure, fond, ratio flexible).

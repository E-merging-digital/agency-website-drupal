# Drupal content mapping — pages stratégiques

Ce document décrit le mapping Drupal complet pour les pages : Accueil, Services, IA & Drupal, Cas clients, Contact.

## 1) Paragraph types et champs exacts

## Hero (`hero`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Titre principal de section hero.
- `field_text` — **Description** — text_long — cardinalité 1 — optionnel — Sous-texte de contexte.
- `field_link` — **Lien** — link — cardinalité 1 — optionnel — CTA primaire.
- `field_secondary_link` — **Lien secondaire** — link — cardinalité 1 — optionnel — CTA secondaire.

## Text block (`text_block`)
- `field_heading` — **Titre** — string — cardinalité 1 — optionnel — Titre de section simple.
- `field_text` — **Texte** — text_long — cardinalité 1 — obligatoire — Texte principal de la section.

## Services grid (`services`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Titre de section services.
- `field_text` — **Texte** — text_long — cardinalité 1 — optionnel — Introduction de section.
- `field_items` — **Cartes de services** — text_long — cardinalité illimitée — obligatoire — Une carte par valeur, format strict `Titre|Description`.

## AI features grid (`ai_features`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Titre de section IA.
- `field_text` — **Texte** — text_long — cardinalité 1 — optionnel — Intro explicative.
- `field_items` — **Fonctionnalités IA** — text_long — cardinalité illimitée — obligatoire — Une fonctionnalité par valeur.

## Case studies (`case_clients`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Titre du bloc cas clients.
- `field_text` — **Texte** — text_long — cardinalité 1 — optionnel — Intro de section.
- `field_items` — **Titres des cas** — text_long — cardinalité illimitée — obligatoire — Un titre par cas.
- `field_case_problem` — **Problème** — text_long — cardinalité illimitée — obligatoire — Un problème par cas (même index).
- `field_case_solution` — **Solution** — text_long — cardinalité illimitée — obligatoire — Une solution par cas (même index).
- `field_case_result` — **Résultat** — text_long — cardinalité illimitée — obligatoire — Un résultat par cas (même index).

## Trust list (`trust_list`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Titre de la section confiance.
- `field_items` — **Éléments de confiance** — text_long — cardinalité illimitée — obligatoire — Un item par valeur.

## CTA block (`cta`)
- `field_heading` — **Titre** — string — cardinalité 1 — obligatoire — Message principal du CTA.
- `field_text` — **Texte** — text_long — cardinalité 1 — optionnel — Texte de réassurance.
- `field_link` — **Lien** — link — cardinalité 1 — optionnel — Bouton d’action.

---

## 2) Relations page ↔ paragraphs

Toutes les pages de type `page` utilisent `field_home_components` (entity_reference_revisions vers Paragraphs) pour assembler les sections dans l’ordre éditorial.

## 3) Mapping de contenu exact par page

## Accueil
1. **Hero (`hero`)**
   - `field_heading` : `Des sites Drupal performants, avec l’IA intégrée au cœur de vos contenus`
   - `field_text` : `Nous concevons des plateformes web durables pour PME et ASBL, en combinant expertise Drupal, structure éditoriale claire et intelligence artificielle utile.`
   - `field_link` : titre `Demander un audit`, URL `/contact`
   - `field_secondary_link` : titre `Découvrir nos services`, URL `/services`
2. **Text block (`text_block`)**
   - `field_heading` : `Drupal solide. Expérience claire. IA utile.`
   - `field_text` : `Nous aidons les organisations à structurer leur présence digitale avec Drupal, à améliorer la qualité de leurs contenus et à intégrer l’IA de manière concrète : rédaction assistée, optimisation SEO, traduction, enrichissement automatique des contenus.`
3. **Services grid (`services`)**
   - `field_heading` : `Nos services`
   - `field_items` (4 valeurs) :
     - `Création de sites Drupal|Sites institutionnels, vitrines ou plateformes éditoriales robustes et évolutives, conçus pour durer.`
     - `Migration et modernisation|Reprise de sites existants, montée de version Drupal, amélioration de la structure et des performances.`
     - `SEO et performance|Un site rapide, lisible et pensé pour être trouvé par vos publics.`
     - `IA intégrée dans le CMS|Des outils concrets pour produire, corriger et enrichir vos contenus directement dans Drupal.`
4. **AI features grid (`ai_features`)**
   - `field_heading` : `Ce que l’IA peut faire dans Drupal`
   - `field_text` : `Nous intégrons des fonctionnalités utiles directement dans l’interface éditoriale.`
   - `field_items` (6 valeurs) :
     - `Correction orthographique et reformulation`
     - `Génération assistée de contenu`
     - `Traduction automatique`
     - `Tags automatiques pour les images`
     - `Suggestions SEO`
     - `Résumé et structuration de contenu`
5. **Case studies (`case_clients`)**
   - `field_heading` : `Des projets clairs, utiles et durables`
   - cas 1
     - `field_items` : `Refonte d’un site institutionnel Drupal`
     - `field_case_problem` : `Site institutionnel difficile à maintenir.`
     - `field_case_solution` : `Structure clarifiée et parcours d’édition simplifié.`
     - `field_case_result` : `Édition facilitée et meilleures performances.`
   - cas 2
     - `field_items` : `Modernisation d’un site existant`
     - `field_case_problem` : `Socle Drupal vieillissant et dette technique.`
     - `field_case_solution` : `Migration maîtrisée et assainissement technique.`
     - `field_case_result` : `Base technique stabilisée.`
   - cas 3
     - `field_items` : `Mise en valeur de services complexes`
     - `field_case_problem` : `Offres perçues comme complexes et confuses.`
     - `field_case_solution` : `Contenus simplifiés et hiérarchie clarifiée.`
     - `field_case_result` : `Meilleure lisibilité et meilleure conversion.`
6. **Trust list (`trust_list`)**
   - `field_heading` : `Pourquoi travailler avec nous`
   - `field_items` :
     - `Expertise Drupal`
     - `Approche structurée`
     - `Vision long terme`
     - `IA utile, pas gadget`
     - `Compréhension des réalités PME / ASBL`
7. **CTA block (`cta`)**
   - `field_heading` : `Vous avez un projet Drupal ou un site à moderniser ?`
   - `field_text` : `Parlons de votre contexte et de ce que l’IA peut réellement vous apporter.`
   - `field_link` : titre `Prendre contact`, URL `/contact`

## Services
1. Hero (`hero`)
   - `field_heading` : `Des solutions Drupal adaptées à vos besoins`
   - `field_text` : `Nous accompagnons PME et ASBL dans la création, la modernisation et l’évolution de leurs sites Drupal.`
2. Text block (`text_block`)
   - `field_text` : `Votre site doit être un outil clair, fiable et évolutif. Nous vous aidons à structurer vos contenus et à construire une base technique solide.`
3. Services grid (`services`)
   - `field_heading` : `Nos services`
   - `field_items` :
     - `Création de site Drupal|Conception de sites sur mesure, pensés pour la performance, la clarté et la durabilité.`
     - `Migration Drupal|Mise à jour de versions anciennes, sécurisation et modernisation de votre plateforme.`
     - `Maintenance et évolutions|Suivi technique, améliorations continues et support.`
     - `SEO et optimisation|Optimisation des contenus, structure technique et performance.`
     - `IA intégrée|Automatisation de tâches éditoriales, amélioration de la qualité des contenus.`
4. Text block (`text_block`)
   - `field_heading` : `Pourquoi Drupal`
   - `field_text` : `Drupal est une plateforme robuste, flexible et idéale pour gérer des contenus complexes de manière structurée.`
5. CTA block (`cta`)
   - `field_text` : `Parlons de votre projet et trouvons la solution adaptée.`

## IA & Drupal
1. Hero (`hero`)
   - `field_heading` : `L’IA au service de vos contenus Drupal`
   - `field_text` : `Des fonctionnalités concrètes pour améliorer votre productivité et la qualité de vos contenus.`
2. Text block (`text_block`)
   - `field_text` : `L’IA ne remplace pas votre expertise, elle l’amplifie. Nous intégrons des outils utiles directement dans votre CMS.`
3. AI features grid (`ai_features`)
   - `field_heading` : `Cas d’usage`
   - `field_items` :
     - `Rédaction assistée : Génération de contenu, reformulation, amélioration de texte.`
     - `Correction éditoriale : Amélioration de la qualité linguistique et du ton.`
     - `SEO intelligent : Suggestions pour améliorer la visibilité.`
     - `Traduction : Adaptation de contenus multilingues.`
     - `Enrichissement automatique : Tags, résumés, structuration.`
4. Trust list (`trust_list`)
   - `field_heading` : `Bénéfices`
   - `field_items` : `Gain de temps`, `Meilleure qualité éditoriale`, `Cohérence des contenus`, `Accessibilité améliorée`
5. Text block (`text_block`)
   - `field_heading` : `Intégration`
   - `field_text` : `Les outils sont directement intégrés dans l’interface Drupal, sans complexité pour les utilisateurs.`
6. CTA block (`cta`)
   - `field_text` : `Découvrez comment intégrer l’IA dans votre site Drupal.`

## Cas clients
1. Hero (`hero`)
   - `field_heading` : `Des projets concrets et maîtrisés`
2. Text block (`text_block`)
   - `field_text` : `Chaque projet est conçu pour répondre à des besoins réels, avec une approche pragmatique.`
3. Case studies (`case_clients`)
   - cas 1 : titre `Refonte d’un site` / problème `site difficile à maintenir` / solution `refonte Drupal` / résultat `meilleure structure, plus simple à éditer`
   - cas 2 : titre `Migration Drupal` / problème `version obsolète` / solution `migration complète` / résultat `sécurité et performance améliorées`
   - cas 3 : titre `Structuration de contenu` / problème `contenu confus` / solution `architecture claire` / résultat `meilleure lisibilité`
4. CTA block (`cta`)
   - `field_text` : `Vous avez un projet similaire ? Discutons-en.`

## Contact
1. Hero (`hero`)
   - `field_heading` : `Parlons de votre projet`
2. Text block (`text_block`)
   - `field_text` : `Nous analysons votre situation et vous proposons des solutions adaptées.`
3. Text block (`text_block`)
   - `field_heading` : `Formulaire`
   - `field_text` : `Nom / Email / Organisation / Message`
4. Text block (`text_block`)
   - `field_heading` : `Informations`
   - `field_text` : `Disponible pour projets en Wallonie et Bruxelles.`
5. CTA block (`cta`)
   - `field_link` : titre `Envoyer une demande`

## 4) Décisions non évidentes (documentées)
- Les grilles `services` utilisent `field_items` avec le séparateur `|` pour éviter d’ajouter un Paragraph supplémentaire de carte et garder une structure simple/maintenable.
- Les cas clients utilisent des champs multi-valeurs synchronisés par index (`field_items`, `field_case_problem`, `field_case_solution`, `field_case_result`) pour conserver une saisie simple en back-office sans complexifier le modèle.

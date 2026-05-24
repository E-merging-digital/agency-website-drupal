# Hero illustrations

Le hero actuel reste la référence artistique : fond clair, lumière douce,
palette bleu/vert, formes éditoriales sobres et niveau de détail modéré.

## Fonctionnement

Le paragraphe `hero` rend d'abord un éventuel champ Drupal Media
`field_hero_media` si le projet l'ajoute plus tard. Sans média, le thème
sélectionne une illustration SVG déterministe selon la page courante.

Le fallback obligatoire est `images/home/home-hero-ia-drupal.svg`.

## Bibliothèque SVG

| Contexte | Fichier | Intention métier |
| --- | --- | --- |
| Homepage | `images/home/home-hero-ia-drupal.svg` | Plateforme web structurée et performante |
| IA & Drupal | `images/ia-drupal/ia-drupal-page-hero.svg` | Modules éditoriaux, flux, gouvernance |
| Services | `images/services/services-page-hero.svg` | Architecture, composants, parcours |
| Contact | `images/contact/contact-page-hero.svg` | Cadrage projet, planning, accompagnement |
| Cas clients | `images/case-clients/case-clients-page-hero.svg` | Transformation, progression, résultats |
| Équipe | `images/team/team-page-hero.svg` | Collaboration humaine et décisions |

## Règles éditoriales

- Raconter un contexte métier, pas une technologie.
- Éviter robots, cerveaux IA, hologrammes, mascottes, avatars cartoon et
  imagerie startup générique.
- Garder des SVG sobres, lisibles et non textuels.
- Maintenir des textes alternatifs FR/EN dans
  `emerging_digital_hero_illustration_definitions()`.
- Ne pas modifier les menus ni `system.site:page.front` pour changer une
  illustration hero.

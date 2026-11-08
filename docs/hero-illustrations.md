# Hero illustrations

Le hero actuel reste la référence artistique : fond clair, lumière douce,
palette bleu/vert, formes éditoriales sobres et niveau de détail modéré.

## Fonctionnement

Le paragraphe `hero` rend d'abord un éventuel champ Drupal Media
`field_hero_media` si le projet l'ajoute plus tard. Sans média, le thème
sélectionne un visuel versionné déterministe selon la page courante.

Le choix est exclusif : si `field_hero_media` produit un rendu Drupal, le visuel de
fallback n'est pas exposé au template. Si aucun média n'est rendu, le fallback
versionné est utilisé. Cela évite les différences entre rendu anonyme et rendu
connecté, notamment quand les permissions ou métadonnées de rendu du champ média
varient.

Le fallback de la homepage V2 est `images/home/home-hero-collaboration.jpg` : photographie réelle de Mikhail Nilov ([Pexels #7731361](https://www.pexels.com/photo/a-person-using-a-laptop-7731361/), [licence Pexels](https://www.pexels.com/license/)). Cette photo d'illustration ne présente pas l'équipe E-merging Digital. Les autres pages utilisent leurs SVG.

## Bibliothèque des visuels de fallback

| Contexte | Fichier | Intention métier |
| --- | --- | --- |
| Homepage | `images/home/home-hero-collaboration.jpg` | Collaboration professionnelle, photo d'illustration |
| IA & Drupal | `images/ia-drupal/ia-drupal-page-hero.svg` | Modules éditoriaux, flux, gouvernance |
| Services | `images/services/services-page-hero.svg` | Architecture, composants, parcours |
| Contact | `images/contact/contact-page-hero.svg` | Cadrage projet, planning, accompagnement |
| Cas clients | `images/case-clients/case-clients-page-hero.svg` | Transformation, progression, résultats |
| Équipe | `images/team/team-page-hero.svg` | Collaboration humaine et décisions |

## Règles éditoriales

- Raconter un contexte métier, pas une technologie.
- Éviter robots, cerveaux IA, hologrammes, mascottes, avatars cartoon et
  imagerie startup générique.
- Garder les SVG secondaires sobres, lisibles et non textuels ; toute photo de stock doit être signalée sans la présenter comme une équipe ou un projet client.
- Maintenir des textes alternatifs FR/EN dans
  `emerging_digital_hero_illustration_definitions()`.
- Ne pas modifier les menus ni `system.site:page.front` pour changer une
  illustration hero.

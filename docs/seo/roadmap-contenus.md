# Roadmap contenus SEO

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/284

Date : 2026-05-20

## Objectif

Cette roadmap transforme la strategie SEO en tickets exploitables par Codex. Les
tickets futurs devront rester scopes : une intention, un contenu, des aliases
FR/EN, du maillage interne et des validations Content Sync.

Contraintes permanentes :

- ne pas modifier les menus sauf ticket explicite ;
- ne pas modifier `system.site:page.front` ;
- ne pas modifier Twig ;
- ne pas modifier le chatbot ;
- ne pas modifier les workflows GitHub Actions ;
- ne pas modifier `scripts/deploy-production.sh` ;
- ne pas recycler de `legacy_uuid`, mapping ou identifiant metier existant ;
- produire FR et EN pour tout contenu public gere par Content Sync.

## Phase 1 - Portes d'entree business

Objectif : capter les prospects qui cherchent une agence web, une creation, une
refonte ou un audit sans parler de Drupal.

### Ticket 1 - Page "Agence web Belgique"

Alias :

- FR : `/agence-web-belgique`
- EN : `/web-agency-belgium`

Objectif :

- Presenter E-merging Digital comme agence web senior, pas seulement Drupal.
- Relier creation, refonte, audit, developpement sur mesure, IA et Drupal.

Type recommande : `service`, sauf decision de hub compose en `page`.

Liens internes :

- `/creation-site-web-professionnel`
- `/refonte-site-internet`
- `/developpement-web-sur-mesure`
- `/agence-drupal-belgique`
- `/contact`

DoD contenu :

- Message clair "agence web senior".
- Pas de promesse agence 360.
- Section "quand Drupal est pertinent".
- Section "quand un developpement PHP/Symfony/Laravel est plus adapte".
- CTA vers contact.

### Ticket 2 - Page "Creation site web professionnel"

Alias :

- FR : `/creation-site-web-professionnel`
- EN : `/professional-website-creation`

Objectif :

- Capter "creation site web", "site professionnel", "site PME".
- Expliquer la methode avant de parler technologie.

Liens internes :

- `/creation-site-drupal`
- `/site-web-pme`
- `/site-web-asbl`
- `/accessibilite-seo-optimisation`
- `/contact`

DoD contenu :

- Angle : site durable, maintenable, visible.
- Inclure architecture de contenu, SEO technique, accessibilite, performance.
- Ne pas vendre Drupal comme reponse automatique.

### Ticket 3 - Page "Refonte site internet"

Alias :

- FR : `/refonte-site-internet`
- EN : `/website-redesign`

Objectif :

- Capter les besoins de modernisation et de perte de performance/SEO.
- Relier a la refonte Drupal existante lorsque Drupal est le socle.

Liens internes :

- `/refonte-site-drupal`
- `/audit-site-web`
- `/audit-drupal`
- `/migration-drupal`
- `/contact`

DoD contenu :

- Checklist des signaux de refonte.
- Section risques SEO et redirections.
- Section contenus et gouvernance.
- CTA audit/refonte.

### Ticket 4 - Page "Audit site web"

Alias :

- FR : `/audit-site-web`
- EN : `/website-audit`

Objectif :

- Creer une offre diagnostic generaliste qui peut orienter vers Drupal, SEO,
  performance, accessibilite ou IA.

Liens internes :

- `/audit-drupal`
- `/accessibilite-seo-optimisation`
- `/refonte-site-internet`
- `/ia-pour-pme`
- `/contact`

DoD contenu :

- Livrables d'audit clairs.
- Backlog priorise.
- Differencier audit site web et audit Drupal.

## Phase 2 - Pages par public

Objectif : qualifier les leads et parler les contraintes reelles.

### Ticket 5 - Page "Site web PME"

Alias :

- FR : `/site-web-pme`
- EN : `/sme-website`

Angle :

- Visibilite, conversion, maintenance, budget, priorisation.

Liens :

- `/creation-site-web-professionnel`
- `/refonte-site-internet`
- `/ia-pour-pme`
- `/maintenance-drupal`
- `/contact`

### Ticket 6 - Page "Site web ASBL"

Alias :

- FR : `/site-web-asbl`
- EN : `/non-profit-website`

Angle :

- Accessibilite, equipe reduite, publication, formulaires, dons, evenements,
  clarte editoriale.

Liens :

- `/accessibilite-seo-optimisation`
- `/creation-site-drupal`
- `/ia-drupal`
- `/contact`

### Ticket 7 - Page "Site web institutionnel"

Alias :

- FR : `/site-web-institutionnel`
- EN : `/institutional-website`

Angle :

- Gouvernance, multilingue, securite, accessibilite, validation, duree de vie.

Liens :

- `/audit-drupal`
- `/refonte-site-drupal`
- `/maintenance-drupal`
- `/accessibilite-seo-optimisation`
- `/contact`

## Phase 3 - Cluster PHP / Symfony / Laravel

Objectif : prouver que l'agence sait arbitrer au-dela de Drupal.

### Ticket 8 - Page "Developpement web sur mesure"

Alias :

- FR : `/developpement-web-sur-mesure`
- EN : `/custom-web-development`

Angle :

- Besoins applicatifs, integrations, API, workflows metier, interfaces internes.

Liens :

- `/developpement-php-sur-mesure`
- `/developpement-symfony`
- `/developpement-laravel`
- `/agence-drupal-belgique`
- `/contact`

### Ticket 9 - Page "Developpement PHP sur mesure"

Alias :

- FR : `/developpement-php-sur-mesure`
- EN : `/custom-php-development`

Angle :

- PHP senior, code maintenable, securite, tests, integrations, dette technique.

DoD contenu :

- Mentionner Drupal/Symfony/Laravel comme ecosysteme PHP.
- Expliquer les criteres de choix.
- Eviter de lister des technos sans cas d'usage.

### Ticket 10 - Page "Developpement Symfony"

Alias :

- FR : `/developpement-symfony`
- EN : `/symfony-development`

Angle :

- Applications structurees, API, logique metier, composants robustes.

Liens :

- `/developpement-php-sur-mesure`
- `/developpement-web-sur-mesure`
- `/agence-drupal-belgique`

### Ticket 11 - Page "Developpement Laravel"

Alias :

- FR : `/developpement-laravel`
- EN : `/laravel-development`

Angle :

- Applications metier, back-office, MVP evolutif, API, integrations.

Note :

- Rester credible : ne pas promettre une "agence Laravel" si les preuves
  publiques ne sont pas encore la. Positionner Laravel comme expertise PHP
  mobilisable selon besoin.

### Ticket 12 - Guide "Drupal, Symfony ou Laravel : comment choisir ?"

Format : article ou page guide.

Objectif :

- Capter les recherches comparatives.
- Montrer un arbitrage honnete.

Structure :

- tableau cas d'usage ;
- quand Drupal ;
- quand Symfony ;
- quand Laravel ;
- quand combiner CMS et application ;
- CTA audit technique.

## Phase 4 - Cluster IA encadree

Objectif : capter l'intention IA sans deplacer le site vers un discours gadget.

### Ticket 13 - Page "IA pour PME"

Alias :

- FR : `/ia-pour-pme`
- EN : `/ai-for-smes`

Angle :

- Cas utiles : contenu, support, qualification, synthese, reporting,
  automatisations simples.

DoD contenu :

- Toujours parler diagnostic avant automatisation.
- Inclure limites, RGPD, validation humaine.
- Lier vers `/ia-drupal`, `/ia-integree`, `/contact`.

### Ticket 14 - Page "Automatisation IA"

Alias :

- FR : `/automatisation-ia`
- EN : `/ai-automation`

Angle :

- Automatiser les taches repetitives sans casser les processus existants.

DoD contenu :

- Exemples concrets mais prudents.
- Ne pas promettre de ROI garanti.
- Distinguer automatisation, assistant IA et chatbot.

### Ticket 15 - Page ou guide "Chatbot IA encadre"

Alias :

- FR : `/chatbot-ia`
- EN : `/ai-chatbot`

Important :

- Ne pas modifier le chatbot existant dans ce ticket futur sauf demande
  explicite.
- La page doit parler cadrage, securite, fallback, limites et qualification des
  demandes.

### Ticket 16 - Guide "IA et SEO : usages utiles, limites et validation"

Format : article.

Objectif :

- Relier IA, contenus, maillage interne, meta descriptions et GEO/LLM SEO.

Liens :

- `/accessibilite-seo-optimisation`
- `/ia-drupal`
- `/ia-pour-pme`
- `/audit-site-web`

## Phase 5 - Renforcement du socle existant

Objectif : connecter les pages deja publiees aux nouveaux hubs.

### Ticket 17 - Mise a jour des liens de `agence-drupal-belgique`

Objectif :

- Ajouter des liens vers agence web, PHP sur mesure et developpement web sur
  mesure une fois ces pages creees.

Contraintes :

- Ne pas changer l'alias.
- Ne pas changer le role de blueprint Drupal.
- Garder 2 a 4 liens internes utiles.

### Ticket 18 - Mise a jour du hub `/services`

Objectif :

- Integrer les nouvelles pages business si elles deviennent des services
  prioritaires.

Contraintes :

- Utiliser les `promotions` Content Sync.
- Ne pas modifier les menus.
- Ne pas ecraser les cartes existantes hors besoin.

### Ticket 19 - Mise a jour de `/ia-drupal`

Objectif :

- Relier le hub IA Drupal aux pages IA generalistes.

Contraintes :

- Ne pas modifier le chatbot.
- Garder la promesse "IA utile et encadree".

### Ticket 20 - Mise a jour de la homepage

Objectif :

- Seulement si la priorite commerciale est confirmee : ajuster la phrase
  d'ouverture pour inclure "agence web senior" tout en gardant Drupal/PHP/IA.

Contraintes :

- Ne pas modifier `system.site:page.front`.
- Passer par Content Sync.
- Ne pas modifier Twig.
- Smoke test front obligatoire dans le ticket d'implementation.

## Phase 6 - Articles et preuves

Objectif : soutenir les pages commerciales par des contenus citables et utiles.

Articles prioritaires :

1. "Drupal ou WordPress pour une PME/ASBL : comment choisir ?"
2. "Checklist avant une refonte de site internet"
3. "Drupal, Symfony ou Laravel : quelle base pour votre projet web ?"
4. "IA pour PME : 7 cas utiles et 5 limites a respecter"
5. "SEO technique avant refonte : les points a verifier"
6. "Site web ASBL accessible : priorites avant design"
7. "Comment preparer un audit site web exploitable"
8. "LLM SEO / GEO : ce qui est utile pour un site Drupal"

Chaque article doit :

- repondre a une intention informationnelle ;
- contenir un tableau ou une checklist ;
- lier vers une page transactionnelle ;
- eviter le remplissage marketing ;
- etre bilingue si gere par Content Sync.

## Template de ticket futur

Chaque ticket contenu devrait inclure :

```md
Objectif :
- Creer ou mettre a jour [page] pour cibler [intention].

Fichiers concernes :
- web/modules/custom/emerging_digital_content/content_sync/catalog.yml
- web/modules/custom/emerging_digital_content/content_sync/node/[id].yml

Contraintes :
- FR/EN obligatoires.
- Alias FR/EN explicites.
- Pas de menu.
- Pas de Twig.
- Pas de workflow.
- Pas de deploy.
- Pas de chatbot.
- Pas de system.site:page.front.

Maillage :
- Lien vers [page 1].
- Lien vers [page 2].
- Lien vers [contact].

Validations :
- ddev drush emerging:content-sync:validate
- ddev drush emerging:content-sync [id] --dry-run
- ddev drush emerging:content-sync [id]
- git diff --check
```

## Priorisation synthetique

| Priorite | Tickets | Pourquoi |
| --- | --- | --- |
| P1 | 1 a 4 | Ouvrir l'acquisition hors Drupal |
| P1 | 5 a 7 | Qualifier PME/ASBL/institutions |
| P2 | 8 a 12 | Prouver l'expertise PHP et l'arbitrage technique |
| P2 | 13 a 16 | Occuper IA/GEO sans surpromesse |
| P3 | 17 a 20 | Relier le nouveau cocon aux pages existantes |
| P3 | Articles | Construire l'autorite longue traine |

## Definition de termine pour cette roadmap

Cette roadmap sera exploitable si les prochains tickets peuvent reprendre :

- un alias ;
- une intention ;
- un public ;
- un type de contenu recommande ;
- les liens internes ;
- les contraintes techniques ;
- les validations attendues.

Elle ne remplace pas la redaction des pages. Elle sert a produire des tickets
plus petits, plus propres et plus faciles a verifier.

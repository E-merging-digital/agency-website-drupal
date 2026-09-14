# #1117 — Candidat éditorial « Audit site web » FR/EN

Statut : **DELIVERY_CANDIDATE_ONLY / NOT_PUBLISHED**

```text
ISSUE = #1117
BUNDLE = service
EDITORIAL_OWNER_AFTER_MATERIALIZATION = DRUPAL
CONTENT_SYNC = FORBIDDEN
PREPROD_DURING_DELIVERY = NONE
PROD = NONE
LANGUAGE_NEGOTIATION = path_prefix
FR_PUBLIC_ROUTE = /fr/audit-site-web
FR_STORED_ALIAS = /audit-site-web
EN_PUBLIC_ROUTE = /en/website-audit
EN_STORED_ALIAS = /website-audit
```

Ce fichier versionne uniquement le candidat de livraison nécessaire à la revue Project Lead.
Il ne constitue ni un catalogue de contenu, ni une nouvelle source runtime, ni une route
de publication. Après matérialisation autorisée, Drupal reste la source éditoriale.

Les routes publiques incluent le préfixe de langue géré par la négociation Drupal.
Les aliases stockés dans Drupal n'incluent jamais ce préfixe : la langue est portée
séparément par l'entité `path_alias`.

## Capacité existante retenue

Le candidat réutilise le bundle `service` existant :

- titre du node = H1 ;
- `field_short_description` = introduction courte et source des métadonnées existantes ;
- `field_detailed_description` = contenu HTML `basic_html` ;
- template `node--service.html.twig` existant ;
- CTA de qualification existant vers `/fr/contact` ou `/en/contact`, avec type `audit`
  déduit du titre ;
- traduction Drupal FR/EN ;
- routes publiques localisées et aliases Drupal stockés explicitement ci-dessous ;
- métadonnées, canonical, hreflang et sitemap via les capacités Drupal déjà en place.

Aucun nouveau Paragraph, SDC, Webform, content type, scanner, analytics ou composant
n'est requis pour ce candidat.

---

## FR

```text
langcode = fr
public_route = /fr/audit-site-web
stored_alias = /audit-site-web
title = Audit de site web : clarifier les priorités avant d’investir
```

### `field_short_description`

Un audit de site web pour identifier ce qui mérite réellement d’être corrigé, priorisé ou approfondi avant une refonte, une migration ou un investissement.

### `field_detailed_description`

```html
<h2>Quand un audit de site web devient utile</h2>
<p>Un site peut continuer à fonctionner tout en devenant plus difficile à faire évoluer, moins clair pour ses visiteurs ou moins efficace pour l’organisation. Un audit est pertinent lorsqu’il faut distinguer les problèmes réellement prioritaires des améliorations secondaires avant d’engager un budget.</p>
<p>Les signaux peuvent être une baisse des conversions, une maintenance devenue lourde, des performances insuffisantes, des difficultés SEO ou d’accessibilité, des intégrations fragiles, une gouvernance éditoriale compliquée, des questions de sécurité ou simplement un besoin d’évolution que l’équipe ne sait pas encore cadrer.</p>

<h2>Ce que nous examinons selon votre contexte</h2>
<ul>
<li>les objectifs métier, les publics et les parcours importants ;</li>
<li>le contenu, la structure de l’information et le SEO ;</li>
<li>la performance et l’expérience sur mobile ;</li>
<li>l’accessibilité et les obstacles qui pénalisent certains utilisateurs ;</li>
<li>l’architecture, la dette technique et la maintenabilité ;</li>
<li>le CMS, PHP et Drupal lorsque ces technologies font partie du contexte ;</li>
<li>les formulaires, intégrations et échanges de données ;</li>
<li>les analytics et la capacité à mesurer ce qui compte réellement ;</li>
<li>la maintenance, l’exploitation et la capacité à déployer des changements proprement ;</li>
<li>les opportunités d’IA ou d’automatisation uniquement lorsqu’elles apportent une valeur concrète.</li>
</ul>
<p>Pour les sujets centrés sur qualité web, performance, SEO et accessibilité, consultez aussi <a href="/fr/accessibilite-seo-optimisation">notre approche accessibilité, SEO et optimisation</a>.</p>

<h2>Un livrable pour décider, pas une liste de défauts</h2>
<p>Le périmètre est défini avant l’audit. Selon le besoin, le livrable rassemble les constats utiles, les risques à traiter, les quick wins, un backlog priorisé et plusieurs scénarios réalistes. L’objectif est de savoir quoi faire maintenant, quoi planifier ensuite et ce qui ne mérite pas d’investissement immédiat.</p>

<h2>Parfois, quelques corrections suffisent</h2>
<p>Une refonte complète n’est pas la conclusion par défaut. Si quelques corrections ciblées peuvent résoudre le problème de façon durable, elles doivent apparaître comme une option crédible. Une <a href="/fr/refonte-site-internet">refonte de site internet</a> n’est recommandée que lorsque les constats la justifient.</p>
<p>La même logique vaut pour une plateforme WordPress existante : lenteur, accumulation de plugins, maintenance, sécurité ou SEO peuvent être audités sans présumer qu’une migration est nécessaire.</p>

<h2>Audit de site web ou audit Drupal ?</h2>
<p>L’audit de site web part du besoin métier et reste technologiquement neutre. Il aide à déterminer si le vrai sujet concerne les parcours, le contenu, la performance, l’accessibilité, la maintenance, le CMS, les intégrations ou une combinaison de plusieurs facteurs.</p>
<p>Si vous savez déjà que Drupal est au centre du problème et que vous avez besoin d’une analyse technique plus profonde de l’architecture, des modules, des mises à jour ou de la maintenabilité, consultez notre <a href="/fr/audit-drupal">audit Drupal</a>.</p>

<h2>Et l’IA ? Seulement si elle résout un problème réel</h2>
<p>Un audit peut faire émerger des opportunités d’automatisation ou d’IA, mais elles ne sont pas ajoutées artificiellement au diagnostic. Lorsqu’un usage est pertinent, notre approche <a href="/fr/ia-pour-pme">IA pour PME</a> permet de le cadrer séparément.</p>

<h2>Commencer par une orientation humaine</h2>
<p>Le premier échange sert à comprendre le contexte et à vérifier si un audit formel est réellement justifié. S’il faut simplement vous orienter vers quelques contrôles ou une amélioration ciblée, nous le disons avant de proposer un chantier plus large.</p>
```

### CTA

```text
PRIMARY = existing service qualification CTA -> /fr/contact?type=audit&source=service&context=<title>#contact-form
SECONDARY = /fr/audit-drupal
FIRST_ORIENTATION = HUMAN / LOW_FRICTION
GENERAL_WEBSITE_AUDIT = SCOPED / PAID WHEN JUSTIFIED
```

---

## EN

```text
langcode = en
public_route = /en/website-audit
stored_alias = /website-audit
title = Website audit: clarify priorities before you invest
```

### `field_short_description`

A website audit to identify what genuinely needs fixing, prioritising or deeper investigation before a redesign, migration or significant investment.

### `field_detailed_description`

```html
<h2>When a website audit becomes useful</h2>
<p>A website can keep running while becoming harder to evolve, less clear for visitors or less effective for the organisation. An audit is useful when you need to separate genuinely important issues from secondary improvements before committing budget.</p>
<p>Signals may include declining conversions, burdensome maintenance, poor performance, SEO or accessibility issues, fragile integrations, difficult editorial governance, security and maintenance concerns, or simply an evolution need that the team cannot yet frame confidently.</p>

<h2>What we examine according to your context</h2>
<ul>
<li>business goals, audiences and important user journeys;</li>
<li>content, information structure and SEO;</li>
<li>performance and the mobile experience;</li>
<li>accessibility and barriers affecting some users;</li>
<li>architecture, technical debt and maintainability;</li>
<li>the CMS, PHP and Drupal when those technologies are relevant;</li>
<li>forms, integrations and data exchanges;</li>
<li>analytics and the ability to measure what actually matters;</li>
<li>maintenance, operations and the ability to deploy changes safely;</li>
<li>AI or automation opportunities only when they bring concrete value.</li>
</ul>
<p>For issues centred on web quality, performance, SEO and accessibility, see our <a href="/en/ai-accessibility-seo-optimization">accessibility, SEO and optimisation approach</a>.</p>

<h2>A deliverable for decisions, not a list of defects</h2>
<p>The scope is agreed before the audit. Depending on the need, the deliverable brings together useful findings, risks to address, quick wins, a prioritised backlog and realistic scenarios. The goal is to know what to do now, what to plan next and what does not justify immediate investment.</p>

<h2>Sometimes a few targeted fixes are enough</h2>
<p>A complete redesign is not the default conclusion. If a small number of targeted changes can solve the problem durably, that should remain a credible option. A <a href="/en/website-redesign">website redesign</a> is recommended only when the findings justify it.</p>
<p>The same principle applies to an existing WordPress platform: slow performance, plugin debt, maintenance, security or SEO can be audited without assuming that a migration is necessary.</p>

<h2>Website audit or Drupal audit?</h2>
<p>A website audit starts with the business need and remains technology-neutral. It helps determine whether the real issue is user journeys, content, performance, accessibility, maintenance, the CMS, integrations or a combination of several factors.</p>
<p>If you already know Drupal is central to the problem and need a deeper technical review of architecture, modules, updates or maintainability, see our <a href="/en/drupal-audit">Drupal audit</a>.</p>

<h2>What about AI? Only when it solves a real problem</h2>
<p>An audit may reveal useful AI or automation opportunities, but they are not forced into the diagnosis. When a use case is relevant, our <a href="/en/ai-for-smes">AI for SMEs</a> approach can frame it separately.</p>

<h2>Start with a human orientation</h2>
<p>The first conversation is used to understand the context and check whether a formal audit is genuinely justified. If the useful next step is simply a few checks or a targeted improvement, we say so before proposing a broader engagement.</p>
```

### CTA

```text
PRIMARY = existing service qualification CTA -> /en/contact?type=audit&source=service&context=<title>#contact-form
SECONDARY = /en/drupal-audit
FIRST_ORIENTATION = HUMAN / LOW_FRICTION
GENERAL_WEBSITE_AUDIT = SCOPED / PAID WHEN JUSTIFIED
```

---

## Validation de livraison attendue

```text
COMMERCIAL_EQUIVALENCE = PASS
GENERAL_AUDIT_VS_DRUPAL_AUDIT = CLEAR
REFONTE_NOT_ASSUMED = PASS
WORDPRESS_GENERALIST_CREATION_OFFER = NO
CMS_WAR = NO
FR_EN = REQUIRED / SATISFIED
CONTENT_SYNC_READMISSION = NONE
NEW_SCANNER = NONE
NEW_WEBFORM = NONE
NEW_CONTENT_TYPE = NONE
NEW_COMPONENT = NONE
PREPROD = NONE
PROD = NONE
```
# Agency #1417 — Correspondance MVP NL-BE dans Drupal (R3, SOURCE_ONLY)

Autorité R3 : https://github.com/E-merging-digital/agency-website-drupal/issues/1417#issuecomment-6887758295
Texte provisoire non approuvé : https://github.com/E-merging-digital/agency-website-drupal/issues/1417#issuecomment-6887681547

| Élément | Identité vérifiée en source | Éditeur Drupal natif — travaux différés |
| --- | --- | --- |
| Accueil | system.site page.front=/node/5 ; révision runtime non prouvée | Traduire le nœud existant et ses Paragraphs Hero / CTA / ALT ; route /nl seulement après contenu NL réel. |
| Services | Route publique /fr/services ; ID/révision non prouvés ; Paragraph services : field_heading, field_text, field_items | Identifier le nœud dans Drupal, traduire ses Paragraphs/cartes ; alias candidat /diensten et aucun lien vers sous-page NL non traduite. |
| Contact | UUID dans le thème d962ec05-25ec-4446-b645-0959a57964e7 ; ID/révision runtime non prouvés | Traduire ce nœud ; vérifier rendu Contact V3, Webform contact, messages et consentement. |
| Formulaire | config/sync/webform.webform.contact.yml et override NL candidat | Installer en DEV isolé autorisé via Config Translation ; vérifier les validations natives ; aucune modification des handlers e-mail. |
| Menu et footer | menu_link_content.main/footer traduisibles, blocs footer versionnés | Traduire les liens Home / Diensten / Contact, puis liens légaux uniquement vers pages NL réellement traduites. Les labels footer ont un override NL source. |
| Pages légales | Trois pages FR publiques ; IDs/révisions inconnus | Traduire leurs entités existantes ; alias candidats /privacyverklaring, /cookiebeleid, /wettelijke-vermeldingen ; relecture juridique obligatoire. |
| SEO | Metatag page/front, Pathauto existants | En Drupal : titre, résumé, alias, canonical, hreflang et sitemap ; ne pas supposer une traduction ou indexer des pages NL non réelles. |

NL_VISIBILITY_HARD_GATE=ACTIVE. Ajouter language.entity.nl et un préfixe /nl ne protège pas les accès directs contre le fallback FR/EN. Le LanguageSwitcherBlock ne garantit rien pour les routes sans entité. Ne pas fusionner/importer/activer cette Draft sans vérification isolée de toutes les routes, SEO, menu, Webform et négation des fallbacks. RUNTIME_NOT_PROVEN.

Relecture linguistique NL-BE (kmo, vzw, AVG, CTA) et juridique (consentement, durée de conservation annoncée 12 mois, RGPD, cookies/Google Maps) en attente. Texte CANDIDATE_FOR_REVIEW. Default FR et lock FR/true inchangés. Aucun déploiement ni activation.

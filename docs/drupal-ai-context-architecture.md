# Architecture de contexte IA partagé et gouverné

Statut : **CONTRAT CIBLE — WAIT FOR UPSTREAM**  
Issue : #387  
Parent : #32  
Date : 2026-08-15

## 1. Objectif

Le projet contient déjà beaucoup de contexte utile aux fonctionnalités IA :
positionnement commercial, ton éditorial, audiences, règles SEO, accessibilité,
doctrine IA, terminologie FR/EN, contenu public et règles propres au chatbot.

Le problème n'est pas l'absence de contexte. Le problème est sa **dispersion** et
le risque qu'un nouveau prompt copie quelques règles, oublie les autres puis
dérive avec le temps.

Cette architecture définit :

- les sources de vérité actuelles ;
- les catégories de contexte réutilisables ;
- le contrat de gouvernance/versioning/traduction ;
- le contexte que chaque capacité IA peut réellement consommer ;
- la frontière entre contexte partagé et contexte spécifique à un use case ;
- le verdict sur Context Control Center (`ai_context`).

Ce ticket n'installe aucun module et ne crée aucun framework générique custom.

## 2. Verdict upstream

Au 2026-08-15, Context Control Center est publié en `1.0.0-beta2` et ne dispose
pas encore de release stable supportée. Il propose la direction fonctionnelle
pertinente : context items révisables, multilingues, gouvernés, scopes, limites,
liaison aux agents et suivi d'usage.

Verdict projet :

```text
Context Control Center en production
-> WAIT

contrat de contexte partagé
-> DEFINE NOW

nouveau framework générique custom Agency
-> DO NOT BUILD

adaptation minimale vers les sources existantes
-> autorisée seulement lorsqu'un consumer réel le nécessite
```

Le passage à `ADOPT` nécessite une nouvelle vérification de maturité : release
stable adaptée, couverture sécurité, compatibilité avec la branche Drupal AI du
projet et preuve locale bornée.

## 3. Inventaire des sources actuelles

### 3.1 `AGENTS.md`

Rôle : doctrine d'implémentation pour développeurs/agents de code.

Contient notamment :

- architecture Drupal du dépôt ;
- doctrine Drupal AI upstream-first ;
- workflow Content Sync ;
- règles SEO/multilingues ;
- contraintes menus/homepage ;
- commandes de validation ;
- interdictions opérationnelles.

Verdict : **source de vérité développeur**, pas contexte éditorial runtime à
injecter dans CKEditor/chatbot. Certaines règles IA y résument des décisions
documentées ailleurs mais ne doivent pas être recopiées dans les prompts.

### 3.2 `docs/drupal-ai-architecture.md`

Rôle : doctrine IA du produit.

Source de vérité pour :

- abstraction Drupal AI ;
- politique provider ;
- validation humaine ;
- secrets ;
- stabilité/sécurité des modules ;
- frontière de l'implémentation legacy de traduction.

Verdict : **source de vérité governance/architecture IA**.

### 3.3 `docs/seo/strategie-seo.md`

Rôle : positionnement commercial et éditorial.

Source de vérité pour :

- positionnement « agence web senior » ;
- hiérarchie besoin business → PHP/architecture → solution → IA → qualité ;
- audiences PME / ASBL / institutions ;
- ton senior, calme, concret, transparent ;
- formulations à privilégier / éviter ;
- intentions de recherche ;
- architecture de conversion ;
- règles de preuve et de maillage.

Verdict : **source de vérité brand/editorial/SEO jusqu'à migration éventuelle
vers une capacité de contexte gouvernée**.

### 3.4 `docs/seo/roadmap-contenus.md`

Rôle : planification des futurs contenus et intentions SEO.

Verdict : contexte de **planification**, pas un style guide à injecter
systématiquement dans une génération. Un consumer peut en extraire un brief de
ticket, mais ne doit pas charger la roadmap entière à chaque requête.

### 3.5 `docs/content/visual-assets.md`

Rôle : direction visuelle.

Source de vérité pour :

- esthétique sobre/premium/professionnelle ;
- palette et traitement ;
- réalisme éditorial ;
- refus des clichés IA futuristes/gadget ;
- principes de prompts image et alt text.

Verdict : **source de vérité visual context** pour les capacités de suggestion
ou génération d'assets, pas pour tous les appels textuels.

### 3.6 Content Sync

Chemins :

```text
web/modules/custom/emerging_digital_content/content_sync/catalog.yml
web/modules/custom/emerging_digital_content/content_sync/node/*.yml
```

Rôle : contenu public versionné et approuvé pour les pages gérées.

Source de vérité pour :

- intitulés de services ;
- faits et promesses publiques ;
- texte FR/EN ;
- aliases ;
- CTA et liens ;
- preuves réellement publiées.

Verdict : **source de vérité factuelle publique**. Un contexte partagé ne doit
pas recopier les pages complètes et devenir une seconde base éditoriale.

### 3.7 Drupal actif

Le contenu éditorial courant non géré par Content Sync — notamment les futurs
articles Blog — vit dans Drupal avec traduction, révisions et workflow normal.

Verdict : **source de vérité pour le contenu éditorial actif**. Une IA qui agit
sur une entité doit recevoir l'entité/revision cible au moment de l'opération,
pas une copie ancienne dans un contexte global.

### 3.8 `emerging_digital_chatbot.settings`

Rôle : contexte/policy spécifique au chatbot public.

Contient :

- prompts FR/EN ;
- règles de sécurité/commerciales ;
- messages/fallbacks ;
- chemins publics autorisés ;
- flows et CTA ;
- limites FutureAi.

Verdict : **source runtime spécifique au chatbot** tant que la convergence
FutureAi de #388 n'est pas réalisée. Les règles génériques de ton/gouvernance
qui y sont répétées sont des candidates à consommation future d'un contexte
partagé, mais les flows, CTA, limites et messages de fallback restent propres au
chatbot.

### 3.9 `PublicContextBuilder` / `PublicAiContextProvider`

Rôle : contexte public borné pour FutureAi chatbot.

Ils appliquent :

- allowlist de chemins ;
- nodes publiés/traduits seulement ;
- allowlist de champs ;
- sanitation emails/téléphones/secrets ;
- budget de contexte.

Verdict : **policy spécifique utile**, à conserver selon #388. Ne pas généraliser
ce builder en « context framework » du projet.

## 4. Duplications actuelles

Les principales répétitions observées sont intentionnelles à l'origine mais
deviennent risquées avec plusieurs consumers IA.

| Sujet | Sources qui se chevauchent | Risque |
| --- | --- | --- |
| Ton professionnel/sobre | stratégie SEO, chatbot prompts, contenus IA, visual assets | formulations divergentes |
| Positionnement Drupal + IA / agence web senior | stratégie SEO, contenu public, chatbot | ancien positionnement trop Drupal dans un prompt |
| Validation humaine IA | AGENTS, architecture IA, contenus IA, chatbot | oubli par un nouveau consumer |
| Données sensibles / secrets | architecture IA, chatbot prompt, payload sanitizer, contexte public | confondre règle de prompt et contrôle technique |
| Audiences PME/ASBL/institutions | stratégie SEO, pages Content Sync | ciblage incohérent |
| Terminologie FR/EN | Content Sync, chatbot, articles futurs | traductions lexicales divergentes |
| Accessibilité/SEO | stratégie SEO, pages services, AGENTS | injecter une règle technique hors contexte |

Règle : **une duplication nécessaire à l'exécution technique peut rester**, mais
elle ne doit pas devenir une deuxième source de vérité sémantique.

Exemple : `ChatbotPayloadSanitizer` doit continuer de bloquer des données
sensibles même si le contexte dit « ne demandez pas de données sensibles ».
La règle de contexte n'est pas une barrière de sécurité.

## 5. Taxonomie cible des contextes

Le contrat cible distingue huit familles.

### A. `brand.positioning`

Contenu :

- raison d'être/positionnement ;
- proposition de valeur ;
- hiérarchie des expertises ;
- claims autorisés et claims interdits.

Source actuelle : `docs/seo/strategie-seo.md` + faits publiés Content Sync.

### B. `brand.voice`

Contenu :

- ton ;
- niveau de langage ;
- formulations à privilégier/éviter ;
- concision ;
- style de preuve ;
- refus des superlatifs non démontrés.

Source actuelle : `docs/seo/strategie-seo.md`.

### C. `audience.*`

Scopes :

- `audience.sme` ;
- `audience.non_profit` ;
- `audience.institution` ;
- futur scope explicite si un public réel apparaît.

Contenu : problèmes, attentes, vocabulaire, niveau technique, contraintes.

Source actuelle : stratégie SEO + pages publiques.

### D. `quality.web`

Contenu :

- SEO technique ;
- accessibilité ;
- performance ;
- gouvernance de contenu ;
- principes de maillage.

Source actuelle : stratégie SEO + `AGENTS.md` pour les conventions techniques.
Les règles développeur ne doivent pas être transmises telles quelles à un
éditeur.

### E. `ai.governance`

Contenu :

- Drupal AI upstream-first ;
- validation humaine ;
- pas d'auto-publication par défaut ;
- secrets hors contexte ;
- providers abstraits ;
- stabilité/sécurité ;
- minimisation et provenance.

Source actuelle : `docs/drupal-ai-architecture.md`.

### F. `terminology.<langcode>`

Contenu : vocabulaire métier approuvé et correspondances FR/EN, par exemple
noms des services, Drupal/IA, CTA et termes à ne pas traduire.

Source actuelle : contenus bilingues Content Sync + vocabulaire runtime approuvé.

Ce contexte doit être un **glossaire**, pas une copie de toutes les pages.

### G. `visual.direction`

Contenu : style, composition, traitement, clichés à éviter, principes ALT.

Source actuelle : `docs/content/visual-assets.md`.

### H. `use_case.*`

Contexte propre à une capacité :

- chatbot public ;
- traduction ;
- rewrite/review CKEditor ;
- automator précis ;
- futur agent.

Cette famille ne doit contenir que ce qui ne peut pas être partagé proprement.

## 6. Contrat logique d'un context item

Aucun schéma custom n'est implémenté dans #387. Le contrat ci-dessous décrit les
métadonnées qu'une future solution upstream ou un adapter minimal doit pouvoir
représenter.

```text
id                  stable machine id
scope               brand.voice / audience.sme / ...
status              draft / approved / retired
language            neutral / fr / en
source_of_truth     fichier, config ou entity/revision
owner               rôle responsable
consumer_allowlist  CKEditor / Translate / chatbot / agent / ...
sensitivity         public / internal / restricted
provenance          version/commit/revision
approved_at         information d'approbation si applicable
content             contenu réellement transmis
```

Ne jamais inclure :

- secret/provider key ;
- mot de passe/token ;
- conversation visiteur ;
- webform submission ;
- document privé ;
- donnée personnelle sans nécessité et base explicite.

## 7. Sources de vérité et gouvernance

| Domaine | Source de vérité actuelle | Mode d'approbation | Traduction |
| --- | --- | --- | --- |
| Architecture IA | `docs/drupal-ai-architecture.md` | PR Git | plutôt neutre |
| Positionnement / ton | `docs/seo/strategie-seo.md` | PR Git | contexte conceptuel + exemples FR/EN |
| Roadmap SEO | `docs/seo/roadmap-contenus.md` | PR Git | non injectée globalement |
| Direction visuelle | `docs/content/visual-assets.md` | PR Git | alt/prompts selon besoin |
| Faits pages versionnées | Content Sync | PR + validation Drupal | FR/EN explicites |
| Articles / contenu courant | Drupal | permissions + révision/workflow | traductions Drupal |
| Chatbot policy/runtime | `emerging_digital_chatbot.settings` + code sécurité | PR + config import | FR/EN |
| Conventions développeur | `AGENTS.md` | PR Git | non destinées au runtime éditorial |

Lorsqu'une information est déjà possédée par une source de vérité, un future
context item doit **référencer/adapter** cette source ou être régénérable depuis
elle. Il ne devient pas automatiquement propriétaire de l'information.

## 8. Traduction et terminologie

Le contexte partagé ne doit pas traduire aveuglément une version française vers
l'anglais à chaque requête.

Règles :

- les règles purement techniques peuvent être `neutral` ;
- le ton et les exemples éditoriaux peuvent avoir des variantes FR/EN ;
- les audiences gardent un concept commun mais peuvent porter des formulations
  propres à chaque langue ;
- un glossaire approuvé mappe les termes réellement sensibles ;
- les aliases, noms de pages et CTA proviennent des sources publiques courantes ;
- AI Translate doit recevoir le glossaire utile + le contexte de l'entité, pas
  toutes les règles SEO du site.

## 9. Least-context : ne pas tout envoyer à tout le monde

Plus de contexte n'est pas nécessairement mieux. Chaque consumer doit déclarer
ses scopes autorisés.

### AI CKEditor

Contexte typique :

```text
brand.voice
brand.positioning
quality.web (sous-ensemble éditorial)
audience.<cible> si connue
terminology.<langcode>
entity/revision courante
use_case.ckeditor.<operation>
```

Ne pas injecter `AGENTS.md`, la roadmap SEO complète ou le contexte chatbot.

### AI Automators

Contexte typique : uniquement le contexte requis par l'automator : entité,
champ, langue, policy IA et éventuellement brand voice/terminologie.

Un automator de métadonnées SEO n'a pas besoin de la direction visuelle ; un
automator de classification n'a pas besoin du prompt chatbot.

### AI Translate

Contexte typique :

```text
source entity/revision
source + target language
terminology.fr/en
brand.voice si le champ est éditorial
use_case.translate
```

La traduction doit préserver format, sens et contraintes de champ. Le contexte
ne doit pas être utilisé pour ajouter des faits absents du texte source.

### Chatbot public

Contexte typique :

```text
policy chatbot spécifique
brand.voice minimal
contenu public allowlisté et frais
langue
use_case.chatbot
```

Ne pas transmettre des docs internes, roadmap, conventions développeur ou
données non publiques.

### Futurs agents

Un agent reçoit :

- contexte selon scopes explicites ;
- permissions minimales ;
- tools séparément gouvernés ;
- entité/objet cible explicite ;
- provenance de ce qu'il utilise.

Aucun agent ne reçoit « tout le contexte Agency » par défaut.

## 10. Context Control Center : critères d'adoption

Le projet revalidera `drupal/ai_context` lorsque :

1. une release stable compatible avec la pile projet existe ;
2. la couverture sécurité est adaptée à la production ;
3. les context items supportent les révisions/permissions/traductions requises ;
4. les scopes permettent le least-context ;
5. CKEditor/Agents ou adapters nécessaires peuvent consommer le contexte sans
   couplage provider ;
6. limites de taille/tokens et provenance sont inspectables ;
7. export/migration/rollback sont compris ;
8. une preuve locale montre qu'aucun workflow éditorial courant ne régresse.

Si l'upstream répond à ces critères : verdict futur **ADOPT**.

S'il reste incomplet mais offre une API stable utilisable : **ADAPTER MINIMAL**,
sans reconstruire son storage/UI/workflow.

Sinon : **WAIT**.

## 11. Ce que le projet ne doit pas construire en attendant

Interdit par cette décision sans nouveau besoin explicite :

- nouvelle entité custom `agency_ai_context` ;
- base vectorielle de contexte de marque juste pour anticiper ;
- registry/service locator générique de contextes ;
- UI custom de scopes/approbation ;
- synchronisation bidirectionnelle docs ↔ Drupal ;
- duplication massive des pages Content Sync dans du YAML de prompts ;
- prompt global contenant toute la connaissance du site.

## 12. Adapter minimal autorisé

Avant adoption upstream, une capacité réelle peut avoir besoin d'un petit
adapter. Il est acceptable seulement s'il :

- sert un consumer identifié ;
- lit une source de vérité existante ;
- sélectionne quelques scopes explicites ;
- ne crée pas de stockage parallèle ;
- ne possède pas d'UI générique ;
- est testable et remplaçable ;
- ne connaît aucun provider.

Exemple conceptuel : un service CKEditor pourrait assembler `brand.voice` et un
glossaire depuis des ressources versionnées existantes. Ce service n'est pas à
implémenter avant le ticket du consumer.

## 13. Provenance et fraîcheur

Chaque contexte utilisé par une capacité IA doit permettre de savoir :

- quelle source a été utilisée ;
- quelle version/revision était courante ;
- quelle langue ;
- quels scopes ;
- quelle capacité l'a demandé.

Pour du contenu Drupal dynamique, la revision/entity courante doit être résolue
au moment de l'opération. Un snapshot dans Git ne doit pas prétendre être plus
frais que Drupal.

Les futurs travaux #389 sur l'observabilité doivent pouvoir enregistrer les ids
de contexte/scopes sans journaliser leur contenu complet par défaut.

## 14. Permissions

Le contexte suit le principe du moindre privilège.

- un éditeur peut utiliser un contexte approuvé sans nécessairement pouvoir le
  modifier ;
- une modification de policy/gouvernance nécessite un rôle plus élevé ;
- une IA ne gagne jamais accès à une source seulement parce qu'un contexte la
  référence ;
- le contexte public chatbot n'élargit jamais l'accès au contenu non publié ;
- les permissions de l'entité source restent la borne principale pour les
  operations Inside AI.

## 15. Cycle de vie

Cycle cible :

```text
source de vérité modifiée
        |
        v
review / moderation normale
        |
        v
contexte approuvé/résolu
        |
        v
consumer sélectionne des scopes
        |
        v
appel Drupal AI provider-agnostic
        |
        v
provenance / observabilité minimale
```

Un contexte retiré doit pouvoir être marqué obsolète/retired sans casser les
anciens contenus qui ont été générés avec une version précédente.

## 16. Relations avec les autres tickets

- #379 : Guardrails protège les appels ; il ne remplace pas la gouvernance du
  contexte.
- #380 : AI CKEditor sera le premier consumer éditorial probable de
  `brand.voice` / terminologie / entity context.
- #381 : chaque Automator doit demander le minimum nécessaire.
- #382 : AI Translate doit surtout consommer terminologie + entity/revision.
- #388 : `PublicContextBuilder` garde sa policy spécifique, sans devenir le
  framework partagé.
- #389 : observabilité/provenance des scopes utilisés, privacy-first.

## 17. Plan d'adoption sans big-bang

### Maintenant

- conserver toutes les sources de vérité actuelles ;
- ne pas installer `ai_context` ;
- éviter toute nouvelle duplication ;
- faire référencer ce document par les futurs tickets IA.

### Avec #380 / #381 / #382

Pour chaque consumer :

1. identifier ses scopes ;
2. lire les sources existantes ;
3. ajouter au maximum un adapter minimal borné ;
4. tester FR/EN et absence de données hors scope ;
5. documenter la provenance.

### Quand Context Control Center devient adoptable

1. revalider maturité/sécurité ;
2. installer uniquement dans un ticket dédié ;
3. migrer d'abord un petit scope (`brand.voice` par exemple) ;
4. prouver permissions, traduction, révisions et rollback ;
5. migrer ensuite consumer par consumer ;
6. retirer les adapters temporaires uniquement après parité.

## 18. DoD de la gouvernance contexte

Le contexte partagé sera considéré maîtrisé lorsque :

- chaque information importante a une source de vérité identifiable ;
- aucun consumer ne copie un mega-prompt global ;
- les scopes consommés sont explicites ;
- FR/EN et terminologie sont gouvernés ;
- permissions/provenance sont conservées ;
- aucun secret/PII inutile n'est inclus ;
- les sources dynamiques restent fraîches ;
- l'upstream est adopté seulement lorsqu'il est suffisamment mature.

## 19. Sources upstream vérifiées

Sources officielles consultées le 2026-08-15 :

- Context Control Center : `https://www.drupal.org/project/ai_context`
- Releases CCC : `https://www.drupal.org/project/ai_context/releases`
- Drupal AI : `https://project.pages.drupalcode.org/ai/`

État vérifié : `ai_context 1.0.0-beta2` est la dernière release publiée ; le
projet indique qu'il n'existe actuellement aucune release stable supportée.

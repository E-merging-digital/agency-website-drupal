# Outside AI read-only — évaluation Tool API / MCP / ARD

Statut : **WAIT — runtime externe non activable proprement aujourd'hui**  
Date : **2026-08-16**  
Ticket : **#390**  
Parent : **#32**

## 1. Verdict

La trajectoire Outside AI reste valide, mais **aucun runtime agentique externe ne doit être ajouté à la production dans l'état upstream actuel**.

Décision :

```text
Tool API
-> base architecturale pertinente
-> compatible avec le socle Drupal AI actuel
-> encore beta, pas de release stable supportée
-> ADOPT LATER candidate, pas de dépendance production maintenant

MCP Server
-> runtime upstream pertinent pour STDIO + HTTP
-> encore beta, pas de release stable supportée
-> version 2.0.0-beta1 bloquée par la politique Composer de sécurité du projet
-> WAIT

AI Agents
-> stable et compatible
-> non requis pour le premier Outside AI read-only
-> ne pas l'ajouter uniquement pour exposer des reads externes

ARD / ai-catalog.json
-> spécification pertinente pour la découvrabilité future
-> v0.9 Draft / Proposal
-> aucune ressource agentique réelle exposable aujourd'hui
-> ne publier aucun catalogue aspirant
-> WAIT
```

Verdict global #390 :

```text
WAIT
```

Le projet ressort néanmoins avec un contrat de surface read-only prêt à être matérialisé lorsque le bridge upstream sera suffisamment sûr et stable.

## 2. État upstream revalidé

### Tool API

La page projet officielle confirme que Tool API définit des tools à entrées/sorties typées, réutilisables notamment par AI Agents et MCP. Elle indique également qu'il n'existe actuellement **aucune release stable supportée**.

Le solveur Composer exécuté le 16 août 2026 avec :

```text
drupal/tool:^1.0@beta
```

a résolu la version :

```text
1.0.0-beta6
```

Cette version s'installe dans un workspace jetable tout en conservant :

```text
drupal/ai 1.4.6
```

L'inspection du code exact de `1.0.0-beta6` montre notamment :

- des entrées et sorties typées ;
- un enum d'opérations `Explain`, `Read`, `Transform`, `Trigger`, `Write` ;
- `Explain`, `Read` et `Transform` classés non-modifiants et idempotents ;
- un marqueur `destructive` dans la définition de tool ;
- un `access()` qui délègue obligatoirement au `checkAccess()` du plugin ;
- une identité logique d'`invoker` disponible dans le tool et propagée aux événements de transformation ;
- des commandes Drush `tool:info` et `tool:run` avec sortie JSON ;
- `tool:run --uid=<id>` permettant d'exécuter sous un principal Drupal explicite ;
- les exceptions inattendues journalisées côté Drupal avec un message externe assaini.

Point important : Tool API ne fournit plus un catalogue générique de tools par défaut. C'est favorable pour Agency : la future surface peut rester explicitement minimale plutôt que d'exposer une collection administrative large.

### AI Agents

Le projet AI Agents possède une branche stable/security-covered 1.3.x. Le solveur Composer live a résolu :

```text
drupal/ai_agents 1.3.4
```

avec :

```text
drupal/ai ^1.4.0
```

et a conservé notre :

```text
drupal/ai 1.4.6
```

AI Agents est donc techniquement compatible avec le socle actuel.

Il n'est toutefois **pas nécessaire au premier Outside AI read-only**. Le module sait aussi orchestrer des tools qui peuvent modifier contenu et configuration ; l'ajouter sans cas d'usage agentique gouverné augmenterait inutilement la surface. La phase read-only externe doit rester Tool API + bridge de protocole minimal, sans agent autonome Drupal.

### MCP Server

La page projet officielle décrit le bon modèle cible :

- Tool API comme source de tools ;
- transport STDIO ;
- transport HTTP ;
- OAuth 2.1 pour HTTP ;
- scopes par tool ;
- configuration explicite des tools exposés.

Mais il n'existe actuellement **aucune release stable supportée**.

La version disponible testée est :

```text
drupal/mcp_server 2.0.0-beta1
```

Sa métadonnée Composer exige :

```text
mcp/sdk ^0.6
```

Le dry-run du projet échoue sous la politique de sécurité Composer normale :

```text
mcp/sdk v0.6.0
-> blocked by security advisory PKSA-p9gd-j6gr-6f9t
```

Le SDK MCP PHP officiel possède déjà une ligne `0.7.x`, mais la contrainte `^0.6` de MCP Server beta1 ne l'autorise pas.

Décision de sécurité :

```text
NE PAS
- désactiver policy.advisories.block ;
- ignorer PKSA-p9gd-j6gr-6f9t ;
- forcer mcp/sdk 0.7 contre la contrainte du module ;
- patcher localement MCP Server uniquement pour obtenir une démo.
```

Un pilote qui ne passe qu'en désactivant le garde-fou de sécurité du projet n'est pas une preuve admissible.

## 3. Preuves exécutées

### Probe combiné initial

Run :

```text
31936733257
```

Artefact :

```text
issue-390-outside-ai-inspection-31936733257
sha256:47a06ab52bd5a79b72184a41b27d3af3ca93ae194340c03fcf13961f70a94975
```

Résultat utile : le solveur a localisé exactement le blocage MCP dans `mcp/sdk ^0.6` et l'advisory Composer.

### Probe séparé Tool API / MCP

Run :

```text
31936799909
```

Artefact :

```text
issue-390-outside-ai-inspection-31936799909
sha256:090754bd6c89b9fcaddb64861eda928e3b89baed71a65e0845cdb69d1b2944eb
```

Résultat :

```text
TOOL_AGENTS_DRY_RUN_EXIT=0
TOOL_AGENTS_INSTALL_EXIT=0
TOOL_AGENTS_COMPATIBILITY=COMPATIBLE

Tool API = 1.0.0-beta6
AI Agents = 1.3.4
Drupal AI = 1.4.6 inchangé

MCP_DRY_RUN_EXIT=2
MCP_SECURITY_GATE=BLOCKED_AS_EXPECTED
```

Les deux workflows étaient des surfaces d'inspection temporaires. Aucune dépendance expérimentale n'est destinée à rester dans le diff final.

## 4. Pourquoi aucun pilote MCP DDEV n'est exécuté

La tâche autorise un test DDEV/local, elle n'impose pas de contourner une sécurité pour obtenir une démonstration.

Le chemin gouverné est :

```text
Composer security gate
-> MCP dependency refused
-> STOP avant installation/runtime
```

Il serait incorrect de :

```text
security advisory
-> ignore
-> installer quand même
-> déclarer PILOT LOCAL réussi
```

Le DDEV pilot MCP est donc **volontairement non exécuté**. C'est le comportement fail-closed attendu.

Tool API a été installé et inspecté dans un workspace jetable afin de définir les futurs contrats. Une preuve Drush locale isolée serait possible, mais elle ne constituerait pas un runtime Outside AI standard et ne justifierait ni un MCP custom ni un catalogue ARD. Elle n'est donc pas matérialisée comme faux substitut.

## 5. STDIO vs HTTP — décision de trajectoire

### Première preuve future : STDIO

Dès qu'une release MCP admissible existe, le premier pilot doit préférer :

```text
DDEV local
+ MCP STDIO
+ 1 à 3 tools read-only
+ principal Drupal non-admin
+ aucun secret provider
+ aucun port MCP public
```

Pourquoi :

- pas d'endpoint réseau exposé ;
- blast radius réduit ;
- transport lié au processus local ;
- observation facile ;
- arrêt immédiat possible ;
- aucune configuration OAuth nécessaire pour la toute première preuve.

STDIO ne dispense pas de contrôle d'accès Drupal. Le processus ne doit jamais s'appuyer sur le défaut `tool:run --uid=1` pour une preuve gouvernée : un principal dédié et non administrateur doit être explicite.

### Étape ultérieure : HTTP

HTTP ne devient admissible qu'après la preuve STDIO et une release upstream acceptable.

Exigences minimales :

```text
OAuth 2.1 obligatoire
+ principal Drupal dédié
+ scopes read-only minimaux
+ TLS
+ rate limits
+ audit
+ aucun mode anonymous/disabled pour les tools Agency internes
```

Même si MCP Server permet conceptuellement de désactiver l'authentification pour des tools publics read-only, Agency ne doit pas utiliser cette option pour les outils d'inspection internes proposés ici.

## 6. Catalogue initial de tools read-only proposés

Ce tableau est un **design de contrat**, pas une liste de tools actuellement exposés.

| Tool ID cible | Opération | Scope cible | Entrées bornées | Sortie | Interdictions |
|---|---|---|---|---|---|
| `agency:content-types-list` | `Explain` | `agency.read.schema` | aucune ou filtre de machine name | content types + labels + capacités structurales | aucune mutation/config brute |
| `agency:fields-describe` | `Explain` | `agency.read.schema` | entity type + bundle allowlistés | champs, types, required, cardinalité, translatability | pas de secret/default sensible |
| `agency:translation-gaps` | `Read` | `agency.read.content-quality` | bundle, langues, limite/pagination | IDs/URLs autorisés + langues manquantes | pas de traduction/génération |
| `agency:media-missing-alt` | `Read` | `agency.read.content-quality` | bundle, limite/pagination | références média/champs sans ALT | pas de génération ALT |
| `agency:publication-status` | `Read` | `agency.read.content-quality` | bundle, statut, limite/pagination | IDs + statut + dates non sensibles | pas de publish/unpublish |
| `agency:seo-audit` | `Read` | `agency.read.seo` | bundle, limite/pagination | métadonnées/alias/schema manquants ou incohérents | pas de rewrite ni save |
| `agency:config-safe-read` | `Read` | `agency.read.schema` | clé dans allowlist fermée | sous-ensemble non sensible normalisé | aucune clé arbitraire, aucun secret |

### Règles communes

Chaque tool futur doit :

```text
operation ∈ {Explain, Read}
destructive = false
```

et respecter :

- `checkAccess()` explicite ;
- permission Agency dédiée, jamais seulement `administer tool` ;
- entity access Drupal respecté pour tout contenu ;
- pagination obligatoire ;
- limite maximale bornée ;
- aucune requête SQL arbitraire ;
- aucun entity query contournant l'access check ;
- aucune lecture de Key, State sensible, variables d'environnement ou credentials ;
- aucune entrée permettant de choisir une classe/service/configuration arbitraire ;
- sortie JSON stable, versionnable et minimale ;
- erreurs externes assainies, détails techniques dans les logs seulement.

## 7. Modèle d'identité et permissions

### Principal local / STDIO

Le futur pilot doit distinguer :

1. **principal OS** : utilisateur qui exécute DDEV/Drush ;
2. **principal Drupal** : compte technique non-admin explicitement utilisé par le tool/runtime ;
3. **invoker/runtime identity** : identifiant de l'exécution MCP/agent lorsqu'upstream le fournit ;
4. **request/correlation ID** : identifiant unique de la requête pour l'audit.

Le principal Drupal ne reçoit que les permissions nécessaires aux scopes read-only retenus et les droits d'accès entité nécessaires à son audit.

### Principal HTTP futur

Le token OAuth doit être associé à un compte ou client identifiable et à des scopes séparés, par exemple :

```text
agency.read.schema
agency.read.content-quality
agency.read.seo
```

Aucun scope générique du type :

```text
agency.admin
agency.write
config.all
users.read
secrets.read
```

n'est prévu dans cette phase.

## 8. Provenance et audit

Chaque invocation future doit produire au minimum :

- timestamp ;
- tool ID ;
- opération ;
- principal Drupal ;
- identité invoker/runtime si disponible ;
- request/correlation ID ;
- scope autorisé ;
- sélecteurs non sensibles utilisés en entrée ;
- statut succès/échec ;
- durée ;
- nombre d'éléments retournés ;
- catégorie d'erreur assainie.

Ne pas journaliser par défaut :

- contenu complet des entités ;
- prompts ;
- réponses IA ;
- bearer tokens ;
- secrets ;
- valeurs de Drupal Key ;
- dumps de configuration complets.

Cette trajectoire doit converger avec la baseline d'observabilité privacy-first de #389 plutôt que créer une seconde stack de monitoring.

## 9. ARD / AI Catalog — état revalidé

La source canonique ARD indique :

```text
ARD v0.9
Status: Draft / Proposal
```

Le manifeste est :

```text
/.well-known/ai-catalog.json
```

et le modèle courant utilise :

```text
specVersion: "1.0"
```

ARD reste une couche de **discovery**. L'authentification et l'autorisation sont déléguées au protocole/runtime de la ressource référencée.

Le projet AI Catalog définit également le catalogue comme une enveloppe de discovery/trust autour d'artefacts natifs ; il ne remplace pas MCP, A2A ou les permissions du runtime.

### Conformance officielle

Le dépôt canonique ARD fournit un CLI officiel `conformance-test` qui vérifie notamment :

- JSON valide ;
- JSON Schema lorsqu'un validateur compatible est présent ;
- identifiants URN domain-anchored ;
- exactement un de `url` ou `data` par entrée ;
- types attendus ;
- absence du champ root `collections` désormais déprécié ;
- 2 à 5 `representativeQueries` lorsqu'elles sont utilisées selon le profil courant.

Commande cible future :

```text
./bin/conformance-test manifest <path-or-url-to-ai-catalog.json>
```

### Pourquoi aucun `ai-catalog.json` Agency n'est créé maintenant

Aujourd'hui :

```text
MCP runtime admissible = aucun
A2A runtime Agency = aucun
Skill/API agentique publique Agency = aucune
```

Donc :

```text
entries réelles = 0
```

Créer une entrée vers :

- un futur endpoint MCP ;
- un chemin DDEV interne ;
- une ressource non installée ;
- un endpoint de démonstration fictif ;

violerait l'invariant `NO ASPIRATIONAL CATALOG ENTRIES`.

Le bon résultat de #390 est donc **l'absence de manifeste publié**, pas un JSON artificiel pour satisfaire une checklist.

## 10. Trust Manifest

Aucun `trustManifest` n'est produit dans #390.

Avant d'en publier un, il faut disposer d'une identité réellement vérifiable et d'attestations réelles. Un DID, SPIFFE ID, certificat, audit ou autre assertion de trust ne doit jamais être inventé pour embellir un catalogue.

Première version future acceptable :

```text
catalogue minimal
+ identité de domaine vérifiable
+ ressource réellement disponible
+ documentation
```

puis seulement enrichissement trust/provenance lorsque la chaîne de preuve existe réellement.

## 11. Sécurité / blast radius

Le premier runtime futur doit refuser explicitement :

```text
Write
Trigger
publish
unpublish
create
update
delete
user management
permission management
role management
secret access
arbitrary config read/write
arbitrary service invocation
arbitrary PHP/SQL/Drush execution
```

Une allowlist de quelques plugins Tool API vaut mieux qu'une règle "tous les tools non-destructive" : le marquage upstream aide, mais ne remplace pas une sélection explicite de ce que l'on expose.

## 12. Conditions de réouverture

Réévaluer #390 ou ouvrir son successeur quand **toutes** les conditions minimales suivantes sont satisfaites :

1. `drupal/mcp_server` possède une release dont les dépendances passent la politique Composer de sécurité sans ignore ;
2. le SDK MCP exigé n'est pas bloqué par un advisory connu ;
3. Tool API et MCP ont une maturité jugée acceptable pour au moins un pilot DDEV gouverné ;
4. le couple de versions est compatible avec le Drupal core et `drupal/ai` verrouillés ;
5. un premier tool read-only concret est sélectionné ;
6. un principal Drupal non-admin et ses permissions sont définis ;
7. les logs/provenance du pilot sont définis avant invocation ;
8. STDIO est prouvé avant HTTP ;
9. aucun catalogue ARD n'est publié avant qu'une ressource réelle et stable n'existe.

Le passage en production exigera en plus une décision spécifique de maturité/security coverage et un gate HTTP/OAuth séparé.

## 13. Conclusion

#390 ne révèle pas un manque d'architecture Agency. Au contraire, l'upstream converge vers le modèle visé :

```text
Tool API
-> typed contracts + access

MCP Server
-> protocol bridge + STDIO/HTTP + auth scopes

ARD / AI Catalog
-> discovery + metadata + trust layer
```

Mais le runtime MCP disponible aujourd'hui échoue le garde-fou de sécurité Composer du projet. Le résultat gouverné est donc :

```text
WAIT
```

avec un catalogue de tools read-only, des scopes, une identité, un modèle d'audit et une séquence STDIO -> HTTP déjà définis, **sans aucune dépendance expérimentale, aucun endpoint d'écriture et aucun catalogue agentique fictif dans la production Agency**.

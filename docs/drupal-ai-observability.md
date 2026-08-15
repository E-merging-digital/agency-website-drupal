# Observabilité, coûts et provenance Drupal AI

Statut : **PREFLIGHT — ACTIVATION TECHNIQUE ENCORE À PROUVER**  
Issue : #389  
Parent : #32  
Date : 2026-08-15

## 1. Objectif

Les futurs usages Drupal AI doivent être observables sans transformer les logs
en copie secondaire des prompts, contenus ou données personnelles.

La baseline Agency doit permettre de répondre à :

- quelle capacité a déclenché l'appel ?
- quel provider/modèle/opération a été utilisé ?
- l'appel a-t-il réussi ou échoué ?
- quelle latence ?
- combien de tokens lorsque le provider les expose ?
- quel coût peut être estimé ?
- un guardrail a-t-il bloqué l'appel ?
- quelle provenance minimale permet de relier l'appel au workflow concerné ?

Elle ne doit pas journaliser par défaut le contenu complet envoyé ou reçu.

## 2. État projet actuel

Au moment de ce preflight :

- `drupal/ai 1.4.6` est verrouillé ;
- `ai` et `ai_provider_openai` sont activés dans la configuration projet ;
- `ai_observability` est fourni par le package AI mais **n'est pas activé** dans
  `config/sync/core.extension.yml` ;
- aucune configuration `ai_observability` n'est actuellement versionnée ;
- les nouveaux consumers Inside AI (#380/#381/#382) ne sont pas encore activés ;
- `FutureAi` chatbot reste désactivé ;
- `agency_ai_translation` utilise encore un appel provider direct et ne traverse
  donc pas l'observabilité Drupal AI.

Conséquence : activer le module maintenant sans consumer upstream réel donnerait
une preuve faible. #389 prépare la politique et le protocole de validation ; le
module ne sera exporté dans `config/sync` qu'après inspection de son comportement
réel sur Agency.

## 3. Capacité upstream

AI Observability écoute les événements de provider Drupal AI et écrit dans le
logger Drupal. La documentation upstream expose notamment :

- provider ;
- modèle ;
- operation type ;
- durée ;
- token usage lorsque disponible ;
- métadonnées de requête/réponse ;
- possibilité de journaliser plus de détails, y compris input/output selon la
  configuration/implémentation.

Cette richesse impose une politique privacy-first avant activation production.

## 4. Principe : metadata-first

Baseline Agency :

```text
metadata technique nécessaire
-> LOG

prompt/input complet
-> OFF par défaut

output/réponse complète
-> OFF par défaut

raw provider payload
-> OFF par défaut

secret/key/auth header
-> NEVER
```

L'objectif est d'observer la plateforme, pas de reconstruire le contenu traité.

## 5. Métadonnées minimales

Lorsque disponibles sans parser un payload brut, les champs suivants sont
utiles :

| Donnée | Politique |
| --- | --- |
| timestamp | LOG |
| capability/use-case | LOG |
| operation type | LOG |
| provider | LOG |
| model | LOG |
| success/error class | LOG |
| duration | LOG |
| input tokens | LOG si fourni |
| output tokens | LOG si fourni |
| total tokens | LOG si fourni |
| cached/reasoning tokens | LOG si fourni et utile |
| rate-limit metadata | LOG si fourni sans secret |
| guardrail result/id | LOG sous forme minimale |
| actor uid | LOG seulement si nécessaire à l'audit Inside AI |
| entity type/bundle | LOG si utile, sans contenu |
| entity id/revision id | LOG si provenance nécessaire |
| context scope ids | LOG, pas le contenu du contexte |
| tool/agent id | LOG lorsque cette capacité existera |

## 6. Données interdites par défaut

Ne pas journaliser par défaut :

- prompt système complet ;
- contenu CKEditor complet ;
- body/champs Drupal complets ;
- traduction source ou résultat complet ;
- message visiteur public ;
- webform submission ;
- document uploadé ;
- email/téléphone/adresse personnelle ;
- secret, API key, bearer token, cookie/session ;
- raw HTTP request/response provider ;
- contenu des context items #387.

Un debug temporaire avec contenu complet exige un ticket/incident explicite,
un environnement non production ou données synthétiques, une durée bornée et
une suppression après diagnostic.

## 7. Provenance par capacité

Les futurs consumers doivent pouvoir fournir une identité de capacité stable.
Convention cible :

```text
agency:<capability>:<operation>
```

Exemples :

```text
agency:ckeditor:review
agency:ckeditor:rewrite
agency:automator:summary
agency:translate:entity
agency:chatbot:public
agency:agent:<agent-id>
```

Cette identité n'est pas un provider tag. Elle décrit **pourquoi** Agency a fait
l'appel.

Si Drupal AI permet de transmettre des tags/metadata de manière stable, les
consumers doivent l'utiliser. Sinon un adapter minimal peut enrichir le contexte
de log sans modifier le payload métier.

## 8. Provenance de contenu

Pour une opération Inside AI sur une entité :

- type d'entité ;
- bundle ;
- id ;
- revision id si applicable ;
- langcode ;
- champ/opération lorsque pertinent.

Ne pas loguer la valeur du champ.

Pour #387, enregistrer au plus les ids/scopes de contexte utilisés et leur
provenance/version, pas le texte complet des contextes.

## 9. Acteur humain

Un `uid` peut être utile pour :

- audit d'usage ;
- quotas ;
- diagnostic d'une opération initiée dans l'admin.

Mais :

- pas de nom/email dans le message de log si l'uid suffit ;
- ne pas créer de profil comportemental éditorial ;
- pas d'identifiant visiteur persistant pour un futur chatbot public sans besoin
  et base explicites.

## 10. Coûts

Les tokens ne sont pas un coût monétaire en eux-mêmes.

La baseline distingue :

```text
token usage observé
!=
coût calculé
```

Un coût estimé nécessite :

- provider ;
- modèle/version ;
- input/output/cached/reasoning tokens selon tarification ;
- table tarifaire datée et versionnée ou source externe fiable.

#389 n'intègre pas de prix en dur dans le code. Les futurs rapports peuvent
calculer un coût estimé à partir des tokens observés et d'un catalogue tarifaire
maintenu séparément.

Si le provider ne retourne pas les tokens, afficher `unknown` plutôt que les
inventer.

## 11. Rate limits

Quand Drupal AI/provider expose des limites :

- remaining requests/tokens ;
- reset time ;
- erreur de quota/rate limit ;

elles peuvent être loguées comme données techniques. Aucun header
`Authorization` ni payload brut ne doit être conservé.

## 12. Guardrails

#379 définit la baseline Guardrails.

L'observabilité doit distinguer :

```text
guardrail PASS
provider appelé

vs

guardrail STOP
provider non appelé
```

Pour une violation : loguer idéalement :

- guardrail/set id ;
- phase pre/post ;
- résultat ;
- capacité ;
- timestamp ;

sans recopier l'entrée fautive.

## 13. `FutureAiMonitoring` : stratégie de convergence

`FutureAiMonitoring` compte actuellement :

- succès ;
- blocages ;
- erreurs provider ;
- fallbacks ;
- raisons.

Décision issue de #388 et précisée ici :

### KEEP temporaire

Conserver les compteurs tant que le flux FutureAi legacy existe. Ils servent à
son comportement/fallback spécifique et leur suppression n'apporte rien avant
migration.

### CONVERGE

Ne plus développer dans `FutureAiMonitoring` :

- tokens ;
- coût ;
- latence provider générique ;
- modèle/provider telemetry ;
- tracing ;
- raw prompts/responses.

Ces responsabilités appartiennent à Drupal AI / AI Observability.

### RETIRE LATER

Après migration FutureAi vers Drupal AI :

- supprimer les compteurs génériques devenus doublons ;
- ne conserver que des métriques métier distinctes si une décision produit les
  utilise réellement, par exemple le taux de fallback guide-only.

## 14. Limite legacy traduction

`agency_ai_translation` effectue encore un appel HTTP provider direct.

Donc :

```text
AI Observability Drupal AI
-> ne couvre pas ce chemin legacy
```

Ne pas ajouter une seconde instrumentation custom sophistiquée autour de ce
client uniquement pour combler cet écart. #382 doit d'abord prouver la parité AI
Translate et faire converger le chemin provider.

## 15. Stockage et rétention

AI Observability écrit via le logger Drupal ; le backend de log reste une
décision d'exploitation.

Baseline :

- aucun SaaS d'observabilité imposé ;
- DBLog acceptable pour développement/preuve bornée ;
- ne pas utiliser DBLog comme entrepôt massif de prompts ;
- production : backend de logs adapté seulement si le volume/besoin le justifie ;
- durée de rétention proportionnée au diagnostic/audit ;
- rotation/purge doivent exister avant une collecte volumineuse.

OpenTelemetry reste une direction possible, pas un prérequis Agency.

## 16. Résilience

Invariant :

```text
observabilité indisponible
!=
fonctionnalité IA indisponible
```

Un échec de logger/export ne doit pas empêcher un éditeur autorisé d'utiliser
une capacité IA.

Exception : si une politique future impose un audit obligatoire pour une action
particulièrement sensible, cette exigence doit être décidée dans un ticket
spécifique. Ce n'est pas la baseline actuelle.

## 17. Gate avant activation dans `config/sync`

Avant de versionner `ai_observability` comme module actif :

1. partir d'un DDEV Agency propre ;
2. vérifier `config:status` avant activation ;
3. activer `ai_observability` localement ;
4. inspecter les configs créées et le config schema ;
5. lancer **un appel Drupal AI non sensible et synthétique** ;
6. inspecter `/admin/reports/dblog` ou le logger configuré ;
7. vérifier ce qui est réellement présent dans message + context ;
8. confirmer : aucun secret ;
9. confirmer : prompts/outputs complets non collectés par défaut ou configurer
   explicitement leur exclusion ;
10. confirmer provider/modèle/durée/token usage lorsqu'exposés ;
11. désactiver/simuler indisponibilité du logger et vérifier que le use case ne
    casse pas ;
12. exporter uniquement la config minimale nécessaire ;
13. `cim` de contrôle + `config:status` propre ;
14. tests/CI exact-head.

## 18. Appel de preuve

La preuve ne doit pas utiliser un contenu réel du site. Prompt synthétique
recommandé :

```text
Return exactly: OBSERVABILITY_OK
```

Aucune donnée client, article, formulaire ou document interne.

Le résultat attendu n'est pas la qualité du LLM mais le log technique.

## 19. Critères de PASS de la preuve

```text
provider                 présent
model                    présent
operation type           présent
success/error            observable
duration                 présente
token usage              présent si provider le fournit
capability provenance    possible ou plan documenté
raw secret               absent
prompt complet           absent de la baseline
output complet           absent de la baseline
fonction sans logger     préservée
```

Si l'implémentation upstream actuelle force la journalisation de contenu complet
sans moyen sûr de la désactiver, verdict : **DO NOT ENABLE IN PRODUCTION** et
attendre/corriger upstream plutôt que contourner avec un fork custom.

## 20. Interaction avec #380 / #381 / #382

- #380 AI CKEditor : premier consumer idéal pour la preuve finale observability,
  avec contenu synthétique uniquement ;
- #381 Automators : ajouter capability id et provenance d'entité, sans valeur de
  champ ;
- #382 AI Translate : après convergence, mesurer tokens/latence via Drupal AI au
  lieu du client HTTP legacy.

#389 doit être fusionné avant de considérer l'observabilité comme une exigence
transversale, mais l'activation module peut rester attachée au premier consumer
si la preuve montre que c'est plus sûr.

## 21. Décision actuelle

```text
Doctrine privacy-first
-> READY

FutureAiMonitoring generic expansion
-> FROZEN

ai_observability installation package
-> AVAILABLE via drupal/ai

ai_observability actif dans Agency
-> NOT YET

activation production
-> PENDING REAL DDEV EVIDENCE
```

Cette décision évite deux erreurs : développer notre propre observability stack,
ou activer aveuglément un logger très riche avant d'avoir inspecté ses données.

## 22. Sources upstream vérifiées

Sources officielles consultées le 2026-08-15 :

- AI Observability : `https://project.pages.drupalcode.org/ai/1.2.x/modules/ai_observability/`
- Full patch test branche AI 1.4.x :
  `https://project.pages.drupalcode.org/ai/1.4.x/contribute/testing/full_patch_test/`
- issue/report upstream sur la réduction/summarization des payloads de logs :
  projet `drupal/ai`, issue `#3566762`.

La documentation officielle confirme que l'observabilité expose durée et token
usage et peut inclure des informations d'input/output ; cette capacité est la
raison de la politique metadata-first du projet.

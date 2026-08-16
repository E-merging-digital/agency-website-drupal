# Observabilité, coûts et provenance Drupal AI

Statut : **BASELINE LOCALE PROUVÉE — PRIVACY-FIRST**  
Issue : #389  
Parent : #32  
Date : 2026-08-16

## 1. Décision

Agency active le sous-module upstream `ai_observability` livré par `drupal/ai
1.4.6` avec sa baseline privacy-first :

```text
Drupal AI provider events
-> Drupal logger metadata

full input / prompt
-> OFF

full output / response
-> OFF

OpenTelemetry export
-> OFF

provider secret / key / auth header
-> NEVER
```

Aucun backend SaaS, endpoint OpenTelemetry, provider concret ou secret n'est
versionné par #389.

## 2. Configuration versionnée

`config/sync/ai_observability.settings.yml` fixe explicitement :

```yaml
logging_enabled: true
log_event_types:
  - Drupal\ai\Event\PreGenerateResponseEvent
  - Drupal\ai\Event\PostGenerateResponseEvent
  - Drupal\ai\Event\PostStreamingResponseEvent
log_input: false
log_output: false
log_tags: {  }
fallback_log_message_mode: true
otel_enabled: false
otel_spans: true
otel_spans_store_input: false
otel_spans_store_output: false
otel_metrics: true
```

`ai_observability` est activé dans `core.extension.yml`. `dblog` était déjà
activé par le profil projet et reste le backend de preuve locale ; ce choix ne
constitue pas une architecture de production imposée.

## 3. Preuve runtime sans provider externe

La preuve technique a été exécutée dans un DDEV jetable reconstruit depuis
l'état repository-owned, sans clé, sans provider réel et sans appel réseau LLM.

Run GitHub Actions : `31935274537`  
Artifact : `9260477630`  
Digest : `sha256:7304ff1620bf1ccb352d9da5c038f395bc6cbe6b983f116642f9ff4404b05440`

La probe a dispatché un vrai `PreGenerateResponseEvent` Drupal AI avec des
données synthétiques et a vérifié le stockage DBLog réel :

```text
PRIVACY_FIRST_LOG=PASS
INPUT_MARKER_PRESENT=no
PROVIDER_PRESENT=yes
MODEL_PRESENT=yes
CONFIGURATION_PERSISTED_BY_DBLOG=no
DISABLED_LOGGING_BYPASS=PASS
```

Le message persistant observé suit le format fallback upstream :

```text
Call provider <provider>: model: <model>, operation type: <operation>.
```

Le contenu synthétique placé dans `ChatInput` n'est pas présent dans les
variables DBLog quand `log_input=false`.

Quand `logging_enabled=false`, le même type d'événement ne crée aucune nouvelle
entrée `ai_observability`.

## 4. Ce que `ai_observability` 1.4.6 émet réellement

L'inspection de `AiLoggingEventSubscriber` de la version verrouillée montre que
le context PSR construit par upstream contient, pour les événements provider :

- event name ;
- tags ;
- provider ;
- operation type ;
- model ;
- provider request id ;
- parent request id ;
- configuration provider de l'événement ;
- token usage sur les outputs qui exposent `getTokenUsage()`.

Input et output ne sont ajoutés au context que si `log_input` ou `log_output`
sont explicitement activés.

### Nuance DBLog

Le backend Database Logging ne persiste pas tout le context PSR arbitraire. En
mode fallback 1.4.6, le message expose essentiellement provider, modèle,
opération et token total lorsqu'il existe. La probe confirme notamment que la
configuration synthétique présente dans le context PSR n'est pas persistée dans
`watchdog.variables`.

Donc :

```text
metadata construite par AI Observability
!=
metadata nécessairement persistée par chaque backend PSR
```

Un futur backend structuré devra être évalué sur ce qu'il conserve réellement.

## 5. Politique de confidentialité

Ne jamais journaliser par défaut :

- prompt système complet ;
- contenu CKEditor ou body complet ;
- traduction source/résultat complet ;
- message visiteur public ;
- webform submission ;
- document uploadé ;
- données personnelles inutiles ;
- secret, API key, bearer token, cookie/session ;
- raw HTTP request/response provider ;
- contenu complet d'un futur context item.

Un debug contenant du contenu exige un ticket/incident explicite, des données
synthétiques ou un environnement approprié, une durée bornée et un nettoyage
après diagnostic.

## 6. Configuration provider : règle de sécurité

Le subscriber upstream met `getConfiguration()` dans son context PSR sans
filtrage spécifique.

Le `ProviderProxy` 1.4.6 construit l'événement à partir de :

```php
configuration: $this->plugin->configuration
```

Conséquence : les configurations d'opération/provider doivent rester
**non secrètes et sans contenu utilisateur**. Les credentials doivent continuer
à vivre dans le mécanisme Key/provider runtime prévu par le projet et ne doivent
jamais être injectés dans cette configuration événementielle.

DBLog n'a pas persisté cette configuration dans la preuve, mais la règle reste
obligatoire car un autre logger PSR structuré pourrait le faire.

## 7. Tokens et coûts

Le token usage est journalisable quand l'output provider expose
`getTokenUsage()`. Ne pas inventer de tokens lorsqu'ils ne sont pas fournis.

Les tokens ne sont pas un coût monétaire :

```text
token usage observé
!=
coût calculé
```

Un coût estimé futur nécessite provider, modèle/version, catégories de tokens
pertinentes et une table tarifaire datée. #389 n'intègre aucun prix en dur.

## 8. Latence, erreurs et rate limits : gaps 1.4.6

La documentation générale de AI Observability présente la durée parmi les
données d'observabilité, mais le subscriber Drupal Logger exact inspecté en
`1.4.6` ne construit pas de champ de durée explicite pour DBLog.

De même, #389 ne prétend pas que DBLog 1.4.6 fournit aujourd'hui une couverture
complète des erreurs provider, rate limits ou coûts.

Décision : **ne pas créer une seconde couche custom générique** pour combler ces
gaps dans cette issue. Ils restent des besoins à réévaluer lors d'une évolution
upstream ou d'un futur backend structuré/OpenTelemetry.

## 9. Provenance par capacité

Convention cible pour les consumers :

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

Drupal AI 1.4.6 transporte déjà des tags sur les événements. Les futurs
consumers doivent préférer ces mécanismes upstream pour identifier leur
capacité plutôt qu'ajouter une télémétrie parallèle.

Pour une entité, la provenance utile est au plus : entity type, bundle, id,
revision id si nécessaire, langcode et opération/champ. Ne pas recopier la
valeur du champ.

## 10. Guardrails

La baseline #379 reste autoritative pour les guardrails. Les détails de
guardrail ne sont ajoutés par le subscriber upstream que dans la branche de
logging de l'input ; comme `log_input=false`, #389 ne revendique pas aujourd'hui
une télémétrie DBLog complète des guardrails.

Si cette métrique devient nécessaire, elle devra être ajoutée sans réactiver la
journalisation des contenus complets.

## 11. `FutureAiMonitoring` : KEEP / CONVERGE / RETIRE LATER

### KEEP

Conserver les compteurs spécifiques du flux FutureAi tant que ce flux existe :
succès, blocages, erreurs provider, fallbacks et raisons.

### CONVERGE

Ne plus étendre `FutureAiMonitoring` avec les responsabilités génériques :
provider/modèle, tokens, coût, tracing, latence provider ou raw payloads. Ces
responsabilités doivent converger vers Drupal AI / AI Observability quand
upstream les expose de façon suffisante.

### RETIRE LATER

Après migration complète des anciens chemins vers Drupal AI, supprimer les
compteurs génériques devenus doublons. Ne conserver que de vraies métriques
métier distinctes si elles servent une décision produit.

## 12. Consumers actuels

Depuis le preflight initial de #389 :

- #380 AI CKEditor est fusionné ;
- #381 AI Automators Article est fusionné ;
- #382 AI Translate a été évalué et le chemin custom existant reste gouverné
  lorsqu'il offre encore des garanties non couvertes par upstream.

La configuration provider reste volontairement runtime-owned. L'absence de
provider doit continuer à laisser fonctionner la saisie/sauvegarde éditoriale
normale ; le browser gate du dépôt vérifie déjà cette propriété sur Article.

## 13. Résilience

Baseline :

```text
observability disabled
-> no observability log
-> AI/event flow continues
```

La probe locale démontre le premier point : le subscriber retourne sans écrire
quand `logging_enabled=false`.

Attention : le subscriber 1.4.6 n'encapsule pas arbitrairement tout backend de
logger défaillant dans un `try/catch`. #389 ne prétend donc pas qu'un backend PSR
qui lève une exception serait automatiquement fail-open. Aucun backend externe
n'est imposé par cette baseline.

## 14. OpenTelemetry

Le module expose des options OTel, mais Agency garde :

```text
otel_enabled: false
```

Pas de collector, SaaS ou endpoint à exploiter tant qu'un besoin de production
mesuré ne le justifie pas. Les options de spans/metrics restent aux defaults
upstream mais sont inertes tant que OTel est désactivé.

## 15. Critères de sortie #389

La baseline est acceptable car :

- module upstream stable déjà livré par le lock ;
- activation/config exportables sans dépendance additionnelle ;
- full input/output désactivés explicitement ;
- provider et modèle prouvés dans DBLog ;
- opération présente dans le message fallback ;
- input synthétique prouvé absent ;
- mode logging désactivé prouvé sans écriture ;
- token usage supporté lorsque l'output upstream l'expose ;
- limites de durée/erreur/rate-limit documentées au lieu d'être inventées ;
- stratégie FutureAiMonitoring explicitement KEEP / CONVERGE / RETIRE LATER ;
- aucun secret, provider concret, prix ou backend SaaS versionné.

La prochaine gate reste le fresh install/config-status + CI/browser validation
sur le HEAD final de la PR, après suppression du workflow temporaire de preuve.

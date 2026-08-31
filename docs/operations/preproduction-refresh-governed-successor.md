# Gouverned full PREPROD refresh successor (#914)

## Autorité

#914 est **source-only** et ne peut jamais autoriser une exécution. Les issues
d’implémentation/capacité connues jusqu’à #917 sont également exclues.

Une future exécution exige une issue enfant distincte et fraîche de #816 :

- créée par `E-merging-digital`;
- `OPEN` avec `status:in-progress`;
- numéro strictement supérieur à `917`;
- un seul marqueur machine canonique de schéma 2;
- un seul commentaire trigger exact;
- `run_attempt = 1`;
- liaison exacte issue / request ID / mode / main SHA / profil / acteur;
- `PLAN`, `APPLY` et `RECOVER_ABORT` sont des autorités distinctes;
- `APPLY` lie aussi l’identité exacte de la release PROD;
- `RECOVER_ABORT` lie une transaction APPLY historique exacte mais reste lui-même gouverné par le `main` courant.

Aucune issue future n’est créée par #914.

## Capability fixe #915 + #917

L’orchestration ne réimplémente aucune sémantique privilégiée. Elle compose :

```text
root-only installer
/usr/local/sbin/agency-preprod-refresh-authority-install

root-only pre-ingress abort
/usr/local/sbin/agency-preprod-refresh-authority-abort

normal bounded sudo ingress
/usr/local/sbin/agency-preprod-refresh-ingress

normal bounded sudo control
/usr/local/sbin/agency-preprod-refresh-control
```

L’installer et l’abort restent absents du sudo normal `agency-preprod`.
Le persistent `DATA_ACTIVATION_AUTHORITY` reste `DISABLED`; seule une autorité de
transaction APPLY exacte peut être armée. Le helper #917 reste la seule surface
de récupération pré-ingress privilégiée.

## PLAN

Le PLAN futur est data-free et mutation-free. Après validation initiale, le job
self-hosted recharge `main`, l’issue d’autorité et ses commentaires, revalide le
request exact et le profil, puis vérifie le profil de sécurité du repository.
Aucun secret PROD/PREPROD n’est matérialisé avant cette passe JIT #910.

Après JIT, PLAN ne lit que :

- identité de release PROD via le probe read-only existant, sans Drush DB/dump;
- métadonnées des exécutables #915/#917 et du bundle;
- identité DB runtime PREPROD via le probe existant;
- état visible de fence/locks/capacité et inventaire attendu.

PLAN ne crée aucun snapshot, n’arme aucune autorité, n’exécute aucun ingress,
n’importe rien, ne crée pas de candidate/backup, ne ferme pas la fence et
n’exécute ni activation, rollback ni abort.

## APPLY

La route future est :

```text
fresh #816 successor APPLY authority
→ exact live main
→ #910 JIT before every SSH/root secret
→ exact runner agency-browser-runner-01
→ reviewed #834/#866 read-only PROD snapshot primitive
→ raw only in RUNNER_TEMP outside workspace
→ exact bytes + SHA-256
→ derived exact authority_id
→ durable metadata-only recovery target record
→ root-only #915/#917 authority install
→ fixed binary ingress through bounded agency-preprod sudo
```

### Cible durable de récupération avant armement

Après calcul de l’identité du snapshot mais **avant** l’appel root-only à
`agency-preprod-refresh-authority-install`, APPLY écrit sur son issue d’autorité
un seul record canonique préfixé :

```text
AGENCY_PREPROD_REFRESH_RECOVERY_TARGET=
```

Ce record contient uniquement :

```text
successor_issue
request_id
main_sha
profile_id
authority_id
snapshot_bytes
snapshot_sha256
record_is_execution_authority=false
```

Il ne contient ni dump, ni SQL, ni PII, ni clé, ni credential. Il ne constitue
jamais une autorité d’exécution. Son unique fonction est de rendre l’identité
exacte de la transaction reconstructible si l’exécuteur disparaît après
armement. Si l’écriture durable du record n’est pas prouvée, l’installer n’est
pas appelé.

### Ingress réussi

Après `SNAPSHOT_READY` :

```text
IMPORT_SANITIZE_HARDEN_RETAIN
→ sealed candidate proof
→ BACKUP_ACTIVATE_CONVERGE_VALIDATE
→ COMMITTED
```

Les erreurs post-ingress restent sous la state machine fixe #915, y compris les
restaurations/rollback et `FAILED_RECOVERY / HUMAN_RECOVERY_REQUIRED`.

### Échec observable après armement avant SNAPSHOT_READY

Le trap local reste la voie normale :

1. supprime le raw transient du trusted runner;
2. invoque l’abort root-only #917 avec l’exact binding
   `successor_issue/request_id/main_sha/profile_id/authority_id`;
3. laisse #917 prouver l’absence des partial/final ingress objects;
4. exige l’historique `ABORTED / TERMINAL` et l’absence de l’autorité active;
5. échoue la transaction.

`ABORTED` n’est jamais présenté comme `ROLLED_BACK`. L’abort ne peut pas être
appelé depuis `SNAPSHOT_READY`; inversement `ROLLBACK_RECORDED` n’est pas
élargi à `AWAITING_INGRESS`.

## RECOVER_ABORT — perte dure de l’exécuteur

Une perte VM/service/process (`SIGKILL`, host crash, runner loss) peut empêcher
le trap APPLY de s’exécuter. Si l’autorité fixe est restée
`ARMED / AWAITING_INGRESS`, l’ancien APPLY reste consommé et non réutilisable.

La récupération exige une **nouvelle issue enfant #816**, distincte de l’issue
APPLY échouée, avec `mode=RECOVER_ABORT` et un nouveau request ID one-shot. Elle
lie deux identités différentes :

```text
RECOVERY_EXECUTION_MAIN = exact current live main
TARGET_TRANSACTION_MAIN = exact historical main_sha from stale #915/#917 authority
```

Le marqueur de récupération lie obligatoirement :

```text
target_successor_issue
target_request_id
target_main_sha
target_profile_id
target_authority_id
```

Le workflow revalide d’abord l’autorité de récupération et le record durable de
la cible. Sur le runner de confiance il applique ensuite #910 : checkout du
`main` courant autorisé, reload du `main` live, reload de la nouvelle autorité,
revalidation de tous les bindings et du profil #915/#917. **Seulement après**
cette passe, la clé root PREPROD est matérialisée.

RECOVER_ABORT n’utilise :

```text
PROD secret = NONE
PROD SSH = NONE
normal PREPROD deploy secret = NONE
raw data = NONE
ingress = NONE
import = NONE
activation = NONE
rollback = NONE
```

La seule opération distante autorisée est :

```text
/usr/local/sbin/agency-preprod-refresh-authority-abort
```

avec le binding historique exact de la cible. Le helper #917 reste autoritatif
pour prouver `ARMED / AWAITING_INGRESS`, l’absence des objets ingress/candidate/
backup/fence et les métadonnées d’activation nulles. Toute cible
`SNAPSHOT_READY` ou plus avancée, terminale, mal liée, ou avec un objet partial
présent échoue fermée.

Une récupération réussie doit produire :

```text
ABORTED / TERMINAL
active authority = ABSENT
RUNTIME_ROLLBACK_CLAIM = NONE
```

Une deuxième récupération après terminalisation échoue fermée. Aucun blind
retry APPLY n’est autorisé; toute nouvelle tentative de refresh exige une
nouvelle autorité APPLY distincte.

## Frontières de données et privilèges

Le raw PROD ne peut pas aller vers un GitHub-hosted runner, un artifact GitHub,
les logs ou une preuve PR. Les opérations raw sont réservées à
`[self-hosted, linux, x64, agency]`, nom exact `agency-browser-runner-01`.

`PROD_WRITE = NONE`.

Aucun `NOPASSWD ALL`, shell/Python/MariaDB/env sudo, SQL/path/DB/table/executable
choisi par l’appelant, ni commande root arbitraire n’est ajouté.

## Limite de livraison #914

Cette livraison matérialise uniquement les routes futures. Elle n’autorise et
n’effectue aucun provisioning réel, PLAN réel, APPLY réel, RECOVER_ABORT réel,
SSH réel, lecture/export PROD, transfert raw, ingress, armement, abort,
candidate, backup, fence mutation, mutation PREPROD, activation ou rollback.

`DATA_ACTIVATION_AUTHORITY = DISABLED` et aucun merge n’est effectué par #914.

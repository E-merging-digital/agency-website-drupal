# Gouverned full PREPROD refresh successor (#914)

## Autorité

#914 est **source-only** et ne peut jamais autoriser une exécution. Les issues
d’implémentation/capacité connues jusqu’à #917 sont également exclues.

Une future exécution exige une issue enfant distincte et fraîche de #816 :

- créée par `E-merging-digital`;
- `OPEN` avec `status:in-progress`;
- numéro strictement supérieur à `917`;
- un seul marqueur machine canonique;
- un seul commentaire trigger exact;
- `run_attempt = 1`;
- liaison exacte issue / request ID / mode / main SHA / profil / acteur;
- `PLAN` et `APPLY` sont deux autorités différentes;
- `APPLY` lie aussi l’identité exacte de la release PROD.

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
transaction APPLY exacte peut être armée.

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

Les objets d’autorité de transaction sont root-only. Un PLAN non privilégié ne
prétend donc pas lire leur contenu. Il rapporte explicitement
`UNOBSERVABLE_UNPRIVILEGED`; l’absence/collision/replay est réimposée
fail-closed par l’installer root-only au moment d’un futur APPLY.

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
→ root-only #915/#917 authority install
→ fixed binary ingress through bounded agency-preprod sudo
```

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

### Échec après armement avant SNAPSHOT_READY

Le trap d’orchestration :

1. supprime le raw transient du trusted runner;
2. invoque l’abort root-only #917 avec l’exact binding
   `successor_issue/request_id/main_sha/profile_id/authority_id`;
3. laisse l’abort fixe prouver l’absence des partial/final ingress objects;
4. exige l’historique `ABORTED / TERMINAL` et l’absence de l’autorité active;
5. échoue la transaction.

`ABORTED` n’est jamais présenté comme `ROLLED_BACK`. L’abort ne peut pas être
appelé depuis `SNAPSHOT_READY`; inversement `ROLLBACK_RECORDED` n’est pas
élargi à `AWAITING_INGRESS`.

Aucun blind retry APPLY n’est autorisé après abort ou échec. Une nouvelle
tentative exige une nouvelle issue/request d’autorité Project Lead.

## Frontières de données et privilèges

Le raw PROD ne peut pas aller vers un GitHub-hosted runner, un artifact GitHub,
les logs ou une preuve PR. Les opérations raw sont réservées à
`[self-hosted, linux, x64, agency]`, nom exact `agency-browser-runner-01`.

`PROD_WRITE = NONE`.

Aucun `NOPASSWD ALL`, shell/Python/MariaDB/env sudo, SQL/path/DB/table/executable
choisi par l’appelant, ni commande root arbitraire n’est ajouté.

## Limite de livraison #914

Cette livraison matérialise uniquement la route future. Elle n’autorise et
n’effectue aucun provisioning réel, PLAN réel, APPLY réel, SSH réel,
lecture/export PROD, transfert raw, ingress, armement, abort, candidate,
backup, fence mutation, mutation PREPROD, activation ou rollback.

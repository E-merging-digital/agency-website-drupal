# Ticket 63 - Ecriture ciblee Content Sync

## Objectif

La commande `drush emerging:content-sync agence-drupal-belgique` peut
desormais appliquer le contenu cible depuis le catalogue YAML versionne.

Le mode `--dry-run` reste strictement en lecture seule : il charge le catalogue,
valide les traductions, consulte le mapping existant et decrit les actions, mais
n'ecrit ni entite Drupal ni ligne de mapping.

## Source de verite

Le contenu gere provient du catalogue :

- `web/modules/custom/emerging_digital_content/content_sync/catalog.yml`
- `web/modules/custom/emerging_digital_content/content_sync/node/agence-drupal-belgique.yml`

L'identifiant metier stable est `agence-drupal-belgique`.

Alias declares :

- FR : `/agence-drupal-belgique`
- EN interne Drupal : `/drupal-agency-belgium`
- EN public attendu avec prefixe de langue : `/en/drupal-agency-belgium`

## Resolution de l'entite

En mode ecriture, le gestionnaire suit l'ordre prudent suivant :

1. chercher une ligne dans `emerging_digital_content_sync_mapping` via
   `ContentSyncMappingRepository::findByContentId()` ;
2. charger le node par UUID de mapping, puis par identifiant numerique si
   necessaire ;
3. sans mapping valide, resoudre les alias declares dans le catalogue ;
4. si aucun node `service` existant n'est trouve, creer un nouveau node.

Si des alias du catalogue pointent vers plusieurs nodes `service`, ou si un
alias est deja utilise par un autre contenu que le node resolu, la commande
refuse d'ecrire.

## Synchronisation

La synchronisation applique uniquement le node `service` cible :

- titre FR et EN ;
- champs `field_short_description` et `field_detailed_description` ;
- publication du node et de ses traductions ;
- alias FR et EN ;
- creation ou mise a jour du mapping apres sauvegarde reussie.

Le hash du catalogue est enregistre dans le mapping avec l'action `created` ou
`updated`, ce qui rend la commande rejouable sans duplication.

## Promotions

Les menus restent hors perimetre.

Les promotions declarees dans le YAML peuvent mettre a jour les cartes du
paragraphe `services` de la homepage et de `/services`. Les autres items du
paragraphe sont conserves. Une carte existante est retrouvee par titre ou URL,
puis mise a jour ; sinon elle est ajoutee.

Les liens stockes restent des alias Drupal non prefixes :

- FR : `/agence-drupal-belgique`
- EN : `/drupal-agency-belgium`

Le theme localise l'URL EN avec la langue courante, ce qui produit l'URL
publique `/en/drupal-agency-belgium`. Le label bouton est fourni par le theme :
`Découvrir` en FR et `Discover` en EN.

## Hors perimetre conserve

Cette implementation ne fait pas :

- de mode `--all` ;
- de mode `--prune` ;
- de suppression de contenu ;
- de modification de menus ;
- de modification des workflows GitHub Actions ;
- de modification de `deploy-production.sh` ;
- de suppression de `default_content`.

Les contenus manuels non resolus par le mapping ou par les alias du catalogue ne
sont pas modifies.

## Verification

Commandes prevues pour le ticket :

```powershell
ddev drush cr
ddev drush updb -y
ddev drush emerging:content-sync:validate
ddev drush emerging:content-sync agence-drupal-belgique --dry-run
ddev drush emerging:content-sync agence-drupal-belgique
ddev drush emerging:content-sync agence-drupal-belgique
ddev composer lint:phpcs
ddev composer lint:phpstan
ddev composer lint:drupal-check
```

Un test kernel couvre le dry-run sans ecriture, la creation ciblee, les
traductions FR/EN, les alias, la creation du mapping et l'idempotence d'un
second passage.

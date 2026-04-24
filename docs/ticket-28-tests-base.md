# Ticket 28 — Base de tests automatisés

## Ce qui est couvert

- Workflow de traduction IA individuelle avec écran de confirmation.
- Exécution de l’action de masse depuis `/admin/content`.
- Vérification de la création de traduction en langue cible (`en`) et du contenu traduit.
- Vérification de base de l’alias de traduction (quand un alias EN existe).
- Formulaire de contact public : affichage des champs, cas invalide, cas valide.

## Isolation des tests (important)

Les tests `BrowserTestBase` sont exécutés dans une installation Drupal de test isolée.
Ils ne doivent **pas** dépendre de la base locale existante.

Le type de contenu `page` est créé explicitement dans `setUp()` du test de workflow avant l’activation de `content_translation`.

## Pré-requis local

Créer le dossier de sortie navigateur utilisé par Simpletest (si absent) :

```bash
mkdir -p web/sites/simpletest/browser_output

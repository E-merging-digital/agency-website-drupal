# Ticket 313 - Envoi email production

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/313

Date : 2026-05-22

## Objectif

Configurer le socle d'envoi email Drupal pour que le formulaire Webform
`contact` reste capture par Mailpit en DDEV et utilise un transport SMTP
securise en production, sans secret versionne.

## Audit

- `system.mail` utilisait `php_mail` et `webform_php_mail`.
- Drupal 11 fournit deja le plugin mail `symfony_mailer`, base sur le composant
  Symfony Mailer present dans Drupal Core.
- Le transport exporte reste `native://default`, ce qui delegue a `mail()` et
  preserve Mailpit en DDEV via le `sendmail_path` PHP configure par DDEV.
- Le Webform `contact` envoie la notification a `contact@emergingdigital.be`.
- Le handler de notification utilisait auparavant l'adresse visiteur en `From`.
  Pour respecter SPF/DMARC, le `From` utilise maintenant l'adresse par defaut
  du site et l'adresse visiteur est conservee en `Reply-To`.

## Configuration Drupal

- `config/sync/system.mail.yml` utilise `symfony_mailer` par defaut, donc aussi
  pour Webform, avec `native://default` comme transport local.
- `config/sync/system.site.yml` declare `contact@emergingdigital.be` comme
  adresse email du site.
- `config/sync/webform.webform.contact.yml` conserve :
  - notification vers `contact@emergingdigital.be` ;
  - confirmation vers l'adresse saisie dans le champ `email` ;
  - `From` domaine site ;
  - `Reply-To` visiteur pour la notification entrante.

DSM+ / Mailer Plus n'est pas ajoute dans ce ticket : le besoin immediat est le
transport SMTP securise, couvert par Drupal Core. DSM+ reste le candidat naturel
si le projet ajoute ensuite des templates transactionnels HTML avances,
attachements, politiques par type d'email ou transports multiples.

## Variables d'environnement production

Activer le SMTP uniquement en production en definissant au minimum :

```bash
EMERGING_DIGITAL_SMTP_HOST=smtp.example.com
EMERGING_DIGITAL_SMTP_PORT=587
EMERGING_DIGITAL_SMTP_SCHEME=smtp
EMERGING_DIGITAL_SMTP_USER=utilisateur-smtp
EMERGING_DIGITAL_SMTP_PASSWORD=<mot-de-passe-smtp-non-versionne>
EMERGING_DIGITAL_SMTP_LOCAL_DOMAIN=emergingdigital.be
```

Notes :

- `EMERGING_DIGITAL_SMTP_HOST` active l'override SMTP production.
- `EMERGING_DIGITAL_SMTP_PORT` vaut `587` par defaut.
- `EMERGING_DIGITAL_SMTP_SCHEME` vaut `smtp` par defaut avec STARTTLS requis.
- Utiliser `smtps` avec le port `465` si le fournisseur impose TLS implicite.
- `EMERGING_DIGITAL_SMTP_USER` et `EMERGING_DIGITAL_SMTP_PASSWORD` sont des
  secrets d'environnement et ne doivent jamais etre commites.
- `require_tls` et `verify_peer` sont forces a `TRUE` dans `settings.php`.
- En DDEV, ces overrides sont ignores afin de laisser Mailpit capturer les
  emails localement via `mail()`.

## DNS email

Avant activation production, verifier chez le fournisseur DNS du domaine
`emergingdigital.be` :

- SPF : autoriser le fournisseur SMTP a emettre pour le domaine.
- DKIM : publier la cle DKIM fournie par le SMTP et activer la signature cote
  fournisseur.
- DMARC : publier une politique DMARC, commencer en observation si necessaire,
  puis durcir progressivement.

Exemples indicatifs a adapter au fournisseur :

```text
emergingdigital.be. TXT "v=spf1 include:spf.fournisseur.example -all"
selector._domainkey.emergingdigital.be. TXT "v=DKIM1; k=rsa; p=..."
_dmarc.emergingdigital.be. TXT "v=DMARC1; p=quarantine; rua=mailto:dmarc@emergingdigital.be; adkim=s; aspf=s"
```

## Verification manuelle DDEV

1. Importer la configuration : `ddev drush cim -y`.
2. Vider le cache : `ddev drush cr`.
3. Tester `mail()` dans DDEV :

   ```bash
   ddev exec php -r "mail('contact@emergingdigital.be', 'Test PHP Mailpit', 'Message de test PHP mail()', 'From: contact@emergingdigital.be');"
   ```

4. Ouvrir Mailpit : `ddev launch -m`.
5. Verifier que l'email PHP est recu dans Mailpit.
6. Soumettre `/fr/contact`.
7. Verifier dans Mailpit :
   - notification a `contact@emergingdigital.be` ;
   - confirmation a l'adresse saisie ;
   - `From` = adresse du site ;
   - `Reply-To` = adresse visiteur sur la notification ;
   - aucun secret SMTP dans le message ni dans `git diff`.

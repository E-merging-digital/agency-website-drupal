# Ticket 313 - Envoi email production

Issue GitHub : https://github.com/E-merging-digital/agency-website-drupal/issues/313

Date : 2026-05-22

## Objectif

Configurer l'envoi email Drupal pour que le formulaire Webform `contact` reste
capture par Mailpit en DDEV et utilise Proton SMTP en production, sans secret
versionne.

## Synthese

- `config/sync/system.mail.yml` reste sur `symfony_mailer` avec
  `native://default`.
- En DDEV, `settings.php` ignore les variables SMTP et Mailpit continue de
  capturer les emails via `mail()`.
- En production, `settings.php` construit le transport Symfony Mailer depuis
  les variables `EMERGING_DIGITAL_SMTP_*`.
- Le mot de passe SMTP reste uniquement dans l'environnement serveur.
- L'adresse principale du site est `contact@emergingdigital.be`.
- La timezone Drupal est `Europe/Brussels`.

## Regles Proton

Proton impose que l'adresse utilisee comme identite SMTP soit autorisee dans le
compte SMTP :

- `EMERGING_DIGITAL_SMTP_USER=jonathan@emergingdigital.be`
- `EMERGING_DIGITAL_SMTP_FROM=jonathan@emergingdigital.be`

`contact@emergingdigital.be` reste le destinataire metier du formulaire. Dans
l'etat actuel, cette adresse est une redirection ou une adresse non autorisee
comme `From` SMTP Proton. Elle ne doit donc pas etre utilisee comme `From`,
`Sender` ou `Return-Path` pour les emails Webform envoyes via Proton.

Si un jeton SMTP Proton a ete expose dans un terminal partage, un ticket, une
documentation ou Git, il faut le revoquer et regenerer un nouveau jeton cote
Proton avant de relancer la production.

## Configuration Drupal

- `system.mail` :
  - interface par defaut : `symfony_mailer` ;
  - transport exporte : `native://default` ;
  - aucun mot de passe SMTP dans `config/sync`.
- `system.site` :
  - `mail: contact@emergingdigital.be`.
- `system.date` :
  - `timezone.default: Europe/Brussels`.
- `webform.webform.contact` :
  - notification interne active vers `contact@emergingdigital.be` ;
  - `From`, `Sender` et `Return-Path` sur `jonathan@emergingdigital.be` ;
  - `From name` sur `E-MERGING DIGITAL` ;
  - `Reply-To` vide temporairement pour maximiser la fiabilite Proton ;
  - confirmation visiteur desactivee temporairement.

DSM+ / Mailer Plus n'est pas ajoute dans ce ticket : le besoin immediat est le
transport SMTP securise, couvert par Drupal Core et Symfony Mailer.

## Variables serveur

Creer le fichier serveur non versionne :

```bash
sudo nano /etc/emergingdigital.env
```

Contenu attendu, sans jamais commiter le jeton :

```bash
EMERGING_DIGITAL_SMTP_HOST=smtp.protonmail.ch
EMERGING_DIGITAL_SMTP_PORT=587
EMERGING_DIGITAL_SMTP_USER=jonathan@emergingdigital.be
EMERGING_DIGITAL_SMTP_PASSWORD=<jeton SMTP Proton>
EMERGING_DIGITAL_SMTP_ENCRYPTION=tls
EMERGING_DIGITAL_SMTP_FROM=jonathan@emergingdigital.be
```

Droits attendus :

```bash
sudo chown root:www-data /etc/emergingdigital.env
sudo chmod 640 /etc/emergingdigital.env
```

## PHP-FPM

Le service PHP-FPM doit charger `/etc/emergingdigital.env`. Creer ou modifier
l'override systemd :

```bash
sudo systemctl edit php8.4-fpm
```

Contenu :

```ini
[Service]
EnvironmentFile=/etc/emergingdigital.env
```

Le pool PHP-FPM doit ensuite exposer les variables necessaires a Drupal. Dans
`/etc/php/8.4/fpm/pool.d/www.conf`, conserver `clear_env = no` ou declarer
explicitement les variables :

```ini
env[EMERGING_DIGITAL_SMTP_HOST] = $EMERGING_DIGITAL_SMTP_HOST
env[EMERGING_DIGITAL_SMTP_PORT] = $EMERGING_DIGITAL_SMTP_PORT
env[EMERGING_DIGITAL_SMTP_USER] = $EMERGING_DIGITAL_SMTP_USER
env[EMERGING_DIGITAL_SMTP_PASSWORD] = $EMERGING_DIGITAL_SMTP_PASSWORD
env[EMERGING_DIGITAL_SMTP_ENCRYPTION] = $EMERGING_DIGITAL_SMTP_ENCRYPTION
env[EMERGING_DIGITAL_SMTP_FROM] = $EMERGING_DIGITAL_SMTP_FROM
```

Apres modification :

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
vendor/bin/drush cr
```

## Verification production

Verifier la configuration effective :

```bash
vendor/bin/drush cget system.site mail
vendor/bin/drush cget system.date timezone.default
vendor/bin/drush cget system.mail
vendor/bin/drush cget webform.webform.contact handlers.email_notification.settings
```

Attendus :

- `system.site mail` vaut `contact@emergingdigital.be`.
- `system.date timezone.default` vaut `Europe/Brussels`.
- `system.mail` ne doit jamais afficher de mot de passe SMTP reel dans la
  configuration exportee.
- Le handler `email_notification` utilise `jonathan@emergingdigital.be` comme
  `from_mail`, `sender_mail` et `return_path`, et envoie vers
  `contact@emergingdigital.be`.

Test direct SMTP :

```bash
vendor/bin/drush php:eval '
$transport = \Symfony\Component\Mailer\Transport::fromDsn(
"smtp://".rawurlencode(getenv("EMERGING_DIGITAL_SMTP_USER")).":".rawurlencode(getenv("EMERGING_DIGITAL_SMTP_PASSWORD"))."@smtp.protonmail.ch:587?encryption=tls"
);
$mailer = new \Symfony\Component\Mailer\Mailer($transport);
$email = (new \Symfony\Component\Mime\Email())
->from(new \Symfony\Component\Mime\Address("jonathan@emergingdigital.be", "E-MERGING DIGITAL"))
->sender("jonathan@emergingdigital.be")
->returnPath("jonathan@emergingdigital.be")
->to("contact@emergingdigital.be")
->subject("SMTP Webform equivalent")
->html("<p>Test Webform equivalent</p>");
$mailer->send($email);
echo "MAIL_OK\n";
'
```

Puis tester :

- soumission reelle de `/fr/contact` ;
- email recu sur `contact@emergingdigital.be` ;
- logs Drupal sans erreur Symfony Mailer ;
- timezone correcte ;
- aucun `Permission denied` dans les derniers logs PHP-FPM / Drupal.

## Verification DDEV

En local :

```bash
ddev drush cim -y
ddev drush cr
ddev drush config:status
ddev exec php -r "mail('contact@emergingdigital.be', 'Test PHP Mailpit', 'Message de test PHP mail()', 'From: jonathan@emergingdigital.be');"
ddev launch -m
```

Verifier :

- Mailpit recoit l'email de test PHP ;
- une soumission `/fr/contact` arrive dans Mailpit ;
- aucun SMTP externe n'est appele en DDEV ;
- aucun secret SMTP n'apparait dans `git diff`.

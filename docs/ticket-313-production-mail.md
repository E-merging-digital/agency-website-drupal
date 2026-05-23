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
- `config/splits/production/system.mail.yml` porte le transport SMTP Proton
  non secret.
- En DDEV, la configuration globale conserve `native://default` et Mailpit continue de
  capturer les emails via `mail()`.
- En production, le Config Split fournit `smtp://smtp.protonmail.ch:587` et
  le `settings.php` serveur, non versionne, injecte les valeurs
  `EMERGING_DIGITAL_SMTP_*`, dont le mot de passe.
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
  - en config globale : `symfony_mailer` avec `native://default` ;
  - en split production : `symfony_mailer` avec `smtp://smtp.protonmail.ch:587`
    et `password: null` ;
  - aucun mot de passe SMTP dans `config/sync` ni dans
    `config/splits/production`.
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

En local, `drush cget system.mail` doit rester sur `native://default`. En
production apres import du split, `drush cget system.mail` doit montrer le
transport SMTP non secret. La presence du mot de passe se verifie uniquement via
un test qui ne l'affiche pas.

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

## Modification manuelle obligatoire du settings.php de production

Le `settings.php` de production est un fichier serveur partage et non versionne
par GitHub. Il peut differer du `settings.php` local du depot. Codex ne le
modifie donc pas directement. Appliquer manuellement le bloc ci-dessous dans :

```text
/var/www/agency/shared/settings/settings.php
```

Placer ce bloc apres la declaration du repertoire de configuration et apres les
eventuels includes propres au serveur, afin qu'il soit le dernier override mail
applique :

```php
/**
 * Production email transport for Proton SMTP.
 *
 * Secrets are read from the server environment only. Do not commit them.
 */
if (getenv('IS_DDEV_PROJECT') !== 'true') {
  $config['config_split.config_split.production']['status'] = TRUE;

  $smtp_host = getenv('EMERGING_DIGITAL_SMTP_HOST') ?: 'smtp.protonmail.ch';
  $smtp_port = getenv('EMERGING_DIGITAL_SMTP_PORT') ?: 587;
  $smtp_user = getenv('EMERGING_DIGITAL_SMTP_USER')
    ?: 'jonathan@emergingdigital.be';
  $smtp_password = getenv('EMERGING_DIGITAL_SMTP_PASSWORD') ?: NULL;
  $smtp_from = getenv('EMERGING_DIGITAL_SMTP_FROM') ?: $smtp_user;
  $smtp_encryption = getenv('EMERGING_DIGITAL_SMTP_ENCRYPTION') ?: 'tls';
  $smtp_encryption = strtolower($smtp_encryption);
  $smtp_scheme = in_array($smtp_encryption, ['ssl', 'smtps'], TRUE)
    ? 'smtps'
    : 'smtp';

  $config['system.mail']['interface'] = [
    'default' => 'symfony_mailer',
  ];
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => $smtp_scheme,
    'host' => $smtp_host,
    'user' => $smtp_user,
    'password' => $smtp_password,
    'port' => (int) $smtp_port,
    'options' => [
      'auto_tls' => TRUE,
      'require_tls' => $smtp_scheme === 'smtp',
      'verify_peer' => TRUE,
    ],
  ];

  $webform_handlers =& $config['webform.webform.contact']['handlers'];
  $notification_settings =& $webform_handlers['email_notification']['settings'];
  $notification_settings['from_mail'] = $smtp_from;
  $notification_settings['sender_mail'] = $smtp_from;
  $notification_settings['return_path'] = $smtp_from;
}
```

Ce bloc ne stocke pas le jeton SMTP : il lit uniquement
`EMERGING_DIGITAL_SMTP_PASSWORD` depuis l'environnement PHP-FPM.

## Deploiement et droits files

Le script de deploiement actuel branche le fichier partage :

```text
/var/www/agency/shared/settings/settings.php
```

sur :

```text
/var/www/agency/current/web/sites/default/settings.php
```

Apres chaque release, verifier ou reparer les droits du repertoire partage :

```bash
sudo chown -R www-data:www-data /var/www/agency/shared/files
sudo find /var/www/agency/shared/files -type d -exec chmod 2775 {} +
sudo find /var/www/agency/shared/files -type f -exec chmod 664 {} +
```

Adapter `www-data:www-data` si le pool PHP-FPM utilise un autre utilisateur ou
un autre groupe.

## Commandes post-deploiement

Le script de deploiement doit conserver cette sequence :

```bash
vendor/bin/drush cim -y
vendor/bin/drush config:import --source="$PWD/config/splits/production" --partial -y
```

Le premier import conserve la configuration globale compatible DDEV, le second
applique les overrides production dont `system.mail`.

Apres modification du `settings.php` serveur ou des variables PHP-FPM :

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
cd /var/www/agency/current
vendor/bin/drush cr
```

## Verification production

Verifier la configuration effective :

```bash
vendor/bin/drush cget system.site mail
vendor/bin/drush cget system.date timezone.default
vendor/bin/drush cget system.mail
vendor/bin/drush cget system.mail mailer_dsn.scheme
vendor/bin/drush cget system.mail mailer_dsn.host
vendor/bin/drush cget system.mail mailer_dsn.port
vendor/bin/drush cget webform.webform.contact handlers.email_notification.settings
```

Attendus :

- `system.site mail` vaut `contact@emergingdigital.be`.
- `system.date timezone.default` vaut `Europe/Brussels`.
- `system.mail` doit afficher le transport SMTP non secret issu du split
  production : `smtp`, `smtp.protonmail.ch`, `587`, `password: null`.
- Ne pas executer ni partager `drush cget system.mail --include-overridden`
  complet : avec l'override `settings.php`, la sortie peut contenir le mot de
  passe SMTP fourni par l'environnement.
- Le handler `email_notification` utilise `jonathan@emergingdigital.be` comme
  `from_mail`, `sender_mail` et `return_path`, et envoie vers
  `contact@emergingdigital.be`.

Verification redigee sans afficher le secret :

```bash
vendor/bin/drush php:eval '
$dsn = \Drupal::config("system.mail")->get("mailer_dsn");
echo $dsn["scheme"]."://".$dsn["host"].":".$dsn["port"]."\n";
echo empty($dsn["password"]) ? "SMTP_PASSWORD_MISSING\n" : "SMTP_PASSWORD_SET\n";
'
```

Attendu :

```text
smtp://smtp.protonmail.ch:587
SMTP_PASSWORD_SET
```

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

# HTTPS en production

## Objectif

La production applique une politique canonique stricte : **HTTPS sans `www`**.

- URL canonique : `https://emergingdigital.be`
- Redirection obligatoire depuis `http://emergingdigital.be`
- Redirection obligatoire depuis `http://www.emergingdigital.be`
- Redirection obligatoire depuis `https://www.emergingdigital.be`

## Certificat TLS (Let’s Encrypt + Certbot)

- AC utilisée : **Let’s Encrypt**
- Client ACME : **Certbot**
- Intégration serveur web : plugin **Nginx** (`python3-certbot-nginx`)
- Domaines couverts par le certificat :
  - `emergingdigital.be`
  - `www.emergingdigital.be`

## Installation (Ubuntu / Debian)

```bash
sudo apt update
sudo apt install certbot python3-certbot-nginx -y
```

## Émission initiale du certificat

```bash
sudo certbot --nginx -d emergingdigital.be -d www.emergingdigital.be
```

Cette commande :

1. valide les challenges ACME ;
2. installe le certificat ;
3. met à jour la configuration Nginx ;
4. peut proposer la redirection HTTP -> HTTPS.

## Renouvellement automatique

Le renouvellement est géré automatiquement par le timer systemd installé avec Certbot.

### Vérifier le timer

```bash
systemctl list-timers | grep certbot
sudo systemctl status certbot.timer
```

### Tester le renouvellement (dry-run)

```bash
sudo certbot renew --dry-run
```

> Exécuter ce test après l’émission initiale puis régulièrement (ex. après changement Nginx/firewall).

## Commandes de diagnostic

### Certificat et échéance

```bash
sudo certbot certificates
openssl s_client -connect emergingdigital.be:443 -servername emergingdigital.be </dev/null 2>/dev/null | openssl x509 -noout -dates -subject -issuer
```

### Nginx

```bash
sudo nginx -t
sudo systemctl status nginx
```

### Vérification des redirections canoniques

```bash
curl -I http://emergingdigital.be
curl -I http://www.emergingdigital.be
curl -I https://www.emergingdigital.be
curl -I https://emergingdigital.be
```

Attendus :

- les trois premières URLs retournent une redirection (301/308) vers `https://emergingdigital.be` ;
- `https://emergingdigital.be` répond en HTTPS sans redirection vers `www`.

## Politique canonique à maintenir

La politique cible est :

- **Canonique unique** : `https://emergingdigital.be`
- **Aucun accès canonique en HTTP**
- **Aucun accès canonique en `www`**

Toute modification de vhost Nginx doit préserver ces règles.

## Emplacements sensibles (non versionnés)

Les certificats et clés privées restent exclusivement sur le serveur :

- `/etc/letsencrypt/live/emergingdigital.be/`
- `/etc/letsencrypt/archive/`
- `/etc/letsencrypt/renewal/`

Ne jamais versionner :

- certificats (`fullchain.pem`, `cert.pem`) ;
- clés privées (`privkey.pem`) ;
- secrets liés à l’infrastructure.

## Périmètre dépôt

La configuration Nginx de production n’est pas versionnée dans ce dépôt Drupal.

Ce dépôt documente la procédure d’exploitation mais ne stocke ni certificat ni secret.

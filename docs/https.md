# HTTPS en production

## Objectif

Ce projet utilise une politique canonique : **HTTPS sans `www`**.

- URL canonique : `https://emergingdigital.be`
- Domaine secondaire couvert : `https://www.emergingdigital.be`

## Certificat TLS

- Fournisseur du certificat : **Let’s Encrypt**
- Outil utilisé : **Certbot** avec plugin **Nginx**
- Domaines couverts : `emergingdigital.be` et `www.emergingdigital.be`

## Installation de Certbot

```bash
sudo apt install certbot python3-certbot-nginx -y
```

## Émission du certificat

```bash
sudo certbot --nginx -d emergingdigital.be -d www.emergingdigital.be
```

## Renouvellement (test à blanc)

```bash
sudo certbot renew --dry-run
```

## Emplacement habituel des certificats

```text
/etc/letsencrypt/live/emergingdigital.be/
```

## Diagnostic rapide

```bash
sudo nginx -t
sudo systemctl status nginx
sudo certbot certificates
sudo certbot renew --dry-run
```

## Règles de sécurité

- Ne jamais versionner de certificats.
- Ne jamais versionner de clés privées.

## Périmètre de configuration dans ce dépôt

La configuration Nginx de production n’est pas versionnée ici et reste gérée sur le serveur par Certbot.

Concernant la configuration Drupal versionnée (fichier `web/sites/default/settings.php`) :

- aucune valeur `base_url` n’est définie ;
- aucun `trusted_host_patterns` n’est défini ;
- aucun paramètre `reverse_proxy` n’est défini.

Ces éléments doivent être gérés par environnement si nécessaires, sans stocker de secrets dans Git.

# Ticket #4 — Troubleshooting `Html::escape(null)` render error

Si l'erreur persiste après un `ddev drush cr`, suivre cette checklist dans l'ordre.

## 1) Repartir de la config versionnée

```bash
ddev drush cim -y
ddev drush cr
```

## 2) Vérifier les modules/thèmes attendus

```bash
ddev drush pml --status=enabled --type=theme | grep -E "(gin|emerging_digital|claro|olivero)"
ddev drush pml --status=enabled --type=module | grep gin_toolbar
```

## 3) Vérifier le thème actif

```bash
ddev drush cget system.theme
```

Attendu :
- `admin: gin`
- `default: emerging_digital`

## 4) Vérifier les logs Drupal

```bash
ddev drush ws --count=50
```

Chercher les entrées autour de `Html::escape()` et les IDs de plugins/blocs impliqués.

## 5) Isolation rapide si l'erreur persiste

Basculer temporairement le front sur Olivero pour confirmer que le problème vient bien de la couche thème custom :

```bash
ddev drush cset system.theme default olivero -y
ddev drush cr
```

Si l'erreur disparaît, le problème est bien dans la surcouche thème/front.

## Note sur le correctif appliqué

Les overrides Twig les plus sensibles (`html`, `page`, `region`) ont été retirés pour revenir au rendu core Drupal.
L'objectif est d'éliminer toute injection de valeur `null` par le thème dans le pipeline de rendu.

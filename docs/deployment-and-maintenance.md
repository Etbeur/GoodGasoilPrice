# Déploiement transitoire et maintenance

Ce document décrit la configuration d'hébergement public actuel de **Good Gasoil Price** sur Railway ainsi que le protocole de maintenance annuelle obligatoire.

---

## 1. Hébergement public actuel et transitoire

L'application est actuellement hébergée et accessible au public à l'adresse suivante :

$$\text{URL publique transitoire : } \href{https://carburants-lezidejou.up.railway.app/}{\text{https://carburants-lezidejou.up.railway.app/}}$$

> [!NOTE]
> **Statut de l'hébergement Railway** :
> Cet hébergement sur la plateforme Railway constitue un **hébergement public actuel et transitoire**. Il permet de mettre à disposition du public la version autonome de Good Gasoil Price. L'architecture cible à terme prévoit l'intégration consolidée de la logique métier au sein du module `FuelPrice` du portail central LÉZIDÉJOU.

---

## 2. Configuration de déploiement Railway

Le dépôt intègre les fichiers de configuration nécessaires pour un déploiement PaaS automatisé :

### 2.1 `railway.toml`

Le fichier `railway.toml` configure le pipeline de construction et de démarrage :

```toml
[build]
builder = "nixpacks"

[deploy]
startCommand = "php artisan config:cache && php artisan route:cache && php artisan view:cache && vendor/bin/heroku-php-apache2 public/"
healthcheckPath = "/"
healthcheckTimeout = 100
restartPolicyType = "on_failure"
restartPolicyMaxRetries = 10
```

### 2.2 `Procfile`

Le fichier `Procfile` définit la commande d'exécution du serveur web :

```text
web: vendor/bin/heroku-php-apache2 public/
```

### 2.3 Variables d'environnement en production

Pour un fonctionnement optimal et sécurisé sur Railway, configurer les variables d'environnement suivantes dans le tableau de bord du projet :

```env
APP_NAME="Good Gasoil Price"
APP_ENV=production
APP_KEY=base64:VotreCleGenereeIci...
APP_DEBUG=false
APP_URL=https://carburants-lezidejou.up.railway.app
CACHE_STORE=file
LOG_CHANNEL=stack
LOG_LEVEL=error
```

---

## 3. Protocole de maintenance annuelle (Chaque 1er janvier)

Les paramètres économiques et fiscaux doivent faire l'objet d'une révision systématique **chaque 1er janvier** pour refléter la nouvelle loi de finances et l'état du marché.

### 3.1 Liste des vérifications à effectuer

1. **Accises sur les énergies** :
   - Consulter la loi de finances publiée au Journal Officiel et les barèmes de la Direction Générale des Douanes.
   - Mettre à jour les montants par carburant dans `config/fuel.php` sous la clé `taxes`.
2. **Taux de TVA** :
   - Confirmer le maintien du taux normal de 20 % ou adapter la clé `vat_rate` dans `config/fuel.php`.
3. **Coût de référence du bioéthanol** :
   - Réévaluer le coût moyen de l'éthanol agricole (`ethanol_cost_per_liter`, fixé à 0,42 €/L pour 2026).
4. **Marges de référence (Raffinage & Distribution)** :
   - Recontrôler les moyennes annuelles publiées par l'UFIP, la CLCV et l'UFC-Que Choisir pour ajuster les marges de référence si des variations structurelles sont constatées.
5. **Pérennité des flux d'API externes** :
   - Vérifier la disponibilité et le format des réponses de :
     - Frankfurter API v2 (BCE) ;
     - Yahoo Finance (`BZ=F` et `HO=F`) ;
     - Alpha Vantage (fallback Brent) ;
     - API Open Data DGCCRF (`data.economie.gouv.fr`).
6. **Validation par les tests automatisés** :
   - Exécuter la suite complète de tests de non-régression :
     ```bash
     php artisan test
     ```

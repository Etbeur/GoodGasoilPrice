# Good Gasoil Price

[![Laravel 13](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4.svg)](https://www.php.net)
[![Quality CI](https://github.com/Etbeur/GoodGasoilPrice/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/Etbeur/GoodGasoilPrice/actions/workflows/quality.yml)
[![Railway](https://img.shields.io/badge/Deploy-Railway-0B0D0E.svg)](https://railway.app)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

Application web publique d'information citoyenne et pédagogique qui répond à une question simple :

> **Quel pourrait être le prix du carburant aujourd’hui en France ?**

👉 **Accéder à l'application en ligne** : **[https://carburants-lezidejou.up.railway.app/](https://carburants-lezidejou.up.railway.app/)**
*(Hébergement public actuel et transitoire)*

---

## Ce que fait l'application

* **Estimation indicative du prix théorique** : Calcule chaque jour un prix théorique moyen à la pompe à partir des cotations de marché et des composantes réelles de coût.
* **Comparaison avec les prix déclarés** : Présente en regard les prix moyens nationaux constatés issus du flux officiel du Ministère de l'Économie.
* **Six carburants couverts** : SP95-E10, SP95, SP98, Gazole, E85 et GPL.
* **Démarche pédagogique et transparente** : Expose la décomposition complète du prix entre matière première, raffinage, distribution, accise et TVA.

---

## Avertissement

Les prix affichés par l'application sont des **repères citoyens informatifs**. Ils ne constituent ni une vérité absolue, ni un prix garanti, ni une accusation envers les stations-service, distributeurs ou raffineurs. Des écarts légitimes existent selon les contextes locaux d'approvisionnement, les coûts de transport, les obligations réglementaires et les politiques commerciales.

---

## Sources principales

* **Données de marché** : Cours du pétrole brut Brent (`BZ=F`) et indicateur distillat NY Harbor ULSD (`HO=F`).
* **Taux de change USD vers EUR** : [Frankfurter API v2](https://api.frankfurter.dev/v2/rates?base=USD&quotes=EUR&providers=ECB&expand=providers), adossée aux publications officielles de la Banque Centrale Européenne (BCE).
* **Données officielles françaises des prix déclarés** : [data.economie.gouv.fr](https://data.economie.gouv.fr) — Jeu de données officiel DGCCRF (Licence Ouverte 2.0).
* **Paramètres fiscaux et réglementaires** : Direction Générale des Douanes, loi de finances 2026, UFIP et FIPECO.

---

## Méthodologie

Le modèle reproduit la chaîne de constitution du prix du carburant en combinant :
1. Le **coût de la matière première** converti en euros par litre ;
2. La **marge de raffinage** moyenne selon le carburant ;
3. La **marge de transport et distribution** (intégrant les CEE et la taxe TIRUERT) ;
4. Le montant fixe de l'**accise sur les énergies 2026** ;
5. La **TVA à 20 %** s'appliquant sur le sous-total hors taxes et sur l'accise.

Une fourchette indicative de **±0,10 €/L** est présentée autour de l'estimation pour refléter la dispersion normale du marché.

📖 Pour consulter les formules complètes, les coefficients et les justifications de calcul :
👉 **[Consulter la documentation méthodologique détaillée](docs/methodology.md)**

---

## Stack technique

* **Framework** : Laravel 13 (installation légère).
* **Langage** : PHP 8.4 (compatible `^8.4`).
* **Rendu & Interface** : Vues Blade (SSR), CSS vanilla responsive.
* **Dépendances frontales** : Aucun framework JavaScript lourd, aucun build npm requis en production.

---

## Installation locale

### Pré-requis
* PHP 8.4 avec extensions usuelles (`curl`, `mbstring`, `xml`, `bcmath`)
* Composer

### Démarrage rapide

```bash
# 1. Cloner le dépôt et installer les dépendances
git clone https://github.com/Etbeur/GoodGasoilPrice.git
cd GoodGasoilPrice
composer install

# 2. Configurer l'environnement
cp .env.example .env
php artisan key:generate

# 3. Exécuter la suite de tests
php artisan test

# 4. Démarrer le serveur de développement local
php artisan serve
```

---

## Documentation technique

Pour approfondir le fonctionnement du projet, trois documents spécialisés sont disponibles :

* 📐 **[Méthodologie de calcul et paramètres métier](docs/methodology.md)** : Formules mathématiques détaillées, barèmes fiscaux 2026, comparaison DGEC et limites d'interprétation.
* 🏗️ **[Architecture technique et résilience](docs/technical-architecture.md)** : Flux applicatif, services, gestion des sources et fallbacks, politiques de cache et sécurité.
* 🚀 **[Déploiement transitoire et maintenance](docs/deployment-and-maintenance.md)** : Configuration Railway (`railway.toml`, `Procfile`) et protocole de maintenance annuelle du 1er janvier.

---

## Licence

* Code source du projet distribué sous licence **[MIT](LICENSE)**.
* Données officielles de prix déclarés distribuées sous **Licence Ouverte 2.0** (DGCCRF / Ministère de l'Économie).

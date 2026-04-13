# GoodGasoilPrice

[![Laravel 13](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4.svg)](https://www.php.net)
[![Railway](https://img.shields.io/badge/Deploy-Railway-0B0D0E.svg)](https://railway.app)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

Application web publique Laravel qui répond à une question simple :

> **Quel devrait être le prix du carburant aujourd'hui en France ?**

GoodGasoilPrice calcule un **prix théorique citoyen** du carburant à la pompe en France à partir de données de marché proches du temps réel, puis l'affiche aux côtés d'un prix moyen constaté à la pompe.

## Avertissement

Les prix affichés par l'application sont des **repères citoyens informatifs**. Ils ne constituent ni une vérité absolue, ni une accusation envers les distributeurs, raffineurs ou stations-service. Des écarts légitimes peuvent exister selon la logistique locale, les coûts d'approvisionnement, les obligations réglementaires, les spreads de raffinage, les stocks et les politiques commerciales.

## Objectif du projet

- Fournir une page publique, lisible et pédagogique.
- Afficher un prix théorique "juste" du carburant en France.
- Expliquer la décomposition du prix : matière première, distribution, accise, TVA.
- Permettre à tout citoyen de comparer ce prix théorique au prix constaté à la pompe.

## Carburants couverts

- SP95-E10
- SP95
- SP98
- Gazole
- E85
- GPL

## Stack technique

- Laravel 13
- PHP 8.4
- CSS vanilla
- JavaScript framework : aucun
- Build front : aucun
- Déploiement cible : Railway

Le projet ne repose ni sur Vite, ni sur npm, ni sur une compilation d'assets pour fonctionner en production.
Le code est documenté pour **PHP 8.4** ; à ce jour, `composer.json` reste compatible avec `^8.3`.

## Fonctionnement général

L'application expose une seule page publique sur `/`.

Le flux applicatif est volontairement simple :

1. `routes/web.php` déclare la route publique.
2. `app/Http/Controllers/FuelController.php` délègue le travail métier.
3. `app/Services/FuelPriceService.php` récupère les données marché, applique les formules de calcul et prépare les données d'affichage.
4. `config/fuel.php` centralise les paramètres métier : accises, marges, TVA, métadonnées carburants, constantes de conversion et TTL du cache.
5. Les vues Blade affichent le résultat dans une interface publique unique.

## Sources de données

- **Cours Brent** : Yahoo Finance API, ticker `BZ=F`
- **Heating Oil NYMEX** : Yahoo Finance API, ticker `HO=F`, utilisé pour le Gazole
- **Taux EUR/USD** : [Frankfurter API](https://www.frankfurter.app), adossée aux données BCE
- **Prix constatés à la pompe** : [prix-carburants.gouv.fr](https://prix-carburants.gouv.fr) via widgets `prix-carburant.eu`

## Formules de calcul

### Essences : SP95-E10, SP95, SP98

Formule générale :

```text
coût brut = (Brent $/baril / 159) x EUR/USD
+ marge de raffinage spécifique par carburant
+ distribution 0.32 €/L
+ accise fixe 2026
+ TVA 20%
```

### Gazole

Formule générale :

```text
coût matière = (HO=F $/gallon / 3.78541) x EUR/USD
+ prime ARA 0.06 €/L
+ distribution 0.32 €/L
+ accise 0.61 €/L
+ TVA 20%
```

Le Gazole utilise `HO=F` comme proxy du marché gasoil ARA Rotterdam, car cette source gratuite est la plus exploitable dans le périmètre du projet.

### E85

Formule hybride :

```text
coût matière = (15% x coût Brent/litre) + (85% x 0.42 €/L éthanol)
+ distribution 0.10 €/L
+ accise 0.1186 €/L
+ TVA 20%
```

### GPL

Formule générale :

```text
coût brut = (Brent $/baril / 159) x EUR/USD
+ raffinage 0.04 €/L
+ distribution 0.10 €/L
+ accise 0.1710 €/L
+ TVA 20%
```

Cette valeur de distribution correspond au paramétrage actuel du dépôt dans `config/fuel.php`.

## Fiscalité fixe 2026

Source de référence : UFIP, loi de finances 2026.

| Carburant | Accise |
| --- | ---: |
| SP95 | 0.6829 €/L |
| SP98 | 0.6829 €/L |
| SP95-E10 | 0.6629 €/L |
| Gazole | 0.6100 €/L |
| E85 | 0.1186 €/L |
| GPL | 0.1710 €/L |

TVA :

- `20%` sur `(produit HT + accise)`

Ces valeurs doivent être révisées chaque année au **1er janvier**.

## Paramétrage métier centralisé

Le fichier `config/fuel.php` centralise notamment :

- les accises 2026
- la TVA
- les marges de raffinage
- les marges de distribution
- `ethanol_cost_per_liter`
- `gasoil_ara_premium`
- la fourchette affichée autour du prix théorique
- la durée de cache des données de marché
- la liste des carburants affichés

## Cache et fraîcheur des données

Le projet utilise le cache Laravel pour éviter des appels externes à chaque requête.

- Driver attendu pour ce projet : `file`
- TTL marché : `900` secondes, soit **15 minutes**
- Données concernées :
  - Brent
  - Heating Oil `HO=F`
  - EUR/USD

Configuration recommandée dans `.env` :

```env
CACHE_STORE=file
```

Le TTL métier est défini dans `config/fuel.php`.

## Installation locale

Pré-requis :

- PHP 8.4
- Composer
- Laravel Herd

Installation :

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Ensuite, lancer le projet en local via Laravel Herd.

## Variables d'environnement utiles

Variables minimales :

- `APP_NAME`
- `APP_ENV`
- `APP_KEY`
- `APP_URL`
- `CACHE_STORE=file`

Variable optionnelle :

- `ALPHA_VANTAGE_KEY`

`ALPHA_VANTAGE_KEY` est utilisée comme **fallback** serveur si Yahoo Finance est indisponible pour le Brent.

## Déploiement Railway

Le projet est prévu pour un déploiement sur Railway.

Éléments présents dans le dépôt :

- `railway.toml`
- `Procfile`

Le déploiement actuel prévoit :

- build via `nixpacks`
- préchauffage des caches Laravel (`config`, `route`, `view`)
- démarrage sur `0.0.0.0:$PORT`
- healthcheck sur `/`

Pensez à configurer dans Railway :

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL`
- `CACHE_STORE=file`

## Maintenance annuelle

Chaque **1er janvier**, mettre à jour les paramètres fiscaux et métier dans `config/fuel.php`.

Vérifications à effectuer :

1. Mettre à jour les accises selon la loi de finances en vigueur.
2. Vérifier la TVA si le cadre fiscal évolue.
3. Réviser `ethanol_cost_per_liter`.
4. Réviser `gasoil_ara_premium`.
5. Recontrôler les marges de distribution et de raffinage si les conditions de marché changent fortement.
6. Vérifier que les sources externes sont toujours accessibles et stables.

## Références

- [UFIP Énergies et Mobilités](https://www.ufip.fr)
- [FIPECO](https://www.fipeco.fr)
- [CLCV](https://www.clcv.org)
- [Frankfurter API](https://www.frankfurter.app)
- [Prix des carburants en France](https://prix-carburants.gouv.fr)

## Structure du projet

```text
app/
  Http/Controllers/FuelController.php
  Services/FuelPriceService.php
config/
  fuel.php
public/
  css/app.css
resources/
  views/layouts/app.blade.php
  views/fuel/index.blade.php
routes/
  web.php
railway.toml
Procfile
```

## Philosophie du projet

GoodGasoilPrice est conçu comme un outil public simple, transparent et pédagogique. Le projet privilégie :

- la lisibilité du calcul
- la centralisation des paramètres métier
- l'absence de dépendances front inutiles
- une maintenance annuelle explicite
- un déploiement simple sur Railway

## Licence

Projet distribué sous licence **MIT**.

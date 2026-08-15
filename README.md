# GoodGasoilPrice

[![Laravel 13](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4.svg)](https://www.php.net)
[![Railway](https://img.shields.io/badge/Deploy-Railway-0B0D0E.svg)](https://railway.app)
[![License MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

Application web publique Laravel qui répond à une question simple :

> **Quel pourrait être le prix du carburant aujourd’hui en France ?**

GoodGasoilPrice calcule une **estimation indicative citoyenne** du prix du carburant à la pompe en France à partir des dernières données de marché disponibles, puis l'affiche aux côtés des **prix moyens nationaux déclarés** récupérés directement depuis le flux officiel Open Data du Ministère de l'Économie (DGCCRF).

## Avertissement

Les prix affichés par l'application sont des **repères citoyens informatifs**. Ils ne constituent ni une vérité absolue, ni un prix garanti, ni une accusation envers les distributeurs, raffineurs ou stations-service. Des écarts légitimes peuvent exister selon la logistique locale, les coûts d'approvisionnement, les obligations réglementaires, les spreads de raffinage, les stocks et les politiques commerciales.

## Objectif du projet

- Fournir une page publique, lisible et pédagogique.
- Afficher un prix théorique indicatif du carburant en France.
- Expliquer la décomposition du prix : matière première, distribution, accise, TVA.
- Présenter les prix moyens nationaux déclarés issus du flux officiel du Ministère de l'Économie (Licence Ouverte 2.0).

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
Le code est documenté pour **PHP 8.4** ; `composer.json` requiert `^8.4`.

## Fonctionnement général

L'application expose une seule page publique sur `/`.

Le flux applicatif est le suivant :

1. `routes/web.php` déclare la route publique.
2. `app/Http/Controllers/FuelController.php` orchestre la récupération des données.
3. `app/Services/FuelPriceService.php` récupère les données de marché, applique les formules de calcul et prépare les prix théoriques.
4. `app/Services/ObservedFuelPriceService.php` interroge l'API Open Data officielle du Ministère de l'Économie pour récupérer les moyennes nationales déclarées.
5. `config/fuel.php` centralise les paramètres métier : accises 2026, marges, TVA, métadonnées carburants, constantes de conversion et durées de cache.
6. Les vues Blade affichent le résultat dans une interface publique unique sans iframe tierce.

## Sources de données

- **Cours Brent** : Yahoo Finance API (ticker `BZ=F`, dernières données disponibles), avec fallback serveur Alpha Vantage (cotation et date réelles).
- **Indicateur Gazole NYMEX** : Yahoo Finance API (ticker `HO=F`, NY Harbor ULSD — indicateur de repli du marché américain en l'absence de flux public ARA temps réel).
- **Taux de change USD vers EUR** : [Frankfurter API v2](https://api.frankfurter.dev/v2/rates?base=USD&quotes=EUR&providers=ECB&expand=providers), adossée aux publications officielles de la Banque Centrale Européenne (BCE).
- **Prix moyens nationaux déclarés** : [data.economie.gouv.fr](https://data.economie.gouv.fr) — Jeu de données officiel DGCCRF / prix-carburants.gouv.fr (Licence Ouverte 2.0).

### Résilience et gestion du cache du taux de change BCE

Le service applique une stratégie de cache et de validation stricte :
- **Cache nominal (1 heure)** : stocke le taux extrait de l'observation officielle BCE (`usd_eur_rate_data`).
- **Cache de repli (7 jours)** : conserve le dernier taux BCE valide (`usd_eur_last_valid_data`) pour assurer la continuité de service en cas d'indisponibilité temporaire de l'API distante.
- **Métadonnées conservées** : chaque entrée en cache contient obligatoirement `rate` (taux numérique positif fini), `date` (date YYYY-MM-DD validée) et `provider_key` (`ECB`).
- **Rejet des caches anciens ou incomplets** : tout cache dépourvu de `provider_key`, portant sur un autre fournisseur ou contenant une valeur invalide est systématiquement rejeté.
- **Absence de taux artificiel** : en cas de démarrage à froid sans aucune donnée valide accessible, l'application ne fabrique aucun taux arbitraire et signale proprement l'indisponibilité du marché.

## Formules de calcul

### Essences : SP95-E10, SP95, SP98

Formule générale :

```text
coût brut = (Brent $/baril / 159) x taux USD vers EUR
+ marge de raffinage spécifique par carburant
+ distribution 0.32 €/L
+ accise fixe 2026
+ TVA 20%
```

### Gazole

Formule de calcul indicative :

```text
coût matière = (HO=F $/gallon / 3.785411784) x taux USD vers EUR
+ distribution 0.32 €/L
+ accise 0.61 €/L
+ TVA 20%
```

En l'absence de cotation ARA Rotterdam (ICE Low Sulphur Gasoil) en accès ouvert et temps réel, l'application utilise l'indicateur NY Harbor ULSD (`HO=F`, coté en USD/gallon) comme repère indicatif du marché américain des distillats. Aucune prime fixe arbitraire n'y est ajoutée. Si l'indicateur NY Harbor ULSD est indisponible, l'application active automatiquement un repli transparent vers le cours du Brent avec une marge de raffinage moyenne.

**Repère de comparaison officiel DGEC (Ministère de la Transition écologique)** :
À titre de repère de comparaison documenté (publication officielle hebdomadaire du [7 août 2026](https://www.ecologie.gouv.fr/sites/default/files/documents/NPG-2026.08.07.pdf), ce document n'étant pas une source dynamique de l'application mais un étalon de validation) :
- Cotation internationale gazole : `0.880 €/L`
- Transport-distribution : `0.320 €/L`
- Accise : `0.610 €/L`
- TVA 20 % : `0.362 €/L`
- **Total TTC calculé** : `2.172 €/L` (fourchette `2.072 €/L` à `2.272 €/L`).

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
coût brut = (Brent $/baril / 159) x taux USD vers EUR
+ raffinage 0.04 €/L
+ distribution 0.10 €/L
+ accise 0.1710 €/L
+ TVA 20%
```

Cette valeur de distribution correspond au paramétrage actuel du dépôt dans `config/fuel.php`.

## Fiscalité fixe 2026

Source de référence : UFIP, FIPECO, Direction Générale des Douanes, loi de finances 2026.

| Carburant | Accise |
| --- | ---: |
| SP95 | 0.6829 €/L |
| SP98 | 0.6829 €/L |
| SP95-E10 | 0.6629 €/L |
| Gazole | 0.6100 €/L |
| E85 | 0.1186 €/L |
| GPL | 0.1710 €/L |

TVA : `20%` sur `(produit HT + accise)`.

Ces valeurs doivent être révisées chaque année au **1er janvier**.

## Paramétrage métier centralisé

Le fichier `config/fuel.php` centralise notamment :

- les accises 2026
- la TVA
- les marges de raffinage
- les marges de distribution
- `ethanol_cost_per_liter`
- la fourchette indicative affichée autour du prix théorique
- la durée de cache des données de marché
- la liste des carburants affichés

## Cache et fraîcheur des données

Le projet utilise le cache Laravel pour limiter les appels externes :

- **Données marché** (Brent, HO=F, taux USD vers EUR) : cache fichier de **1 heure** (`3600` secondes).
- **Prix moyens nationaux déclarés** (Open Data DGCCRF) :
  - Cache courant : **30 minutes** (`1800` secondes) ;
  - Dernier résultat valide de repli : **24 heures** (`86400` secondes) ;
  - Temporisation d'échec : **1 minute** (`60` secondes).

Configuration recommandée dans `.env` :

```env
CACHE_STORE=file
```

## Installation locale

Pré-requis :

- PHP 8.4
- Composer

Installation :

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Lancer les tests :

```bash
php artisan test
```

Démarrer le serveur local :

```bash
php artisan serve
```

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

Configuration recommandée dans Railway :

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
4. Recontrôler les marges de distribution et de raffinage si les conditions de marché changent fortement.
5. Vérifier que les sources externes sont toujours accessibles et stables.

## Références

- [UFIP Énergies et Mobilités](https://www.ufip.fr)
- [FIPECO](https://www.fipeco.fr)
- [CLCV](https://www.clcv.org)
- [Frankfurter API](https://api.frankfurter.dev/v2/rates?base=USD&quotes=EUR&providers=ECB&expand=providers)
- [Prix des carburants en France](https://prix-carburants.gouv.fr)
- [Portail Open Data du Ministère de l'Économie](https://data.economie.gouv.fr)

## Structure du projet

```text
app/
  Http/Controllers/FuelController.php
  Services/FuelPriceService.php
  Services/ObservedFuelPriceService.php
config/
  fuel.php
public/
  css/app.css
resources/
  views/layouts/app.blade.php
  views/fuel/index.blade.php
routes/
  web.php
tests/
  Feature/
  Unit/
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
Données officielles de prix déclarés distribuées sous **Licence Ouverte 2.0** (DGCCRF / Ministère de l'Économie).

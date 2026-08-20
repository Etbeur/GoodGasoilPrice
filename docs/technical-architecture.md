# Architecture technique et résilience

Ce document décrit l'architecture logicielle, le cycle de vie des requêtes, les mécanismes de cache, la gestion des sources externes et la stratégie de résilience de **Good Gasoil Price**.

---

## 1. Vue d'ensemble de la stack

* **Framework** : Laravel 13 (installation légère, sans dépendances front lourdes).
* **Langage** : PHP 8.4 (typage strict, assertions de robustesse).
* **Rendu** : Vues Blade avec Server-Side Rendering (SSR).
* **Styles** : CSS vanilla responsive (`public/css/app.css`), aucun préprocesseur ni compilation npm/Vite requis en production.
* **Persistance** : Aucun SGBD relationnel requis en V1 (stockage des données en cache applicatif fichier).

---

## 2. Flux applicatif de la requête

L'application expose une unique route publique sur `/` dont le cycle d'exécution est le suivant :

```text
Navigateur Client
      │ (GET /)
      ▼
routes/web.php
      │
      ▼
app/Http/Controllers/FuelController.php
      ├──► app/Services/FuelPriceService.php
      │         ├── Taux USD/EUR (Frankfurter API / BCE)
      │         ├── Cours Brent (Yahoo Finance / Alpha Vantage)
      │         ├── Indicateur Gazole (Yahoo Finance HO=F)
      │         └── Formules de calcul & formatage
      │
      ├──► app/Services/ObservedFuelPriceService.php
      │         └── API Open Data DGCCRF (data.economie.gouv.fr)
      │
      ▼
resources/views/fuel/index.blade.php (Layout resources/views/layouts/app.blade.php)
      │ (Rendu HTML SSR)
      ▼
Navigateur Client
```

---

## 3. Sources externes et stratégies de repli

Tous les appels aux services tiers s'exécutent **exclusivement côté serveur** (aucun appel direct, jeton ou clé API exposé côté client).

### 3.1 Taux de change USD/EUR (Banque Centrale Européenne)

* **Fournisseur principal** : [Frankfurter API v2](https://api.frankfurter.dev/v2/rates?base=USD&quotes=EUR&providers=ECB&expand=providers) (gratuite, sans clé requise, adossée aux publications officielles de la BCE).
* **Extraction et validation strictes** :
  - Extraction de l'observation portant la clé `ECB`.
  - Vérification de la présence d'un taux numérique fini et strictement positif.
  - Vérification de la date de publication canonique (format `YYYY-MM-DD`).
* **Résilience et politique de cache** :
  - **Cache nominal (1 heure)** : clé `usd_eur_rate_data`.
  - **Cache de repli (7 jours)** : clé `usd_eur_last_valid_data`, stockant le dernier taux BCE valide avec ses métadonnées complètes (`rate`, `date`, `provider_key`).
  - **Rejet strict des caches invalides** : tout cache ancien non conforme ou associé à un autre fournisseur est rejeté.
  - **Aucun taux artificiel** : en cas d'indisponibilité totale et d'absence de cache valide, le service ne génère aucun taux arbitraire et retourne un état d'indisponibilité transparent.

### 3.2 Cours du pétrole brut Brent

* **Fournisseur principal** : Yahoo Finance API non officielle (symbole `BZ=F`, extraction du dernier cours de clôture ou temps réel).
* **Fournisseur de repli** : Alpha Vantage (symbole `BRENT`, clé configurée via `ALPHA_VANTAGE_KEY` dans `.env`).

### 3.3 Indicateur Gazole Distillat

* **Fournisseur principal** : Yahoo Finance API non officielle (symbole `HO=F`, New York Harbor Ultra-Low Sulfur Diesel).
* **Stratégie de repli interne** : En cas d'indisponibilité de la cotation `HO=F`, bascule automatique sur un calcul dérivé du cours du Brent majoré d'une marge de raffinage moyenne.

### 3.4 Prix moyens nationaux déclarés (DGCCRF)

* **Source officielle** : Ministère de l'Économie / DGCCRF via la plateforme Open Data nationale ([data.economie.gouv.fr](https://data.economie.gouv.fr)).
* **Jeu de données** : `prix-des-carburants-en-france-flux-instantane-v2` (Licence Ouverte 2.0).
* **Agrégations côté serveur** : Requêtes SQL agrégées `avg()`, `count()`, `max()` pour obtenir les moyennes nationales déclarées sur les dernières 48 heures.
* **Cache à deux niveaux** :
  - **Cache courant (30 minutes)** : `1800` secondes.
  - **Cache de repli dernier résultat valide (24 heures)** : `86400` secondes.
  - **Temporisation sur échec (1 minute)** : `60` secondes pour éviter d'engorger le service distant lors d'anomalies réseau.

---

## 4. Structure des fichiers clés

```text
GoodGasoilPrice/
├── app/
│   ├── Http/Controllers/
│   │   └── FuelController.php               # Orchestration du contrôleur principal
│   └── Services/
│       ├── FuelPriceService.php             # Données de marché, calculs et fallbacks
│       └── ObservedFuelPriceService.php     # Données Open Data DGCCRF et cache 2 niveaux
├── config/
│   └── fuel.php                             # Paramètres métier, accises 2026 et durées de cache
├── public/
│   └── css/
│       └── app.css                          # Styles vanilla responsive
├── resources/
│   └── views/
│       ├── layouts/
│       │   └── app.blade.php                # Layout HTML principal
│       └── fuel/
│           └── index.blade.php              # Vue SSR principale
├── routes/
│   └── web.php                              # Route unique GET /
├── tests/
│   ├── Feature/                             # Tests fonctionnels de routage et rendu
│   └── Unit/                                # Tests unitaires des calculs et services
├── Procfile                                 # Commande de processus Railway
└── railway.toml                             # Configuration d'environnement Railway
```

---

## 5. Variables d'environnement documentaires

Les variables d'environnement sont répertoriées dans `.env.example` :

| Variable | Type | Rôle | Valeur recommandée en production |
|---|---|---|---|
| `APP_NAME` | Chaîne | Nom de l'application | `"GoodGasoilPrice"` |
| `APP_ENV` | Chaîne | Environnement d'exécution | `production` |
| `APP_KEY` | Chaîne | Clé de chiffrement Laravel | *Générée via `php artisan key:generate`* |
| `APP_DEBUG` | Booléen | Mode de débogage | `false` |
| `APP_URL` | URL | URL canonique publique | `https://carburants-lezidejou.up.railway.app` |
| `CACHE_STORE` | Chaîne | Driver de cache | `file` |
| `ALPHA_VANTAGE_KEY` | Chaîne | Clé API optionnelle pour le fallback Brent | *Optionnel (laisser vide si non utilisé)* |

---

## 6. Sécurité et robustesse

1. **Isolation des secrets** : Aucune clé d'API, aucun jeton de service ni aucune variable d'authentification n'est injecté dans les vues ou le code public.
2. **Gestion des exceptions** : Les erreurs de communication réseau sont interceptées et journalisées sans exposer de traces complètes aux utilisateurs.
3. **Absence de traceurs tiers** : Aucun script tiers, pixel de suivi ou outil d'analyse externe intrusif n'est chargé sur la page publique.

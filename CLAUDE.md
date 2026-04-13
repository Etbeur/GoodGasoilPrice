# CLAUDE.md — GoodGasoilPrice

## CONTEXTE DU PROJET

Application web publique qui calcule et affiche le prix théorique "juste" du carburant à la pompe
en France, à partir des données de marché en temps réel. L'objectif est de permettre à n'importe
quel citoyen de comparer le prix affiché à la pompe avec ce que le prix devrait logiquement être
compte tenu des données du marché.

Ce type d'outil n'existe pas sous cette forme sur le web français. Les sites existants montrent
les prix constatés, pas les prix théoriques calculés à partir du brut du moment.

---

## STACK TECHNIQUE

- PHP 8.4
- Laravel 13 (installation légère)
- Vues Blade pour le HTML
- CSS vanilla responsive, sans framework CSS externe
- Pas de base de données en V1
- Pas d'authentification en V1
- Cache Laravel natif (driver fichier) avec rafraîchissement toutes les 15 minutes
- Déploiement cible : Railway (gratuit au démarrage)

---

## CARBURANTS COUVERTS

SP95-E10, SP98, Gazole, E85, GPL

---

## APIS UTILISÉES

### 1. Taux EUR/USD en temps réel
- Fournisseur : Frankfurter API (gratuite, sans clé, maintenue par la BCE)
- URL : `https://api.frankfurter.app/latest?from=USD&to=EUR`
- Réponse exemple : `{"amount":1.0,"base":"USD","date":"2026-04-10","rates":{"EUR":0.862}}`

### 2. Cours du pétrole Brent en temps réel
- Fournisseur principal : Yahoo Finance API non officielle (gratuite, sans clé)
- Symbole : `BZ=F`
- URL : `https://query1.finance.yahoo.com/v8/finance/chart/BZ=F`
- Fallback : Alpha Vantage (clé API gratuite requise, à placer dans `.env`)

### 3. Prix carburants constatés en France (pour comparaison)
- Source : `https://prix-carburants.gouv.fr` (données officielles)
- **À intégrer en V2, pas en V1**

**IMPORTANT** : tous les appels API se font exclusivement côté serveur.
Aucune clé API ne doit être exposée côté client.

---

## TAXES FIXES 2026

Valeurs intégrées dans `config/fuel.php`. Mise à jour manuelle chaque 1er janvier selon loi de finances.

| Carburant          | Accise (€/litre) |
|--------------------|-----------------|
| SP95 / SP98 / E10  | 0.6829          |
| Gazole             | 0.5940          |
| E85                | 0.1260          |
| GPL                | 0.1710          |
| TVA                | 20% sur (produit HT + accise) |

Sources : UFIP, FIPECO, loi de finances 2026

---

## FORMULE DE CALCUL DU PRIX THÉORIQUE

```
Étape 1 : Coût brut par litre
  = (cours Brent en dollars / 159) / taux EUR/USD
  (159 = nombre de litres dans un baril de pétrole)

Étape 2 : Ajouter marge de raffinage
  - Essences (SP95, SP98, E10) : +0.07 €/litre
  - Gazole                     : +0.12 €/litre
  - E85                        : +0.05 €/litre
  - GPL                        : +0.04 €/litre

Étape 3 : Ajouter marge distribution normale
  +0.18 €/litre pour tous les carburants
  Source : CLCV, valeur normale estimée 2025-2026

Étape 4 : Ajouter accise fixe selon le carburant

Étape 5 : Appliquer TVA 20% sur (sous-total HT + accise)
  prix_ttc = (cout_ht + accise) * 1.20

Étape 6 : Afficher une fourchette de ±0.10 € autour du prix calculé
```

---

## AFFICHAGE ATTENDU PAR CARBURANT

- Prix théorique calculé avec fourchette honnête
- Décomposition en 4 lignes :
  1. Produit brut + raffinage
  2. Distribution
  3. Accise (fixe)
  4. TVA
- Cours Brent utilisé pour le calcul
- Taux EUR/USD utilisé pour le calcul
- Date et heure de la dernière mise à jour des données
- Mention des sources : UFIP, FIPECO, Frankfurter API, Yahoo Finance

---

## CACHE

```php
// Utiliser Cache::remember() natif Laravel
// Durée : 900 secondes (15 minutes)
// Driver : fichier (pas de Redis en V1)
$brent = Cache::remember('brent_price', 900, fn() => $this->fetchBrent());
```

---

## STRUCTURE DES FICHIERS CLÉS

```
app/Services/FuelPriceService.php       ← Logique : appels API, calculs, formatage
app/Http/Controllers/FuelController.php ← Appelle le service, passe les données à la vue
resources/views/fuel/index.blade.php    ← Vue principale, affichage des prix par carburant
resources/views/layouts/app.blade.php   ← Layout principal avec emplacement Google AdSense
routes/web.php                          ← Une seule route publique : GET /
config/fuel.php                         ← Taxes, marges et paramètres de calcul centralisés
.env.example                            ← Avec clé ALPHA_VANTAGE_KEY commentée
```

---

## PUBLICITÉ

Emplacement Google AdSense prévu dans `resources/views/layouts/app.blade.php`.
Format : div commentée, responsive, non intrusive, à activer en V2.

---

## DÉPLOIEMENT RAILWAY

Fichiers de déploiement présents à la racine :
- `Procfile` — commande de démarrage du serveur PHP
- `railway.toml` — configuration Railway
- `.env.example` — variables d'environnement à copier en `.env`

---

## RÈGLES DE CODE

- Tous les commentaires en français
- Chaque bloc de calcul commenté avec sa source (UFIP, FIPECO, CLCV selon le cas)
- Aucune clé API exposée côté client ou dans le code versionné
- Code propre, lisible, sans dépendances inutiles
- Pas de framework JavaScript (vanilla JS uniquement si nécessaire)

---

## POINTS DE VIGILANCE

- **Marge de raffinage volatile** : les valeurs dans ce projet sont des moyennes 2025-2026.
  En période de crise, la marge réelle peut s'éloigner significativement.

- **Écart pompe / théorique** : le prix constaté à la pompe peut légitimement dépasser
  le prix théorique de 10 à 15 centimes en raison des coûts réels de logistique,
  normes environnementales, CEE, TIRUERT. L'outil donne un repère, il n'accuse pas
  les distributeurs.

- **Sécurité IA** : 45 à 80% du code généré par les outils IA contient des vulnérabilités
  selon Veracode/Stanford 2026. Prévoir une relecture du code avant mise en production.

---

## SOURCES DE RÉFÉRENCE

- UFIP Energies et Mobilités : https://www.ufip.fr
- FIPECO taxes carburants : https://www.fipeco.fr
- Connaissance des Energies : https://www.connaissancedesenergies.org
- CLCV marges distribution mars 2026 : https://www.clcv.org
- API Frankfurter : https://www.frankfurter.app

---

## ROADMAP

### V1 (en cours)
- [x] Calcul du prix théorique pour SP95-E10, SP98, Gazole, E85, GPL
- [x] Appels API Brent (Yahoo Finance) + fallback Alpha Vantage
- [x] Taux EUR/USD via Frankfurter
- [x] Cache fichier 15 minutes
- [x] Vue Blade responsive, CSS vanilla
- [x] Déploiement Railway

### V2 (prévu)
- [ ] Intégration des prix constatés depuis prix-carburants.gouv.fr
- [ ] Comparaison prix théorique vs prix constaté par carburant
- [ ] Carte interactive des stations
- [ ] Historique sur 30 jours
- [ ] Google AdSense activé

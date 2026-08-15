# CLAUDE.md — GoodGasoilPrice

## CONTEXTE DU PROJET

Application web publique qui calcule et affiche une estimation indicative du prix du carburant à la pompe en France à partir des données de marché, et présente en regard les prix moyens nationaux déclarés issus du flux Open Data officiel du Ministère de l'Économie (DGCCRF).

L'objectif est de fournir un repère citoyen pédagogique permettant de comprendre la décomposition des coûts (matière première, raffinage, distribution, accise, TVA).

---

## STACK TECHNIQUE

- PHP 8.4
- Laravel 13 (installation légère)
- Vues Blade pour le rendu SSR
- CSS vanilla responsive, sans framework CSS externe
- Pas de base de données requise en V1
- Pas d'authentification en V1
- Cache Laravel natif (driver fichier)
- Déploiement cible : Railway

---

## CARBURANTS COUVERTS

SP95-E10, SP95, SP98, Gazole, E85, GPL

---

## APIS UTILISÉES

### 1. Taux de change USD vers EUR
- Fournisseur : Frankfurter API (gratuite, sans clé, adossée aux taux de référence BCE du dernier jour ouvré)
- URL : `https://api.frankfurter.app/latest?from=USD&to=EUR`
- Réponse exemple : `{"amount":1.0,"base":"USD","date":"2026-08-14","rates":{"EUR":0.8645}}`

### 2. Cours du pétrole Brent
- Fournisseur principal : Yahoo Finance API non officielle (gratuite, sans clé, symbole `BZ=F`)
- Fallback : Alpha Vantage (clé API requise dans `.env` si activé)

### 3. Cotation Gazole (proxy ARA Rotterdam)
- Fournisseur : Yahoo Finance API non officielle (ticker `HO=F`, Heating Oil NYMEX)

### 4. Prix moyens nationaux déclarés (DGCCRF)
- Source officielle : Ministère de l'Économie / DGCCRF via Open Data (`data.economie.gouv.fr`)
- Jeu de données : `prix-des-carburants-en-france-flux-instantane-v2`
- Licence : Licence Ouverte 2.0
- Récupération côté serveur avec agrégations SQL `avg()`, `count()`, `max()` et gestion de cache à 2 niveaux.

**IMPORTANT** : tous les appels API se font exclusivement côté serveur. Aucune clé API ni identifiant n'est exposé côté client.

---

## TAXES FIXES 2026

Valeurs centralisées dans `config/fuel.php` (mise à jour annuelle chaque 1er janvier selon la loi de finances).

| Carburant | Accise (€/litre) |
|---|---|
| SP95 / SP98 | 0.6829 |
| SP95-E10 | 0.6629 |
| Gazole | 0.6100 |
| E85 | 0.1186 |
| GPL | 0.1710 |
| TVA | 20 % sur (produit HT + accise) |

Sources : UFIP, FIPECO, Direction Générale des Douanes, loi de finances 2026.

---

## FORMULE DE CALCUL DU PRIX THÉORIQUE

```text
Étape 1 : Coût brut par litre
  = (cours Brent en USD / 159) × taux USD vers EUR
  (159 = nombre de litres dans un baril de pétrole)

Étape 2 : Ajouter marge de raffinage
  - Essences (SP95, SP98, E10) : +0.07 à +0.09 €/litre
  - Gazole (proxy HO=F)        : cotation directe incluant raffinage + prime ARA 0.06 €/litre
  - E85                        : formule hybride (15 % Brent + 85 % éthanol agricole à 0.42 €/L)
  - GPL                        : +0.04 €/litre

Étape 3 : Ajouter marge distribution normale
  +0.32 €/litre pour essences et gazole (inclus CEE et TIRUERT) ; +0.10 €/litre pour E85/GPL
  Sources : UFC-Que Choisir, CLCV, UFIP

Étape 4 : Ajouter accise fixe 2026 selon le carburant

Étape 5 : Appliquer TVA 20 % sur (sous-total HT + accise)
  prix_ttc = (cout_ht + accise) × 1.20

Étape 6 : Afficher une fourchette indicative de ±0.10 € autour du prix estimé
```

---

## AFFICHAGE PAR CARBURANT

- Prix théorique estimé avec fourchette indicative
- Décomposition en 4 lignes (Matière/Raffinage, Distribution, Accise, TVA)
- Prix moyen national déclaré (issu de l'Open Data officiel avec nombre de déclarations et date)
- Indicateurs généraux : cours Brent, taux USD vers EUR, horodatage des données
- Sources et avertissement citoyen explicatifs

---

## CACHE

- **Données marché** : cache 1 heure (`3600` secondes)
- **Prix moyens Open Data** :
  - Cache courant : 30 minutes (`1800` secondes)
  - Dernier résultat valide : 24 heures (`86400` secondes)
  - Temporisation d'échec : 1 minute (`60` secondes)

---

## STRUCTURE DES FICHIERS CLÉS

```text
app/Services/FuelPriceService.php         ← Logique marché, calculs théoriques, formatage
app/Services/ObservedFuelPriceService.php ← Appel API Open Data officielle DGCCRF, cache 2 niveaux
app/Http/Controllers/FuelController.php   ← Contrôleur principal
resources/views/fuel/index.blade.php      ← Vue principale SSR
resources/views/layouts/app.blade.php     ← Layout général
public/css/app.css                        ← Styles vanilla
config/fuel.php                           ← Paramètres métier et fiscaux
routes/web.php                            ← Route unique : GET /
tests/                                    ← Tests unitaires et d'intégration
```

---

## PUBLICITÉ

Emplacement publicitaire prévu dans `resources/views/layouts/app.blade.php`.
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
- Chaque bloc de calcul commenté avec sa source
- Aucune clé API exposée
- Code propre, testé, sans framework front lourd

---

## POINTS DE VIGILANCE

- **Marge de raffinage volatile** : les valeurs dans ce projet sont des estimations moyennes 2025-2026. En période de tension, la marge réelle peut fluctuer.
- **Écart pompe / théorique** : le prix constaté peut différer du repère théorique en raison des coûts réels locaux, de la logistique, des CEE et du TIRUERT. L'outil fournit un repère indicatif citoyen et n'accuse pas les distributeurs.
- **Sécurité et journaux** : aucune exception brute ni trace contenant des paramètres ou des clés d'API ne doit être journalisée.

---

## SOURCES DE RÉFÉRENCE

- UFIP Énergies et Mobilités : https://www.ufip.fr
- FIPECO taxes carburants : https://www.fipeco.fr
- Connaissance des Énergies : https://www.connaissancedesenergies.org
- CLCV marges distribution : https://www.clcv.org
- Frankfurter API (BCE) : https://www.frankfurter.app
- Prix des carburants en France : https://prix-carburants.gouv.fr
- Open Data Ministère de l'Économie : https://data.economie.gouv.fr

---

## ROADMAP

### V1 (actuelle)
- [x] Calcul du prix théorique pour SP95-E10, SP95, SP98, Gazole, E85, GPL
- [x] Appels API Brent (Yahoo Finance) + fallback Alpha Vantage
- [x] Taux USD vers EUR via Frankfurter API (BCE)
- [x] Prix moyens nationaux déclarés via l'API Open Data officielle DGCCRF (Licence Ouverte 2.0)
- [x] Cache fichier (1 h marché, 30 min / 24 h Open Data)
- [x] Vue Blade responsive, CSS vanilla, sans iframe tierce
- [x] Suite de tests automatisés (Unitaires et Feature)

### V2 (prévu)
- [ ] Comparaison détaillée prix théorique vs prix constaté par carburant
- [ ] Carte interactive des stations
- [ ] Historique sur 30 jours
- [ ] Activation de l'emplacement publicitaire

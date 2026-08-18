# Méthodologie de calcul et paramètres métier

Ce document détaille les principes économiques, les formules de calcul, les paramètres fiscaux et les hypothèses retenues par **Good Gasoil Price** pour estimer le prix théorique des carburants à la pompe en France.

---

## 1. Principe général du modèle

Good Gasoil Price calcule un **prix théorique indicatif** en reproduisant la chaîne de décomposition des coûts d'un carburant routier en France métropolitaine :

```text
Prix TTC estimé =
(Coût matière première HT + Marge de raffinage + Marge de transport/distribution + Accise fixe)
× (1 + Taux TVA)
```

Ce modèle s'appuie sur :
1. Les cotations internationales de marché (cours du pétrole brut Brent ou du distillat raffiné).
2. Le taux de change officiel quotidien USD vers EUR publié par la Banque Centrale Européenne (BCE).
3. Les niveaux d'accise fixés par la loi de finances française pour l'année 2026.
4. Des marges de référence moyennes de raffinage et de distribution issues d'organismes professionnels et d'associations de consommateurs (UFIP, CLCV, UFC-Que Choisir).

---

## 2. Décomposition des coûts

### 2.1 Matière première

* **Pétrole brut Brent** : Référence internationale pour les carburants raffinés en Europe. La cotation en dollars par baril (USD/baril) est convertie en euros par litre (€/L) sur la base de 1 baril = 159 litres :

```text
Coût brut (€/L) =
cours Brent (USD/baril) / 159
× taux USD→EUR
```

* **Distillat gazole (NY Harbor ULSD — `HO=F`)** : En l'absence de flux public ouvert en temps réel pour la cotation ARA Rotterdam (*ICE Low Sulphur Gasoil*), l'application utilise l'indicateur NY Harbor ULSD (`HO=F`, exprimé en USD/gallon) comme repère indicatif de marché. La conversion en euros par litre s'effectue sur la base de 1 gallon US = 3,785411784 litres :

```text
Coût matière (€/L) =
cours HO=F (USD/gallon) / 3,785411784
× taux USD→EUR
```

  Aucune prime fixe arbitraire n'y est ajoutée. Si cet indicateur est temporairement indisponible, le service bascule automatiquement sur un calcul basé sur le Brent avec marge de raffinage moyenne.

* **Éthanol agricole (E85)** : Pour son estimation, le modèle utilise une hypothèse simplifiée de 85 % de composante éthanol et 15 % de composante essence. Le coût matière combine :
  - 85 % d'éthanol à un coût de référence moyen fixé à **0,42 €/L** ;
  - 15 % de composante essence basée sur le cours du Brent.

### 2.2 Marge de raffinage

La marge de raffinage couvre la transformation industrielle du brut en carburant fini. Les valeurs moyennes de référence 2025-2026 intégrées sont :
* **SP95-E10** : +0,07 €/L
* **SP95** : +0,08 €/L
* **SP98** : +0,09 €/L
* **GPL** : +0,04 €/L
* **E85** : +0,03 €/L
* **Gazole** :
  * En mode nominal HO=F : aucune marge de raffinage supplémentaire, car le calcul part directement de l'indicateur de distillat raffiné ;
  * En mode de repli Brent : marge configurée de **+0,43 €/L**.

### 2.3 Marge de transport et de distribution

La marge brute de distribution couvre la logistique, le stockage, le transport vers les stations, les coûts d'exploitation des points de vente, ainsi que les obligations réglementaires associées (Certificats d'Économies d'Énergie - CEE et taxe TIRUERT) :
* **Essences et Gazole** : **0,32 €/L** (valeur issue des analyses UFIP et associations de consommateurs).
* **E85 et GPL** : **0,10 €/L** (paramétrage spécifique `config/fuel.php`).

---

## 3. Fiscalité fixe 2026

La fiscalité sur les carburants en France comprend deux volets :
1. **L'accise sur les énergies** (anciennement TICPE) : taxe fixe au volume (en euros par litre), arrêtée annuellement par la loi de finances.
2. **La TVA** : taux normal de **20 %**, s'appliquant sur le sous-total hors taxes **majoré du montant de l'accise** (taxe sur la taxe).

### Tableau des accises 2026

| Carburant | Accise (€/litre) | Source réglementaire |
|---|---:|---|
| **SP95** | 0,6829 €/L | Direction Générale des Douanes / Loi de finances 2026 |
| **SP98** | 0,6829 €/L | Direction Générale des Douanes / Loi de finances 2026 |
| **SP95-E10** | 0,6629 €/L | Direction Générale des Douanes / Loi de finances 2026 |
| **Gazole** | 0,6100 €/L | Direction Générale des Douanes / Loi de finances 2026 |
| **E85** | 0,1186 €/L | Direction Générale des Douanes / Loi de finances 2026 |
| **GPL** | 0,1710 €/L | Direction Générale des Douanes / Loi de finances 2026 |

---

## 4. Formules détaillées par carburant

### 4.1 Essences (SP95-E10, SP95, SP98)

```text
Prix HT =
(Brent en USD/baril / 159 × taux USD→EUR)
+ marge de raffinage
+ 0,32 €/L

Prix TTC =
(Prix HT + accise) × 1,20
```

### 4.2 Gazole

* **Mode nominal (Indicateur NY Harbor ULSD `HO=F`)** :

```text
Prix HT =
(HO=F en USD/gallon / 3,785411784 × taux USD→EUR)
+ 0,32 €/L

Prix TTC =
(Prix HT + 0,6100 €/L) × 1,20
```

* **Mode de repli (Brent si `HO=F` indisponible)** :

```text
Prix HT =
(Brent en USD/baril / 159 × taux USD→EUR)
+ 0,43 €/L
+ 0,32 €/L

Prix TTC =
(Prix HT + 0,6100 €/L) × 1,20
```

### 4.3 Superéthanol E85

```text
Coût matière =
(0,15 × Brent en USD/baril / 159 × taux USD→EUR)
+ (0,85 × 0,42 €/L)

Prix HT =
Coût matière
+ 0,03 €/L
+ 0,10 €/L

Prix TTC =
(Prix HT + 0,1186 €/L) × 1,20
```

### 4.4 Gaz de Pétrole Liquéfié (GPL)

```text
Prix HT =
(Brent en USD/baril / 159 × taux USD→EUR)
+ 0,04 €/L
+ 0,10 €/L

Prix TTC =
(Prix HT + 0,1710 €/L) × 1,20
```

---

## 5. Fourchette indicative et étalon de validation

### 5.1 Fourchette indicative affichée

Afin de refléter les variations normales du marché de détail, l'application présente une **fourchette indicative de ±0,10 €/L** autour du prix estimé :

```text
Borne basse = prix estimé − 0,10 €/L
Borne haute = prix estimé + 0,10 €/L
```

### 5.2 Étalon de comparaison officiel DGEC

À titre de repère de comparaison méthodologique indépendant, la publication hebdomadaire du Ministère de la Transition écologique (DGEC) du 7 août 2026 pour le gazole établit la décomposition suivante :
* Cotation internationale gazole : `0,880 €/L`
* Transport et distribution : `0,320 €/L`
* Accise : `0,610 €/L`
* TVA (20 %) : `0,362 €/L`
* **Total TTC calculé** : `2,172 €/L` (fourchette indicative associée : `2,072 €/L` à `2,272 €/L`).

*Note : Cette publication sert d'étalon documentaire et ne constitue pas un flux d'ingestion dynamique.*

---

## 6. Limites méthodologiques et avertissement

* **Caractère indicatif** : Les prix calculés constituent des repères théoriques moyens à l'échelle nationale.
* **Non-prise en compte des disparités locales** : Le modèle n'intègre pas les spécificités de chaque station (éloignement des dépôts pétroliers, statut autoroutier, concurrence locale, politiques promotionnelles à prix coûtant).
* **Volatilité des spreads** : Les écarts réels entre brut et raffiné peuvent fluctuer rapidement en période de tension géopolitique ou d'arrêt technique de raffineries.
* **Absence d'accusation** : Un écart entre le prix constaté à la pompe et l'estimation théorique ne caractérise pas en soi une anomalie commerciale.

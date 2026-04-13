<?php

/**
 * Configuration centralisée des paramètres de calcul du prix théorique des carburants.
 *
 * MISE À JOUR : chaque 1er janvier selon la loi de finances annuelle.
 * Sources fiscales : UFIP Energies et Mobilités, FIPECO, Direction Générale des Douanes.
 * Source marges distribution : CLCV (Consommation Logement Cadre de Vie), mars 2026.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Accises fixes par carburant (en euros par litre) — Loi de finances 2026
    |--------------------------------------------------------------------------
    | Source : UFIP / FIPECO / DGDDI
    | Appelées aussi TICPE (Taxe Intérieure de Consommation sur les Produits Énergétiques).
    | Ces valeurs sont fixées par la loi et restent stables sur l'année civile.
    */
    'accises' => [
        'sp95_e10' => 0.6629,   // SP95-E10 — accise réduite car 10% éthanol, Source UFIP mars 2026
        'sp95'     => 0.6829,   // SP95 (E5) — sans éthanol, même taux que SP98, Source UFIP mars 2026
        'sp98'     => 0.6829,   // SP98 (E5) — Source UFIP mars 2026
        'gazole'   => 0.6100,   // Gazole B7 — Source : Que Choisir 10/04/2026 et UFIP mars 2026
        'e85'      => 0.1186,   // E85 (Superéthanol) — Source UFIP mars 2026
        'gpl'      => 0.1710,   // GPL (Gaz de Pétrole Liquéfié) — Source UFIP 2026
    ],

    /*
    |--------------------------------------------------------------------------
    | TVA applicable sur (produit HT + accise)
    |--------------------------------------------------------------------------
    | La TVA est calculée sur la base TTC du produit pré-taxé + accise.
    | Formule : prix_ttc = (cout_ht + accise) * (1 + tva)
    */
    'tva' => 0.20, // 20% — TVA taux normal, stable depuis 2014

    /*
    |--------------------------------------------------------------------------
    | Marges de raffinage moyennes par type de carburant (en euros par litre)
    |--------------------------------------------------------------------------
    | Source : estimations moyennes 2025-2026, volatiles en période de crise.
    | ATTENTION : ces valeurs peuvent varier de ±50% en période de forte tension.
    | À réévaluer si le spread de raffinage dépasse significativement ces moyennes.
    | Source Gazole mars 2026 : CLCV (marges raffinage gazole élevées en période de tension).
    */
    'marges_raffinage' => [
        'sp95_e10' => 0.07,   // SP95-E10 — marge raffinage moyenne 2025-2026
        'sp95'     => 0.08,   // SP95     — légèrement supérieure (pas d'éthanol en charge)
        'sp98'     => 0.09,   // SP98     — premium indice octane 98, Source UFIP/IFPen 2025-2026
        'gazole'   => 0.43,   // Gazole   — coté Rotterdam + import France, cible ~2.30 €/L (CLCV mars 2026 : marge brute 32.9 ct/L)
        'e85'      => 0.03,   // E85      — marge très réduite (majoritairement éthanol agricole)
        'gpl'      => 0.04,   // GPL      — marge réduite (sous-produit raffinage)
    ],

    /*
    |--------------------------------------------------------------------------
    | Marges de distribution par carburant (en euros par litre)
    |--------------------------------------------------------------------------
    | Sources : UFC-Que Choisir 10/04/2026, UFIP / Connaissance des Energies mars 2026.
    | Couvre : transport jusqu'au dépôt, stockage, transfert station, exploitation station,
    |          CEE (Certificats d'Économie d'Énergie, jusqu'à 0.15 €/L en 2026, Source Que Choisir)
    |          et TIRUERT (Taxe Incitative relative aux Energies Renouvelables dans les Transports).
    | NOTE : E85 et GPL ont une chaîne logistique plus simple (production nationale, citerne)
    |        et des CEE réduits — leur marge de distribution est donc inférieure aux essences.
    */
    'marges_distribution' => [
        'sp95_e10' => 0.32,   // Essences — marge pleine : transport, CEE, TIRUERT inclus
        'sp95'     => 0.32,   // Essences — idem SP95-E10
        'sp98'     => 0.32,   // Essences — idem SP95-E10
        'gazole'   => 0.32,   // Gazole   — marge pleine (CEE et TIRUERT élevés)
        'e85'      => 0.10,   // E85      — production nationale, CEE réduits, logistique simplifiée
        'gpl'      => 0.10,   // GPL      — stockage citerne, logistique simplifiée, pas de cotation Rotterdam
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombre de litres dans un baril de pétrole brut
    |--------------------------------------------------------------------------
    | Valeur physique constante. 1 baril = 158.987 litres (≈ 159).
    */
    'litres_par_baril' => 159,

    /*
    |--------------------------------------------------------------------------
    | Paramètres spécifiques au Gazole (proxy NYMEX Heating Oil HO=F)
    |--------------------------------------------------------------------------
    | ICE Low Sulphur Gasoil Futures (LSG=F) n'étant pas disponible sur
    | Yahoo Finance en accès gratuit, on utilise le Heating Oil NYMEX (HO=F)
    | comme proxy. Il s'agit du distillat ULSD (Ultra Low Sulfur Diesel) coté
    | à New York, fortement corrélé au gasoil ARA Rotterdam.
    |
    | HO=F est coté en USD par gallon US (1 gallon = 3.78541 litres).
    | La cotation inclut le raffinage : aucune marge raffinage ajoutée.
    | Source : NYMEX / CME Group via Yahoo Finance.
    */
    'gasoil_density'           => 0.845,    // kg/litre — constante physique gazole B7 (IFPen, référence)
    'gasoil_litres_per_gallon' => 3.78541,  // litres par gallon US — constante de conversion NYMEX
    'gasoil_yahoo_ticker'      => 'HO=F',   // Heating Oil NYMEX (ULSD) — proxy gasoil ARA Rotterdam
    'gasoil_ara_premium'       => 0.06,     // prime ARA en €/L : spread structurel US→Europe (géographie, stocks, TIRUERT)

    /*
    |--------------------------------------------------------------------------
    | Coût de l'éthanol agricole européen (en euros par litre) — E85 uniquement
    |--------------------------------------------------------------------------
    | L'E85 contient 65 à 85% d'éthanol produit à partir de blé et de betterave.
    | Ce coût est découplé du cours du pétrole brut Brent.
    | Source : cours européen éthanol blé/betterave, Connaissance des Energies 2026.
    | Valeur à réviser si les cours agricoles européens bougent de ±10%.
    */
    'ethanol_cost_per_liter' => 0.42,

    /*
    |--------------------------------------------------------------------------
    | Fourchette d'incertitude affichée autour du prix calculé (en euros)
    |--------------------------------------------------------------------------
    | Le prix théorique est un repère, pas une valeur exacte.
    | ±0.10 € couvre les variations légitimes (logistique locale, CEE, TIRUERT…).
    */
    'fourchette' => 0.10,

    /*
    |--------------------------------------------------------------------------
    | Durée de mise en cache des données marché (en secondes)
    |--------------------------------------------------------------------------
    | Brent et EUR/USD sont rafraîchis toutes les 60 minutes.
    | Suffisant pour un outil informatif non temps réel.
    */
    'cache_ttl' => 3600, // 1 heure

    /*
    |--------------------------------------------------------------------------
    | Définition des carburants affichés et leurs métadonnées
    |--------------------------------------------------------------------------
    */
    'carburants' => [
        'sp95_e10' => [
            'nom'         => 'SP95-E10',
            'description' => 'Sans-plomb 95, contient jusqu\'à 10% d\'éthanol',
            'couleur'     => '#27ae60', // vert
        ],
        'sp95' => [
            'nom'         => 'SP95',
            'description' => 'Sans-plomb 95, sans éthanol (E5 max), indice octane 95',
            'couleur'     => '#16a085', // vert foncé
        ],
        'sp98' => [
            'nom'         => 'SP98',
            'description' => 'Sans-plomb 98, indice d\'octane élevé',
            'couleur'     => '#2980b9', // bleu
        ],
        'gazole' => [
            'nom'         => 'Gazole',
            'description' => 'Diesel, carburant des moteurs à compression',
            'couleur'     => '#f39c12', // orange
        ],
        'e85' => [
            'nom'         => 'E85',
            'description' => 'Superéthanol, contient 65 à 85% d\'éthanol',
            'couleur'     => '#8e44ad', // violet
        ],
        'gpl' => [
            'nom'         => 'GPL',
            'description' => 'Gaz de Pétrole Liquéfié (LPG)',
            'couleur'     => '#e74c3c', // rouge
        ],
    ],

];

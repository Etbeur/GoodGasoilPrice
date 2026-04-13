@extends('layouts.app')

@section('title', 'Prix carburant théorique France — ' . date('d/m/Y'))

@section('content')

    {{-- =====================================================================
         Bandeau données marché
         Affiche le cours Brent et le taux EUR/USD utilisés pour le calcul
    ===================================================================== --}}
    @if(!$erreur)
    <div class="donnees-marche">
        <div class="indicateur">
            <span class="label">Pétrole Brent</span>
            <span class="valeur">{{ number_format($brentUsd, 2) }} <small class="unite-mesure">USD/baril</small></span>
        </div>
        <div class="indicateur">
            <span class="label">Taux EUR/USD</span>
            <span class="valeur">{{ number_format($eurUsd, 4) }} <small class="unite-mesure">€ pour 1 $</small></span>
        </div>
        <div class="mise-a-jour">
            Dernière mise à jour<br>
            <strong>{{ $miseAJour }}</strong>
        </div>
    </div>
    @endif

    {{-- =====================================================================
         Message d'erreur si les données marché sont indisponibles
    ===================================================================== --}}
    @if($erreur)
    <div class="alerte-erreur" role="alert">
        <strong>Données indisponibles</strong><br>
        {{ $erreur }}
    </div>
    @endif

    {{-- =====================================================================
         Grille des carburants
    ===================================================================== --}}
    @if(!$erreur && count($carburants) > 0)

    {{-- Correspondance clé interne → paramètre fuel du widget prix-carburant.eu --}}
    @php
    $widgetFuels = [
        'sp95_e10' => 'E10',
        'sp95'     => 'SP95',
        'sp98'     => 'SP98',
        'gazole'   => 'Gazole',
        'e85'      => 'E85',
        'gpl'      => 'GPLc',
    ];
    @endphp

    <div class="grille-carburants">

        @foreach($carburants as $carburant)
        <article class="carte-carburant">

            {{-- En-tête de la carte --}}
            <div class="carte-entete" style="border-bottom-color: {{ $carburant['couleur'] }};">
                <div class="carte-badge" style="background-color: {{ $carburant['couleur'] }};"></div>
                <div>
                    <div class="carte-nom">{{ $carburant['nom'] }}</div>
                    <div class="carte-description">{{ $carburant['description'] }}</div>
                </div>
            </div>

            {{-- Corps de la carte --}}
            <div class="carte-corps">

                {{-- Prix principal avec fourchette --}}
                <div class="prix-principal">
                    <div class="label">Prix théorique estimé</div>
                    <div class="valeur">
                        {{ number_format($carburant['prix_ttc'], 3, ',', '') }}
                        <span class="unite">&nbsp;€/L</span>
                    </div>
                    <div class="prix-fourchette">
                        Fourchette honnête :
                        {{ number_format($carburant['prix_min'], 3, ',', '') }}&nbsp;€
                        &ndash;
                        {{ number_format($carburant['prix_max'], 3, ',', '') }}&nbsp;€
                    </div>
                </div>

                {{-- Décomposition du prix en 4 lignes --}}
                <div class="decomposition">
                    <div class="titre">Décomposition du prix</div>
                    <table>
                        <tbody>
                            <tr>
                                <td>{{ $carburant['label_matiere'] }}</td>
                                <td>{{ number_format($carburant['detail']['brut_raffinage'], 4, ',', '') }}&nbsp;€</td>
                            </tr>
                            <tr>
                                <td>Distribution</td>
                                <td>{{ number_format($carburant['detail']['distribution'], 4, ',', '') }}&nbsp;€</td>
                            </tr>
                            <tr>
                                <td>Accise (TICPE)</td>
                                <td>{{ number_format($carburant['detail']['accise'], 4, ',', '') }}&nbsp;€</td>
                            </tr>
                            <tr>
                                <td>TVA 20&nbsp;%</td>
                                <td>{{ number_format($carburant['detail']['tva'], 4, ',', '') }}&nbsp;€</td>
                            </tr>
                            <tr class="total">
                                <td>Total TTC</td>
                                <td>{{ number_format($carburant['prix_ttc'], 3, ',', '') }}&nbsp;€</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>{{-- fin .carte-corps --}}

            {{-- ---------------------------------------------------------------
                 Note source ICE — Gazole uniquement
            --------------------------------------------------------------- --}}
            @if($carburant['cle'] === 'gazole' && $carburant['lsg_usd_tonne'] !== null)
            <div class="note-source-ice">
                Prix calculé à partir du Heating Oil NYMEX (HO=F)
                avec prime ARA +0,06&nbsp;€/L pour refléter le spread
                structurel entre le marché US et le marché européen ARA Rotterdam.
                @if(!empty($gasoilRotterdamUsd))
                <span class="note-lsg-valeur">HO=F&nbsp;: {{ number_format($gasoilRotterdamUsd, 4, ',', '&nbsp;') }}&nbsp;$/gallon</span>
                @endif
            </div>
            @endif

            {{-- ---------------------------------------------------------------
                 Widget prix moyen constaté à la pompe
                 Source : prix-carburants.gouv.fr via prix-carburant.eu
                 Clairement séparé du prix théorique calculé ci-dessus
            --------------------------------------------------------------- --}}
            @if(isset($widgetFuels[$carburant['cle']]))
            <div class="widget-constate">
                <div class="widget-constate-titre">Prix moyen constaté à la pompe</div>
                <iframe
                    src="https://prix-carburant.eu/embed/prix-moyen-national.php?fuel={{ $widgetFuels[$carburant['cle']] }}&compact=1&show_date=1&show_title=0"
                    height="180"
                    frameborder="0"
                    scrolling="no"
                    loading="lazy"
                    title="Prix moyen constaté {{ $carburant['nom'] }} — prix-carburants.gouv.fr"
                ></iframe>
                <div class="widget-source">Prix constaté source&nbsp;: prix-carburants.gouv.fr</div>
            </div>
            @endif

        </article>
        @endforeach

    </div>{{-- fin .grille-carburants --}}
    @endif

    {{-- =====================================================================
         Bloc méthodologie et avertissement
    ===================================================================== --}}
    <section class="methodologie">
        <h2>Comment ce prix est-il calculé ?</h2>
        <ul>
            <li>
                <strong>Coût matière :</strong>
                (Cours Brent en USD ÷ 159&nbsp;litres) × taux EUR/USD
            </li>
            <li>
                <strong>Marge de raffinage :</strong>
                entre +0,03&nbsp;€/L (E85) et +0,18&nbsp;€/L (Gazole, coté Rotterdam) — estimations 2025-2026
            </li>
            <li>
                <strong>Marge de distribution :</strong>
                +0,32&nbsp;€/L — transport, stockage, station, CEE et TIRUERT
                (sources&nbsp;: UFC-Que Choisir 10/04/2026, UFIP mars&nbsp;2026)
            </li>
            <li>
                <strong>Accise (TICPE) :</strong>
                taxe fixe définie par la loi de finances 2026 — ex. 0,6829&nbsp;€/L pour SP95/SP98,
                0,6629&nbsp;€/L pour SP95-E10 (réduite car 10&nbsp;% éthanol), 0,6100&nbsp;€/L pour Gazole
            </li>
            <li>
                <strong>TVA 20&nbsp;%</strong> appliquée sur (HT + accise)
            </li>
        </ul>

        {{-- Note citoyenne : pourquoi l'écart pompe/théorique est normal --}}
        <div class="note-citoyenne" role="note">
            <p>
                <strong>Pourquoi le prix à la pompe peut différer du prix théorique&nbsp;?</strong>
            </p>
            <p>
                Ce calculateur donne un <strong>repère citoyen</strong> basé sur les données de marché
                en temps réel. Il ne prétend pas reproduire exactement le prix constaté à la pompe,
                pour plusieurs raisons légitimes&nbsp;:
            </p>
            <ul>
                <li>
                    <strong>Marges de raffinage variables :</strong> les spreads de raffinage fluctuent
                    quotidiennement selon la demande mondiale. En période de tension (hiver, crises
                    géopolitiques), ils peuvent doubler ou tripler la valeur moyenne utilisée ici.
                </li>
                <li>
                    <strong>CEE et TIRUERT :</strong> les Certificats d'Économie d'Énergie et la
                    Taxe Incitative aux Energies Renouvelables dans les Transports représentent
                    jusqu'à 0,15&nbsp;€/L et sont intégrés dans notre marge de distribution.
                    Leur poids exact varie d'un distributeur à l'autre.
                </li>
                <li>
                    <strong>Gazole :</strong> la France importe une part significative de son gazole,
                    coté séparément à Rotterdam. Les coûts d'importation et les obligations
                    d'incorporation de biocarburants (TIRUERT) expliquent un écart structurel
                    plus important qu'pour les essences.
                </li>
                <li>
                    <strong>E85 et GPL :</strong> ces carburants sont largement découplés du pétrole
                    brut. L'E85 est composé à 65–85&nbsp;% d'éthanol agricole (filière betterave/blé)
                    et le GPL provient majoritairement du gaz naturel. La formule Brent n'est pas
                    adaptée à ces filières — les prix affichés pour E85 et GPL sont indicatifs
                    et doivent être interprétés avec prudence.
                </li>
            </ul>
            <p>
                <em>Cet outil donne un repère basé sur les données publiques —
                il n'accuse pas les distributeurs.</em>
            </p>
        </div>

        {{-- Sources de données --}}
        @if(!empty($sources))
        <div class="sources">
            <h3>Sources utilisées</h3>
            <ul>
                @foreach($sources as $label => $source)
                <li><strong>{{ $label }} :</strong> {{ $source }}</li>
                @endforeach
            </ul>
        </div>
        @endif
    </section>

@endsection

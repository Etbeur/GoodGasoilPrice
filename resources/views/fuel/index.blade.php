@extends('layouts.app')

@section('title', 'Quel pourrait être le prix du carburant aujourd’hui ? — ' . date('d/m/Y'))

@section('content')

    {{-- =====================================================================
         Bandeau données marché
         Affiche le cours Brent et le taux de change USD vers EUR
    ===================================================================== --}}
    @if(!$erreur)
    <div class="donnees-marche">
        <div class="indicateur">
            <span class="label">Pétrole Brent</span>
            <span class="valeur">{{ number_format($brentUsd, 2) }} <small class="unite-mesure">USD/baril</small></span>
        </div>
        <div class="indicateur">
            <span class="label">Taux de change USD vers EUR</span>
            <span class="valeur">1 USD = {{ number_format($usdEur, 4, ',', ' ') }} <small class="unite-mesure">EUR</small></span>
        </div>
        <div class="mise-a-jour">
            Dernières données disponibles<br>
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

    <div class="grille-carburants">

        @foreach($carburants as $carburant)
        @php
            $observe = $prixObserves['carburants'][$carburant['cle']] ?? null;
            $observeDisponible = $observe !== null && !empty($observe['disponible']) && $observe['prix_moyen'] !== null;
        @endphp
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
                        Fourchette indicative :
                        {{ number_format($carburant['prix_min'], 3, ',', '') }}&nbsp;€
                        &ndash;
                        {{ number_format($carburant['prix_max'], 3, ',', '') }}&nbsp;€
                    </div>
                </div>

                {{-- Décomposition du prix en 4 lignes --}}
                <div class="decomposition">
                    <div class="titre">Décomposition du prix estimé</div>
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
                                <td>Total TTC estimé</td>
                                <td>{{ number_format($carburant['prix_ttc'], 3, ',', '') }}&nbsp;€</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>{{-- fin .carte-corps --}}

            {{-- ---------------------------------------------------------------
                 Note source NYMEX — Gazole uniquement
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
                 Prix moyen national déclaré (Open Data officiel DGCCRF)
                 Récupéré côté serveur, sans iframe ni widget tiers
            --------------------------------------------------------------- --}}
            <div class="prix-moyen-national {{ !$observeDisponible ? 'prix-moyen-indisponible' : '' }}">
                <div class="prix-moyen-titre">Prix moyen national déclaré</div>
                @if($observeDisponible)
                    <div class="prix-moyen-valeur">
                        {{ number_format($observe['prix_moyen'], 3, ',', ' ') }}&nbsp;<span class="unite">€/L</span>
                    </div>
                    <div class="prix-moyen-meta">
                        {{ number_format($observe['nb_declarations'], 0, ',', ' ') }} déclarations prises en compte
                        @if(!empty($observe['derniere_maj']))
                            &middot; Dernière actualisation : {{ $observe['derniere_maj'] }}
                        @endif
                    </div>
                    @if(!empty($prixObserves['is_fallback']) && !empty($prixObserves['recupere_le']))
                        <div class="prix-moyen-fallback">
                            Dernières données officielles disponibles, récupérées le {{ $prixObserves['recupere_le'] }}
                        </div>
                    @endif
                @else
                    <p class="prix-moyen-alerte">Prix moyen national momentanément indisponible.</p>
                @endif
                <div class="prix-moyen-source">
                    Source : {{ $prixObserves['source'] ?? 'DGCCRF – prix-carburants.gouv.fr' }} &middot; {{ $prixObserves['licence'] ?? 'Licence Ouverte 2.0' }}
                </div>
            </div>

        </article>
        @endforeach

    </div>{{-- fin .grille-carburants --}}
    @endif

    {{-- =====================================================================
         Bloc méthodologie et avertissement
    ===================================================================== --}}
    <section class="methodologie">
        <h2>Comment ces prix sont-ils calculés et observés&nbsp;?</h2>
        <ul>
            <li>
                <strong>Coût matière :</strong>
                (Cours Brent en USD ÷ 159&nbsp;litres) × taux USD vers EUR
            </li>
            <li>
                <strong>Marge de raffinage :</strong>
                entre +0,03&nbsp;€/L (E85) et +0,09&nbsp;€/L (SP98) — estimations moyennes 2025-2026 (UFIP / IFPen)
            </li>
            <li>
                <strong>Marge de distribution :</strong>
                +0,32&nbsp;€/L pour les essences et le gazole — transport, stockage, station, CEE et TIRUERT
                (sources&nbsp;: UFC-Que Choisir 10/04/2026, CLCV mars&nbsp;2026, UFIP mars&nbsp;2026)
            </li>
            <li>
                <strong>Accise (TICPE) :</strong>
                taxe fixe définie par la loi de finances 2026 — ex. 0,6829&nbsp;€/L pour SP95/SP98,
                0,6629&nbsp;€/L pour SP95-E10 (réduite car 10&nbsp;% éthanol), 0,6100&nbsp;€/L pour Gazole
            </li>
            <li>
                <strong>TVA 20&nbsp;%</strong> appliquée sur (HT + accise)
            </li>
            <li>
                <strong>Prix moyen national :</strong>
                Moyenne arithmétique non pondérée des prix actuellement présents dans le flux officiel. Les stations distribuant moins de 500 m³ de carburants par an ne sont pas toutes soumises à l’obligation de déclaration.
            </li>
        </ul>

        {{-- Note citoyenne : pourquoi l'écart pompe/théorique est normal --}}
        <div class="note-citoyenne" role="note">
            <p>
                <strong>Pourquoi le prix à la pompe peut différer du prix théorique&nbsp;?</strong>
            </p>
            <p>
                Ce calculateur donne un <strong>repère citoyen</strong> basé sur les dernières données de marché disponibles. Il ne prétend pas reproduire exactement le prix constaté à la pompe, pour plusieurs raisons légitimes&nbsp;:
            </p>
            <ul>
                <li>
                    <strong>Marges de raffinage variables :</strong> les spreads de raffinage fluctuent selon la demande mondiale. En période de tension (hiver, crises géopolitiques), ils peuvent s'éloigner des valeurs moyennes utilisées ici.
                </li>
                <li>
                    <strong>CEE et TIRUERT :</strong> les Certificats d'Économie d'Énergie et la Taxe Incitative aux Energies Renouvelables dans les Transports représentent jusqu'à 0,15&nbsp;€/L et sont intégrés dans notre marge de distribution. Leur poids exact varie d'un distributeur à l'autre.
                </li>
                <li>
                    <strong>Gazole :</strong> la France importe une part significative de son gazole, coté séparément à Rotterdam. Les coûts d'importation et les obligations d'incorporation de biocarburants expliquent un écart structurel plus important que pour les essences.
                </li>
                <li>
                    <strong>E85 et GPL :</strong> ces carburants sont largement découplés du pétrole brut. L'E85 est composé à 65–85&nbsp;% d'éthanol agricole (filière betterave/blé) et le GPL provient majoritairement du gaz naturel. La formule Brent n'est pas adaptée à ces filières — les prix affichés pour E85 et GPL sont indicatifs et doivent être interprétés avec prudence.
                </li>
            </ul>
            <p>
                <em>Cet outil donne un repère basé sur les données publiques — il n'accuse pas les distributeurs.</em>
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
                <li><strong>Prix moyens observés :</strong> DGCCRF &middot; prix-carburants.gouv.fr (Open Data Ministère de l'Économie, Licence Ouverte 2.0)</li>
            </ul>
        </div>
        @endif
    </section>

@endsection

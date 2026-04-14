<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Calculez le prix théorique juste du carburant à la pompe en France, basé sur le cours du pétrole Brent et le taux EUR/USD en temps réel.">
    <meta name="keywords" content="prix carburant france, prix essence théorique, brent, gazole, sp95, e85, calcul prix pompe">
    <meta name="robots" content="index, follow">

    <!-- Open Graph pour partage réseaux sociaux -->
    <meta property="og:title" content="Quel devrait être le prix du carburant aujourd'hui en France ?">
    <meta property="og:description" content="Prix théorique calculé à partir du cours du Brent et du taux EUR/USD en temps réel.">
    <meta property="og:type" content="website">

    <title>@yield('title', 'Prix carburant théorique France — ' . date('d/m/Y'))</title>

    {{-- =====================================================================
         EMPLACEMENT GOOGLE ADSENSE - À ACTIVER EN V2
         ======================================================================
         Intégrer ici le script Google AdSense externe en V2 avec votre
         identifiant ca-pub-XXXXXXXXXXXXXXXX.
    ====================================================================== --}}
    <link rel="stylesheet" href="{{ secure_asset('css/app.css') }}">
</head>
<body>

    {{-- En-tête --}}
    <header class="entete">
        <h1>Quel devrait être le prix du carburant aujourd'hui&nbsp;?</h1>
        <p class="sous-titre">
            Prix théorique calculé à partir du cours du pétrole Brent et du taux EUR/USD
            en temps réel. Un repère citoyen, pas une accusation envers les distributeurs.
        </p>
    </header>

    {{-- Emplacement publicité haute (AdSense V2) --}}
    <div class="pub-bandeau-haut" aria-hidden="true">
        {{-- Bannière AdSense responsive — À ACTIVER EN V2 --}}
    </div>

    {{-- Contenu principal --}}
    <main class="contenu">
        @yield('content')
    </main>

    {{-- Emplacement publicité basse (AdSense V2) --}}
    <div class="pub-bandeau-bas" aria-hidden="true">
        {{-- Bannière AdSense responsive — À ACTIVER EN V2 --}}
    </div>

    {{-- Pied de page --}}
    <footer class="pied-de-page">
        <p>
            <p>
                Code source disponible sur 
                <a href="https://github.com/Etbeur/GoodGasoilPrice" 
                target="_blank" rel="noopener">GitHub</a>
                &middot; Projet indépendant.
            </p>
            Données actualisées toutes les 15 minutes.
            Ce site est un outil informatif indépendant, sans lien avec les compagnies pétrolières.
        </p>
        <p style="margin-top: 0.5rem;">
            Sources : UFIP &middot; FIPECO &middot; UFC-Que Choisir &middot; Connaissance des Energies &middot; Frankfurter API (BCE) &middot; Yahoo Finance
        </p>
    </footer>

</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Calculez le prix thÃ©orique juste du carburant Ã  la pompe en France, basÃ© sur le cours du pÃ©trole Brent et le taux EUR/USD en temps rÃ©el.">
    <meta name="keywords" content="prix carburant france, prix essence thÃ©orique, brent, gazole, sp95, e85, calcul prix pompe">
    <meta name="robots" content="index, follow">

    <!-- Open Graph pour partage rÃ©seaux sociaux -->
    <meta property="og:title" content="Quel devrait Ãªtre le prix du carburant aujourd'hui en France ?">
    <meta property="og:description" content="Prix thÃ©orique calculÃ© Ã  partir du cours du Brent et du taux EUR/USD en temps rÃ©el.">
    <meta property="og:type" content="website">

    <title>@yield('title', 'Prix carburant thÃ©orique France â€” ' . date('d/m/Y'))</title>

    {{-- =====================================================================
         EMPLACEMENT GOOGLE ADSENSE - A ACTIVER EN V2
         ======================================================================
         Integrer ici le script Google AdSense externe en V2 avec votre
         identifiant ca-pub-XXXXXXXXXXXXXXXX.
    ====================================================================== --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>

    {{-- En-tÃªte --}}
    <header class="entete">
        <h1>Quel devrait Ãªtre le prix du carburant aujourd'hui&nbsp;?</h1>
        <p class="sous-titre">
            Prix thÃ©orique calculÃ© Ã  partir du cours du pÃ©trole Brent et du taux EUR/USD
            en temps rÃ©el. Un repÃ¨re citoyen, pas une accusation envers les distributeurs.
        </p>
    </header>

    {{-- Emplacement publicitÃ© haute (AdSense V2) --}}
    <div class="pub-bandeau-haut" aria-hidden="true">
        {{-- BanniÃ¨re AdSense responsive â€” Ã€ ACTIVER EN V2 --}}
    </div>

    {{-- Contenu principal --}}
    <main class="contenu">
        @yield('content')
    </main>

    {{-- Emplacement publicitÃ© basse (AdSense V2) --}}
    <div class="pub-bandeau-bas" aria-hidden="true">
        {{-- BanniÃ¨re AdSense responsive â€” Ã€ ACTIVER EN V2 --}}
    </div>

    {{-- Pied de page --}}
    <footer class="pied-de-page">
        <p>
            DonnÃ©es actualisÃ©es toutes les 15 minutes.
            Ce site est un outil informatif indÃ©pendant, sans lien avec les compagnies pÃ©troliÃ¨res.
        </p>
        <p style="margin-top: 0.5rem;">
            Sources : UFIP &middot; FIPECO &middot; UFC-Que Choisir &middot; Connaissance des Energies &middot; Frankfurter API (BCE) &middot; Yahoo Finance
        </p>
    </footer>

</body>
</html>


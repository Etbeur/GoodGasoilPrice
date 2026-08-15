<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Estimation indicative du prix du carburant en France basée sur le cours du pétrole Brent, le taux de change USD vers EUR et les données officielles des prix constatés.">
    <meta name="keywords" content="prix carburant france, prix essence théorique, brent, gazole, sp95, e85, calcul prix pompe, open data carburants">
    <meta name="robots" content="index, follow">

    <!-- Open Graph pour partage réseaux sociaux -->
    <meta property="og:title" content="Quel pourrait être le prix du carburant aujourd’hui ?">
    <meta property="og:description" content="Estimation indicative calculée à partir des dernières données disponibles pour le pétrole Brent et le taux de change USD vers EUR.">
    <meta property="og:type" content="website">

    <title>@yield('title', 'Quel pourrait être le prix du carburant aujourd’hui ? — ' . date('d/m/Y'))</title>

    {{-- =====================================================================
         EMPLACEMENT GOOGLE ADSENSE - À ACTIVER EN V2
         ======================================================================
         Intégrer ici le script Google AdSense externe en V2 avec votre
         identifiant ca-pub-XXXXXXXXXXXXXXXX.
    ====================================================================== --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>

    {{-- En-tête --}}
    <header class="entete">
        <h1>Quel pourrait être le prix du carburant aujourd’hui&nbsp;?</h1>
        <p class="sous-titre">
            Estimation indicative calculée à partir des dernières données disponibles pour le pétrole Brent et le taux de change du dollar vers l’euro. Ce résultat constitue un repère et non un prix garanti.
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
            Code source disponible sur
            <a href="https://github.com/Etbeur/GoodGasoilPrice"
               target="_blank" rel="noopener">GitHub</a>
            &middot; Projet indépendant.
        </p>
        <p style="margin-top: 0.5rem;">
            Données de marché mises en cache (1 heure) &middot; Flux officiel Open Data actualisé toutes les 10 minutes.
        </p>
        <p style="margin-top: 0.5rem;">
            Sources : UFIP &middot; FIPECO &middot; CLCV &middot; Direction Générale des Douanes &middot; DGCCRF (prix-carburants.gouv.fr) &middot; Frankfurter API (BCE) &middot; Yahoo Finance
        </p>
    </footer>

</body>
</html>

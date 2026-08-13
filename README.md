# bibkort
Første selvstændige version af **Bo i Bækmarksbro**: et analyseværktøj, der viser realistiske køretidsområder og større arbejdsmarkedsbyer fra Bækmarksbro og tre sammenligningspunkter.

Appen er bygget til et almindeligt PHP-webhotel og kræver ingen database, Composer, Node.js eller buildkommando. Alle URL'er er relative, så den kan køre fra eksempelvis `https://bibkort.landogbyforeningen.dk/`.

## Funktioner

- 15–90 minutters køretidsområde i trin på 5 minutter
- skift mellem Bækmarksbro, Thyborøn, Lemvig og kommunegrænsen ved Vilhelmsborgvej; Bækmarksbro er standard
- delbare sammenligninger via URL-parameteren `origin`
- TravelTime-isochroner baseret på vejnettet, ikke simple cirkler; Valhalla bruges som lokal fallback uden nøgler
- faktiske køretider og vejafstande til 14 arbejdsmarkedsbyer
- servercache af både isochroner, køretidsmatrix og Statistikbank-data
- seneste tilgængelige ERHV2-år findes automatisk
- job, arbejdssteder og branchefordeling for nåede kommuner uden dobbeltoptælling
- responsivt kort- og informationslayout
- tydelig metodeforklaring om forskellen på køretidspolygon og kommunetal

## Struktur

```text
index.php                      Hovedside og konfiguration til klienten
assets/css/app.css             Responsivt visuelt design
assets/js/app.js               Kort, slider og dynamiske beregninger
api/routing.php                Normaliseret routing-API med cache
api/statbank.php               ERHV2-proxy, årvalg og normalisering
config/app.php                 Udgangspunkt, byer, kommuner og providers
lib/bootstrap.php              HTTP-, cache- og JSON-hjælpere
lib/Routing/TravelTimeProvider.php TravelTime-provider til flade og matrix
lib/Routing/ValhallaProvider.php  Isoleret routing-provider
cache/                         Genererede cachefiler (ignoreres af Git)
```

## Krav

- PHP 8.0 eller nyere
- PHP-udvidelsen cURL **eller** `allow_url_fopen=On`
- udgående HTTPS-adgang til Statistikbanken og TravelTime
- JavaScript i browseren
- internetadgang til Leaflet-CDN og OpenStreetMap-kortfliser
- skriveadgang for PHP til mappen `cache/`

TravelTime-oplysninger læses fra miljøvariablerne `BIBKORT_TRAVELTIME_APP_ID` og `BIBKORT_TRAVELTIME_API_KEY` eller fra den Git-ignorerede fil `config/secrets.php`. Kopiér `config/secrets.example.php` som udgangspunkt. Hvis oplysningerne ikke findes, bruger appen Valhalla. Provider kan desuden vælges eksplicit med `BIBKORT_ROUTING_PROVIDER`.

## Lokal udvikling

Kør fra projektets rod:

```bash
php -S localhost:8080
```

Åbn derefter <http://localhost:8080/>. Første kald kan tage lidt længere tid, fordi data endnu ikke ligger i cache.

Syntaxkontrol:

```bash
php -l index.php
php -l api/routing.php
php -l api/statbank.php
php -l lib/bootstrap.php
php -l lib/Routing/TravelTimeProvider.php
php -l lib/Routing/ValhallaProvider.php
```

## Test-URL'er

Med den lokale server på port 8080:

- hovedside: <http://localhost:8080/>
- køretidsmatrix: <http://localhost:8080/api/routing.php?action=matrix&origin=baekmarksbro>
- 45-minutters isochrone: <http://localhost:8080/api/routing.php?action=isochrone&minutes=45&origin=thyboroen>
- seneste ERHV2-tal: <http://localhost:8080/api/statbank.php>

Et gyldigt API-svar har `"ok": true`. Routing-svar oplyser provider og cachestatus; Statistikbank-svaret oplyser tabel og år.

## Deployment til Simply.com

1. Klon eller importer repository til webroden for `bibkort.landogbyforeningen.dk`.
2. Vælg PHP 8.0 eller nyere i Simply-kontrolpanelet.
3. Opret `config/secrets.php` ud fra `config/secrets.example.php`, og indsæt TravelTime Application ID og Application Key. Filen bliver liggende ved senere Git-udrulninger.
4. Kontrollér, at webserverens PHP-bruger kan skrive i `cache/`.
5. Åbn `/api/statbank.php` og `/api/routing.php?action=matrix&origin=baekmarksbro` for at varme og kontrollere cachen. Routing-svaret skal vise `"provider":"TravelTime"`.
6. Åbn subdomænets forside.

Der skal ikke køres Composer, npm eller andre buildtrin på serveren. `cache/.htaccess` forhindrer direkte webadgang til cachefiler på Apache-kompatible webhoteller.

## Datakilder

- [Danmarks Statistik, ERHV2](https://www.statbank.dk/ERHV2): arbejdssteder og job efter kommune, branche og enhed
- [TravelTime](https://traveltime.com/): isochroner og køretidsmatrix
- [Valhalla](https://valhalla.github.io/valhalla/): fallback til lokal udvikling uden TravelTime-oplysninger
- [OpenStreetMap](https://www.openstreetmap.org/copyright): vejnet og kortgrundlag
- [Leaflet](https://leafletjs.com/): kortvisning i browseren

## Metode og kendte begrænsninger

Isochronen er en modelberegning på vejnettet. Resultatet afhænger af routingproviderens kortdata og kørselsmodel og er ikke en garanti for en bestemt rejsetid. TravelTime-beregningen bruger en typisk hverdagsmorgen og ikke live trafik.

ERHV2 indeholder kommuneoplysninger, ikke præcise by- eller polygondata. En kommunes job og arbejdssteder medregnes, når mindst én af de viste byer i kommunen kan nås inden for sliderens tid. Det omfatter også arbejdssteder i landzoner, men de kan ligge uden for selve køretidspolygonen. Derfor beskrives resultatet som **job i nåede kommuner** – ikke som job inden for selve køretidspolygonen. En kommune tælles højst én gang.

Se [DEPLOYMENT.md](DEPLOYMENT.md) for branch-, test- og produktionsflow.

Servercachen mindsker belastningen på routingtjenesten og kan levere senest gemte svar ved midlertidige udfald. TravelTime-kontoens kvote og vilkår skal passe til den faktiske trafik.

Bylisten og kommune­koblingen vedligeholdes samlet i `config/app.php`. Det er næste naturlige sted at udvide geografi og analysegrundlag.

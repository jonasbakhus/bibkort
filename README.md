# bibkort
Første selvstændige version af **Bo i Bækmarksbro**: et analyseværktøj, der viser realistiske køretidsområder og større arbejdsmarkedsbyer omkring Bækmarksbro.

Appen er bygget til et almindeligt PHP-webhotel og kræver ingen database, Composer, Node.js eller buildkommando. Alle URL'er er relative, så den kan køre fra eksempelvis `https://landogbyforeningen.dk/bibkort/`.

## Funktioner

- 15–90 minutters køretidsområde i trin på 5 minutter
- Valhalla-isochroner baseret på OpenStreetMaps vejnet, ikke simple cirkler
- faktiske køretider og vejafstande til ni arbejdsmarkedsbyer
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
lib/Routing/ValhallaProvider.php  Isoleret routing-provider
cache/                         Genererede cachefiler (ignoreres af Git)
```

## Krav

- PHP 8.0 eller nyere
- PHP-udvidelsen cURL **eller** `allow_url_fopen=On`
- udgående HTTPS-adgang til Statistikbanken og Valhalla
- JavaScript i browseren
- internetadgang til Leaflet-CDN og OpenStreetMap-kortfliser
- skriveadgang for PHP til mappen `cache/`

Der bruges ingen API-nøgler eller secrets. Routing-serveren kan skiftes med miljøvariablen `BIBKORT_VALHALLA_URL`; den skal pege på en kompatibel Valhalla-instans.

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
php -l lib/Routing/ValhallaProvider.php
```

## Test-URL'er

Med den lokale server på port 8080:

- hovedside: <http://localhost:8080/>
- køretidsmatrix: <http://localhost:8080/api/routing.php?action=matrix>
- 45-minutters isochrone: <http://localhost:8080/api/routing.php?action=isochrone&minutes=45>
- seneste ERHV2-tal: <http://localhost:8080/api/statbank.php>

Et gyldigt API-svar har `"ok": true`. Routing-svar oplyser provider og cachestatus; Statistikbank-svaret oplyser tabel og år.

## Deployment til Simply.com

1. Klon eller importer repository til `/public_html/bibkort`.
2. Vælg PHP 8.0 eller nyere i Simply-kontrolpanelet.
3. Kontrollér, at webserverens PHP-bruger kan skrive i `/public_html/bibkort/cache`.
4. Åbn `/bibkort/api/statbank.php` og `/bibkort/api/routing.php?action=matrix` for at varme og kontrollere cachen.
5. Åbn `/bibkort/`.

Der skal ikke køres Composer, npm eller andre buildtrin på serveren. `cache/.htaccess` forhindrer direkte webadgang til cachefiler på Apache-kompatible webhoteller.

## Datakilder

- [Danmarks Statistik, ERHV2](https://www.statbank.dk/ERHV2): arbejdssteder og job efter kommune, branche og enhed
- [Valhalla](https://valhalla.github.io/valhalla/): isochroner og køretidsmatrix
- [OpenStreetMap](https://www.openstreetmap.org/copyright): vejnet og kortgrundlag
- [Leaflet](https://leafletjs.com/): kortvisning i browseren

## Metode og kendte begrænsninger

Isochronen er en modelberegning på vejnettet. Resultatet afhænger af routingproviderens kortdata og kørselsmodel og er ikke en garanti for en bestemt rejsetid. Første version bruger ikke afgangstid eller live trafik.

ERHV2 indeholder kommuneoplysninger, ikke præcise by- eller polygondata. En kommunes job og arbejdssteder medregnes, når den viste hovedby kan nås inden for sliderens tid. Derfor beskrives resultatet som **job i kommuner, hvis hovedby kan nås** – ikke som job inden for selve køretidspolygonen. En kommune tælles højst én gang.

Den offentlige Valhalla-instans er en ekstern fællestjeneste uden oppetidsgaranti. Servercachen mindsker belastningen og kan levere senest gemte svar ved midlertidige udfald. Til en senere højtrafikversion bør en driftet routinginstans med aftalt kapacitet overvejes.

Bylisten og kommune­koblingen vedligeholdes samlet i `config/app.php`. Det er næste naturlige sted at udvide geografi og analysegrundlag.

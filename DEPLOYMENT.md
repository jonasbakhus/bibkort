# Versions- og udgivelsesflow

Projektet bruger to permanente grene og to subdomæner:

| Gren | Miljø | Webrod |
| --- | --- | --- |
| `develop` | `testbibkort.landogbyforeningen.dk` | `~/testbibkort` |
| `main` | `bibkort.landogbyforeningen.dk` | `~/bibkort` |

## Dagligt arbejde

1. Nye funktioner og rettelser committes til `develop`.
2. `develop` publiceres på testsubdomænet og afprøves.
3. Når versionen er godkendt, merges `develop` til `main`.
4. `main` publiceres på produktionssubdomænet og får et versions-tag.

Testmiljøet får automatisk `noindex`, når værtsnavnet begynder med `testbibkort.`.

## Automatisk deploy til test

Simply kører `scripts/deploy-test.sh` hvert minut via cron. Scriptet sammenligner den publicerede version med seneste commit på `develop` og gør ingenting, hvis de er ens. Ved et nyt commit hentes præcis den version fra GitHub, alle PHP-filer syntakstestes, og filerne synkroniseres til `~/testbibkort`.

Cronjobbet på Simply er:

```cron
* * * * * /usr/bin/flock -n $HOME/.bibkort-test-deploy.lock $HOME/testbibkort/scripts/deploy-test.sh >> $HOME/.bibkort-test-deploy.log 2>&1
```

`config/secrets.php` og genererede cachefiler bevares på serveren. Versionsmarkøren skrives først, når testsidens forside svarer korrekt, og routing-API'et oplyser TravelTime som provider. Ved fejl prøver cronjobbet igen næste minut; detaljer står i `~/.bibkort-test-deploy.log`.

## Publicér en gren via SSH

Erstat `BRANCH` og `MAPPE` med henholdsvis `develop`/`testbibkort` eller `main`/`bibkort`:

```bash
cd ~
deploy_tmp=$(mktemp -d)
curl -fsSL "https://github.com/jonasbakhus/bibkort/archive/refs/heads/BRANCH.tar.gz" | tar -xz -C "$deploy_tmp"
cp -a "$deploy_tmp/bibkort-BRANCH/." "MAPPE/"
chmod 775 "MAPPE/cache"
```

## TravelTime-oplysninger på hvert miljø

Opret `config/secrets.php` separat i både `~/testbibkort` og `~/bibkort`. Brug strukturen fra `config/secrets.example.php`, og indsæt miljøets Application ID og Application Key. Filen er ignoreret af Git og må ikke committes.

Udrulningskommandoen ovenfor sletter ikke filen, så den skal kun oprettes første gang. Kontrollér bagefter:

```text
https://testbibkort.landogbyforeningen.dk/api/routing.php?action=matrix&origin=baekmarksbro
```

Svaret skal indeholde `"provider":"TravelTime"`.

## Godkend testversionen

Lokalt i repositoryet:

```bash
git switch main
git merge --ff-only develop
git push origin main
git tag -a v1.1.0 -m "Version 1.1.0"
git push origin v1.1.0
```

Et tag gør det let at finde eller genskabe præcis den kode, som blev publiceret.

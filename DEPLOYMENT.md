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

## Publicér en gren via SSH

Erstat `BRANCH` og `MAPPE` med henholdsvis `develop`/`testbibkort` eller `main`/`bibkort`:

```bash
cd ~
deploy_tmp=$(mktemp -d)
curl -fsSL "https://github.com/jonasbakhus/bibkort/archive/refs/heads/BRANCH.tar.gz" | tar -xz -C "$deploy_tmp"
cp -a "$deploy_tmp/bibkort-BRANCH/." "MAPPE/"
chmod 775 "MAPPE/cache"
```

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

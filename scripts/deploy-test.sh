#!/usr/bin/env bash

set -Eeuo pipefail

repository="https://github.com/jonasbakhus/bibkort.git"
archive_base="https://github.com/jonasbakhus/bibkort/archive"
branch="develop"
target="$HOME/testbibkort"
state_file="$HOME/.bibkort-test-deployed"

test "$target" = "$HOME/testbibkort"
test -d "$target"

remote_commit="$(git ls-remote --heads "$repository" "refs/heads/$branch" | awk 'NR == 1 { print $1 }')"
if [[ ! "$remote_commit" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Kunne ikke finde seneste commit på $branch." >&2
    exit 1
fi

deployed_commit="$(cat "$state_file" 2>/dev/null || true)"
if [[ "$deployed_commit" == "$remote_commit" ]]; then
    exit 0
fi

stage="$(mktemp -d "$HOME/.bibkort-deploy.XXXXXX")"
cleanup() {
    rm -rf "$stage"
}
trap cleanup EXIT

curl -fsSL "$archive_base/${remote_commit}.tar.gz" | tar -xz -C "$stage"
source_dir="$(find "$stage" -mindepth 1 -maxdepth 1 -type d -print -quit)"
test -n "$source_dir"
test -f "$source_dir/index.php"

find "$source_dir" -type f -name '*.php' -exec php -l {} \;

mkdir -p "$target/cache"
rsync -a --delete \
    --exclude '/cache/*.json' \
    --exclude '/config/secrets.php' \
    "$source_dir/" "$target/"
chmod 775 "$target/cache"
test -f "$target/config/secrets.php"

# Opbyg den tunge geografi-cache uden webserverens korte backend-timeout.
geography_prewarm="$stage/geography-prewarm.json"
php "$target/api/geography.php" > "$geography_prewarm"
grep -Fq '"ok":true' "$geography_prewarm"
grep -Fq '"urban":0.9' "$geography_prewarm"

routing_prewarm="$stage/routing-prewarm.json"
php "$target/scripts/prewarm-routing.php" > "$routing_prewarm"
grep -Fq '"provider":"TravelTime"' "$routing_prewarm"

routing_response="$stage/routing-http.json"
curl -fsS --max-time 60 'https://testbibkort.landogbyforeningen.dk/api/routing.php?action=matrix&scope=settlements&origin=lemvig' -o "$routing_response"
grep -Fq '"provider":"TravelTime"' "$routing_response"
geography_response="$stage/geography-http.json"
curl -fsS --max-time 30 'https://testbibkort.landogbyforeningen.dk/api/geography.php' -o "$geography_response"
grep -Fq '"ok":true' "$geography_response"
grep -Fq '"urban":0.9' "$geography_response"
page_response="$(curl -fsS --max-time 30 'https://testbibkort.landogbyforeningen.dk/')"
[[ "$page_response" == *'<h1>Joboplandskort for Lemvig Kommune</h1>'* ]]

state_tmp="${state_file}.tmp"
printf '%s\n' "$remote_commit" > "$state_tmp"
mv "$state_tmp" "$state_file"
printf '%s Deployed %s to testbibkort\n' "$(date -Iseconds)" "$remote_commit"

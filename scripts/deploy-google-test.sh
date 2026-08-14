#!/usr/bin/env bash

set -Eeuo pipefail

repository="https://github.com/jonasbakhus/bibkort.git"
archive_base="https://github.com/jonasbakhus/bibkort/archive"
branch="google"
target="$HOME/testbibg"
state_file="$HOME/.bibkort-google-test-deployed"
auth_file="$HOME/.testbibg-health-auth"

test "$target" = "$HOME/testbibg"
test -d "$target"
test -f "$HOME/.testbibg-htpasswd"
test -f "$auth_file"

remote_commit="$(git ls-remote --heads "$repository" "refs/heads/$branch" | awk 'NR == 1 { print $1 }')"
if [[ ! "$remote_commit" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Kunne ikke finde seneste commit på $branch." >&2
    exit 1
fi

deployed_commit="$(cat "$state_file" 2>/dev/null || true)"
if [[ "$deployed_commit" == "$remote_commit" ]]; then
    exit 0
fi

stage="$(mktemp -d "$HOME/.bibkort-google-deploy.XXXXXX")"
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
cp "$target/scripts/testbibg.htaccess" "$target/.htaccess"
printf 'User-agent: *\nDisallow: /\n' > "$target/robots.txt"
chmod 775 "$target/cache"
test -f "$target/config/secrets.php"
grep -Fq "'variant' => 'google'" "$target/config/secrets.php"

geography_prewarm="$stage/geography-prewarm.json"
php "$target/api/geography.php" > "$geography_prewarm"
grep -Fq '"ok":true' "$geography_prewarm"

routing_prewarm="$stage/routing-prewarm.json"
php "$target/scripts/prewarm-routing.php" > "$routing_prewarm"
grep -Fq '"ok":true' "$routing_prewarm"

credentials="$(cat "$auth_file")"
page_response="$(curl -fsS --max-time 30 -u "$credentials" 'https://testbibg.landogbyforeningen.dk/')"
[[ "$page_response" == *'<h1>Arbejdsmarkedskort</h1>'* ]]
robots_response="$(curl -fsS --max-time 30 -u "$credentials" 'https://testbibg.landogbyforeningen.dk/robots.txt')"
[[ "$robots_response" == *'Disallow: /'* ]]

state_tmp="${state_file}.tmp"
printf '%s\n' "$remote_commit" > "$state_tmp"
mv "$state_tmp" "$state_file"
printf '%s Deployed %s to testbibg\n' "$(date -Iseconds)" "$remote_commit"

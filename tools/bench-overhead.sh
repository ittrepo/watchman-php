#!/usr/bin/env bash
# Measure what the Watchman SDK costs per request, on the real application.
#
# Runs ON the app server (it toggles the kill switch file, so it needs write
# access to it: run with sudo, or as the owner of /etc/watchman). Requests go
# to the URLs you list, alternating SDK-off / SDK-on round by round, so load
# drift and caches hit both modes equally. Prints p50 / p95 / mean per mode
# and the p95 difference against the limit (Phase 6 gate: < 2 ms).
#
#   tools/bench-overhead.sh -u urls.txt [-n 200] [-k /etc/watchman/disabled] [-l 2] [-H 'Cookie: PHPSESSID=...']
#
# urls.txt: one URL per line (# comments allowed). Use pages that matter
# (search, booking steps) and at least one that raises a PHP warning, the
# worst case for the SDK. The kill switch is always removed again on exit,
# even on Ctrl-C, unless it existed before the run (then it is left in place).
set -euo pipefail

URLS="" ROUNDS=200 KILL=/etc/watchman/disabled LIMIT_MS=2 HEADER=""
while getopts "u:n:k:l:H:" opt; do
    case $opt in
        u) URLS=$OPTARG ;; n) ROUNDS=$OPTARG ;; k) KILL=$OPTARG ;;
        l) LIMIT_MS=$OPTARG ;; H) HEADER=$OPTARG ;;
        *) sed -n '2,16p' "$0"; exit 2 ;;
    esac
done
[ -r "$URLS" ] || { sed -n '2,16p' "$0"; exit 2; }

mapfile -t list < <(grep -vE '^\s*(#|$)' "$URLS")
[ "${#list[@]}" -gt 0 ] || { echo "no URLs in $URLS"; exit 2; }

existed=0; [ -e "$KILL" ] && existed=1
restore() { [ $existed -eq 1 ] && touch "$KILL" || rm -f "$KILL"; }
trap restore EXIT INT TERM

tmp=$(mktemp -d); trap 'restore; rm -rf "$tmp"' EXIT INT TERM

hit() { # mode -> appends ms per request to $tmp/mode
    local url
    for url in "${list[@]}"; do
        curl -s -o /dev/null ${HEADER:+-H "$HEADER"} -w '%{time_total}\n' "$url" \
            | awk '{ printf "%.3f\n", $1 * 1000 }' >> "$tmp/$1"
    done
}

echo "warming up..."
for _ in 1 2 3; do rm -f "$KILL"; hit warm; touch "$KILL"; hit warm; done

echo "measuring: $ROUNDS rounds x ${#list[@]} URLs x 2 modes"
for ((i = 1; i <= ROUNDS; i++)); do
    if (( i % 2 )); then
        touch "$KILL"; hit off; rm -f "$KILL"; hit on
    else
        rm -f "$KILL"; hit on; touch "$KILL"; hit off
    fi
    if (( i % 50 == 0 )); then echo "  $i/$ROUNDS"; fi
done

stats() { # file -> "p50 p95 mean n"
    sort -n "$1" | awk '{ v[NR] = $1; s += $1 }
        END { p50 = v[int(NR * 0.50) > 0 ? int(NR * 0.50) : 1]; p95 = v[int(NR * 0.95) > 0 ? int(NR * 0.95) : 1];
              printf "%.2f %.2f %.2f %d\n", p50, p95, s / NR, NR }'
}

read -r off50 off95 offm n < <(stats "$tmp/off")
read -r on50 on95 onm _ < <(stats "$tmp/on")

printf '\n%-12s %9s %9s %9s\n' "" "p50 ms" "p95 ms" "mean ms"
printf '%-12s %9s %9s %9s\n' "SDK off" "$off50" "$off95" "$offm"
printf '%-12s %9s %9s %9s\n' "SDK on" "$on50" "$on95" "$onm"
diff=$(awk -v a="$on95" -v b="$off95" 'BEGIN { printf "%.2f", a - b }')
printf '\np95 difference: %s ms (limit %s ms, %d requests per mode)\n' "$diff" "$LIMIT_MS" "$n"

if awk -v d="$diff" -v l="$LIMIT_MS" 'BEGIN { exit !(d < l) }'; then
    echo "PASS"
else
    echo "FAIL"; exit 1
fi

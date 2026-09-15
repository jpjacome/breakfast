#!/usr/bin/env bash
#
# Read the production log without opening cPanel.
#
# Deploys here are FTP uploads to iFastNet — there is no CI, no SSH and no log
# drain. That same FTP account can READ, which is all this needs: it downloads
# storage/logs/<file> from the live app and prints the tail. Nothing is added to
# the live site, so there is no new URL to secure and no token to rotate.
#
# ⚠️ CREDENTIALS LIVE IN portal/.env.deploy, WHICH IS GIT-IGNORED AND MUST NEVER
# BE UPLOADED. It is a separate file from portal/.env (the local app config) and
# from portal/tests/.env (the one that IS uploaded, and is production's own env
# — see CLAUDE.md §3). Three env files, one of which travels; keep them straight.
#
# Downloads land OUTSIDE the repo, in Breakfast/logs-produccion/, so a stray
# "upload the folder" can never push production's log back to production.
#
#   tools/production-log.sh                  today's log, last 200 lines
#   tools/production-log.sh --date 2026-08-17
#   tools/production-log.sh --lines 500
#   tools/production-log.sh --grep "ERROR"   only matching lines (with context)
#   tools/production-log.sh --list           what log files the server has
#   tools/production-log.sh --single         laravel.log, for before LOG_CHANNEL=daily
#   tools/production-log.sh --raw            just download, print the local path
#
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app="$(dirname "$here")"
config="$app/.env.deploy"
downloads="$(dirname "$app")/logs-produccion"

# --- what was asked for ------------------------------------------------------

date_wanted="$(date +%F)"
lines=200
pattern=""
mode="tail"
single=false

while [ $# -gt 0 ]; do
    case "$1" in
        --date)   date_wanted="$2"; shift 2 ;;
        --lines)  lines="$2"; shift 2 ;;
        --grep)   pattern="$2"; shift 2 ;;
        --list)   mode="list"; shift ;;
        --raw)    mode="raw"; shift ;;
        --single) single=true; shift ;;
        -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 64 ;;
    esac
done

# --- credentials -------------------------------------------------------------

if [ ! -f "$config" ]; then
    cat >&2 <<MISSING
No $config.

Copy portal/.env.deploy.example to portal/.env.deploy and fill in the FTP
details you already deploy with. It is git-ignored; it must never be uploaded.
MISSING
    exit 1
fi

# Parsed rather than sourced: this file holds a password, and sourcing it would
# execute whatever is in it. Quotes are stripped, comments and blanks skipped.
read_setting() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$config" \
        | head -n 1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" \
        | tr -d '\r'
}

host="$(read_setting FTP_HOST)"
user="$(read_setting FTP_USER)"
pass="$(read_setting FTP_PASSWORD)"
logs_dir="$(read_setting FTP_LOG_DIR)"
logs_dir="${logs_dir:-storage/logs}"

for required in host user pass; do
    if [ -z "${!required}" ]; then
        echo "FTP_${required^^} is missing from $config." >&2
        exit 1
    fi
done

# --- the call ----------------------------------------------------------------
#
# --ssl rather than --ssl-reqd: it upgrades to TLS when the server offers it and
# still works when it does not, which is the difference between this being
# usable on iFastNet today and failing with a handshake error. --disable-epsv
# because passive-mode extensions are what shared hosts most often get wrong.
#
fetch() {
    curl --silent --show-error --fail \
         --ssl --disable-epsv \
         --user "$user:$pass" \
         "$@"
}

remote="ftp://$host/$logs_dir/"

if [ "$mode" = "list" ]; then
    echo "· $remote"
    fetch --list-only "$remote"
    exit 0
fi

if [ "$single" = true ]; then
    file="laravel.log"
else
    file="laravel-$date_wanted.log"
fi

mkdir -p "$downloads"
local_copy="$downloads/$file"

if ! fetch "$remote$file" --output "$local_copy"; then
    rm -f "$local_copy"
    cat >&2 <<GONE

Could not read $remote$file.

If the path is wrong, --list shows what is actually there. If the file simply
does not exist, production is probably still on the single-file channel — try
--single, or set LOG_CHANNEL=daily in the uploaded env (see CLAUDE.md §3).
GONE
    exit 1
fi

if [ "$mode" = "raw" ]; then
    echo "$local_copy"
    exit 0
fi

echo "· $file — $(wc -l < "$local_copy") lines, $(du -h "$local_copy" | cut -f1)"
echo "· saved to $local_copy"
echo

if [ -n "$pattern" ]; then
    grep -n -C 3 -- "$pattern" "$local_copy" | tail -n "$lines"
else
    tail -n "$lines" "$local_copy"
fi

#!/usr/bin/env bash
# Deft move (2026-10-30): puts a rebuilt site on its WordPress as Deft pages, with forms sending to Deft Core, then
# takes the old builder and form plugins off. Runs on the Hostinger server as u543656281.
#
# Bundles come from deft_move.py (one <domain>.zip each) and go in ~/deft-move/bundles/ with deft-move.php beside
# this script.
#
#   bash ~/deft-move.sh check SITE...       read-only: page matching, Deft Core version, where leads go, what's left
#   MOVE_APPROVED=yes bash ~/deft-move.sh apply SITE...
#                                           backup, Deft Core to 0.9.6 if older (from ~/deft-move/deft-core-client.zip),
#                                           upload the files, put every page live, purge cache, test
#   bash ~/deft-move.sh undo SITE           puts the pages back exactly as before the last apply
#   CLEANUP_APPROVED=yes bash ~/deft-move.sh cleanup SITE...
#                                           after the phone test send: export old form submissions (CSV, private),
#                                           drop those tables, then delete Elementor, Elementor Pro, Forminator,
#                                           WPForms, Contact Form 7, Mailchimp for WP and Elementor add-ons.
#                                           Stops if anything published still uses them (FORCE=yes to go anyway).
#
# Logs, backups, undo files and exports live in ~/deft-move/ (chmod 700). Nothing here touches DNS, users or passwords.

set -u
BASE=${BASE:-$HOME/domains}
HOME_DIR="$HOME/deft-move"
BUNDLES="$HOME_DIR/bundles"
HERE=$(cd "$(dirname "$0")" && pwd)
PHP_FILE=${PHP_FILE:-$HERE/deft-move.php}
CORE_ZIP=${CORE_ZIP:-$HOME/deft-move/deft-core-client.zip}   # Deft Core 0.9.6+ (dist\wp\deft-core-client.zip)
RUN=$(date -u +%Y%m%dT%H%MZ)
mkdir -p "$HOME_DIR"/{bundles,work,backups,undo,exports,logs}
chmod 700 "$HOME_DIR" "$HOME_DIR"/{backups,undo,exports}
LOG="$HOME_DIR/logs/$RUN.log"
MODE=${1:-}; shift || true

say() { echo "$*" | tee -a "$LOG"; }
wpc() { wp --path="$ROOT" --skip-themes "$@" 2>>"$LOG"; }
wpe() { wp --path="$ROOT" --skip-themes eval-file "$PHP_FILE" "$@" 2>>"$LOG"; }
code() { curl -s -o /dev/null -m 25 -w '%{http_code}' "$1"; }
fresh() { echo "$1$( [[ $1 == *\?* ]] && echo '&' || echo '?' )deft-move=$RANDOM"; }

site_ok() {
  case "$1" in ''|*[!a-z0-9.-]*|.*|-*) say "STOP bad site name $1"; return 1;; esac
  ROOT="$BASE/$1/public_html"
  [ -f "$ROOT/wp-config.php" ] || { say "SKIP $1 no WordPress"; return 1; }
  [ -f "$PHP_FILE" ] || { say "STOP deft-move.php is not next to this script"; exit 3; }
}

# Unpacks a bundle after checking it holds only what deft_move.py writes: no paths out of the folder, no PHP.
unpack() {
  local d=$1 zip="$BUNDLES/$1.zip"
  [ -f "$zip" ] || { say "SKIP $d no bundle at $zip"; return 1; }
  local bad
  bad=$(unzip -Z1 "$zip" | grep -vE '^(manifest\.json|REPORT\.md|site/[A-Za-z0-9._ ()@%+,~=&-][A-Za-z0-9._ ()@%+,~=&/-]*)$' | head -3)
  [ -z "$bad" ] || { say "STOP $d bundle has unexpected entries: $bad"; return 1; }
  if unzip -Z1 "$zip" | grep -E '(^|/)\.\.(/|$)|\.(php[0-9s]?|phtml|phar|pht|cgi|pl|py|sh|htaccess|user\.ini)$|(^|/)\.ht' | grep -q .; then
    say "STOP $d bundle holds a file a server could run"; return 1
  fi
  WORK="$HOME_DIR/work/$d-$RUN"; rm -rf "$WORK"; mkdir -p "$WORK"
  unzip -q "$zip" -d "$WORK" || { say "STOP $d unzip failed"; return 1; }
}

# Endpoint test that makes no lead: a filled honeypot is answered "sent" and dropped by Deft Core.
endpoint() {
  curl -s -m 25 -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
    -d '{"name":"deft-move check","phone":"0000000000","website_url":"bot","_elapsed":9000}' \
    "https://$1/wp-json/deft/v1/forms/contact"
}

check() {
  local d=$1
  site_ok "$d" && unpack "$d" || return
  say "== $d"
  wpe check "$WORK/manifest.json" | tee -a "$LOG"
  say "plugins on: $(wpc plugin list --status=active --field=name | grep -E 'elementor|forminator|wpforms|contact-form-7|mailchimp-for-wp|flamingo|smtp' | tr '\n' ' ')"
  say "forms endpoint: $(endpoint "$d")   wp-login: $(code "$(fresh "https://$d/wp-login.php")")"
  local perm; perm=$(stat -c %a "$ROOT/wp-content/plugins/deft-core" 2>/dev/null)
  [ "$perm" = 755 ] || say "WARN plugins/deft-core folder is ${perm:-missing} (should be 755)"
  wpe leftovers "$WORK/manifest.json" | sed 's/^/left: /' | tee -a "$LOG"
}

apply() {
  local d=$1
  site_ok "$d" && unpack "$d" || return
  say "== $d apply"
  local before; before=$(code "$(fresh "https://$d/wp-login.php")")
  [ "$before" = 200 ] || { say "SKIP $d wp-login.php is $before before we start"; return; }

  local bk="$HOME_DIR/backups/$d-$RUN"; mkdir -p "$bk"; chmod 700 "$bk"
  # Streamed to gzip: writing the dump straight to a file fails silently on Hostinger, stdout works.
  # --no-tablespaces: Hostinger's database users lack the PROCESS privilege newer mysqldump asks for.
  ( set -o pipefail; wpc db export - --no-tablespaces --single-transaction | gzip > "$bk/db.sql.gz" )
  local rc=$? size; size=$(stat -c %s "$bk/db.sql.gz" 2>/dev/null || echo 0)
  if [ $rc != 0 ] || [ "$size" -lt 1000 ] || ! zcat "$bk/db.sql.gz" | tail -n 1 | grep -q 'Dump completed'; then
    say "STOP $d database backup failed (exit $rc, $size bytes):"; tail -n 3 "$LOG" | sed 's/^/  /'; return
  fi
  chmod 600 "$bk/db.sql.gz"; say "backup: $bk/db.sql.gz ($size bytes)"
  # Deft Core must be 0.9.6 or later so the extra questions are kept. Update from the client zip if it's older.
  local ver; ver=$(wpc plugin get deft-core --field=version)
  if [ -z "$ver" ] || [ "$(printf '%s\n0.9.6\n' "$ver" | sort -V | head -n1)" != 0.9.6 ]; then
    [ -f "$CORE_ZIP" ] || { say "SKIP $d Deft Core is ${ver:-missing}; put the 0.9.6 client zip at $CORE_ZIP"; return; }
    wpc plugin install "$CORE_ZIP" --force --activate >> "$LOG" || { say "STOP $d Deft Core update failed"; return; }
    find "$ROOT/wp-content/plugins/deft-core" -type d -exec chmod 755 {} + ; find "$ROOT/wp-content/plugins/deft-core" -type f -exec chmod 644 {} +
    say "deft-core ${ver:-none} -> $(wpc plugin get deft-core --field=version)"
  fi

  local up="$ROOT/wp-content/uploads/deft-site"
  [ -d "$up" ] && mv "$up" "$bk/deft-site"

  mkdir -p "$up" && cp -R "$WORK/site/." "$up/"
  cat > "$up/.htaccess" <<'HT'
# Deft move: static files only. Nothing in this folder may run as a script, and folders don't list.
Options -Indexes -ExecCGI
<FilesMatch "\.(php[0-9s]?|phtml|phar|pht|cgi|pl|py|sh|asp|aspx|jsp|shtml)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
</IfModule>
HT
  find "$up" -type d -exec chmod 755 {} + ; find "$up" -type f -exec chmod 644 {} +

  wpe apply "$WORK/manifest.json" "$HOME_DIR/undo/$d.json" | tee -a "$LOG"
  cp "$HOME_DIR/undo/$d.json" "$HOME_DIR/undo/$d-$RUN.json" 2>/dev/null
  wpc litespeed-purge all >/dev/null 2>&1 || true

  # Checks: every page answers 200 as the Deft page, forms are connected, the form script loads, login still works.
  local fail=0 url
  for url in $(wpe urls "$WORK/manifest.json"); do
    local path=${url%%|*} forms=${url##*|} page
    path=${path/#http:/https:}
    page=$(curl -s -m 25 -L "$(fresh "$path")")
    if ! grep -q 'name="deft-page"' <<<"$page"; then say "FAIL $path is not showing the Deft page"; fail=1; continue; fi
    if [ "$forms" != 0 ] && ! grep -q 'data-deft-form' <<<"$page"; then say "FAIL $path lost its form"; fail=1; fi
    if grep -qiE 'preview-form|preview only|delivery will be connected' <<<"$page"; then say "FAIL $path still has preview form text"; fail=1; fi
  done
  [ "$(code "$(fresh "https://$d/wp-content/uploads/deft-site/deft-forms.js")")" = 200 ] || { say "FAIL deft-forms.js does not load"; fail=1; }
  local ep; ep=$(endpoint "$d"); [ "$ep" = 200 ] || { say "FAIL forms endpoint answers $ep (firewall or Deft Core)"; fail=1; }
  local after; after=$(code "$(fresh "https://$d/wp-login.php")")
  if [ "$after" != 200 ]; then
    say "FAIL wp-login.php is $after after the move. Undoing."; undo "$d"; return
  fi
  [ $fail = 0 ] && say "OK $d moved. Next: one real test send from a phone, then cleanup." || say "CHECK $d moved with the failures above. Undo with: bash ~/deft-move.sh undo $d"
}

undo() {
  local d=$1
  site_ok "$d" || return
  [ -f "$HOME_DIR/undo/$d.json" ] || { say "SKIP $d nothing to undo"; return; }
  say "== $d undo"
  wpe undo "$HOME_DIR/undo/$d.json" | tee -a "$LOG"
  mv "$HOME_DIR/undo/$d.json" "$HOME_DIR/undo/$d-undone-$RUN.json"
  wpc litespeed-purge all >/dev/null 2>&1 || true
  say "login: $(code "$(fresh "https://$d/wp-login.php")")"
}

cleanup() {
  local d=$1
  site_ok "$d" && unpack "$d" || return
  say "== $d cleanup"
  local left; left=$(wpe leftovers "$WORK/manifest.json")
  if [ -n "$left" ] && [ "${FORCE-}" != yes ]; then
    say "STOP $d still uses Elementor or a form plugin here (FORCE=yes to remove anyway):"; say "$left"; return
  fi
  local ex="$HOME_DIR/exports/$d-$RUN"; mkdir -p "$ex"; chmod 700 "$ex"
  wpe export "$ex" | tee -a "$LOG"
  wpe purge "$ex" | tee -a "$LOG"
  local p removed=""
  for p in $(wpc plugin list --field=name | grep -E '^(elementor|elementor-pro|forminator|wpforms|wpforms-lite|contact-form-7|mailchimp-for-wp|flamingo)$|elementor'); do
    wpc plugin deactivate "$p" >/dev/null; wpc plugin delete "$p" >/dev/null && removed="$removed $p"
  done
  say "deleted:${removed:- nothing}"
  wpc litespeed-purge all >/dev/null 2>&1 || true
  local fail=0 path
  for path in $(wpe urls "$WORK/manifest.json"); do
    path=${path%%|*}; path=${path/#http:/https:}
    local c; c=$(code "$(fresh "$path")"); [ "$c" = 200 ] || { say "FAIL $path answers $c"; fail=1; }
  done
  [ "$(code "$(fresh "https://$d/wp-login.php")")" = 200 ] || { say "FAIL wp-login.php"; fail=1; }
  [ $fail = 0 ] && say "OK $d clean. Exports (private, copy to the Vault): $ex" || say "CHECK $d failures above. Plugins can be reinstalled from wp.org; Elementor's page data is still in the database."
}

case "$MODE" in
  check)   [ $# -gt 0 ] || { echo "name the sites"; exit 3; }; for d in "$@"; do check "$d"; done ;;
  apply)   [ "${MOVE_APPROVED-}" = yes ] || { echo "STOP apply needs MOVE_APPROVED=yes"; exit 3; }
           [ $# -gt 0 ] || { echo "name the sites"; exit 3; }; for d in "$@"; do apply "$d"; done ;;
  undo)    [ $# -gt 0 ] || { echo "name the site"; exit 3; }; for d in "$@"; do undo "$d"; done ;;
  cleanup) [ "${CLEANUP_APPROVED-}" = yes ] || { echo "STOP cleanup needs CLEANUP_APPROVED=yes"; exit 3; }
           [ $# -gt 0 ] || { echo "name the sites"; exit 3; }; for d in "$@"; do cleanup "$d"; done ;;
  *) sed -n "2,23p" "$0"; exit 1 ;;
esac
echo "log: $LOG"

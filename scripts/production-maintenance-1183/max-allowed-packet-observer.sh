# Shared bounded #1183 max_allowed_packet observation contract.
observe_max_allowed_packet() {
  local drupal_root="${1:-}"
  local max_allowed_packet='UNKNOWN'
  local max_allowed_packet_source='UNKNOWN'
  local max_packet_raw=''

  if [[ -x "$drupal_root/vendor/bin/drush" ]] \
    && (cd "$drupal_root" && vendor/bin/drush status >/dev/null 2>&1); then
    if max_packet_raw="$(cd "$drupal_root" \
      && vendor/bin/drush php:eval \
        'echo (string) \\Drupal::database()->query("SELECT @@global.max_allowed_packet")->fetchField();' \
        2>/dev/null | tail -n 1 | tr -d '[:space:]')"; then
      if [[ "$max_packet_raw" =~ ^[0-9]+$ ]]; then
        max_allowed_packet="$max_packet_raw"
        max_allowed_packet_source='DRUPAL_DB_API'
      fi
    fi
  fi

  if [[ "$max_allowed_packet_source" == 'UNKNOWN' ]]; then
    if max_packet_raw="$(sudo -n mariadb -NBe \
      'SELECT @@global.max_allowed_packet;' 2>/dev/null \
      | tail -n 1 | tr -d '[:space:]')"; then
      if [[ "$max_packet_raw" =~ ^[0-9]+$ ]]; then
        max_allowed_packet="$max_packet_raw"
        max_allowed_packet_source='SUDO_MARIADB'
      fi
    fi
  fi

  printf 'MAX_ALLOWED_PACKET=%s\n' "$max_allowed_packet"
  printf 'MAX_ALLOWED_PACKET_SOURCE=%s\n' "$max_allowed_packet_source"
}

#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

PROJECT_ROOT="/var/www/agency-preprod"
CURRENT="$PROJECT_ROOT/current"
DRUSH="$CURRENT/vendor/bin/drush"

fail() {
  printf '[preprod-runtime] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ -L "$CURRENT" ]] || fail "Current PREPROD release symlink is missing."
[[ -x "$DRUSH" ]] || fail "Drush is unavailable in the active PREPROD release."

cd "$CURRENT"

"$DRUSH" --quiet php:eval '
$config = \Drupal::config("automated_cron.settings");
if ((int) $config->get("interval") !== 0) {
  throw new \RuntimeException("Automated cron is not disabled in PREPROD.");
}
$mail = \Drupal::config("system.mail");
if ($mail->get("interface.default") !== "symfony_mailer") {
  throw new \RuntimeException("PREPROD mail interface is not symfony_mailer.");
}
if ($mail->get("mailer_dsn.scheme") !== "native") {
  throw new \RuntimeException("PREPROD mail transport is not native.");
}
if ($mail->get("mailer_dsn.user") !== NULL || $mail->get("mailer_dsn.password") !== NULL) {
  throw new \RuntimeException("PREPROD mail transport unexpectedly contains credentials.");
}
if ((bool) \Drupal::config("config_split.config_split.production")->get("status") !== FALSE) {
  throw new \RuntimeException("Production Config Split is active in PREPROD.");
}
if ((bool) \Drupal::config("config_split.config_split.preproduction")->get("status") !== TRUE) {
  throw new \RuntimeException("PREPROD Config Split is not active.");
}
if (\Drupal::config("google_tag.settings")->get("default_google_tag_entity") !== NULL) {
  throw new \RuntimeException("Google Tag is active in PREPROD.");
}
if (\Drupal::config("linkchecker.settings")->get("base_path") !== "preprod.emergingdigital.be") {
  throw new \RuntimeException("Link Checker is not bound to the PREPROD canonical host.");
}
if ((bool) \Drupal::config("key.key.openai_api_key")->get("status") !== FALSE) {
  throw new \RuntimeException("OpenAI Key entity is enabled in normal PREPROD.");
}
if (\Drupal\Core\Site\Settings::get("agency_external_ai_egress_enabled", NULL) !== FALSE) {
  throw new \RuntimeException("External AI egress is not explicitly blocked in PREPROD.");
}
$key = getenv("OPENAI_API_KEY");
if (is_string($key) && trim($key) !== "") {
  throw new \RuntimeException("OPENAI_API_KEY is present in normal PREPROD runtime.");
}
$guard = \Drupal::service("emerging_digital_chatbot.future_ai_environment_guard");
if ($guard->allowsExternalCalls()) {
  throw new \RuntimeException("Chatbot Future AI guard allows external calls in PREPROD.");
}
'

sendmail_path="$(php8.4 -r 'echo (string) ini_get("sendmail_path");')"
[[ "$sendmail_path" == "/bin/true" ]] || fail "PHP CLI sendmail_path is not /bin/true."

mem_total_bytes="$(awk '/^MemTotal:/ {print $2 * 1024}' /proc/meminfo | cut -d. -f1)"
mem_available_bytes="$(awk '/^MemAvailable:/ {print $2 * 1024}' /proc/meminfo | cut -d. -f1)"
swap_total_bytes="$(awk '/^SwapTotal:/ {print $2 * 1024}' /proc/meminfo | cut -d. -f1)"
swap_free_bytes="$(awk '/^SwapFree:/ {print $2 * 1024}' /proc/meminfo | cut -d. -f1)"
cpu_count="$(nproc)"
disk_total_bytes="$(df -B1 --output=size "$PROJECT_ROOT" | tail -n 1 | tr -d ' ')"
disk_used_bytes="$(df -B1 --output=used "$PROJECT_ROOT" | tail -n 1 | tr -d ' ')"
disk_available_bytes="$(df -B1 --output=avail "$PROJECT_ROOT" | tail -n 1 | tr -d ' ')"
disk_used_percent="$(df -P "$PROJECT_ROOT" | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
oom_kill_count="$(awk '$1 == "oom_kill" {print $2}' /proc/vmstat 2>/dev/null || true)"
oom_kill_count="${oom_kill_count:-0}"

printf 'schema_version=2\n'
printf 'side_effects=PASS\n'
printf 'automated_cron=OFF\n'
printf 'mail_transport=NATIVE_NULL_CREDENTIALS\n'
printf 'php_sendmail_path=BIN_TRUE\n'
printf 'production_config_split=OFF\n'
printf 'preproduction_config_split=ON\n'
printf 'google_tag=OFF\n'
printf 'linkchecker_base_path=PREPROD\n'
printf 'openai_key=ABSENT\n'
printf 'external_ai_egress=BLOCKED\n'
printf 'normal_openai_egress=ZERO_BY_POLICY\n'
printf 'cpu_count=%s\n' "$cpu_count"
printf 'mem_total_bytes=%s\n' "$mem_total_bytes"
printf 'mem_available_bytes=%s\n' "$mem_available_bytes"
printf 'swap_total_bytes=%s\n' "$swap_total_bytes"
printf 'swap_free_bytes=%s\n' "$swap_free_bytes"
printf 'disk_total_bytes=%s\n' "$disk_total_bytes"
printf 'disk_used_bytes=%s\n' "$disk_used_bytes"
printf 'disk_available_bytes=%s\n' "$disk_available_bytes"
printf 'disk_used_percent=%s\n' "$disk_used_percent"
printf 'oom_kill_count=%s\n' "$oom_kill_count"
printf 'capacity_observed=PASS\n'

#!/usr/bin/env bash
set -euo pipefail

: "${PROOF_PROFILE:?PROOF_PROFILE is required}"

case "$PROOF_PROFILE" in
  case-studies-440)
    PROOF_CONTENT_IDS=(
      "cas-client-refonte-drupal-institutionnelle"
      "cas-client-migration-drupal-11"
      "cas-client-integration-ia-editoriale"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/cas-clients/refonte-drupal-institutionnelle"
      "/en/case-studies/institutional-drupal-redesign"
      "/fr/cas-clients/migration-drupal-11"
      "/en/case-studies/drupal-11-migration"
      "/fr/cas-clients/integration-ia-editoriale"
      "/en/case-studies/editorial-ai-integration"
    )
    PROOF_EDITORIAL_CONTENT_ID="cas-client-migration-drupal-11"
    PROOF_EDITORIAL_PATH="/fr/cas-clients/migration-drupal-11"
    PROOF_EDITORIAL_MARKER="[proof-440-editorial-survives]"
    ;;

  ai-features-441)
    PROOF_CONTENT_IDS=(
      "ai-automatisation-contenu-drupal"
      "ai-generation-multilingue"
      "ai-chatbot-qualification"
      "ai-audit-intelligent"
      "ai-redaction-assistee"
      "ai-correction-editoriale"
      "ai-traduction-fr-en"
      "ai-resumes-tags-structure"
      "ai-seo-liens-internes"
      "ai-gouvernance-validation"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/ia-drupal/automatisation-contenu-drupal"
      "/en/ai-drupal/drupal-content-automation"
      "/fr/ia-drupal/generation-multilingue"
      "/en/ai-drupal/multilingual-generation"
      "/fr/ia-drupal/chatbot-qualification"
      "/en/ai-drupal/qualification-chatbot"
      "/fr/ia-drupal/audit-intelligent"
      "/en/ai-drupal/intelligent-audit"
      "/fr/ia-drupal/redaction-assistee"
      "/en/ai-drupal/assisted-writing"
      "/fr/ia-drupal/correction-editoriale"
      "/en/ai-drupal/editorial-review"
      "/fr/ia-drupal/traduction-fr-en"
      "/en/ai-drupal/fr-en-translation"
      "/fr/ia-drupal/resumes-tags-structure"
      "/en/ai-drupal/summaries-tags-structure"
      "/fr/ia-drupal/seo-liens-internes"
      "/en/ai-drupal/seo-internal-links"
      "/fr/ia-drupal/gouvernance-validation"
      "/en/ai-drupal/governance-approval"
    )
    PROOF_EDITORIAL_CONTENT_ID="ai-redaction-assistee"
    PROOF_EDITORIAL_PATH="/fr/ia-drupal/redaction-assistee"
    PROOF_EDITORIAL_MARKER="[proof-441-ai-features-editorial-survives]"
    ;;

  *)
    echo "Unsupported governed transition proof profile: $PROOF_PROFILE" >&2
    return 1 2>/dev/null || exit 1
    ;;
esac

PROOF_PAYLOADS=()
for content_id in "${PROOF_CONTENT_IDS[@]}"; do
  PROOF_PAYLOADS+=(
    "web/modules/custom/emerging_digital_content/content_sync/node/${content_id}.yml"
  )
done

PROOF_MAPPING_COUNT="${#PROOF_CONTENT_IDS[@]}"
PROOF_SQL_IDS="$(printf "'%s'," "${PROOF_CONTENT_IDS[@]}")"
PROOF_SQL_IDS="${PROOF_SQL_IDS%,}"

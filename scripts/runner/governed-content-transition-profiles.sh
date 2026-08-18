#!/usr/bin/env bash
set -euo pipefail

: "${PROOF_PROFILE:?PROOF_PROFILE is required}"

PROOF_BROWSER_ONLY_PATHS=()
PROOF_CONTACT_FORM_PATHS=()

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

  services-drupal-441)
    PROOF_CONTENT_IDS=(
      "agence-drupal-belgique"
      "creation-site-drupal"
      "maintenance-drupal"
      "migration-drupal"
      "refonte-site-drupal"
      "audit-drupal"
      "accessibilite-seo-optimisation"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/agence-drupal-belgique"
      "/en/drupal-agency-belgium"
      "/fr/creation-site-drupal"
      "/en/drupal-website-creation"
      "/fr/maintenance-drupal"
      "/en/drupal-maintenance"
      "/fr/migration-drupal"
      "/en/drupal-migration"
      "/fr/refonte-site-drupal"
      "/en/drupal-website-redesign"
      "/fr/audit-drupal"
      "/en/drupal-audit"
      "/fr/accessibilite-seo-optimisation"
      "/en/ai-accessibility-seo-optimization"
    )
    PROOF_EDITORIAL_CONTENT_ID="audit-drupal"
    PROOF_EDITORIAL_PATH="/fr/audit-drupal"
    PROOF_EDITORIAL_MARKER="[proof-441-services-drupal-editorial-survives]"
    ;;

  services-general-441)
    PROOF_CONTENT_IDS=(
      "agence-web-belgique"
      "agence-web-liege"
      "creation-site-web-professionnel"
      "refonte-site-internet"
      "site-web-pme"
      "ia-integree"
      "ia-pour-pme"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/agence-web-belgique"
      "/en/web-agency-belgium"
      "/fr/agence-web-liege"
      "/en/web-agency-liege"
      "/fr/creation-site-web-professionnel"
      "/en/professional-website-creation"
      "/fr/refonte-site-internet"
      "/en/website-redesign"
      "/fr/site-web-pme"
      "/en/sme-website"
      "/fr/ia-integree"
      "/en/integrated-ai"
      "/fr/ia-pour-pme"
      "/en/ai-for-smes"
    )
    PROOF_EDITORIAL_CONTENT_ID="creation-site-web-professionnel"
    PROOF_EDITORIAL_PATH="/fr/creation-site-web-professionnel"
    PROOF_EDITORIAL_MARKER="[proof-441-services-general-editorial-survives]"
    ;;

  pages-medium-441)
    PROOF_CONTENT_IDS=(
      "cas-clients"
      "equipe"
      "ia-drupal"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/cas-clients"
      "/en/case-studies"
      "/fr/equipe"
      "/en/team"
      "/fr/ia-drupal"
      "/en/ai-drupal"
    )
    PROOF_EDITORIAL_CONTENT_ID="equipe"
    PROOF_EDITORIAL_PATH="/fr/equipe"
    PROOF_EDITORIAL_MARKER="[proof-441-medium-pages-editorial-survives]"
    ;;

  pages-final-441)
    PROOF_CONTENT_IDS=(
      "homepage"
      "services"
      "contact"
    )
    PROOF_PUBLIC_PATHS=(
      "/fr/accueil"
      "/en/home"
      "/fr/services"
      "/en/services"
      "/fr/contact"
      "/en/contact"
    )
    PROOF_BROWSER_ONLY_PATHS=(
      "/fr/"
      "/en/"
    )
    PROOF_CONTACT_FORM_PATHS=(
      "/fr/contact"
      "/en/contact"
    )
    PROOF_EDITORIAL_CONTENT_ID="services"
    PROOF_EDITORIAL_PATH="/fr/services"
    PROOF_EDITORIAL_MARKER="[proof-441-final-pages-editorial-survives]"
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

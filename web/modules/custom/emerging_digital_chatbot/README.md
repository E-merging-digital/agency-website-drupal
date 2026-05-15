# Emerging Digital Chatbot

Guided Drupal chatbot MVP for contact orientation and light qualification,
with a prepared server-side AI boundary.

## Scope

This module provides a discreet floating widget through a Drupal block. The MVP
uses quick choices, guarded responses and CTA links by default. The optional AI
mode is disabled in exported configuration and remains controlled by Drupal. It
does not calculate prices, produce quotes, make commercial decisions, expose API
keys, or store conversation history.

## Installation

Enable the module and place the `Guided chatbot` block, or import the project
configuration that places `emerging_digital_chatbot_widget` in the
`emerging_digital` content region.

The exported settings live in:

- `emerging_digital_chatbot.settings`
- `block.block.emerging_digital_chatbot_widget`

## Configuration

The settings file controls:

- global activation;
- enabled languages;
- allowed and excluded pages;
- guide mode versus future AI mode;
- localized FR/EN messages;
- guided flows and CTAs;
- OpenAI Responses API endpoint/model metadata;
- FR/EN system prompts and fallback messages;
- input, context, timeout and rate-limit safeguards;
- future public-context profile for mini-RAG preparation.

External AI calls are blocked by default, even if `future_ai.enabled` is true.
They require an explicit runtime allowance through
`$settings['emerging_digital_chatbot.allow_external_ai'] = TRUE` or
`EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true`.

The OpenAI API key must never be stored in exportable configuration. It must be
available through the Drupal Key module. Resolution order is:

1. OpenAI provider configuration from `ai_provider_openai` through the Key
   module.
2. `future_ai.openai_key_id`, interpreted as a Drupal Key id.

## Controlled local/staging runtime activation

Future AI can be tested only in an explicitly trusted local or staging runtime.
Do not enable it in exported `config/sync` to carry a secret or an environment
decision between environments.

Required runtime conditions:

- `mode` is set to `ai` in the active Drupal configuration.
- `future_ai.enabled` is `true` in the active Drupal configuration.
- `future_ai.provider` is `openai_responses`.
- the runtime explicitly allows external AI calls.
- the Drupal Key module can resolve the configured OpenAI key.

Recommended `settings.local.php` allowance for local/staging:

```php
$settings['emerging_digital_chatbot.allow_external_ai'] = TRUE;
```

Equivalent environment-variable allowance:

```bash
export EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true
```

The setting wins when it is a boolean. If it is absent, the environment variable
is accepted only for `1`, `true`, `yes` or `on`, case-insensitively. Any other
value leaves external calls blocked.

Configure the secret through Key module, not configuration export. The expected
setup is an environment-backed Key:

```bash
export OPENAI_API_KEY=sk-...
```

Create a Key with id `openai_api_key`, type `Authentication`, provider
`Environment`, and environment variable `OPENAI_API_KEY`. The key input stays
`None` because the value is read at runtime from the environment.

Then either let `ai_provider_openai` reference that Key id, or keep the
non-secret fallback id in `future_ai.openai_key_id`. Never put the key value in
`config/sync`, `settings.php`, `settings.local.php`, exported YAML or logs.

Runtime diagnostics are available at:

`/admin/config/services/emerging-digital-chatbot/public-context`

The screen exposes only sanitized status and monitoring values:

- Future AI state: `enabled` or `disabled`.
- Active provider: provider id only.
- Environment: `allowed` or `blocked`.
- Reason: for example `environment_blocked`, `future_ai_disabled`,
  `unsupported_provider`, `key_missing` or `key_unreadable`.
- Key status: `available`, `missing` or `unreadable`.
- External calls allowed: `yes` only when every guard passes.
- Monitoring period: counters since the last Drupal cache clear.
- Technical counters: events, successes, blocked calls, provider errors and
  fallbacks returned.
- Controlled monitoring reasons only: `environment_blocked`,
  `future_ai_disabled`, `key_missing`, `key_unreadable`,
  `unsupported_provider`, `context_empty`, `provider_timeout`, `provider_error`,
  `fallback_used` and `success`.

Future AI response contracts are typed before they reach the HTTP controller:

- `FutureAiResponse` carries only controlled public fields: `status`,
  `message`, `fallback`, `stored`, `langcode` and the optional sanitized
  `futureAi` summary.
- `FutureAiResponseStatus` is the public status vocabulary serialized by
  `FutureAiResponse::toArray()`. It preserves the existing endpoint values such
  as `ai_response`, `guide_only`, `provider_error` and `provider_timeout`.
- `FutureAiResponseReason` is the internal reason vocabulary used by the
  orchestrator and monitoring. Detailed local reasons that are not part of the
  admin monitoring vocabulary are folded into `fallback_used` before storage.
- The contract has no extension bag for prompts, visitor payloads, provider
  payloads, RAG context text, API keys or Key ids. The optional `futureAi`
  summary is allow-listed and sanitized during construction.

Monitoring is intentionally minimal and anonymous. It stores volatile counters
in Drupal cache and emits sanitized Drupal log events with controlled reason
codes only. It never stores visitor messages, prompts, public RAG context,
provider payloads, API keys, Key ids, session ids, user ids, IP addresses,
marketing identifiers or conversation state. It does not add external calls,
queues, analytics scripts, a custom SQL business table or durable conversation
storage. Cache clears reset the admin counters; logs remain subject to the
site's normal Drupal logging retention.

Expected behavior:

- allowed environment plus readable Key: one server-side OpenAI Responses call
  can be made with `store: false`, no tools and no conversation state.
- blocked environment, disabled Future AI, unsupported provider or missing Key:
  no external HTTP call is made and the guided fallback is returned.
- provider error, invalid JSON, timeout, empty answer or guardrail violation:
  the guided fallback is returned.
- sensitive visitor input is rejected before any provider request.
- logs contain only technical reason/status/class values, never prompts, API
  keys, raw provider payloads, full context or visitor messages.

The default block hides the widget on contact pages so it does not cover the
human contact form.

## Architecture

- `ChatbotBlock` renders a cacheable Drupal block and attaches the widget
  library.
- `ChatbotConfig` normalizes Config API values, language selection and path
  visibility.
- `ChatbotEndpointController` exposes a prepared POST endpoint for later server
  conversation handling, with CSRF protection, no-store responses, flood
  limiting and delegation to the Future AI orchestrator.
- `ChatbotPayloadSanitizer` keeps only minimal scalar visitor input and blocks
  obvious sensitive data before any provider call.
- `FutureAiGatewayInterface` defines the server-side AI boundary.
- `FutureAiOrchestrator` centralizes Future AI activation, environment checks,
  public context retrieval, fallback decisions, provider dispatch and sanitized
  monitoring. It is deterministic: every blocked, empty, timed-out or invalid
  provider path returns the local guided fallback and `stored: false`.
- `FutureAiResponse`, `FutureAiResponseStatus` and `FutureAiResponseReason`
  define the typed response contract used between the orchestrator, provider
  gateway and controller before final JSON serialization.
- `FutureAiEnvironmentGuard` validates only the runtime environment, provider
  id and runtime Key availability. It does not build prompts or call providers.
- `FutureAiProviderGatewayInterface` defines a stateless provider adapter
  contract so future providers can be added behind the orchestrator without
  changing the controller.
- `OpenAiResponsesGateway` prepares OpenAI Responses API calls with `store:
  false`, no tools, no conversation state and prompt/context limits, then
  parses and sanitizes provider results. It does not decide activation,
  fallback policy, environment access or monitoring.
- `PublicAiContextProvider` prepares a public-pages-only context contract for a
  future mini-RAG without adding vector stores.
- `NullFutureAiGateway` provides the local guided fallback when AI is disabled,
  unavailable, unsafe, unconfigured or empty.
- `js/chatbot-widget.js` is vanilla JS with keyboard support and a focus trap.
- `css/chatbot-widget.css` keeps the visual layer lightweight and module-owned.

## Limits of the MVP

The widget guides visitors toward public pages and human contact. It deliberately
avoids pricing, binding promises, autonomous decisions, advanced analytics,
conversation memory and CRM integration. AI mode must stay an assistant for
clarification and orientation only.

OpenAI calls remain server-side. Drupal owns UX, CSRF/rate limiting,
sanitization, prompts, public context, fallback, logging policy and
multilingual behavior. The orchestration layer owns Future AI business
decisions; provider gateways only adapt one provider's HTTP contract. The
frontend never receives prompts or API keys.

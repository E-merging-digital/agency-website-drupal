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

The OpenAI API key must never be stored in exportable configuration. Resolution
order is:

1. OpenAI provider configuration from `ai_provider_openai` through the Key
   module.
2. `future_ai.openai_key_id`, interpreted as a Drupal Key id.
3. `$settings['emerging_digital_chatbot.openai_api_key']`.
4. `EMERGING_DIGITAL_CHATBOT_OPENAI_API_KEY`.
5. `OPENAI_API_KEY`.

The default block hides the widget on contact pages so it does not cover the
human contact form.

## Architecture

- `ChatbotBlock` renders a cacheable Drupal block and attaches the widget
  library.
- `ChatbotConfig` normalizes Config API values, language selection and path
  visibility.
- `ChatbotEndpointController` exposes a prepared POST endpoint for later server
  conversation handling, with CSRF protection, no-store responses and flood
  limiting.
- `ChatbotPayloadSanitizer` keeps only minimal scalar visitor input and blocks
  obvious sensitive data before any provider call.
- `FutureAiGatewayInterface` defines the server-side AI boundary.
- `OpenAiResponsesGateway` prepares OpenAI Responses API calls with `store:
  false`, no tools, no conversation state and prompt/context limits.
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
multilingual behavior. The frontend never receives prompts or API keys.

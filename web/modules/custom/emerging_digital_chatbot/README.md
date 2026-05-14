# Emerging Digital Chatbot

Guided Drupal chatbot MVP for contact orientation and light qualification.

## Scope

This module provides a discreet floating widget through a Drupal block. The MVP
uses quick choices, guarded responses and CTA links only. It does not generate
free-form AI answers, call OpenAI, calculate prices, produce quotes, or store
conversation history.

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
- future prompt/version metadata.

The default block hides the widget on contact pages so it does not cover the
human contact form.

## Architecture

- `ChatbotBlock` renders a cacheable Drupal block and attaches the widget
  library.
- `ChatbotConfig` normalizes Config API values, language selection and path
  visibility.
- `ChatbotEndpointController` exposes a prepared POST endpoint for later server
  conversation handling.
- `FutureAiGatewayInterface` defines the future AI boundary.
- `NullFutureAiGateway` guarantees that the MVP never calls an external AI
  provider.
- `js/chatbot-widget.js` is vanilla JS with keyboard support and a focus trap.
- `css/chatbot-widget.css` keeps the visual layer lightweight and module-owned.

## Limits of the MVP

The widget guides visitors toward public pages and human contact. It deliberately
avoids pricing, binding promises, autonomous decisions, advanced analytics,
conversation memory, CRM integration and OpenAI calls.

Future OpenAI work should remain server-side, use the prepared gateway boundary,
version prompts, limit retrieval to approved public pages, and keep personal data
out of long-term logs by default.

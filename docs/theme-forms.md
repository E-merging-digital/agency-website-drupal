# Theme forms

Ticket: #331

## Perimeter

The Emerging Digital theme styles Drupal core system forms on public pages.
The theme adds the `ed-system-form` class in
`emerging_digital_preprocess_form()` only when the current route is not an
admin route. CSS also recognizes the native Drupal core form classes so the
visual treatment remains effective before a cache clear.

Covered form IDs:

- `user_login_form`
- `user_pass`
- `user_register_form`
- `search_form`
- `search_block_form`

Webform forms and business forms are intentionally excluded. Contact Webform
styling remains handled by the existing `.webform-submission-form` and
`.ed-section__content--contact-form` selectors.

## Styling rules

System form CSS is scoped to `.ed-system-form` in
`web/themes/custom/emerging_digital/css/components.css`.

The rules cover readable width, labels, inputs, descriptions, errors, submit
actions, details elements and mobile layout. They reuse the theme variables for
colors, spacing, radius, focus rings and buttons.

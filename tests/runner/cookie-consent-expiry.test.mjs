import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../web/modules/custom/emerging_digital_cookie_consent/js/cookie-consent.js', import.meta.url), 'utf8');

function render(lang, stored, currentTime) {
  const elements = new Map();
  const values = new Map();
  if (stored !== null) {
    values.set('ed_cookie_consent_v1', JSON.stringify(stored));
  }
  const now = Date.parse(currentTime);
  class Clock extends Date {
    constructor(...args) {
      if (args.length) super(...args);
      else super(now);
    }
    static now() { return now; }
  }
  const document = {
    documentElement: { lang },
    getElementById: (id) => elements.get(id) ?? null,
    querySelectorAll: () => [],
    querySelector: () => null,
    createElement() {
      const classes = new Set();
      return {
        id: '', dataset: {}, listeners: {}, isConnected: true,
        classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name), contains: (name) => classes.has(name) },
        addEventListener(name, handler) { this.listeners[name] = handler; },
        removeEventListener() {},
        querySelector: () => ({ checked: false }),
        querySelectorAll: () => [],
        focus() {},
        remove() { elements.delete(this.id); },
      };
    },
    body: { appendChild(element) { elements.set(element.id, element); } },
  };
  const window = {
    localStorage: {
      getItem: (key) => values.get(key) ?? null,
      setItem: (key, value) => values.set(key, value),
    },
    addEventListener() {},
    dispatchEvent() {},
  };
  const Drupal = { behaviors: {} };
  vm.runInNewContext(source, {
    Drupal, once: () => [document.body], drupalSettings: {}, document,
    window, Date: Clock, CustomEvent: class {},
  });
  Drupal.behaviors.emergingDigitalCookieConsent.attach(document);
  return { elements, values };
}
const accepted = (updatedAt, external = true) => ({ necessary: true, external, updatedAt });
const hasBanner = (state) => state.elements.has('ed-cookie-banner');
const hasShortcut = (state) => state.elements.has('ed-cookie-preferences-shortcut');

test('six calendar months: before / exact / after, in FR, EN, NL', () => {
  for (const lang of ['fr', 'en', 'nl']) {
    assert.equal(hasBanner(render(lang, accepted('2030-01-31T12:00:00.000Z'), '2030-07-31T11:59:59.999Z')), false);
    assert.equal(hasBanner(render(lang, accepted('2030-01-31T12:00:00.000Z'), '2030-07-31T12:00:00.000Z')), true);
    assert.equal(hasBanner(render(lang, accepted('2030-01-31T12:00:00.000Z'), '2030-07-31T12:00:00.001Z')), true);
    assert.equal(hasBanner(render(lang, accepted('2027-08-31T12:00:00.000Z'), '2028-02-28T12:00:00.000Z')), false);
    assert.equal(hasBanner(render(lang, accepted('2027-08-31T12:00:00.000Z'), '2028-02-29T12:00:00.000Z')), true);
  }
});

test('missing, malformed and future timestamps never authorize external content', () => {
  const now = '2030-03-01T12:00:00.000Z';
  for (const payload of [
    null, {}, { necessary: true, external: true },
    accepted('not-a-date'), accepted('2030-03-01T12:00:00.001Z'),
    { necessary: true, external: 'false', updatedAt: '2030-02-01T00:00:00.000Z' },
    { necessary: false, external: true, updatedAt: '2030-02-01T00:00:00.000Z' },
  ]) {
    assert.equal(hasBanner(render('fr', payload, now)), true);
  }
});

test('both acceptance and refusal remain editable in all three languages', () => {
  for (const lang of ['fr', 'en', 'nl']) {
    const previous = render(lang, accepted('2030-02-01T12:00:00.000Z', false), '2030-03-01T12:00:00.000Z');
    assert.equal(hasShortcut(previous), true);
    assert.equal(hasBanner(previous), false);
    for (const [action, external] of [['accept', true], ['reject', false]]) {
      const state = render(lang, null, '2030-03-01T12:00:00.000Z');
      const banner = state.elements.get('ed-cookie-banner');
      banner.listeners.click({ target: { dataset: { edCookieAction: action } } });
      assert.equal(hasBanner(state), false);
      assert.equal(hasShortcut(state), true);
      const saved = JSON.parse(state.values.get('ed_cookie_consent_v1'));
      assert.equal(saved.external, external);
      assert.equal(saved.updatedAt, '2030-03-01T12:00:00.000Z');
      state.elements.get('ed-cookie-preferences-shortcut').listeners.click();
      assert.equal(state.elements.get('ed-cookie-preferences-modal').classList.contains('is-open'), true);
    }
  }
});

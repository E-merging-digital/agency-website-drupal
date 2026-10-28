/** Agency #1470 — one-pass explanatory section reveals, no autoplay loop. */
(function (Drupal) {
  'use strict';
  Drupal.behaviors.agencyHomeV2 = {
    attach(context) {
      const root = document.querySelector('[data-agency-v2]');
      if (!root || root.dataset.agencyV2Ready === 'true') return;
      root.dataset.agencyV2Ready = 'true';
      if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      const targets = Array.from(root.querySelectorAll('[data-agency-reveal]'));
      const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('agency-v2-is-visible');
          observer.unobserve(entry.target);
        });
      }, { threshold: .08, rootMargin: '0px 0px -25px 0px' });
      // The default state is visible when JavaScript or motion support is absent.
      targets.forEach(target => observer.observe(target));
      root.classList.add('agency-v2-motion-ready');
    }
  };
})(Drupal);

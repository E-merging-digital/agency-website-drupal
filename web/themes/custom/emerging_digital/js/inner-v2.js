/** Agency #1480 — accessible, automatic entry motion for public inner pages. */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.agencyInnerPageV2 = {
    attach() {
      const page = document.querySelector('.page-main:not(.page-main--front)');
      if (!page || page.dataset.edSiteMotionInitialized) {
        return;
      }
      page.dataset.edSiteMotionInitialized = 'true';

      // Content remains fully visible if JS/IntersectionObserver is unavailable,
      // and whenever the visitor requests reduced motion.
      if (!('IntersectionObserver' in window) ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }

      const targets = page.querySelectorAll([
        '.strategic-page .ed-section:not(.ed-section--hero)',
        '.ed-card',
        '.ed-service-group',
        '.ed-detail-page__hero',
        '.ed-detail-page__references',
        '.ed-qualification-cta',
      ].join(', '));
      if (!targets.length) {
        return;
      }

      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) {
            return;
          }
          entry.target.classList.add('ed-site-is-visible');
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.06, rootMargin: '0px 0px -25px 0px' });

      targets.forEach((target) => {
        target.setAttribute('data-ed-site-reveal', '');
        observer.observe(target);
      });
      page.classList.add('ed-site-motion-ready');
    },
  };
})(Drupal);

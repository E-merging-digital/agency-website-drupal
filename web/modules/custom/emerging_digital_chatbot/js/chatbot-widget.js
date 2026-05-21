(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.emergingDigitalChatbot = {
    attach(context) {
      once('emerging-digital-chatbot', '.ed-chatbot', context).forEach((widget) => {
        const id = widget.getAttribute('data-chatbot-id');
        const settings = drupalSettings.emergingDigitalChatbot && drupalSettings.emergingDigitalChatbot[id];

        if (!settings || !settings.payload || !settings.payload.messages) {
          return;
        }

        const payload = settings.payload;
        const messages = payload.messages;
        const launcher = widget.querySelector('.ed-chatbot__launcher');
        const launcherLabel = widget.querySelector('.ed-chatbot__launcher-label');
        const panel = widget.querySelector('.ed-chatbot__panel');
        const closeButton = widget.querySelector('.ed-chatbot__close');
        const resetButton = widget.querySelector('.ed-chatbot__reset');
        const body = widget.querySelector('[data-chatbot-body]');
        const messageList = widget.querySelector('[data-chatbot-messages]');
        const choices = widget.querySelector('[data-chatbot-choices]');
        const ctas = widget.querySelector('[data-chatbot-ctas]');
        const privacy = widget.querySelector('[data-chatbot-privacy]');

        if (!launcher || !panel || !closeButton || !resetButton || !body || !messageList || !choices || !ctas || !privacy) {
          return;
        }

        if (settings.launcherVariant === 'compact' && launcherLabel) {
          launcherLabel.classList.add('visually-hidden');
        }

        privacy.textContent = messages.privacy_label || 'Privacy';
        privacy.setAttribute('href', messages.privacy_path || '#');

        const focusableSelector = [
          'a[href]',
          'button:not([disabled])',
          'textarea:not([disabled])',
          'input:not([disabled])',
          'select:not([disabled])',
          'summary',
          '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        const openPanel = () => {
          const transparency = widget.querySelector('.ed-chatbot__transparency');
          if (transparency instanceof HTMLDetailsElement) {
            transparency.open = false;
          }

          panel.hidden = false;
          launcher.setAttribute('aria-expanded', 'true');
          widget.classList.add('is-open');
          closeButton.focus();
        };

        const closePanel = () => {
          panel.hidden = true;
          launcher.setAttribute('aria-expanded', 'false');
          widget.classList.remove('is-open');
          launcher.focus();
        };

        const clear = (element) => {
          while (element.firstChild) {
            element.removeChild(element.firstChild);
          }
        };

        const addMessage = (text, type) => {
          const item = document.createElement('p');
          item.className = `ed-chatbot__message ed-chatbot__message--${type}`;
          item.textContent = text;
          messageList.appendChild(item);
          body.scrollTop = body.scrollHeight;
        };

        const addTransparency = (text) => {
          if (!text) {
            return;
          }

          const details = document.createElement('details');
          details.className = 'ed-chatbot__transparency';

          const summary = document.createElement('summary');
          summary.className = 'ed-chatbot__transparency-summary';
          summary.textContent = payload.langcode === 'fr'
            ? 'Assistant guidé - pas de conservation durable'
            : 'Guided assistant - no long-term storage';
          details.appendChild(summary);

          const detail = document.createElement('p');
          detail.className = 'ed-chatbot__transparency-detail';
          detail.textContent = text;
          details.appendChild(detail);

          messageList.appendChild(details);
          body.scrollTop = body.scrollHeight;
        };

        const createButton = (label, flowId) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'ed-chatbot__choice';
          button.textContent = label;
          button.setAttribute('data-flow-id', flowId);
          return button;
        };

        const createCta = (label, path, isPrimary) => {
          const link = document.createElement('a');
          link.className = isPrimary ? 'ed-chatbot__cta ed-chatbot__cta--primary' : 'ed-chatbot__cta';
          link.href = path;
          link.textContent = label;
          return link;
        };

        const getBackLabel = () => (payload.langcode === 'fr' ? 'Changer de besoin' : 'Change need');

        const getChoiceLabel = () => messages.choose_label || (payload.langcode === 'fr' ? 'Que souhaitez-vous faire ?' : 'What would you like to do?');

        const getSelectedLabel = () => (payload.langcode === 'fr' ? 'Besoin sélectionné' : 'Selected need');

        const isContactCta = (cta) => typeof cta.path === 'string' && cta.path.endsWith('/contact');

        const getOrderedCtas = (flow) => {
          const flowCtas = Array.isArray(flow.ctas) ? flow.ctas.filter((cta) => cta.label && cta.path) : [];
          const contactCtas = flowCtas.filter(isContactCta);
          const secondaryCtas = flowCtas.filter((cta) => !isContactCta(cta));
          return [...contactCtas, ...secondaryCtas].slice(0, 4);
        };

        const renderCtas = (flow) => {
          clear(ctas);
          if (!flow.ctas || !flow.ctas.length) {
            ctas.hidden = true;
            return;
          }

          choices.hidden = true;
          ctas.hidden = false;

          const back = document.createElement('button');
          back.type = 'button';
          back.className = 'ed-chatbot__back';
          back.textContent = getBackLabel();
          back.setAttribute('data-chatbot-back', 'true');
          ctas.appendChild(back);

          const selected = document.createElement('p');
          selected.className = 'ed-chatbot__selected';
          selected.textContent = `${getSelectedLabel()}: ${flow.label}`;
          ctas.appendChild(selected);

          const label = document.createElement('p');
          label.className = 'ed-chatbot__section-label';
          label.textContent = messages.cta_label || 'Suggested actions';
          ctas.appendChild(label);

          const list = document.createElement('div');
          list.className = 'ed-chatbot__cta-list';
          getOrderedCtas(flow).forEach((cta) => {
            list.appendChild(createCta(cta.label, cta.path, isContactCta(cta)));
          });
          ctas.appendChild(list);
          body.scrollTop = 0;
        };

        const renderChoices = () => {
          clear(choices);
          clear(ctas);
          choices.hidden = false;
          ctas.hidden = true;

          const label = document.createElement('p');
          label.className = 'ed-chatbot__section-label';
          label.textContent = getChoiceLabel();
          choices.appendChild(label);

          const list = document.createElement('div');
          list.className = 'ed-chatbot__choice-list';
          Object.keys(messages.flows || {}).forEach((flowId) => {
            const flow = messages.flows[flowId];
            if (flow && flow.label) {
              list.appendChild(createButton(flow.label, flowId));
            }
          });
          choices.appendChild(list);
          body.scrollTop = 0;
        };

        const renderWelcome = () => {
          clear(messageList);
          addMessage(messages.intro || '', 'assistant');
          addTransparency(messages.transparency || '');
        };

        const renderFlowResponse = (flow) => {
          clear(messageList);
          addMessage(flow.response, 'assistant');
        };

        const reset = () => {
          widget.setAttribute('data-chatbot-step', 'choices');
          renderWelcome();
          renderChoices();
        };

        choices.addEventListener('click', (event) => {
          const target = event.target;
          if (!(target instanceof HTMLButtonElement)) {
            return;
          }

          const flowId = target.getAttribute('data-flow-id');
          const flow = flowId && messages.flows ? messages.flows[flowId] : null;
          if (!flow) {
            return;
          }

          widget.setAttribute('data-chatbot-step', 'actions');
          renderFlowResponse(flow);
          renderCtas(flow);
          ctas.querySelector('a, button')?.focus();
        });

        ctas.addEventListener('click', (event) => {
          const target = event.target;
          if (!(target instanceof HTMLButtonElement) || !target.hasAttribute('data-chatbot-back')) {
            return;
          }

          widget.setAttribute('data-chatbot-step', 'choices');
          renderWelcome();
          renderChoices();
          choices.querySelector('button')?.focus();
        });

        launcher.addEventListener('click', () => {
          if (panel.hidden) {
            openPanel();
            return;
          }
          closePanel();
        });

        closeButton.addEventListener('click', closePanel);
        resetButton.addEventListener('click', reset);

        panel.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
            return;
          }

          if (event.key !== 'Tab') {
            return;
          }

          const focusable = Array.from(panel.querySelectorAll(focusableSelector))
            .filter((element) => element instanceof HTMLElement && element.offsetParent !== null);

          if (!focusable.length) {
            event.preventDefault();
            return;
          }

          const first = focusable[0];
          const last = focusable[focusable.length - 1];

          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          }
          else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        });

        reset();
      });
    },
  };
})(Drupal, drupalSettings, once);

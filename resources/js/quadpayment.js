/*!
 * QuadPayment — Payload secure card fields inside any form.
 *
 * Flow on submit of the surrounding <form>:
 *   1. the form's own HTML5 validation runs (your fields + name/ZIP)
 *   2. Payload.js processes the card inside its secure iframes
 *   3. on success the transaction id is written to quadpayment_transaction
 *      and the form is submitted normally to your controller, which calls
 *      QuadPayment::verify($request)
 *
 * Card data never passes through this script or your server.
 *
 * DOM events (bubble from the component root): quadpayment:ready,
 * quadpayment:success, quadpayment:declined, quadpayment:locked,
 * quadpayment:blocked, quadpayment:error.
 */
(function () {
  'use strict';

  if (window.QuadPayment) {
    return;
  }

  var SUCCESS_EVENTS = ['success', 'processed', 'authorized'];
  var instances = [];

  function init(root) {
    if (root.__quadpayment) {
      return root.__quadpayment;
    }

    var form = root.closest('form');

    if (!form) {
      console.error('[quadpayment] <x-quadpayment::card-fields> must be placed inside a <form>.');
      return null;
    }

    var ui = {
      error: root.querySelector('[data-qp-error]'),
      gate: root.querySelector('[data-qp-gate]'),
      reveal: root.querySelector('[data-qp-reveal]'),
      fields: root.querySelector('[data-qp-fields]'),
      transaction: root.querySelector('[data-qp-transaction]'),
      checkout: root.querySelector('input[name="quadpayment_checkout"]').value
    };

    var state = {
      token: root.getAttribute('data-token'),
      payloadForm: null,
      phase: 'idle', // idle | processing | done | dead
      challenge: null,
      busyLabels: new Map()
    };

    var instance = { root: root, form: form, state: state, requestToken: requestToken };
    root.__quadpayment = instance;
    instances.push(instance);

    // ---- helpers --------------------------------------------------------

    function emit(name, detail) {
      root.dispatchEvent(new CustomEvent('quadpayment:' + name, { detail: detail || {}, bubbles: true }));
    }

    function showError(message) {
      if (!ui.error) return;
      ui.error.textContent = message;
      ui.error.hidden = false;
      ui.error.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideError() {
      if (ui.error) ui.error.hidden = true;
    }

    function submitButtons() {
      return Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'));
    }

    function setBusy(busy, label) {
      submitButtons().forEach(function (button) {
        if (busy) {
          if (!state.busyLabels.has(button)) {
            state.busyLabels.set(button, button.tagName === 'INPUT' ? button.value : button.textContent);
          }
          button.disabled = true;
          if (label) {
            if (button.tagName === 'INPUT') button.value = label; else button.textContent = label;
          }
        } else if (state.phase !== 'dead') {
          button.disabled = false;
          if (state.busyLabels.has(button)) {
            var original = state.busyLabels.get(button);
            if (button.tagName === 'INPUT') button.value = original; else button.textContent = original;
          }
        }
      });
      if (!busy) state.busyLabels.clear();
    }

    function kill(message) {
      state.phase = 'dead';
      showError(message);
      setBusy(true);
      if (ui.gate) ui.gate.hidden = true;
      if (ui.fields) ui.fields.hidden = true;
    }

    function post(url, body) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': root.getAttribute('data-csrf')
        },
        body: JSON.stringify(body)
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          return { ok: response.ok, status: response.status, data: data };
        });
      });
    }

    function transactionIdFrom(evt) {
      evt = evt || {};
      var hidden = form.querySelector('input[name="pl_transaction_id"]');
      return evt.transaction_id || evt.id || (evt.transaction && evt.transaction.id) || (evt.data && evt.data.id) || (hidden && hidden.value) || null;
    }

    function resetChallenge() {
      state.challenge = null;
      if (window.turnstile && root.querySelector('[data-qp-challenge]')) {
        try { window.turnstile.reset(root.querySelector('[data-qp-challenge]')); } catch (e) { /* ignore */ }
      }
    }

    // ---- token + mounting -----------------------------------------------

    function requestToken() {
      if (state.payloadForm || state.phase === 'dead') return;
      if (state.token) return mount(state.token);

      var body = { quadpayment_checkout: ui.checkout };
      var field = root.getAttribute('data-challenge-field');

      if (field) {
        var input = root.querySelector('[name="' + field + '"]');
        var response = state.challenge || (input && input.value);
        if (!response) {
          showError('Please complete the security check first.');
          return;
        }
        body[field] = response;
      }

      if (ui.reveal) ui.reveal.disabled = true;
      hideError();

      post(root.getAttribute('data-token-url'), body).then(function (res) {
        if (ui.reveal) ui.reveal.disabled = false;

        if (!res.ok || !res.data.token) {
          resetChallenge();
          var message = res.data.message || 'Payments are temporarily unavailable. Please try again shortly.';
          if (res.data.terminal) {
            kill(message);
          } else {
            showError(message);
          }
          emit('blocked', res.data);
          return;
        }

        mount(res.data.token);
      }).catch(function () {
        if (ui.reveal) ui.reveal.disabled = false;
        resetChallenge();
        showError('Connection problem. Please check your connection and try again.');
      });
    }

    function mount(token) {
      if (typeof window.Payload !== 'function') {
        showError('The secure payment form could not be loaded. Please disable content blockers and reload the page.');
        emit('error', { reason: 'payload_js_missing' });
        return;
      }

      state.token = token;
      window.Payload(token);
      form.setAttribute('pl-form', 'payment');

      state.payloadForm = new window.Payload.Form({
        form: form,
        autosubmit: false,
        preventDefaultOnSubmit: true
      });

      var succeeded = false;

      SUCCESS_EVENTS.forEach(function (name) {
        state.payloadForm.on(name, function (evt) {
          if (succeeded) return;
          succeeded = true;
          onSuccess(evt);
        });
      });

      state.payloadForm.on('declined', onDeclined);
      state.payloadForm.on('error', onError);
      state.payloadForm.on('invalid', function () {
        state.phase = 'idle';
        setBusy(false);
        showError('Please check your card details and try again.');
      });

      if (ui.gate) ui.gate.hidden = true;
      if (ui.fields) ui.fields.hidden = false;
      emit('ready');
    }

    // ---- Payload outcomes -----------------------------------------------

    function onSuccess(evt) {
      var id = transactionIdFrom(evt);

      if (!id) {
        state.phase = 'idle';
        setBusy(false);
        showError('Your payment went through but we could not confirm it. Please contact us before trying again.');
        emit('error', { reason: 'missing_transaction_id' });
        return;
      }

      ui.transaction.value = id;
      state.phase = 'done';
      setBusy(true, 'Finishing…');
      emit('success', { transactionId: id });

      // Native submit: skips submit listeners (ours and Payload's), so the
      // form goes straight to your controller with all its fields.
      HTMLFormElement.prototype.submit.call(form);
    }

    function onDeclined(evt) {
      state.phase = 'idle';
      setBusy(false);
      var reason = (evt && (evt.decline_message || evt.status_message || evt.message)) || 'Please check the details or try another card.';
      showError('Your card was declined. ' + reason);
      emit('declined', evt || {});
      report(evt);
    }

    function onError(evt) {
      state.phase = 'idle';
      setBusy(false);
      showError('We could not process your card. Please check the details and try again.');
      emit('error', evt || {});
      if (transactionIdFrom(evt)) report(evt);
    }

    // The server re-fetches the transaction from Payload; this is a hint
    // that makes the per-checkout limit immediate.
    function report(evt) {
      post(root.getAttribute('data-declined-url'), {
        quadpayment_checkout: ui.checkout,
        transaction_id: transactionIdFrom(evt)
      }).then(function (res) {
        var data = res.data || {};
        if (data.locked) {
          kill(data.message || 'Too many failed payment attempts. Please reload the page to start over.');
          emit('locked', data);
        } else if (data.remaining === 1) {
          showError('Your card was declined. You have 1 attempt remaining.');
        }
      }).catch(function () { /* the webhook still counts it */ });
    }

    // ---- submit interception --------------------------------------------

    form.addEventListener('submit', function (e) {
      if (state.phase === 'done') {
        return; // onSuccess did a native submit; let it reach the server
      }

      if (state.phase !== 'idle') {
        // A card is already being processed — block duplicate submits, but
        // never stop propagation: the in-flight submit still belongs to Payload.
        e.preventDefault();
        return;
      }

      if (!state.payloadForm) {
        e.preventDefault();
        e.stopImmediatePropagation();
        showError(ui.gate ? 'Please enter your card details first.' : 'The payment form is still loading. Please wait a moment.');
        if (ui.gate && !root.querySelector('[data-qp-challenge]')) requestToken();
        return;
      }

      if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
      }

      // Hand Payload.js the user's real, trusted submit event. This handler
      // runs first (capture phase, registered before Payload's handler when
      // the form was mounted); once our checks pass we let the SAME event
      // continue to Payload, which tokenizes the card and fires
      // success/declined. We must NOT re-dispatch a synthetic Event('submit')
      // here: Payload.js ignores an untrusted, hand-built event in Firefox, so
      // the card was never processed and the form hung. We also must NOT
      // preventDefault — Payload stops the native navigation itself
      // (preventDefaultOnSubmit: true) and needs the event to reach it.
      hideError();
      setBusy(true, 'Processing…');
      state.phase = 'processing';
    }, true);

    // ---- wiring ----------------------------------------------------------

    if (root.hasAttribute('data-authorized')) {
      state.phase = 'done';
      return instance;
    }

    if (root.hasAttribute('data-unavailable')) {
      kill(ui.error && ui.error.textContent ? ui.error.textContent : 'Payments are unavailable.');
      return instance;
    }

    if (ui.reveal) {
      ui.reveal.addEventListener('click', requestToken);
    }

    if (state.token) {
      mount(state.token);
    } else {
      // The challenge may have been solved before this script booted.
      var field = root.getAttribute('data-challenge-field');
      var solved = field && root.querySelector('[name="' + field + '"]');
      if (solved && solved.value) requestToken();
    }

    return instance;
  }

  // Cloudflare Turnstile data-callback.
  window.quadpaymentChallengeSolved = function (response) {
    instances.forEach(function (instance) {
      if (!instance.state.payloadForm && instance.state.phase === 'idle') {
        instance.state.challenge = response;
        instance.requestToken();
      }
    });
  };

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-quadpayment]'), init);
  }

  window.QuadPayment = { init: init, boot: boot, instances: instances };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

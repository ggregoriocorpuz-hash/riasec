/* ═══════════════════════════════════════════════════════════
   RIASEC AI Assistant — Floating Chatbot Module
   Available on ALL screens (general mode before test,
   personalized mode after test).
   Auto-initialises on DOMContentLoaded.
   Exposes: window.RIASECAssistant.setResults(results, scores)
            window.RIASECAssistant.resetResults()
═══════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  /* ─── State ────────────────────────────────────────────── */
  var aiState = {
    initialized:    false,
    listenersSetup: false,
    panelOpen:      false,
    hasResults:     false,
    results:        null,
    scores:         null,
    messages:       [],
    loading:        false,
  };

  /* ─── Config ───────────────────────────────────────────── */
  // Derive the API path from this script's own URL so the assistant
  // works no matter which screen/directory the page is served from.
  // VS Code's Live Server (port 5500) cannot run PHP, so chat requests
  // from a Live Server page are sent to Apache instead — the same rule
  // script.js applies to the other endpoints, and config/api_cors.php
  // already allows the localhost:5500 origin.
  var CHAT_API = (function () {
    var isLiveServer = window.location.port === '5500' &&
      (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    if (isLiveServer) {
      return 'http://localhost/riasec/api/chat.php';
    }
    var script = document.currentScript;
    if (script && script.src) {
      return script.src.replace(/\/[^/]*$/, '') + '/api/chat.php';
    }
    return window.location.pathname.replace(/\/[^/]*$/, '') + '/api/chat.php';
  })();

  // Local-dev hints only apply when the site itself is served from
  // XAMPP/Live Server — production (e.g. Railway) gets a generic message.
  var IS_LOCAL_HOST = window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1';
  function localOrGeneric(localText) {
    return IS_LOCAL_HOST ? localText : 'Please try again later or contact the administrator.';
  }

  var GENERAL_QUESTIONS = [
    'What is the RIASEC test?',
    'How does the RIASEC assessment work?',
    'What do the RIASEC categories mean?',
    'How can RIASEC help me choose a career?',
    'What should I expect during the test?',
    'How long does the assessment take?',
  ];

  var PERSONALIZED_QUESTIONS = [
    'What does my RIASEC result mean?',
    'What college courses are suitable for me?',
    'What careers match my interests?',
    'What schools near me offer these courses?',
    'What is the difference between my main and opposite category?',
    'What are my possible career options?',
  ];

  /* ─── DOM Helpers ──────────────────────────────────────── */
  function $(id) { return document.getElementById(id); }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function scrollToBottom() {
    var el = $('ai-chat-messages');
    if (el) el.scrollTop = el.scrollHeight;
  }

  /* ─── Panel Toggle ─────────────────────────────────────── */
  function openPanel() {
    var panel = $('ai-chat-panel');
    var btn   = $('ai-float-btn');
    if (!panel) return;

    panel.classList.remove('d-none');
    if (btn) {
      btn.classList.add('d-none');
      btn.setAttribute('aria-expanded', 'true');
    }
    aiState.panelOpen = true;
    scrollToBottom();

    // Focus input after animation
    setTimeout(function () {
      var input = $('ai-message-input');
      if (input) input.focus();
    }, 200);
  }

  function closePanel() {
    var panel = $('ai-chat-panel');
    var btn   = $('ai-float-btn');
    if (!panel) return;

    panel.classList.add('d-none');
    if (btn) {
      btn.classList.remove('d-none');
      btn.setAttribute('aria-expanded', 'false');
    }
    aiState.panelOpen = false;
    if (btn) btn.focus();
  }

  function togglePanel() {
    if (aiState.panelOpen) closePanel();
    else openPanel();
  }

  /* ─── Mode Label ────────────────────────────────────────── */
  function updateModeLabel() {
    var el = $('ai-panel-mode');
    if (!el) return;
    el.textContent = aiState.hasResults
      ? 'Personalized \u2014 using your RIASEC results'
      : 'Ask about RIASEC, careers, and schools';
  }

  /* ─── Message Formatting ────────────────────────────────── */
  function formatMessage(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
  }

  function addUserMessage(text) {
    aiState.messages.push({ role: 'user', content: text });

    var el = $('ai-chat-messages');
    if (!el) return;

    var msg = document.createElement('div');
    msg.className = 'ai-message ai-message-user';
    msg.innerHTML = '<div class="ai-message-bubble">' + formatMessage(text) + '</div>';
    el.appendChild(msg);
    scrollToBottom();
  }

  function addBotMessage(text, schools) {
    aiState.messages.push({ role: 'assistant', content: text });

    var el = $('ai-chat-messages');
    if (!el) return;

    var msg = document.createElement('div');
    msg.className = 'ai-message ai-message-bot';

    var html =
      '<div class="ai-message-avatar"><i class="bi bi-robot"></i></div>' +
      '<div class="ai-message-content">';

    if (text && text.trim()) {
      html += '<div class="ai-message-bubble">' + formatMessage(text) + '</div>';
    }

    if (schools && schools.length > 0) {
      html += '<div class="ai-school-cards">';
      schools.forEach(function (s) { html += renderSchoolCard(s); });
      html += '</div>';
    }

    html += '</div>';
    msg.innerHTML = html;
    el.appendChild(msg);
    scrollToBottom();
  }

  /* ─── School Card ──────────────────────────────────────── */
  function renderSchoolCard(school) {
    var html = '<div class="ai-school-card">';
    html += '<div class="ai-school-card-header">';
    html += '<i class="bi bi-building-fill"></i>';
    html += '<span class="ai-school-name">' + escapeHtml(school.school_name) + '</span>';
    html += '</div><div class="ai-school-card-body">';

    if (school.school_address) {
      html += '<div class="ai-school-detail"><i class="bi bi-geo-alt-fill"></i>';
      html += '<span>' + escapeHtml(school.school_address) + ', ' +
              escapeHtml(school.city) + ', ' + escapeHtml(school.province) + '</span></div>';
    }
    if (school.contact_number) {
      html += '<div class="ai-school-detail"><i class="bi bi-telephone-fill"></i>';
      html += '<span>' + escapeHtml(school.contact_number) + '</span></div>';
    }
    if (school.email) {
      html += '<div class="ai-school-detail"><i class="bi bi-envelope-fill"></i>';
      html += '<a href="mailto:' + escapeHtml(school.email) + '">' + escapeHtml(school.email) + '</a></div>';
    }
    if (school.official_website) {
      html += '<div class="ai-school-detail"><i class="bi bi-globe"></i>';
      html += '<a href="' + escapeHtml(school.official_website) + '" target="_blank" rel="noopener">' +
              escapeHtml(school.official_website) + '</a></div>';
    }
    if (school.courses_offered && school.courses_offered.length > 0) {
      html += '<div class="ai-school-detail ai-school-courses"><i class="bi bi-book-fill"></i>';
      html += '<div class="ai-course-tags">';
      school.courses_offered.forEach(function (c) {
        html += '<span class="ai-course-tag">' + escapeHtml(c) + '</span>';
      });
      html += '</div></div>';
    }
    if (school.admission_information) {
      html += '<div class="ai-school-detail"><i class="bi bi-info-circle-fill"></i>';
      html += '<span>' + escapeHtml(school.admission_information) + '</span></div>';
    }

    html += '</div></div>';
    return html;
  }

  /* ─── Typing Indicator ─────────────────────────────────── */
  function showTyping() {
    var el = $('ai-chat-messages');
    if (!el) return;

    var msg = document.createElement('div');
    msg.className = 'ai-message ai-message-bot';
    msg.id = 'ai-typing-indicator';
    msg.innerHTML =
      '<div class="ai-message-avatar"><i class="bi bi-robot"></i></div>' +
      '<div class="ai-message-content">' +
      '<div class="ai-message-bubble ai-typing-bubble">' +
      '<span class="ai-typing-dot"></span>' +
      '<span class="ai-typing-dot"></span>' +
      '<span class="ai-typing-dot"></span>' +
      '</div></div>';
    el.appendChild(msg);
    scrollToBottom();
  }

  function hideTyping() {
    var el = $('ai-typing-indicator');
    if (el) el.remove();
  }

  /* ─── Error Display ────────────────────────────────────── */
  function showError(msg) {
    var el = $('ai-error');
    if (el) {
      el.innerHTML = '<i class="bi bi-exclamation-circle-fill me-1"></i>' + escapeHtml(msg);
      el.classList.remove('d-none');
    }
    setTimeout(hideError, 6000);
  }

  function hideError() {
    var el = $('ai-error');
    if (el) el.classList.add('d-none');
  }

  /* ─── Suggested Questions ──────────────────────────────── */
  function renderSuggestions() {
    var el = $('ai-suggestions');
    if (!el) return;

    var questions = aiState.hasResults ? PERSONALIZED_QUESTIONS : GENERAL_QUESTIONS;

    el.innerHTML = '';
    questions.forEach(function (q) {
      var chip = document.createElement('button');
      chip.className = 'ai-suggestion-chip';
      chip.type = 'button';
      chip.textContent = q;
      chip.addEventListener('click', function () {
        sendMessage(q);
        hideSuggestions();
      });
      el.appendChild(chip);
    });
    el.classList.remove('d-none');
  }

  function hideSuggestions() {
    var el = $('ai-suggestions');
    if (el) el.classList.add('d-none');
  }

  /* ─── API Communication ────────────────────────────────── */
  function buildContext() {
    if (!aiState.results) return {};
    var sorted    = aiState.results.sorted || [];
    var topThree  = sorted.slice(0, 3).map(function (e) { return e[0]; });
    return {
      holland_code: topThree.join(''),
      top_three:    topThree,
      scores:        aiState.scores,
    };
  }

  function buildHistory() {
    return aiState.messages.slice(-10).map(function (m) {
      return { role: m.role, content: m.content };
    });
  }

  async function sendMessage(text) {
    text = (text || '').trim();
    if (aiState.loading || text === '') return;

    hideError();
    addUserMessage(text);

    var input = $('ai-message-input');
    if (input) input.value = '';

    aiState.loading = true;
    updateSendState();
    showTyping();

    try {
      var response = await fetch(CHAT_API, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          message:  text,
          context:  buildContext(),
          history:  buildHistory().slice(0, -1),
        }),
      });

      var data;
      try { data = await response.json(); }
      catch (e) { throw new Error('Invalid response from server.'); }

      hideTyping();

      if (!data.success) {
        if (data.configured === false) {
          addBotMessage(
            "The AI service is not yet configured. An administrator needs to " +
            "set the AI provider API key in Admin \u2192 Settings \u2192 AI Configuration " +
            "before I can answer questions."
          );
        } else if (response.status === 405 || response.status === 404) {
          showError(
            'The chat service is not reachable at this address (HTTP ' + response.status + '). ' +
            localOrGeneric('Open the site through XAMPP at http://localhost/riasec/')
          );
        } else {
          showError(data.error || 'Something went wrong. Please try again.');
        }
      } else {
        addBotMessage(data.response || '', data.schools || []);
      }
    } catch (err) {
      hideTyping();
      if (err && err.message === 'Invalid response from server.') {
        showError(
          'The server did not return chat data. ' +
          localOrGeneric(
            'Make sure the site is opened through XAMPP at http://localhost/riasec/ ' +
            '(a Live Server preview cannot run the chat API).'
          )
        );
      } else {
        showError('Network error. Please check your connection and try again.');
      }
    }

    aiState.loading = false;
    updateSendState();
  }

  function updateSendState() {
    var btn   = $('ai-send-btn');
    var input = $('ai-message-input');
    if (btn)   btn.disabled   = aiState.loading;
    if (input) input.disabled = aiState.loading;
  }

  /* ─── Event Listeners ──────────────────────────────────── */
  function setupEventListeners() {
    // Floating button → open panel
    var floatBtn = $('ai-float-btn');
    if (floatBtn) floatBtn.addEventListener('click', openPanel);

    // Close button → close panel
    var closeBtn = $('ai-chat-close');
    if (closeBtn) closeBtn.addEventListener('click', closePanel);

    // Send on Enter
    var input = $('ai-message-input');
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage(input.value);
        }
      });
    }

    // Send button
    var sendBtn = $('ai-send-btn');
    if (sendBtn) sendBtn.addEventListener('click', function () {
      sendMessage(input ? input.value : '');
    });

    // Close panel on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && aiState.panelOpen) closePanel();
    });
  }

  /* ─── Init (general mode — called on page load) ─────────── */
  function init() {
    aiState.initialized = true;
    aiState.hasResults  = false;
    aiState.results     = null;
    aiState.scores      = null;
    aiState.messages    = [];

    // Clear chat
    var messagesEl = $('ai-chat-messages');
    if (messagesEl) messagesEl.innerHTML = '';

    hideError();
    updateModeLabel();
    renderSuggestions();

    // General welcome message
    addBotMessage(
      "I'm your RIASEC AI Assistant! I can answer questions about the RIASEC " +
      "assessment, explain the six interest categories, and help you understand " +
      "how RIASEC can guide your career exploration. Once you complete the " +
      "assessment, I'll provide personalized guidance based on your results. " +
      "Feel free to ask me anything!"
    );

    if (!aiState.listenersSetup) {
      setupEventListeners();
      aiState.listenersSetup = true;
    }
  }

  /* ─── Set Results (personalized mode — after test) ──────── */
  function setResults(results, scores) {
    // Same results already loaded — just refresh the UI quietly.
    var alreadyLoaded =
      aiState.hasResults &&
      aiState.results &&
      results &&
      aiState.results.dominantLetter === results.dominantLetter;

    aiState.results    = results;
    aiState.scores     = scores;
    aiState.hasResults = true;

    updateModeLabel();
    renderSuggestions();

    if (alreadyLoaded) return;

    // Notify that personalized guidance is now available
    addBotMessage(
      "Your RIASEC results are now available! I can provide personalized guidance " +
      "based on your interests. Ask me about your results, suitable college courses, " +
      "career paths, or nearby schools."
    );

    // Flag the floating button so the student notices the update.
    // The chat panel is deliberately NOT opened here: on the results
    // screen the school locator is showing, and the panel must not cover
    // it or steal focus from the location search.
    var btn = $('ai-float-btn');
    if (btn && !aiState.panelOpen) {
      btn.classList.add('ai-float-notify');
      setTimeout(function () { btn.classList.remove('ai-float-notify'); }, 1400);
    }
  }

  /* ─── Reset Results (back to general mode — on retake) ─── */
  function resetResults() {
    var hadResults = aiState.hasResults;

    aiState.results    = null;
    aiState.scores     = null;
    aiState.hasResults = false;

    updateModeLabel();
    renderSuggestions();

    // Already in general mode — nothing personalized to clear.
    if (!hadResults) return;

    aiState.messages = [];

    // Clear chat
    var messagesEl = $('ai-chat-messages');
    if (messagesEl) messagesEl.innerHTML = '';

    hideError();

    addBotMessage(
      "Your assessment has been reset. I'm now in general mode — ask me about " +
      "RIASEC, the test process, or career interests. Complete the assessment " +
      "again for personalized guidance!"
    );
  }

  /* ─── Auto-initialise on DOMContentLoaded ───────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* ─── Export ───────────────────────────────────────────── */
  window.RIASECAssistant = {
    init:        init,
    setResults:  setResults,
    resetResults: resetResults,
  };
})();

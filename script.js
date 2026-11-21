/* ═══════════════════════════════════════════════════════════
   RIASEC Career Interest Assessment — Main Application Script
   Vanilla JavaScript · PHP/MySQL backend · localStorage for
   in-progress session only (not a substitute for the DB)
═══════════════════════════════════════════════════════════ */

'use strict';

/* ─── Constants ──────────────────────────────────────────── */
const STORAGE_KEY = 'riasec_session_v2';
const TOTAL_Q     = 48;

// Resolve the API base path from the current page URL.
// Works for http://localhost/riasec/, http://localhost/, or any subfolder.
const API_BASE   = window.location.pathname.replace(/\/[^/]*$/, '');  // e.g. /riasec
const isLiveServer = window.location.port === '5500'
  && ['localhost', '127.0.0.1'].includes(window.location.hostname);
const ASSESSMENT_API_BASE = isLiveServer
  ? 'http://localhost/riasec/api'
  : API_BASE + '/api';
const API_SUBMIT = ASSESSMENT_API_BASE + '/submit_assessment.php';
const API_CHECK  = ASSESSMENT_API_BASE + '/check_student.php';
const API_INQUIRIES = ASSESSMENT_API_BASE + '/inquiries.php';
const INQUIRY_VERSIONS_KEY = 'riasec_inquiry_versions_v1';
const INQUIRY_SUBMITTED_KEY = 'riasec_inquiry_submitted_v1';
const INQUIRY_SUCCESS_MESSAGE = 'Your inquiry and interview request were sent to your teacher. Use Track Inquiry on the Respondent Information page to see updates.';
let inquiryPollTimer = null;
let trackedInquiryVersions = {};

function localDateValue() {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function loadInquiryVersions() {
  try {
    const saved = JSON.parse(localStorage.getItem(INQUIRY_VERSIONS_KEY) || '{}');
    return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
  } catch (error) {
    console.warn('Inquiry update notifications may not persist in this browser.', error);
    return {};
  }
}

const SCORE_LABELS = {
  1: 'Strongly Dislike',
  2: 'Dislike',
  3: 'Neutral',
  4: 'Like',
  5: 'Strongly Like',
};

/* ─── 48 Questions (8 per RIASEC category) ───────────────── */
const ALL_QUESTIONS = [
  // ── Realistic (R) ────────────────────────────────────────
  { id: 'R1', category: 'R', text: 'I enjoy repairing things that are broken.' },
  { id: 'R2', category: 'R', text: 'I like working with tools, machines, or equipment.' },
  { id: 'R3', category: 'R', text: 'I enjoy building or assembling things with my hands.' },
  { id: 'R4', category: 'R', text: 'I like doing practical tasks that produce visible results.' },
  { id: 'R5', category: 'R', text: 'I enjoy working outdoors.' },
  { id: 'R6', category: 'R', text: 'I like learning how to operate machinery or technical equipment.' },
  { id: 'R7', category: 'R', text: 'I enjoy tasks that involve physical activity.' },
  { id: 'R8', category: 'R', text: 'I like figuring out how to fix mechanical or technical problems.' },
  // ── Investigative (I) ────────────────────────────────────
  { id: 'I1', category: 'I', text: 'I enjoy researching topics that interest me.' },
  { id: 'I2', category: 'I', text: 'I like solving challenging problems.' },
  { id: 'I3', category: 'I', text: 'I enjoy learning how things work.' },
  { id: 'I4', category: 'I', text: 'I like analyzing information before making decisions.' },
  { id: 'I5', category: 'I', text: 'I enjoy conducting experiments or testing ideas.' },
  { id: 'I6', category: 'I', text: 'I like working with scientific or technical information.' },
  { id: 'I7', category: 'I', text: 'I enjoy asking questions and searching for explanations.' },
  { id: 'I8', category: 'I', text: 'I like using logic to understand complicated situations.' },
  // ── Artistic (A) ─────────────────────────────────────────
  { id: 'A1', category: 'A', text: 'I enjoy drawing, painting, or creating visual art.' },
  { id: 'A2', category: 'A', text: 'I like writing stories, poems, or other creative pieces.' },
  { id: 'A3', category: 'A', text: 'I enjoy expressing myself through music, dance, or performance.' },
  { id: 'A4', category: 'A', text: 'I like designing things and coming up with original ideas.' },
  { id: 'A5', category: 'A', text: 'I enjoy activities that allow me to use my imagination.' },
  { id: 'A6', category: 'A', text: 'I like creating content that communicates a message or feeling.' },
  { id: 'A7', category: 'A', text: 'I enjoy experimenting with different styles and creative approaches.' },
  { id: 'A8', category: 'A', text: 'I like finding original ways to express my thoughts and ideas.' },
  // ── Social (S) ───────────────────────────────────────────
  { id: 'S1', category: 'S', text: 'I enjoy helping people solve their problems.' },
  { id: 'S2', category: 'S', text: 'I like teaching others how to do something.' },
  { id: 'S3', category: 'S', text: 'I enjoy listening to people and understanding their feelings.' },
  { id: 'S4', category: 'S', text: 'I like working with others toward a shared goal.' },
  { id: 'S5', category: 'S', text: 'I enjoy volunteering or participating in community activities.' },
  { id: 'S6', category: 'S', text: 'I like supporting people when they are facing difficulties.' },
  { id: 'S7', category: 'S', text: 'I enjoy explaining ideas in ways that help other people understand.' },
  { id: 'S8', category: 'S', text: 'I like cooperating with people and building positive relationships.' },
  // ── Enterprising (E) ─────────────────────────────────────
  { id: 'E1', category: 'E', text: 'I enjoy leading a group or team.' },
  { id: 'E2', category: 'E', text: 'I like persuading people to consider my ideas.' },
  { id: 'E3', category: 'E', text: 'I enjoy organizing projects or activities.' },
  { id: 'E4', category: 'E', text: 'I like selling products, services, or ideas.' },
  { id: 'E5', category: 'E', text: 'I enjoy taking initiative and making decisions.' },
  { id: 'E6', category: 'E', text: 'I like setting goals and motivating people to achieve them.' },
  { id: 'E7', category: 'E', text: 'I enjoy negotiating or finding agreements between people.' },
  { id: 'E8', category: 'E', text: 'I like exploring business ideas or opportunities.' },
  // ── Conventional (C) ─────────────────────────────────────
  { id: 'C1', category: 'C', text: 'I enjoy organizing files, records, or information.' },
  { id: 'C2', category: 'C', text: 'I like working with numbers and data.' },
  { id: 'C3', category: 'C', text: 'I enjoy checking details to make sure information is accurate.' },
  { id: 'C4', category: 'C', text: 'I like following clear procedures and instructions.' },
  { id: 'C5', category: 'C', text: 'I enjoy keeping schedules, lists, or records organized.' },
  { id: 'C6', category: 'C', text: 'I like entering information into forms or computer systems.' },
  { id: 'C7', category: 'C', text: 'I enjoy arranging information in a clear and systematic way.' },
  { id: 'C8', category: 'C', text: 'I like completing structured tasks that require accuracy and consistency.' },
];

/* ─── Category Metadata ──────────────────────────────────── */
const CATEGORY_META = {
  R: {
    name: 'Realistic', icon: 'bi-tools', colorClass: 'circle-r', fillClass: 'fill-r',
    color: '#6b0f1a',
    description: 'You enjoy hands-on, practical work. You like working with tools, machines, or your body. Building, repairing, and working with physical materials energizes you.',
    traits:  ['Practical', 'Mechanical', 'Athletic', 'Nature-oriented'],
    careers: ['Mechanical Engineer', 'Electrician', 'Carpenter', 'Civil Engineer', 'Pilot', 'Farmer', 'Auto Technician', 'Construction Manager', 'Landscape Architect', 'Physical Therapist'],
  },
  I: {
    name: 'Investigative', icon: 'bi-microscope', colorClass: 'circle-i', fillClass: 'fill-i',
    color: '#991b1b',
    description: 'You are drawn to research, analysis, and intellectual discovery. You enjoy exploring complex problems, testing hypotheses, and understanding how things work at a deeper level.',
    traits:  ['Analytical', 'Curious', 'Logical', 'Scientific'],
    careers: ['Research Scientist', 'Data Analyst', 'Physician', 'Software Developer', 'Economist', 'Psychologist', 'Mathematician', 'Pharmacist', 'Biologist', 'Actuary'],
  },
  A: {
    name: 'Artistic', icon: 'bi-palette', colorClass: 'circle-a', fillClass: 'fill-a',
    color: '#800000',
    description: 'You thrive with creative expression and original thinking. You like work that involves imagination, design, writing, music, or visual arts where you can communicate ideas and feelings.',
    traits:  ['Creative', 'Expressive', 'Imaginative', 'Independent'],
    careers: ['Graphic Designer', 'Writer / Author', 'Musician', 'Architect', 'Film Director', 'UX Designer', 'Art Director', 'Photographer', 'Interior Designer', 'Marketing Creative'],
  },
  S: {
    name: 'Social', icon: 'bi-people-fill', colorClass: 'circle-s', fillClass: 'fill-s',
    color: '#7f1d1d',
    description: 'You are motivated by helping, teaching, and connecting with others. You find fulfillment in supporting people, resolving conflicts, and contributing to communities.',
    traits:  ['Empathetic', 'Cooperative', 'Helpful', 'Patient'],
    careers: ['Teacher / Educator', 'Counselor', 'Social Worker', 'Nurse', 'Human Resources', 'Community Manager', 'Speech Therapist', 'Nonprofit Coordinator', 'Coach', 'Public Health Worker'],
  },
  E: {
    name: 'Enterprising', icon: 'bi-briefcase-fill', colorClass: 'circle-e', fillClass: 'fill-e',
    color: '#6b0f1a',
    description: 'You are energized by leadership, influence, and initiative. You enjoy starting projects, persuading others, making decisions, and working toward ambitious goals.',
    traits:  ['Ambitious', 'Persuasive', 'Confident', 'Goal-oriented'],
    careers: ['Entrepreneur', 'Sales Manager', 'Marketing Director', 'Lawyer', 'Financial Advisor', 'Real Estate Agent', 'Business Developer', 'Political Scientist', 'Event Manager', 'Executive'],
  },
  C: {
    name: 'Conventional', icon: 'bi-grid-3x3-gap-fill', colorClass: 'circle-c', fillClass: 'fill-c',
    color: '#5d1414',
    description: 'You value accuracy, structure, and efficiency. You excel at organizing information, following clear processes, managing data, and ensuring everything runs smoothly and correctly.',
    traits:  ['Organized', 'Detail-oriented', 'Dependable', 'Precise'],
    careers: ['Accountant', 'Financial Analyst', 'Administrative Manager', 'Data Entry Specialist', 'Compliance Officer', 'Database Administrator', 'Bookkeeper', 'Operations Analyst', 'Medical Records Technician', 'Logistics Coordinator'],
  },
};

/* ─── Opposite (complementary) RIASEC categories ─────────── */
const OPPOSITE_CATEGORY = {
  R: 'S', I: 'E', A: 'C',
  S: 'R', E: 'I', C: 'A',
};

/* ─── Canonical RIASEC order (used for tied results) ─────── */
const RIASEC_ORDER = ['R', 'I', 'A', 'S', 'E', 'C'];

/* ─── Application State ──────────────────────────────────── */
let state = {
  // Respondent info (collected from screen-info form)
  respondent: {
    full_name:  '',
    student_id: '',
    section:    '',
  },
  shuffledQuestions: [],
  currentIndex:      0,
  answers:           {},   // { questionId: scoreValue (1–5) }
  sessionActive:     false,
  submitted:         false,   // true once successfully saved to DB
  inquirySubmittedFor: null,  // student_id whose inquiry was sent this session
};

/* ─── Fisher–Yates Shuffle ───────────────────────────────── */
function shuffleQuestions(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

/* ─── Screen Management ──────────────────────────────────── */
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const target = document.getElementById(id);
  if (target) {
    target.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

/* ─── localStorage (in-progress session only) ────────────── */
function saveProgress() {
  if (state.submitted) return; // don't re-save after DB write
  try {
    const payload = {
      respondent:    state.respondent,
      shuffledIds:   state.shuffledQuestions.map(q => q.id),
      currentIndex:  state.currentIndex,
      answers:       state.answers,
      sessionActive: state.sessionActive,
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  } catch (e) { /* storage unavailable */ }
}

function restoreProgress() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return false;
    const data = JSON.parse(raw);

    if (
      !Array.isArray(data.shuffledIds) ||
      data.shuffledIds.length !== TOTAL_Q ||
      typeof data.answers !== 'object' ||
      typeof data.currentIndex !== 'number'
    ) { clearStorage(); return false; }

    const idMap = {};
    ALL_QUESTIONS.forEach(q => { idMap[q.id] = q; });
    const rebuilt = data.shuffledIds.map(id => idMap[id]).filter(Boolean);
    if (rebuilt.length !== TOTAL_Q) { clearStorage(); return false; }

    state.shuffledQuestions = rebuilt;
    state.currentIndex      = Math.max(0, Math.min(data.currentIndex, TOTAL_Q - 1));
    state.answers           = data.answers || {};
    state.sessionActive     = true;
    state.submitted         = false;

    // Restore respondent info if present
    if (data.respondent && data.respondent.full_name) {
      state.respondent = data.respondent;
    }
    return true;
  } catch (e) { clearStorage(); return false; }
}

function clearStorage() {
  try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
}

/* ─── Per-student inquiry submission status ──────────────── */
function loadInquirySubmittedMap() {
  try {
    const saved = JSON.parse(localStorage.getItem(INQUIRY_SUBMITTED_KEY) || '{}');
    return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
  } catch (e) {
    return {};
  }
}

function hasSubmittedInquiryFor(studentId) {
  if (!studentId) return false;
  return loadInquirySubmittedMap()[studentId] === true;
}

function markInquirySubmittedFor(studentId) {
  if (!studentId) return;
  try {
    const map = loadInquirySubmittedMap();
    map[studentId] = true;
    localStorage.setItem(INQUIRY_SUBMITTED_KEY, JSON.stringify(map));
  } catch (e) { /* storage unavailable */ }
}

function currentInquiryStudentId() {
  return String((state.respondent && state.respondent.student_id) || '').trim();
}

function isInquiryAlreadySubmitted() {
  const studentId = currentInquiryStudentId();
  if (!studentId) return false;
  return state.inquirySubmittedFor === studentId || hasSubmittedInquiryFor(studentId);
}

function resetInquiryFormFields(form) {
  if (!form) return;
  form.reset();
  delete form.dataset.validationAttempted;
  form.querySelectorAll('.is-invalid').forEach(field => {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
  });
}

function showInquiryFormView() {
  const form    = document.getElementById('inquiry-form');
  const alertEl = document.getElementById('inquiry-submit-alert');
  if (form) {
    form.classList.remove('d-none');
    resetInquiryFormFields(form);
  }
  if (alertEl) {
    alertEl.className = 'alert d-none';
    alertEl.textContent = '';
  }
  const dateEl = document.getElementById('inquiry-preferred-date');
  if (dateEl) dateEl.min = localDateValue();
}

function showInquirySubmittedView() {
  const form    = document.getElementById('inquiry-form');
  const alertEl = document.getElementById('inquiry-submit-alert');
  if (form) {
    form.classList.add('d-none');
    resetInquiryFormFields(form);
  }
  if (alertEl) {
    alertEl.className = 'alert alert-success';
    alertEl.textContent = INQUIRY_SUCCESS_MESSAGE;
  }
  const dateEl = document.getElementById('inquiry-preferred-date');
  if (dateEl) dateEl.min = localDateValue();
}

function syncInquirySection() {
  const form    = document.getElementById('inquiry-form');
  const alertEl = document.getElementById('inquiry-submit-alert');
  if (!form || !alertEl) return;

  if (isInquiryAlreadySubmitted()) {
    showInquirySubmittedView();
    return;
  }

  showInquiryFormView();
  refreshInquiryStatusFromServer();
}

async function refreshInquiryStatusFromServer() {
  const studentId = currentInquiryStudentId();
  const fullName  = String((state.respondent && state.respondent.full_name) || '').trim();
  if (!studentId || !fullName) return;

  try {
    const response = await fetch(API_INQUIRIES, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'status', full_name: fullName, student_id: studentId }),
    });
    if (!response.ok) return;
    const result = await readApiJson(response);
    if (!result || !result.success || !result.has_inquiry) return;

    // Ignore stale responses (student changed or a submit is in flight)
    if (state._inquirySubmitting) return;
    if (currentInquiryStudentId() !== studentId) return;
    if (isInquiryAlreadySubmitted()) return;

    markInquirySubmittedFor(studentId);
    showInquirySubmittedView();
  } catch (error) {
    // Status lookup is non-fatal; the form stays usable.
    console.warn('Inquiry status check failed:', error);
  }
}

/* ─── App Init ───────────────────────────────────────────── */
function initApp() {
  const hasSaved = restoreProgress();
  const banner   = document.getElementById('resume-banner');
  if (hasSaved && banner) banner.classList.remove('d-none');

  // Wire up the respondent info form
  const infoForm = document.getElementById('info-form');
  if (infoForm) {
    infoForm.addEventListener('submit', handleInfoFormSubmit);
  }

  const idEl = document.getElementById('input-studentid');
  if (idEl) {
    idEl.addEventListener('input', () => {
      idEl.value = idEl.value.replace(/[^0-9]/g, '').slice(0, 12);
      idEl.classList.remove('is-valid', 'is-invalid');
    });
  }

  const trackForm = document.getElementById('track-inquiry-form');
  if (trackForm) {
    trackForm.addEventListener('submit', handleTrackInquiry);
    const trackLrn = document.getElementById('track-lrn');
    trackLrn.addEventListener('input', () => {
      trackLrn.value = trackLrn.value.replace(/[^0-9]/g, '').slice(0, 12);
      trackLrn.classList.remove('is-invalid');
    });
  }

  const inquiryForm = document.getElementById('inquiry-form');
  if (inquiryForm) {
    inquiryForm.addEventListener('submit', handleInquirySubmit);
    ['inquiry-concern', 'inquiry-preferred-date'].forEach(id => {
      const field = document.getElementById(id);
      field.addEventListener('input', refreshInquiryValidation);
      field.addEventListener('change', refreshInquiryValidation);
    });
  }

  const inquiryDate = document.getElementById('inquiry-preferred-date');
  if (inquiryDate) inquiryDate.min = localDateValue();

  // School finder — opening the panel never asks for the location;
  // the student chooses the location button or types a city themselves.
  const findSchoolsBtn = document.getElementById('btn-find-schools');
  if (findSchoolsBtn) {
    findSchoolsBtn.addEventListener('click', () => {
      openSchoolFinder();
    });
  }

  const useLocationBtn = document.getElementById('btn-use-location');
  if (useLocationBtn) useLocationBtn.addEventListener('click', useCurrentLocation);

  const searchCityBtn = document.getElementById('btn-search-city');
  if (searchCityBtn) searchCityBtn.addEventListener('click', searchSchoolsByCity);

  const schoolCitySelect = document.getElementById('school-city-select');
  if (schoolCitySelect) schoolCitySelect.addEventListener('change', onSchoolCityPicked);

  const schoolCityInput = document.getElementById('school-city-input');
  if (schoolCityInput) {
    schoolCityInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        searchSchoolsByCity();
      }
    });
  }
}

/* ─── Respondent Info Form ───────────────────────────────── */
async function handleInfoFormSubmit(e) {
  e.preventDefault();

  const nameEl    = document.getElementById('input-fullname');
  const idEl      = document.getElementById('input-studentid');
  const sectionEl = document.getElementById('input-section');
  const verifyEl  = document.getElementById('verify-panel');

  let valid = true;

  // Full name
  const nameVal = nameEl.value.trim();
  if (!nameVal) {
    nameEl.classList.add('is-invalid');
    valid = false;
  } else {
    nameEl.classList.remove('is-invalid');
    nameEl.classList.add('is-valid');
  }

  // LRN must contain exactly 12 digits
  const idVal = idEl.value.trim();
  if (!/^[0-9]{12}$/.test(idVal)) {
    idEl.classList.add('is-invalid');
    valid = false;
  } else {
    idEl.classList.remove('is-invalid');
    idEl.classList.add('is-valid');
  }

  // Section
  const sectionVal = sectionEl.value.trim();
  if (!sectionVal) {
    sectionEl.classList.add('is-invalid');
    valid = false;
  } else {
    sectionEl.classList.remove('is-invalid');
    sectionEl.classList.add('is-valid');
  }

  if (!valid) return;

  // Hide any previous verification panel
  if (verifyEl) verifyEl.classList.add('d-none');

  // ── Check DB for existing assessment using all fields ──
  showOverlay(true);

  try {
    const response = await fetch(API_CHECK, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        full_name:  nameVal,
        student_id: idVal,
        section:    sectionVal,
      }),
    });

    const result = await response.json();

    showOverlay(false);

    if (!response.ok) {
      throw new Error(result.error || 'The existing-record check failed.');
    }

    if (!result || !['exact', 'similar', 'none'].includes(result.match_type)) {
      throw new Error('The existing-record check returned an invalid response.');
    }

    // ── EXACT MATCH: same student already assessed ──
    if (result.match_type === 'exact') {
      state.respondent = {
        full_name:  result.respondent.full_name,
        student_id: result.respondent.student_id,
        section:    result.respondent.section,
      };
      state.submitted = true;
      clearStorage();

      renderResults(result.scores);
      showScreen('screen-results');
      showDuplicateBanner(
        'You have already completed the RIASEC assessment. ' +
        'Your previous results (submitted ' +
        (result.submitted_at || 'earlier') +
        ') are shown below.'
      );
      return;
    }

    // ── SIMILAR MATCH: candidate found, needs verification ──
    if (result.match_type === 'similar' && Array.isArray(result.candidates) && result.candidates.length > 0) {
      showVerifyPanel(result.candidates, { nameVal, idVal, sectionVal });
      return;
    }

    if (result.match_type !== 'none') {
      throw new Error('The existing-record check returned an invalid response.');
    }

    // ── NO MATCH: proceed to new assessment ──
    state.respondent = {
      full_name:  nameVal,
      student_id: idVal,
      section:    sectionVal,
    };
    startAssessment();

  } catch (err) {
    showOverlay(false);
    console.error('Existing student record check failed:', err);
    showErrorToast('Could not check for existing records. Please try again.');
  }
}

/* ─── Similar-Match Verification Panel ───────────────────── */
function showVerifyPanel(candidates, formData) {
  const panel     = document.getElementById('verify-panel');
  const container = document.getElementById('verify-candidates');
  if (!panel || !container) return;

  container.innerHTML = '';

  candidates.forEach((c, idx) => {
    const card = document.createElement('div');
    card.className = 'verify-candidate';
    card.innerHTML = `
      <div class="verify-candidate-info">
        <strong>${escapeHtml(c.full_name)}</strong> &middot;
        ID: ${escapeHtml(c.student_id)} &middot;
        ${escapeHtml(c.section)}<br>
        <span class="verify-meta">Main RIASEC category: ${escapeHtml(getDominantLabel(c.scores))} &middot;
        Submitted: ${escapeHtml(c.submitted_at || 'earlier')}</span>
      </div>
      <div class="verify-candidate-actions">
        <button type="button" class="btn-verify-yes"
                onclick="confirmSimilarMatch(${idx})">
          Yes, that's me
        </button>
        <button type="button" class="btn-verify-no"
                onclick="dismissVerifyPanel()">
          No
        </button>
      </div>
    `;
    container.appendChild(card);
  });

  // Store candidates + form data for the "Yes" handler
  state._verifyCandidates = candidates;
  state._verifyFormData   = formData;

  panel.classList.remove('d-none');
  panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function confirmSimilarMatch(idx) {
  const candidate = (state._verifyCandidates || [])[idx];
  if (!candidate) return;

  // Treat as exact match — show their previous results
  state.respondent = {
    full_name:  candidate.full_name,
    student_id: candidate.student_id,
    section:    candidate.section,
  };
  state.submitted = true;
  clearStorage();

  // Hide verify panel
  const panel = document.getElementById('verify-panel');
  if (panel) panel.classList.add('d-none');

  renderResults(candidate.scores);
  showScreen('screen-results');
  showDuplicateBanner(
    'A matching record was found and verified. ' +
    'Your previous results (submitted ' +
    (candidate.submitted_at || 'earlier') +
    ') are shown below.'
  );

  // Clean up temp state
  delete state._verifyCandidates;
  delete state._verifyFormData;
}

function dismissVerifyPanel() {
  const panel = document.getElementById('verify-panel');
  if (panel) panel.classList.add('d-none');

  const formData = state._verifyFormData;
  delete state._verifyCandidates;
  delete state._verifyFormData;

  // User confirmed this is NOT them — proceed to new assessment
  if (formData) {
    state.respondent = {
      full_name:  formData.nameVal,
      student_id: formData.idVal,
      section:    formData.sectionVal,
    };
    startAssessment();
  }
}

/* ─── Assessment Start ───────────────────────────────────── */
function startAssessment() {
  clearStorage();
  state.shuffledQuestions = shuffleQuestions(ALL_QUESTIONS);
  state.currentIndex      = 0;
  state.answers           = {};
  state.sessionActive     = true;
  state.submitted         = false;
  saveProgress();
  showScreen('screen-assessment');
  renderQuestion();
}

function resumeAssessment() {
  if (!state.sessionActive) {
    showScreen('screen-info');
    return;
  }
  showScreen('screen-assessment');
  renderQuestion();
}

/* ─── Assessment Screen ──────────────────────────────────── */
function renderQuestion() {
  const q           = state.shuffledQuestions[state.currentIndex];
  const totalQ      = TOTAL_Q;
  const current     = state.currentIndex + 1;
  const pct         = Math.round((current / totalQ) * 100);
  const savedAnswer = state.answers[q.id] || null;

  // Statement
  document.getElementById('statement-text').innerHTML =
    `<p class="statement-text">${escapeHtml(q.text)}</p>`;

  // Answer options
  const optionsEl = document.getElementById('answer-options');
  optionsEl.innerHTML = '';

  const options = [
    { value: 1, label: 'Strongly Dislike' },
    { value: 2, label: 'Dislike'          },
    { value: 3, label: 'Neutral'          },
    { value: 4, label: 'Like'             },
    { value: 5, label: 'Strongly Like'    },
  ];

  options.forEach(opt => {
    const isSelected = savedAnswer === opt.value;
    const optionId   = `answer-${opt.value}`;

    const div = document.createElement('label');
    div.className = `answer-option${isSelected ? ' selected' : ''}`;
    div.setAttribute('for', optionId);
    div.setAttribute('tabindex', '0');
    div.setAttribute('role', 'radio');
    div.setAttribute('aria-checked', isSelected ? 'true' : 'false');

    div.innerHTML = `
      <input type="radio" id="${optionId}" name="question-answer"
             value="${opt.value}" ${isSelected ? 'checked' : ''}
             aria-label="${escapeHtml(opt.label)}" />
      <span class="answer-dot"></span>
      <span class="answer-label-text">${escapeHtml(opt.label)}</span>
      <span class="answer-score-badge">${opt.value}</span>
    `;

    div.addEventListener('click', () => selectAnswer(q.id, opt.value));
    div.addEventListener('keydown', (e) => {
      if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); selectAnswer(q.id, opt.value); }
    });

    optionsEl.appendChild(div);
  });

  // Progress
  document.getElementById('progress-bar').style.width = pct + '%';
  document.getElementById('progress-bar').setAttribute('aria-valuenow', pct);
  document.getElementById('progress-indicator').textContent = `${current}/${totalQ}`;

  // Buttons
  document.getElementById('btn-prev').disabled = state.currentIndex === 0;

  const nextBtn  = document.getElementById('btn-next');
  const isLast   = state.currentIndex === TOTAL_Q - 1;
  const submitWrap = document.getElementById('submit-eval-wrap');

  nextBtn.textContent = isLast ? 'Review' : 'Next';
  nextBtn.onclick = goToNextQuestion;

  // Show the "Review & Submit Evaluation" button only on the last question
  // and only once the user has selected an answer for it
  if (isLast && submitWrap) {
    const alreadyAnswered = !!state.answers[q.id];
    submitWrap.classList.toggle('d-none', !alreadyAnswered);
  } else if (submitWrap) {
    submitWrap.classList.add('d-none');
  }

  hideValidation();
}

function selectAnswer(questionId, value) {
  state.answers[questionId] = value;
  saveProgress();

  document.querySelectorAll('.answer-option').forEach((el, idx) => {
    const isSelected = (idx + 1) === value;
    el.classList.toggle('selected', isSelected);
    el.setAttribute('aria-checked', isSelected ? 'true' : 'false');
    const radio = el.querySelector('input[type="radio"]');
    if (radio) radio.checked = isSelected;
  });

  // Reveal the "Review & Submit Evaluation" button when the last question is answered
  const isLast     = state.currentIndex === TOTAL_Q - 1;
  const submitWrap = document.getElementById('submit-eval-wrap');
  if (isLast && submitWrap) {
    submitWrap.classList.remove('d-none');
  }

  hideValidation();
}

function goToNextQuestion() {
  const q = state.shuffledQuestions[state.currentIndex];
  if (!state.answers[q.id]) { showValidation(); return; }

  if (state.currentIndex === TOTAL_Q - 1) {
    renderReviewScreen();
    showScreen('screen-review');
    return;
  }

  state.currentIndex++;
  saveProgress();
  renderQuestion();
}

function goToPreviousQuestion() {
  if (state.currentIndex === 0) return;
  state.currentIndex--;
  saveProgress();
  renderQuestion();
}

function showValidation() {
  const msg = document.getElementById('validation-msg');
  msg.classList.remove('d-none');
  msg.style.animation = 'none';
  void msg.offsetWidth;
  msg.style.animation = '';
}

function hideValidation() {
  const msg = document.getElementById('validation-msg');
  if (msg) msg.classList.add('d-none');
}

/* ─── Review Screen ──────────────────────────────────────── */
function renderReviewScreen() {
  const list        = document.getElementById('review-list');
  const warning     = document.getElementById('unanswered-warning');
  const countText   = document.getElementById('unanswered-count-text');
  const summaryText = document.getElementById('review-summary-text');
  const submitBtn   = document.getElementById('btn-submit');

  list.innerHTML = '';
  let unansweredCount = 0;

  state.shuffledQuestions.forEach((q, idx) => {
    const answer     = state.answers[q.id];
    const isAnswered = !!answer;
    if (!isAnswered) unansweredCount++;

    const item = document.createElement('div');
    item.className = `review-item ${isAnswered ? 'answered' : 'unanswered'}`;
    if (!isAnswered) {
      item.title = 'Click to answer this question';
      item.addEventListener('click', () => jumpToQuestion(idx));
    }

    item.innerHTML = `
      <span class="review-num">${idx + 1}</span>
      <span class="review-text">${escapeHtml(q.text)}</span>
      <span class="review-answer ${isAnswered ? 'review-answered-badge' : 'review-unanswered-badge'}">
        ${isAnswered ? escapeHtml(SCORE_LABELS[answer]) : '<i class="bi bi-dash"></i>'}
      </span>
    `;
    list.appendChild(item);
  });

  const answeredCount = TOTAL_Q - unansweredCount;
  summaryText.textContent = `${answeredCount} of ${TOTAL_Q} statements answered.`;

  if (unansweredCount > 0) {
    warning.classList.remove('d-none');
    countText.textContent = `${unansweredCount} statement${unansweredCount > 1 ? 's are' : ' is'} unanswered.`;
    submitBtn.disabled = true;
  } else {
    warning.classList.add('d-none');
    submitBtn.disabled = false;
  }
}

function jumpToQuestion(index) {
  state.currentIndex = index;
  saveProgress();
  showScreen('screen-assessment');
  renderQuestion();
}

function goBackToAssessment() {
  showScreen('screen-assessment');
  renderQuestion();
}

/* ─── Submit Assessment → PHP API ────────────────────────── */
async function submitAssessment() {
  // Guard: all answered
  const unanswered = state.shuffledQuestions.filter(q => !state.answers[q.id]);
  if (unanswered.length > 0) { renderReviewScreen(); return; }

  // Prevent double-submission
  if (state.submitted) {
    showScreen('screen-results');
    return;
  }

  const submitBtn = document.getElementById('btn-submit');
  if (submitBtn) submitBtn.disabled = true;

  showOverlay(true);

  const payload = {
    full_name:  state.respondent.full_name,
    student_id: state.respondent.student_id,
    section:    state.respondent.section,
    answers:    state.answers,
  };

  try {
    const response = await fetch(API_SUBMIT, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });

    const result = await response.json();

    // ── Duplicate submission (409 Conflict) ───────────────
    if (response.status === 409 || result.duplicate === true) {
      state.submitted = true;
      clearStorage();
      showOverlay(false);

      // Show results using the scores returned by the API
      renderResults(result.scores);
      showScreen('screen-results');
      showDuplicateBanner(result.message || null);
      return;
    }

    if (!response.ok || !result.success) {
      const msg = result.errors
        ? result.errors.join(' ')
        : (result.error || 'Submission failed. Please try again.');
      throw new Error(msg);
    }

    // ── Success ───────────────────────────────────────────
    state.submitted = true;
    clearStorage();
    showOverlay(false);
    renderResults(result.scores);
    showScreen('screen-results');
    showSuccessBanner();

  } catch (err) {
    showOverlay(false);
    showErrorToast(err.message || 'An unexpected error occurred. Please try again.');
    if (submitBtn) submitBtn.disabled = false;
  }
}

/* ─── Scoring (client-side, matches server) ──────────────── */
function calculateScores() {
  const scores = { R: 0, I: 0, A: 0, S: 0, E: 0, C: 0 };
  ALL_QUESTIONS.forEach(q => {
    const answer = state.answers[q.id];
    if (answer) scores[q.category] += answer;
  });
  return scores;
}

/* ─── Results Screen ─────────────────────────────────────── */
function generateResults(scores) {
  const sorted = Object.entries(scores).sort((a, b) => {
    if (b[1] !== a[1]) return b[1] - a[1];
    return a[0].localeCompare(b[0]); // tie-break alphabetically
  });

  const maxScore = sorted[0][1];
  // All categories tied for the highest score, in canonical RIASEC order
  const dominantLetters = RIASEC_ORDER.filter(letter => scores[letter] === maxScore);

  return {
    sorted,
    dominantLetters,
    dominantCode: dominantLetters.join(''),
    dominantNames: dominantLetters.map(letter => CATEGORY_META[letter].name).join(', '),
    dominantLetter: dominantLetters[0],
    dominantScore: maxScore,
    tiedForHighest: dominantLetters.length > 1,
  };
}

function getDominantLetter(scores) {
  return generateResults(scores).dominantLetter;
}

function getDominantLabel(scores) {
  const r = generateResults(scores);
  return r.dominantCode + ' — ' + r.dominantNames;
}

function renderResults(scores) {
  const results    = generateResults(scores);
  const codeEl     = document.getElementById('holland-code');
  const subtitleEl = document.getElementById('holland-subtitle');
  const tieNotice  = document.getElementById('tie-notice');

  // Respondent info strip
  const strip = document.getElementById('respondent-strip');
  if (strip && state.respondent.full_name) {
    document.getElementById('strip-name').textContent    = state.respondent.full_name;
    document.getElementById('strip-id').textContent      = state.respondent.student_id;
    document.getElementById('strip-section').textContent = state.respondent.section;
    strip.classList.remove('d-none');
  }

  codeEl.textContent = results.dominantCode;
  subtitleEl.textContent = results.dominantNames;

  if (results.tiedForHighest) {
    tieNotice.classList.remove('d-none');
    tieNotice.innerHTML = `
      <i class="bi bi-info-circle me-1"></i>
      The highest score is tied. All tied categories are shown as your main category:
      <strong>${results.dominantNames}</strong>
    `;
  } else {
    tieNotice.classList.add('d-none');
  }

  // Opposite category display
  const oppositeEl = document.getElementById('opposite-category');
  if (oppositeEl) {
    const oppositeLetter = OPPOSITE_CATEGORY[results.dominantLetter];
    const oppositeMeta   = CATEGORY_META[oppositeLetter];
    oppositeEl.innerHTML = `
      <span class="opposite-label">Opposite Category</span>
      <span class="opposite-value">
        <span class="opposite-letter">${oppositeLetter}</span>${oppositeMeta.name}
      </span>
      <p class="opposite-message">This category may reflect work preferences that are less aligned with your primary interests.</p>
    `;
    oppositeEl.classList.remove('d-none');
  }

  renderScoreBars(results.sorted, results.dominantLetters);
  renderInterestExplanations(results.dominantLetters);
  renderCareerSuggestions(results.dominantLetters);
  syncInquirySection();
  resetSchoolFinder();
  openSchoolFinder();   // panel opens ready for the school search — the student's
                        // location is only requested when they enter it or press
                        // the location button (never automatically)
  setTimeout(() => animateScoreBars(), 120);

  // Hand the student's results to the global AI Assistant so it
  // switches to personalized mode (general mode before the test).
  // Guarded so an AI problem can never break the results screen or the
  // school locator started above.
  state.lastResults = results;
  state.lastScores  = scores;
  if (window.RIASECAssistant) {
    try {
      window.RIASECAssistant.setResults(results, scores);
    } catch (error) {
      console.warn('AI assistant could not pick up the results:', error);
    }
  }
}

async function handleInquirySubmit(event) {
  event.preventDefault();
  const form = event.target;
  const concernEl = form.querySelector('#inquiry-concern');
  const dateEl = form.querySelector('#inquiry-preferred-date');
  const alertEl = document.getElementById('inquiry-submit-alert');
  const concern = concernEl.value.trim();
  const studentId = currentInquiryStudentId();

  // Already sent for this student/test result — keep the confirmed state.
  if (state._inquirySubmitting) return;
  if (isInquiryAlreadySubmitted()) {
    showInquirySubmittedView();
    return;
  }

  form.dataset.validationAttempted = 'true';
  const validation = validateInquiryFields(concernEl, dateEl);
  if (!validation.valid) {
    const missingFields = [];
    if (!validation.concernValid) missingFields.push('your question or concern');
    if (!validation.dateValid) missingFields.push('a valid preferred interview date');
    alertEl.className = 'alert alert-danger inquiry-validation-error';
    alertEl.textContent = `Please complete the required information: ${missingFields.join(' and ')}.`;
    const firstInvalid = form.querySelector('.is-invalid');
    if (firstInvalid) firstInvalid.focus();
    return;
  }

  alertEl.classList.remove('inquiry-validation-error');
  const submitButton = event.submitter || form.querySelector('button[type="submit"]');
  if (submitButton) submitButton.disabled = true;
  alertEl.className = 'alert alert-info';
  alertEl.textContent = 'Sending your request…';
  state._inquirySubmitting = true;
  form.classList.remove('d-none');

  try {
    const response = await fetch(API_INQUIRIES, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'submit',
        full_name: state.respondent.full_name,
        student_id: state.respondent.student_id,
        concern,
        preferred_date: dateEl.value,
      }),
    });
    const result = await readApiJson(response);
    if (!response.ok || !result.success) {
      throw new Error(result.error || 'Could not send your request. Please try again.');
    }

    // Success: remember per student + test result, then hide the form.
    if (studentId) {
      state.inquirySubmittedFor = studentId;
      markInquirySubmittedFor(studentId);
    }
    showInquirySubmittedView();
  } catch (error) {
    // Failure: keep the form visible so the student can retry.
    form.classList.remove('d-none');
    alertEl.className = 'alert alert-danger';
    alertEl.textContent = error.message || 'Could not send your request. Please try again.';
  } finally {
    state._inquirySubmitting = false;
    if (submitButton) submitButton.disabled = false;
  }
}

async function readApiJson(response) {
  const body = await response.text();
  if (!body) {
    throw new Error(`The server returned an empty response (HTTP ${response.status}).`);
  }

  try {
    return JSON.parse(body);
  } catch (error) {
    console.error('Inquiry API returned invalid JSON:', error);
    throw new Error(`The server returned an invalid response (HTTP ${response.status}).`);
  }
}

function validateInquiryFields(concernEl, dateEl) {
  const concern = concernEl.value.trim();
  const concernValid = concern !== '' && concern.length <= 3000;
  const dateValid = !!dateEl.value && dateEl.value >= localDateValue();

  concernEl.classList.toggle('is-invalid', !concernValid);
  concernEl.setAttribute('aria-invalid', concernValid ? 'false' : 'true');
  document.getElementById('inquiry-concern-error').textContent = concern
    ? 'Your question or concern must be no more than 3,000 characters.'
    : 'Enter your question or concern.';

  dateEl.classList.toggle('is-invalid', !dateValid);
  dateEl.setAttribute('aria-invalid', dateValid ? 'false' : 'true');
  document.getElementById('inquiry-date-error').textContent = dateEl.value
    ? 'Choose a valid interview date that is not in the past.'
    : 'Choose your preferred interview date.';

  return { valid: concernValid && dateValid, concernValid, dateValid };
}

function refreshInquiryValidation(event) {
  const form = event.currentTarget.form;
  if (!form || form.dataset.validationAttempted !== 'true') return;

  const concernEl = form.querySelector('#inquiry-concern');
  const dateEl = form.querySelector('#inquiry-preferred-date');
  const validation = validateInquiryFields(concernEl, dateEl);
  const alertEl = document.getElementById('inquiry-submit-alert');
  if (validation.valid && alertEl.classList.contains('inquiry-validation-error')) {
    alertEl.className = 'alert d-none';
    alertEl.textContent = '';
  }
}

function toggleTrackInquiry(forceOpen) {
  const panel = document.getElementById('track-inquiry-panel');
  const willOpen = typeof forceOpen === 'boolean' ? forceOpen : panel.classList.contains('d-none');
  panel.classList.toggle('d-none', !willOpen);
  if (!willOpen) {
    stopInquiryPolling();
    return;
  }
  document.getElementById('track-name').focus();
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function handleTrackInquiry(event) {
  event.preventDefault();
  const nameEl = document.getElementById('track-name');
  const lrnEl = document.getElementById('track-lrn');
  const errorEl = document.getElementById('track-inquiry-error');
  const resultsEl = document.getElementById('track-inquiry-results');
  const fullName = nameEl.value.trim().replace(/\s+/g, ' ');
  const studentId = lrnEl.value.trim();

  if (!fullName || !/^[0-9]{12}$/.test(studentId)) {
    lrnEl.classList.toggle('is-invalid', !/^[0-9]{12}$/.test(studentId));
    errorEl.textContent = 'Enter your registered student name and exact 12-digit LRN.';
    errorEl.classList.remove('d-none');
    return;
  }

  errorEl.classList.add('d-none');
  resultsEl.classList.remove('d-none');
  resultsEl.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading your inquiries…</div>';
  trackedInquiryVersions = loadInquiryVersions();
  await loadTrackedInquiries(fullName, studentId, true);
  stopInquiryPolling();
  inquiryPollTimer = window.setInterval(() => {
    if (!document.hidden && !document.getElementById('track-inquiry-panel').classList.contains('d-none')) {
      loadTrackedInquiries(fullName, studentId, true);
    }
  }, 30000);
}

async function loadTrackedInquiries(fullName, studentId, notify) {
  const errorEl = document.getElementById('track-inquiry-error');
  const resultsEl = document.getElementById('track-inquiry-results');
  try {
    const response = await fetch(API_INQUIRIES, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'track', full_name: fullName, student_id: studentId }),
    });
    const result = await readApiJson(response);
    if (!response.ok || !result.success) {
      throw new Error(result.error || 'Could not load inquiry updates.');
    }
    renderTrackedInquiries(result.inquiries, notify);
    errorEl.classList.add('d-none');
  } catch (error) {
    if (!notify) resultsEl.classList.add('d-none');
    errorEl.textContent = error.message || 'Could not load inquiry updates. Please try again.';
    errorEl.classList.remove('d-none');
  }
}

function renderTrackedInquiries(inquiries, notify) {
  const resultsEl = document.getElementById('track-inquiry-results');
  if (!inquiries.length) {
    resultsEl.innerHTML = '<div class="alert alert-info mb-0">No inquiry or interview requests were found for this assessment.</div>';
    return;
  }

  const changed = [];
  inquiries.forEach(inquiry => {
    const previous = trackedInquiryVersions[inquiry.id];
    const hasTeacherResponse = inquiry.updates.some(update => update.actor === 'admin');
    if (notify && ((previous && previous !== inquiry.update_version) || (!previous && hasTeacherResponse))) {
      changed.push(inquiry);
    }
    trackedInquiryVersions[inquiry.id] = inquiry.update_version;
  });
  try {
    localStorage.setItem(INQUIRY_VERSIONS_KEY, JSON.stringify(trackedInquiryVersions));
  } catch (error) {
    console.warn('Inquiry update notifications may not persist in this browser.', error);
  }
  const notification = changed.length
    ? `<div class="alert alert-primary" role="alert"><i class="bi bi-bell-fill me-2"></i>Your teacher updated ${changed.length === 1 ? 'an inquiry' : `${changed.length} inquiries`}. The latest details are shown below.</div>`
    : '';

  resultsEl.innerHTML = notification + inquiries.map(inquiry => `
    <article class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <h3 class="h6 fw-bold mb-0">Inquiry #${Number(inquiry.id)}</h3>
          <span class="badge ${inquiry.status === 'Pending' ? 'text-bg-warning' : inquiry.status === 'Rejected' ? 'text-bg-danger' : 'text-bg-success'}">${escapeHtml(inquiry.status)}</span>
        </div>
        <p class="mb-2"><strong>Your question or concern</strong><br>${escapeHtml(inquiry.concern).replace(/\n/g, '<br>')}</p>
        <p class="mb-2"><strong>Preferred interview date:</strong> ${escapeHtml(inquiry.preferred_date)}</p>
        ${inquiry.scheduled_at ? `<p class="mb-2"><strong>Confirmed interview:</strong> ${escapeHtml(inquiry.scheduled_at)}</p>` : ''}
        ${inquiry.teacher_message ? `<div class="alert alert-light border mb-2"><strong>Teacher's message</strong><br>${escapeHtml(inquiry.teacher_message).replace(/\n/g, '<br>')}</div>` : ''}
        <p class="small text-muted mb-2">Last updated ${escapeHtml(inquiry.updated_at)}</p>
        <details>
          <summary class="fw-semibold">Update history (${inquiry.updates.length})</summary>
          <ul class="list-group list-group-flush mt-2">
            ${inquiry.updates.map(update => `<li class="list-group-item px-0">
              <strong>${escapeHtml(update.status)}</strong> · ${escapeHtml(update.created_at)}
              ${update.scheduled_at ? `<div>Interview date: ${escapeHtml(update.scheduled_at)}</div>` : ''}
              ${update.message ? `<div>${escapeHtml(update.message).replace(/\n/g, '<br>')}</div>` : ''}
            </li>`).join('')}
          </ul>
        </details>
      </div>
    </article>
  `).join('');
}

function stopInquiryPolling() {
  if (inquiryPollTimer !== null) {
    window.clearInterval(inquiryPollTimer);
    inquiryPollTimer = null;
  }
}

function renderScoreBars(sorted, dominantLetters) {
  const container = document.getElementById('score-bars');
  container.innerHTML = '';
  const domSet = Array.isArray(dominantLetters) ? dominantLetters : [dominantLetters];

  sorted.forEach(([letter, score]) => {
    const meta  = CATEGORY_META[letter];
    const pct   = Math.round((score / 40) * 100);
    const isDominant = domSet.includes(letter);

    const row = document.createElement('div');
    row.className = 'score-bar-row';
    row.innerHTML = `
      <div class="score-bar-label">
        <div class="score-bar-letter ${meta.colorClass}">${letter}</div>
        <span class="score-bar-name ${isDominant ? 'fw-bold' : ''}">${meta.name}</span>
      </div>
      <div class="score-bar-track" title="${score}/40 (${pct}%)">
        <div class="score-bar-fill ${meta.fillClass}" data-pct="${pct}" style="width:0%"></div>
      </div>
      <div class="score-bar-value">${score}<span class="text-muted fw-normal">/40</span></div>
    `;
    container.appendChild(row);
  });
}

function animateScoreBars() {
  document.querySelectorAll('.score-bar-fill').forEach(el => {
    el.style.width = el.dataset.pct + '%';
  });
}

function renderInterestExplanations(dominantLetters) {
  const container = document.getElementById('interest-explanations');
  container.innerHTML = '';
  const letters = Array.isArray(dominantLetters) ? dominantLetters : [dominantLetters];

  letters.forEach(letter => {
    const meta = CATEGORY_META[letter];
    const item = document.createElement('div');
    item.className = 'interest-explanation-item';
    item.innerHTML = `
      <div class="interest-icon-circle ${meta.colorClass}">
        <i class="bi ${meta.icon}"></i>
      </div>
      <div class="interest-explanation-body">
        <h4>${meta.name}</h4>
        <p>${meta.description}</p>
        <div class="d-flex flex-wrap gap-1 mt-2">
          ${meta.traits.map(t => `<span class="badge rounded-pill"
            style="background:${meta.color}22;color:${meta.color};border:1px solid ${meta.color}44;">${t}</span>`).join('')}
        </div>
      </div>
    `;
    container.appendChild(item);
  });
}

function renderCareerSuggestions(dominantLetters) {
  const container = document.getElementById('career-suggestions');
  container.innerHTML = '';
  const letters = Array.isArray(dominantLetters) ? dominantLetters : [dominantLetters];

  letters.forEach(letter => {
    const meta = CATEGORY_META[letter];
    const cat  = document.createElement('div');
    cat.className = 'career-category';
    cat.innerHTML = `
      <div class="career-category-title">
        <span class="score-bar-letter ${meta.colorClass}" style="width:22px;height:22px;font-size:.7rem;">${letter}</span>
        ${meta.name}
      </div>
      <div class="career-tags">
        ${meta.careers.map(c => `<span class="career-tag">${escapeHtml(c)}</span>`).join('')}
      </div>
    `;
    container.appendChild(cat);
  });
}

/* ═══════════════════════════════════════════════════════════
   School Finder — recommended courses for the RIASEC result and
   the colleges / universities near the student's location that
   offer them. Sources: OpenStreetMap (looked up dynamically by
   location) — there is no fixed school list. Only colleges and
   universities offering at least one recommended program are
   ever shown.
═══════════════════════════════════════════════════════════ */
const NOMINATIM_API = 'https://nominatim.openstreetmap.org/search'; // city lookup fallback
const API_SCHOOLS   = ASSESSMENT_API_BASE + '/schools.php';
const SCHOOL_SEARCH_RADII = [100000]; // metres: search within 100 km of the student's location
const SCHOOL_EXPAND_RADIUS = 100000;  // metres: widest radius offered by the wider-area button
const SCHOOL_DISPLAY_LIMIT = 25;    // max institutions shown in the list
const SCHOOL_BACKEND_TIMEOUT = 75000;   // ms — backend location search
const NOMINATIM_TIMEOUT = 15000;        // ms — city lookup fallback
const SCHOOL_RESOLVE_TIMEOUT = 30000;   // ms — place lookup incl. a province's city list

const RIASEC_COURSE_MAP = {
  R: {
    label: 'Realistic (R)',
    courses: [
      'Mechanical Engineering',
      'Civil Engineering',
      'Electrical Engineering',
      'Automotive Engineering Technology',
      'Agriculture / Agricultural Business',
      'Fisheries / Marine Engineering',
      'Construction Management',
      'Geodetic Engineering',
      'Industrial Technology / Mechatronics',
    ],
  },
  I: {
    label: 'Investigative (I)',
    courses: [
      'Computer Science',
      'Biology / Microbiology',
      'Chemistry',
      'Physics',
      'Mathematics / Statistics',
      'Medical Laboratory Science',
      'Pharmacy',
      'Psychology',
      'Data Science',
    ],
  },
  A: {
    label: 'Artistic (A)',
    courses: [
      'Architecture',
      'Fine Arts / Visual Arts',
      'Graphic Design / Multimedia Arts',
      'Advertising',
      'Literature / Language Studies',
      'Music / Performing Arts',
      'Film and Audiovisual Production',
      'Fashion Design / Industrial Design',
      'Interior Design',
    ],
  },
  S: {
    label: 'Social (S)',
    courses: [
      'Education / Teacher Education',
      'Nursing',
      'Psychology',
      'Social Work',
      'Guidance and Counseling',
      'Physical Therapy',
      'Nutrition and Dietetics',
      'Speech-Language Pathology',
      'Community Development',
    ],
  },
  E: {
    label: 'Enterprising (E)',
    courses: [
      'Business Administration',
      'Marketing Management',
      'Entrepreneurship',
      'Human Resource Management',
      'Political Science / Public Administration',
      'Legal Management (Pre-Law)',
      'International Business',
      'Hospitality / Hotel Management',
    ],
  },
  C: {
    label: 'Conventional (C)',
    courses: [
      'Accountancy',
      'Accounting Information System',
      'Banking and Finance',
      'Information Technology',
      'Logistics and Supply Chain Management',
      'Customs Administration',
      'Library and Information Science',
      'Office Administration',
    ],
  },
};

let schoolSearch = null;   // { lat, lon, label, radius, schools }
let lastSchoolQuery = null; // last attempted search, used by the retry button
let schoolCityChoices = []; // cities of the province currently in the dropdown
let schoolAreaLabel    = ''; // e.g. "Nueva Ecija" — used in status text/labels
let schoolAreaQuery    = ''; // the text that opened the dropdown (to detect edits)

// Every RIASEC category of the student's result (all categories tied for
// the highest score when the result is a tie, e.g. "RC" → ['R', 'C']).
// The result is read from the displayed code so the school finder always
// agrees with what the student sees on the results screen.
function getDominantLettersForResult() {
  const codeEl = document.getElementById('holland-code');
  const code = codeEl ? String(codeEl.textContent || '').trim().toUpperCase() : '';
  return code.split('').filter(letter => Object.prototype.hasOwnProperty.call(RIASEC_COURSE_MAP, letter));
}

function getDominantLetterForResult() {
  const letters = getDominantLettersForResult();
  return letters[0] || '';
}

function resetSchoolFinder() {
  const btn    = document.getElementById('btn-find-schools');
  const panel  = document.getElementById('school-finder-panel');
  const courses = document.getElementById('school-finder-courses');
  const results = document.getElementById('school-finder-results');
  const details = document.getElementById('school-finder-details');
  if (btn) btn.classList.remove('d-none');
  if (panel) panel.classList.add('d-none');
  if (courses) courses.innerHTML = '';
  if (results) { results.classList.remove('d-none'); results.innerHTML = ''; }
  if (details) { details.className = 'd-none mt-3'; details.innerHTML = ''; }
  showSchoolStatus('', '');
  const cityInput = document.getElementById('school-city-input');
  if (cityInput) cityInput.value = '';
  hideSchoolCityPicker();
  schoolSearch = null;
  lastSchoolQuery = null;
}

function showSchoolStatus(kind, message) {
  const el = document.getElementById('school-finder-status');
  if (!el) return;
  if (!message) {
    el.className = 'alert d-none';
    el.textContent = '';
    return;
  }
  el.className = `alert ${kind} school-status mb-0`;
  el.textContent = message;
}

// Status text that may contain a button (built with escapeHtml first).
function showSchoolStatusHtml(kind, html) {
  const el = document.getElementById('school-finder-status');
  if (!el) return;
  el.className = `alert ${kind} school-status mb-0`;
  el.innerHTML = html;
}

function showSchoolLoading(message) {
  const el = document.getElementById('school-finder-status');
  if (!el) return;
  el.className = 'alert alert-info school-status mb-0';
  el.innerHTML =
    '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
    `<span>${escapeHtml(message)}</span>`;
}

function showSchoolError(message) {
  const el = document.getElementById('school-finder-status');
  if (!el) return;
  el.className = 'alert alert-danger school-status mb-0';
  el.innerHTML =
    `<span>${escapeHtml(message)}</span>` +
    (lastSchoolQuery
      ? ' <button type="button" id="btn-school-retry" class="btn btn-sm btn-outline-danger ms-2">Try again</button>'
      : '');
  const retry = document.getElementById('btn-school-retry');
  if (retry) retry.addEventListener('click', retrySchoolSearch);
}

function retrySchoolSearch() {
  if (!lastSchoolQuery) return;
  loadSchoolsForLocation(lastSchoolQuery.lat, lastSchoolQuery.lon, lastSchoolQuery.label);
}

function openSchoolFinder() {
  const btn   = document.getElementById('btn-find-schools');
  const panel = document.getElementById('school-finder-panel');
  if (btn) btn.classList.add('d-none');
  if (panel) panel.classList.remove('d-none');
  renderSchoolCourses();
  showSchoolStatus('', '');
  const results = document.getElementById('school-finder-results');
  if (results) { results.classList.remove('d-none'); results.innerHTML = ''; }
  const details = document.getElementById('school-finder-details');
  if (details) { details.className = 'd-none mt-3'; details.innerHTML = ''; }
  schoolSearch = null;
}

function renderSchoolCourses() {
  const box = document.getElementById('school-finder-courses');
  if (!box) return;

  const letters = getDominantLettersForResult();
  if (!letters.length) {
    box.innerHTML = '<p class="text-muted mb-0">Complete the assessment to see suggested courses.</p>';
    return;
  }

  // One block per RIASEC category of the result (a tied result shows
  // every tied category's recommended courses).
  box.innerHTML = letters.map(letter => {
    const meta = CATEGORY_META[letter];
    const courses = RIASEC_COURSE_MAP[letter].courses;
    return `
    <div class="mb-3">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="score-bar-letter ${meta.colorClass}" style="width:22px;height:22px;font-size:.7rem;">${letter}</span>
        <strong>${escapeHtml(meta.name)}</strong>
      </div>
      <div class="career-tags">
        ${courses.map(c => `<span class="career-tag">${escapeHtml(c)}</span>`).join('')}
      </div>
    </div>`;
  }).join('');
}

/* ─── Location ───────────────────────────────────────────── */
function useCurrentLocation() {
  if (!navigator.geolocation) {
    showSchoolStatus('alert-warning',
      'This browser cannot share a location. Please enter your city or municipality below.');
    focusSchoolCityInput();
    return;
  }

  showSchoolStatus('alert-info', 'Getting your location…');
  navigator.geolocation.getCurrentPosition(
    position => {
      loadSchoolsForLocation(
        position.coords.latitude,
        position.coords.longitude,
        'your current location'
      );
    },
    () => {
      showSchoolStatus('alert-warning',
        'Location access is unavailable. Please enter your city or municipality below.');
      focusSchoolCityInput();
    },
    { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
  );
}

function focusSchoolCityInput() {
  const input = document.getElementById('school-city-input');
  if (input) input.focus();
}

async function searchSchoolsByCity() {
  const input = document.getElementById('school-city-input');
  const query = input ? input.value.trim() : '';
  if (!query) {
    showSchoolStatus('alert-warning', 'Enter your city or municipality first.');
    focusSchoolCityInput();
    return;
  }

  // A province is already resolved and its city dropdown is open and
  // unchanged: search the chosen city, or ask the student to pick one.
  const picker = document.getElementById('school-city-picker');
  const pickerOpen = picker && !picker.classList.contains('d-none');
  if (pickerOpen && query === schoolAreaQuery) {
    const city = selectedSchoolCityChoice();
    if (city) {
      await searchSchoolCityChoice(city, schoolAreaLabel);
    } else {
      showSchoolStatus('alert-warning', 'Select a city or municipality from the list first.');
    }
    return;
  }

  hideSchoolCityPicker();
  showSchoolLoading(`Looking up “${query}”…`);
  const details = document.getElementById('school-finder-details');
  if (details) { details.className = 'd-none mt-3'; details.innerHTML = ''; }

  try {
    const place = await resolvePlace(query);
    if (!place) {
      showSchoolStatus('alert-warning',
        `We could not find “${query}”. Try another city or municipality, or use your current location.`);
      return;
    }
    // Province/region → let the student choose a city first; the chosen
    // city becomes the reference point of the 100 km search.
    if (place.kind === 'province' && place.cities.length >= 2) {
      showSchoolCityPicker(place, query);
      return;
    }
    await loadSchoolsForLocation(place.lat, place.lon, place.label);
  } catch (error) {
    showSchoolError(error.message ||
      'We could not look up that location. Check your internet connection and try again.');
  }
}

/* ─── Province → city dropdown ────────────────────────────── */
// The city the student picked in the dropdown (null when only the
// placeholder is selected).
function selectedSchoolCityChoice() {
  const select = document.getElementById('school-city-select');
  if (!select) return null;
  const raw = String(select.value === undefined || select.value === null ? '' : select.value);
  if (raw === '') return null;               // placeholder — nothing picked yet
  const index = Number(raw);
  return Number.isInteger(index) && index >= 0 && index < schoolCityChoices.length
    ? schoolCityChoices[index]
    : null;
}

function showSchoolCityPicker(place, query) {
  const picker = document.getElementById('school-city-picker');
  const select = document.getElementById('school-city-select');
  const labelEl = document.getElementById('school-city-picker-label');

  if (!picker || !select) {
    // No dropdown available — search the area's centre directly.
    loadSchoolsForLocation(place.lat, place.lon, place.label);
    return;
  }

  schoolCityChoices = Array.isArray(place.cities) ? place.cities : [];
  schoolAreaLabel = place.province || place.label;
  schoolAreaQuery = query;

  if (labelEl) labelEl.textContent = `Select a city or municipality in ${schoolAreaLabel}:`;
  select.innerHTML = '<option value="">— Select a city or municipality —</option>' +
    schoolCityChoices.map((city, index) =>
      `<option value="${index}">${escapeHtml(city.name)}</option>`).join('');
  select.value = '';
  picker.classList.remove('d-none');

  showSchoolStatus('alert-info',
    `${schoolCityChoices.length} cities and municipalities were found in ${escapeHtml(schoolAreaLabel)}. ` +
    'Choose one to search for colleges and universities within 100 km.');
}

function hideSchoolCityPicker() {
  const picker = document.getElementById('school-city-picker');
  const select = document.getElementById('school-city-select');
  if (picker) picker.classList.add('d-none');
  if (select) select.innerHTML = '';
  schoolCityChoices = [];
  schoolAreaLabel = '';
  schoolAreaQuery = '';
}

// The student picked a city → search it right away (req: select a
// city before searching).
async function onSchoolCityPicked() {
  const city = selectedSchoolCityChoice();
  if (!city) return;
  const areaLabel = schoolAreaLabel;
  await searchSchoolCityChoice(city, areaLabel);
}

async function searchSchoolCityChoice(city, areaLabel) {
  hideSchoolCityPicker();
  const label = areaLabel ? `${city.name}, ${areaLabel}` : String(city.name);
  await loadSchoolsForLocation(Number(city.lat), Number(city.lon), label);
}

/* ─── Location lookup (backend first, directory as fallback) ─ */
async function fetchWithTimeout(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function geocodeCity(query) {
  // 1) Backend proxy (cached server-side, keeps a single caller identity)
  try {
    const url = `${API_SCHOOLS}?action=geocode&q=${encodeURIComponent(query)}`;
    const response = await fetchWithTimeout(url, { headers: { Accept: 'application/json' } }, NOMINATIM_TIMEOUT);
    const data = await readApiJson(response);
    if (data && data.success) {
      if (!data.found) return null;
      return { lat: Number(data.lat), lon: Number(data.lon), label: String(data.label || query) };
    }
    if (data && data.error) {
      const error = new Error(data.error);
      error.fallback = false;
      throw error;
    }
  } catch (error) {
    if (error && error.fallback === false) throw error;
    // Backend unreachable — fall through to a direct lookup below.
  }

  // 2) Direct directory lookup
  try {
    const url = `${NOMINATIM_API}?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`;
    const response = await fetchWithTimeout(url, { headers: { Accept: 'application/json' } }, NOMINATIM_TIMEOUT);
    if (!response.ok) throw new Error('lookup failed');
    const rows = await response.json();
    if (!Array.isArray(rows) || rows.length === 0) return null;
    return {
      lat:  parseFloat(rows[0].lat),
      lon:  parseFloat(rows[0].lon),
      label: String(rows[0].display_name || query),
    };
  } catch (error) {
    throw new Error('We could not look up that location. Check your internet connection and try again.');
  }
}

/* Resolve a typed place for the manual search. Returns
   { kind: 'province'|'place', lat, lon, label, province, cities } —
   provinces (OSM admin levels 3–4) also carry their cities and
   municipalities for the dropdown. Falls back to a plain geocode
   (kind 'place') when the backend or the city list is unavailable. */
async function resolvePlace(query) {
  try {
    const url = `${API_SCHOOLS}?action=geocode&details=1&q=${encodeURIComponent(query)}`;
    const response = await fetchWithTimeout(url, { headers: { Accept: 'application/json' } }, SCHOOL_RESOLVE_TIMEOUT);
    const data = await readApiJson(response);
    if (data && data.success) {
      if (!data.found) return null;
      return {
        kind:     data.kind === 'province' ? 'province' : 'place',
        lat:      Number(data.lat),
        lon:      Number(data.lon),
        label:    String(data.label || query),
        province: String(data.province || ''),
        cities:   Array.isArray(data.cities) ? data.cities : [],
      };
    }
    if (data && data.error) {
      const error = new Error(data.error);
      error.fallback = false;
      throw error;
    }
  } catch (error) {
    if (error && error.fallback === false) throw error;
    // Backend unreachable — resolve directly below (no city list).
  }

  const place = await geocodeCity(query);
  return place
    ? { kind: 'place', lat: place.lat, lon: place.lon, label: place.label, province: '', cities: [] }
    : null;
}

/* ─── School directory (colleges & universities, verified by the backend) ── */
// Courses recommended for the student's actual RIASEC code — every
// Returns courses for the student's highest-scoring RIASEC category (or
// all categories tied for the highest score). Uses state.lastScores when
// available; falls back to the displayed Holland code letter.
function selectedRiasecCourses() {
  const seen    = new Set();
  const courses = [];

  const scores = state.lastScores;
  if (scores && typeof scores === 'object') {
    const values   = Object.values(scores).map(Number);
    const topScore = Math.max(...values);

    // Only include categories tied exactly at the top score.
    const letters = Object.keys(scores)
      .filter(l => Object.prototype.hasOwnProperty.call(RIASEC_COURSE_MAP, l)
                && Number(scores[l]) === topScore);

    for (const letter of letters) {
      for (const course of RIASEC_COURSE_MAP[letter].courses) {
        if (!seen.has(course)) { seen.add(course); courses.push(course); }
      }
    }
    if (courses.length) return courses;
  }

  // Fallback: use the dominant letter shown in the results screen.
  for (const letter of getDominantLettersForResult()) {
    for (const course of RIASEC_COURSE_MAP[letter].courses) {
      if (!seen.has(course)) { seen.add(course); courses.push(course); }
    }
  }
  return courses;
}

async function fetchSchoolsFromBackend(lat, lon, radius, courses) {
  const url = `${API_SCHOOLS}?action=search`
    + `&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`
    + `&radius=${encodeURIComponent(radius)}`
    + `&courses=${encodeURIComponent(courses.join('|'))}`;

  let response;
  try {
    response = await fetchWithTimeout(url, { headers: { Accept: 'application/json' } }, SCHOOL_BACKEND_TIMEOUT);
  } catch (networkError) {
    const error = new Error('The school directory could not be reached. Check your internet connection and try again.');
    throw error;
  }

  let data;
  try {
    data = await readApiJson(response);
  } catch (parseError) {
    throw new Error('The school directory returned an invalid response. Please try again.');
  }

  if (!data || data.success !== true) {
    // The backend answered — show its message instead of masking it.
    throw new Error(
      (data && data.error)
      || 'The school directory is busy right now. Please try again in a few seconds.'
    );
  }

  return {
    schools: Array.isArray(data.schools) ? data.schools : [],
    totalInArea: Number(data.total_in_area) || 0,
  };
}

async function loadSchoolsForLocation(lat, lon, label, radiusOverride) {
  hideSchoolCityPicker();   // any open province dropdown is no longer relevant
  const courses = selectedRiasecCourses();
  if (!courses.length) {
    showSchoolStatus('alert-warning',
      'Complete the assessment first so we can match schools to your RIASEC result.');
    return;
  }

  // Single search within 100 km of the location (no 25 km limit).
  // radiusOverride is used by the "search a wider area" button.
  const radii = radiusOverride ? [radiusOverride] : SCHOOL_SEARCH_RADII;

  schoolSearch = { lat, lon, label, radius: 0, schools: [], totalInArea: 0 };
  lastSchoolQuery = { lat, lon, label };

  const results = document.getElementById('school-finder-results');
  if (results) { results.classList.remove('d-none'); results.innerHTML = ''; }
  const details = document.getElementById('school-finder-details');
  if (details) { details.className = 'd-none mt-3'; details.innerHTML = ''; }

  for (const radius of radii) {
    showSchoolLoading(
      `Looking for colleges and universities near ${label} within ${formatDistance(radius)}…`);

    let payload;
    try {
      payload = await fetchSchoolsFromBackend(lat, lon, radius, courses);
    } catch (backendError) {
      showSchoolError((backendError && backendError.message)
        || 'We could not reach the school directory. Please check your connection and try again.');
      return;
    }

    schoolSearch.radius = radius;
    schoolSearch.totalInArea = payload.totalInArea;

    const isLastRadius = radius === radii[radii.length - 1];
    if (payload.schools.length >= 3 || isLastRadius) {
      schoolSearch.schools = payload.schools;
      renderSchoolList();
      return;
    }
  }
}

function schoolHasProgramMatch(school) {
  return Array.isArray(school.matched_courses) && school.matched_courses.length > 0;
}

function renderSchoolItem(entry) {
  const school = entry.school;
  const matched = Array.isArray(school.matched_courses) ? school.matched_courses : [];
  const shown   = matched.slice(0, 3);
  const more    = matched.length - shown.length;

  const programLine = matched.length
    ? `<span class="school-item-programs"><i class="bi bi-bookmark-check-fill"></i>${escapeHtml(shown.join(', '))}`
      + (more > 0 ? `<span class="ms-1">+${more} more</span>` : '') + '</span>'
    : '<span class="school-item-programs school-item-programs-none">' +
      '<i class="bi bi-x-circle"></i>No matching programs found for your RIASEC result</span>';

  return `
    <button type="button" class="school-item" data-school-index="${entry.index}">
      <span class="school-item-head">
        <span class="school-item-name">${escapeHtml(school.name)}</span>
        <span class="school-badge school-badge-degree">College / University</span>
      </span>
      <span class="school-item-meta">
        <i class="bi bi-geo-alt"></i>
        ${escapeHtml(school.address || 'Address not listed')}
        &middot; ${formatDistance(school.distance)} away
      </span>
      ${programLine}
    </button>
  `;
}

function renderSchoolList() {
  const results = document.getElementById('school-finder-results');
  if (!results || !schoolSearch) return;

  // Only colleges and universities that offer at least one program
  // matching the student's RIASEC result are ever displayed.
  const entries = schoolSearch.schools
    .map((school, index) => ({ school, index }))
    .filter(entry => entry.school.is_degree !== false && schoolHasProgramMatch(entry.school));

  const count = n => `${n} college${n === 1 ? '' : 's'} or universit${n === 1 ? 'y' : 'ies'}`;

  if (!entries.length) {
    results.innerHTML = '';
    const where = `within ${formatDistance(schoolSearch.radius)} of ${escapeHtml(schoolSearch.label)}`;
    const totalFound = Math.max(Number(schoolSearch.totalInArea) || 0, schoolSearch.schools.length);
    if (totalFound > 0) {
      // Colleges exist nearby, but none of them offers a recommended program.
      showSchoolStatus('alert-warning',
        `${count(totalFound)} ${totalFound === 1 ? 'was' : 'were'} found ${where}, but none ` +
        `${totalFound === 1 ? 'offers' : 'offer'} programs matching your RIASEC result. ` +
        'Try a different city or municipality, or use your current location.');
    } else if (schoolSearch.radius < SCHOOL_EXPAND_RADIUS) {
      // Nothing within the search radius — offer a wider search or a city lookup (req. 7).
      showSchoolStatusHtml('alert-warning',
        `No colleges or universities were found ${where}. ` +
        'Search a wider area, or type your city or municipality below.' +
        `<button type="button" id="btn-expand-radius" class="btn btn-sm btn-outline-warning ms-2">` +
        `Search within ${formatDistance(SCHOOL_EXPAND_RADIUS)}</button>`);
      const expandBtn = document.getElementById('btn-expand-radius');
      if (expandBtn) {
        expandBtn.addEventListener('click', () => {
          loadSchoolsForLocation(schoolSearch.lat, schoolSearch.lon, schoolSearch.label,
            SCHOOL_EXPAND_RADIUS);
        });
      }
    } else {
      showSchoolStatus('alert-warning',
        `No colleges or universities were found ${where}. ` +
        'Try a different city or municipality, or use your current location.');
    }
    return;
  }

  const where = `within ${formatDistance(schoolSearch.radius)} of ${escapeHtml(schoolSearch.label)}`;
  showSchoolStatus('alert-success',
    `Found ${count(entries.length)} offering programs recommended for your RIASEC result ${where}.`);

  const visible = entries.slice(0, SCHOOL_DISPLAY_LIMIT);
  const sections = ['<p class="school-group-title">Offering your recommended programs</p>'];
  sections.push(visible.map(entry => renderSchoolItem(entry)).join(''));
  if (entries.length > visible.length) {
    sections.push(
      `<p class="form-text mb-0">Showing the ${visible.length} closest of ` +
      `${entries.length} institutions offering your recommended programs. ` +
      `Search a more specific city or municipality to narrow the list.</p>`
    );
  }

  results.innerHTML = sections.join('');

  results.querySelectorAll('.school-item').forEach(button => {
    button.addEventListener('click', () => selectSchool(Number(button.dataset.schoolIndex)));
  });
}

/* ─── School details ─────────────────────────────────────── */
function selectSchool(index) {
  if (!schoolSearch || !schoolSearch.schools[index]) return;
  renderSchoolDetails(index);

  const results = document.getElementById('school-finder-results');
  if (results) results.classList.add('d-none');

  const details = document.getElementById('school-finder-details');
  if (details) {
    details.classList.remove('d-none');
    details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

function closeSchoolDetails() {
  const details = document.getElementById('school-finder-details');
  if (details) { details.className = 'd-none mt-3'; details.innerHTML = ''; }
  const results = document.getElementById('school-finder-results');
  if (results) {
    results.classList.remove('d-none');
    results.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

function schoolDetailRow(label, valueHtml) {
  return `
    <div class="school-detail-row">
      <span class="school-detail-label">${escapeHtml(label)}</span>
      <div class="school-detail-value">${valueHtml}</div>
    </div>
  `;
}

function schoolLink(url, text) {
  const safe = safeSchoolUrl(url);
  const label = escapeHtml(text || url);
  if (!safe) return label;
  return `<a href="${escapeHtml(safe)}" target="_blank" rel="noopener noreferrer">${label}</a>`;
}

function safeSchoolUrl(raw) {
  const value = String(raw || '').trim();
  if (!value) return '';
  if (/^https?:\/\//i.test(value)) return value;
  if (/^\/\//.test(value)) return `https:${value}`;
  if (/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(\/.*)?$/i.test(value)) return `https://${value}`;
  return '';
}

function renderSchoolDetails(index) {
  const details = document.getElementById('school-finder-details');
  if (!details || !schoolSearch) return;

  const school = schoolSearch.schools[index];
  if (!school) return;

  const rows = [];

  rows.push(schoolDetailRow('Complete address', escapeHtml(school.address || 'Not listed')));

  rows.push(schoolDetailRow(
    'Distance',
    `${formatDistance(school.distance)} from ${escapeHtml(schoolSearch.label)}`
  ));

  rows.push(schoolDetailRow('School type', 'College / University'));

  if (school.operator) rows.push(schoolDetailRow('Operator', escapeHtml(school.operator)));

  // Programs recommended for the student's RIASEC result, matched from the school's course list.
  const resultNames = getDominantLettersForResult()
    .map(l => (CATEGORY_META[l] ? CATEGORY_META[l].name : ''))
    .filter(Boolean)
    .join(', ');
  const matched = Array.isArray(school.matched_courses) ? school.matched_courses : [];
  const matchedHtml = matched.length
    ? `<div class="career-tags mb-1">${matched.map(c => `<span class="career-tag">${escapeHtml(c)}</span>`).join('')}</div>
       <span class="form-text">These programs match your <strong>${escapeHtml(resultNames || 'RIASEC')}</strong> result.
       Confirm with the school that the program is currently offered before applying.</span>`
    : `<span class="form-text"><strong>No matching programs found.</strong> None of the programs on record for this school match your RIASEC result.`
      + (school.courses
        ? ' Its listed programs are shown below — check them against your RIASEC result.'
        : ' Contact the school for its current program offerings.')
      + '</span>';
  rows.push(schoolDetailRow('Recommended programs at this school', matchedHtml));

  if (school.courses) {
    rows.push(schoolDetailRow('Programs listed in the directory', escapeHtml(school.courses)));
  }

  // Contact details — shown only when available
  if (school.phone) rows.push(schoolDetailRow('Contact number', escapeHtml(school.phone)));

  if (school.email) rows.push(schoolDetailRow('Email', escapeHtml(school.email)));

  if (school.website) rows.push(schoolDetailRow('Official website', schoolLink(school.website, school.website)));

  if (school.facebook) rows.push(schoolDetailRow('Facebook page', schoolLink(school.facebook, school.facebook)));

  if (school.instagram) rows.push(schoolDetailRow('Instagram', schoolLink(school.instagram, school.instagram)));

  if (school.twitter) rows.push(schoolDetailRow('X / Twitter', schoolLink(school.twitter, school.twitter)));

  if (school.description) rows.push(schoolDetailRow('Other information', escapeHtml(school.description)));

  if (school.opening_hours) rows.push(schoolDetailRow('Opening hours', escapeHtml(school.opening_hours)));

  if (school.fee) rows.push(schoolDetailRow('Fees', escapeHtml(school.fee)));

  if (school.wheelchair) {
    const wheelchair = school.wheelchair;
    const accessible = wheelchair === 'yes' ? 'Yes — accessible' : wheelchair === 'limited' ? 'Limited access' : 'Not specified';
    rows.push(schoolDetailRow('Accessibility', accessible));
  }

  if (school.osm_url) rows.push(schoolDetailRow('Directory record', schoolLink(school.osm_url, 'View source record')));

  details.innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-2">
      <p class="school-step-title mb-0">School details</p>
      <button type="button" id="btn-school-back" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to list
      </button>
    </div>
    <div class="school-detail-card">
      <h4 class="school-detail-name">${escapeHtml(school.name)}</h4>
      ${rows.join('')}
      <p class="form-text mb-0 mt-3">
        School details come from the OpenStreetMap directory and the school database. Matched programs are based on each school's listed courses. Always confirm program availability, tuition fees, and admission requirements directly with the school.
      </p>
    </div>
  `;

  const backButton = document.getElementById('btn-school-back');
  if (backButton) backButton.addEventListener('click', closeSchoolDetails);
}

/* ─── Small helpers ──────────────────────────────────────── */
function formatDistance(metres) {
  if (!isFinite(metres) || metres < 0) return 'distance not available';
  if (metres < 1000) return `${Math.round(metres)} m`;
  const km = metres / 1000;
  return `${km < 10 ? km.toFixed(1) : Math.round(km)} km`;
}

/* ─── Reset / Restart ────────────────────────────────────── */
function confirmReset() {
  const modal = new bootstrap.Modal(document.getElementById('resetModal'));
  modal.show();
}

function resetConfirmed() {
  const modalEl  = document.getElementById('resetModal');
  const instance = bootstrap.Modal.getInstance(modalEl);
  if (instance) instance.hide();
  restartAssessment();
}

function restartAssessment() {
  clearStorage();
  state.respondent        = { full_name: '', student_id: '', section: '' };
  state.shuffledQuestions = [];
  state.currentIndex      = 0;
  state.answers           = {};
  state.sessionActive     = false;
  state.submitted         = false;

  // Clear form fields
  const fields = ['input-fullname', 'input-studentid', 'input-section'];
  fields.forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.value = ''; el.classList.remove('is-valid', 'is-invalid'); }
  });

  // Hide banners and submit-eval button
  const successBanner = document.getElementById('submission-success-banner');
  const dupBanner     = document.getElementById('duplicate-warning-banner');
  const submitWrap    = document.getElementById('submit-eval-wrap');
  if (successBanner) successBanner.classList.add('d-none');
  if (dupBanner)     dupBanner.classList.add('d-none');
  if (submitWrap)    submitWrap.classList.add('d-none');

  const banner = document.getElementById('resume-banner');
  if (banner) banner.classList.add('d-none');

  // Return the shared AI Assistant to general (non-personalized) mode
  if (window.RIASECAssistant) {
    try {
      window.RIASECAssistant.resetResults();
    } catch (error) {
      console.warn('AI assistant could not be reset:', error);
    }
  }

  showScreen('screen-welcome');
}

/* ─── UI Helpers ─────────────────────────────────────────── */
function showOverlay(visible) {
  const overlay = document.getElementById('submit-overlay');
  if (overlay) overlay.classList.toggle('d-none', !visible);
}

function showErrorToast(message) {
  const toastEl  = document.getElementById('error-toast');
  const toastMsg = document.getElementById('error-toast-msg');
  if (!toastEl) return;
  if (toastMsg) toastMsg.textContent = message;
  const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 6000 });
  toast.show();
}

function showSuccessBanner() {
  const banner = document.getElementById('submission-success-banner');
  const dupBanner = document.getElementById('duplicate-warning-banner');
  if (dupBanner) dupBanner.classList.add('d-none');
  if (banner) {
    banner.classList.remove('d-none');
    banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function showDuplicateBanner(customMessage) {
  const banner    = document.getElementById('duplicate-warning-banner');
  const okBanner  = document.getElementById('submission-success-banner');
  const msgEl     = document.getElementById('duplicate-warning-text');
  if (okBanner) okBanner.classList.add('d-none');
  if (msgEl && customMessage) msgEl.textContent = customMessage;
  if (banner) {
    banner.classList.remove('d-none');
    banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.appendChild(document.createTextNode(str));
  return div.innerHTML;
}

/* ─── Entry Point ────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initApp();
  showScreen('screen-welcome');

  // Admin button: file:// and VS Code Live Server cannot execute PHP,
  // so the browser downloads admin/index.php instead of rendering it.
  // Send the user to XAMPP, which runs PHP correctly.
  const adminBtn = document.getElementById('btn-admin');
  if (adminBtn) {
    adminBtn.addEventListener('click', (e) => {
      const onFile = window.location.protocol === 'file:';
      if (onFile || isLiveServer) {
        e.preventDefault();
        window.location.href = 'http://localhost/riasec/admin/index.php';
      }
    });
  }
});

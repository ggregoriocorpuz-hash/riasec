// Mock-DOM tests for script.js — school finder + province city picker.
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const ROOT = __dirname;
const source = fs.readFileSync(path.join(ROOT, 'script.js'), 'utf8');

let pass = 0, fail = 0;
function nodeCheck(label, cond, extra) {
  if (cond) { pass++; console.log('PASS  ' + label); }
  else { fail++; console.log('FAIL  ' + label + (extra !== undefined ? '\n        got: ' + JSON.stringify(extra) : '')); }
}

/* ── DOM stubs ─────────────────────────────────────────── */
function escapeText(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function stripTags(html) { return String(html).replace(/<[^>]*>/g, ''); }

const elements = new Map();
function makeEl(id) {
  const el = {
    id, _text: '', _html: null, value: '', className: '', style: {},
    dataset: {}, children: [], _listeners: {},
    checked: false, disabled: false, hidden: false, min: '', placeholder: '',
    offsetTop: 0, offsetHeight: 0, scrollTop: 0, scrollHeight: 0, clientHeight: 0,
    options: [], selectedIndex: -1,
    addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); },
    removeEventListener() {},
    appendChild(c) { if (c && c.__textNode !== undefined) this._text += c.__textNode; this.children.push(c); return c; },
    querySelectorAll() { return []; },
    querySelector() { return null; },
    closest() { return null; },
    setAttribute(k, v) { this['attr_' + k] = v; },
    getAttribute(k) { return this['attr_' + k] === undefined ? null : this['attr_' + k]; },
    removeAttribute(k) { delete this['attr_' + k]; },
    focus() {}, blur() {}, click() {}, scrollIntoView() {},
    matches() { return false; },
    getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0, bottom: 0, right: 0 }; },
    insertAdjacentHTML(pos, html) { this._html = (this._html || '') + html; this._text = ''; },
  };
  Object.defineProperty(el, 'innerHTML', {
    get() { return (this._html !== null && this._html !== undefined) ? this._html : escapeText(this._text || ''); },
    set(v) { this._html = String(v); this._text = ''; this.children = []; },
  });
  Object.defineProperty(el, 'textContent', {
    get() { return this._text ? this._text : stripTags(this._html || ''); },
    set(v) { this._text = String(v); this._html = null; this.children = []; },
  });
  el.classList = {
    _s: new Set(),
    add(...c) { c.forEach(x => this._s.add(x)); },
    remove(...c) { c.forEach(x => this._s.delete(x)); },
    toggle(c, f) {
      const want = f === undefined ? !this._s.has(c) : !!f;
      if (want) this._s.add(c); else this._s.delete(c);
      return want;
    },
    contains(c) { return this._s.has(c); },
  };
  return el;
}

const document = {
  readyState: 'complete',
  title: '',
  cookie: '',
  getElementById(id) { if (!elements.has(id)) elements.set(id, makeEl(id)); return elements.get(id); },
  createElement(tag) { return makeEl(tag + '@created'); },
  createTextNode(t) { return { __textNode: String(t) }; },
  querySelector() { return null; },
  querySelectorAll() { return []; },
  addEventListener() {},
  removeEventListener() {},
  body: makeEl('body'),
  documentElement: makeEl('html'),
};

function makeStorage() {
  const d = {};
  return { getItem: k => (k in d ? d[k] : null), setItem: (k, v) => { d[k] = String(v); }, removeItem: k => { delete d[k]; }, clear() { for (const k of Object.keys(d)) delete d[k]; } };
}

const locationObj = {
  pathname: '/riasec/index.html', port: '80', hostname: 'localhost',
  href: 'http://localhost/riasec/index.html', search: '', hash: '', protocol: 'http:',
  assign() {}, replace() {},
};
const winListeners = {};
const window = {
  location: locationObj,
  addEventListener(t, fn) { (winListeners[t] = winListeners[t] || []).push(fn); },
  removeEventListener() {},
  innerWidth: 1280, innerHeight: 900,
  scrollTo() {}, scrollBy() {},
  getComputedStyle() { return { getPropertyValue() { return ''; } }; },
  confirm() { return true; }, alert() {}, prompt() { return null; },
  matchMedia() { return { matches: false, addEventListener() {}, addListener() {} }; },
  requestAnimationFrame(cb) { return setTimeout(cb, 0); },
  cancelAnimationFrame(id) { clearTimeout(id); },
  localStorage: makeStorage(),
  sessionStorage: makeStorage(),
};

let geoCalls = 0;
const navigator = {
  userAgent: 'test-agent',
  geolocation: {
    getCurrentPosition(ok, err) { geoCalls++; if (err) err({ code: 1, message: 'denied' }); },
    watchPosition() { geoCalls++; return 1; },
    clearWatch() {},
  },
  clipboard: { writeText: async () => {} },
};

// URL-routed fetch mock: routes = [{ match, payload }]
let fetchRoutes = [];
const fetchUrls = [];
const fetchFn = async (url) => {
  fetchUrls.push(String(url));
  const route = fetchRoutes.find(r => String(url).includes(r.match));
  const payload = route ? route.payload : null;
  const body = JSON.stringify(payload);
  return { ok: true, status: 200, text: async () => body, json: async () => payload };
};

const bootstrap = {
  Toast: { getOrCreateInstance: () => ({ show() {}, hide() {} }) },
  Modal: {
    getOrCreateInstance: () => ({ show() {}, hide() {}, toggle() {} }),
    getInstance: () => ({ show() {}, hide() {}, toggle() {} }),
  },
};

const sandbox = {
  window, document, location: locationObj,
  localStorage: window.localStorage, sessionStorage: window.sessionStorage,
  navigator, fetch: fetchFn, bootstrap,
  AbortController, setTimeout, clearTimeout, setInterval, clearInterval,
  console, URL, URLSearchParams, TextEncoder, TextDecoder, atob, btoa,
  __results: [],
  __fetchUrls: fetchUrls,
  __geoCalls: () => geoCalls,
  __setRoutes: r => { fetchRoutes = r; },
};
vm.createContext(sandbox);

/* ── Test code appended to the same script ── */
const testCode = `
;(async () => {
  const R = globalThis.__results;
  const ttCheck = (label, cond, extra) => R.push({ label, ok: !!cond, extra: cond ? undefined : extra });
  const ttEl = id => document.getElementById(id);
  const ttSetCode = code => { ttEl('holland-code').textContent = code; };
  const ttMk = (name, matched, isDegree) => ({
    name, address: '123 Test St', distance: 1500,
    is_degree: isDegree === undefined ? true : isDegree,
    matched_courses: matched, courses: null, website: null, phone: null,
    email: null, operator: null, description: null, opening_hours: null,
    fee: null, wheelchair: null, osm_url: null, facebook: null,
    instagram: null, twitter: null, city: 'Test City', province: 'Test Province',
    verified_by: 'test', verified_url: null,
  });
  const ttStatus = () => { const s = ttEl('school-finder-status'); s.textContent = ''; s.innerHTML = ''; return s; };
  const ttResults = () => ttEl('school-finder-results');
  const ttPicker = () => ttEl('school-city-picker');
  const ttPickerOpen = () => !ttPicker().classList.contains('d-none');
  const ttSearchUrls = () => globalThis.__fetchUrls.filter(u => u.includes('action=search'));

  // The picker starts hidden, exactly like index.html.
  ttPicker().classList.add('d-none');

  /* ═══ Regression: previous school-finder behaviour ═══ */
  ttCheck('SCHOOL_SEARCH_RADII = [100000]', JSON.stringify(SCHOOL_SEARCH_RADII) === JSON.stringify([100000]), SCHOOL_SEARCH_RADII);
  ttCheck('SCHOOL_EXPAND_RADIUS = 100000', SCHOOL_EXPAND_RADIUS === 100000, SCHOOL_EXPAND_RADIUS);

  ttSetCode('RC');
  ttCheck('letters for RC', JSON.stringify(getDominantLettersForResult()) === '["R","C"]', getDominantLettersForResult());
  ttSetCode('RI');
  ttCheck('letters for RI', JSON.stringify(getDominantLettersForResult()) === '["R","I"]');
  ttSetCode('AS');
  ttCheck('letters for AS', JSON.stringify(getDominantLettersForResult()) === '["A","S"]');
  ttSetCode('XY');
  ttCheck('invalid code -> []', getDominantLettersForResult().length === 0, getDominantLettersForResult());
  ttSetCode('---');
  ttCheck('--- -> []', getDominantLettersForResult().length === 0);
  ttSetCode('I');
  ttCheck('first-letter wrapper', getDominantLetterForResult() === 'I');

  ttSetCode('RC');
  const ttRc = selectedRiasecCourses();
  const ttExpRc = [...new Set([...RIASEC_COURSE_MAP.R.courses, ...RIASEC_COURSE_MAP.C.courses])];
  ttCheck('RC course union', JSON.stringify(ttRc) === JSON.stringify(ttExpRc), ttRc);
  ttSetCode('RI');
  const ttRi = selectedRiasecCourses();
  const ttExpRi = [...new Set([...RIASEC_COURSE_MAP.R.courses, ...RIASEC_COURSE_MAP.I.courses])];
  ttCheck('RI course union', JSON.stringify(ttRi) === JSON.stringify(ttExpRi));
  ttCheck('RI union deduplicated', new Set(ttRi).size === ttRi.length);
  ttSetCode('I');
  ttCheck('single-letter courses unchanged', JSON.stringify(selectedRiasecCourses()) === JSON.stringify(RIASEC_COURSE_MAP.I.courses));

  ttSetCode('RC');
  renderSchoolCourses();
  const ttHtml = ttEl('school-finder-courses').innerHTML;
  ttCheck('RC: two letter blocks', (ttHtml.match(/score-bar-letter/g) || []).length === 2, ttHtml.slice(0, 240));
  ttCheck('RC: shows Realistic', ttHtml.includes('Realistic'));
  ttCheck('RC: shows Conventional', ttHtml.includes('Conventional'));
  ttCheck('RC: all R+C courses listed', [...RIASEC_COURSE_MAP.R.courses, ...RIASEC_COURSE_MAP.C.courses].every(c => ttHtml.includes(c)));

  ttCheck('renderResults has no useCurrentLocation', !renderResults.toString().includes('useCurrentLocation'));
  ttCheck('renderResults opens school finder', renderResults.toString().includes('openSchoolFinder'));

  ttSetCode('RI');
  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 100000, totalInArea: 4, schools: [
    ttMk('Matched Uni', ['Psychology']),
    ttMk('Unmatched Uni', []),
    ttMk('Degree-less', ['Psychology'], false),
    ttMk('Matched College', ['Nursing']),
  ]};
  ttStatus(); renderSchoolList();
  const ttResA = ttResults().innerHTML;
  const ttStA = ttEl('school-finder-status').textContent;
  ttCheck('A: matched shown', ttResA.includes('Matched Uni') && ttResA.includes('Matched College'));
  ttCheck('A: unmatched hidden', !ttResA.includes('Unmatched Uni'));
  ttCheck('A: non-degree hidden', !ttResA.includes('Degree-less'));
  ttCheck('A: success message', ttStA.includes('Found 2 colleges or universities') && ttStA.includes('within 100 km of Test City'), ttStA);
  ttCheck('A: no "listed below" wording', !ttStA.includes('listed below'), ttStA);

  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 100000, totalInArea: 5, schools: [
    ttMk('Alpha U', []), ttMk('Beta U', []),
  ]};
  ttStatus(); renderSchoolList();
  const ttStB = ttEl('school-finder-status').textContent;
  ttCheck('B: none-offer message', ttStB.includes('5 colleges or universities were found') && ttStB.includes('but none offer programs matching'), ttStB);
  ttCheck('B: no schools listed', ttResults().innerHTML === '');

  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 25000, totalInArea: 0, schools: [] };
  ttStatus(); renderSchoolList();
  const ttStC = ttEl('school-finder-status').innerHTML;
  ttCheck('C: expand button offered', ttStC.includes('Search within 100 km'), ttStC);

  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 100000, totalInArea: 0, schools: [] };
  ttStatus(); renderSchoolList();
  const ttStD = ttEl('school-finder-status').textContent;
  ttCheck('D: city suggestion', ttStD.includes('Try a different city or municipality'), ttStD);

  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 100000, totalInArea: 30,
    schools: Array.from({ length: 30 }, (_, i) => ttMk('Uni ' + i, ['Psychology'])) };
  ttStatus(); renderSchoolList();
  ttCheck('E: overflow note', ttResults().innerHTML.includes('Showing the 25 closest of 30'));

  ttSetCode('RC');
  schoolSearch = { lat: 14.6, lon: 121, label: 'Test City', radius: 100000, totalInArea: 1,
    schools: [ttMk('RC Uni', ['Psychology', 'Accountancy'])] };
  renderSchoolDetails(0);
  const ttDet = ttEl('school-finder-details').innerHTML;
  ttCheck('details: joined tie names', ttDet.includes('Realistic, Conventional'), ttDet.slice(0, 300));

  /* ═══ Regression: loadSchoolsForLocation (100 km, matched-only) ═══ */
  const provincePayload = {
    success: true, found: true, lat: 15.8, lon: 121.0,
    label: 'Nueva Ecija, Central Luzon, Philippines',
    admin_level: '4', kind: 'province', province: 'Nueva Ecija',
    cities: [
      { name: 'Cabanatuan', lat: 15.4908, lon: 120.9678 },
      { name: 'Palayan', lat: 15.5517, lon: 121.0858 },
      { name: 'San Jose', lat: 15.7883, lon: 120.9867 },
    ],
  };
  const searchPayload = {
    success: true,
    schools: [ttMk('Nearby Uni', ['Psychology']), ttMk('Other Uni', [])],
    total_in_area: 9,
  };
  globalThis.__setRoutes([
    { match: 'action=geocode&details=1&q=Nueva%20Ecija', payload: provincePayload },
    { match: 'action=search', payload: searchPayload },
  ]);

  ttSetCode('RI');
  ttStatus(); ttResults().innerHTML = '';
  globalThis.__fetchUrls.length = 0;
  await loadSchoolsForLocation(14.6, 121.0, 'Quezon City');
  ttCheck('load: totalInArea stored', schoolSearch && schoolSearch.totalInArea === 9, schoolSearch && schoolSearch.totalInArea);
  ttCheck('load: radius is 100 km', schoolSearch && schoolSearch.radius === 100000, schoolSearch && schoolSearch.radius);
  const ttRh = ttResults().innerHTML;
  ttCheck('load: only matched rendered', ttRh.includes('Nearby Uni') && !ttRh.includes('Other Uni'), ttRh.slice(0, 300));
  ttCheck('load: success message', ttEl('school-finder-status').textContent.includes('Found 1 college or university'));
  ttCheck('load: fetch radius=100000', globalThis.__fetchUrls.some(u => u.includes('radius=100000')));
  ttCheck('load: no 25 km radius', !globalThis.__fetchUrls.some(u => u.includes('radius=25000')));
  ttCheck('load: courses passed', globalThis.__fetchUrls.some(u => u.includes('courses=')));

  /* ═══ Province → city dropdown flow ═══ */
  globalThis.__fetchUrls.length = 0;

  // T1: typing a province shows the dropdown and does NOT search yet.
  ttSetCode('RI');
  ttEl('school-city-input').value = 'Nueva Ecija';
  await searchSchoolsByCity();
  ttCheck('T1: picker shown', ttPickerOpen());
  ttCheck('T1: label names the province',
    ttEl('school-city-picker-label').textContent.includes('Nueva Ecija'),
    ttEl('school-city-picker-label').textContent);
  const ttSel = ttEl('school-city-select').innerHTML;
  ttCheck('T1: all 3 cities listed', ['Cabanatuan', 'Palayan', 'San Jose'].every(n => ttSel.includes(n)), ttSel);
  ttCheck('T1: placeholder present', ttSel.includes('— Select a city or municipality —'));
  ttCheck('T1: status explains the step',
    ttEl('school-finder-status').textContent.includes('3 cities and municipalities were found in Nueva Ecija'),
    ttEl('school-finder-status').textContent);
  ttCheck('T1: no school search yet', ttSearchUrls().length === 0, ttSearchUrls());
  ttCheck('T1: geocode details=1 used',
    globalThis.__fetchUrls.some(u => u.includes('action=geocode&details=1') && u.includes('Nueva%20Ecija')),
    globalThis.__fetchUrls);
  ttCheck('T1: select starts unselected', ttEl('school-city-select').value === '');

  // T2: pressing Search with the dropdown open but nothing chosen → prompt.
  await searchSchoolsByCity();
  ttCheck('T2: prompt to select a city',
    ttEl('school-finder-status').textContent.includes('Select a city or municipality from the list first.'),
    ttEl('school-finder-status').textContent);
  ttCheck('T2: picker still open', ttPickerOpen());
  ttCheck('T2: still no school search', ttSearchUrls().length === 0, ttSearchUrls());

  // T3: picking a city searches it right away (100 km, labelled with province).
  ttEl('school-city-select').value = '0';
  await onSchoolCityPicked();
  ttCheck('T3: search called with 100 km',
    ttSearchUrls().some(u => u.includes('radius=100000')), ttSearchUrls());
  ttCheck('T3: label is "Cabanatuan, Nueva Ecija"',
    schoolSearch && schoolSearch.label === 'Cabanatuan, Nueva Ecija', schoolSearch && schoolSearch.label);
  ttCheck('T3: totalInArea stored', schoolSearch && schoolSearch.totalInArea === 9, schoolSearch && schoolSearch.totalInArea);
  ttCheck('T3: picker hidden after pick', !ttPickerOpen());
  ttCheck('T3: matched school rendered', ttResults().innerHTML.includes('Nearby Uni'));

  // T4: Search pressed while the dropdown is open with a choice → that city.
  await searchSchoolsByCity();                 // re-open the dropdown
  ttCheck('T4a: picker reopened', ttPickerOpen());
  ttEl('school-city-select').value = '1';
  await searchSchoolsByCity();
  ttCheck('T4b: searched the chosen city (Palayan)',
    schoolSearch && schoolSearch.label === 'Palayan, Nueva Ecija', schoolSearch && schoolSearch.label);
  ttCheck('T4b: picker hidden', !ttPickerOpen());

  // T5: editing the text re-resolves instead of using the stale city.
  ttEl('school-city-input').value = 'Nueva Ecija';
  await searchSchoolsByCity();                 // dropdown open again
  ttCheck('T5a: picker open before edit', ttPickerOpen());
  globalThis.__setRoutes([
    { match: 'action=geocode&details=1&q=Nueva%20Ecija', payload: provincePayload },
    { match: 'action=geocode&details=1&q=Cabanatuan', payload: {
        success: true, found: true, lat: 15.4908, lon: 120.9678,
        label: 'Cabanatuan, Nueva Ecija, Central Luzon, Philippines',
        admin_level: '6', kind: 'place',
    } },
    { match: 'action=search', payload: searchPayload },
  ]);
  ttEl('school-city-input').value = 'Cabanatuan';
  await searchSchoolsByCity();
  ttCheck('T5: edited query → direct search',
    schoolSearch && schoolSearch.label.startsWith('Cabanatuan') && schoolSearch.label !== 'Cabanatuan, Nueva Ecija',
    schoolSearch && schoolSearch.label);
  ttCheck('T5: picker hidden after direct search', !ttPickerOpen());

  // T6: the location button (current location) also closes an open dropdown.
  ttEl('school-city-input').value = 'Nueva Ecija';
  globalThis.__setRoutes([
    { match: 'action=geocode&details=1&q=Nueva%20Ecija', payload: provincePayload },
    { match: 'action=search', payload: searchPayload },
  ]);
  await searchSchoolsByCity();
  ttCheck('T6a: picker open before location button', ttPickerOpen());
  await loadSchoolsForLocation(14.6, 121.0, 'your current location');
  ttCheck('T6: location button hides the picker', !ttPickerOpen());
  ttCheck('T6: location search ran', schoolSearch && schoolSearch.label === 'your current location', schoolSearch && schoolSearch.label);

  // T7: reset clears the dropdown and the input.
  ttEl('school-city-input').value = 'Nueva Ecija';
  await searchSchoolsByCity();
  ttCheck('T7a: picker open before reset', ttPickerOpen());
  resetSchoolFinder();
  ttCheck('T7: reset hides the picker', !ttPickerOpen());
  ttCheck('T7: reset clears the input', ttEl('school-city-input').value === '');
  ttCheck('T7: reset clears schoolSearch', schoolSearch === null);

  // No geolocation was used anywhere in these flows.
  ttCheck('no geolocation used', globalThis.__geoCalls() === 0, globalThis.__geoCalls());
})();
`;

/* ── Run ───────────────────────────────────────────────── */
(async () => {
  try {
    vm.runInContext(source + '\n' + testCode, sandbox, { filename: 'script.js+tests' });
  } catch (err) {
    console.error('LOAD ERROR:', err && err.stack || err);
    process.exit(1);
  }

  try {
    await sandbox.__asyncTests;
  } catch (err) {
    nodeCheck('async tests ran', false, String(err && err.stack || err));
  }

  for (const r of sandbox.__results) {
    if (r.ok) { pass++; console.log('PASS  ' + r.label); }
    else { fail++; console.log('FAIL  ' + r.label + (r.extra !== undefined ? '\n        got: ' + JSON.stringify(r.extra) : '')); }
  }

  // ── Source-level assertions ──
  const idx = source.indexOf('const findSchoolsBtn');
  const end = source.indexOf('useLocationBtn', idx);
  const block = source.slice(idx, end);
  nodeCheck('find-schools handler: no useCurrentLocation', !block.includes('useCurrentLocation'), block);
  nodeCheck('find-schools handler: calls openSchoolFinder', block.includes('openSchoolFinder()'));

  const occ = source.split('\n')
    .map((l, i) => ({ l: l.trim(), n: i + 1 }))
    .filter(x => x.l.includes('useCurrentLocation'));
  nodeCheck('useCurrentLocation only defined + wired to its own button',
    occ.length === 2
      && occ.some(x => x.l.startsWith('function useCurrentLocation'))
      && occ.some(x => x.l.includes("addEventListener('click', useCurrentLocation)")),
    occ);

  // index.html: chat location field gone, city picker present
  const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
  nodeCheck('index.html: no ai-location-input', !html.includes('ai-location-input'));
  nodeCheck('index.html: no ai-locate-btn', !html.includes('ai-locate-btn'));
  nodeCheck('index.html: has school-city-picker', html.includes('id="school-city-picker"'));
  nodeCheck('index.html: has school-city-select', html.includes('id="school-city-select"'));
  nodeCheck('index.html: keeps manual city input', html.includes('id="school-city-input"'));
  nodeCheck('index.html: keeps current-location button', html.includes('id="btn-use-location"'));

  // ai-assistant.js: chat location code fully removed
  const ai = fs.readFileSync(path.join(ROOT, 'ai-assistant.js'), 'utf8');
  nodeCheck('ai-assistant: no location input refs', !ai.includes('ai-location-input') && !ai.includes('ai-locate-btn'));
  nodeCheck('ai-assistant: no detectLocation', !ai.includes('detectLocation'));
  nodeCheck('ai-assistant: no aiState.location', !ai.includes('aiState.location'));

  // chat.php prompts no longer mention the removed field
  const chat = fs.readFileSync(path.join(ROOT, 'api/chat.php'), 'utf8');
  nodeCheck('chat.php: no "location field" wording', !chat.includes('location field'));

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail > 0 ? 1 : 0);
})();

'use strict';
(() => {
  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];
  const it = document.body.dataset.ui === 'it';
  const text = (italian, english) => it ? italian : english;
  const editor = $('[data-editor]');
  let dirty = false;
  const markDirty = () => { dirty = true; const status = $('[data-save-status]'); if (status) status.textContent = text('Modifiche non salvate.', 'Unsaved changes.'); };
  if (editor) {
    editor.addEventListener('input', markDirty);
    editor.addEventListener('change', markDirty);
    editor.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key === 's') { event.preventDefault(); editor.requestSubmit(); } });
  }
  const tabs = $$('[data-tab]');
  function showTab(language) {
    tabs.forEach(tab => { tab.setAttribute('aria-selected', String(tab.dataset.tab === language)); tab.tabIndex = tab.dataset.tab === language ? 0 : -1; });
    $$('[data-panel]').forEach(panel => { panel.hidden = panel.dataset.panel !== language; });
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => showTab(tab.dataset.tab));
    tab.addEventListener('keydown', event => {
      if (['ArrowLeft', 'ArrowRight'].includes(event.key)) {
        event.preventDefault(); const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; showTab(next.dataset.tab); next.focus();
      }
    });
  });
  if (tabs.length) showTab(tabs.find(tab => tab.dataset.tab === document.body.dataset.ui)?.dataset.tab || tabs[0].dataset.tab);
  function insert(language, before, after = '') {
    const area = $('#body-' + language); if (!area) return;
    const start = area.selectionStart, end = area.selectionEnd;
    const selected = area.value.slice(start, end);
    area.setRangeText(before + selected + after, start, end, 'end'); area.focus(); markDirty();
  }
  $$('[data-format]').forEach(button => button.addEventListener('click', () => {
    const format = button.dataset.format;
    const pair = { bold: ['**', '**'], italic: ['*', '*'], heading: ['\n## ', ''], link: ['[', '](https://example.com)'] }[format];
    insert(button.dataset.language, pair[0], pair[1]);
  }));
  async function request(form) {
    form.set('csrf', document.body.dataset.csrf);
    const response = await fetch(document.body.dataset.endpoint, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error(text('Risposta non valida. Controlla dimensione foto e sessione.', 'Invalid response. Check photo size and your session.')); }
    if (!response.ok || payload.error) throw new Error(payload.error || text('Operazione non riuscita.', 'Operation failed.'));
    return payload;
  }
  $$('[data-preview]').forEach(button => button.addEventListener('click', async () => {
    const language = button.dataset.preview, panel = $('[data-preview-panel="' + language + '"]');
    if (!panel.hidden) { panel.hidden = true; return; }
    button.disabled = true;
    try {
      const form = new FormData(); form.set('action', 'preview'); form.set('body', $('#body-' + language).value);
      const result = await request(form); panel.innerHTML = result.html; panel.hidden = false; panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (error) { panel.textContent = error.message; panel.hidden = false; }
    finally { button.disabled = false; }
  }));
  $('[data-search]')?.addEventListener('input', event => { const term = event.target.value.toLocaleLowerCase(); $$('[data-search-row]').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase().includes(term); }); });
  const dialog = $('#media-dialog');
  let mediaTarget = 'library', coverMode = false;
  $$('[data-open-media]').forEach(button => button.addEventListener('click', () => {
    mediaTarget = button.dataset.openMedia; coverMode = button.dataset.coverMode === '1'; $('[data-upload-status]').textContent = ''; dialog.showModal();
  }));
  $('[data-close-dialog]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => { if (event.target === dialog) { const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close(); } });
  function choose(reference) {
    if (mediaTarget === 'library') { dialog.close(); return; }
    if (coverMode) { $('#cover-' + mediaTarget).value = reference; markDirty(); }
    else { const alt = $('#photo-alt').value.replace(/[\[\]\r\n]/g, ' ').trim(); insert(mediaTarget, '\n![' + alt + '](' + reference + ')\n'); }
    dialog.close();
  }
  $('[data-picker]')?.addEventListener('click', event => { const button = event.target.closest('[data-photo]'); if (button) choose(button.dataset.photo); });
  $$('[data-clear-cover]').forEach(button => button.addEventListener('click', () => { $('#cover-' + button.dataset.clearCover).value = ''; markDirty(); }));
  $('[data-upload]')?.addEventListener('submit', async event => {
    event.preventDefault(); const form = new FormData(event.target); form.set('action', 'upload');
    const status = $('[data-upload-status]'), submit = $('button[type=submit]', event.target);
    status.textContent = text('Caricamento in corso…', 'Uploading…'); submit.disabled = true;
    try {
      const result = await request(form);
      const button = document.createElement('button'); button.type = 'button'; button.className = 'photo-choice'; button.dataset.photo = result.reference; button.setAttribute('aria-label', text('Usa questa foto', 'Use this photo'));
      const image = document.createElement('img'); image.src = result.url; image.alt = ''; button.append(image); $('[data-picker]').prepend(button);
      if ($('[data-library]')) { const figure = document.createElement('figure'); figure.append(image.cloneNode()); $('[data-library]').prepend(figure); }
      status.textContent = text('Foto caricata. Selezionala qui sotto per usarla.', 'Photo uploaded. Select it below to use it.'); event.target.reset();
    } catch (error) { status.textContent = error.message; }
    finally { submit.disabled = false; }
  });
})();
(() => {
  const form = document.querySelector('[data-settings-form]');
  if (!form) return;
  const it = document.body.dataset.ui === 'it';
  let changed = false;
  const mark = () => { changed = true; form.querySelector('[data-settings-status]').textContent = it ? 'Modifiche non salvate.' : 'Unsaved changes.'; };
  form.addEventListener('input', mark); form.addEventListener('change', mark);
  form.addEventListener('submit', () => { changed = false; });
  window.addEventListener('beforeunload', event => { if (changed) { event.preventDefault(); event.returnValue = ''; } });
  form.querySelector('[data-reset-css]').addEventListener('click', () => { document.getElementById('custom-css').value = ''; mark(); });
  document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key === 's') { event.preventDefault(); form.requestSubmit(); } });
})();

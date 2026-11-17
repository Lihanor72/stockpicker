/* StockBell: every UK regulated announcement (FCA NSM), filterable. */
(() => {
  const app = document.querySelector('[data-sba]');
  if (!app || !window.sbaEndpoint) return;
  const $ = s => app.querySelector(s);
  const list = $('.sba-list'), status = $('.sba-status'), tagsBox = $('.sba-tags'), dateSel = $('.sba-date'),
        q = $('.sba-q'), listed = $('.sba-listed'), routine = $('.sba-routine');
  const ORDER = ['Results', 'Trading update', 'Warning', 'Takeover', 'Director dealing', 'Fundraise', 'Contract', 'Trial / regulatory',
    'Drilling / resources', 'Acquisition / disposal', 'Board change', 'Suspension', 'Holdings', 'Dividend', 'Company news',
    'Buyback', 'Meeting', 'Takeover disclosure', 'Fund NAV'];
  const TONE = {Results: 'b', Warning: 'r', Takeover: 'a', 'Director dealing': 'g', Fundraise: 'r', Contract: 'g', 'Trial / regulatory': 'b',
    'Drilling / resources': 'b', Suspension: 'r'};
  let data = null, tag = '', date = '', timer = null;
  const el = (t, c, txt) => { const n = document.createElement(t); if (c) n.className = c; if (txt !== undefined) n.textContent = txt; return n; };
  const hhmm = iso => new Intl.DateTimeFormat('en-GB', {hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London'}).format(new Date(iso));
  const safe = u => { try { const x = new URL(u); return x.protocol === 'https:' ? x.href : ''; } catch { return ''; } };

  function visible() {
    const term = q.value.trim().toLowerCase();
    return data.items.filter(i =>
      (!listed.checked || !i.mapped || i.ticker) &&
      (!term || (i.company + ' ' + i.ticker + ' ' + i.headline + ' ' + i.type).toLowerCase().includes(term)));
  }
  function render() {
    if (!data) return;
    const base = visible();
    const counts = {};
    base.forEach(i => counts[i.tag] = (counts[i.tag] || 0) + 1);
    tagsBox.replaceChildren();
    const chip = (label, value, n) => {
      const b = el('button', 'sba-chip' + (tag === value ? ' on' : ''), label + (n !== undefined ? ' ' + n : ''));
      b.type = 'button'; b.setAttribute('aria-pressed', String(tag === value));
      b.addEventListener('click', () => { tag = value; render(); });
      tagsBox.append(b);
    };
    chip('All', '', base.length);
    Object.keys(counts).sort((a, b) => (ORDER.indexOf(a) + 99) % 99 - (ORDER.indexOf(b) + 99) % 99 || counts[b] - counts[a]).forEach(t => chip(t, t, counts[t]));
    const rows = base.filter(i => !tag || i.tag === tag);
    list.replaceChildren();
    const companies = new Set(rows.map(i => i.company));
    for (const i of rows) {
      const li = el('li', 'sba-row');
      li.append(el('time', 'sba-time', hhmm(i.time)));
      const who = el('div', 'sba-who');
      if (i.ticker) {
        const tk = el('a', 'sba-tk', i.ticker);
        tk.href = 'https://www.tradingview.com/symbols/LSE-' + encodeURIComponent(i.ticker.replace(/\/.*$/, '').replace(/\.$/, '')) + '/';
        tk.target = '_blank'; tk.rel = 'noopener nofollow'; tk.title = 'Price chart on TradingView';
        who.append(tk);
      }
      who.append(el('span', 'sba-co', i.company));
      const body = el('div', 'sba-body');
      const url = safe(i.url);
      const h = el(url ? 'a' : 'span', 'sba-h', i.headline || i.type);
      if (url) { h.href = url; h.target = '_blank'; h.rel = 'noopener'; }
      body.append(h, el('small', 'sba-type', i.type + (i.source ? ' · ' + i.source : '')));
      li.append(who, body, el('span', 'sba-tag sba-' + (TONE[i.tag] || 'n'), i.tag));
      list.append(li);
    }
    const h = data.health || {};
    status.textContent = rows.length + ' announcements from ' + companies.size + ' companies' +
      (data.date === todayUK() ? ' so far today' : ' on ' + data.date) + '. ' +
      (h.ok === false ? 'Latest check failed; showing saved announcements. ' : '') +
      (h.last_ok ? 'Last checked ' + hhmm(h.last_ok) + ' UK.' : '');
    if (!rows.length) list.append(el('li', 'sba-empty', data.items.length ? 'Nothing matches these filters.' : 'No announcements collected for this date yet.'));
  }
  const todayUK = () => new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/London'}).format(new Date());
  async function load() {
    const u = new URL(window.sbaEndpoint);
    if (date) u.searchParams.set('date', date);
    if (routine.checked) u.searchParams.set('routine', '1');
    try {
      const r = await fetch(u, {cache: 'no-store', signal: AbortSignal.timeout(15000)});
      if (!r.ok) throw Error(r.status);
      data = await r.json();
      if (!Array.isArray(data.items)) throw Error('bad data');
      const keep = date || data.date;
      dateSel.replaceChildren();
      const dates = Array.from(new Set([data.date, ...(data.available_dates || [])])).sort().reverse();
      for (const d of dates) { const o = el('option', '', d === todayUK() ? 'Today (' + d + ')' : d); o.value = d; o.selected = d === keep; dateSel.append(o); }
      render();
    } catch (e) {
      status.textContent = data ? 'Could not refresh; showing the last loaded list.' : 'Announcements could not be loaded. Please try again shortly.';
    }
    clearTimeout(timer);
    if (!date || date === todayUK()) timer = setTimeout(() => { if (!document.hidden) load(); else timer = null; }, 120000);
  }
  dateSel.addEventListener('change', () => { date = dateSel.value; tag = ''; load(); });
  routine.addEventListener('change', load);
  listed.addEventListener('change', render);
  q.addEventListener('input', render);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !timer) load(); });
  load();
})();

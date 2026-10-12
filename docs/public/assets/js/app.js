// Documentation DSCS — comportements côté client, sans dépendance ni réseau externe.
(() => {
  'use strict';

  const store = {
    get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* stockage indisponible */ } },
    remove(key) { try { localStorage.removeItem(key); } catch (e) { /* stockage indisponible */ } },
  };

  const root = document.documentElement;

  // textes de l'interface dans la langue de la page (lang/<code>.php, clés js.*)
  let T = {};
  try { T = JSON.parse(document.getElementById('i18n')?.textContent || '{}'); } catch (e) { /* textes par défaut */ }
  const t = (key, fallback) => T[key] || fallback;

  // ------------------------------------------------------------ thème : sombre par défaut / clair / auto (système)
  const THEME_KEY = 'dscs-docs-theme';
  const systemDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;
  const themeButtons = document.querySelectorAll('[data-theme-choice]');

  function applyTheme(choice, save = true) {
    if (choice === 'light' || choice === 'dark') {
      root.dataset.theme = choice;
    } else {
      delete root.dataset.theme;
      choice = 'auto';
    }
    if (save) store.set(THEME_KEY, choice);
    themeButtons.forEach((b) => {
      const on = b.dataset.themeChoice === choice;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', String(on));
    });
  }

  applyTheme(store.get(THEME_KEY) || 'dark', false);
  themeButtons.forEach((b) => b.addEventListener('click', () => applyTheme(b.dataset.themeChoice)));
  document.querySelector('[data-action="toggle-theme"]')?.addEventListener('click', () => {
    const current = root.dataset.theme || (systemDark() ? 'dark' : 'light');
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });

  // ------------------------------------------------------------ sidebar (mobile)
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.querySelector('.sidebar-backdrop');
  const menuButton = document.querySelector('.topbar__menu');
  document.querySelectorAll('[data-action="toggle-sidebar"]').forEach((el) => {
    el.addEventListener('click', () => {
      const open = !sidebar.classList.contains('is-open');
      sidebar.classList.toggle('is-open', open);
      backdrop.hidden = !open;
      menuButton?.setAttribute('aria-expanded', String(open));
    });
  });

  // ------------------------------------------------------------ copier les blocs de code
  document.querySelectorAll('.code').forEach((figure) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code__copy';
    button.textContent = t('copy', 'Copier');
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(figure.querySelector('code').innerText);
        button.textContent = t('copied', 'Copié ✓');
        button.classList.add('is-done');
      } catch (e) {
        button.textContent = t('copy_failed', 'Impossible');
      }
      setTimeout(() => { button.textContent = t('copy', 'Copier'); button.classList.remove('is-done'); }, 1500);
    });
    figure.appendChild(button);
  });

  // ------------------------------------------------------------ table des matières : section visible
  const tocLinks = new Map(
    [...document.querySelectorAll('.toc__item a')].map((a) => [decodeURIComponent(a.hash.slice(1)), a]),
  );
  if (tocLinks.size && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.filter((e) => e.isIntersecting).forEach((e) => {
        tocLinks.forEach((a) => a.classList.remove('is-active'));
        tocLinks.get(e.target.id)?.classList.add('is-active');
      });
    }, { rootMargin: '0px 0px -70% 0px' });
    tocLinks.forEach((_, id) => { const h = document.getElementById(id); if (h) observer.observe(h); });
  }

  // ------------------------------------------------------------ recherche
  const box = document.querySelector('[data-search]');
  if (!box) return;
  const input = box.querySelector('input');
  const list = box.querySelector('.search__results');
  let index = null;
  let selected = -1;

  const normalize = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const escape = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  async function load() {
    if (index) return index;
    try {
      const res = await fetch(`/${root.lang || 'fr'}/search.json`);
      index = (await res.json()).map((p) => ({
        ...p,
        haystack: normalize(`${p.title} ${p.headings.map((h) => h.text).join(" ")} ${p.text}`),
      }));
    } catch (e) {
      index = [];
    }
    return index;
  }

  function snippet(text, terms) {
    const plain = normalize(text);
    const at = Math.max(0, plain.indexOf(terms[0]) - 40);
    let out = escape(text.slice(at, at + 140));
    terms.forEach((t) => {
      out = out.replace(new RegExp(`(${t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'), '<mark>$1</mark>');
    });
    return (at > 0 ? '… ' : '') + out + ' …';
  }

  function render(results, terms) {
    selected = -1;
    list.hidden = false;
    if (!results.length) {
      list.innerHTML = `<li class="search__empty">${escape(t('no_results', 'Aucun résultat'))}</li>`;
      return;
    }
    list.innerHTML = results.slice(0, 8).map((p) => {
      const heading = p.headings.find((h) => terms.every((t) => normalize(h.text).includes(t)));
      const href = heading ? `${p.url}#${heading.id}` : p.url;
      return `<li role="option"><a href="${href}">${escape(p.title)}`
        + `${heading ? ` › ${escape(heading.text)}` : ''}<small>${escape(p.section)} — ${snippet(p.text, terms)}</small></a></li>`;
    }).join('');
  }

  input.addEventListener('input', async () => {
    const terms = normalize(input.value).split(/\s+/).filter(Boolean);
    if (!terms.length) { list.hidden = true; return; }
    const pages = await load();
    render(pages.filter((p) => terms.every((t) => p.haystack.includes(t))), terms);
  });

  input.addEventListener('keydown', (e) => {
    const items = [...list.querySelectorAll('[role="option"]')];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      selected = (selected + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach((li, i) => li.setAttribute('aria-selected', String(i === selected)));
    } else if (e.key === 'Enter') {
      const target = items[Math.max(selected, 0)]?.querySelector('a');
      if (target) window.location.href = target.href;
    } else if (e.key === 'Escape') {
      list.hidden = true;
      input.blur();
    }
  });

  document.addEventListener('click', (e) => { if (!box.contains(e.target)) list.hidden = true; });
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && document.activeElement !== input && !/input|textarea/i.test(document.activeElement?.tagName)) {
      e.preventDefault();
      input.focus();
    }
  });
})();

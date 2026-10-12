<?php
/**
 * @var Dscs\Docs\View       $v
 * @var array<string, mixed> $site
 */
?>
<header class="topbar">
  <button class="topbar__menu icon-btn" type="button" data-action="toggle-sidebar" aria-label="<?= $v->e($v->t('menu')) ?>" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>

  <a class="brand" href="<?= $v->e($v->url('index')) ?>">
    <?= $v->partial('logo') ?>
    <span class="brand__name"><?= $v->e($site['title']) ?></span>
    <span class="brand__tagline"><?= $v->e($site['tagline']) ?></span>
  </a>
  <span class="badge badge--accent">v<?= $v->e($site['version']) ?></span>

  <div class="search" data-search>
    <label class="visually-hidden" for="search-input"><?= $v->e($v->t('search.label')) ?></label>
    <input id="search-input" class="search__input" type="search" placeholder="<?= $v->e($v->t('search.placeholder')) ?>" autocomplete="off">
    <ul class="search__results" role="listbox" hidden></ul>
  </div>

  <button class="icon-btn" type="button" data-action="toggle-theme" aria-label="<?= $v->e($v->t('theme.toggle')) ?>">
    <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
  </button>
</header>

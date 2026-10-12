<?php
/**
 * @var Dscs\Docs\View        $v
 * @var Dscs\Docs\Navigation  $nav
 * @var string                $current
 * @var array<string, string> $languages
 */
// la page 404 n'a pas d'équivalent : le changement de langue mène à l'accueil
$target = $current === '404' ? 'index' : $current;
?>
<nav class="sidebar" aria-label="<?= $v->e($v->t('nav.label')) ?>">
  <div class="sidebar__nav">
  <?php foreach ($nav->sections as $section): ?>
    <div class="sidebar__section">
      <p class="sidebar__title"><?= $v->e($section['title']) ?></p>
      <ul class="sidebar__list">
        <?php foreach ($section['pages'] as $slug => $title): ?>
          <li>
            <a class="sidebar__link<?= $slug === $current ? ' is-active' : '' ?>"
               href="<?= $v->e($v->url($slug)) ?>"
               <?= $slug === $current ? 'aria-current="page"' : '' ?>><?= $v->e($title) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
  </div>

  <section class="settings" aria-labelledby="settings-title">
    <p class="sidebar__title" id="settings-title">
      <svg class="settings__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
      <?= $v->e($v->t('settings.title')) ?>
    </p>

    <div class="settings__group">
      <span class="settings__label" id="settings-language"><?= $v->e($v->t('settings.language')) ?></span>
      <div class="segmented" role="group" aria-labelledby="settings-language">
        <?php foreach ($languages as $code => $name): ?>
          <a class="segmented__item<?= $code === $v->lang ? ' is-active' : '' ?>"
             href="<?= $v->e($v->url($target, $code)) ?>"
             hreflang="<?= $v->e($code) ?>" lang="<?= $v->e($code) ?>"
             <?= $code === $v->lang ? 'aria-current="true"' : '' ?>><?= $v->e($name) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="settings__group">
      <span class="settings__label" id="settings-theme"><?= $v->e($v->t('settings.theme')) ?></span>
      <div class="segmented" role="group" aria-labelledby="settings-theme">
        <?php foreach (['auto', 'light', 'dark'] as $choice): ?>
          <button class="segmented__item" type="button" data-theme-choice="<?= $choice ?>" aria-pressed="false"><?= $v->e($v->t('theme.' . $choice)) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</nav>
<div class="sidebar-backdrop" data-action="toggle-sidebar" hidden></div>

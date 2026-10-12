<?php
/**
 * @var Dscs\Docs\View         $v
 * @var array<string, mixed>   $site
 * @var Dscs\Docs\Page         $page
 * @var Dscs\Docs\Navigation   $nav
 * @var array<string, string>  $languages
 * @var string|null            $scssError
 */
$section = $nav->section($page->slug);
?>
<!doctype html>
<html lang="<?= $v->e($v->lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $v->e($page->title) ?> · <?= $v->e($site['title']) ?></title>
  <meta name="description" content="<?= $v->e($page->description) ?>">
  <?php if ($page->slug !== '404'): ?>
    <?php foreach ($languages as $code => $name): ?>
      <link rel="alternate" hreflang="<?= $v->e($code) ?>" href="<?= $v->e($v->url($page->slug, $code)) ?>">
    <?php endforeach; ?>
  <?php endif; ?>
  <link rel="icon" href="<?= $v->asset('img/logo.svg') ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= $v->asset('css/app.css') ?>">
  <script>
    // thème appliqué avant le rendu (évite le flash) : sombre par défaut, « auto » suit le système
    (function () {
      let t = null;
      try { t = localStorage.getItem('dscs-docs-theme'); } catch (e) {}
      if (t !== 'auto') document.documentElement.dataset.theme = t === 'light' ? 'light' : 'dark';
    })();
  </script>
  <script type="application/json" id="i18n"><?= json_encode($v->i18n->forJs(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body>
  <a class="skip-link" href="#main"><?= $v->e($v->t('skip_link')) ?></a>

  <?= $v->partial('header') ?>

  <div class="shell">
    <?= $v->partial('sidebar', ['nav' => $nav, 'current' => $page->slug, 'languages' => $languages]) ?>

    <main id="main" class="main" tabindex="-1">
      <?php if ($scssError): ?>
        <div class="callout callout--danger dev-error">
          <p class="callout__title"><?= $v->e($v->t('scss.error')) ?></p>
          <pre><?= $v->e($scssError) ?></pre>
        </div>
      <?php endif; ?>

      <article class="prose"<?= $page->lang !== $v->lang ? ' lang="' . $v->e($page->lang) . '"' : '' ?>>
        <?php if ($section): ?>
          <p class="eyebrow"><?= $v->e($section) ?></p>
        <?php endif; ?>
        <h1><?= $v->e($page->title) ?></h1>
        <?php if ($page->description): ?>
          <p class="lead"><?= $v->e($page->description) ?></p>
        <?php endif; ?>
        <?php if ($page->lang !== $v->lang): ?>
          <aside class="callout callout--note" role="note" lang="<?= $v->e($v->lang) ?>">
            <p class="callout__title"><?= $v->e($v->t('fallback.title')) ?></p>
            <div class="callout__body"><p><?= $v->e($v->t('fallback.body', ['language' => $languages[$page->lang] ?? $page->lang])) ?></p></div>
          </aside>
        <?php endif; ?>
        <?php if ($page->draft): ?>
          <aside class="callout callout--warning" role="note">
            <p class="callout__title"><?= $v->e($v->t('draft.title')) ?></p>
            <div class="callout__body"><p><?= $v->e($v->t('draft.body')) ?></p></div>
          </aside>
        <?php endif; ?>

        <?= $page->html ?>
      </article>

      <?= $v->partial('pager', ['nav' => $nav, 'current' => $page->slug]) ?>
      <?= $v->partial('footer') ?>
    </main>

    <?= $v->partial('toc', ['toc' => $page->toc]) ?>
  </div>

  <script src="<?= $v->asset('js/app.js') ?>" defer></script>
</body>
</html>

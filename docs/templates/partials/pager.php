<?php
/**
 * @var Dscs\Docs\View       $v
 * @var Dscs\Docs\Navigation $nav
 * @var string               $current
 */
$prev = $nav->previous($current);
$next = $nav->next($current);
if (!$prev && !$next) {
    return;
}
?>
<nav class="pager" aria-label="<?= $v->e($v->t('pager.label')) ?>">
  <?php if ($prev): ?>
    <a class="pager__link pager__link--prev" href="<?= $v->e($v->url($prev['slug'])) ?>">
      <span class="pager__label"><?= $v->e($v->t('pager.prev')) ?></span>
      <span class="pager__title"><?= $v->e($prev['title']) ?></span>
    </a>
  <?php endif; ?>
  <?php if ($next): ?>
    <a class="pager__link pager__link--next" href="<?= $v->e($v->url($next['slug'])) ?>">
      <span class="pager__label"><?= $v->e($v->t('pager.next')) ?></span>
      <span class="pager__title"><?= $v->e($next['title']) ?></span>
    </a>
  <?php endif; ?>
</nav>

<?php
/**
 * @var Dscs\Docs\View                                     $v
 * @var list<array{level: int, id: string, text: string}> $toc
 */
?>
<aside class="toc" aria-label="<?= $v->e($v->t('toc.title')) ?>">
  <?php if (count($toc) > 1): ?>
    <p class="toc__title"><?= $v->e($v->t('toc.title')) ?></p>
    <ul class="toc__list">
      <?php foreach ($toc as $item): ?>
        <li class="toc__item toc__item--h<?= $item['level'] ?>">
          <a href="#<?= $v->e($item['id']) ?>"><?= $v->e($item['text']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</aside>

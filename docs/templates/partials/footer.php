<?php
/**
 * @var Dscs\Docs\View       $v
 * @var array<string, mixed> $site
 */
?>
<footer class="footer">
  <p><?= $v->t('footer', ['title' => $v->e($site['title']), 'version' => $v->e($site['version'])]) ?></p>
</footer>

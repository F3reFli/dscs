<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Sauvegarde et restauration';
$page->description = 'Ce qui protège l\'accès à ton coffre, comment ne jamais le perdre, et comment tout retrouver sur une autre machine.';
?>
<p>Les fichiers vivent sur Discord, chiffrés. Pour les relire, il faut une clé de coffre, rangée dans le
<strong>trousseau</strong>, lui-même ouvert par la <strong>clé de l'appareil</strong>. Perdre l'accès au trousseau sur tous
tes appareils, c'est perdre le coffre. Trois protections, de la plus simple à la plus solide :</p>

<?= $c->table(['Protection', 'Couvre', 'Comment'], [
    ['<strong>Kit de secours</strong>', 'Perte de tous tes appareils', '<code>dscs key recovery</code>, imprimé ou hors ligne'],
    ['<strong>Un deuxième appareil</strong> membre', 'Perte ou panne d\'un appareil', '<code>dscs device add</code> (' . $c->link('guide/appareils') . ')'],
    ['<strong>Copie du dossier de configuration</strong>', 'Panne de disque de cet appareil', 'copie de <code>~/.config/dscs/</code>'],
]) ?>

<?= $c->callout('warning', '<p>Fais au moins un kit de secours dès que ton coffre contient quelque chose d\'important. Sans lui, et sans autre appareil membre, une panne de disque rend tout illisible, définitivement.</p>', 'À faire tout de suite') ?>

<h2>Ce qu'il y a sur cet appareil</h2>

<?= $c->table(['Fichier', 'Rôle', 'Si tu le perds', 'Secret ?'], [
    [$c->path('~/.config/dscs/device.json'), 'Clé privée de cet appareil (chiffrée par la phrase de passe si tu en as mis une)', 'Le trousseau local devient illisible : rejoins de nouveau le coffre (autre appareil ou kit de secours)', 'Oui'],
    [$c->path('~/.config/dscs/keyring.sealed'), 'Trousseau du coffre (clés de coffre, membres, webhooks), scellé pour cet appareil', 'Aucune perte : il est aussi sur Discord, scellé pour chaque membre', 'Scellé'],
    [$c->path('~/.config/dscs/config.toml'), 'Réglages, webhooks ajoutés à la main, noms lisibles', 'Recréé à la prochaine jonction ; réglages à refaire', 'Oui (tokens)'],
    [$c->path('~/.local/share/dscs/index.db'), 'Copie locale de l\'index', 'Aucune perte : l\'index partagé est sur Discord, chiffré', 'Non'],
]) ?>

<p>L'index et le trousseau sont publiés sur Discord (chiffrés, scellés) : ce qui est vraiment irremplaçable, c'est l'accès
d'<em>au moins un</em> appareil, ou le kit de secours.</p>

<h2>Le kit de secours</h2>

<?= $c->code('bash', <<<'SH'
dscs key recovery -o [file]
SH) ?>

<p>Le kit est la clé privée d'un appareil « de secours », membre du coffre, plus l'adresse du coffre. Il ne change pas
quand tu fais une rotation de clés : un seul kit suffit, tant que tu ne le révoques pas.</p>

<?= $c->callout('danger', '<p>Le kit donne accès à <strong>tout</strong> le coffre. Imprime-le et range-le dans un endroit sûr, ou sur une clé USB chiffrée. Jamais sur Discord, dans un mail, un nuage ou à côté de ton ordinateur.</p>') ?>

<h2>Restaurer sur une autre machine</h2>

<h3>Avec un autre appareil membre</h3>
<p>C'est le cas le plus simple : <?= $c->link('guide/appareils', null, 'ajouter-un-appareil') ?>
(<code>dscs device init</code> sur la nouvelle machine, <code>dscs device add</code> sur l'ancienne, puis
<code>dscs device join</code>).</p>

<h3>Avec le kit de secours</h3>
<?= $c->steps([
    '<p>Installe dscs (' . $c->link('guide/installation') . '). Ne lance pas <code>dscs init</code> : il créerait un nouveau coffre.</p>',
    '<p>Lance la récupération et colle le kit (saisie masquée) :</p>' . $c->code('bash', 'dscs device recover'),
    '<p>Vérifie la liste des membres affichée (tu dois reconnaître tes appareils), confirme, et choisis une phrase de passe pour cette machine.</p>',
    '<p><code>dscs ls</code> doit lister tes fichiers. Si le kit a pu être vu, crée-en un nouveau et retire l\'ancien appareil de secours.</p>',
]) ?>

<h3>Avec une copie du dossier de configuration</h3>
<p>Remets <code>device.json</code>, <code>keyring.sealed</code> et <code>config.toml</code> dans
<code>~/.config/dscs/</code> (droits <code>600</code>), puis lance <code>dscs sync</code> : l'index revient de Discord.
Si <code>device.json</code> était protégé, sa phrase de passe est demandée.</p>

<h2>Phrase de passe oubliée</h2>

<p>Elle ne peut pas être récupérée. Sur cette machine, supprime <code>device.json</code> et <code>keyring.sealed</code>,
puis rejoins le coffre de nouveau (autre appareil ou kit de secours), et retire l'ancienne identité de la machine depuis un
autre membre (<code>dscs device revoke</code>).</p>

<h2>Si le pire arrive</h2>

<?= $c->table(['Perdu', 'Conséquence', 'Que faire'], [
    ['Tous les appareils et le kit', 'Le trousseau ne s\'ouvre plus : les morceaux sont du bruit pour tout le monde.', 'Rien ne permet de les déchiffrer. Supprime les salons pour faire le ménage.'],
    ['Un appareil', 'Les autres continuent normalement.', 'Retire-le (<code>dscs device revoke</code>) depuis un autre membre.'],
    ['Un webhook (supprimé dans Discord)', 'Ses morceaux ne peuvent plus être relus ni supprimés par webhook.', 'Les fichiers qui en dépendent sont perdus pour dscs. Ne supprime jamais un webhook qui a servi.'],
]) ?>

<h2>Venir de dscs 0.2 ou de dstore</h2>

<ul>
  <li><strong>dscs 0.2</strong> : au premier lancement, l'ancienne clé en clair (<code>~/.config/dscs/key</code>) devient la
    première génération du trousseau, puis le fichier est effacé. Garde quand même ta copie de cette clé tant que tu n'as pas
    fait de kit de secours.</li>
  <li><strong>dstore</strong> (ancien nom) : renomme <code>~/.config/dstore</code> en <code>~/.config/dscs</code> et
    <code>~/.local/share/dstore</code> en <code>~/.local/share/dscs</code> avant tout lancement.</li>
</ul>

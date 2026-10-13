<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Transferts';
$page->description = 'Ce qui se passe exactement pendant un envoi, un téléchargement, une suppression et un nettoyage.';
?>
<h2>Envoi</h2>

<figure class="diagram">
  <svg viewBox="0 0 760 600" role="img" aria-labelledby="seq-title">
    <title id="seq-title">Séquence d'envoi d'un fichier</title>
    <defs>
      <marker id="seq-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
        <path class="diagram__arrowhead" d="M0 0L10 5L0 10z"/>
      </marker>
      <marker id="seq-arrow-accent" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
        <path class="diagram__arrowhead diagram__arrowhead--accent" d="M0 0L10 5L0 10z"/>
      </marker>
    </defs>

    <!-- participants -->
    <rect class="diagram__box" x="20" y="10" width="120" height="40" rx="8"/>
    <text class="diagram__label" x="80" y="35" text-anchor="middle">Interface</text>
    <rect class="diagram__box diagram__box--accent" x="170" y="10" width="120" height="40" rx="8"/>
    <text class="diagram__label" x="230" y="35" text-anchor="middle">Vault</text>
    <rect class="diagram__box" x="320" y="10" width="140" height="40" rx="8"/>
    <text class="diagram__label" x="390" y="35" text-anchor="middle">upload_file</text>
    <rect class="diagram__box" x="490" y="10" width="110" height="40" rx="8"/>
    <text class="diagram__label" x="545" y="35" text-anchor="middle">Index</text>
    <rect class="diagram__box" x="630" y="10" width="110" height="40" rx="8"/>
    <text class="diagram__label" x="685" y="35" text-anchor="middle">Discord</text>

    <!-- lignes de vie -->
    <path class="diagram__lifeline" d="M80 50V585"/>
    <path class="diagram__lifeline" d="M230 50V585"/>
    <path class="diagram__lifeline" d="M390 50V585"/>
    <path class="diagram__lifeline" d="M545 50V585"/>
    <path class="diagram__lifeline" d="M685 50V585"/>

    <!-- plan + confirmation -->
    <path class="diagram__line" d="M80 85H228" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="78" text-anchor="middle">plan_upload()</text>
    <path class="diagram__line diagram__line--return" d="M230 112H82" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="105" text-anchor="middle">UploadPlan</text>
    <text class="diagram__sub" x="90" y="138">confirmation y/n</text>

    <!-- upload -->
    <path class="diagram__line" d="M80 165H228" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="158" text-anchor="middle">upload(item)</text>
    <path class="diagram__line" d="M230 192H388" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="310" y="185" text-anchor="middle">upload_file()</text>
    <path class="diagram__line" d="M390 222H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="215" text-anchor="middle">add_file · complete=0</text>

    <!-- boucle par morceau -->
    <rect class="diagram__loop" x="300" y="240" width="450" height="200" rx="6"/>
    <text class="diagram__sub" x="310" y="258">pour chaque morceau (un worker par webhook)</text>
    <text class="diagram__msg" x="400" y="284">lire 9 Mio · SHA-256 · chiffrer</text>
    <path class="diagram__line diagram__line--accent" d="M390 312H683" marker-end="url(#seq-arrow-accent)"/>
    <text class="diagram__msg" x="537" y="305" text-anchor="middle">POST ?wait=true  xxxx.bin</text>
    <path class="diagram__line diagram__line--return" d="M685 342H392" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="537" y="335" text-anchor="middle">message_id</text>
    <path class="diagram__line" d="M390 375H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="368" text-anchor="middle">add_chunk</text>
    <path class="diagram__line diagram__line--return" d="M390 420H82" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="235" y="413" text-anchor="middle">on_progress(octets)</text>

    <!-- fin -->
    <path class="diagram__line" d="M390 470H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="463" text-anchor="middle">mark_complete(sha256)</text>
    <path class="diagram__line diagram__line--return" d="M390 500H232" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="310" y="493" text-anchor="middle">StoredFile</text>
    <path class="diagram__line diagram__line--return" d="M230 527H82" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="520" text-anchor="middle">StoredFile</text>

    <text class="diagram__sub" x="390" y="565" text-anchor="middle">Échec ou annulation : purge_file supprime les morceaux déjà envoyés, puis l'entrée d'index.</text>
  </svg>
  <figcaption>Les flèches pleines sont des appels, les pointillés des réponses. En accent : ce qui sort de ta machine.</figcaption>
</figure>

<h3>Étapes</h3>

<?= $c->steps([
    '<p><strong>Plan</strong> (<code>plan_upload</code>) : la sélection est développée en liste de fichiers. Chaque dossier garde sa structure sous son propre nom (<code>projet/src</code>) ; les liens symboliques internes sont ignorés ; les doublons retirés. Le plan donne le total d\'octets et de morceaux affiché à la confirmation.</p>',
    '<p><strong>Enregistrement</strong> : une ligne <code>files</code> est créée avec <code>complete = 0</code> et un <code>uid</code> aléatoire. Elle reste invisible tant que l\'envoi n\'est pas terminé.</p>',
    '<p><strong>Producteur</strong> : lit les fichiers l\'un après l\'autre par morceaux de <code>chunk_size_mb</code>, met à jour leur SHA-256, chiffre chaque morceau (dans un thread, pour ne pas bloquer l\'interface) et le dépose dans <em>une seule</em> file d\'attente pour tout l\'envoi, bornée à deux morceaux par webhook : la mémoire utilisée reste faible, même pour un très gros fichier.</p>',
    '<p><strong>Workers</strong> : un par webhook choisi. Chacun prend le morceau suivant dans la file, quel que soit son fichier, l\'envoie, puis l\'inscrit <em>aussitôt</em> dans l\'index et signale la progression. Tous les webhooks travaillent donc en même temps, même avec beaucoup de petits fichiers d\'un seul morceau, et enchaînent sans pause d\'un fichier au suivant (comme <code>dscs bench</code>).</p>',
    '<p><strong>Contrôle final</strong> : si le nombre d\'octets lus diffère de la taille annoncée (fichier modifié pendant l\'envoi), l\'envoi échoue. Sinon le SHA-256 est enregistré et le fichier passe à <code>complete = 1</code>.</p>',
]) ?>

<h3>Échec et annulation</h3>

<p>Si un morceau ne peut pas être envoyé (erreur Discord, fichier illisible ou modifié…), seul son fichier échoue :
ses morceaux suivants sont ignorés, <code>purge_file</code> supprime de Discord ceux déjà envoyés, et les autres fichiers
continuent. Si l'utilisateur annule, les fichiers terminés sont gardés et les morceaux des fichiers en cours supprimés. Un envoi <em>en vol</em> au moment de
l'annulation peut quand même aboutir côté Discord : dscs attend sa réponse (jusqu'à 120 s) pour l'inscrire dans l'index,
afin de pouvoir le supprimer lui aussi.</p>

<h2>Téléchargement</h2>

<?= $c->steps([
    '<p><strong>Contrôles préalables</strong>, avant d\'écrire quoi que ce soit : format de morceaux connu, nombre de morceaux dans l\'index égal au nombre attendu, et tous les webhooks concernés présents dans la config.</p>',
    '<p><strong>Destination</strong> : <code>dossier_de_téléchargement/&lt;dossier distant&gt;/&lt;nom&gt;</code>. Le chemin ne peut pas sortir du dossier de téléchargement (<code>safe_join</code>), et un fichier existant n\'est jamais écrasé (<code>nom (1).ext</code>).</p>',
    '<p><strong>Récupération</strong> : pour chaque morceau, le message est relu pour obtenir un lien CDN frais, puis la pièce jointe est téléchargée. Trois morceaux sont préchargés en avance.</p>',
    '<p><strong>Déchiffrement</strong> dans l\'ordre, avec vérification de l\'AAD ; écriture dans <code>&lt;nom&gt;.part</code>.</p>',
    '<p><strong>Vérification</strong> du SHA-256 global, puis renommage du <code>.part</code>. En cas d\'erreur ou d\'annulation, le <code>.part</code> est supprimé.</p>',
]) ?>

<h2>Suppression</h2>

<ul>
  <li>dscs vérifie d'abord que tous les webhooks concernés sont dans la config ; sinon il refuse, sans rien toucher.</li>
  <li>Le fichier passe à <code>complete = 0</code> (il n'est plus entier dès le premier message supprimé).</li>
  <li>Chaque message est supprimé, et sa ligne <code>chunks</code> retirée aussitôt. Un message déjà absent (404) compte
    comme supprimé.</li>
  <li>Si tout a réussi, le fichier disparaît de l'index. Sinon il y reste, incomplet, et l'opération signale une
    <code>Suppression partielle</code>.</li>
</ul>

<h2>Nettoyage : <code>dscs gc</code></h2>

<p>Le nettoyage rejoue la suppression sur <em>tous</em> les fichiers marqués incomplets : envois interrompus par un crash,
annulations dont le nettoyage a échoué, suppressions partielles. Il exige le verrou exclusif
(<?= $c->link('concepts/index', null, 'verrou-entre-processus') ?>) et rapporte combien de fichiers ont été entièrement
nettoyés et combien restent.</p>

<h2>Progression</h2>

<p>Les fonctions de transfert acceptent un rappel <code>on_progress(n)</code>, appelé avec le nombre d'octets <em>en clair</em>
traités. La CLI l'utilise pour sa barre de progression, l'interface pour sa barre et son estimation du temps restant.</p>

<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Transfers';
$page->description = 'Exactly what happens during an upload, a download, a deletion and a cleanup.';
?>
<h2>Upload</h2>

<figure class="diagram">
  <svg viewBox="0 0 760 600" role="img" aria-labelledby="seq-title">
    <title id="seq-title">Sequence of a file upload</title>
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

    <!-- lifelines -->
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
    <text class="diagram__sub" x="90" y="138">y/n confirmation</text>

    <!-- upload -->
    <path class="diagram__line" d="M80 165H228" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="158" text-anchor="middle">upload(item)</text>
    <path class="diagram__line" d="M230 192H388" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="310" y="185" text-anchor="middle">upload_file()</text>
    <path class="diagram__line" d="M390 222H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="215" text-anchor="middle">add_file · complete=0</text>

    <!-- loop per chunk -->
    <rect class="diagram__loop" x="300" y="240" width="450" height="200" rx="6"/>
    <text class="diagram__sub" x="310" y="258">for each chunk (one worker per webhook)</text>
    <text class="diagram__msg" x="400" y="284">read 9 MiB · SHA-256 · encrypt</text>
    <path class="diagram__line diagram__line--accent" d="M390 312H683" marker-end="url(#seq-arrow-accent)"/>
    <text class="diagram__msg" x="537" y="305" text-anchor="middle">POST ?wait=true  xxxx.bin</text>
    <path class="diagram__line diagram__line--return" d="M685 342H392" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="537" y="335" text-anchor="middle">message_id</text>
    <path class="diagram__line" d="M390 375H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="368" text-anchor="middle">add_chunk</text>
    <path class="diagram__line diagram__line--return" d="M390 420H82" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="235" y="413" text-anchor="middle">on_progress(bytes)</text>

    <!-- end -->
    <path class="diagram__line" d="M390 470H543" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="467" y="463" text-anchor="middle">mark_complete(sha256)</text>
    <path class="diagram__line diagram__line--return" d="M390 500H232" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="310" y="493" text-anchor="middle">StoredFile</text>
    <path class="diagram__line diagram__line--return" d="M230 527H82" marker-end="url(#seq-arrow)"/>
    <text class="diagram__msg" x="155" y="520" text-anchor="middle">StoredFile</text>

    <text class="diagram__sub" x="390" y="565" text-anchor="middle">Failure or cancellation: purge_file deletes the chunks already sent, then the index entry.</text>
  </svg>
  <figcaption>Solid arrows are calls, dashed ones are replies. In accent colour: what leaves your machine.</figcaption>
</figure>

<h3>Steps</h3>

<?= $c->steps([
    '<p><strong>Plan</strong> (<code>plan_upload</code>): the selection is expanded into a list of files. Each folder keeps its structure under its own name (<code>project/src</code>); internal symbolic links are skipped; duplicates removed. The plan gives the total bytes and chunks shown at confirmation.</p>',
    '<p><strong>Registration</strong>: a <code>files</code> row is created with <code>complete = 0</code> and a random <code>uid</code>. It stays invisible until the upload has finished.</p>',
    '<p><strong>Producer</strong>: reads the files one after the other in chunks of <code>chunk_size_mb</code>, updates their SHA-256, encrypts each chunk (in a thread, so as not to block the interface) and puts it in <em>a single</em> queue for the whole upload, bounded to two chunks per webhook: memory use stays low, even for a very large file.</p>',
    '<p><strong>Workers</strong>: one per selected webhook. Each takes the next chunk from the queue, whatever its file, uploads it, then records it in the index <em>immediately</em> and reports progress. So every webhook works at the same time, even with many single-chunk small files, and moves on to the next file without a pause (like <code>dscs bench</code>).</p>',
    '<p><strong>Final check</strong>: if the number of bytes read differs from the announced size (file modified during the upload), the upload fails. Otherwise the SHA-256 is recorded and the file switches to <code>complete = 1</code>.</p>',
]) ?>

<h3>Failure and cancellation</h3>

<p>If a chunk cannot be uploaded (Discord error, unreadable or modified file…), only its file fails: its next chunks are
skipped, <code>purge_file</code> deletes those already uploaded from Discord, and the other files go on. If the user
cancels, finished files are kept and the chunks of files in progress are deleted. An upload <em>in flight</em> at the moment of
cancellation may still succeed on Discord's side: dscs waits for its answer (up to 120 s) to record it in the index, so that
it can delete it too.</p>

<h2>Download</h2>

<?= $c->steps([
    '<p><strong>Preliminary checks</strong>, before writing anything: known chunk format, number of chunks in the index equal to the expected number, and all the webhooks involved present in the config.</p>',
    '<p><strong>Destination</strong>: <code>download_folder/&lt;remote folder&gt;/&lt;name&gt;</code>. The path cannot leave the download folder (<code>safe_join</code>), and an existing file is never overwritten (<code>name (1).ext</code>).</p>',
    '<p><strong>Retrieval</strong>: for each chunk, the message is read again to get a fresh CDN link, then the attachment is downloaded. Three chunks are prefetched ahead.</p>',
    '<p><strong>Decryption</strong> in order, with AAD check; writing into <code>&lt;name&gt;.part</code>.</p>',
    '<p><strong>Verification</strong> of the overall SHA-256, then renaming of the <code>.part</code>. On error or cancellation, the <code>.part</code> is deleted.</p>',
]) ?>

<h2>Deletion</h2>

<ul>
  <li>dscs first checks that all the webhooks involved are in the config; otherwise it refuses, without touching anything.</li>
  <li>The file switches to <code>complete = 0</code> (it is no longer whole as soon as the first message is deleted).</li>
  <li>Each message is deleted, and its <code>chunks</code> row removed straight away. A message that is already gone (404)
    counts as deleted.</li>
  <li>If everything succeeded, the file disappears from the index. Otherwise it stays there, incomplete, and the operation
    reports a partial deletion (<code>Suppression partielle</code>).</li>
</ul>

<h2>Cleanup: <code>dscs gc</code></h2>

<p>The cleanup replays the deletion on <em>all</em> files marked incomplete: uploads interrupted by a crash, cancellations
whose cleanup failed, partial deletions. It requires the exclusive lock
(<?= $c->link('concepts/index', null, 'locking-between-processes') ?>) and reports how many files were fully cleaned and how
many remain.</p>

<h2>Progress</h2>

<p>Transfer functions accept an <code>on_progress(n)</code> callback, called with the number of <em>plaintext</em> bytes
processed. The CLI uses it for its progress bar, the interface for its bar and its time-left estimate.</p>

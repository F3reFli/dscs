<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Discord and webhooks';
$page->description = 'How dscs talks to Discord: requests, size and rate limits, links that expire.';
?>
<?= $c->callout('danger', <<<'HTML'
<p>Using Discord as storage space is not a use its terms of service intend. The account, the server or the messages may
disappear without notice. DSCS is encrypted secondary storage, not a backup.</p>
HTML) ?>

<h2>Why webhooks</h2>

<p>A webhook is a secret URL tied to a channel: it can post, read back and delete <em>its own</em> messages, without a bot,
without an account and without a permanent connection. That is exactly what dscs needs, with minimal rights: a webhook can
neither read the other messages of the channel nor list its history.</p>

<p>Direct consequence: <strong>dscs must remember the ID of every message</strong> (that is the job of the
<?= $c->link('concepts/index', 'index') ?>), and <strong>each chunk depends on the webhook that posted it</strong>.</p>

<h2>Requests used</h2>

<?= $c->table(['Operation', 'Request', 'Details'], [
    ['Check a webhook', '<code>GET {webhook}</code>', 'During <code>dscs init</code>, when the interface starts and with <code>dscs webhooks</code>: webhook name and IDs of its server and channel (Discord does not give their names)'],
    ['Upload a chunk', '<code>POST {webhook}?wait=true</code>', 'Multipart: <code>payload_json</code> + <code>files[0]</code>. <code>wait=true</code> to receive the message ID'],
    ['Read a chunk back', '<code>GET {webhook}/messages/{id}</code>', 'Then download of <code>attachments[0].url</code>'],
    ['Delete a chunk', '<code>DELETE {webhook}/messages/{id}</code>', 'A <code>404</code> (already deleted) is accepted during cleanups'],
]) ?>

<p>Each message sent has empty content, a single attachment with a random name (<code>3f9a1c0be47d2a85.bin</code>, 16
hexadecimal characters) and the <code>SUPPRESS_NOTIFICATIONS</code> flag (<code>4096</code>): the channel does not ping.</p>

<h2>Size limit</h2>

<p>Discord rejects an attachment above the server's limit: <strong>10 MiB</strong> without boosts. dscs therefore splits into
<strong>9 MiB</strong> chunks by default; with the 28 bytes of encryption, each attachment stays below the limit. Setting:
<code>chunk_size_mb</code> (<?= $c->link('guide/configuration', null, 'chunk-size') ?>). A chunk that is too big is rejected with a
<code>413</code> and the upload fails cleanly.</p>

<h2>Links that expire</h2>

<p>Attachment download links (CDN) are signed and expire after about 24 hours. dscs therefore never stores them: on every
download, it <strong>reads the message again</strong> to get a fresh link, then downloads. A file uploaded a year ago is
retrieved just like a file uploaded a minute ago.</p>

<h2>Rate limits</h2>

<p>Discord limits the number of requests per webhook and per channel. dscs handles everything automatically:</p>

<?= $c->table(['Situation', 'Reaction'], [
    ['<code>429 Too Many Requests</code>', 'Waits for the requested time (<code>retry_after</code> in the response, or the <code>Retry-After</code> header), then retries'],
    ['Header <code>X-RateLimit-Remaining: 0</code>', 'Waits <code>X-RateLimit-Reset-After</code> before the next request, to avoid causing a 429'],
    ['Server error <code>5xx</code>', 'Retries after 1 s, 2 s, 4 s… up to 30 s'],
    ['Dropped connection, timeout', 'Same progressive wait'],
    ['Other <code>4xx</code> error', 'Immediate failure with Discord\'s message (<code>Discord a répondu 403 : …</code>, “Discord answered 403”)'],
]) ?>

<p>After 8 unsuccessful attempts: <code>Échec après 8 tentatives : …</code> (“failed after 8 attempts”). Connecting takes at
most 30 s, and a read that stays silent for more than 180 s is considered dropped.</p>

<h2>Parallelism</h2>

<p>While a file is being uploaded, <strong>each webhook has its own stream</strong>: with three webhooks, three chunks go out
at the same time. The chunks of one file are thus spread across the webhooks (and their channels). Files, however, are sent
one after the other.</p>

<p>On download, three chunks are prefetched while the previous one is decrypted and written.</p>

<h2>Organisation tips</h2>

<ul>
  <li>A dedicated <strong>private server</strong>, with no other members.</li>
  <li><strong>One channel per webhook</strong>, reserved for storage: nobody writes there, nothing gets lost.</li>
  <li>Never delete a webhook or a channel that has been used, and do not move a webhook to another channel: it only finds
    its messages in its current channel. For a new channel, create a new webhook.</li>
  <li>Keep webhook URLs as secret as passwords: they allow deleting your chunks.</li>
</ul>

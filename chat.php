<?php
$pageTitle = 'iTISTANT AI';
$active = 'chat';
require_once __DIR__ . '/includes/bootstrap.php';
$extraCss = '<link rel="stylesheet" href="' . e(url('assets/css/chat.css')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/css/chat.css') . '">';
require __DIR__ . '/includes/header.php';

$uid = (int)$me['id'];
chat_ensure_schema();
chat_migrate_legacy($uid);
$convs = chat_list($uid);
$openId = (int)($_GET['c'] ?? 0);
$open = null;
if ($openId && ($c = chat_find($uid, $openId))) {
    $open = ['conversation' => $c, 'messages' => chat_messages($uid, $openId)];
}
$boot = ['conversations' => $convs, 'open' => $open, 'ai' => ai_enabled()];
?>
<section id="page-aichat" class="page">
  <div class="cx is-side-open" id="cx">
    <aside class="cx-side" id="cxSide" aria-label="Riwayat percakapan">
      <div class="cx-side-in">
        <div class="cx-side-head">
          <button class="cx-new" id="cxNew" type="button"><span aria-hidden="true"><?= icon('plus') ?></span> Percakapan baru</button>
          <button class="cx-icon" id="cxCollapse" type="button" title="Tutup sidebar" aria-label="Tutup sidebar">‹</button>
        </div>
        <div class="cx-search">
          <span class="cx-search-ic" aria-hidden="true"><?= icon('search') ?></span>
          <input id="cxSearch" type="search" placeholder="Cari percakapan..." autocomplete="off" aria-label="Cari percakapan">
          <button class="cx-search-x hidden" id="cxSearchX" type="button" aria-label="Hapus pencarian"><?= icon('xmark') ?></button>
        </div>
        <div class="cx-list" id="cxList" role="list"></div>
        <div class="cx-side-foot">Riwayat tersimpan di akunmu. Jangan bagikan OTP atau data pribadi.</div>
      </div>
    </aside>
    <div class="cx-backdrop" id="cxBackdrop"></div>

    <section class="cx-main">
      <header class="cx-head">
        <button class="cx-icon" id="cxToggle" type="button" title="Buka/tutup sidebar" aria-label="Buka atau tutup sidebar" aria-controls="cxSide" aria-expanded="true"><?= icon('bars') ?></button>
        <div class="cx-bot" aria-hidden="true"><?= ico_img('iTISTANT_Ai') ?></div>
        <div class="cx-head-txt"><strong id="cxTitle">iTISTANT AI</strong><small>Asisten edukasi keamanan digital</small></div>
        <button class="cx-icon cx-head-new" id="cxNewTop" type="button" title="Percakapan baru" aria-label="Percakapan baru"><?= icon('plus') ?></button>
        <span class="tag tag-green cx-online"><?= icon('dot') ?> Online</span>
      </header>

      <div class="cx-scroll" id="cxScroll">
        <div class="cx-thread" id="cxThread" aria-live="polite"></div>
      </div>
      <button class="cx-down hidden" id="cxDown" type="button" aria-label="Ke pesan terbaru"><?= icon('arrow-down') ?></button>

      <div class="cx-drop hidden" id="cxDrop" aria-hidden="true"><div><b><?= icon('paperclip') ?> Lepaskan file di sini</b><small>Gambar, PDF, DOCX, atau file teks · maks. 4 file, 10 MB per file</small></div></div>

      <form class="cx-composer" id="cxForm" autocomplete="off">
        <input type="file" id="cxFile" class="hidden" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.docx,.txt,.md,.csv,.tsv,.json,.log,.eml,.xml,image/*">
        <div class="cx-box">
          <div class="cx-files hidden" id="cxFiles" aria-label="Lampiran"></div>
          <button class="cx-plus" id="cxPlus" type="button" title="Lampirkan file (gambar, PDF, DOCX, teks)" aria-label="Lampirkan file"><?= icon('plus') ?></button>
          <textarea id="cxInput" rows="1" maxlength="1500" placeholder="Tulis pertanyaanmu atau lampirkan file untuk dianalisis..." aria-label="Pesan"></textarea>
          <button class="cx-send" id="cxSend" type="submit" aria-label="Kirim" disabled><?= icon('send') ?></button>
        </div>
        <div class="cx-foot"><span><?= ai_enabled() ? 'Dijawab oleh AI.' : 'Mode dasar: jawaban dari basis pengetahuan lokal.' ?> AI bisa keliru, verifikasi lewat kanal resmi.</span><span id="cxCount">0/1500</span></div>
      </form>
    </section>
  </div>
  <script type="application/json" id="cxBoot"><?= chat_json($boot) ?></script>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/chat.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/chat.js') . '"></script>'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
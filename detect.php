<?php
$pageTitle = 'ItihScan AI';
$active = 'detect';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/detections.php';

$uid = (int)$me['id'];
$hasSaved = det_has_saved();
$hasImgs = det_has_images();
$hasAfter = det_has_after();
// Hasil yang tidak disimpan & sudah lebih dari 1 jam dibersihkan otomatis.
if ($hasSaved) {
    foreach (qall('SELECT id FROM detections WHERE user_id = ? AND saved = 0 AND created_at < (NOW() - INTERVAL 1 HOUR)', [$uid]) as $old) {
        det_delete((int)$old['id'], $uid);
    }
}

$rows = qall('SELECT id, input_text, image_path' . ($hasImgs ? ', images' : '') . ($hasAfter ? ', after_steps' : '') . ', risk_score, verdict, reasons, advice, caption, engine, created_at FROM detections WHERE user_id = ?' . ($hasSaved ? ' AND saved = 1' : '') . ' ORDER BY id DESC LIMIT 50', [$uid]);
$hist = array_map(fn ($h) => [
    'id' => (int)$h['id'], 'text' => (string)($h['input_text'] ?? ''), 'image' => $h['image_path'], 'images' => det_image_list($h),
    'score' => (int)$h['risk_score'], 'verdict' => $h['verdict'],
    'reasons' => json_decode((string)$h['reasons'], true) ?: [], 'advice' => json_decode((string)$h['advice'], true) ?: [],
    // riwayat lama (sebelum fitur ini) dibuatkan langkahnya dari pola teks yang tersimpan
    'after' => (isset($h['after_steps']) && $h['after_steps'] !== null) ? (json_decode((string)$h['after_steps'], true) ?: []) : heuristic_after_steps((string)($h['input_text'] ?? ''), $h['verdict']),
    'caption' => ensure_scan_tag((string)($h['caption'] ?? '')), 'engine' => $h['engine'], 'time' => time_ago($h['created_at']), 'saved' => true,
], $rows);
$histJson = json_encode($hist, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section id="page-detect" class="page dt-page" data-tab="work">
  <div class="dt-head">
    <div><h1>ItihScan AI</h1><div class="eyebrow">Periksa sebelum percaya</div></div>
    <button type="button" class="btn btn-light dt-reopen" id="dtSideOpen" title="Buka riwayat analisis"><?= icon('history') ?> Riwayat <span id="detectCountOpen">(<?= count($hist) ?>)</span></button>
  </div>

  <div class="dt-tabs">
    <button type="button" class="tab active" data-dt-tab="work"><?= ico_img('iTIHSCAN') ?> ItihScan AI</button>
    <button type="button" class="tab" data-dt-tab="history"><?= icon('history') ?> Riwayat <span id="detectCountM">(<?= count($hist) ?>)</span></button>
  </div>

  <div class="dt-grid">
    <!-- Kiri: riwayat (bisa ditutup) -->
    <aside class="card dt-side" id="dtSide">
      <button type="button" class="btn btn-primary dt-new" id="dtNew"><?= ico_img('iTIHSCAN') ?> ItihScan AI</button>
      <div class="dt-side-head">
        <div class="side-title" style="margin:0">Riwayat analisis</div>
        <span class="tag tag-red" id="detectCount"><?= count($hist) ?></span>
        <button type="button" class="dt-mini dt-collapse" id="dtSideClose" title="Tutup riwayat" aria-label="Tutup riwayat">‹</button>
      </div>
      <div class="dt-list" id="detectHistoryList"></div>
    </aside>

    <!-- Kanan: form / hasil -->
    <div class="card dt-work">
      <div id="detectFormView" class="dt-view">
        <form id="detectForm" class="dt-form" enctype="multipart/form-data" novalidate>
          <div class="dt-body">
            <div class="dt-view-head"><div class="feature-icon" style="margin:0"><?= ico_img('iTIHSCAN') ?></div><div><h3 style="margin:0 0 2px">Apa yang ingin kamu periksa?</h3><div class="sub">Jangan sertakan kata sandi, OTP, atau data pribadi sensitif.</div></div></div>
            <div class="dt-field-grow">
              <label class="field-label" for="detectText">Teks pesan / isi laporan</label>
              <textarea class="textarea" name="text" id="detectText" maxlength="4000" placeholder="Tempel pesan mencurigakan di sini...&#10;Contoh: Selamat! Anda memenangkan hadiah. Klik link ini dan masukkan OTP Anda."></textarea>
            </div>
            <div class="dt-attach" id="dtAttach" data-max="6" data-max-mb="<?= (int)MAX_IMAGE_MB ?>">
              <div class="dt-attach-head"><label class="field-label" style="margin:0">Lampiran gambar <span class="sub">(opsional)</span></label><span class="sub" id="dtAttachCount"></span></div>
              <label class="dt-drop" id="dtDrop" for="detectImages"><div class="dt-drop-ico"><?= icon('image') ?></div><b>Pilih atau seret beberapa gambar</b><span>PNG, JPG, WebP · maks. 6 gambar · <?= (int)MAX_IMAGE_MB ?> MB per gambar</span></label>
              <input id="detectImages" type="file" accept="image/*" multiple hidden>
              <div class="dt-previews hidden" id="dtPreviews"></div>
              <div class="sub" style="margin-top:6px">Tip: klik <b>Edit</b> pada gambar untuk menyamarkan data pribadi (coretan, blur, mosaik) sebelum diperiksa &amp; dijadikan laporan.</div>
            </div>
          </div>
          <div class="notice hidden" id="detectUnavailable" role="alert" style="margin:0 18px 12px;background:#fff7e6;color:#8a5a00;border:1px solid #f3dca8"><b>Deteksi belum bisa dilakukan saat ini.</b> Layanan AI sedang tidak tersedia, jadi teks dan gambarmu belum bisa diperiksa. Tidak ada yang tersimpan — coba lagi nanti ya.<span id="detectUnavailableDetail" class="sub" style="display:block;margin-top:4px"></span></div>
          <div class="dt-foot"><span class="sub">Analisis ini adalah indikasi awal, bukan kepastian hukum.</span><button class="btn btn-primary" id="detectBtn" type="submit"><?= ico_img('iTIHSCAN') ?> ItihScan AI</button></div>
        </form>
      </div>
      <div id="detectResultView" class="dt-view hidden"></div>
    </div>
  </div>
  <script type="application/json" id="detectHistoryData"><?= $histJson ?></script>
</section>
<?php $extraJs = '<script src="' . e(url('assets/js/detect.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/detect.js') . '"></script>';
require __DIR__ . '/includes/footer.php'; ?>
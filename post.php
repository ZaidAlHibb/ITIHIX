<?php
$pageTitle = 'Laporan';
$active = 'home';
$guest = true;
require __DIR__ . '/includes/header.php';
$id = (int)($_GET['id'] ?? 0);
$p = get_post($id, ($me['role'] ?? '') === 'admin');
$canSee = $p && ($p['status'] === 'approved' || (int)$p['user_id'] === (int)($me['id'] ?? 0) || is_staff($me));
if (!$canSee) {
    http_response_code(404);
    echo '<div class="card" style="padding:30px;text-align:center"><h2>Postingan tidak ditemukan</h2><a class="btn btn-primary" href="' . e(url('index.php')) . '">Kembali ke beranda</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
?>
<section id="page-post" class="page">
  <div class="detail-bar">
    <a class="back-btn" href="<?= e(url('index.php')) ?>" data-back aria-label="Kembali" title="Kembali"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a>
    <h1>Postingan</h1>
  </div>
  <?php
  $html = render_post($p, $me, ['detail' => true]);
if ($p['status'] === 'approved') {
    $html = str_replace('class="comments hidden" id="comments-' . $id . '" data-loaded="0"', 'class="comments hidden" id="comments-' . $id . '" data-loaded="0" data-autoload="1"', $html);
}
echo $html;
?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
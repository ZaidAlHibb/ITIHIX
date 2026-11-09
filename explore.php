<?php
$pageTitle = 'Jelajahi';
$active = 'explore';
require __DIR__ . '/includes/header.php';

$qs  = trim((string)($_GET['q'] ?? ''));
$tag = ltrim(trim((string)($_GET['tag'] ?? '')), '#');
$cat = trim((string)($_GET['cat'] ?? ''));
$sub = (string)($_GET['sub'] ?? '');
if (!isset(all_subs()[$sub])) {
    $sub = '';
}
$label = (string)($_GET['label'] ?? '');
if (!isset(label_defs()[$label])) {
    $label = '';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 10;
$filtering = ($qs !== '' || $tag !== '' || $cat !== '' || $label !== '' || $sub !== '');

$cats = [
    ['mobile', 'Penipuan WhatsApp / SMS', 'Pesan berantai, APK berbahaya, dan penyamaran identitas.'],
    ['card', 'Investasi bodong', 'Iming-iming keuntungan tinggi tanpa risiko.'],
    ['fish', 'Phishing & tautan palsu', 'Halaman login palsu dan pencurian data pribadi.'],
    ['laptop', 'Lowongan kerja palsu', 'Rekrutmen palsu yang meminta biaya administrasi.'],
    ['masks', 'Impersonasi / penyamaran', 'Akun palsu yang mencatut pejabat, instansi, atau orang terdekat.'],
    ['thumbtack', 'Lainnya', 'Modus lain seperti romansa palsu, donasi palsu, dan jual beli online.'],
];
?>
<section id="page-explore" class="page">
  <div class="explore-mobile-search"><form class="searchbox explore-searchbox" method="get"><span class="search-icon"><?= icon('search') ?></span><input name="q" value="<?= e($qs) ?>" placeholder="Cari laporan, topik, atau komunitas..."></form></div>
  <div class="page-heading"><div><h1>Jelajahi</h1><div class="sub">Laporan populer, topik hangat, dan edukasi komunitas.</div></div>
    <?php if ($filtering): ?><a class="btn btn-light" href="<?= e(url('explore.php')) ?>"><?= icon('xmark') ?> Hapus filter</a><?php endif; ?></div>

<?php if ($filtering):
    $where = 'p.status = "approved"';
    $params = [];
    $join = '';
    if ($tag !== '') {
        $join = 'JOIN post_hashtags fph ON fph.post_id = p.id JOIN hashtags fh ON fh.id = fph.hashtag_id';
        $where .= ' AND fh.tag = :tag';
        $params['tag'] = $tag;
    }
    if ($cat !== '') {
        [$csql, $cpar] = category_filter_sql($cat);
        $where .= ' AND ' . $csql;
        $params += $cpar;
    }
    if ($sub !== '') {
        [$ssql, $spar] = sub_filter_sql($sub);
        $where .= ' AND ' . $ssql;
        $params += $spar;
    }
    if ($label !== '') {
        [$lsql, $lpar] = label_filter_sql($label);
        $where .= ' AND ' . $lsql;
        $params += $lpar;
    }
    if ($qs !== '') {
        $like = '%' . addcslashes($qs, '%_') . '%';
        $params += ['s1' => $like, 's2' => $like, 's3' => $like];
        $catSearch = '';
        if (categories_ready()) {
            $catSearch = ' OR EXISTS(SELECT 1 FROM post_categories sc WHERE sc.post_id = p.id AND sc.category LIKE :s4)';
            $params['s4'] = $like;
        }
        if (subs_ready()) {
            $catSearch .= ' OR EXISTS(SELECT 1 FROM post_subcategories ss WHERE ss.post_id = p.id AND ss.sub LIKE :s5)';
            $params['s5'] = $like;
        }
        $where .= ' AND (p.title LIKE :s1 OR p.body LIKE :s2 OR p.category LIKE :s3' . $catSearch . ')';
    }
    $posts = fetch_posts(['where' => $where, 'params' => $params, 'join' => $join, 'limit' => $per + 1, 'offset' => ($page - 1) * $per]);
    $more = count($posts) > $per;
    $posts = array_slice($posts, 0, $per);
    $users = [];
    if ($qs !== '') {
        $users = qall('SELECT id, username, display_name, avatar_color, avatar_path, level, show_level, role FROM users WHERE status="active" AND (username LIKE ? OR display_name LIKE ?) ORDER BY xp DESC LIMIT 6', ['%' . addcslashes($qs, '%_') . '%', '%' . addcslashes($qs, '%_') . '%']);
    }
    ?>
  <div class="sub" style="margin-bottom:12px">Hasil untuk <b><?= e($qs !== '' ? '“' . $qs . '”' : ($tag !== '' ? '#' . $tag : ($label !== '' ? 'label ' . label_defs()[$label]['name'] : ($sub !== '' ? ($cat !== '' ? 'kategori ' . $cat . ' › ' : 'jenis ') . $sub : 'kategori ' . $cat)))) ?></b></div>
  <?php if ($cat !== '' && !empty(category_defs()[$cat]['subs'])): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:-2px 0 14px"><span class="sub">Jenis modus:</span>
      <a class="tag <?= $sub === '' ? 'tag-red' : 'tag-neutral' ?>" href="<?= e(url('explore.php?cat=' . urlencode($cat))) ?>" style="text-decoration:none">Semua</a>
      <?php foreach (category_defs()[$cat]['subs'] as $sn): ?><a class="tag <?= $sub === $sn ? 'tag-red' : 'tag-neutral' ?>" href="<?= e(url('explore.php?cat=' . urlencode($cat) . '&sub=' . urlencode($sn))) ?>" style="text-decoration:none"><?= e($sn) ?></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($users): ?>
    <div class="card" style="padding:14px;margin-bottom:14px"><div class="side-title">Pengguna</div>
      <?php foreach ($users as $u): ?>
        <a href="<?= e(url(profile_url($u['username']))) ?>" style="display:flex;gap:10px;align-items:center;padding:8px 0;text-decoration:none;color:inherit"><?= avatar_html($u) ?><div><strong><?= e($u['display_name']) ?></strong><div class="sub">@<?= e($u['username']) ?><?= $u['show_level'] ? ' · Lv. ' . (int)$u['level'] : '' ?></div></div></a>
      <?php endforeach; ?></div>
  <?php endif; ?>
  <?= render_posts($posts, $me, 'Tidak ada laporan yang cocok.') ?>
  <div class="pager">
    <?php $base = '?' . http_build_query(array_filter(['q' => $qs, 'tag' => $tag, 'cat' => $cat, 'sub' => $sub, 'label' => $label])); ?>
    <?php if ($page > 1): ?><a class="btn btn-light" href="<?= e($base . '&page=' . ($page - 1)) ?>">← Sebelumnya</a><?php endif; ?>
    <?php if ($more): ?><a class="btn btn-light" href="<?= e($base . '&page=' . ($page + 1)) ?>">Berikutnya →</a><?php endif; ?>
  </div>
<?php else:
    $tops = top_hashtags(6, 1);
    $popular = fetch_posts(['order' => '((SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) + (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.deleted_at IS NULL)) DESC, p.created_at DESC', 'where' => 'p.status="approved"', 'limit' => 12]);
    ?>
  <section class="card explore-top-hashtags" aria-labelledby="exploreTopTagsTitle">
    <div class="explore-top-head"><div><div class="eyebrow">Yang sedang ramai</div><h2 id="exploreTopTagsTitle">Top # Hari Ini</h2><p class="sub">Tagar populer dari laporan dan diskusi komunitas.</p></div><span class="explore-trend-badge"><?= icon('trend') ?> Trending</span></div>
    <div class="explore-hashtag-grid">
      <?php foreach ($tops as $i => $t): ?>
        <a class="explore-hashtag" href="<?= e(url('explore.php?tag=' . urlencode($t['tag']))) ?>" style="text-decoration:none"><span class="hashtag-rank"><?= sprintf('%02d', $i + 1) ?></span><span class="hashtag-name">#<?= e($t['tag']) ?></span><small><?= num_short($t['c']) ?> diskusi</small><b>›</b></a>
      <?php endforeach;
    if (!$tops): ?><div class="sub" style="padding:10px">Belum ada tagar. Tambahkan #tagar pada laporanmu.</div><?php endif; ?>
    </div>
  </section>
  <section class="card" style="padding:16px 18px;margin:14px 0">
    <div class="explore-top-head"><div><div class="eyebrow">Telusuri</div><h2 style="font-size:17px;margin:2px 0 0">Berdasarkan label</h2></div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <?php foreach (label_defs() as $lk => $ld): [$lsql, $lpar] = label_filter_sql($lk);
          $lc = (int)qval('SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id AND u.status = "active" WHERE p.status = "approved" AND p.deleted_at IS NULL AND p.type <> "short" AND ' . $lsql, $lpar); ?>
        <a class="tag <?= e($ld['cls']) ?>" href="<?= e(url('explore.php?label=' . urlencode($lk))) ?>" style="text-decoration:none;padding:8px 13px;font-size:12px"><?= eic($ld['chip']) ?> · <?= num_short($lc) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="content-grid">
    <?php foreach ($cats as [$ic, $name, $desc]): ?>
      <a class="card feature-card" href="<?= e(url('explore.php?cat=' . urlencode($name))) ?>" style="text-decoration:none;color:inherit"><div class="feature-icon"><?= icon($ic) ?></div><h3><?= e($name) ?></h3><p><?= e($desc) ?></p><span class="tag tag-red"><?php [$csql, $cpar] = category_filter_sql($name); ?><?= (int)qval('SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id AND u.status = "active" WHERE p.status="approved" AND p.deleted_at IS NULL AND ' . $csql, $cpar) ?> laporan</span></a>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:22px" class="page-heading"><div><h2 style="font-size:18px;margin:0">Laporan yang sedang ramai</h2><div class="sub">Paling banyak dibicarakan komunitas</div></div></div>
  <?php if ($popular): ?>
  <div class="grid-posts">
    <?php foreach ($popular as $p): ?>
      <?php $isShort = ($p['type'] ?? '') === 'short'; ?>
      <a class="card grid-post" href="<?= e($isShort ? url('safesnaps.php?v=' . (int)$p['id']) : url('post.php?id=' . $p['id'])) ?>"<?= $isShort ? ' data-type="short"' : '' ?>>
        <?php $vth = (!$p['image_path'] && !empty($p['video_path'])) ? video_thumb_url($p['video_path']) : null;
        $isVid = !$p['image_path'] && !empty($p['video_path']); ?>
        <?php $isTxt = !$p['image_path'] && !$isVid;
        $ttl = trim((string)$p['title']);
        $tl = mb_strlen($ttl);
        $tsz = $tl <= 28 ? 'tt-lg' : ($tl <= 60 ? 'tt-md' : 'tt-sm');
        $tcat = (string)(($p['categories'][0] ?? '') ?: ($p['category'] ?? '')); ?>
        <div class="thumb<?= $isVid ? ' is-video' : '' ?><?= $isTxt ? ' is-text ' . $tsz : '' ?>"><?php if ($p['image_path']): ?><img src="<?= e(url('uploads/' . $p['image_path'])) ?>" alt="" loading="lazy"><?php elseif ($vth): ?><img src="<?= e($vth) ?>" alt="" loading="lazy"><?php elseif ($isVid): ?><video src="<?= e(url('uploads/' . $p['video_path'])) ?>#t=0.1" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true" style="width:100%;height:100%;object-fit:cover;pointer-events:none"></video><?php else: ?><span class="tt-cat"><?= e($tcat !== '' ? mb_strimwidth($tcat, 0, 26, '…') : 'Laporan') ?></span><span class="tt-title"><?= e(mb_strimwidth($ttl, 0, 90, '…')) ?></span><span class="tt-foot"><?= ($p['type'] ?? '') === 'education' ? 'Baca edukasi' : 'Baca laporan' ?> →</span><?php endif; ?><?php if (!$isTxt): ?><span class="thumb-chip<?= $isShort ? ' is-ss' : '' ?>"><?= $isShort ? icon('film') . ' SafeSnaps' : e($tcat !== '' ? mb_strimwidth($tcat, 0, 26, '…') : 'Laporan') ?></span><?php endif; ?><?php if ($isVid): ?><span class="thumb-play"><?= icon('play') ?></span><?php endif; ?></div>
        <div class="gp-body"><h4><?= e(mb_strimwidth($p['title'], 0, 70, '…')) ?></h4><div class="sub">@<?= e($p['username']) ?> · <?= icon('heart-o') ?> <?= (int)$p['like_count'] ?> · <?= icon('comment') ?> <?= (int)$p['comment_count'] ?></div></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div class="card" style="padding:28px;text-align:center;color:var(--muted)">Belum ada laporan terbit.</div><?php endif; ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
/**
 * Logika halaman admin (admin.php): helper, ekspor CSV, aksi POST, dan kueri tiap tab.
 * admin.php sendiri hanya mengatur alur halaman dan HTML. Fungsi adm_*_data() mengembalikan array
 * variabel yang dipakai tampilan (dipasang lewat extract()).
 */

function adm_url(array $o = []): string
{
    return '?' . http_build_query(array_filter(array_merge($_GET, $o), fn ($v) => $v !== '' && $v !== null));
}
function adm_pager(int $page, bool $more): string
{
    $h = '';
    if ($page > 1) {
        $h .= '<a class="btn btn-light" href="' . e(adm_url(['page' => $page - 1])) . '">←</a>';
    }
    if ($more) {
        $h .= '<a class="btn btn-light" href="' . e(adm_url(['page' => $page + 1])) . '">→</a>';
    }
    return '<div class="pager">' . $h . '</div>';
}
function adm_safe(string $sql): array
{
    try {
        return qall($sql);
    } catch (Throwable $e) {
        return [];
    }
}
function adm_days(string $sql): array
{
    $d = [];
    for ($i = 6; $i >= 0; $i--) {
        $d[date('Y-m-d', strtotime("-$i day"))] = 0;
    }
    foreach (adm_safe($sql) as $r) {
        if (isset($d[$r['d']])) {
            $d[$r['d']] = (int)$r['c'];
        }
    }
    return $d;
}
function adm_wow(string $t, string $x = ''): array
{
    try {
        $a = (int)qval("SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $x");
        $b = (int)qval("SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) $x");
        return [$a, $b];
    } catch (Throwable $e) {
        return [0, 0];
    }
}
function adm_delta(int $a, int $b): string
{
    if ($b === 0) {
        return $a > 0 ? 'baru' : '—';
    }
    $p = (int)round(($a - $b) / $b * 100);
    return ($p >= 0 ? '▲ ' : '▼ ') . abs($p) . '%';
}
function adm_bytes(float $b): string
{
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
        if ($b < 1024 || $u === 'TB') {
            return round($b, $i ? 1 : 0) . ' ' . $u;
        } $b /= 1024;
    }
    return '';
}
function adm_bar(string $l, int $v, int $m, string $c = ''): string
{
    return '<div class="adm-bar"><span>' . e($l) . '</span><i class="' . $c . '"><b style="width:' . ($m ? round($v / $m * 100) : 0) . '%"></b></i><em>' . $v . '</em></div>';
}
function adm_dir_size(string $dir): array // di-cache 10 menit agar dashboard tetap ringan
{$cache = sys_get_temp_dir() . '/itihix_upl_' . md5($dir) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j)) {
            return $j;
        }
    }
    $size = 0;
    $n = 0;
    if (is_dir($dir)) {
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $size += $f->getSize();
                    $n++;
                }
            }
        } catch (Throwable $e) {
            error_log('[ITIHIX] ' . basename(__FILE__) . ': ' . $e->getMessage());
        }
    }
    $r = ['size' => $size, 'files' => $n];
    @file_put_contents($cache, json_encode($r));
    return $r;
}
function adm_filter_logs(array $get): array
{
    $w = ['1'];
    $p = [];
    $fa = trim((string)($get['action'] ?? ''));
    if ($fa !== '') {
        $w[] = 'l.action = ?';
        $p[] = $fa;
    }
    $fu = ltrim(trim((string)($get['user'] ?? '')), '@');
    if ($fu !== '') {
        $w[] = 'u.username LIKE ?';
        $p[] = '%' . addcslashes($fu, '%_') . '%';
    }
    $ff = (string)($get['from'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ff)) {
        $w[] = 'l.created_at >= ?';
        $p[] = $ff . ' 00:00:00';
    }
    $ft = (string)($get['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ft)) {
        $w[] = 'l.created_at <= ?';
        $p[] = $ft . ' 23:59:59';
    }
    return [implode(' AND ', $w), $p];
}

/** Ekspor CSV log aktivitas (maks. 5.000 baris sesuai filter), lalu menghentikan skrip. */
function adm_export_logs(array $get): never
{
    [$w, $p] = adm_filter_logs($get);
    $rows = qall("SELECT l.created_at, u.username, l.action, l.target_type, l.target_id, l.detail, l.ip FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT 5000", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="log-itihix-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Waktu', 'Pengguna', 'Aksi', 'Target', 'ID target', 'Detail', 'IP'], ',', '"', '');
    foreach ($rows as $r) {
        $r = array_map(function ($v) {
            $v = (string)$v;
            return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        }, $r); // cegah formula injection
        fputcsv($o, array_values($r), ',', '"', '');
    }
    exit;
}

/** Menangani aksi POST admin (peran, status, buat akun, hapus/pulihkan postingan), lalu redirect. */
function adm_handle_post(array $admin): void
{
    if (!is_post()) {
        return;
    }
    csrf_check();
    $do = $_POST['do'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);
    $back = 'admin.php?tab=' . urlencode($_POST['back'] ?? 'users');
    if ($do === 'set_role' && $uid !== (int)$admin['id']) {
        $role = $_POST['role'] ?? '';
        if (in_array($role, ['user', 'trusted', 'moderator', 'admin'], true)) {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $uid]);
            log_activity('admin_set_role', 'user', $uid, 'Peran diubah menjadi ' . $role);
            notify($uid, 'square-check', 'Perananmu diperbarui', 'Kini kamu berperan sebagai ' . role_label($role) . '.');
            flash('Peran pengguna diperbarui.');
        }
    } elseif ($do === 'toggle_status' && $uid !== (int)$admin['id']) {
        $cur = qval('SELECT status FROM users WHERE id = ?', [$uid]);
        $new = $cur === 'active' ? 'suspended' : 'active';
        $why = trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 200));
        q('UPDATE users SET status = ? WHERE id = ?', [$new, $uid]);
        log_activity('admin_' . ($new === 'suspended' ? 'suspend' : 'activate'), 'user', $uid, 'Status akun: ' . $new . ($why !== '' ? ' — alasan: ' . $why : ''));
        flash($new === 'suspended' ? 'Akun ditangguhkan.' : 'Akun diaktifkan kembali.');
    } elseif ($do === 'create_user') {
        $name = trim((string)($_POST['display_name'] ?? ''));
        $user = trim((string)($_POST['username'] ?? ''));
        $mail = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'user';
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $user) || !filter_var($mail, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8 || !in_array($role, ['user', 'trusted', 'moderator', 'admin'], true)) {
            flash('Data akun tidak valid (sandi minimal 8 karakter).', 'err');
        } elseif (qval('SELECT 1 FROM users WHERE username = ? OR email = ?', [$user, $mail])) {
            flash('Nama pengguna atau email sudah dipakai.', 'err');
        } else {
            q('INSERT INTO users (username, display_name, email, password_hash, role) VALUES (?,?,?,?,?)', [$user, mb_substr($name, 0, 60), $mail, password_hash($pw, PASSWORD_DEFAULT), $role]);
            log_activity('admin_create_user', 'user', (int)db()->lastInsertId(), 'Akun ' . $role . ' dibuat: @' . $user);
            flash('Akun berhasil dibuat.');
        }
    } elseif ($do === 'delete_post' || $do === 'restore_post') {
        $pid = (int)($_POST['post_id'] ?? 0);
        q('UPDATE posts SET deleted_at = ' . ($do === 'delete_post' ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$pid]);
        log_activity('admin_' . $do, 'post', $pid, $do === 'delete_post' ? 'Postingan dihapus admin' : 'Postingan dipulihkan');
        flash($do === 'delete_post' ? 'Postingan dihapus.' : 'Postingan dipulihkan.');
    }
    redirect($back);
}

/** Data tab Dashboard. */
function adm_dashboard_data(): array
{
    $c = fn (string $sql): int => (int)qval($sql);
    $live = 'deleted_at IS NULL';
    $totalUsers = $c('SELECT COUNT(*) FROM users');
    $suspended = $c('SELECT COUNT(*) FROM users WHERE status="suspended"');
    $totalPosts = $c("SELECT COUNT(*) FROM posts WHERE $live");
    $pending = $c("SELECT COUNT(*) FROM posts WHERE status='pending' AND $live");
    $pendOld = $c("SELECT COUNT(*) FROM posts WHERE status='pending' AND $live AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $repOpen = $c('SELECT COUNT(*) FROM user_reports WHERE status="open"');
    $repOld = $c('SELECT COUNT(*) FROM user_reports WHERE status="open" AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $failed = $c('SELECT COUNT(*) FROM activity_logs WHERE action="login_failed" AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $badIps = adm_safe('SELECT ip, COUNT(*) c FROM activity_logs WHERE action="login_failed" AND ip<>"" AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY ip ORDER BY c DESC LIMIT 5');
    $todayPosts = $c("SELECT COUNT(*) FROM posts WHERE created_at >= CURDATE() AND $live");
    $dec = qone('SELECT SUM(status="approved") a, COUNT(*) t FROM posts WHERE moderator_id IS NOT NULL AND deleted_at IS NULL');
    $approveRate = $dec['t'] ? round($dec['a'] / $dec['t'] * 100) : 0;
    $quizToday = $c('SELECT COUNT(*) FROM quiz_attempts WHERE created_at >= CURDATE()');
    $detToday = $c('SELECT COUNT(*) FROM detections WHERE created_at >= CURDATE()');
    $chatToday = $c('SELECT COUNT(*) FROM chat_messages WHERE role="user" AND created_at >= CURDATE()');
    [$wp, $wpp] = adm_wow('posts', "AND $live");
    [$wu, $wup] = adm_wow('users');
    [$wd, $wdp] = adm_wow('detections');
    [$wq, $wqp] = adm_wow('quiz_attempts');
    $days = adm_days("SELECT DATE(created_at) d, COUNT(*) c FROM posts WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND $live GROUP BY DATE(created_at)");
    $regs = adm_days('SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)');
    $max = max(1, max($days));
    $maxR = max(1, max($regs));
    $eng = array_map('intval', array_column(adm_safe('SELECT engine, COUNT(*) c FROM detections WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY engine'), 'c', 'engine'));
    $engN = array_sum($eng);
    $rulesShare = $engN ? (int)round(($eng['rules'] ?? 0) / $engN * 100) : 0;
    $ver = array_map('intval', array_column(adm_safe('SELECT verdict, COUNT(*) c FROM detections WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY verdict'), 'c', 'verdict'));
    $verMax = max(1, max($ver ?: [0]));
    $up = adm_dir_size(UPLOAD_DIR);
    $dTot = (float)@disk_total_space(UPLOAD_DIR);
    $dFree = (float)@disk_free_space(UPLOAD_DIR);
    $dPct = $dTot > 0 ? (int)round(($dTot - $dFree) / $dTot * 100) : 0;
    $dbSize = (float)(adm_safe('SELECT COALESCE(SUM(data_length + index_length),0) s FROM information_schema.tables WHERE table_schema = DATABASE()')[0]['s'] ?? 0);
    $aiOn = ai_enabled();
    $since = 'p.deleted_at IS NULL AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    $labs = adm_safe("SELECT pl.label, COUNT(*) c FROM post_labels pl JOIN posts p ON p.id = pl.post_id WHERE pl.kind='final' AND $since GROUP BY pl.label ORDER BY c DESC");
    $subs = adm_safe("SELECT s.sub, COUNT(*) c FROM post_subcategories s JOIN posts p ON p.id = s.post_id WHERE $since GROUP BY s.sub ORDER BY c DESC LIMIT 5");
    $cats = adm_safe("SELECT s.category, COUNT(*) c FROM post_categories s JOIN posts p ON p.id = s.post_id WHERE $since GROUP BY s.category ORDER BY c DESC LIMIT 5");
    $tags = top_hashtags(5, 7);
    $openReps = adm_safe('SELECT r.id, r.reason, r.created_at, tu.username FROM user_reports r JOIN users tu ON tu.id = r.reported_id WHERE r.status="open" ORDER BY r.id LIMIT 5');
    $reasons = report_reasons();
    $alerts = [];
    if ($repOpen) {
        $alerts[] = [$repOld ? 'danger' : 'warn', $repOpen . ' laporan pengguna belum ditangani' . ($repOld ? " ($repOld lebih dari 24 jam)" : ''), url('moderator.php?tab=reports')];
    }
    if ($pendOld) {
        $alerts[] = ['warn', $pendOld . ' postingan menunggu moderasi lebih dari 24 jam', '?tab=posts&status=pending'];
    }
    if ($failed >= 5) {
        $alerts[] = [$failed >= 20 ? 'danger' : 'warn', $failed . ' percobaan login gagal dalam 24 jam terakhir', '?tab=logs&action=login_failed'];
    }
    if (!$aiOn) {
        $alerts[] = ['warn', 'Kunci API AI belum diatur — Deteksi AI memakai aturan dasar.', ''];
    } elseif ($engN >= 5 && $rulesShare >= 30) {
        $alerts[] = ['danger', "$rulesShare% analisis 24 jam terakhir jatuh ke aturan dasar — periksa kunci API/kuota AI.", ''];
    }
if ($dPct >= 85) {
    $alerts[] = ['danger', "Disk server terpakai $dPct% — segera bersihkan atau tambah ruang.", ''];
}
if (!$alerts) {
    $alerts[] = ['ok', 'Semua indikator normal. Tidak ada yang perlu ditindak.', ''];
}
$roles = qall('SELECT role, COUNT(*) c FROM users GROUP BY role');
$top = qall('SELECT username, display_name, level, xp FROM users WHERE status="active" ORDER BY xp DESC LIMIT 5');
    return compact('aiOn', 'alerts', 'approveRate', 'badIps', 'cats', 'chatToday', 'dFree', 'dPct', 'dTot', 'days', 'dbSize', 'detToday', 'eng', 'engN', 'failed', 'labs', 'max', 'maxR', 'openReps', 'pending', 'quizToday', 'reasons', 'regs', 'repOld', 'repOpen', 'roles', 'rulesShare', 'subs', 'suspended', 'tags', 'todayPosts', 'top', 'totalPosts', 'totalUsers', 'up', 'ver', 'verMax', 'wd', 'wdp', 'wp', 'wpp', 'wq', 'wqp', 'wu', 'wup');
}

/** Data tab Pengguna & moderator. */
function adm_users_data(array $get): array
{
    $s = trim((string)($get['q'] ?? ''));
    $fr = (string)($get['role'] ?? '');
    $fs = (string)($get['status'] ?? '');
    $page = max(1, (int)($get['page'] ?? 1));
    $per = 20;
    $where = '1';
    $params = [];
    if ($s !== '') {
        $where = '(u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
        $l = '%' . addcslashes($s, '%_') . '%';
        $params = [$l, $l, $l];
    }
    if (in_array($fr, ['user', 'trusted', 'moderator', 'admin'], true)) {
        $where .= ' AND u.role = ?';
        $params[] = $fr;
    }
    if (in_array($fs, ['active', 'suspended'], true)) {
        $where .= ' AND u.status = ?';
        $params[] = $fs;
    }
    $rows = qall("SELECT u.*, (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND deleted_at IS NULL) pc,
                  (SELECT COUNT(*) FROM posts WHERE moderator_id = u.id) rc,
                  (SELECT COUNT(*) FROM user_reports WHERE reported_id = u.id AND status = 'open') ro FROM users u WHERE $where ORDER BY u.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    return compact('fr', 'fs', 'more', 'page', 'rows', 's');
}

/** Data tab Semua postingan (termasuk postingan yang sedang ditinjau). */
function adm_posts_data(array $get): array
{
    $stf = $get['status'] ?? '';
    $ty = (string)($get['type'] ?? '');
    $pq = trim((string)($get['q'] ?? ''));
    $page = max(1, (int)($get['page'] ?? 1));
    $per = 20;
    $where = '1';
    $params = [];
    if (in_array($stf, ['pending', 'approved', 'rejected', 'clarify'], true)) {
        $where .= ' AND p.status = ?';
        $params[] = $stf;
    }
    if (in_array($ty, ['report', 'education', 'short'], true)) {
        $where .= ' AND p.type = ?';
        $params[] = $ty;
    }
    if ($pq !== '') {
        $where .= ' AND (p.title LIKE ? OR u.username LIKE ?)';
        $l = '%' . addcslashes($pq, '%_') . '%';
        array_push($params, $l, $l);
    }
    $rows = qall("SELECT p.id, p.title, p.status, p.type, p.created_at, p.deleted_at, u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE $where ORDER BY p.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $rv = isset($get['review']) ? qone('SELECT p.*, u.username, u.display_name, u.level, u.xp, u.avatar_color, u.avatar_path, u.status AS author_status FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.deleted_at IS NULL', [(int)$get['review']]) : null;
    $rid = 0;
    $lblS = $catS = $subS = [];
    $nextP = null; // hanya terisi bila ada postingan yang ditinjau
    if ($rv) {
        $rid = (int)$rv['id'];
        $lblS = post_label_sets([$rid])[$rid]['user'] ?? [(string)$rv['user_label']];
        $catS = post_category_sets([$rid])[$rid] ?? [(string)$rv['category']];
        $subS = array_column(post_sub_sets([$rid])[$rid] ?? [], 'sub');
        $nextP = qval("SELECT id FROM posts WHERE status='pending' AND deleted_at IS NULL AND id <> ? ORDER BY created_at, id LIMIT 1", [$rid]);
    }
    return compact('catS', 'lblS', 'more', 'nextP', 'page', 'pq', 'rid', 'rows', 'rv', 'stf', 'subS', 'ty');
}

/** Data tab Log aktivitas. */
function adm_logs_data(array $get): array
{
    $fa = trim((string)($get['action'] ?? ''));
    $fu = trim((string)($get['user'] ?? ''));
    $ff = (string)($get['from'] ?? '');
    $ft = (string)($get['to'] ?? '');
    $page = max(1, (int)($get['page'] ?? 1));
    $per = 40;
    [$where, $params] = adm_filter_logs($get);
    $actions = array_column(adm_safe('SELECT action, COUNT(*) c FROM activity_logs GROUP BY action ORDER BY action'), 'c', 'action');
    if ($fa !== '' && !isset($actions[$fa])) {
        $actions[$fa] = 0;
    }
    $rows = qall("SELECT l.*, u.username FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $where ORDER BY l.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    return compact('actions', 'fa', 'ff', 'ft', 'fu', 'more', 'page', 'rows');
}
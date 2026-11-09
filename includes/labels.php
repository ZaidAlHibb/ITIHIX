<?php

/* ---------- Label postingan ----------
 * Satu postingan boleh punya LEBIH DARI SATU label (Penipuan, Mencurigakan, Klarifikasi, Edukasi).
 * Label bisa diklik seperti hashtag: explore.php?label=penipuan menampilkan semua laporan berlabel itu.
 * Tabel post_labels: kind 'user' = saran pelapor, kind 'final' = label akhir moderator.
 * Kolom posts.user_label / posts.final_label tetap dipakai sebagai label UTAMA (pertama) agar data lama tetap berfungsi.
 */
function label_defs(): array
{
    return [
        'penipuan'     => ['cls' => 'tag-red',   'chip' => ':warn: Penipuan', 'name' => 'Penipuan',     'hint' => 'Ini penipuan'],
        'mencurigakan' => ['cls' => 'tag-amber', 'chip' => ':question: Mencurigakan', 'name' => 'Mencurigakan', 'hint' => 'Mencurigakan, perlu diverifikasi'],
        'klarifikasi'  => ['cls' => 'tag-amber', 'chip' => ':question: Klarifikasi', 'name' => 'Klarifikasi',  'hint' => 'Saya ragu, mohon klarifikasi komunitas'],
        'edukasi'      => ['cls' => 'tag-green', 'chip' => ':check: Edukasi', 'name' => 'Edukasi',      'hint' => 'Edukasi / tips pencegahan'],
    ];
}

function labels_ready(): bool
{
    return schema_ready();
}
function subs_ready(): bool
{
    return schema_ready();
}
function categories_ready(): bool
{
    return schema_ready();
}

/** Ambil input label dari form (array) -> daftar label valid, tanpa duplikat, urut sesuai label_defs(). */
function parse_labels($raw): array
{
    $defs = label_defs();
    $got = [];
    foreach ((array)$raw as $v) {
        if (is_string($v) && isset($defs[$v])) {
            $got[$v] = true;
        }
    }
    return array_values(array_filter(array_keys($defs), fn ($k) => isset($got[$k])));
}

function set_post_labels(int $pid, string $kind, array $labels): void
{
    if (!labels_ready() || !in_array($kind, ['user', 'final'], true)) {
        return;
    }
    try {
        q('DELETE FROM post_labels WHERE post_id = ? AND kind = ?', [$pid, $kind]);
        foreach (parse_labels($labels) as $l) {
            q('INSERT IGNORE INTO post_labels (post_id, label, kind) VALUES (?,?,?)', [$pid, $l, $kind]);
        }
    } catch (Throwable $e) {
        error_log('set_post_labels: ' . $e->getMessage());
    }
}

/** @return array<int,array{user?:string[],final?:string[]}> */
function post_label_sets(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !labels_ready()) {
        return [];
    }
    $order = array_flip(array_keys(label_defs()));
    $sets = [];
    try {
        foreach (qall('SELECT post_id, label, kind FROM post_labels WHERE post_id IN (' . implode(',', $ids) . ')') as $r) {
            $sets[(int)$r['post_id']][$r['kind']][] = $r['label'];
        }
    } catch (Throwable $e) {
        return [];
    }
    foreach ($sets as $pid => $kinds) {
        foreach ($kinds as $kind => $list) {
            usort($list, fn ($a, $b) => $order[$a] <=> $order[$b]);
            $sets[$pid][$kind] = $list;
        }
    }
    return $sets;
}

/** Label yang berlaku: disetujui -> label akhir moderator; belum -> saran pelapor. Data lama jatuh ke kolom label tunggal. */
function effective_labels(array $p, array $set = []): array
{
    if (($p['status'] ?? '') === 'approved') {
        $l = $set['final'] ?? [];
        $one = $p['final_label'] ?? null ?: ($p['user_label'] ?? null);
    } else {
        $l = $set['user'] ?? [];
        $one = $p['user_label'] ?? null;
    }
    return $l ?: ($one ? [(string)$one] : []);
}
function attach_post_labels(array $rows): array
{
    if (!$rows) {
        return $rows;
    }
    $sets = post_label_sets(array_column($rows, 'id'));
    $cats = post_category_sets(array_column($rows, 'id'));
    $subs = post_sub_sets(array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['subs'] = $subs[(int)$r['id']] ?? [];
        $r['labels'] = effective_labels($r, $sets[(int)$r['id']] ?? []);
        $r['categories'] = $cats[(int)$r['id']] ?? [(string)$r['category']];
    }
    unset($r);
    return $rows;
}

/** Label sebagai tautan (seperti hashtag) menuju daftar laporan berlabel sama. */
function label_chip(string $label, bool $link = true): string
{
    $d = label_defs()[$label] ?? null;
    if (!$d) {
        return '';
    }
    if (!$link) {
        return '<span class="tag ' . $d['cls'] . '">' . eic($d['chip']) . '</span>';
    }
    return '<a class="tag ' . $d['cls'] . ' label-chip" href="' . e(url('explore.php?label=' . urlencode($label))) . '" title="Lihat semua laporan berlabel ' . e($d['name']) . '" style="text-decoration:none">' . eic($d['chip']) . '</a>';
}

/** Potongan SQL (alias posts = p) untuk menyaring postingan terbit berdasarkan satu label. */
function label_filter_sql(string $label): array
{
    $legacy = 'COALESCE(p.final_label, p.user_label) = :lbl2';
    if (!labels_ready()) {
        return [$legacy, ['lbl2' => $label]];
    }
    return ['(EXISTS(SELECT 1 FROM post_labels pl WHERE pl.post_id = p.id AND pl.kind = "final" AND pl.label = :lbl1)
              OR (NOT EXISTS(SELECT 1 FROM post_labels pf WHERE pf.post_id = p.id AND pf.kind = "final") AND ' . $legacy . '))',
            ['lbl1' => $label, 'lbl2' => $label]];
}

function picker_css(): string // CSS pemilih chip, dicetak sekali per halaman
{static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>.label-pick{display:flex;flex-wrap:wrap;gap:8px}.label-opt{position:relative;cursor:pointer}.label-opt input{position:absolute;opacity:0;pointer-events:none}'
        . '.label-opt span{display:flex;flex-direction:column;gap:2px;min-width:150px;padding:9px 13px;border:1px solid var(--line);border-radius:12px;background:#fff;font-size:12px;font-weight:750}'
        . '.label-opt span.cat{min-width:0;flex-direction:row;align-items:center;gap:6px}'
        . '.label-opt small{font-size:10.5px;font-weight:400;color:var(--muted)}'
        . '.label-opt input:checked+span{border-color:#e58d93;background:var(--red-soft);color:var(--red-dark)}'
        . '.label-opt input:focus-visible+span{outline:2px solid #e58d93;outline-offset:1px}'
        . '.sub-wrap[hidden],.sub-group[hidden]{display:none!important}body.dark .label-opt span{background:#292a33;border-color:#3b3c47}body.dark .label-opt input:checked+span{background:#3a2026}'
        . '.label-pick.label-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}'
        . '.label-grid .label-opt{display:block;min-width:0}'
        . '.label-grid .label-opt span{position:relative;height:100%;min-width:0;gap:3px;padding:12px 44px 12px 14px;border-width:1.5px;border-radius:14px;transition:border-color .15s,background .15s,box-shadow .15s}'
        . '.label-grid .label-opt:hover span{border-color:#e9b3b7}'
        . '.label-grid .lo-t{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:800;line-height:1.3}'
        . '.label-grid .lo-t svg,.label-grid .lo-t .ic{flex:0 0 auto;width:16px;height:16px}'
        . '.label-grid small{font-size:11px;line-height:1.4;color:var(--muted)}'
        . '.label-grid .label-opt span::after{content:"";position:absolute;right:13px;top:13px;width:18px;height:18px;border-radius:50%;border:1.5px solid #cfd0d8;background:transparent;transition:all .15s}'
        . '.label-grid .label-opt span::before{content:"";position:absolute;right:20px;top:17px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg);opacity:0;z-index:1;transition:opacity .15s}'
        . '.label-grid .label-opt input:checked+span{box-shadow:0 0 0 3px #e5394518}'
        . '.label-grid .label-opt input:checked+span::after{background:var(--red);border-color:var(--red)}'
        . '.label-grid .label-opt input:checked+span::before{opacity:1}'
        . '.label-grid .label-opt[data-tone=amber] input:checked+span{border-color:#e3b25a;background:#fff5df;color:#98600a;box-shadow:0 0 0 3px #e3b25a22}'
        . '.label-grid .label-opt[data-tone=amber] input:checked+span::after{background:#d89a2b;border-color:#d89a2b}'
        . '.label-grid .label-opt[data-tone=green] input:checked+span{border-color:#6cc3a0;background:#e9f7f1;color:#147857;box-shadow:0 0 0 3px #6cc3a022}'
        . '.label-grid .label-opt[data-tone=green] input:checked+span::after{background:#1f9f74;border-color:#1f9f74}'
        . '.label-grid .label-opt input:checked+span small{color:inherit;opacity:.8}'
        . 'body.dark .label-grid .label-opt span::after{border-color:#555664}'
        . 'body.dark .label-grid .label-opt:hover span{border-color:#61333a}'
        . 'body.dark .label-grid .label-opt[data-tone=amber] input:checked+span{background:#3b301a;color:#f2c66d;border-color:#8a6a2a}'
        . 'body.dark .label-grid .label-opt[data-tone=green] input:checked+span{background:#173b32;color:#73d9b2;border-color:#2c7a60}'
        . '@media(max-width:480px){.label-pick.label-grid{grid-template-columns:1fr}}</style>';
}

/** Pilihan label (boleh lebih dari satu) untuk formulir. */
function label_picker(string $name, array $selected): string
{
    $h = picker_css() . '<div class="label-pick label-grid" role="group" aria-label="Pilih label">';
    foreach (label_defs() as $k => $d) {
        $tone = str_replace('tag-', '', $d['cls']);
        $h .= '<label class="label-opt" data-tone="' . e($tone) . '"><input type="checkbox" name="' . e($name) . '[]" value="' . e($k) . '"' . (in_array($k, $selected, true) ? ' checked' : '') . '><span><b class="lo-t">' . eic($d['chip']) . '</b><small>' . e($d['hint']) . '</small></span></label>';
    }
    return $h . '</div><div class="sub" style="margin-top:6px">Pilih satu atau lebih label.</div>';
}

/* ---------- Kategori ----------
 * Sama seperti label: satu postingan boleh punya beberapa kategori (maks. 3), bisa diklik (explore.php?cat=...).
 * Tabel post_categories; kolom posts.category tetap menyimpan kategori UTAMA (pertama).
 */
const MAX_CATEGORIES = 3;
function category_defs(): array
{
    // 'subs' = jenis modus di dalam kategori (opsional dipilih pelapor). Nama sub HARUS unik di seluruh daftar.
    return [
        'Phishing & tautan palsu' => ['icon' => 'fish', 'seeds' => ['PhishingAlert', 'LinkPalsu', 'WaspadaTautan'],
            'subs' => ['Email phishing', 'Login bank / e-wallet palsu', 'Situs belanja palsu', 'Tautan undian / hadiah', 'Pencurian akun media sosial', 'QR code palsu']],
        'Penipuan WhatsApp / SMS' => ['icon' => 'mobile', 'seeds' => ['PenipuanWhatsApp', 'WaspadaAPK', 'SMSPenipuan'],
            'subs' => ['Hadiah / undian palsu', 'APK undangan / paket', 'Catut nomor keluarga / atasan', 'Kurir / tarif paket palsu', 'Admin bank / CS palsu', 'Pinjol ilegal', 'Minta kode OTP']],
        'Investasi bodong' => ['icon' => 'card', 'seeds' => ['InvestasiBodong', 'WaspadaInvestasi', 'SkemaPonzi'],
            'subs' => ['Trading / kripto palsu', 'Skema Ponzi / MLM', 'Arisan / koperasi bodong', 'Robot trading', 'Investasi emas / saham palsu']],
        'Lowongan kerja palsu' => ['icon' => 'laptop', 'seeds' => ['LokerPalsu', 'WaspadaLoker', 'RekrutmenPalsu'],
            'subs' => ['Biaya administrasi / pelatihan', 'Kerja paruh waktu like & subscribe', 'Kerja luar negeri palsu', 'Magang palsu']],
        'Impersonasi / penyamaran' => ['icon' => 'masks', 'seeds' => ['Impersonasi', 'AkunPalsu', 'CatutNama'],
            'subs' => ['Catut pejabat / instansi', 'Akun selebritas palsu', 'Customer service palsu']],
        'Lainnya' => ['icon' => 'thumbtack', 'seeds' => ['WaspadaDigital', 'PenipuanOnline'],
            'subs' => ['Romansa / cinta palsu', 'Donasi palsu', 'Jual beli online', 'Modus lain']],
    ];
}
function parse_categories($raw): array
{
    $defs = category_defs();
    $got = [];
    foreach ((array)$raw as $v) {
        if (is_string($v) && isset($defs[$v])) {
            $got[$v] = true;
        }
    }
    return array_values(array_filter(array_keys($defs), fn ($k) => isset($got[$k])));
}
function set_post_categories(int $pid, array $cats): void
{
    if (!categories_ready()) {
        return;
    }
    try {
        q('DELETE FROM post_categories WHERE post_id = ?', [$pid]);
        foreach (array_slice(parse_categories($cats), 0, MAX_CATEGORIES) as $c) {
            q('INSERT IGNORE INTO post_categories (post_id, category) VALUES (?,?)', [$pid, $c]);
        }
    } catch (Throwable $e) {
        error_log('set_post_categories: ' . $e->getMessage());
    }
}
/** @return array<int,string[]> pid => kategori (urut sesuai category_defs) */
function post_category_sets(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !categories_ready()) {
        return [];
    }
    $order = array_flip(array_keys(category_defs()));
    $sets = [];
    try {
        foreach (qall('SELECT post_id, category FROM post_categories WHERE post_id IN (' . implode(',', $ids) . ')') as $r) {
            $sets[(int)$r['post_id']][] = $r['category'];
        }
    } catch (Throwable $e) {
        return [];
    }
    foreach ($sets as $pid => $list) {
        usort($list, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $sets[$pid] = $list;
    }
    return $sets;
}
function category_chip(string $cat, bool $link = true): string
{
    if (!$link) {
        return '<span class="tag tag-red">' . e($cat) . '</span>';
    }
    return '<a class="tag tag-red cat-chip" href="' . e(url('explore.php?cat=' . urlencode($cat))) . '" title="Lihat semua laporan kategori ' . e($cat) . '" style="text-decoration:none">' . e($cat) . '</a>';
}
/** Potongan SQL (alias posts = p): postingan yang punya kategori tertentu (data lama: kolom posts.category). */
function category_filter_sql(string $cat): array
{
    $legacy = 'p.category = :cat2';
    if (!categories_ready()) {
        return [$legacy, ['cat2' => $cat]];
    }
    return ['(EXISTS(SELECT 1 FROM post_categories pc WHERE pc.post_id = p.id AND pc.category = :cat1)
              OR (NOT EXISTS(SELECT 1 FROM post_categories px WHERE px.post_id = p.id) AND ' . $legacy . '))',
            ['cat1' => $cat, 'cat2' => $cat]];
}
function category_picker(string $name, array $selected): string
{
    $h = picker_css() . '<div class="label-pick" role="group" aria-label="Pilih kategori">';
    foreach (category_defs() as $k => $d) {
        $h .= '<label class="label-opt"><input type="checkbox" data-catbox name="' . e($name) . '[]" value="' . e($k) . '"' . (in_array($k, $selected, true) ? ' checked' : '') . '><span class="cat"><i style="font-style:normal" aria-hidden="true">' . icon($d['icon']) . '</i>' . e($k) . '</span></label>';
    }
    return $h . '</div><div class="sub" style="margin-top:6px">Pilih 1–' . MAX_CATEGORIES . ' kategori yang paling sesuai.</div>';
}
/** Rekomendasi tagar bawaan per kategori (dipakai di formulir laporan). */
function category_seed_tags(): array
{
    $o = [];
    foreach (category_defs() as $k => $d) {
        $o[$k] = $d['seeds'];
    } return $o;
}

/* ---------- Sub-kategori (jenis modus) ----------
 * Contoh: kategori "Penipuan WhatsApp / SMS" -> jenis "APK undangan / paket". Opsional, maks. MAX_SUBS per laporan.
 * Tabel post_subcategories (category = induk, sub = nama jenis). Bisa diklik: explore.php?cat=...&sub=...
 */
const MAX_SUBS = 5;
function all_subs(): array
{
    $o = [];
    foreach (category_defs() as $c => $d) {
        foreach ($d['subs'] ?? [] as $sname) {
            $o[$sname] = $c;
        }
    } return $o;
}

/** Input form (daftar nama sub) -> [['category'=>..,'sub'=>..]]; hanya sub yang induknya ikut dipilih. */
function parse_subs($raw, array $selectedCats): array
{
    $map = all_subs();
    $got = [];
    foreach ((array)$raw as $v) {
        if (is_string($v) && isset($map[$v]) && in_array($map[$v], $selectedCats, true)) {
            $got[$v] = true;
        }
    }
    $out = [];
    foreach (category_defs() as $c => $d) {
        foreach ($d['subs'] ?? [] as $sname) {
            if (isset($got[$sname])) {
                $out[] = ['category' => $c, 'sub' => $sname];
            }
        }
    }
    return $out;
}
function set_post_subs(int $pid, array $subs): void
{
    if (!subs_ready()) {
        return;
    }
    try {
        q('DELETE FROM post_subcategories WHERE post_id = ?', [$pid]);
        foreach (array_slice($subs, 0, MAX_SUBS) as $x) {
            q('INSERT IGNORE INTO post_subcategories (post_id, category, sub) VALUES (?,?,?)', [$pid, $x['category'], $x['sub']]);
        }
    } catch (Throwable $e) {
        error_log('set_post_subs: ' . $e->getMessage());
    }
}
/** @return array<int,array<int,array{category:string,sub:string}>> */
function post_sub_sets(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !subs_ready()) {
        return [];
    }
    $pos = array_flip(array_keys(all_subs()));
    $sets = [];
    try {
        foreach (qall('SELECT post_id, category, sub FROM post_subcategories WHERE post_id IN (' . implode(',', $ids) . ')') as $r) {
            $sets[(int)$r['post_id']][] = ['category' => $r['category'], 'sub' => $r['sub']];
        }
    } catch (Throwable $e) {
        return [];
    }
    foreach ($sets as $pid => $list) {
        usort($list, fn ($a, $b) => ($pos[$a['sub']] ?? 999) <=> ($pos[$b['sub']] ?? 999));
        $sets[$pid] = $list;
    }
    return $sets;
}
function sub_chip(string $category, string $sub): string
{
    return '<a class="tag tag-sub sub-chip" href="' . e(url('explore.php?cat=' . urlencode($category) . '&sub=' . urlencode($sub))) . '" title="Lihat laporan jenis ' . e($sub) . ' (' . e($category) . ')" style="text-decoration:none">' . icon('subarrow') . ' ' . e($sub) . '</a>';
}
function sub_filter_sql(string $sub): array
{
    if (!subs_ready()) {
        return ['1 = 0', []];
    }
    return ['EXISTS(SELECT 1 FROM post_subcategories ps WHERE ps.post_id = p.id AND ps.sub = :sub1)', ['sub1' => $sub]];
}
/** Pemilih jenis modus: grup per kategori; hanya grup kategori yang dicentang yang tampil (diatur skrip kecil di bawah). */
function sub_picker(string $name, array $selectedSubs, array $selectedCats): string
{
    $h = picker_css() . '<div class="sub-wrap">';
    foreach (category_defs() as $c => $d) {
        if (empty($d['subs'])) {
            continue;
        }
        $on = in_array($c, $selectedCats, true);
        $h .= '<div class="sub-group" data-parent="' . e($c) . '"' . ($on ? '' : ' hidden') . ' style="margin:2px 0 10px"><div class="sub" style="margin:0 0 6px;font-weight:700">' . icon($d['icon']) . ' Jenis modus: ' . e($c) . '</div><div class="label-pick">';
        foreach ($d['subs'] as $sname) {
            $h .= '<label class="label-opt"><input type="checkbox" name="' . e($name) . '[]" value="' . e($sname) . '"' . (in_array($sname, $selectedSubs, true) ? ' checked' : '') . '><span class="cat">' . e($sname) . '</span></label>';
        }
        $h .= '</div></div>';
    }
    $h .= '<div class="sub">Pilih jenis modusnya agar laporan lebih mudah dicari (maks. ' . MAX_SUBS . ').</div></div>';
    static $js = false;
    if (!$js) { // satu skrip untuk semua pemilih; tiap pemilih hanya membaca kategori di FORMULIR yang sama
        $js = true;
        $h .= '<script>(function(){function sync(w){var f=w.closest("form")||document,on={};f.querySelectorAll("input[data-catbox]").forEach(function(c){if(c.checked)on[c.value]=1});'
            . 'var any=false;w.querySelectorAll(".sub-group").forEach(function(g){var s=!!on[g.dataset.parent];g.hidden=!s;if(s)any=true;else g.querySelectorAll("input").forEach(function(i){i.checked=false})});w.hidden=!any}'
            . 'function all(){document.querySelectorAll(".sub-wrap").forEach(sync)}'
            . 'document.addEventListener("change",function(e){var t=e.target;if(t&&t.matches&&t.matches("input[data-catbox]")){(t.closest("form")||document).querySelectorAll(".sub-wrap").forEach(sync)}});'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",all);else all()})();</script>';
    }
    return $h;
}
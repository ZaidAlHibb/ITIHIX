<?php
$pageTitle = 'Mask of Deception 2P';
$active = 'game';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/game.php';
game_ensure_tables();
$extraJs = '<script src="' . e(url('assets/js/game.js')) . '?v=' . (int)@filemtime(__DIR__ . '/assets/js/game.js') . '"></script>';
?>
<section id="page-game" class="page" data-rounds="<?= GAME_ROUNDS ?>" data-xp="<?= GAME_XP ?>" data-max="<?= GAME_MSG_MAX ?>" data-reply="<?= GAME_REPLY_MAX ?>" data-open-sec="<?= GAME_OPEN_SEC ?>" data-turn-sec="<?= GAME_TURN_SEC ?>" data-guess-sec="<?= GAME_GUESS_SEC ?>">
  <div class="page-heading"><div><h1>Mask of Deception 2P <?= icon('masks') ?></h1><div class="eyebrow">Duel Detektif Siber</div><div class="sub">Ngobrol maksimal 5 chat: satu jadi pengirim (penipu atau bukan), satu jadi penebak. Tebakan benar = kalian berdua menang dan dapat XP.</div></div></div>
  <div id="gmRoot" class="gm-root"><div class="card gm-card"><div class="sub">Memuat permainan…</div></div></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
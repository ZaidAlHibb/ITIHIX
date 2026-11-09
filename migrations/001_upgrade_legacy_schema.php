<?php

/**
 * Menyatukan semua "migrasi otomatis" yang dulu tersebar di kode (bootstrap, game, quiz, chat, dm, comments, labels,
 * posts, detections, settings, profile, admin). Untuk instalasi BARU semuanya sudah ada di database.sql, jadi
 * migrasi ini hanya berefek pada instalasi lama. Idempoten.
 */
return function (PDO $pdo): void {
    // --- users ---
    foreach (['hide_reposts', 'hide_likes', 'hide_comments'] as $c) {
        migration_add_column($pdo, 'users', $c, 'TINYINT(1) NOT NULL DEFAULT 0');
    }
    migration_add_column($pdo, 'users', 'banner_color', 'VARCHAR(7) NULL AFTER avatar_path');
    $rc = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if ($rc && stripos((string)$rc['Type'], 'trusted') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','trusted','moderator','admin') NOT NULL DEFAULT 'user'");
    }
    // --- posts / detections / notifications / comments ---
    migration_add_column($pdo, 'posts', 'scan_text', 'TEXT NULL AFTER body');
    migration_add_column($pdo, 'detections', 'saved', 'TINYINT(1) NOT NULL DEFAULT 1');
    migration_add_column($pdo, 'detections', 'images', 'TEXT NULL AFTER image_path');
    migration_add_column($pdo, 'detections', 'after_steps', 'TEXT NULL AFTER advice');
    if (migration_has_table($pdo, 'notifications')) {
        $pdo->exec("ALTER TABLE notifications MODIFY icon VARCHAR(24) NOT NULL DEFAULT 'star'");
    }
    migration_add_column($pdo, 'comments', 'parent_id', 'INT UNSIGNED NULL AFTER user_id, ADD INDEX (parent_id)');
    // --- quiz ---
    migration_add_column($pdo, 'quiz_questions', 'highlights', 'TEXT NULL');
    migration_add_column($pdo, 'quiz_attempts', 'session_id', 'VARCHAR(16) NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS quiz_assigned (
      user_id INT UNSIGNED NOT NULL, question_id INT UNSIGNED NOT NULL,
      assigned_at DATETIME NOT NULL, answered_at DATETIME NULL,
      PRIMARY KEY (user_id, question_id), INDEX (user_id, answered_at),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
    // --- komentar: suka/tidak suka ---
    $pdo->exec('CREATE TABLE IF NOT EXISTS comment_votes (
      comment_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, vote TINYINT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (comment_id, user_id), INDEX (user_id),
      FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
    // --- laporan pengguna ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_reports (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      reporter_id INT UNSIGNED NOT NULL, reported_id INT UNSIGNED NOT NULL, post_id INT UNSIGNED NULL,
      reason ENUM('penipuan','penyamaran','spam','pelecehan','tidak_pantas','lainnya') NOT NULL,
      detail VARCHAR(500) NOT NULL DEFAULT '',
      status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
      handled_by INT UNSIGNED NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (reported_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL,
      FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
      INDEX (status, created_at), INDEX (reported_id)
    ) ENGINE=InnoDB");
    if (!migration_has_column($pdo, 'user_reports', 'comment_id')) {
        $pdo->exec('ALTER TABLE user_reports ADD COLUMN comment_id INT UNSIGNED NULL AFTER post_id, ADD INDEX (comment_id)');
        $pdo->exec("UPDATE user_reports SET comment_id = CAST(SUBSTRING_INDEX(SUBSTRING(detail, 12), ']', 1) AS UNSIGNED) WHERE comment_id IS NULL AND detail LIKE '[Komentar #%'");
    }
    // --- XP harian, lencana status, label/kategori ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS xp_daily (
      user_id INT UNSIGNED NOT NULL, day DATE NOT NULL, kind ENUM('feature','mission') NOT NULL,
      amount INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (user_id, day, kind),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec('CREATE TABLE IF NOT EXISTS status_badges (
      code VARCHAR(20) NOT NULL PRIMARY KEY, label VARCHAR(60) NOT NULL, sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT IGNORE INTO status_badges (code, label, sort_order) VALUES
      ('admin','OLEH ADMIN',1),('moderator','OLEH MODERATOR',2),('trusted','PELAPOR TERVERIFIKASI',3),('verified','LAPORAN TERVERIFIKASI',4)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_categories (
      post_id INT UNSIGNED NOT NULL, category VARCHAR(60) NOT NULL, PRIMARY KEY (post_id, category), INDEX (category),
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_subcategories (
      post_id INT UNSIGNED NOT NULL, category VARCHAR(60) NOT NULL, sub VARCHAR(60) NOT NULL,
      PRIMARY KEY (post_id, sub), INDEX (sub), INDEX (category),
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_labels (
      post_id INT UNSIGNED NOT NULL, label ENUM('penipuan','mencurigakan','edukasi','klarifikasi') NOT NULL,
      kind ENUM('user','final') NOT NULL, PRIMARY KEY (post_id, kind, label), INDEX (label, kind),
      FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // --- DM ---
    $pdo->exec('CREATE TABLE IF NOT EXISTS direct_messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sender_id INT UNSIGNED NOT NULL, recipient_id INT UNSIGNED NOT NULL,
      body VARCHAR(1000) NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX (recipient_id, is_read), INDEX (sender_id, recipient_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // --- Chat AI ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
      title VARCHAR(120) NOT NULL DEFAULT 'Percakapan baru',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX (user_id, updated_at)
    ) ENGINE=InnoDB");
    if (migration_has_table($pdo, 'chat_messages') && !migration_has_column($pdo, 'chat_messages', 'conversation_id')) {
        $pdo->exec('ALTER TABLE chat_messages ADD COLUMN conversation_id INT UNSIGNED NULL AFTER user_id, ADD INDEX idx_chat_conv (conversation_id, id)');
        try {
            $pdo->exec('ALTER TABLE chat_messages ADD CONSTRAINT fk_chat_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE');
        } catch (Throwable $e) {
            error_log('[ITIHIX migrate] FK chat opsional gagal: ' . $e->getMessage());
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_attachments (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
      conversation_id INT UNSIGNED NULL, message_id INT UNSIGNED NULL,
      original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL,
      kind ENUM('image','pdf','text','docx') NOT NULL, size INT UNSIGNED NOT NULL DEFAULT 0,
      extracted MEDIUMTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
      INDEX (message_id), INDEX (conversation_id)
    ) ENGINE=InnoDB");
    // --- Game Tebak Scam 2P ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_rooms (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(8) NOT NULL,
      host_id INT UNSIGNED NOT NULL, guest_id INT UNSIGNED NULL, first_sender_id INT UNSIGNED NULL,
      status ENUM('waiting','playing','finished') NOT NULL DEFAULT 'waiting',
      round_no TINYINT UNSIGNED NOT NULL DEFAULT 0, total_rounds TINYINT UNSIGNED NOT NULL DEFAULT 4,
      finished_reason VARCHAR(20) NULL, left_by INT UNSIGNED NULL,
      visibility ENUM('public','private') NOT NULL DEFAULT 'public', is_auto TINYINT(1) NOT NULL DEFAULT 0,
      host_seen DATETIME NOT NULL, guest_seen DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
      FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (guest_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX (status, host_seen), INDEX (code), INDEX (host_id, status), INDEX (guest_id, status)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_rounds (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, room_id INT UNSIGNED NOT NULL, round_no TINYINT UNSIGNED NOT NULL,
      sender_id INT UNSIGNED NOT NULL, guesser_id INT UNSIGNED NOT NULL, is_scam TINYINT(1) NOT NULL,
      cat_options VARCHAR(120) NULL, category VARCHAR(30) NULL, message TEXT NULL,
      guess ENUM('scam','safe') NULL, reason VARCHAR(300) NULL, correct TINYINT(1) NULL,
      timeout_kind VARCHAR(10) NULL, done_at DATETIME NULL,
      ready_host TINYINT(1) NOT NULL DEFAULT 0, ready_guest TINYINT(1) NOT NULL DEFAULT 0, ready_at DATETIME NULL,
      xp_sender SMALLINT UNSIGNED NOT NULL DEFAULT 0, xp_guesser SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      status ENUM('writing','guessing','done') NOT NULL DEFAULT 'writing',
      created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
      UNIQUE KEY uq_room_round (room_id, round_no),
      FOREIGN KEY (room_id) REFERENCES game_rooms(id) ON DELETE CASCADE, INDEX (updated_at)
    ) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE IF NOT EXISTS game_messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, round_id INT UNSIGNED NOT NULL, author_id INT UNSIGNED NOT NULL,
      seq TINYINT UNSIGNED NOT NULL, body TEXT NOT NULL, is_skip TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
      UNIQUE KEY uq_round_seq (round_id, seq),
      FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
    migration_add_column($pdo, 'game_rooms', 'visibility', "ENUM('public','private') NOT NULL DEFAULT 'public'");
    migration_add_column($pdo, 'game_rooms', 'is_auto', 'TINYINT(1) NOT NULL DEFAULT 0');
    migration_add_column($pdo, 'game_rounds', 'timeout_kind', 'VARCHAR(10) NULL');
    migration_add_column($pdo, 'game_rounds', 'done_at', 'DATETIME NULL');
    migration_add_column($pdo, 'game_rounds', 'ready_host', 'TINYINT(1) NOT NULL DEFAULT 0');
    migration_add_column($pdo, 'game_rounds', 'ready_guest', 'TINYINT(1) NOT NULL DEFAULT 0');
    migration_add_column($pdo, 'game_rounds', 'ready_at', 'DATETIME NULL');
    migration_add_column($pdo, 'game_messages', 'is_skip', 'TINYINT(1) NOT NULL DEFAULT 0');
};

<?php

/** Tabel pembatas laju berbasis database (menggantikan penghitung di $_SESSION). */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      bucket VARCHAR(40) NOT NULL,
      subject VARCHAR(64) NOT NULL,
      hit_at INT UNSIGNED NOT NULL,
      INDEX idx_rl (bucket, subject, hit_at),
      INDEX idx_rl_time (hit_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
};

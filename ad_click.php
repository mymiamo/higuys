<?php
// ad_click.php

// 1) Veritabanı bağlantısı
require_once 'config.php';  // burada $pdo tanımlı olmalı

// 2) Ad ID’si alın
if (!isset($_GET['ad'])) {
    http_response_code(400);
    exit('Eksik parametre: ad');
}
$adId = (int)$_GET['ad'];

// 3) Reklamı DB’den çek (hem target_url’i almak, hem de var mı diye kontrol etmek için)
$stmt = $pdo->prepare("SELECT target_url FROM ads WHERE id = ? AND is_active = 1");
$stmt->execute([$adId]);
$ad = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ad) {
    http_response_code(404);
    exit('Reklam bulunamadı');
}

// 4) Tıklama sayısını arttır
$stmt = $pdo->prepare("UPDATE ads SET clicks = clicks + 1 WHERE id = ?");
$stmt->execute([$adId]);

// 5) Yönlendir
header('Location: ' . $ad['target_url']);
exit;

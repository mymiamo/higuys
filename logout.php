<?php
// logout.php

// 1) Oturumu başlat ve tamamen temizle
session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();

// 2) "Beni Hatırla" çerezini sil ve DB kaydını temizle
if (isset($_COOKIE['remember_me'])) {
    // Config sadece bu bloğun içinde yükleniyor, 
    // böylece config.php'deki otomatik-login kodu  
    // logout sırasında devreye girmez.
    require_once 'config.php';  

    $token      = $_COOKIE['remember_me'];
    $tokenHash  = hash('sha256', $token);

    // persistent_logins tablosundan sil
    $stmt = $pdo->prepare("DELETE FROM persistent_logins WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);

    // Çerezi de sil
    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
}

// 3) Giriş sayfasına veya ana sayfaya yönlendir
header('Location: /');
exit;

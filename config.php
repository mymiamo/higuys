<?php
// config.php
$host = '';
$db = '';
$user = '';
$pass = '';
$charset = '';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    exit('DB Bağlantı Hatası: ' . $e->getMessage());
}

// Şifreleme ayarları
// Şifreleme ayarları
define('ENCRYPT_METHOD', 'AES-256-CBC');

// 32 baytlık AES-256 KEY (base64 encoded)
define('ENCRYPT_KEY', base64_decode(''));

// 16 baytlık AES-CBC IV (base64 encoded)
define('ENCRYPT_IV', base64_decode(''));

$genderLabels = [
    'men' => 'Erkek',
    'women' => 'Kadın',
    'other' => 'Diğer',
    'prefer_not_to_say' => 'Söylemeyi Tercih Etmiyor',
    '' => 'Belirtilmemiş'
];


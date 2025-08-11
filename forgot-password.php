<?php
session_start();
date_default_timezone_set('Europe/Istanbul');

require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'config.php';  // $pdo, ENCRYPT_METHOD, ENCRYPT_KEY, ENCRYPT_IV

$errors = [];
$success = '';
$stage = 1;

// Aşama 2’ye GET token ile gelinmişse
if (isset($_GET['token'])) {
  $stage = 2;
  $token = $_GET['token'];

  // token’ı doğrula (>= NOW())
  $stmt = $pdo->prepare("
      SELECT pr.user_id, u.username, u.email_encrypted
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
       WHERE pr.token = :token
         AND pr.expires_at >= NOW()
         AND pr.used = 0
    ");
  $stmt->execute([':token' => $token]);
  $reset = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$reset) {
    $errors[] = 'Geçersiz veya süresi dolmuş sıfırlama bağlantısı.';
    $stage = 0;
  }
}

// Aşama 1 isteği
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
  $input = trim($_POST['username-or-mail'] ?? '');
  if ($input === '') {
    $errors[] = 'Kullanıcı adınızı veya e-postanızı girin.';
  }
  if (empty($errors)) {
    // e-posta ise encrypt et
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
    $email_enc = $isEmail
      ? openssl_encrypt($input, ENCRYPT_METHOD, base64_decode(ENCRYPT_KEY), 0, base64_decode(ENCRYPT_IV))
      : null;

    // kullanıcıyı bul
    $stmt = $pdo->prepare("
          SELECT id, username, email_encrypted
            FROM users
           WHERE username = :in
              OR email_encrypted = :email
        ");
    $stmt->execute([':in' => $input, ':email' => $email_enc]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // her halükârda bilgi sızdırmamak için success mesajı
    $success = 'Eğer kayıtlı e-posta/adresinize ait bir hesap varsa, 10 dakikalık sıfırlama bağlantısı gönderildi.';
    if ($user) {
      // eski token’ları iptal et
      $pdo->prepare("UPDATE password_resets SET used=1 WHERE user_id=:uid")
        ->execute([':uid' => $user['id']]);

      // yeni token
      $token = bin2hex(random_bytes(16));
      $expires = date('Y-m-d H:i:s', time() + 600);
      $pdo->prepare("
              INSERT INTO password_resets (user_id, token, expires_at)
              VALUES (:uid, :token, :exp)
            ")->execute([
            ':uid' => $user['id'],
            ':token' => $token,
            ':exp' => $expires
          ]);

      // gerçek e-posta
      $realEmail = openssl_decrypt(
        $user['email_encrypted'],
        ENCRYPT_METHOD,
        ENCRYPT_KEY, // tekrar base64_decode ETME
        0,
        ENCRYPT_IV   // tekrar base64_decode ETME
      );


      $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
        . "://{$_SERVER['HTTP_HOST']}/forgot-password/{$token}";

      try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.turkticaret.net';
        $mail->SMTPAuth = true;
        $mail->Username = 'no-reply@higuys.app';
        $mail->Password = '';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';

        // başlıklar ve güvenlik ayarları
        $mail->setFrom('no-reply@higuys.app', 'HG! Hesap Kurtarma');
        $mail->addReplyTo('no-reply@higuys.app', 'Hi Guys!');
        $mail->addAddress($realEmail, $user['username']);
        $mail->Subject = 'Hi Guys! Şifre Sıfırlama Bağlantısı';
        $mail->isHTML(true);
        $mail->Body = "<p>Merhaba <strong>{$user['username']}</strong>,</p>
                                  <p>Şifrenizi sıfırlamak için lütfen
                                  <a href=\"{$link}\">bu bağlantıya</a> tıklayın.</p>
                                  <p>Bağlantı 10 dakika boyunca geçerlidir.</p>";
        $mail->AltBody = "Merhaba {$user['username']},\n\n"
          . "Şifrenizi sıfırlamak için şu bağlantıyı ziyaret edin:\n{$link}\n\n"
          . "Bağlantı 10 dakika boyunca geçerlidir.";
        $mail->Priority = 1;
        $mail->XMailer = ' ';
        $mail->addCustomHeader('X-Mailer: PHP/' . phpversion());

        $mail->send();
      } catch (Exception $e) {
        // hata görmezden geliniyor
      }
    }
    $stage = 1;
  }
}

// Aşama 2 şifre kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
  $token = $_POST['token'] ?? '';
  $password = $_POST['password'] ?? '';
  $password_verify = $_POST['password-verify'] ?? '';

  // token doğrula (>= NOW())
  $stmt = $pdo->prepare("
      SELECT pr.user_id
        FROM password_resets pr
       WHERE pr.token = :token
         AND pr.expires_at >= NOW()
         AND pr.used = 0
    ");
  $stmt->execute([':token' => $token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $errors[] = 'Geçersiz veya süresi dolmuş bağlantı.';
  }

  // şifre validasyonu
  if (
    strlen($password) < 8
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^a-zA-Z0-9]/', $password)
  ) {
    $errors[] = 'Şifreniz en az 8 karakter ve içinde büyük/küçük harf, rakam ve özel karakter içermelidir.';
  }
  if ($password !== $password_verify) {
    $errors[] = 'Şifreler uyuşmuyor.';
  }

  if (empty($errors)) {
    // Kullanıcının mevcut hash’ini çek
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :uid");
    $stmt->execute([':uid' => $row['user_id']]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current && password_verify($password, $current['password_hash'])) {
      $errors[] = 'Yeni şifreniz mevcut şifrenizle aynı olamaz.';
    }
  }
  if (empty($errors)) {
    // şifre güncelle
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash=:h WHERE id=:uid")
      ->execute([':h' => $hash, ':uid' => $row['user_id']]);
    // token kullanıldı
    $pdo->prepare("UPDATE password_resets SET used=1 WHERE token=:token")
      ->execute([':token' => $token]);

    $success = 'Şifreniz başarıyla sıfırlandı. Artık giriş yapabilirsiniz.';
    $stage = 0;
  } else {
    $stage = 2;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hi Guys! Hesap Kurtarma</title>
  <link rel="stylesheet" href="/assets/css/sign.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css">

</head>

<body>

  <?php if ($stage === 1): ?>
    <form method="POST">
      <input type="hidden" name="step" value="1">
      <h1>Hesap Kurtarma</h1>
      <div class="input-group">
        <label>Kullanıcı Adınız veya E-Posta</label>
        <input type="text" name="username-or-mail" value="<?= htmlspecialchars($_POST['username-or-mail'] ?? '') ?>">
      </div>
      <button type="submit">Sıfırlama Bağlantısı Al</button>
    </form>

  <?php elseif ($stage === 2): ?>
    <form method="POST">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <h1>Hesap Kurtarma</h1>
      <div class="input-group">
        <label>Şifre</label>
        <span class="toggle" data-target="password"><i class="fa-regular fa-eye"></i></span>
        <input type="password" id="password" name="password">
      </div>
      <div class="input-group">
        <label>Şifreleri Doğrulayın</label>
        <span class="toggle" data-target="password-verify"><i class="fa-regular fa-eye"></i></span>
        <input type="password" id="password-verify" name="password-verify">
      </div>
      <button type="submit">Hesabı Kurtar</button>
    </form>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="msg">
      <div class="error"><i class="fa-solid fa-triangle-exclamation"></i>
        <?= implode('<br>', $errors) ?>
      </div>
    </div>
  <?php elseif ($success): ?>
    <div class="msg">
      <div class="succuss"><i class="fa-solid fa-circle-check"></i>
        <?= $success ?>
      </div>
    </div>
  <?php endif; ?>
  <script src="/assets/js/sign.js"></script>
</body>

</html>
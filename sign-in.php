<?php
session_start();
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'config.php';

// Oturum yoksa ve "remember me" çerezi varsa kontrol et
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
    $token = $_COOKIE['remember_me'];
    $token_hash = hash('sha256', $token);



    $stmt = $pdo->prepare("SELECT user_id FROM persistent_logins WHERE token_hash = ? AND expires_at > NOW()");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch();

    if ($row) {
        $_SESSION['user_id'] = $row['user_id'];
    }
}

// Eğer session’da giriş bilgisi varsa, direkt app.php’ye yönlendir
if (isset($_SESSION['user_id'])) {
    header('Location: app.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['username-or-mail'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);

    if ($input === '' || $password === '') {
        $errors[] = 'Kullanıcı adı/e-posta ve şifre girmeniz zorunludur.';
    }

    if (empty($errors)) {
        // Kullanıcıyı çek
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $email_enc = openssl_encrypt(
                $input,
                ENCRYPT_METHOD,
                base64_decode(ENCRYPT_KEY),
                0,
                base64_decode(ENCRYPT_IV)
            );
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email_encrypted = :email');
            $stmt->execute(['email' => $email_enc]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
            $stmt->execute(['username' => $input]);
        }
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Kullanıcı adı/e-posta veya şifre hatalı.';
        } else {
            // Başarılı giriş
            $_SESSION['user_id'] = $user['id'];

            // Unutma işaretlendiyse
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);
                $expires_at = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';

                $ins = $pdo->prepare(
                    'INSERT INTO persistent_logins (user_id, token_hash, user_agent, ip_address, expires_at) VALUES (:uid, :th, :ua, :ip, :exp)'
                );
                $ins->execute([
                    'uid' => $user['id'],
                    'th' => $token_hash,
                    'ua' => $user_agent,
                    'ip' => $ip,
                    'exp' => $expires_at
                ]);

                setcookie(
                    'remember_me',
                    $token,
                    time() + 30 * 24 * 3600,
                    '/',
                    '',
                    true,
                    true
                );
            }

            // Bildirim maili
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $geo_json = @file_get_contents("http://ip-api.com/json/{$ip}");
            $geo = $geo_json ? json_decode($geo_json) : null;
            $location = ($geo && $geo->status === 'success')
                ? "{$geo->country}, {$geo->regionName}, {$geo->city}" : 'Bilinmiyor';

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

                $mail->setFrom('no-reply@higuys.app', 'Hi Guys! Güvenlik Bildirimi');
                $decryptedEmail = openssl_decrypt(
                    $user['email_encrypted'],
                    ENCRYPT_METHOD,
                    base64_decode(ENCRYPT_KEY),
                    0,
                    base64_decode(ENCRYPT_IV)
                );
                $mail->addAddress($decryptedEmail, $user['username']);

                $mail->isHTML(true);
                $mail->Subject = 'Yeni Giriş Bildirimi';
                $mail->Body = "<p>Merhaba {$user['username']},</p>"
                    . "<p>Hesabınıza yeni bir cihazdan giriş yapıldı:</p>"
                    . "<ul>"
                    . "<li>Cihaz/Tarayıcı: {$ua}</li>"
                    . "<li>IP Adresi: {$ip}</li>"
                    . "<li>Konum: {$location}</li>"
                    . "<li>Zaman: " . date('Y-m-d H:i:s') . "</li>"
                    . "</ul>"
                    . "<p>Bu siz değilseniz lütfen şifrenizi değiştirin.</p>";

                $mail->send();
            } catch (Exception $e) {
                // Mail gönderme hatası loglanabilir
            }

            // Başarılı giriş
            $_SESSION['user_id'] = $user['id'];

            // …remember-me, mail bildirimi vs…

            // Redirect parametresini oku, yoksa default app.php
            $dest = '/app.php';
            if (!empty($_POST['redirect']) && strpos($_POST['redirect'], '/') === 0) {
                $dest = $_POST['redirect'];
            }

            header('Location: ' . $dest);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi Guys! Üyeliği Oluştur ve Oyun Arkadaşını Bul</title>
    <link rel="stylesheet" href="/assets/css/sign.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css">

</head>

<body>

    <form action="" method="POST">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
        <h1>Giriş Yap ve Oyun Arkadaşını Bul!</h1>
        <p>Hi Guys! ile bir ömürlük arkadaşlıklar edinin.</p>

        <div class="input-group">
            <label>Kullanıcı Adınız veya E-Posta</label>
            <input type="text" name="username-or-mail">
        </div>
        <div class="input-group">
            <label>Şifre</label>
            <span class="toggle"><i class="fa-regular fa-eye"></i></span>
            <input type="password" name="password" id="password">
        </div>
        <div class="checkbox-area">
            <label><input type="checkbox" name="remember_me" id="password"> Beni Unutma (30 gün boyunca)</label>
        </div>
        <button>Giriş Yap</button>
        <div class="recovery">
            <a href="sign-up">Hesabım yok.</a>
            <a href="forgot-password">Giriş yapamıyorum.</a>
        </div>
    </form>



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
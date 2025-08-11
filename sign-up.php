<?php
session_start();
$gender = $_POST['cinsiyet'] ?? ($_SESSION['formdata']['cinsiyet'] ?? '');

// Eğer session’da giriş bilgisi varsa, direkt app.php’ye yönlendir
if (isset($_SESSION['user_id'])) {
    header('Location: app.php');
    exit;
}

require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'config.php'; // $pdo ve ENCRYPT_KEY, ENCRYPT_IV

$stage = isset($_POST['step']) ? (int) $_POST['step'] : 1;
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($stage === 1) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_verify = $_POST['password-verify'] ?? '';
        $birthday = $_POST['birthday'] ?? '';

        if ($username === '')
            $errors[] = 'Kullanıcı adınızı girin.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'Geçerli bir e‑posta adresi girin.';
        if (
            strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)
        ) {
            $errors[] = 'Şifreniz en az 8 karakter ve içinde büyük/küçük harf, rakam ve özel karakter içermelidir.';
        }
        if ($password !== $password_verify)
            $errors[] = 'Şifreler uyuşmuyor.';
        if ($birthday === '' || (strtotime($birthday) > strtotime('-13 years'))) {
            $errors[] = '13 yaşından büyük olmalısınız.';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Bu kullanıcı adı zaten alınmış.';
        }

        // E-posta veritabanında var mı kontrol et (şifrelenmiş haliyle)
        $email_encrypted = openssl_encrypt(
            $email,
            ENCRYPT_METHOD,
            ENCRYPT_KEY,
            0,
            ENCRYPT_IV
        );
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email_encrypted = ?");
        $stmt->execute([$email_encrypted]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Bu e‑posta adresi zaten kayıtlı.';
        }

        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $email_encrypted = openssl_encrypt(
                $email,
                ENCRYPT_METHOD,
                ENCRYPT_KEY, // tekrar base64_decode ETME
                0,
                ENCRYPT_IV   // tekrar base64_decode ETME
            );
            $verification_code = random_int(100000, 999999);
            $_SESSION['signup'] = [
                'username' => $username,
                'email_enc' => $email_encrypted,
                'pass_hash' => $password_hash,
                'birthday' => $birthday,
                'code' => $verification_code,
            ];

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

                $mail->setFrom('no-reply@higuys.app', 'Hi Guys!');
                $mail->addAddress($email, $username);
                $mail->isHTML(true);
                $mail->Subject = 'Üyelik Doğrulama Kodu';
                $mail->Body = "<p>Merhaba <strong>{$username}</strong>,</p>
                               <p>Doğrulama kodunuz: <strong>{$verification_code}</strong></p>
                               <p>Bu kodu 10 dakika içinde girmeniz gerekmektedir.</p>";

                $mail->send();
                $success = 'Doğrulama kodu e‑postanıza gönderildi. Lütfen aşağıya girin.';
                $stage = 2;
            } catch (Exception $e) {
                $errors[] = 'E‑posta gönderilemedi: ' . $mail->ErrorInfo;
            }
        }
    } elseif ($stage === 2) {
        $_SESSION['formdata'] = array_merge($_SESSION['formdata'] ?? [], $_POST);
        $gender = $_SESSION['formdata']['cinsiyet'] ?? '';

        $verify = trim($_POST['verify'] ?? '');
        $about = trim($_POST['about'] ?? '');
        $dc_username = trim($_POST['dc-username'] ?? '');
        $steam_profile = trim($_POST['steam-profile'] ?? '');
        $other_link = trim($_POST['other-link'] ?? '');
        $gender = $_POST['cinsiyet'] ?? ($_SESSION['formdata']['cinsiyet'] ?? '');
        $gender_agree = isset($_POST['topluluk']);
        $privacy_agree = isset($_POST['gizlilik']);



        if ($verify === '' || !isset($_SESSION['signup']['code']) || $verify !== (string) $_SESSION['signup']['code']) {
            $errors[] = 'Doğrulama kodu yanlış veya eksik.';
        }
        if ($about === '')
            $errors[] = 'Hakkında kısmı boş bırakılamaz.';
        if ($gender === '')
            $errors[] = 'Cinsiyet seçimi yapılmalıdır.'; // EKLENDİ
        if ($dc_username === '' && $steam_profile === '' && $other_link === '') {
            $errors[] = 'En az bir iletişim alanını doldurun (Discord, Steam veya başka oyun linki).';
        }
        if (
            $steam_profile !== '' &&
            !preg_match('#^https://steamcommunity\.com/(id|profiles)/[a-zA-Z0-9_-]+/?$#', $steam_profile)
        ) {
            $errors[] = 'Steam profili "https://steamcommunity.com/id/..." veya "https://steamcommunity.com/profiles/..." formatında olmalıdır.';
        }

        if (!$gender_agree || !$privacy_agree) {
            $errors[] = 'Topluluk kuralları ve gizlilik politikasını kabul etmelisiniz.';
        }
        if (!isset($_FILES['profile-photo']) || $_FILES['profile-photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Profil fotoğrafı yüklenemedi veya seçilmedi.';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['profile-photo']['tmp_name']);
        finfo_close($finfo);

        $allowed_types = ['image/jpeg', 'image/png'];

        if (!in_array($mime_type, $allowed_types)) {
            $errors[] = 'Sadece JPG ve PNG dosyalarına izin verilir.';
        }
        $max_size = 2 * 1024 * 1024;

        if ($_FILES['profile-photo']['size'] > $max_size) {
            $errors[] = 'Dosya boyutu 2MB’yi geçemez.';
        }

        if (empty($errors)) {
            $upload_dir = __DIR__ . '/';
            $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_SESSION['signup']['username']);
            $user_folder = $upload_dir . $username . '/';

            if (!is_dir($user_folder)) {
                mkdir($user_folder, 0777, true);
            }

            $filename = uniqid('profile_', true) . '.' . pathinfo($_FILES['profile-photo']['name'], PATHINFO_EXTENSION);
            $destination = 'uploads/' . $user_folder . $filename;

            if (move_uploaded_file($_FILES['profile-photo']['tmp_name'], $destination)) {

                // ✅ Cinsiyet session'a kaydediliyor
                $_SESSION['signup']['gender'] = $gender;

                try {
                    $stmt = $pdo->prepare("
                    INSERT INTO users (
                        username, email_encrypted, password_hash, birthday,
                        about, dc_username, steam_profile, other_link,
                        profile_photo_path, agreed_terms, agreed_privacy, gender
                    )
                    VALUES (
                        :username, :email, :password, :birthday,
                        :about, :dc, :steam, :other,
                        :photo, :terms, :privacy, :gender
                    )
                ");
                    $stmt->execute([
                        ':username' => $username,
                        ':email' => $_SESSION['signup']['email_enc'],
                        ':password' => $_SESSION['signup']['pass_hash'],
                        ':birthday' => $_SESSION['signup']['birthday'],
                        ':about' => $about,
                        ':dc' => $dc_username,
                        ':steam' => $steam_profile,
                        ':other' => $other_link,
                        ':photo' => "$username/$filename",
                        ':terms' => $gender_agree ? 1 : 0,
                        ':privacy' => $privacy_agree ? 1 : 0,
                        ':gender' => $_SESSION['signup']['gender']
                    ]);

                    $success = 'Kayıt başarılı!';
                    unset($_SESSION['signup'], $_SESSION['formdata']);

                    header("Refresh: 3; url=sign-in.php");
                    exit;
                } catch (PDOException $e) {
                    $errors[] = 'Veritabanı hatası: ' . $e->getMessage();
                }
            } else {
                $errors[] = 'Dosya yüklenemedi.';
            }
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
    <?php if ($stage === 1): ?>
        <form action="" method="POST">
            <input type="hidden" name="step" value="1">
            <h1>Üyelik Oluştur</h1>
            <p> Hi Guys! kullanmak için en az <strong>13</strong> yaşında olmalısınız.</p>

            <div class="input-group">
                <label>Kullanıcı Adınız</label>
                <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>E-Posta</label>
                <input type="text" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>Şifre</label>
                <span class="toggle"><i class="fa-regular fa-eye"></i></span>
                <input type="password" name="password" id="password">
            </div>

            <div class="input-group">
                <label>Şifreleri Doğrulayın</label>
                <span class="toggle"><i class="fa-regular fa-eye"></i></span>
                <input type="password" name="password-verify" id="password">
            </div>

            <div class="input-group">
                <label>Cinsiyetiniz</label>
                <select name="cinsiyet" required>
                    <option value="" <?= $gender === '' ? 'selected' : '' ?>>Cinsiyetinizi Seçiniz</option>
                    <option value="women" <?= $gender === 'women' ? 'selected' : '' ?>>Kadın</option>
                    <option value="men" <?= $gender === 'men' ? 'selected' : '' ?>>Erkek</option>
                    <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Diğer...</option>
                    <option value="prefer_not_to_say" <?= $gender === 'prefer_not_to_say' ? 'selected' : '' ?>>Şöylemeyi Tercih
                        Etmiyorum.</option>
                </select>
                <p>Bu seçenek arkadaş aramanıza yardımcı olacak. 🐻</p>
            </div>


            <div class="input-group">
                <label>Doğum Tarihiniz</label>
                <input type="date" name="birthday" value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>">
                <p>13 yaşından büyük olmalısın.</p>
            </div>

            <button type="submit">Devam Ettir</button>
        </form>
    <?php endif; ?>
    <?php if ($stage === 2): ?>
        <form action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="cinsiyet" value="<?= htmlspecialchars($gender) ?>">

            <h1>Üyelik Bilgilerinizi Girin</h1>

            <div class="input-group">
                <label>Doğrulama Kodunu Girin</label>
                <input type="text" name="verify" value="<?= htmlspecialchars($_POST['verify'] ?? '') ?>" required>
            </div>

            <div class="input-group">
                <label>Hakkında</label>
                <textarea maxlength="500"
                    name="about"><?= htmlspecialchars($_SESSION['formdata']['about'] ?? '') ?></textarea>
            </div>

            <div class="input-group">
                <label>Discord Kullanıcı Adı</label>
                <input name="dc-username" value="<?= htmlspecialchars($_SESSION['formdata']['dc-username'] ?? '') ?>">

            </div>

            <div class="input-group">
                <label>Steam Profili</label>
                <input type="text" name="steam-profile" placeholder="https://steamcommunity.com/.../..."
                    value="<?= htmlspecialchars($_SESSION['formdata']['steam-profile'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>Oyun Profil Linki</label>
                <input type="text" name="other-link"
                    value="<?= htmlspecialchars($_SESSION['formdata']['other-link'] ?? '') ?>">
            </div>

            <div class="input-group">
                <label>Profil Fotoğrafı</label>
                <input type="file" name="profile-photo">
            </div>

            <div class="checkbox-area">
                <label><input type="checkbox" name="topluluk" <?= isset($_SESSION['formdata']['topluluk']) ? 'checked' : '' ?>> Topluluk kurallarını kabul ediyorum.</label>
            </div>

            <div class="checkbox-area">
                <label><input type="checkbox" name="gizlilik" <?= isset($_SESSION['formdata']['gizlilik']) ? 'checked' : '' ?>> Gizlilik politikasını kabul ediyorum.</label>
            </div>

            <button type="submit">Üye Ol</button>
            <div class="recovery">
                <a href="/sign-in">Hesabım var.</a>
                <a href="/forgot-password">Giriş yapamıyorum.</a>
            </div>
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
<?php
// profile.php
session_start();
require_once 'config.php';

// Oturum kontrolü
if (empty($_SESSION['user_id'])) {
    $cameFrom = $_SERVER['REQUEST_URI'];
    header('Location: sign-in?redirect=' . urlencode($cameFrom));
    exit;
}

$userId = $_SESSION['user_id'];

// POST isteği
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Hangi alan? (text/gender vs. fotoğraf)
    $field = $_POST['field'] ?? null;

    // ————————————————————————————————————
    // 1) Profil fotoğrafı yükleme
    // ————————————————————————————————————
    if ($field === 'profile-photo') {
        $errors = [];

        // Dosya var mı, upload hatası yok mu?
        if (!isset($_FILES['profile-photo']) || $_FILES['profile-photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Profil fotoğrafı yüklenemedi veya seçilmedi.';
        } else {
            $file = $_FILES['profile-photo'];
            $type = $file['type'];
            $size = $file['size'];
            $tmp_name = $file['tmp_name'];

            // İzin verilen tipler ve boyut limiti
            $allowed_types = ['image/jpeg', 'image/png'];
            $max_size = 2 * 1024 * 1024; // 2 MB

            if (!in_array($type, $allowed_types, true)) {
                $errors[] = 'Sadece JPG ve PNG dosyalarına izin verilir.';
            }
            if ($size > $max_size) {
                $errors[] = 'Dosya boyutu 2MB’yi geçemez.';
            }
        }

        if (empty($errors)) {
            // Kullanıcı adını al
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $username_raw = $stmt->fetchColumn();
            $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username_raw);

            // Klasör yolları
            $upload_dir = __DIR__ . '/uploads/';
            $user_folder = $upload_dir . $username . '/';

            if (!is_dir($user_folder)) {
                mkdir($user_folder, 0755, true);
            }

            // Dosya işlemleri
            $file = $_FILES['profile-photo'];
            $tmp_name = $file['tmp_name'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('profile_', true) . '.' . $ext;
            $destination = $user_folder . $new_filename;

            if (move_uploaded_file($tmp_name, $destination)) {
                // Göreceli yol
                $relativePath = 'uploads/' . $username . '/' . $new_filename;

                // Veritabanını güncelle
                $stmt = $pdo->prepare("
            UPDATE users
            SET profile_photo_path = :path,
                updated_at = NOW()
            WHERE id = :id
        ");
                $stmt->execute([
                    ':path' => $relativePath,
                    ':id' => $userId
                ]);

                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Profil fotoğrafınız başarıyla güncellendi.'
                ];
            } else {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Dosya sunucuya taşınırken bir hata oluştu.'
                ];
            }
        } else {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => implode('<br>', $errors)
            ];
        }

        header('Location: profile.php');
        exit;
    }

    // ————————————————————————————————————
    // 2) Diğer metin/gender güncellemeleri
    // ————————————————————————————————————
    $allowed = ['username', 'about', 'gender'];
    if (in_array($field, $allowed, true)) {
        $value = trim($_POST[$field] ?? '');
        $sql = "UPDATE users
                    SET `$field` = :val,
                        updated_at = NOW()
                  WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['val' => $value, 'id' => $userId]);
        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => ucfirst($field) . ' bilgisi güncellendi.'
        ];
    }

    header('Location: profile.php');
    exit;
}


// Kullanıcı bilgisini çek

$stmt = $pdo->prepare('SELECT username, about, gender AS gender, birthday, profile_photo_path FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

$genderKey = $user['gender'] ?? '';
$genderText = $genderLabels[$genderKey] ?? $genderKey;

// Yaş hesaplama
$age = date_diff(date_create($user['birthday']), date_create('today'))->y;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi Guys! Profiliniz</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css">
</head>

<body>
    <div class="topbar">
        <div class="left">
            <a href="javascript:history.back()" style="text-decoration: none; color:#fff;">
                <i class="fa-solid fa-angle-left"></i>
            </a>
            <img class="circle" src="/<?= htmlspecialchars($user['profile_photo_path']) ?>"
                alt="Profil Fotoğrafı" />
            <div class="username">
                <?= htmlspecialchars($user['username']) ?>
            </div>
        </div>
        <div class="buttons">
            <button class="home-btn" style="background-color:#000;" onclick="window.location.href='/app';">Ana Sayfa</button>
            <button class="match-btn" onclick="window.location.href='/matches';">Eşleşmeler</button>
            <button class="request-btn" onclick="window.location.href='/match-requests';">Eşleşme İstekleri</button>
            <div class="settings-area">
                <button class="settings-btn" id="settings">
                    <span class="user">
                        <img class="circle" src="/<?= htmlspecialchars($user['profile_photo_path']) ?>"
                            alt="Profil Fotoğrafı" />
                        <span class="user-info">
                            <span class="username"><?= htmlspecialchars($user['username']) ?></span>
                            <span class="subtext">Ayarlar</span>
                        </span>
                    </span>
                    <span class="icon"><i class="fa-solid fa-angle-down"></i></span>
                </button>

                <div class="settings-menu">
                    <span>Ayarlar</span>

                    <a href="/profile">Profil</a>
                    <a href="/games-info">Oyun Tercihleri</a>
                    <span>Çıkış</span>
                    <a href="/logout" class="logout">Çıkış Yap</a>
                </div>
            </div>
        </div>
    </div>

    <div class="main">
        <div class="card">
            <div class="report">
                <!-- Buraya rapor/uyarı mesajları eklenebilir -->
            </div>
            <div class="profile-pic">
                <img src="/<?= htmlspecialchars($user['profile_photo_path']) ?>" alt="Profil Fotoğrafı" />
                <span class="edit" data-edit="profile-form">Değiştir</span>
                <form action="" method="POST" enctype="multipart/form-data" class="edit-input" id="profile-form">
                    <input type="hidden" name="field" value="profile-photo">
                    <input type="file" name="profile-photo" required>
                    <button type="submit">Kaydet</button>
                </form>

            </div>
            <div class="username">
                <?= htmlspecialchars($user['username']) ?>
                <span class="edit" data-edit="username-form">Değiştir</span>
                <form action="" method="POST" class="edit-input" id="username-form">
                    <input type="hidden" name="field" value="username">
                    <input type="text" name="username" required value="<?= htmlspecialchars($user['username']) ?>">
                    <button>Kaydet</button>
                </form>
            </div>

            <div class="about">
                <strong>Hakkında</strong><br>
                <?= nl2br(htmlspecialchars($user['about'])) ?>
                <span class="edit" data-edit="about-form">Değiştir</span>
                <form action="" method="POST" class="edit-input" id="about-form">
                    <input type="hidden" name="field" value="about">
                    <textarea name="about" maxlength="500"><?= htmlspecialchars($user['about']) ?></textarea>
                    <button>Kaydet</button>
                </form>
                <br><br>
                <strong>Yaş :</strong>
                <?= $age ?><br>
                <strong>Cinsiyet :</strong>
                <?= htmlspecialchars($genderLabels[$user['gender']] ?? $user['gender']) ?>
                <span class="edit" data-edit="gender-form">Değiştir</span>
                <form action="" method="POST" class="edit-input" id="gender-form">
                    <input type="hidden" name="field" value="gender">
                    <select name="gender">
                        <option value="">Seçiniz</option>
                        <option value="women" <?= $user['gender'] === 'women' ? 'selected' : '' ?>>Kadın</option>
                        <option value="men" <?= $user['gender'] === 'men' ? 'selected' : '' ?>>Erkek</option>
                        <option value="other" <?= $user['gender'] === 'other' ? 'selected' : '' ?>>Diğer</option>
                        <option value="prefer_not_to_say" <?= $user['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>
                            Tercih Etmiyorum</option>
                    </select>
                    <button>Kaydet</button>
                </form>
                <br>

            </div>
            <div class="buttons">
                <a href="/games-info" class="send-btn">Oyun Bilgilerini Ekle</a>
                <a href="/forgot-password" class="skip-btn">Şifremi değiştirmek istiyorum</a>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.edit').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = this.getAttribute('data-edit');
                const targetForm = document.getElementById(targetId);

                if (targetForm.style.display === 'block') {
                    // Form zaten açık, şimdi kapat
                    targetForm.style.display = 'none';
                    this.textContent = 'Değiştir';
                } else {
                    // Form kapalı, şimdi aç
                    targetForm.style.display = 'block';
                    this.textContent = 'İptal Et';
                }
            });
        });
    </script>
    <script src="/assets/js/script.js"></script>
</body>

</html>
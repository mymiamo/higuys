<?php
session_start();


require_once 'config.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1) Oturum kontrolü
if (empty($_SESSION['user_id'])) {
    $cameFrom = $_SERVER['REQUEST_URI'];
    header('Location: sign-in?redirect=' . urlencode($cameFrom));
    exit;
}


// 2) CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verify_csrf()
{
    if (
        !isset($_POST['csrf_token']) ||
        $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')
    ) {
        die("Geçersiz istek. Lütfen sayfayı yenileyin.");
    }
}

$currentUserId = $_SESSION['user_id'];
// Kullanıcı bilgisi
$stmt = $pdo->prepare('SELECT username, profile_photo_path FROM users WHERE id = :id');
$stmt->execute(['id' => $currentUserId]);
$user = $stmt->fetch();


// 3) “Report” işlemi
function decryptEmail(string $encrypted): string|false
{
    // Config’tan gelen sabitler
    $method = ENCRYPT_METHOD;
    $key = ENCRYPT_KEY;
    $iv = ENCRYPT_IV;

    // 1) Base64-decode edilmiş raw data mod
    if (($decoded = base64_decode($encrypted, true)) !== false) {
        if (($plain = openssl_decrypt($decoded, $method, $key, OPENSSL_RAW_DATA, $iv)) !== false) {
            return $plain;
        }
    }

    // 2) Default mod (input base64) 
    if (($plain = openssl_decrypt($encrypted, $method, $key, 0, $iv)) !== false) {
        return $plain;
    }

    return false;
}

// … aşağıda rapor işleminiz …

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') {
    verify_csrf();
    if (!$currentUserId) {
        die("Oturum açılmamış!");
    }

    $reportedId = (int) ($_POST['reported_user_id'] ?? 0);
    $reportedName = trim($_POST['reported_username'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $message = trim($_POST['msg'] ?? '');

    // Raporlayan
    $stmt = $pdo->prepare("SELECT username, email_encrypted FROM users WHERE id = ?");
    $stmt->execute([$currentUserId]);
    $rep = $stmt->fetch(PDO::FETCH_ASSOC);
    $reporterUsername = $rep['username'] ?? 'Bilinmiyor';
    $reporterEmail = decryptEmail($rep['email_encrypted']) ?: '';

    // Raporlanan
    $stmt2 = $pdo->prepare("SELECT email_encrypted FROM users WHERE id = ?");
    $stmt2->execute([$reportedId]);
    $reportedData = $stmt2->fetch(PDO::FETCH_ASSOC);
    $reportedEmail = '';
    if (!empty($reportedData['email_encrypted'])) {
        $reportedEmail = decryptEmail($reportedData['email_encrypted']) ?: '';
    }

    // Admin mail
    $adminBody = "
      <h2>🚨 Yeni Kullanıcı Raporu</h2>
      <p><strong>Raporlayan:</strong> {$reporterUsername} (ID: {$currentUserId})</p>
      <p><strong>Raporlanan:</strong> {$reportedName} (ID: {$reportedId})</p>
      <p><strong>Raporlanan E-posta:</strong> "
        . htmlspecialchars($reportedEmail ?: 'Bulunamadı', ENT_QUOTES)
        . "</p>
      <p><strong>Gerekçe:</strong> {$reason}</p>
      <p><strong>Ek Not:</strong><br>"
        . nl2br(htmlspecialchars($message, ENT_QUOTES))
        . "</p>
    ";

    $userBody = "
      <p>Merhaba <strong>{$reporterUsername}</strong>,</p>
      <p>Raporun bize ulaştı, teşekkür ederiz! 🔍</p>
      <p>En kısa sürede inceleyip gerekli aksiyonları alacağız.</p>
    ";

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

        // Admin’e mail
        $mail->setFrom('no-reply@higuys.app', 'HiGuys Rapor Sistemi');
        $mail->addAddress('erayydurupinar@gmail.com');
        $mail->isHTML(true);
        $mail->Subject = '🚨 Yeni Kullanıcı Raporu Geldi';
        $mail->Body = $adminBody;
        $mail->send();
        $mail->clearAllRecipients();

        // Raporlayana onay maili
        if ($reporterEmail) {
            $mail->setFrom('info@higuys.app', 'HiGuys Destek');
            $mail->addAddress($reporterEmail, $reporterUsername);
            $mail->Subject = '📩 Raporunuz Alındı';
            $mail->Body = $userBody;
            $mail->send();
        }
    } catch (Exception $e) {
        error_log("Mail gönderilemedi: " . $mail->ErrorInfo);
    }

    $_SESSION['notification'] = [
        'type' => 'success',
        'message' => 'Raporunuz bize ulaştı, teşekkür ederiz! 🙏'
    ];
    header("Location: app.php");
    exit;
}

// 4) Yaş filtresi
$stmt = $pdo->prepare("SELECT birthday FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$birthdate = $stmt->fetchColumn();
$age = (new DateTime($birthdate))->diff(new DateTime())->y;

if ($age >= 18) {
    $ageFilter = "AND birthday <= DATE_SUB(CURDATE(), INTERVAL 18 YEAR)";
} else {
    $ageFilter = "AND birthday > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)";
}

// 5) Skip/Match işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['target_user_id'])) {
    verify_csrf();
    $action = $_POST['action'];
    $targetId = (int) $_POST['target_user_id'];

    if ($action === 'skip') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO skips (user_id, skipped_user_id) VALUES (?, ?)");
        $stmt->execute([$currentUserId, $targetId]);
        $_SESSION['match_index']++;
        $_SESSION['notification'] = [
            'type' => 'info',
            'message' => 'Kullanıcıyı ghostladınız. 👻'
        ];
    } elseif ($action === 'match') {
        $stmt = $pdo->prepare("
          INSERT INTO match_requests (from_user, to_user, status)
          VALUES (?, ?, 'pending')
          ON DUPLICATE KEY UPDATE status = 'pending', sent_at = NOW()
        ");
        $stmt->execute([$currentUserId, $targetId]);
        $_SESSION['match_index']++;
        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => 'Eşleşme isteği başarıyla gönderildi! 🎉'
        ];

        $stmt = $pdo->prepare("SELECT email_encrypted, username FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $receiver = $stmt->fetch();
        $email = openssl_decrypt(
            $receiver['email_encrypted'],
            ENCRYPT_METHOD,
            ENCRYPT_KEY,
            0,
            ENCRYPT_IV
        );
        if ($email) {
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
                $mail->setFrom('no-reply@higuys.app', 'Yeni Eşleşme İsteği 🎉');
                $mail->addAddress($email, $receiver['username']);
                $mail->isHTML(true);
                $mail->Subject = 'Yeni bir eşleşme isteğiniz var! 🎉';
                $mail->Body = "<p>Merhaba <strong>{$receiver['username']}</strong>,</p>
                                 <p>Sana yeni bir eşleşme isteği gönderildi. Profiline girerek kontrol edebilirsin.</p>";
                $mail->send();
            } catch (Exception $e) {
                error_log('Mail gönderilemedi: ' . $mail->ErrorInfo);
            }
        }
    }

    header('Location: app.php');
    exit;
}

// 6) Kullanıcı kuyruğu & navigasyon
$countStmt = $pdo->prepare("
  SELECT COUNT(*) FROM users
   WHERE id != :me
     AND id NOT IN (SELECT skipped_user_id FROM skips WHERE user_id = :me)
     AND id NOT IN (SELECT to_user FROM match_requests WHERE from_user = :me)
     $ageFilter
");
$countStmt->execute(['me' => $currentUserId]);
$totalAvailable = (int) $countStmt->fetchColumn();

// 7) Navigasyon (ok tuşları) — aynen koruyun
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nav'])) {
    if ($_POST['nav'] === 'next') {
        $_SESSION['match_index']++;
    } elseif ($_POST['nav'] === 'prev' && $_SESSION['match_index'] > 0) {
        $_SESSION['match_index']--;
    }
    header("Location: app.php");
    exit;
}

// 8) Queue’yu ilk defa yükleyin
if (!isset($_SESSION['match_queue']) || !is_array($_SESSION['match_queue'])) {
    $stmt = $pdo->prepare("
    SELECT id FROM users
     WHERE id != :me
       AND id NOT IN (SELECT skipped_user_id FROM skips WHERE user_id = :me)
       AND id NOT IN (SELECT to_user FROM match_requests WHERE from_user = :me)
       AND id NOT IN (
            SELECT 
                CASE 
                    WHEN from_user = :me THEN to_user 
                    ELSE from_user 
                END
            FROM match_requests
            WHERE 
                (from_user = :me OR to_user = :me)
                AND status = 'matched'
       )
       $ageFilter
     ORDER BY RAND()
");
    $stmt->execute(['me' => $currentUserId]);
    $_SESSION['match_queue'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['match_index'] = 0;
}

// 9) Slayt & Reklam Mantığı
$queue = $_SESSION['match_queue'];
$index = $_SESSION['match_index'] ?? 0;
$adInterval = 2;
$isAdSlide = ((($index + 1) % $adInterval) === 0);

// 9.a) Eğer reklam slaytıysa, reklam yükle
if ($isAdSlide) {
    $stmt = $pdo->query("
      SELECT * FROM ads
       WHERE is_active = 1
         AND (start_date IS NULL OR start_date <= CURDATE())
         AND (end_date   IS NULL OR end_date   >= CURDATE())
      ORDER BY RAND() LIMIT 1
    ");
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ad) {
        $pdo->prepare("UPDATE ads SET impressions = impressions + 1 WHERE id = ?")
            ->execute([$ad['id']]);
        $trackUrl = '/ad_click.php?ad=' . $ad['id']
            . '&url=' . urlencode($ad['target_url']);
    }
    // kullanıcı yüklemeyin
    $nextUser = null;

    // 9.b) Değilse kullanıcı slaytı
} else {
    // eğer index queue dışında ise (yani bitti demek), 
    // DB’de hâlâ kullanıcı varsa yeniden shuffle ve index’i sıfırla
    if (!isset($queue[$index]) && $totalAvailable > 0) {
        $stmt = $pdo->prepare("
          SELECT id FROM users
           WHERE id != :me
             AND id NOT IN (SELECT skipped_user_id FROM skips WHERE user_id = :me)
             AND id NOT IN (SELECT to_user FROM match_requests WHERE from_user = :me)
             $ageFilter
           ORDER BY RAND()
        ");
        $stmt->execute(['me' => $currentUserId]);
        $_SESSION['match_queue'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $_SESSION['match_index'] = 0;
        $queue = $_SESSION['match_queue'];
        $index = 0;
    }

    // eğer hâlâ queue’da bir öğe varsa, onu yükle
    if (isset($queue[$index])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$queue[$index]]);
        $nextUser = $stmt->fetch(PDO::FETCH_ASSOC);
        // oyun bilgileri ekleme kodunuz…
    } else {
        // queue bitti ve totalAvailable === 0 demektir
        $nextUser = null;
    }
}

// 10) Cinsiyet metni
if (!empty($nextUser)) {
    $genderKey = $nextUser['gender'] ?? '';
    $genderText = $genderLabels[$genderKey] ?? $genderKey;
}

if ($nextUser) {
    // Oyun bilgilerini çek
    $stmt = $pdo->prepare("SELECT valo_rank, lol_rank, cs2_rank, gta_level, r6_rank FROM user_game_info WHERE user_id = ?");
    $stmt->execute([$nextUser['id']]);
    $games = $stmt->fetch();

    // Oyun bilgilerini $nextUser'a entegre et
    if ($games) {
        $nextUser = array_merge($nextUser, $games);
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi Guys!</title>
    <link rel="stylesheet" href="/assets/css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css">
</head>

<body>
    <div class="topbar">
        <div class="left">
            <img class="circle" src="/<?= htmlspecialchars($user['profile_photo_path']) ?>" alt="Profil Fotoğrafı" />
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
                            <span class="username">
                                <?= htmlspecialchars($user['username']) ?>
                            </span>
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

    <?php if ($isAdSlide && !empty($ad)): ?>
        <div class="main">
            <!-- ← -->
            <form method="POST" class="arrow" action="">
                <input type="hidden" name="nav" value="prev">
                <button type="submit"><i class="fa-solid fa-angle-left"></i></button>
            </form>

            <div class="ads-card">
                <div class="ads-card-header">
                    <div class="report"><i class="fa-solid fa-rectangle-ad"></i></div>
                </div>
                <div class="ads-card-body">
                    <div class="ads-img">
                        <img src="<?= htmlspecialchars($ad['image_url'] ?: '/assets/img/Hi Guys!.png') ?>"
                            alt="<?= htmlspecialchars($ad['title']) ?>">
                    </div>
                    <h4><?= htmlspecialchars($ad['title']) ?></h4>
                    <p><?= nl2br(htmlspecialchars($ad['description'] ?? '')) ?></p>
                </div>
                <div class="ads-card-footer">
                    <a href="<?= $trackUrl ?>" class="send-btn" target="_blank">Göz At</a>
                </div>
            </div>

            <!-- → -->
            <form method="POST" class="arrow" action="">
                <input type="hidden" name="nav" value="next">
                <button type="submit"><i class="fa-solid fa-angle-right"></i></button>
            </form>
        </div>

    <?php elseif ($nextUser): ?>
        <div class="main">
            <!-- ← -->
            <form method="POST" class="arrow" action="">
                <input type="hidden" name="nav" value="prev">
                <button type="submit"><i class="fa-solid fa-angle-left"></i></button>
            </form>

            <div class="card">
                <div class="card-header">
                    <div class="report">
                        <button class="report-btn" id="report-btn">
                            <i class="fa-solid fa-flag"></i> Bildir
                        </button>
                    </div>
                    <div class="profile-pic">
                        <img src="/<?= htmlspecialchars($nextUser['profile_photo_path']) ?>" alt="Profil Fotoğrafı"
                            width="100">
                    </div>
                    <div class="username">
                        <?= htmlspecialchars($nextUser['username']) ?>
                    </div>
                </div>

                <div class="card-body">
                    <section class="about-section">
                        <h3>Hakkında</h3>
                        <p><?= nl2br(htmlspecialchars($nextUser['about'])) ?></p>
                        <p><strong>Yaş:</strong>
                            <?php
                            $birth = new DateTime($nextUser['birthday']);
                            $now = new DateTime();
                            echo $now->diff($birth)->y;
                            ?>
                        </p>
                        <p><strong>Cinsiyet:</strong>
                            <?= htmlspecialchars($genderLabels[$nextUser['gender']] ?? $user['gender']) ?>
                        </p>
                    </section>

                    <section class="games-section">
                        <h3>Oyun Bilgileri</h3>
                        <ul>
                            <?php if (!empty($nextUser['valo_rank'])): ?>
                                <li><strong>Valorant:</strong> <?= htmlspecialchars($nextUser['valo_rank']) ?></li>
                            <?php endif; ?>
                            <?php if (!empty($nextUser['lol_rank'])): ?>
                                <li><strong>League of Legends:</strong> <?= htmlspecialchars($nextUser['lol_rank']) ?></li>
                            <?php endif; ?>
                            <?php if (!empty($nextUser['r6_rank'])): ?>
                                <li><strong>Rainbow Six Siege :</strong> <?= htmlspecialchars($nextUser['r6_rank']) ?></li>
                            <?php endif; ?>
                            <?php if (!empty($nextUser['cs2_rank'])): ?>
                                <li><strong>Counter-Strike 2 :</strong> <?= htmlspecialchars($nextUser['cs2_rank']) ?></li>
                            <?php endif; ?>
                            <?php if (!empty($nextUser['gta_level'])): ?>
                                <li><strong>GTA Level:</strong> <?= htmlspecialchars($nextUser['gta_level']) ?></li>
                            <?php endif; ?>
                            <?php if (
                                empty($nextUser['valo_rank']) &&
                                empty($nextUser['lol_rank']) &&
                                empty($nextUser['cs2_rank']) &&
                                empty($nextUser['gta_level']) &&
                                empty($nextUser['r6_rank'])
                            ): ?>
                                <li><em>Henüz oyun bilgisi belirtilmemiş.</em></li>
                            <?php endif; ?>
                        </ul>
                    </section>
                </div>

                <div class="card-footer">
                    <form action="" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="target_user_id" value="<?= $nextUser['id'] ?>">
                        <button type="submit" class="send-btn" name="action" value="match">Eşleşme İsteği Gönder</button>
                        <button type="submit" class="skip-btn" name="action" value="skip">Ghostla</button>
                    </form>
                </div>
            </div>

            <!-- → -->
            <form method="POST" class="arrow" action="">
                <input type="hidden" name="nav" value="next">
                <button type="submit"><i class="fa-solid fa-angle-right"></i></button>
            </form>
        </div>

    <?php else: ?>
        <div class="main">
            <div class="card">
                <p>Gösterilecek kullanıcı kalmadı.</p>
            </div>
        </div>
    <?php endif; ?>


    <div class="notification" id="notificationBox">
        <div class="header">
            <div class="name"><i class="fa-solid fa-bell"></i> Bildirim</div>
            <div class="close-area" onclick="closeNotification()"><span><i class="fa-solid fa-xmark"></i></span></div>
        </div>
        <div class="body" id="notificationMessage">
            <!-- JS ile doldurulacak -->
        </div>
    </div>

    <div class="report-modal-area" id="report-modal">
        <div class="report-modal">
            <div class="header">
                <h1>Kullanıcı Bildirme</h1>
                <span class="close-btn"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="body">
                <form id="report-form" method="POST" action="" class="inputs">
                    <!-- CSRF -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <!-- Action tipi -->
                    <input type="hidden" name="action" value="report">

                    <!-- Raportalanacak kişi -->
                    <input type="hidden" name="reported_user_id" value="<?= (int) $nextUser['id'] ?>">

                    <div class="input">
                        <label>Kullanıcı Adı</label>
                        <input type="text" name="reported_username"
                            value="<?= htmlspecialchars($nextUser['username'], ENT_QUOTES) ?>" readonly>
                    </div>
                    <div class="input">
                        <label>Nedeni</label>
                        <select name="reason" required>
                            <option value="">Lütfen bir seçenek belirleyin</option>
                            <option value="Saygısızlık">Profilde uygunsuz veya saygısız ifadeler var</option>
                            <option value="Yaş Sınırı">Kullanıcının yaşı 13’ten küçük</option>
                            <option value="Kişisel Veriler">Kullanıcı kişisel verilerini (adres, telefon vb.) paylaşmış
                            </option>
                            <option value="Spam veya Reklam">Hesap spam ya da reklam içeriyor</option>
                            <option value="Uygunsuz İçerik">Profilde uygunsuz içerik bulunuyor</option>
                            <option value="Zorbalık">Profilde zorbalık veya tehdit edici ifadeler var</option>
                            <option value="Hoşlanılmayan Kullanıcı">Bu kullanıcıdan hoşlanmadım.</option>
                        </select>
                    </div>
                    <div class="input">
                        <label>Eklemek İstediğin Birşey var mı?</label>
                        <textarea name="msg" placeholder="İstersen açıklama ekleyebilirsin..."></textarea>
                    </div>
                    <div class="input">
                        <!-- type=button, JS ile submit edeceğiz -->
                        <button type="submit" id="report-submit-btn">Kullanıcıyı Bildir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            <?php if (isset($_SESSION['notification'])): ?>
                const msg = <?= json_encode($_SESSION['notification']['message']); ?>;
                const type = <?= json_encode($_SESSION['notification']['type']); ?>;

                const box = document.getElementById('notificationBox');
                const body = document.getElementById('notificationMessage');

                if (box && body) {
                    body.textContent = msg;
                    box.classList.add(type); // Örn: success, info, warning
                    box.style.display = 'block';

                    setTimeout(() => {
                        closeNotification();
                    }, 5000);
                }
                <?php unset($_SESSION['notification']); endif; ?>
        });

        function closeNotification() {
            const n = document.getElementById('notificationBox');
            if (n) n.style.display = 'none';
        }
    </script>
    <script>
        document.getElementById("report-submit-btn")
            .addEventListener("click", () => {
                document.getElementById("report-form").submit();
            });

        // Mevcut kapatma mantığın da olduğu gibi bırakabilirsin:
        document.getElementById("report-btn")
            .addEventListener("click", () => {
                document.getElementById("report-modal").style.display = "flex";
            });
        document.querySelector(".close-btn")
            .addEventListener("click", () => {
                document.getElementById("report-modal").style.display = "none";
            });
        window.addEventListener("click", (e) => {
            if (e.target === document.getElementById("report-modal")) {
                document.getElementById("report-modal").style.display = "none";
            }
        });

    </script>

    <script src="/assets/js/script.js"></script>

</body>

</html>
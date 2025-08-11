<?php
session_start();
require_once 'config.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $requestId = (int) $_POST['request_id'];

    if ($action === 'accept') {
        $_SESSION['notification'] = [
            'message' => 'Eşleşme kabul edildi! 🎮 Artık arkadaşsınız.',
            'type' => 'success' // success sınıfı CSS’de tanımlı olmalı (yeşil vb.)
        ];

        // Eşleşme kabul edildi
        $stmt = $pdo->prepare("UPDATE match_requests SET status = 'accepted' WHERE id = ? AND to_user = ?");
        $stmt->execute([$requestId, $currentUserId]);

        // from_user ID'sini al
        $stmt = $pdo->prepare("SELECT from_user FROM match_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $fromUserId = $stmt->fetchColumn();

        if ($fromUserId) {
            // matches tablosuna kayıt
            $pdo->prepare("INSERT INTO matches (user1, user2) VALUES (?, ?)")->execute([$currentUserId, $fromUserId]);

            // Her iki kullanıcının bilgilerini çek
            $stmt = $pdo->prepare("SELECT id, email_encrypted, username, profile_photo_path FROM users WHERE id IN (?, ?)");
            $stmt->execute([$currentUserId, $fromUserId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $usersById = [];
            foreach ($users as $u) {
                $u['email'] = openssl_decrypt($u['email_encrypted'], ENCRYPT_METHOD, ENCRYPT_KEY, 0, ENCRYPT_IV);
                $usersById[$u['id']] = $u;
            }

            $currentUser = $usersById[$currentUserId];
            $otherUser = $usersById[$fromUserId];

            // Diğer kişinin oyun bilgileri
            $stmt = $pdo->prepare("SELECT valo_rank, lol_rank, cs2_rank, gta_level, r6_rank FROM user_game_info WHERE user_id = ?");
            $stmt->execute([$fromUserId]);
            $games = $stmt->fetch();

            $photoUrl = 'https://higuys.app/' . $otherUser['profile_photo_path'];

            $gamesHtml = "<em>Herhangi bir oyun bilgisi girilmemiş.</em>";
            if ($games) {
                $gamesHtml = "<ul>";
                if (!empty($games['valo_rank']))
                    $gamesHtml .= "<li><strong>Valorant:</strong> " . htmlspecialchars($games['valo_rank']) . "</li>";
                if (!empty($games['lol_rank']))
                    $gamesHtml .= "<li><strong>League of Legends:</strong> " . htmlspecialchars($games['lol_rank']) . "</li>";
                if (!empty($games['r6_rank']))
                    $gamesHtml .= "<li><strong>Rainbow Six Siege :</strong> " . htmlspecialchars($games['r6_rank']) . "</li>";
                if (!empty($games['cs2_rank']))
                    $gamesHtml .= "<li><strong>Counter-Strike 2 :</strong> " . htmlspecialchars($games['cs2_rank']) . "</li>";
                if (!empty($games['gta_level']))
                    $gamesHtml .= "<li><strong>GTA Level:</strong> " . htmlspecialchars($games['gta_level']) . "</li>";
                $gamesHtml .= "</ul>";
            }

            // Mail fonksiyonu
            function sendMatchMail($toEmail, $toUsername, $matchUsername, $matchPhotoUrl, $matchGamesHtml)
            {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.turkticaret.net';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'no-reply@higuys.app';
                    $mail->Password = '';
                    $mail->SMTPSecure = 'ssl';
                    $mail->Port = 465;
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('no-reply@higuys.app', 'Hi Guys! Bildirim');
                    $mail->addAddress($toEmail, $toUsername);
                    $mail->isHTML(true);
                    $mail->Subject = 'Yeni Arkadaşınla Eşleştin 🥳';
                    $mail->Body = "
                        <p>Merhaba <strong>{$toUsername}</strong>,</p>
                        <p>Tebrikler! Karşılıklı bir eşleşme gerçekleşti 🎉</p>
                        <p>Artık oyun arkadaşı adayınızla iletişime geçebilirsiniz.</p>
                        <img src='{$matchPhotoUrl}' alt='Profil Fotoğrafı' style='width:100px;height:auto;border-radius:8px; margin-top:10px;'>
                        <p><strong>Arkadaşının Oyun Bilgileri:</strong></p>
                        {$matchGamesHtml}
                    ";

                    $mail->send();
                } catch (Exception $e) {
                    error_log("Mail gönderilemedi ({$toEmail}): " . $mail->ErrorInfo);
                }
            }

            // Gönderen kişiye mail
            if (!empty($otherUser['email'])) {
                sendMatchMail($otherUser['email'], $otherUser['username'], $currentUser['username'], 'https://higuys.app/' . $currentUser['profile_photo_path'], $gamesHtml);
            }

            // Kabul eden kişiye mail
            if (!empty($currentUser['email'])) {
                sendMatchMail($currentUser['email'], $currentUser['username'], $otherUser['username'], $photoUrl, $gamesHtml);
            }
        }
    } elseif ($action === 'reject') {
        $_SESSION['notification'] = [
            'message' => 'İstek reddedildi. 👻',
            'type' => 'error' // error sınıfı CSS’de tanımlı olmalı (kırmızı vb.)
        ];

        $stmt = $pdo->prepare("UPDATE match_requests SET status = 'rejected' WHERE id = ? AND to_user = ?");
        $stmt->execute([$requestId, $currentUserId]);
    }

    header('Location: match-requests.php');
    exit;
}

// Bekleyen eşleşme isteklerini getir
$stmt = $pdo->prepare("SELECT r.id, u.username, u.about, u.gender, u.birthday, u.profile_photo_path,
                              g.valo_rank, g.lol_rank, g.cs2_rank, g.gta_level, g.r6_rank
                       FROM match_requests r
                       JOIN users u ON r.from_user = u.id
                       LEFT JOIN user_game_info g ON g.user_id = u.id
                       WHERE r.to_user = ? AND r.status = 'pending'");
$stmt->execute([$currentUserId]);
$requests = $stmt->fetchAll();

// Kullanıcı bilgisi
$stmt = $pdo->prepare('SELECT username, profile_photo_path FROM users WHERE id = :id');
$stmt->execute(['id' => $currentUserId]);
$user = $stmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi Guys! - Eşleşme İstekleri</title>
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

    <div class="page">
        <div class="requests">
            <?php if (!empty($requests)): ?>
                <?php foreach ($requests as $req): ?>
                    <div class="request-card">
                        <div class="avatar">
                            <img src="/<?= htmlspecialchars($req['profile_photo_path']) ?>" draggable="false"
                                alt="<?= htmlspecialchars($req['username']) ?>">
                            <span class="username">
                                <?= htmlspecialchars($req['username']) ?>
                            </span>
                        </div>

                        <div class="body">
                            <p class="about"><strong>Hakkında:</strong><br>
                                <?= nl2br(htmlspecialchars($req['about'])) ?>
                            </p>
                            <p><strong>Cinsiyet:</strong>
                                <?= htmlspecialchars($req['gender']) ?>
                            </p>
                            <p><strong>Doğum Tarihi:</strong>
                            <?php
                            $birth = new DateTime($req['birthday']);
                            $now = new DateTime();
                            echo $now->diff($birth)->y;
                            ?>
                               
                            </p>
                            <p><strong>Oyun Tercihleri:</strong></p>
                            <ul>
                                <?php if (!empty($req['valo_rank'])): ?>
                                    <li><strong>Valorant:</strong>
                                        <?= htmlspecialchars($req['valo_rank']) ?>
                                    </li>
                                <?php endif; ?>
                                <?php if (!empty($req['lol_rank'])): ?>
                                    <li><strong>League of Legends:</strong>
                                        <?= htmlspecialchars($req['lol_rank']) ?>
                                    </li>
                                <?php endif; ?>
                                <?php if (!empty($req['r6_rank'])): ?>
                                    <li><strong>Rainbow Six Siege:</strong>
                                        <?= htmlspecialchars($req['r6_rank']) ?>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($req['cs2_rank'])): ?>
                                    <li><strong>Counter-Strike 2 :</strong>
                                        <?= htmlspecialchars($req['cs2_rank']) ?>
                                    </li>
                                <?php endif; ?>
                                <?php if (!empty($req['gta_level'])): ?>
                                    <li><strong>GTA Level:</strong>
                                        <?= htmlspecialchars($req['gta_level']) ?>
                                    </li>
                                <?php endif; ?>
                                <?php if (empty($req['valo_rank']) && empty($req['lol_rank']) && empty($req['cs2_rank']) && empty($req['gta_level']) && empty($req['r6_rank'])): ?>
                                    <li><em>Henüz oyun bilgisi belirtilmemiş.</em></li>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <div class="footer">
                            <form method="POST">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button type="submit" name="action" value="accept" class="accept-btn">
                                    <i class="fa-solid fa-gamepad"></i> Kabul Et
                                </button>
                                <button type="submit" name="action" value="reject" class="reject-btn">
                                    <i class="fa-solid fa-ghost"></i> Reddet
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Bekleyen eşleşme isteğiniz yok.</p>
            <?php endif; ?>
        </div>

    </div>


    <div class="notification" id="notificationBox">
        <div class="header">
            <div class="name"><i class="fa-solid fa-bell"></i> Bildirim</div>
            <div class="close-area" onclick="closeNotification()"><span><i class="fa-solid fa-xmark"></i></span></div>
        </div>
        <div class="body" id="notificationMessage">
            <!-- JS ile doldurulacak -->
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
                    box.classList.add(type); // ✅ bildirime class ekliyoruz (örnek: "success", "info")
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
    <script src="/assets/js/script.js"></script>
</body>

</html>
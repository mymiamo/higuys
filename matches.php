<?php
// matches.php - Kabul edilmiş eşleşmeleri listeler
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];

// Kullanıcının eşleşmelerini getir
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.about, u.gender, u.birthday, u.profile_photo_path,
           u.dc_username, u.steam_profile, u.other_link,
           g.valo_rank, g.lol_rank, g.cs2_rank, g.gta_level, g.r6_rank
    FROM matches m
    JOIN users u ON (u.id = IF(m.user1 = ?, m.user2, m.user1))
    LEFT JOIN user_game_info g ON g.user_id = u.id
    WHERE m.user1 = ? OR m.user2 = ?
");
$stmt->execute([$currentUserId, $currentUserId, $currentUserId]);
$matches = $stmt->fetchAll();

$genderKey = $match['gender'] ?? '';
$genderText = $genderLabels[$genderKey] ?? $genderKey;

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
    <title>Hi Guys!</title>
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

        <div class="match-cards">
            <?php if (empty($matches)): ?>
                <div class="match">
                    <p>Henüz eşleşmeniz yok.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($matches as $match): ?>
                <div class="match">
                    <div class="avatar">
                        <img src="/<?= htmlspecialchars($match['profile_photo_path']) ?>" draggable="false"
                            alt="<?= htmlspecialchars($match['username']) ?>">
                        <span class="username">
                            <?= htmlspecialchars($match['username']) ?> 
                        </span>
                    </div>
                    <div class="body">
                        <p class="about"><strong>Hakkında:</strong><br><?= nl2br(htmlspecialchars($match['about'])) ?></p>
                        <p><strong>Cinsiyet:</strong> <?= htmlspecialchars($genderLabels[$match['gender']] ?? $user['gender']) ?></p>
                        <p><strong>Yaş:</strong>   <?php
                            $birth = new DateTime($match['birthday']);
                            $now = new DateTime();
                            echo $now->diff($birth)->y;
                            ?></p>

                        <p><strong>Oyun Tercihleri:</strong></p>
                        <ul>
                            <?php if ($match['valo_rank']): ?>
                                <li><strong>Valorant:</strong> <?= htmlspecialchars($match['valo_rank']) ?></li><?php endif; ?>
                            <?php if ($match['lol_rank']): ?>
                                <li><strong>LoL:</strong> <?= htmlspecialchars($match['lol_rank']) ?></li><?php endif; ?>
                                <?php if ($match['r6_rank']): ?>
                                <li><strong>Rainbow Six Siege :</strong> <?= htmlspecialchars($match['r6_rank']) ?></li><?php endif; ?>
                            <?php if ($match['cs2_rank']): ?>
                                <li><strong>Counter-Strike 2 :</strong> <?= htmlspecialchars($match['cs2_rank']) ?></li><?php endif; ?>
                            <?php if ($match['gta_level']): ?>
                                <li><strong>GTA Level:</strong> <?= htmlspecialchars($match['gta_level']) ?></li><?php endif; ?>
                            <?php if (!$match['valo_rank'] && !$match['lol_rank'] && !$match['cs2_rank'] && !$match['gta_level']): ?>
                                <li><em>Henüz oyun bilgisi belirtilmemiş.</em></li>
                            <?php endif; ?>
                        </ul>

                        <p><strong>Oyun Hesapları:</strong></p>
                        <ul>
                            <li><strong>Discord:</strong>
                                <?= $match['dc_username'] ? htmlspecialchars($match['dc_username']) : '<em>Bilgi verilmemiş</em>' ?>
                            </li>
                            <li><strong>Steam Profili:</strong>
                                <?php if ($match['steam_profile']): ?>
                                    <a href="<?= htmlspecialchars($match['steam_profile']) ?>" target="_blank">Profili Aç</a>
                                <?php else: ?>
                                    <em>Bilgi verilmemiş</em>
                                <?php endif; ?>
                            </li>
                            <li><strong>Diğer Link:</strong>
                                <?php if ($match['other_link']): ?>
                                    <a href="<?= htmlspecialchars($match['other_link']) ?>" target="_blank">Linki Aç</a>
                                <?php else: ?>
                                    <em>Bilgi verilmemiş</em>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>





    </div>

    <script src="/assets/js/script.js"></script>
</body>

</html>
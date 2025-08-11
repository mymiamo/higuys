<?php
session_start();
require_once 'config.php';

// Giriş kontrolü
if (!isset($_SESSION['user_id'])) {
    header('Location: sign-in.php');
    exit;
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = '';

// Form gönderildiyse
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Selectler boşsa "Oynamıyorum" olarak saklanacak
    $valo = $_POST['valo-rank'] ?? '';
    $lol = $_POST['lol-rank'] ?? '';
    $cs2 = $_POST['cs2_rank'] ?? '';
    $gta_input = $_POST['gta_level'] ?? '';
    $r6 = $_POST['r6_rank'] ?? '';

    // GTA level sayısal değilse NULL olarak kaydedilsin (yani oynamıyorum)
    $gta = is_numeric($gta_input) && $gta_input >= 0 ? (int) $gta_input : null;

    try {
        $sql = "INSERT INTO user_game_info (user_id, valo_rank, lol_rank, cs2_rank, gta_level, r6_rank)
                VALUES (:uid, :valo, :lol, :cs2, :gta, :r6)
                ON DUPLICATE KEY UPDATE
                    valo_rank = VALUES(valo_rank),
                    lol_rank  = VALUES(lol_rank),
                    cs2_rank  = VALUES(cs2_rank),
                    gta_level = VALUES(gta_level),
                    r6_rank = VALUES(r6_rank)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'uid' => $userId,
            'valo' => $valo ?: 'Oynamıyorum',
            'lol' => $lol ?: 'Oynamıyorum',
            'cs2' => $cs2 ?: 'Oynamıyorum',
            'r6' => $r6 ?: 'Oynamıyorum',
            'gta' => $gta
        ]);

        $success = 'Oyun bilgileri başarıyla kaydedildi.';
    } catch (PDOException $e) {
        $errors[] = 'Veritabanı hatası: ' . $e->getMessage();
    }
}

// Mevcut verileri çek (prefill için)
$stmt = $pdo->prepare("SELECT valo_rank, lol_rank, cs2_rank, gta_level, r6_rank FROM user_game_info WHERE user_id = :id");
$stmt->execute(['id' => $userId]);
$game = $stmt->fetch() ?: [
    'valo_rank' => '',
    'lol_rank' => '',
    'cs2_rank' => '',
    'gta_level' => '',
    'r6_rank' => '',
];

// Kullanıcı bilgileri
$stmt = $pdo->prepare("SELECT username, about, gender, birthday, profile_photo_path FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

$valo_rank_options = [
    "Oynamıyorum" => "Oynamıyorum",
    "Derecesiz" => "Derecesiz",
    "Demir 1" => "Demir 1",
    "Demir 2" => "Demir 2",
    "Demir 3" => "Demir 3",
    "Bronz 1" => "Bronz 1",
    "Bronz 2" => "Bronz 2",
    "Bronz 3" => "Bronz 3",
    "Gümüş 1" => "Gümüş 1",
    "Gümüş 2" => "Gümüş 2",
    "Gümüş 3" => "Gümüş 3",
    "Altın 1" => "Altın 1",
    "Altın 2" => "Altın 2",
    "Altın 3" => "Altın 3",
    "Platin 1" => "Platin 1",
    "Platin 2" => "Platin 2",
    "Platin 3" => "Platin 3",
    "Elmas 1" => "Elmas 1",
    "Elmas 2" => "Elmas 2",
    "Elmas 3" => "Elmas 3",
    "Yücelik 1" => "Yücelik 1",
    "Yücelik 2" => "Yücelik 2",
    "Yücelik 3" => "Yücelik 3",
    "Ölümsüzlük 1" => "Ölümsüzlük 1",
    "Ölümsüzlük 2" => "Ölümsüzlük 2",
    "Ölümsüzlük 3" => "Ölümsüzlük 3",
    "Radyant" => "Radyant"
];


$lol_rank_options = [
    "Oynamıyorum" => "Oynamıyorum",
    "Derecesiz" => "Derecesiz",
    "Iron IV" => "Iron IV",
    "Iron III" => "Iron III",
    "Iron II" => "Iron II",
    "Iron I" => "Iron I",
    "Bronze IV" => "Bronze IV",
    "Bronze III" => "Bronze III",
    "Bronze II" => "Bronze II",
    "Bronze I" => "Bronze I",
    "Silver IV" => "Silver IV",
    "Silver III" => "Silver III",
    "Silver II" => "Silver II",
    "Silver I" => "Silver I",
    "Gold IV" => "Gold IV",
    "Gold III" => "Gold III",
    "Gold II" => "Gold II",
    "Gold I" => "Gold I",
    "Platinum IV" => "Platinum IV",
    "Platinum III" => "Platinum III",
    "Platinum II" => "Platinum II",
    "Platinum I" => "Platinum I",
    "Emerald IV" => "Emerald IV",
    "Emerald III" => "Emerald III",
    "Emerald II" => "Emerald II",
    "Emerald I" => "Emerald I",
    "Diamond IV" => "Diamond IV",
    "Diamond III" => "Diamond III",
    "Diamond II" => "Diamond II",
    "Diamond I" => "Diamond I",
    "Master" => "Master",
    "Grandmaster" => "Grandmaster",
    "Challenger" => "Challenger"
];


$cs2_rank_options = [
    "Oynamıyorum" => "Oynamıyorum",
    "Derecesiz" => "Derecesiz",
    "Silver I" => "Silver I",
    "Silver II" => "Silver II",
    "Silver III" => "Silver III",
    "Silver IV" => "Silver IV",
    "Silver Elite" => "Silver Elite",
    "Silver Elite Master" => "Silver Elite Master",
    "Gold Nova I" => "Gold Nova I",
    "Gold Nova II" => "Gold Nova II",
    "Gold Nova III" => "Gold Nova III",
    "Gold Nova Master" => "Gold Nova Master",
    "Master Guardian I" => "Master Guardian I",
    "Master Guardian II" => "Master Guardian II",
    "Master Guardian Elite" => "Master Guardian Elite",
    "Distinguished Master Guardian" => "Distinguished Master Guardian",
    "Legendary Eagle" => "Legendary Eagle",
    "Legendary Eagle Master" => "Legendary Eagle Master",
    "Supreme Master First Class" => "Supreme Master First Class",
    "The Global Elite" => "The Global Elite"
];

$r6_rank_options = [
    "Oynamıyorum" => "Oynamıyorum",
    "Derecesiz" => "Derecesiz",
    "Copper V" => "Copper V",
    "Copper IV" => "Copper IV",
    "Copper III" => "Copper III",
    "Copper II" => "Copper II",
    "Copper I" => "Copper I",
    "Bronze V" => "Bronze V",
    "Bronze IV" => "Bronze IV",
    "Bronze III" => "Bronze III",
    "Bronze II" => "Bronze II",
    "Bronze I" => "Bronze I",
    "Silver V" => "Silver V",
    "Silver IV" => "Silver IV",
    "Silver III" => "Silver III",
    "Silver II" => "Silver II",
    "Silver I" => "Silver I",
    "Gold V" => "Gold V",
    "Gold IV" => "Gold IV",
    "Gold III" => "Gold III",
    "Gold II" => "Gold II",
    "Gold I" => "Gold I",
    "Platinum V" => "Platinum V",
    "Platinum IV" => "Platinum IV",
    "Platinum III" => "Platinum III",
    "Platinum II" => "Platinum II",
    "Platinum I" => "Platinum I",
    "Emerald V" => "Emerald V",
    "Emerald IV" => "Emerald IV",
    "Emerald III" => "Emerald III",
    "Emerald II" => "Emerald II",
    "Emerald I" => "Emerald I",
    "Diamond V" => "Diamond V",
    "Diamond IV" => "Diamond IV",
    "Diamond III" => "Diamond III",
    "Diamond II" => "Diamond II",
    "Diamond I" => "Diamond I",
    "Champion" => "Champion",
    "Elite Champion" => "Elite Champion"
];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi Guys! Oyun Bilgileri</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css">
</head>

<body>
    <div class="topbar">
        <div class="left">
            <a href="javascript:history.back()" style="text-decoration: none; color:#fff;">
                <i class="fa-solid fa-angle-left"></i>
            </a>
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
            </div>
            <div class="username">
                <?= htmlspecialchars($user['username']) ?>
            </div>
            <p>Bu alana eklediğiniz bilgiler eşleşme istedği gönderdiğiniz kişilere gösterilir. Doğruluğu önemlidir.</p>
            <div class="inputs">
                <form action="" method="POST" class="input">
                    <!-- VALORANT -->
                    <label for="valo-rank">Valorant Rankı</label>
                    <select name="valo-rank">
                        <?php foreach ($valo_rank_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $game['valo_rank'] === $value ? "selected" : "" ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- LOL -->
                    <label for="lol-rank">League of Legends</label>
                    <select name="lol-rank">
                        <?php foreach ($lol_rank_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $game['lol_rank'] === $value ? "selected" : "" ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- CS2 -->
                    <label for="r6_rank">Rainbow Six Siege Rankı</label>
                    <select name="r6_rank">
                        <?php foreach ($r6_rank_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $game['r6_rank'] === $value ? "selected" : "" ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="cs2_rank">Counter Strike 2 Rankı</label>
                    <select name="cs2_rank">
                        <?php foreach ($cs2_rank_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $game['cs2_rank'] === $value ? "selected" : "" ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- GTA -->
                    <label for="gta_level">GTA 5 Online Leveli</label>
                    <p>Boş bırakırsanız <strong>Oynamıyorum</strong> anlamına gelmektedir.</p>
                    <input type="number" name="gta_level" value="<?= htmlspecialchars($game['gta_level'] ?? '') ?>">

                    <!-- Submit -->
                    <button type="submit">Kaydet</button>
                </form>

            </div>

        </div>
    </div>
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
    <script src="/assets/js/script.js"></script>
</body>

</html>
<?php
$token = 'token';
$apiUrl = "https://api.telegram.org/bot$token/";
$servername = "localhost";
$username = "username";
$password = "password";
$dbname = "dbname";

// MySQLga ulanish
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Foydalanuvchi ma'lumotlarini olish
$input = file_get_contents('php://input');
$update = json_decode($input, TRUE);

$chatId = isset($update['message']['chat']['id']) ? $update['message']['chat']['id'] : null;
$message = isset($update['message']['text']) ? $update['message']['text'] : '';
$callbackQuery = isset($update['callback_query']) ? $update['callback_query'] : null;

if ($chatId && $message) {
    if ($message == '/start') {
        // Foydalanuvchi mavjudligini tekshirish
        $stmt = $conn->prepare("SELECT referral_code FROM users WHERE telegram_id = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $stmt->bind_result($existingReferralCode);
        $stmt->fetch();
        $stmt->close();

        if ($existingReferralCode) {
            // Mavjud foydalanuvchi
            $referralCode = $existingReferralCode;
            $response = "Siz oldin botga obuna bo'lgansiz. Sizning referral kod: $referralCode\nDo'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
        } else {
            // Yangi foydalanuvchi uchun referral kod yaratish
            $referralCode = bin2hex(random_bytes(5));
            $stmt = $conn->prepare("INSERT INTO users (telegram_id, referral_code) VALUES (?, ?)");
            $stmt->bind_param("is", $chatId, $referralCode);
            $stmt->execute();
            $stmt->close();
            $response = "Botga hush kelibsiz! Sizning referral kod: $referralCode\nDo'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
        }
        sendMessage($chatId, $response);
    } else if (strpos($message, '/start ') === 0) {
        $referralCode = substr($message, 7);
        
        // Foydalanuvchini ro'yxatga olish va refererni belgilash
        $stmt = $conn->prepare("SELECT telegram_id FROM users WHERE referral_code = ?");
        $stmt->bind_param("s", $referralCode);
        $stmt->execute();
        $stmt->bind_result($referrerId);
        $stmt->fetch();
        $stmt->close();
        
        if ($referrerId) {
            // Foydalanuvchi mavjudligini tekshirish
            $stmt = $conn->prepare("SELECT telegram_id, referred_by FROM users WHERE telegram_id = ?");
            $stmt->bind_param("i", $chatId);
            $stmt->execute();
            $stmt->bind_result($existingUserId, $existingReferredBy);
            $stmt->fetch();
            $stmt->close();
            
            if ($existingUserId) {
                if ($existingReferredBy) {
                    // Foydalanuvchi allaqachon referal orqali ro'yxatdan o'tgan
                    $response = "Siz oldin botga obuna bo'lgansiz.";
                } else {
                    // Foydalanuvchi mavjud, lekin referal orqali ro'yxatdan o'tmagan
                    $stmt = $conn->prepare("UPDATE users SET referred_by = ? WHERE telegram_id = ?");
                    $stmt->bind_param("si", $referralCode, $chatId);
                    $stmt->execute();
                    $stmt->close();

                    // Refererga ball qo'shish
                    $stmt = $conn->prepare("UPDATE users SET points = points + 1 WHERE referral_code = ?");
                    $stmt->bind_param("s", $referralCode);
                    $stmt->execute();
                    $stmt->close();

                    // Refererga xabar yuborish
                    sendMessage($referrerId, "Sizning referal kod orqali do'stingiz botga qo'shildi va sizga +1 ball berildi!");

                    $response = "Botga hush kelibsiz! Siz oldin ro'yxatdan o'tmagan edingiz. Endi do'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
                }
            } else {
                // Yangi foydalanuvchi uchun ro'yxatdan o'tish
                $newReferralCode = bin2hex(random_bytes(5));
                $stmt = $conn->prepare("INSERT INTO users (telegram_id, referral_code, referred_by) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $chatId, $newReferralCode, $referralCode);
                $stmt->execute();
                $stmt->close();

                // Refererga ball qo'shish
                $stmt = $conn->prepare("UPDATE users SET points = points + 1 WHERE referral_code = ?");
                $stmt->bind_param("s", $referralCode);
                $stmt->execute();
                $stmt->close();

                // Refererga xabar yuborish
                sendMessage($referrerId, "Sizning referal kod orqali do'stingiz botga qo'shildi va sizga +1 ball berildi!");

                $response = "Botga hush kelibsiz! Sizning referral kod: $newReferralCode\nDo'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$newReferralCode";
            }
        } else {
            // Notog'ri referal kod
            $response = "Notog'ri referral kod.";
        }
        sendMessage($chatId, $response);
    } else if ($message == '/rating') {
        // Top 10 reytingni ko'rsatish
        $stmt = $conn->prepare("SELECT telegram_id, points FROM users ORDER BY points DESC LIMIT 10");
        $stmt->execute();
        $stmt->bind_result($userId, $points);
        
        $ratingMessage = "Top 10 foydalanuvchilar:\n";
        $rank = 1;
        while ($stmt->fetch()) {
            $profileLink = "tg://user?id=$userId";
            $ratingMessage .= "Foydalanuvchi ID: $rank. [ $userId]($profileLink), Ballar: $points\n";
            $rank++;
        }
        $stmt->close();

        sendMessage($chatId, $ratingMessage, null, 'Markdown');
    }
}

function sendMessage($chatId, $message, $keyboard = null, $parseMode = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'text' => $message
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }
    file_get_contents($apiUrl . "sendMessage?" . http_build_query($data));
}
?>

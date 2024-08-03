<?php
$token = '5100515674:AAGO7PRF39fd-wNvvb33E339FH0VuiXsgRY';
$apiUrl = "https://api.telegram.org/bot$token/";
$servername = "localhost";
$username = "";
$password = "";
$dbname = "";

// Majburiy obuna kanali
$channelId = '@shodiboyev_ozodbek';

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

function checkIfUserIsMember($chatId) {
    global $apiUrl, $channelId;

    $url = $apiUrl . "getChatMember?chat_id=$channelId&user_id=$chatId";
    $response = file_get_contents($url);
    $result = json_decode($response, true);

    if ($result && isset($result['result']['status'])) {
        $status = $result['result']['status'];
        return in_array($status, ['member', 'administrator', 'creator']);
    }

    return false;
}

if ($chatId && $message) {
    if ($message == '/start') {
        if (checkIfUserIsMember($chatId)) {
            // Foydalanuvchi kanalda a'zo bo'lsa
            $stmt = $conn->prepare("SELECT referral_code FROM users WHERE telegram_id = ?");
            $stmt->bind_param("i", $chatId);
            $stmt->execute();
            $stmt->bind_result($existingReferralCode);
            $stmt->fetch();
            $stmt->close();

            if ($existingReferralCode) {
                // Mavjud foydalanuvchi
                $referralCode = $existingReferralCode;
                $response = "Siz oldin botga obuna bo'lgansiz.\nDo'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
            } else {
                // Yangi foydalanuvchi uchun referral kod yaratish
                $referralCode = bin2hex(random_bytes(5));
                $stmt = $conn->prepare("INSERT INTO users (telegram_id, referral_code) VALUES (?, ?)");
                $stmt->bind_param("is", $chatId, $referralCode);
                $stmt->execute();
                $stmt->close();
                $response = "Botga hush kelibsiz!\nDo'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
            }

            // Tugmalarni yaratish
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => "Do'stlarni taklif qilish", 'callback_data' => 'invite_friends'],
                        ['text' => "Ballarni ko'rish", 'callback_data' => 'check_points']
                    ],
                    [
                        ['text' => "Reyting", 'callback_data' => 'rating']
                    ]
                ]
            ];

            sendMessage($chatId, $response, $keyboard);
        } else {
            // Foydalanuvchi kanalda a'zo bo'lmasa
            $response = "Ilmiy kanalimizga obuna bo'lish uchun: $channelId, keyin /start buyrug'ini yuboring.";
            sendMessage($chatId, $response);
        }
    }
} elseif ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackChatId = $callbackQuery['message']['chat']['id'];
    $callbackMessageId = $callbackQuery['message']['message_id'];

    if (checkIfUserIsMember($callbackChatId)) {
        if ($callbackData == 'invite_friends') {
            $stmt = $conn->prepare("SELECT referral_code FROM users WHERE telegram_id = ?");
            $stmt->bind_param("i", $callbackChatId);
            $stmt->execute();
            $stmt->bind_result($referralCode);
            $stmt->fetch();
            $stmt->close();

            if ($referralCode) {
                $response = "Do'stlaringizni ushbu havola orqali taklif qiling: https://t.me/InstaSaverUz_Bot?start=$referralCode";
            } else {
                $response = "Sizning referral kod topilmadi.";
            }

            sendMessage($callbackChatId, $response);
        } elseif ($callbackData == 'check_points') {
            $stmt = $conn->prepare("SELECT points FROM users WHERE telegram_id = ?");
            $stmt->bind_param("i", $callbackChatId);
            $stmt->execute();
            $stmt->bind_result($points);
            $stmt->fetch();
            $stmt->close();

            $response = "Sizda $points ball bor.";

            sendMessage($callbackChatId, $response);
        } elseif ($callbackData == 'rating') {
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

            sendMessage($callbackChatId, $ratingMessage, null, 'Markdown');
        }
    } else {
        $response = "Ilmiy kanalimizga obuna bo'lish uchun: $channelId, keyin /start buyrug'ini yuboring.";
        sendMessage($callbackChatId, $response);
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

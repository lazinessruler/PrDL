<?php
// Stop Telegram retries
http_response_code(200);

require_once 'config.php';

// ==========================================
// 🛠️ AUTO-FIX & LOGGING
// ==========================================
if (!file_exists(LOG_FILE)) file_put_contents(LOG_FILE, "0");
if (!file_exists(COOKIE_FILE)) file_put_contents(COOKIE_FILE, "");

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) die();

// Prevent double processing
$update_id = $update['update_id'];
if ($update_id == file_get_contents(LOG_FILE)) die();
file_put_contents(LOG_FILE, $update_id);

// ==========================================
// 🚦 TRAFFIC CONTROLLER
// ==========================================

// 1. BUTTON CLICKS (For "I Joined")
if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

// 2. TEXT MESSAGES
if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $text = $msg['text'] ?? '';
    $user_id = $msg['from']['id'];
    $first_name = $msg['from']['first_name'] ?? 'User';
    
    // Command: /start
    if ($text === '/start') {
        sendWelcome($chat_id, $first_name);
    }
    // Link Handler
    elseif (filter_var($text, FILTER_VALIDATE_URL)) {
        // 🔒 THE GATEKEEPER CHECK
        if (isMember($user_id)) {
            processVideo($chat_id, $text);
        } else {
            sendForceJoinAlert($chat_id, $first_name);
        }
    }
}

// ==========================================
// 🔐 FORCE JOIN SYSTEM (The Guard)
// ==========================================

function isMember($user_id) {
    // We check the status of the user in the channel
    $res = apiRequest("getChatMember", [
        'chat_id' => F_CHANNEL_USERNAME,
        'user_id' => $user_id
    ]);

    // If API fails (bot not admin?), assume false to be safe
    if (!isset($res['result']['status'])) return false;

    $status = $res['result']['status'];
    // Allowed statuses: creator, administrator, member
    return in_array($status, ['creator', 'administrator', 'member']);
}

function sendForceJoinAlert($chat_id, $name) {
    $msg = "🚫 <b>Access Restricted</b>\n\n" .
       "Hi <b>$name</b> 👋\n\n" .
       "To use this bot, you need to join our official updates channel first.\n\n" .
       "Don’t worry, it only takes a few seconds 🙂\n\n" .
       "<b>Steps:</b>\n" .
       "1️⃣ Join the channel using the button below.\n" .
       "2️⃣ After joining, click <b>\"I Joined\"</b>.\n\n" .
       "Once verified, you'll get full access instantly.";

$keyboard = [
    'inline_keyboard' => [
        [
            ['text' => '📢 Join Channel', 'url' => F_CHANNEL_LINK]
        ],
        [
            ['text' => '✅ I Joined', 'callback_data' => 'check_join']
        ]
    ]
];

apiRequest("sendMessage", [
    'chat_id' => $chat_id,
    'text' => $msg,
    'parse_mode' => 'HTML',
    'reply_markup' => json_encode($keyboard)
]);
}

function handleCallback($cq) {
    $chat_id = $cq['message']['chat']['id'];
    $user_id = $cq['from']['id'];
    $msg_id = $cq['message']['message_id'];
    $data = $cq['data'];

    if ($data === 'check_join') {
        if (isMember($user_id)) {
            // ✅ User has joined!
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $cq['id'],
                'text' => "✅ Verification Successful!"
            ]);
            
            // Delete the warning message
            deleteMessage($chat_id, $msg_id);
            
            // Send a "Ready" message
            apiRequest("sendMessage", [
'chat_id' => $chat_id,
'text' => "<b>🎉 Access Approved!</b>\n\nYou can now send your video link again.",
'parse_mode' => 'HTML'
]);        } else {
            // ❌ User still lying
            apiRequest("answerCallbackQuery", [
'callback_query_id' => $cq['id'],
'text' => "❌ Please join the channel first!",
'show_alert' => true
]);
        }
    }
}

// ==========================================
// 🎨 ANIMATION & PROCESSING
// ==========================================

function processVideo($chat_id, $url) {
$baseText = 
    "🎬 <b>VIDEO PIPELINE</b>\n\n" .
    "● Validating link\n" .
    "○ Fetching API RE\n" .
    "○ Generating formats\n\n" .
    "<i>Please wait...</i>";

$msg_id = sendStylishMessage($chat_id, $baseText);

try {

    usleep(350000);

    editMessage($chat_id, $msg_id,
        "🎬 <b>VIDEO PIPELINE</b>\n\n" .
        "✔ Validating link\n" .
        "● Fetching API RE\n" .
        "○ Generating formats\n\n" .
        "<i>Contacting source server...</i>"
    );

    // Real backend call here
    $video_data = fetchDownloadInfo($url);

    usleep(350000);

    editMessage($chat_id, $msg_id,
        "🎬 <b>VIDEO PIPELINE</b>\n\n" .
        "✔ Validating link\n" .
        "✔ Fetching API RE\n" .
        "● Generating formats\n\n" .
        "<i>Ban rhe H download options...</i>"
    );

    $main_info = $video_data[0];
    $title = htmlspecialchars($main_info['title']);
    $thumb = $main_info['thumbnail'];

    $buttons = [];

    foreach ($video_data as $video) {
        $quality = $video['quality'] ?? 'HD';
        $clean_url = str_replace("https://href.li/?", "", $video['url']);
        $buttons[] = [
            'text' => "📥 " . strtoupper($quality),
            'url'  => $clean_url
        ];
    }

    $keyboard = count($buttons) > 1 ? array_chunk($buttons, 2) : [$buttons];

    editMessage($chat_id, $msg_id,
        "🎬 <b>VIDEO PIPELINE</b>\n\n" .
        "✔ Validating link\n" .
        "✔ Fetching API RE\n" .
        "✔ Generating formats\n\n" .
        "<b>✓ Ready</b>"
    );

    usleep(250000);

    $caption =
        "🎬 <b>" . $title . "</b>\n\n" .
        "📂 <b>Available Formats:</b> " . count($buttons) . "\n" .
        "──────────────────\n" .
        "Select your preferred quality below.";

    sendPhotoWithKeyboard($chat_id, $thumb, $caption, $keyboard);

    deleteMessage($chat_id, $msg_id);

} catch (Exception $e) {

    editMessage($chat_id, $msg_id,
        "❌ <b>Processing Failed</b>\n\n" .
        "<code>" . $e->getMessage() . "</code>"
    );
}}

function sendWelcome($chat_id, $first_name) {

        // ✨ THE PROFESSIONAL WELCOME UI
        $welcome_msg = "<b>Hi, " . htmlspecialchars($first_name) . " 👋</b>\n\n" .
                       "Welcome to <b>KOMDI DOWNLOADER</b>\n" .
                       "<i>The 🍑 to your FAP!! <blockquote><b>BADDIE</b></blockquote></i>\n\n" .
                       "<blockquote><b>🚀 Getting Started:</b>\n" .
                       "Paste any supported video link below to begin instant extraction.</blockquote>\n\n" .
                       "<b>System:</b> <code>Online 🟢</code>\n" .
                       "<b>Speed:</b> <code>Turbo API ⚡</code>\n\n" .
                       "<b>Supported Platforms:</b>\n" .
                       "<tg-spoiler>RedTube, xHamster, XVideos, HelloPorn + 10 more</tg-spoiler>\n\n" .
                       "─── <b>Ready for input...</b> ───";
    $keyboard = ['inline_keyboard' => [[['text' => '📢 Official Updates', 'url' => F_CHANNEL_LINK]]]];

    apiRequest("sendMessage", [
        'chat_id' => $chat_id,
        'text' => $welcome_msg,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ]);
}

// ==========================================
// 🌐 API BACKEND
// ==========================================

function fetchDownloadInfo($video_url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => BASE_URL . "/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => COOKIE_FILE,
        CURLOPT_COOKIEFILE => COOKIE_FILE,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    curl_close($ch);

    if (!file_exists(COOKIE_FILE)) throw new Exception("Session Init Error");
    $cookies = file_get_contents(COOKIE_FILE);
    preg_match('/x-csrf-token\s+([^\s]+)/', $cookies, $matches);
    $csrf = $matches[1] ?? null;
    if (!$csrf) throw new Exception("Server Busy (CSRF Error)");

    $ch = curl_init();
    $payload = json_encode(["apiToken" => API_KEY, "apiValue" => $video_url]);
    curl_setopt_array($ch, [
        CURLOPT_URL => BASE_URL . "/callDownloaderApi",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => COOKIE_FILE,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Origin: " . BASE_URL, "Referer: " . BASE_URL . "/", "X-CSRF-Token: " . $csrf, "User-Agent: Mozilla/5.0"],
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response, true);

    if (empty($json['data'])) throw new Exception("Private or Removed Video");
    return $json['data'];
}

// ==========================================
// 📡 HELPERS
// ==========================================

function apiRequest($method, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => TG_API . $method,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function sendStylishMessage($chat_id, $text) {
    $res = apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML']);
    return $res['result']['message_id'] ?? null;
}

function editMessage($chat_id, $msg_id, $text) {
    apiRequest("editMessageText", ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML']);
}

function deleteMessage($chat_id, $msg_id) {
    apiRequest("deleteMessage", ['chat_id' => $chat_id, 'message_id' => $msg_id]);
}

function sendPhotoWithKeyboard($chat_id, $photo, $caption, $keyboard_layout) {
    apiRequest("sendPhoto", ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keyboard_layout])]);
}
?>

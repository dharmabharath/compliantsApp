<?php
session_start();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to verify CSRF token
function verifyCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Load .env file

// Microsoft Graph API credentials
$gemini_api_key = getenv('GEMINI_API_KEY');
$tenant_id = getenv('MICROSOFT_TENANT_ID');
$client_id = getenv('MICROSOFT_CLIENT_ID');
$client_secret = getenv('MICROSOFT_CLIENT_SECRET');

// email addresses
$YOUR_NAME = getenv('YOUR_NAME');
$FROM_YOUR_EMAIL = getenv('FROM_YOUR_EMAIL');
$TO_COUNCIL_EMAIL = getenv('TO_COUNCIL_EMAIL');
$CC_EMAILS = getenv('CC_EMAILS');

// set to your city
$MAP_CENTER_LAT = getenv('MAP_CENTER_LAT') ?: 11.0168;
$MAP_CENTER_LNG = getenv('MAP_CENTER_LNG') ?: 76.9558;



error_reporting(0);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_file_uploads', '20');

$config = [
    'microsoft' => [
        'tenant_id' => $tenant_id,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'from' => $FROM_YOUR_EMAIL,
        'to' => $TO_COUNCIL_EMAIL
    ],
    'gemini' => [
        'key' => $gemini_api_key
    ]
];

$message = '';
$messageType = '';

// Function to get Microsoft Graph API access token
function getMicrosoftAccessToken($config)
{
    $tokenUrl = "https://login.microsoftonline.com/{$config['microsoft']['tenant_id']}/oauth2/v2.0/token";

    $postData = [
        'client_id' => $config['microsoft']['client_id'],
        'client_secret' => $config['microsoft']['client_secret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// Handle AJAX request for Gemini expansion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'expand') {
    header('Content-Type: application/json');

    // Verify CSRF token
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $complaint = trim($_POST['complaint'] ?? '');
    $hasAttachments = isset($_POST['hasAttachments']) && $_POST['hasAttachments'] === 'true';
    $address = trim($_POST['address'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    if (empty($complaint)) {
        echo json_encode(['success' => false, 'error' => 'Complaint text is required']);
        exit;
    }

    $systemPrompt = "You are an assistant that converts informal complaints into formal letters in Tamil for sending to the Coimbatore Municipal Corporation in Tamil Nadu, India.

FORMAT OF THE LETTER:
Always start with a structured data block for quick scanning (only include fields you have information for):

---
சுருக்கம்: [one line summarizing the problem, e.g., '3 நாட்களாக குப்பை தொட்டியில் குப்பை குவிந்திருக்கிறது']
இடம்: [address/road mentioned]
Google Maps: [link if provided]
---

Then write the formal letter in Tamil:
- Formal salutation: 'அன்பார்ந்த ஐயா/அம்மா,'
- Structure the problem clearly
- Include a specific request for action
- End with formal closing

Use proper Tamil script and formal language. The letter should be addressed to the Commissioner, Coimbatore Municipal Corporation.

IMPORTANT: NEVER add placeholders, templates, or text in brackets like [பெயர்], [முகவரி], etc. The letter must be ready to send without any text to fill in. If you don't have information, simply don't include that field.

Always sign the letter with:
நன்றி,
" . $YOUR_NAME;

    $userPrompt = "Convert the following complaint into a formal letter for Coimbatore Municipal Corporation, Tamil Nadu.\n\n";

    if (!empty($address)) {
        $userPrompt .= "Problem location: $address\n";
        if (!empty($lat) && !empty($lng)) {
            $userPrompt .= "Google Maps: https://www.google.com/maps/@$lat,$lng,100m/data=!3m1!1e3\n";
        }
        $userPrompt .= "\n";
    }

    $userPrompt .= "Report:\n$complaint";

    if ($hasAttachments) {
        $userPrompt .= "\n\nNOTE: The sender will attach photographs as proof. Mention this in the letter (e.g., 'சம்பந்தப்பட்ட நிலைமையை உறுதிப்படுத்தும் புகைப்படங்களை இணைப்பாக அனுப்புகிறேன்.')";
    }

    $userPrompt .= "\n\nWrite first the letter in Tamil, then write ===ENGLISH=== and the English translation.";

    // Gemini API call
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key;

    $requestData = [
        'model' => 'models/gemini-2.5-flash',
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt . "\n\n" . $userPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 10000,
            'temperature' => 0.7,
            'topP' => 0.95,
            'topK' => 40
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $geminiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorMsg = 'Failed to connect to Gemini API';
        $responseData = json_decode($response, true);
        if (isset($responseData['error'])) {
            $errorMsg = 'Gemini API Error: ' . ($responseData['error']['message'] ?? 'Unknown error');
        }
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }

    $data = json_decode($response, true);
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        echo json_encode(['success' => true, 'expanded' => $data['candidates'][0]['content']['parts'][0]['text']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid response from Gemini API']);
    }
    exit;
}

// Handle form submission (send email via Microsoft Graph)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    header('Content-Type: application/json');

    // Verify CSRF token
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $complaint = trim($_POST['complaint'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    // Extract சுருக்கம் from the letter for subject, or fallback to first 60 chars
    if (preg_match('/சுருக்கம்:\s*(.+)/i', $complaint, $matches)) {
        $subject = trim($matches[1]);
    } else {
        $subject = mb_substr(preg_replace('/\s+/', ' ', $complaint), 0, 60);
        if (mb_strlen($complaint) > 60) $subject .= '...';
    }

    if (empty($complaint)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in the complaint.']);
        exit;
    }

    // Build email body
    $emailBody = $complaint;

    // Prepare attachments (resize images to ~1MB max)
    $attachments = [];
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['attachments']['tmp_name'][$i];
                $fileName = $_FILES['attachments']['name'][$i];
                $mimeType = mime_content_type($tmpName);

                // Resize image if it's too large
                $maxSize = 1024 * 1024; // 1MB
                $fileSize = filesize($tmpName);

                if (strpos($mimeType, 'image/') === 0 && $fileSize > $maxSize) {
                    $imageData = resizeImage($tmpName, $mimeType, $maxSize);
                    $content = base64_encode($imageData);
                    // Update filename to jpg if converted
                    if ($mimeType !== 'image/jpeg') {
                        $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.jpg';
                        $mimeType = 'image/jpeg';
                    }
                } else {
                    $content = base64_encode(file_get_contents($tmpName));
                }

                $attachments[] = [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $fileName,
                    'contentType' => $mimeType,
                    'contentBytes' => $content
                ];
            }
        }
    }

    function resizeImage($filePath, $mimeType, $maxSize)
    {
        // Load image
        switch ($mimeType) {
            case 'image/jpeg':
                $img = imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $img = imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $img = imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                $img = imagecreatefromwebp($filePath);
                break;
            default:
                return file_get_contents($filePath);
        }

        if (!$img) return file_get_contents($filePath);

        // Get dimensions
        $width = imagesx($img);
        $height = imagesy($img);

        // Start with quality 85 and reduce until under maxSize
        $quality = 85;
        do {
            ob_start();
            imagejpeg($img, null, $quality);
            $data = ob_get_clean();
            $quality -= 10;
        } while (strlen($data) > $maxSize && $quality > 20);

        // If still too large, also reduce dimensions
        if (strlen($data) > $maxSize) {
            $scale = sqrt($maxSize / strlen($data));
            $newWidth = (int)($width * $scale);
            $newHeight = (int)($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $resized;

            ob_start();
            imagejpeg($img, null, 70);
            $data = ob_get_clean();
        }

        imagedestroy($img);
        return $data;
    }

    // Check if files were uploaded but none processed (attachment failure)
    $filesUploaded = isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0]);
    if ($filesUploaded && empty($attachments)) {
        echo json_encode(['success' => false, 'message' => 'Failed to process attachments. Please try again.']);
        exit;
    }

    // Get Microsoft Graph access token
    $accessToken = getMicrosoftAccessToken($config);
    if (!$accessToken) {
        echo json_encode(['success' => false, 'message' => 'Failed to authenticate with Microsoft Graph API']);
        exit;
    }

    // Prepare CC recipients
    $ccRecipients = [];
    if (!empty($CC_EMAILS)) {
        $ccEmails = array_map('trim', explode(',', $CC_EMAILS));
        foreach ($ccEmails as $ccEmail) {
            if (!empty($ccEmail)) {
                $ccRecipients[] = [
                    'emailAddress' => [
                        'address' => $ccEmail
                    ]
                ];
            }
        }
    }

    // Build Microsoft Graph email payload
    $emailPayload = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'Text',
                'content' => $emailBody
            ],
            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => $config['microsoft']['to']
                    ]
                ]
            ]
        ],
        'saveToSentItems' => 'true'
    ];

    // Add CC recipients if any
    if (!empty($ccRecipients)) {
        $emailPayload['message']['ccRecipients'] = $ccRecipients;
    }

    // Add attachments if any
    if (!empty($attachments)) {
        $emailPayload['message']['attachments'] = $attachments;
    }

    // Send via Microsoft Graph API
    $graphUrl = "https://graph.microsoft.com/v1.0/users/{$config['microsoft']['from']}/sendMail";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailPayload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 202 || $httpCode === 200) {
        $attachmentCount = count($attachments);
        echo json_encode(['success' => true, 'message' => "Complaint sent successfully to Coimbatore Municipal Corporation! ({$attachmentCount} photo" . ($attachmentCount != 1 ? 's' : '') . " attached)"]);
    } else {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'message' => 'Error sending: ' . $errorMsg]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report to Coimbatore Municipal Corporation</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🇮🇳</text></svg>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
            color: #333;
        }

        h1 {
            color: #000000;
            border-bottom: 3px solid #000000;
            padding-bottom: 10px;
        }

        .info {
            background: #e8f5e9;
            border-left: 4px solid #000000;
            padding: 15px;
            margin-bottom: 20px;
        }

        form {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        input[type="text"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            margin-bottom: 15px;
        }

        textarea {
            min-height: 200px;
            resize: vertical;
            font-family: inherit;
        }

        textarea[readonly] {
            background: #f9f9f9;
            color: #555;
        }

        #map {
            height: 250px;
            width: 100%;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
        }

        .location-info {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
        }

        input[type="file"] {
            margin-bottom: 15px;
        }

        .buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }

        button[type="submit"] {
            background: #000000;
            color: white;
        }

        button[type="submit"]:hover {
            background: #333333;
        }

        button[type="button"] {
            background: #2196F3;
            color: white;
        }

        button[type="button"]:hover {
            background: #1976D2;
        }

        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .recipient {
            color: #666;
            font-size: 14px;
            margin-top: -10px;
            margin-bottom: 20px;
        }

        .upload-box {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 0;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            margin-bottom: 10px;
        }

        .upload-box:hover {
            border-color: #2196F3;
            background: #f8f9fa;
        }

        .upload-box.dragover {
            border-color: #2196F3;
            background: #e3f2fd;
        }

        .upload-box svg {
            width: 48px;
            height: 48px;
            fill: #999;
            margin-bottom: 10px;
        }

        .upload-box:hover svg {
            fill: #2196F3;
        }

        .upload-box p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        /* Tamil font support */
        .tamil-text {
            font-family: 'Latha', 'TAMu_Kadambri', 'TAMu_Kalyani', 'Helvetica Neue', Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            direction: ltr;
        }
    </style>
</head>

<body>
    <h1>Coimbatore Municipal Corporation</h1>

    <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="complaintForm">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" id="selectedLat" name="lat" value="">
        <input type="hidden" id="selectedLng" name="lng" value="">
        <input type="hidden" id="selectedWard" name="ward" value="">

        <button type="button" onclick="useMyLocation()" style="margin-bottom: 10px;">📌 Use my current location</button>
        <label>Or click on map to select a different location</label>
        <div id="map"></div>
        <div class="location-info" id="locationInfo">No location selected</div>

        <label>Attach images (optional)</label>
        <div class="upload-box" id="uploadBox" onclick="document.getElementById('attachments').click()">
            <div id="uploadPlaceholder">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                </svg>
                <p>Click to upload photos or drag & drop</p>
            </div>
            <div id="imagePreview" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;"></div>
            <input type="file" id="attachments" name="attachments[]" multiple accept="image/*">
        </div>

        <label for="input">Your complaint (in English or Tamil)</label>
        <textarea id="input" required placeholder="Write your complaint here in English or Tamil..." oninput="localStorage.setItem('complaint', this.value)" style="min-height: 100px;"></textarea>

        <button type="button" id="expandBtn" onclick="expandToFormalLetter()">
            ✨ Generate Formal Letter
        </button>

        <label for="complaint" style="margin-top: 15px;">Tamil formal letter (this will be sent)</label>
        <textarea id="complaint" name="complaint" class="tamil-text" placeholder="Tamil formal letter will appear here..." style="min-height: 400px;"><?= htmlspecialchars($complaint ?? '') ?></textarea>

        <label for="expanded" style="margin-top: 15px;">English translation (for your reference)</label>
        <textarea id="expanded" readonly placeholder="English translation will appear here..." style="min-height: 400px;"><?= htmlspecialchars($expanded ?? '') ?></textarea>

        <div class="buttons">
            <button type="button" id="sendBtn" onclick="sendComplaint()">Send to Coimbatore Corporation</button>
        </div>

        <div id="statusMessage" style="margin-top: 15px;"></div>
    </form>

    <script>
        // Initialize map centered on Coimbatore
        const map = L.map('map').setView([<?= $MAP_CENTER_LAT ?>, <?= $MAP_CENTER_LNG ?>], 13);

        const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        });

        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri'
        });

        satellite.addTo(map);

        L.control.layers({
            'Streets': streets,
            'Satellite': satellite
        }).addTo(map);

        async function useMyLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation not supported');
                return;
            }

            document.getElementById('locationInfo').textContent = 'Getting your location...';

            navigator.geolocation.getCurrentPosition(
                async (pos) => {
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;

                        // Center map and zoom in
                        map.setView([lat, lng], 17);

                        // Place or move marker
                        if (marker) {
                            marker.setLatLng([lat, lng]);
                        } else {
                            marker = L.marker([lat, lng]).addTo(map);
                        }

                        // Store coordinates
                        document.getElementById('selectedLat').value = lat;
                        document.getElementById('selectedLng').value = lng;

                        // Fetch address and ward info
                        await fetchLocationDetails(lat, lng);
                    },
                    (err) => {
                        alert('Could not get location. Please allow location access.');
                        document.getElementById('locationInfo').textContent = 'No location selected';
                    }, {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 0
                    }
            );
        }

        // Shared function to fetch address and ward details
        async function fetchLocationDetails(lat, lng) {
            document.getElementById('locationInfo').textContent = 'Getting address...';

            try {
                // Fetch address from Nominatim
                const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`, {
                    headers: {
                        'User-Agent': 'CoimbatoreComplaints/1.0'
                    }
                });
                const data = await resp.json();
                console.log("data123", data);

                let cleanAddress = data.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                cleanAddress = cleanAddress.replace(/,?\s*Ward\s*\d+\s*,?/gi, ', ').replace(/,\s*,/g, ',').replace(/^,\s*|,\s*$/g, '');

                // Fetch ward info from CCMC GeoJSON API
                const wardInfo = await getWardFromCCMC(lat, lng);
                if (wardInfo && wardInfo.wardNo) {
                    selectedAddress = 'Ward ' + wardInfo.wardNo + ', ' + cleanAddress;
                    document.getElementById('locationInfo').textContent = '📌 Ward ' + wardInfo.wardNo + ' - ' + wardInfo.areaName + '\n' + cleanAddress;
                    document.getElementById('selectedWard').value = wardInfo.wardNo;
                    window.selectedWardInfo = wardInfo;
                } else {
                    selectedAddress = cleanAddress;
                    document.getElementById('locationInfo').textContent = '📌 ' + cleanAddress;
                    document.getElementById('selectedWard').value = '';
                    window.selectedWardInfo = null;
                }
            } catch (err) {
                selectedAddress = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                document.getElementById('locationInfo').textContent = '📌 ' + selectedAddress;
                document.getElementById('selectedWard').value = '';
                window.selectedWardInfo = null;
            }
        }

        let marker = null;
        let selectedAddress = '';

        map.on('click', async function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;

            document.getElementById('selectedLat').value = lat;
            document.getElementById('selectedLng').value = lng;

            if (marker) {
                marker.setLatLng(e.latlng);
            } else {
                marker = L.marker(e.latlng).addTo(map);
            }

            // Fetch address and ward info using shared function
            await fetchLocationDetails(lat, lng);
        });

        // Point-in-polygon algorithm (ray casting)
        function isPointInPolygon(point, polygon) {
            const x = point[0],
                y = point[1];
            let inside = false;

            for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                const xi = polygon[i][0],
                    yi = polygon[i][1];
                const xj = polygon[j][0],
                    yj = polygon[j][1];

                if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
                    inside = !inside;
                }
            }

            return inside;
        }

        // Cache for CCMC GeoJSON data
        let ccmcWardData = null;

        // Fetch ward info from CCMC GeoJSON API
        async function getWardFromCCMC(lat, lng) {
            try {
                // Fetch GeoJSON data if not cached
                if (!ccmcWardData) {
                    const response = await fetch('https://ccmc.gov.in/images/Ward_Boundary_Feb_2022.geojson');
                    ccmcWardData = await response.json();
                }

                // Point coordinates [longitude, latitude] - GeoJSON uses lng, lat order
                const point = [lng, lat];

                // Search through all features to find matching ward
                for (const feature of ccmcWardData.features) {
                    const geometry = feature.geometry;
                    const properties = feature.properties;

                    if (geometry.type === 'Polygon') {
                        // Single polygon - coordinates[0] is the outer ring
                        const coordinates = geometry.coordinates[0].map(coord => [coord[0], coord[1]]);
                        if (isPointInPolygon(point, coordinates)) {
                            return parseWardProperties(properties);
                        }
                    } else if (geometry.type === 'MultiPolygon') {
                        // Multiple polygons
                        for (const polygon of geometry.coordinates) {
                            const coordinates = polygon[0].map(coord => [coord[0], coord[1]]);
                            if (isPointInPolygon(point, coordinates)) {
                                return parseWardProperties(properties);
                            }
                        }
                    }
                }

                return null;
            } catch (err) {
                console.error('Error fetching CCMC ward data:', err);
                return null;
            }
        }

        // Parse ward properties from GeoJSON description
        function parseWardProperties(properties) {
            const description = properties.description || '';
            const name = properties.name || '';

            // Extract ward number from description (e.g., "New_Ward_No 1")
            const wardMatch = description.match(/New_Ward_No\s*(\d+)/i);
            const wardNo = wardMatch ? wardMatch[1] : null;

            // Extract area name
            const areaMatch = description.match(/Area_Name\s*([^<]+)/i);
            const areaName = areaMatch ? areaMatch[1].trim() : name;

            // Extract zone
            const zoneMatch = description.match(/^([A-Z]+\s*ZONE)/i);
            const zone = zoneMatch ? zoneMatch[1] : '';

            return {
                wardNo: wardNo,
                areaName: areaName,
                zone: zone,
                fullName: name
            };
        }

        document.getElementById('input').value = localStorage.getItem('complaint') || '';

        const uploadBox = document.getElementById('uploadBox');
        const fileInput = document.getElementById('attachments');

        // Store selected files in an array (FileList is read-only, can't delete from it)
        let selectedFiles = [];

        uploadBox.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadBox.classList.add('dragover');
        });

        uploadBox.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadBox.classList.remove('dragover');
        });

        uploadBox.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadBox.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                addFiles(e.dataTransfer.files);
            }
        });

        // Handle file input change
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                addFiles(e.target.files);
                e.target.value = ''; // Reset so same file can be added again
            }
        });

        function addFiles(files) {
            Array.from(files).forEach(file => {
                if (file.type.startsWith('image/')) {
                    selectedFiles.push(file);
                }
            });
            renderImagePreviews();
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            renderImagePreviews();
        }

        function renderImagePreviews() {
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('uploadPlaceholder');
            preview.textContent = '';

            if (selectedFiles.length > 0) {
                placeholder.style.display = 'none';
                selectedFiles.forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const container = document.createElement('div');
                        container.style.cssText = 'position: relative; display: inline-block; margin: 4px;';

                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.style.cssText = 'width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd; display: block;';

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.textContent = '\u00D7';
                        deleteBtn.style.cssText = 'position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; border: none; background: rgba(0,0,0,0.6); color: white; font-size: 12px; font-weight: bold; cursor: pointer; line-height: 1; display: flex; align-items: center; justify-content: center; padding: 0; transition: background 0.2s;';
                        deleteBtn.title = 'Remove image';
                        deleteBtn.onmouseenter = () => deleteBtn.style.background = 'rgba(220,53,69,0.9)';
                        deleteBtn.onmouseleave = () => deleteBtn.style.background = 'rgba(0,0,0,0.6)';
                        deleteBtn.onclick = (evt) => {
                            evt.stopPropagation();
                            removeFile(index);
                        };

                        container.appendChild(img);
                        container.appendChild(deleteBtn);
                        preview.appendChild(container);
                    };
                    reader.readAsDataURL(file);
                });
            } else {
                placeholder.style.display = 'block';
            }
        }

        function getSelectedFiles() {
            return selectedFiles;
        }

        // Helper function to set button loading state safely (no innerHTML)
        function setButtonLoading(btn, isLoading, loadingText, normalText) {
            btn.disabled = isLoading;
            btn.textContent = isLoading ? loadingText : normalText;

            // Remove existing spinner if any
            const existingSpinner = btn.querySelector('.loading');
            if (existingSpinner) existingSpinner.remove();

            // Add spinner when loading
            if (isLoading) {
                const spinner = document.createElement('span');
                spinner.className = 'loading';
                btn.appendChild(spinner);
            }
        }

        async function expandToFormalLetter() {
            const input = document.getElementById('input').value;
            const hasAttachments = selectedFiles.length > 0;
            const btn = document.getElementById('expandBtn');
            const lat = document.getElementById('selectedLat').value;
            const lng = document.getElementById('selectedLng').value;

            if (!lat || !lng) {
                alert('Please click on the map to set the problem location first.');
                return;
            }

            if (!input.trim()) {
                alert('Please write your complaint first.');
                return;
            }

            setButtonLoading(btn, true, 'Generating with AI...', 'Generate Tamil Letter');

            try {
                const formData = new FormData();
                formData.append('action', 'expand');
                formData.append('csrf_token', document.getElementById('csrf_token').value);
                formData.append('complaint', input);
                formData.append('hasAttachments', hasAttachments);
                formData.append('address', selectedAddress);
                formData.append('lat', document.getElementById('selectedLat').value);
                formData.append('lng', document.getElementById('selectedLng').value);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    const parts = data.expanded.split('===ENGLISH===');
                    document.getElementById('complaint').value = parts[0].trim();
                    document.getElementById('expanded').value = parts[1] ? parts[1].trim() : '';
                } else {
                    alert('Error: ' + (data.error || 'Failed to generate letter'));
                }
            } catch (error) {
                alert('Connection error. Please try again.');
                console.error(error);
            } finally {
                setButtonLoading(btn, false, 'Generating with AI...', 'Generate Tamil Letter');
            }
        }

        async function sendComplaint() {
            const complaint = document.getElementById('complaint').value;
            const statusDiv = document.getElementById('statusMessage');
            const hasAttachments = selectedFiles.length > 0;
            const sendBtn = document.getElementById('sendBtn');

            if (!complaint.trim()) {
                alert('Please generate a formal Tamil letter first.');
                return;
            }

            if (!hasAttachments) {
                if (!confirm('No photo attached. Send anyway?')) {
                    return;
                }
            }

            if (!confirm('Are you sure you want to send this complaint to Coimbatore Municipal Corporation?')) {
                return;
            }

            const formData = new FormData(document.getElementById('complaintForm'));
            formData.set('action', 'send');

            // Add selected files from our managed array
            formData.delete('attachments[]');
            selectedFiles.forEach(file => {
                formData.append('attachments[]', file);
            });

            // Disable button during sending
            setButtonLoading(sendBtn, true, 'Sending...', 'Send to Coimbatore Corporation');
            statusDiv.textContent = 'Sending to Coimbatore Corporation...';
            statusDiv.style.color = '#666';

            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    statusDiv.textContent = data.message;
                    statusDiv.style.color = 'green';
                    document.getElementById('input').value = '';
                    document.getElementById('complaint').value = '';
                    document.getElementById('expanded').value = '';
                    localStorage.removeItem('complaint');
                    // Clear uploaded images
                    selectedFiles = [];
                    renderImagePreviews();
                } else {
                    statusDiv.textContent = data.message;
                    statusDiv.style.color = 'red';
                }
            } catch (error) {
                statusDiv.textContent = 'Connection error. Please try again.';
                statusDiv.style.color = 'red';
                console.error(error);
            } finally {
                // Re-enable button after sending
                setButtonLoading(sendBtn, false, 'Sending...', 'Send to Coimbatore Corporation');
            }
        }
    </script>
</body>

</html>

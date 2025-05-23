<?php
session_start();
$responseData = null;
$error = null;

// Helper to get value from POST or session or default
function get_value($key, $default = '') {
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_SESSION[$key])) return $_SESSION[$key];
    return $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = $_POST['api_key'] ?? '';
    $endpoint = $_POST['endpoint'] ?? 'search';
    $type = $_POST['type'] ?? '';
    $account = $_POST['account'] ?? '';
    $uuid = $_POST['uuid'] ?? '';
    $number_of_records = $_POST['number_of_records'] ?? '';
    $sort_by = $_POST['sort_by'] ?? '';

    // Save to session for persistence
    $_SESSION['api_key'] = $apiKey;
    $_SESSION['endpoint'] = $endpoint;
    $_SESSION['type'] = $type;
    $_SESSION['account'] = $account;
    $_SESSION['uuid'] = $uuid;
    $_SESSION['number_of_records'] = $number_of_records;
    $_SESSION['sort_by'] = $sort_by;

    $url = '';
    if ($endpoint === 'search') {
        $url = 'https://api.heroic.com/v7/breach-search?type=' . urlencode($type) . '&account=' . urlencode($account);
        if (!empty($number_of_records)) {
            $url .= '&number_of_records=' . urlencode($number_of_records);
        }
    } elseif ($endpoint === 'all') {
        $url = 'https://api.heroic.com/v7/breaches';
    } elseif ($endpoint === 'uuid') {
        $url = 'https://api.heroic.com/v7/breaches/' . urlencode($uuid);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = 'Error: ' . curl_error($ch);
    } else {
        $responseData = json_decode($response, true);
        // Sort results if needed
        if ($sort_by && isset($responseData['data']) && is_array($responseData['data'])) {
            usort($responseData['data'], function($a, $b) use ($sort_by) {
                $get = function($item, $field) {
                    // Try both root and breach_details
                    if (isset($item[$field])) return $item[$field];
                    if (isset($item['breach_details'][$field])) return $item['breach_details'][$field];
                    return null;
                };
                $va = $get($a, $sort_by);
                $vb = $get($b, $sort_by);
                if ($sort_by === 'severity') {
                    $order = ['high'=>3, 'medium'=>2, 'low'=>1];
                    $va = strtolower($va ?? '');
                    $vb = strtolower($vb ?? '');
                    return ($order[$vb] ?? 0) <=> ($order[$va] ?? 0); // High > Medium > Low
                } else {
                    // Assume date string, sort descending (newest first)
                    return strtotime($vb) <=> strtotime($va);
                }
            });
        }
    }

    curl_close($ch);
}

// Data overview logic
$records_found = null;
$critical_breaches = 0;
$total_breaches = 0;
if (isset($responseData['data']) && is_array($responseData['data'])) {
    $total_breaches = count($responseData['data']);
    foreach ($responseData['data'] as $item) {
        if (
            (isset($item['breach_details']['severity']) && strtolower($item['breach_details']['severity']) === 'high') ||
            (isset($item['severity']) && strtolower($item['severity']) === 'high')
        ) {
            $critical_breaches++;
        }
    }
} elseif (is_array($responseData) && isset($responseData[0])) {
    $total_breaches = count($responseData);
}

// Read the PHP code for the code viewer tab
$phpCode = file_get_contents(__FILE__);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Heroic API Breach Search Tester</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background: #f5f5f7;
            color: #222;
            font-family: 'Segoe UI', 'Arial', sans-serif;
            min-height: 100vh;
        }
        body {
            display: flex;
            flex-direction: row;
            height: 100vh;
        }
        .sidebar {
            width: 180px;
            background: #f0f0f0;
            border-right: 1px solid #ddd;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 12px 0 0 0;
        }
        .sidebar h1 {
            color: #333;
            font-size: 1.1em;
            margin: 0 0 18px 18px;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .sidebar .nav {
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        .sidebar .nav button {
            background: none;
            border: none;
            color: #444;
            padding: 8px 18px;
            text-align: left;
            font-size: 0.95em;
            cursor: pointer;
        }
        .sidebar .nav button.active, .sidebar .nav button:hover {
            background: #eaeaea;
            color: #111;
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 0;
            min-width: 0;
        }
        .header {
            padding: 16px 18px 0 18px;
            font-size: 1.1em;
            font-weight: 600;
            color: #222;
            letter-spacing: 0.5px;
        }
        .form-section {
            background: #fff;
            margin: 16px 18px 0 18px;
            border: 1px solid #ddd;
            padding: 12px 12px 8px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 0.98em;
        }
        .form-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .form-row label {
            flex: 1 1 120px;
            display: flex;
            flex-direction: column;
            font-size: 0.98em;
            color: #222;
        }
        .form-row input, .form-row select {
            margin-top: 3px;
            padding: 5px 7px;
            border-radius: 0;
            border: 1px solid #bbb;
            background: #fff;
            color: #222;
            font-size: 0.98em;
            outline: none;
            font-family: inherit;
        }
        .form-row input:focus, .form-row select:focus {
            border: 1.5px solid #888;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
        }
        .form-actions button {
            background: #f0f0f0;
            color: #222;
            border: 1px solid #bbb;
            border-radius: 0;
            padding: 6px 18px;
            font-size: 1em;
            font-weight: 400;
            cursor: pointer;
        }
        .form-actions button:hover {
            background: #eaeaea;
        }
        .api-key-eye {
            position: relative;
            display: flex;
            align-items: center;
        }
        .api-key-eye input[type=password],
        .api-key-eye input[type=text] {
            flex: 1;
        }
        .api-key-eye .eye-icon {
            width: 22px;
            height: 22px;
            margin-left: -28px;
            cursor: pointer;
            fill: #888;
            transition: fill 0.2s;
        }
        .api-key-eye .eye-icon:hover {
            fill: #222;
        }
        .overview {
            margin: 16px 18px 0 18px;
            background: #fff;
            border: 1px solid #ddd;
            padding: 10px 12px 8px 12px;
            color: #222;
            font-size: 0.98em;
        }
        .overview h3 {
            margin-top: 0;
            font-size: 1.05em;
            color: #444;
        }
        .overview-stats {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .overview-stat {
            background: #f5f5f7;
            border: 1px solid #eee;
            padding: 8px 14px;
            min-width: 100px;
            text-align: center;
            font-family: 'Consolas', 'Menlo', 'Monaco', monospace;
        }
        .overview-stat .label {
            color: #888;
            font-size: 0.93em;
        }
        .overview-stat .value {
            font-size: 1.1em;
            font-weight: 700;
            color: #222;
            margin-top: 2px;
        }
        .response {
            margin: 16px 18px 0 18px;
            background: #fff;
            border: 1px solid #ddd;
            padding: 8px 12px 8px 12px;
            color: #222;
            font-family: 'Consolas', 'Menlo', 'Monaco', monospace;
            font-size: 0.97em;
            overflow-x: scroll;
            min-height: fit-content;
        }
        .error {
            color: #b00020;
            margin: 16px 18px 0 18px;
            background: #fff;
            border: 1px solid #fbb;
            padding: 8px 12px;
            font-size: 1em;
        }
        .code-section {
            font-family: inherit;
            font-size: 0.96em;
            line-height: 1.3;
            max-width: 800px;
            padding: 4px 8px 4px 8px;
        }
        .code-section h2, .code-section h3 {
            margin-top: 10px;
            margin-bottom: 6px;
            font-size: 1em;
        }
        .code-section ul, .code-section pre, .code-section p {
            margin-top: 4px;
            margin-bottom: 8px;
        }
        .code-section pre {
            font-size: 0.95em;
            padding: 4px 6px;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .sort-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 8px 0;
            font-size: 0.98em;
        }
        .sort-row label {
            color: #444;
            font-size: 0.98em;
        }
        .sort-row select {
            padding: 3px 7px;
            border: 1px solid #bbb;
            background: #fff;
            color: #222;
            font-size: 0.98em;
            border-radius: 0;
            font-family: inherit;
        }
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { padding: 0; }
            .form-section, .overview, .response, .error, .code-section { margin: 12px 4px 0 4px; }
        }
    </style>
    <script>
    // Persist form fields in localStorage
    function saveFormToStorage() {
        const fields = ['api_key','endpoint','type','account','uuid','number_of_records','sort_by'];
        fields.forEach(f => {
            const el = document.querySelector(`[name="${f}"]`);
            if (el) localStorage.setItem('heroic_' + f, el.value);
        });
    }
    function loadFormFromStorage() {
        const fields = ['api_key','endpoint','type','account','uuid','number_of_records','sort_by'];
        fields.forEach(f => {
            const el = document.querySelector(`[name="${f}"]`);
            if (el && localStorage.getItem('heroic_' + f)) {
                el.value = localStorage.getItem('heroic_' + f);
            }
        });
        updateForm();
    }
    function updateForm() {
        var endpoint = document.getElementById('endpoint').value;
        document.getElementById('search-fields').style.display = endpoint === 'search' ? 'flex' : 'none';
        document.getElementById('uuid-field').style.display = endpoint === 'uuid' ? 'flex' : 'none';
    }
    // Tab switching
    function showTab(tab) {
        document.getElementById('tab-api').classList.remove('active');
        document.getElementById('tab-docs').classList.remove('active');
        document.getElementById('tab-code').classList.remove('active');
        document.getElementById('tabcontent-api').classList.remove('active');
        document.getElementById('tabcontent-docs').classList.remove('active');
        document.getElementById('tabcontent-code').classList.remove('active');
        document.getElementById('tab-' + tab).classList.add('active');
        document.getElementById('tabcontent-' + tab).classList.add('active');
    }
    // Eye icon toggle for API key
    function toggleApiKeyVisibility() {
        var input = document.getElementById('api-key-input');
        var icon = document.getElementById('api-key-eye-icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = eyeOpenSvg;
        } else {
            input.type = 'password';
            icon.innerHTML = eyeClosedSvg;
        }
    }
    // SVGs for eye icon
    const eyeOpenSvg = '<svg class="eye-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12c-4.97 0-8-4.03-8-5s3.03-5 8-5 8 4.03 8 5-3.03 5-8 5z"/></svg>';
    const eyeClosedSvg = '<svg class="eye-icon" viewBox="0 0 24 24"><path d="M1 1l22 22M4.22 4.22A9.77 9.77 0 0 0 2 12s3 7 10 7c2.39 0 4.5-.66 6.28-1.78M9.5 9.5a3.5 3.5 0 0 1 5 5"/><path d="M12 5c7 0 10 7 10 7s-3 7-10 7c-2.39 0-4.5-.66-6.28-1.78"/></svg>';
    window.addEventListener('DOMContentLoaded', function() {
        loadFormFromStorage();
        // Set initial tab
        showTab('api');
        // Set initial eye icon
        document.getElementById('api-key-eye-icon').innerHTML = eyeClosedSvg;
    });
    </script>
</head>
<body>
    <div class="sidebar">
        <h1>HEROIC</h1>
        <div class="nav">
            <button id="tab-api" class="active" onclick="showTab('api')">API Tester</button>
            <button id="tab-docs" onclick="showTab('docs')">Documentation</button>
            <button id="tab-code" onclick="showTab('code')">PHP Code</button>
        </div>
    </div>
    <div class="main">
        <div class="header">API Management Console</div>
        <div id="tabcontent-api" class="tab-content active">
            <form class="form-section" method="post" onsubmit="saveFormToStorage()">
                <div class="form-row">
                    <label>API Key
                        <span class="api-key-eye">
                            <input type="password" id="api-key-input" name="api_key" required placeholder="Enter your API key" value="<?= htmlspecialchars(get_value('api_key')) ?>">
                            <span id="api-key-eye-icon" onclick="toggleApiKeyVisibility()" tabindex="0" style="user-select:none;"></span>
                        </span>
                    </label>
                    <label>Endpoint
                        <select name="endpoint" id="endpoint" onchange="updateForm()">
                            <option value="search" <?= get_value('endpoint','search')==='search'?'selected':'' ?>>Search (email, domain, etc)</option>
                            <option value="all" <?= get_value('endpoint')==='all'?'selected':'' ?>>All Breaches</option>
                            <option value="uuid" <?= get_value('endpoint')==='uuid'?'selected':'' ?>>Breach by UUID</option>
                        </select>
                    </label>
                </div>
                <div class="form-row" id="search-fields">
                    <label>Type
                        <select name="type">
                            <option value="email" <?= get_value('type')==='email'?'selected':'' ?>>Email</option>
                            <option value="email_domain" <?= get_value('type')==='email_domain'?'selected':'' ?>>Email Domain</option>
                            <option value="phonenumber" <?= get_value('type')==='phonenumber'?'selected':'' ?>>Phone Number</option>
                            <option value="username" <?= get_value('type')==='username'?'selected':'' ?>>Username</option>
                            <option value="ipaddress" <?= get_value('type')==='ipaddress'?'selected':'' ?>>IP Address</option>
                        </select>
                    </label>
                    <label>Account
                        <input type="text" name="account" placeholder="Enter value to search" value="<?= htmlspecialchars(get_value('account')) ?>">
                    </label>
                    <label>Number of Records (optional)
                        <input type="number" name="number_of_records" min="1" placeholder="e.g. 100" value="<?= htmlspecialchars(get_value('number_of_records')) ?>">
                    </label>
                </div>
                <div class="form-row" id="uuid-field" style="display:none;">
                    <label>UUID
                        <input type="text" name="uuid" placeholder="Enter breach UUID" value="<?= htmlspecialchars(get_value('uuid')) ?>">
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit">Send Request</button>
                </div>
            </form>

            <?php if ($responseData): ?>
                <div class="overview">
                    <h3>Data Overview</h3>
                    <div class="overview-stats">
                        <?php if ($records_found !== null): ?>
                            <div class="overview-stat">
                                <div class="label">Total Records Found</div>
                                <div class="value"><?= htmlspecialchars($records_found) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($total_breaches): ?>
                            <div class="overview-stat">
                                <div class="label">Breaches Returned</div>
                                <div class="value"><?= htmlspecialchars($total_breaches) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($critical_breaches): ?>
                            <div class="overview-stat">
                                <div class="label">Critical Breaches</div>
                                <div class="value" style="color:#b00020;"> <?= htmlspecialchars($critical_breaches) ?> </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sort-row" style="margin-top:8px;">
                    <label for="sort_by">Sort by:</label>
                    <form method="post" style="display:inline;" onsubmit="saveFormToStorage()">
                        <select name="sort_by" id="sort_by" onchange="this.form.submit()">
                            <option value="">None</option>
                            <option value="date_leaked" <?= get_value('sort_by')==='date_leaked'?'selected':'' ?>>Date Leaked</option>
                            <option value="created_at" <?= get_value('sort_by')==='created_at'?'selected':'' ?>>Created At</option>
                            <option value="severity" <?= get_value('sort_by')==='severity'?'selected':'' ?>>Severity</option>
                        </select>
                        <?php // Keep all other form fields as hidden inputs for persistence
                        $fields = ['api_key','endpoint','type','account','uuid','number_of_records'];
                        foreach ($fields as $f) {
                            echo '<input type="hidden" name="'.$f.'" value="'.htmlspecialchars(get_value($f)).'">';
                        }
                        ?>
                    </form>
                </div>
                <div class="response">
                    <h3>API Response</h3>
                    <pre><?= htmlspecialchars(json_encode($responseData, JSON_PRETTY_PRINT)) ?></pre>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>
        <div id="tabcontent-docs" class="tab-content">
            <div class="code-section">
                <h2>Build Your Own HEROIC API Tool</h2>
                <p>This page is for developers who want to build their own integrations or tools using the HEROIC API. Below you'll find the essentials for authenticating, making requests, and handling responses in your own code.</p>
                <h3>Quick Start</h3>
                <pre>1. Get your API key from your HEROIC account.
2. Make HTTP requests to the HEROIC API endpoints with your API key in the <b>x-api-key</b> header.
3. Parse the JSON response in your application.
</pre>
                <h3>Authentication</h3>
                <pre>Header: x-api-key: YOUR_API_KEY</pre>
                <h3>Making Requests</h3>
                <pre># Search for breaches by email, domain, etc.
GET https://api.heroic.com/v7/breach-search?type=email&account=user@domain.com

# Get all breaches
GET https://api.heroic.com/v7/breaches

# Get breach details by UUID
GET https://api.heroic.com/v7/breaches/{uuid}
</pre>
                <h3>Example: PHP (cURL)</h3>
                <pre>$apiKey = 'YOUR_API_KEY';
$url = 'https://api.heroic.com/v7/breach-search?type=email&account=user@domain.com';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-api-key: ' . $apiKey
]);
$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);
</pre>
                <h3>Best Practices</h3>
                <ul>
                    <li>Always keep your API key secure. Never expose it in public code repositories.</li>
                    <li>Handle errors and check for HTTP status codes in your integration.</li>
                    <li>Paginate results if you expect large datasets (use <b>number_of_records</b> and <b>pagination_token</b> if supported).</li>
                    <li>Cache results where possible to avoid unnecessary API calls.</li>
                </ul>
                <h3>More Info</h3>
                <p>For full API reference, see <a href="https://doc.api.heroic.com" target="_blank">https://doc.api.heroic.com</a></p>
            </div>
        </div>
        <div id="tabcontent-code" class="tab-content">
            <div class="code-section">
                <?= htmlspecialchars($phpCode) ?>
            </div>
        </div>
    </div>
</body>
</html> 
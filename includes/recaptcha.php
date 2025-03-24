<?php
/*
 * Modern PHP library for Google reCAPTCHA v2
 * - Documentation: https://developers.google.com/recaptcha/
 * - Get API Keys: https://www.google.com/recaptcha/admin/create
 * - Discussion group: https://groups.google.com/g/recaptcha
 *
 * Copyright (c) 2007 reCAPTCHA, updated 2025 by xAI
 * Original AUTHORS: Mike Crawford, Ben Maurer
 * License: MIT (see original license terms above)
 */

/**
 * reCAPTCHA v2 Configuration
 */
class ReCaptchaConfig {
    const SITE_KEY = 'your-site-key-here';    // Replace with your Site Key
    const SECRET_KEY = 'your-secret-key-here'; // Replace with your Secret Key
    const API_ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';
}

/**
 * ReCaptcha Response Object
 */
class ReCaptchaResponse {
    public $success;
    public $error_codes;
    public $timestamp;
    public $hostname;
    
    public function __construct() {
        $this->success = false;
        $this->error_codes = [];
        $this->timestamp = null;
        $this->hostname = null;
    }
}

/**
 * Gets the reCAPTCHA v2 HTML widget
 * @param string $site_key Public site key (optional, uses config if null)
 * @param string $theme Theme option: 'light' or 'dark' (optional)
 * @param string $size Size option: 'normal' or 'compact' (optional)
 * @return string HTML widget code
 */
function recaptcha_get_html($site_key = null, $theme = 'light', $size = 'normal') {
    $site_key = $site_key ?: ReCaptchaConfig::SITE_KEY;
    
    if (empty($site_key)) {
        return '<div class="recaptcha-error">Error: reCAPTCHA site key not configured. Please obtain one from <a href="https://www.google.com/recaptcha/admin/create">Google reCAPTCHA</a></div>';
    }

    return <<<HTML
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <div class="g-recaptcha" 
         data-sitekey="{$site_key}" 
         data-theme="{$theme}" 
         data-size="{$size}">
    </div>
HTML;
}

/**
 * Verifies reCAPTCHA v2 response
 * @param string $response User's reCAPTCHA response from POST
 * @param string $remote_ip User's IP address
 * @param string $secret_key Secret key (optional, uses config if null)
 * @return ReCaptchaResponse Verification result
 */
function recaptcha_verify($response, $remote_ip, $secret_key = null) {
    $secret_key = $secret_key ?: ReCaptchaConfig::SECRET_KEY;
    $recaptcha_response = new ReCaptchaResponse();
    
    if (empty($secret_key)) {
        $recaptcha_response->error_codes[] = 'missing-secret-key';
        return $recaptcha_response;
    }
    
    if (empty($response) || empty($remote_ip)) {
        $recaptcha_response->error_codes[] = 'missing-input';
        return $recaptcha_response;
    }

    $params = [
        'secret' => $secret_key,
        'response' => $response,
        'remoteip' => $remote_ip
    ];

    // Use cURL for modern HTTP requests
    $ch = curl_init(ReCaptchaConfig::API_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        $recaptcha_response->error_codes[] = 'connection-error';
        return $recaptcha_response;
    }

    $result = json_decode($response, true);
    
    if ($result && isset($result['success'])) {
        $recaptcha_response->success = $result['success'];
        $recaptcha_response->timestamp = $result['challenge_ts'] ?? null;
        $recaptcha_response->hostname = $result['hostname'] ?? null;
        
        if (!$result['success'] && isset($result['error-codes'])) {
            $recaptcha_response->error_codes = $result['error-codes'];
        }
    } else {
        $recaptcha_response->error_codes[] = 'invalid-response';
    }

    return $recaptcha_response;
}

/**
 * Helper function to get client IP address
 * @return string Client IP address
 */
function recaptcha_get_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Example usage function
 * @return string Example implementation
 */
function recaptcha_example_usage() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['g-recaptcha-response'])) {
        $response = recaptcha_verify(
            $_POST['g-recaptcha-response'],
            recaptcha_get_client_ip()
        );
        
        if ($response->success) {
            return "reCAPTCHA verification successful!";
        } else {
            return "reCAPTCHA verification failed: " . implode(', ', $response->error_codes);
        }
    }
    
    return recaptcha_get_html() . '
    <form method="post">
        <button type="submit">Submit</button>
    </form>';
}

// Remove outdated Mailhide functionality as it's deprecated
// Remove old AES encryption code as it's no longer needed
?>
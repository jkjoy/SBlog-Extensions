<?php
declare(strict_types=1);

/** Providers are code-defined: credentials never determine outbound endpoints. */
function sns_providers(): array
{
    $providers = [
        'github' => [
            'slug' => 'github', 'name' => 'GitHub',
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'profile_url' => 'https://api.github.com/user',
            'scope' => 'read:user', 'pkce' => true, 'token_auth' => 'body',
        ],
        'google' => [
            'slug' => 'google', 'name' => 'Google',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'profile_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid profile email', 'pkce' => true, 'token_auth' => 'body',
        ],
        'linuxdo' => [
            'slug' => 'linuxdo', 'name' => 'Linux.do',
            'authorize_url' => 'https://connect.linux.do/oauth2/authorize',
            'token_url' => 'https://connect.linux.do/oauth2/token',
            'profile_url' => 'https://connect.linux.do/api/user',
            'scope' => '', 'pkce' => false, 'token_auth' => 'basic',
        ],
        'qq' => [
            'slug' => 'qq', 'name' => 'QQ',
            'authorize_url' => 'https://graph.qq.com/oauth2.0/authorize',
            'token_url' => 'https://graph.qq.com/oauth2.0/token',
            'profile_url' => 'https://graph.qq.com/user/get_user_info',
            'scope' => 'get_user_info', 'pkce' => false, 'token_auth' => 'body',
        ],
    ];
    $extended = function_exists('plugin_filter') ? plugin_filter('sns_login_providers', $providers, []) : $providers;
    if (!is_array($extended)) {
        return $providers;
    }
    foreach ($extended as $slug => $provider) {
        // Keep the audited builtins immutable. Other plugins may append adapters.
        if (!is_string($slug) || isset($providers[$slug]) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $slug)
            || !is_array($provider) || !is_callable($provider['adapter'] ?? null)) {
            continue;
        }
        try {
            foreach (['authorize_url', 'token_url', 'profile_url'] as $field) {
                sns_endpoint_parts((string)($provider[$field] ?? ''));
            }
        } catch (RuntimeException $exception) {
            continue;
        }
        $name = trim((string)($provider['name'] ?? $slug));
        $scope = (string)($provider['scope'] ?? '');
        if ($name === '' || strlen($name) > 100 || strlen($scope) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $scope)) {
            continue;
        }
        $provider['slug'] = $slug;
        $provider['name'] = $name;
        $provider['scope'] = $scope;
        $provider['pkce'] = (bool)($provider['pkce'] ?? true);
        $provider['token_auth'] = ($provider['token_auth'] ?? 'body') === 'basic' ? 'basic' : 'body';
        $providers[$slug] = $provider;
    }
    return $providers;
}

function sns_authorization_url(array $provider, array $config, string $redirectUri, string $state, string $verifier): string
{
    sns_endpoint_parts((string)($provider['authorize_url'] ?? ''));
    $clientId = sns_client_credential($config, 'client_id');
    $parameters = [
        'response_type' => 'code', 'client_id' => $clientId,
        'redirect_uri' => $redirectUri, 'state' => $state,
    ];
    if (($provider['scope'] ?? '') !== '') {
        $parameters['scope'] = (string)$provider['scope'];
    }
    if (!empty($provider['pkce'])) {
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            throw new RuntimeException('登录验证参数无效，请重新发起登录。');
        }
        $parameters['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $parameters['code_challenge_method'] = 'S256';
    }
    if (($provider['slug'] ?? '') === 'google') {
        $parameters['prompt'] = 'select_account';
    }
    return sns_url_query((string)$provider['authorize_url'], $parameters);
}

function sns_fetch_identity(array $provider, array $config, string $redirectUri, string $code, string $verifier): array
{
    $clientId = sns_client_credential($config, 'client_id');
    $clientSecret = sns_client_credential($config, 'client_secret');
    if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $code)) {
        throw new RuntimeException('授权码无效，请重新发起登录。');
    }
    if (is_callable($provider['adapter'] ?? null)) {
        try {
            $identity = ($provider['adapter'])($provider, $config, $redirectUri, $code, $verifier);
            return sns_normalize_identity(is_array($identity) ? $identity : []);
        } catch (Throwable $exception) {
            throw new RuntimeException('第三方账号身份验证失败，请稍后重试。');
        }
    }

    $fields = [
        'grant_type' => 'authorization_code', 'code' => $code,
        'redirect_uri' => $redirectUri, 'client_id' => $clientId,
    ];
    $headers = ['Accept' => 'application/json'];
    if (!empty($provider['pkce'])) {
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            throw new RuntimeException('登录验证参数无效，请重新发起登录。');
        }
        $fields['code_verifier'] = $verifier;
    }
    if (($provider['token_auth'] ?? 'body') === 'basic') {
        $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret));
        unset($fields['client_id']);
    } else {
        $fields['client_secret'] = $clientSecret;
    }
    $slug = (string)($provider['slug'] ?? '');
    $method = $slug === 'qq' ? 'GET' : 'POST';
    if ($slug === 'qq') {
        $fields['fmt'] = 'json';
    }
    $raw = sns_http_request($method, (string)$provider['token_url'], $headers, $fields);
    $tokenResponse = $slug === 'qq' ? sns_parse_qq_response($raw) : sns_json_object($raw);
    $token = $tokenResponse['access_token'] ?? null;
    if (array_key_exists('error', $tokenResponse) || !is_string($token) || $token === '' || strlen($token) > 8192
        || preg_match('/[\x00-\x20\x7f]/', $token)
        || (isset($tokenResponse['token_type']) && strcasecmp((string)$tokenResponse['token_type'], 'bearer') !== 0)) {
        throw new RuntimeException('第三方授权失败，请重新发起登录。');
    }

    $extra = [];
    if ($slug === 'qq') {
        $extra['openid'] = sns_parse_qq_response(sns_http_request('GET', 'https://graph.qq.com/oauth2.0/me', [], [
            'access_token' => $token, 'fmt' => 'json',
        ]));
        $extra['client_id'] = $clientId;
        // Validate the token's app binding before sending it to the profile API.
        sns_qq_subject($extra['openid'], $clientId);
        $profile = sns_json_object(sns_http_request('GET', (string)$provider['profile_url'], [], [
            'access_token' => $token, 'oauth_consumer_key' => $clientId, 'openid' => $extra['openid']['openid'],
        ]));
    } else {
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($slug === 'github') {
            $headers['X-GitHub-Api-Version'] = '2022-11-28';
        }
        $profile = sns_json_object(sns_http_request('GET', (string)$provider['profile_url'], $headers));
    }
    // Google identity comes from authenticated userinfo, never an unverified JWT.
    return sns_provider_identity($provider, $profile, $extra);
}

function sns_provider_identity(array $provider, array $profile, array $extra = []): array
{
    $slug = (string)($provider['slug'] ?? '');
    $identity = ['subject' => '', 'name' => '', 'email' => '', 'email_verified' => false, 'avatar' => '', 'url' => ''];
    if (array_key_exists('error', $profile)) {
        throw new RuntimeException('第三方账号资料响应无效，请稍后重试。');
    }
    switch ($slug) {
        case 'github':
            $identity['subject'] = sns_numeric_subject($profile['id'] ?? null);
            $identity['name'] = $profile['name'] ?? $profile['login'] ?? '';
            $identity['avatar'] = $profile['avatar_url'] ?? '';
            $identity['url'] = $profile['html_url'] ?? '';
            $verified = [];
            foreach ($extra['emails'] ?? [] as $email) {
                if (is_array($email) && ($email['verified'] ?? false) === true && is_string($email['email'] ?? null)
                    && filter_var($email['email'], FILTER_VALIDATE_EMAIL)) {
                    $verified[] = $email;
                }
            }
            usort($verified, static fn(array $a, array $b): int => (int)($b['primary'] ?? false) <=> (int)($a['primary'] ?? false));
            if ($verified !== []) {
                $identity['email'] = $verified[0]['email'];
                $identity['email_verified'] = true;
            }
            break;
        case 'google':
            $identity['subject'] = is_string($profile['sub'] ?? null) ? $profile['sub'] : '';
            $identity['name'] = $profile['name'] ?? '';
            $identity['email'] = $profile['email'] ?? '';
            $identity['email_verified'] = ($profile['email_verified'] ?? false) === true;
            $identity['avatar'] = $profile['picture'] ?? '';
            break;
        case 'linuxdo':
            $identity['subject'] = sns_numeric_subject($profile['id'] ?? null);
            if (($profile['active'] ?? true) === false || ($profile['silenced'] ?? false) === true) {
                throw new RuntimeException('该第三方账号当前不可用于登录。');
            }
            $identity['name'] = $profile['name'] ?? $profile['username'] ?? '';
            $avatar = is_string($profile['avatar_template'] ?? null) ? str_replace('{size}', '96', $profile['avatar_template']) : '';
            $identity['avatar'] = str_starts_with($avatar, '/') && !str_starts_with($avatar, '//') ? 'https://linux.do' . $avatar : $avatar;
            if (is_string($profile['username'] ?? null) && $profile['username'] !== '') {
                $identity['url'] = 'https://linux.do/u/' . rawurlencode($profile['username']);
            }
            break;
        case 'qq':
            if (!isset($profile['ret']) || !in_array($profile['ret'], [0, '0'], true)) {
                throw new RuntimeException('QQ 账号资料获取失败，请稍后重试。');
            }
            $identity['subject'] = sns_qq_subject((array)($extra['openid'] ?? []), (string)($extra['client_id'] ?? ''));
            $identity['name'] = $profile['nickname'] ?? '';
            $avatar = $profile['figureurl_qq_2'] ?? $profile['figureurl_qq_1'] ?? $profile['figureurl_2'] ?? '';
            // QQ's documented avatar fields may still use an HTTP scheme.
            $identity['avatar'] = is_string($avatar) ? preg_replace('#^http://#i', 'https://', $avatar) : '';
            break;
        default:
            throw new RuntimeException('此登录服务需要提供账号适配器。');
    }
    return sns_normalize_identity($identity);
}

function sns_numeric_subject(mixed $subject): string
{
    if ((!is_int($subject) && !is_string($subject)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string)$subject)) {
        throw new RuntimeException('第三方账号缺少稳定的身份标识。');
    }
    return (string)$subject;
}

function sns_qq_subject(array $openid, string $clientId): string
{
    if ($clientId === '' || !is_string($openid['client_id'] ?? null) || !hash_equals($clientId, $openid['client_id'])
        || !is_string($openid['openid'] ?? null) || !preg_match('/^[a-fA-F0-9]{32}$/D', $openid['openid'])) {
        throw new RuntimeException('QQ 账号身份验证失败，请重新发起登录。');
    }
    return $openid['openid'];
}

function sns_normalize_identity(array $identity): array
{
    $subject = $identity['subject'] ?? '';
    if ((!is_int($subject) && !is_string($subject)) || (string)$subject === '' || strlen((string)$subject) > 255
        || !preg_match('//u', (string)$subject) || preg_match('/[\x00-\x20\x7f]/', (string)$subject)) {
        throw new RuntimeException('第三方账号缺少稳定的身份标识。');
    }
    $name = is_string($identity['name'] ?? null) ? trim($identity['name']) : '';
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
    if (!preg_match('//u', $name)) {
        $name = '';
    }
    $name = function_exists('mb_strcut') ? mb_strcut($name, 0, 160, 'UTF-8') : $name;
    if (strlen($name) > 160) {
        $name = '';
    }
    $email = is_string($identity['email'] ?? null) ? trim($identity['email']) : '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    return [
        'subject' => (string)$subject, 'name' => $name, 'email' => $email,
        'email_verified' => $email !== '' && ($identity['email_verified'] ?? false) === true,
        'avatar' => sns_profile_url($identity['avatar'] ?? ''), 'url' => sns_profile_url($identity['url'] ?? ''),
    ];
}

function sns_profile_url(mixed $url): string
{
    if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return '';
    }
    try {
        sns_endpoint_parts($url);
        return $url;
    } catch (RuntimeException $exception) {
        return '';
    }
}

function sns_client_credential(array $config, string $key): string
{
    $value = $config[$key] ?? '';
    if (!is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new RuntimeException('登录服务尚未配置完整，请联系管理员。');
    }
    return $value;
}

function sns_json_decode(string $body): array
{
    try {
        $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException $exception) {
        throw new RuntimeException('第三方服务返回了无效资料，请稍后重试。');
    }
    if (!is_array($result)) {
        throw new RuntimeException('第三方服务返回了无效资料，请稍后重试。');
    }
    return $result;
}

function sns_json_object(string $body): array
{
    if (!str_starts_with(ltrim($body), '{')) {
        throw new RuntimeException('第三方服务返回了无效资料，请稍后重试。');
    }
    return sns_json_decode($body);
}

function sns_parse_qq_response(string $body): array
{
    $body = trim($body);
    if (str_starts_with($body, '{')) {
        $result = sns_json_object($body);
    } elseif (preg_match('/\Acallback\s*\(\s*(\{.*\})\s*\)\s*;?\s*\z/s', $body, $match)) {
        $result = sns_json_object($match[1]);
    } else {
        $result = [];
        foreach (explode('&', $body) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            if (count($parts) !== 2 || !preg_match('/^[a-z_]+$/D', $key) || array_key_exists($key, $result)) {
                throw new RuntimeException('QQ 服务返回了无效资料，请稍后重试。');
            }
            $result[$key] = urldecode($parts[1]);
        }
    }
    if (array_key_exists('error', $result)) {
        throw new RuntimeException('QQ 授权失败，请重新发起登录。');
    }
    return $result;
}

function sns_url_query(string $url, array $fields): string
{
    if ($fields === []) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

/** Only domain-based public HTTPS endpoints on the standard TLS port. */
function sns_endpoint_parts(string $url): array
{
    $parts = parse_url($url);
    if (strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
        throw new RuntimeException('第三方服务地址不合法。');
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
        || preg_match('/\.(?:localhost|local|internal|home|test|invalid|example|localdomain)$/D', $host)) {
        throw new RuntimeException('第三方服务地址不合法。');
    }
    $parts['host'] = $host;
    return $parts;
}

function sns_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
        return false;
    }
    if (str_contains($ip, ':')) {
        $packed = inet_pton($ip);
        // Exclude IPv4 translation/tunnelling ranges, even if PHP considers them global.
        return $packed !== false && (ord($packed[0]) & 0xe0) === 0x20
            && substr($packed, 0, 2) !== "\x20\x02";
    }
    return true;
}

function sns_public_endpoint_ip(string $host): string
{
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    $addresses = [];
    foreach (is_array($records) ? $records : [] as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? '';
        if ($ip === '') {
            continue;
        }
        if (!sns_public_ip($ip)) {
            throw new RuntimeException('第三方服务地址不允许访问。');
        }
        $addresses[] = $ip;
    }
    if ($addresses === []) {
        throw new RuntimeException('暂时无法连接第三方服务，请稍后重试。');
    }
    // Prefer IPv4 for servers without IPv6 connectivity; every DNS answer is checked.
    usort($addresses, static fn(string $a, string $b): int => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));
    return $addresses[0];
}

function sns_http_request(string $method, string $url, array $headers = [], array $fields = []): string
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new RuntimeException('第三方服务请求方式不合法。');
    }
    $url = $method === 'GET' ? sns_url_query($url, $fields) : $url;
    $parts = sns_endpoint_parts($url);
    $host = $parts['host'];
    $ip = sns_public_endpoint_ip($host);
    $lines = ['User-Agent: SBlog-SNS-Login/1.0', 'Accept: application/json', 'Connection: close'];
    foreach ($headers as $key => $value) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9-]+$/D', $key) || !is_string($value)
            || preg_match('/[\x00-\x1f\x7f]/', $value) || in_array(strtolower($key), ['host', 'content-length', 'connection'], true)) {
            throw new RuntimeException('第三方服务请求参数不合法。');
        }
        $lines[] = $key . ': ' . $value;
    }
    $body = $method === 'POST' ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986) : '';
    if ($method === 'POST') {
        $lines[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $response = '';
    $status = 0;
    $maximum = 1048576;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('暂时无法连接第三方服务，请稍后重试。');
        }
        $resolvedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host . ':443:' . $resolvedIp],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maximum): int {
                if (strlen($response) + strlen($chunk) > $maximum) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $success = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($success === false) {
            throw new RuntimeException('暂时无法连接第三方服务，请稍后重试。');
        }
    } else {
        // Pin the already-validated address; peer_name supplies TLS verification and SNI.
        $authority = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $pinnedUrl = 'https://' . $authority . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $lines[] = 'Host: ' . $host;
        $context = stream_context_create([
            'http' => [
                'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body,
                'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true],
        ]);
        $stream = @fopen($pinnedUrl, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('暂时无法连接第三方服务，请稍后重试。');
        }
        $started = microtime(true);
        while (!feof($stream)) {
            if (microtime(true) - $started > 20) {
                fclose($stream);
                throw new RuntimeException('第三方服务响应超时，请稍后重试。');
            }
            $chunk = @fread($stream, min(8192, $maximum + 1 - strlen($response)));
            if ($chunk === false || strlen($response) + strlen($chunk) > $maximum || stream_get_meta_data($stream)['timed_out']) {
                fclose($stream);
                throw new RuntimeException('第三方服务响应无效，请稍后重试。');
            }
            $response .= $chunk;
        }
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        foreach ($metadata['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#', $line, $match)) {
                $status = (int)$match[1];
            }
        }
    }
    if ($status < 200 || $status >= 300 || $response === '') {
        throw new RuntimeException('第三方服务暂时不可用，请稍后重试。');
    }
    return $response;
}

<?php
declare(strict_types=1);

final class GambioApiClient
{
    private string $baseUrl;
    private string $apiVer; // 'v2' | 'v3'
    private ?string $basicUser;
    private ?string $basicPass;
    private ?string $jwtToken;

    public function __construct(array $cfg)
    {
        $this->baseUrl   = rtrim($cfg['baseUrl'] ?? '', '/');
        $this->apiVer    = in_array($cfg['apiVersion'] ?? 'v2', ['v2','v3'], true) ? $cfg['apiVersion'] : 'v2';
        $this->basicUser = $cfg['basicUser'] ?? null;
        $this->basicPass = $cfg['basicPass'] ?? null;
        $this->jwtToken  = $cfg['jwt'] ?? null;
    }

    public function withVersion(string $apiVersion): self
    {
        $clone = clone $this;
        $clone->apiVer = in_array($apiVersion, ['v2','v3'], true) ? $apiVersion : $this->apiVer;
        return $clone;
    }

    public function getApiVersion(): string { return $this->apiVer; }

    private function endpoint(string $path): string
    {
        return sprintf('%s/api.php/%s/%s', $this->baseUrl, $this->apiVer, ltrim($path, '/'));
    }

    public function request(string $method, string $path, array $query = [], $body = null, array $headers = []): array
    {
        $url = $this->endpoint($path);
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $ch = curl_init($url);
        $httpHeaders = [
            'Accept: application/json',
        ];
        // Auth
        if ($this->jwtToken && $this->apiVer === 'v3') {
            $httpHeaders[] = 'Authorization: Bearer ' . $this->jwtToken;
        } elseif ($this->basicUser !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->basicUser . ':' . $this->basicPass);
        }

        if ($body !== null && !is_string($body)) {
            $httpHeaders[] = 'Content-Type: application/json';
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($headers as $h) { $httpHeaders[] = $h; }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_HTTPHEADER     => $httpHeaders,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $info  = curl_getinfo($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("HTTP Error: $err ($errno)");
        }

        $status = (int)($info['http_code'] ?? 0);
        $json   = json_decode((string)$resp, true);
        if ($status >= 400) {
            $msg = is_array($json) ? ($json['message'] ?? json_encode($json)) : (string)$resp;
            throw new RuntimeException("API {$this->apiVer} {$method} {$path} failed: HTTP $status: $msg");
        }
        return is_array($json) ? $json : [];
    }

    public function get(string $path, array $q = []): array      { return $this->request('GET', $path, $q); }
    public function post(string $path, $body = null, array $q = []): array { return $this->request('POST', $path, $q, $body); }
    public function put(string $path, $body = null, array $q = []): array  { return $this->request('PUT', $path, $q, $body); }
    public function patch(string $path, $body = null, array $q = []): array{ return $this->request('PATCH', $path, $q, $body); }
    public function delete(string $path, array $q = []): array    { return $this->request('DELETE', $path, $q); }
}

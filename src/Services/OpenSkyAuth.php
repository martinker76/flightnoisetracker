<?php

declare(strict_types=1);

namespace App\Services;

/**
 * OpenSky Network OAuth2 token manager.
 *
 * Handles client_credentials grant flow with automatic token refresh
 * (tokens expire after 30 minutes).
 *
 * Accepts an optional $credentialSetLabel (e.g. 'default' or 'backfill')
 * used purely for log messages so operators can tell at a glance which
 * OAuth2 client is making each request. The label is not sent to OpenSky.
 */
class OpenSkyAuth
{
    private const TOKEN_URL = 'https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token';

    private ?string $clientId;
    private ?string $clientSecret;
    private string $credentialSetLabel;

    private ?string $accessToken = null;
    private ?int $expiresAt = null;

    /** Margin (seconds) before expiry to proactively refresh. */
    private const REFRESH_MARGIN = 60;

    public function __construct(array $openskyConfig, string $credentialSetLabel = 'default')
    {
        $this->clientId = $openskyConfig['client_id'] ?? null;
        $this->clientSecret = $openskyConfig['client_secret'] ?? null;
        $this->credentialSetLabel = $credentialSetLabel;
    }

    /**
     * The credential set label (e.g. 'default' or 'backfill'). For logging only.
     */
    public function getCredentialSetLabel(): string
    {
        return $this->credentialSetLabel;
    }

    /**
     * Check whether OAuth2 credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null;
    }

    /**
     * Get a valid Bearer token, refreshing automatically if needed.
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if ($this->accessToken !== null && $this->expiresAt !== null && time() < $this->expiresAt) {
            return $this->accessToken;
        }

        return $this->fetchToken();
    }

    /**
     * Return HTTP headers with a valid Bearer token.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            return [];
        }

        return [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FlightNoiseTracker/1.0',
        ];
    }

    /**
     * Fetch a new access token from the OpenSky auth server.
     */
    private function fetchToken(): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: FlightNoiseTracker/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            fwrite(STDERR, sprintf(
                "[OpenSkyAuth:%s] Token fetch failed: HTTP %d - %s\n",
                $this->credentialSetLabel,
                $httpCode,
                $error ?: $response
            ));
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            fwrite(STDERR, "[OpenSkyAuth:{$this->credentialSetLabel}] Invalid token response\n");
            return null;
        }

        $this->accessToken = $data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 1800);
        $this->expiresAt = time() + $expiresIn - self::REFRESH_MARGIN;

        return $this->accessToken;
    }
}

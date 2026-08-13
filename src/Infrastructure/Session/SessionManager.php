<?php

declare(strict_types=1);

namespace ProjectSync\Infrastructure\Session;

use ProjectSync\Infrastructure\Config;

final readonly class SessionManager
{
    public function __construct(private Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode', '1');
        $name = $this->config->requiredString('session.name');
        session_name($name);
        $sameSite = match ($this->config->allowedString('session.same_site', ['Lax', 'Strict', 'None'])) { 'Lax' => 'Lax', 'Strict' => 'Strict', 'None' => 'None', default => throw new \LogicException('Validated SameSite value was not mapped.') };
        session_set_cookie_params(['lifetime' => (int) $this->config->requiredString('session.lifetime'), 'path' => '/api', 'domain' => $this->config->string('session.domain'), 'secure' => $this->config->bool('session.secure_cookie'), 'httponly' => true, 'samesite' => $sameSite]);
        session_start();
    }

    public function regenerate(): void { $this->start(); session_regenerate_id(true); }
    public function destroy(): void { $this->start(); $_SESSION = []; $name = session_name(); if (is_string($name)) setcookie($name, '', time() - 3600, '/api'); session_destroy(); }
}

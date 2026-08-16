<?php
/**
 * Shared helpers for the QR Code Login / Device Pairing feature.
 *
 * BASE_URL (includes/auth.php) is host-RELATIVE ("/AttendancySystem") —
 * useless inside a QR payload, since the phone scanning the code has no
 * "current host" of its own to resolve a relative URL against. These
 * helpers derive an absolute URL from the CURRENT request instead, so the
 * QR always encodes whichever host the desktop browser is actually using
 * right now (localhost / 127.0.0.1 / a LAN IP) — the only host a phone on
 * the same network can actually reach.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function qr_absolute_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host . BASE_URL;
}

function qr_absolute_url(string $path): string
{
    return qr_absolute_base_url() . '/' . ltrim($path, '/');
}

function qr_new_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Renders a QR code PNG for the given payload as raw image bytes (not a
 * data URI) — the caller is responsible for the image/png Content-Type
 * header.
 */
function qr_render_png(string $payload): string
{
    $options = new QROptions([
        'outputInterface' => QRGdImagePNG::class,
        'eccLevel' => EccLevel::M,
        'scale' => 6,
        'outputBase64' => false,
    ]);

    return (new QRCode($options))->render($payload);
}

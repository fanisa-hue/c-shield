<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminLoginCaptcha
{
    private const SESSION_KEY = 'admin_login_captcha';

    private const LIFETIME_SECONDS = 300;

    public function ensure(Request $request): void
    {
        if (! $this->hasActiveChallenge($request)) {
            $this->create($request);
        }
    }

    public function image(Request $request): Response
    {
        if ($request->boolean('refresh')) {
            $this->create($request);
        } else {
            $this->ensure($request);
        }

        $challenge = $request->session()->get(self::SESSION_KEY);
        $answer = decrypt($challenge['answer']);

        // Vercel's PHP runtime ships without GD, so fall back to an SVG image there.
        [$image, $contentType] = extension_loaded('gd')
            ? [$this->renderImage($answer), 'image/png']
            : [$this->renderSvg($answer), 'image/svg+xml'];

        return response($image, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(Request $request, mixed $answer): bool
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        if (! $this->hasActiveChallenge($request) || ! is_string($answer)) {
            return false;
        }

        $normalizedAnswer = strtoupper(trim($answer));

        if (! preg_match('/\A[A-Z0-9]{5,6}\z/', $normalizedAnswer)) {
            return false;
        }

        $submittedHash = hash_hmac('sha256', $normalizedAnswer, (string) config('app.key'));

        return hash_equals($challenge['answer_hash'], $submittedHash);
    }

    public function create(Request $request): void
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = random_int(5, 6);
        $answer = '';

        for ($index = 0; $index < $length; $index++) {
            $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $request->session()->put(self::SESSION_KEY, [
            'answer_hash' => hash_hmac('sha256', $answer, (string) config('app.key')),
            'expires_at' => now()->timestamp + self::LIFETIME_SECONDS,
            // Keep the session small (cookie sessions cap at ~4 KB); the image is rendered on request.
            'answer' => encrypt($answer),
        ]);
    }

    private function hasActiveChallenge(Request $request): bool
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        return is_array($challenge)
            && isset($challenge['answer_hash'], $challenge['expires_at'], $challenge['answer'])
            && is_string($challenge['answer_hash'])
            && is_string($challenge['answer'])
            && (int) $challenge['expires_at'] > now()->timestamp;
    }

    private function renderImage(string $answer): string
    {
        $width = 184;
        $height = 58;
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 241, 247, 250);
        imagefill($image, 0, 0, $background);

        $noiseColors = [
            imagecolorallocate($image, 190, 211, 220),
            imagecolorallocate($image, 205, 222, 229),
            imagecolorallocate($image, 220, 232, 237),
        ];

        for ($dot = 0; $dot < 115; $dot++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $noiseColors[array_rand($noiseColors)]);
        }

        for ($line = 0; $line < 6; $line++) {
            $color = $noiseColors[array_rand($noiseColors)];
            imagesetthickness($image, random_int(1, 2));
            imageline(
                $image,
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                $color
            );
        }

        $characters = str_split($answer);
        $cellWidth = intdiv($width - 24, count($characters));
        $textColors = [
            [16, 42, 67],
            [15, 76, 92],
            [33, 54, 76],
        ];

        foreach ($characters as $index => $character) {
            $glyph = imagecreatetruecolor(16, 24);
            imagealphablending($glyph, false);
            imagesavealpha($glyph, true);
            $transparent = imagecolorallocatealpha($glyph, 255, 255, 255, 127);
            imagefill($glyph, 0, 0, $transparent);
            imagealphablending($glyph, true);

            [$red, $green, $blue] = $textColors[array_rand($textColors)];
            imagestring($glyph, 5, 3, 4, $character, imagecolorallocate($glyph, $red, $green, $blue));

            $scaled = imagecreatetruecolor(25, 38);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $scaledTransparent = imagecolorallocatealpha($scaled, 255, 255, 255, 127);
            imagefill($scaled, 0, 0, $scaledTransparent);
            imagecopyresampled($scaled, $glyph, 0, 0, 0, 0, 25, 38, 16, 24);

            $rotated = imagerotate($scaled, random_int(-12, 12), $scaledTransparent);
            imagealphablending($image, true);
            imagecopy(
                $image,
                $rotated,
                12 + ($index * $cellWidth) + random_int(-2, 2),
                random_int(7, 11),
                0,
                0,
                imagesx($rotated),
                imagesy($rotated)
            );

            imagedestroy($rotated);
            imagedestroy($scaled);
            imagedestroy($glyph);
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function renderSvg(string $answer): string
    {
        $width = 184;
        $height = 58;
        $noiseColors = ['#bed3dc', '#cddee5', '#dce8ed'];
        $textColors = ['#102a43', '#0f4c5c', '#21364c'];
        $shapes = '';

        for ($dot = 0; $dot < 115; $dot++) {
            $shapes .= sprintf('<rect x="%d" y="%d" width="1" height="1" fill="%s"/>', random_int(0, $width - 1), random_int(0, $height - 1), $noiseColors[array_rand($noiseColors)]);
        }

        for ($line = 0; $line < 6; $line++) {
            $shapes .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="%d"/>',
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                $noiseColors[array_rand($noiseColors)],
                random_int(1, 2)
            );
        }

        $characters = str_split($answer);
        $cellWidth = intdiv($width - 24, count($characters));

        foreach ($characters as $index => $character) {
            $x = 12 + ($index * $cellWidth) + random_int(-2, 2) + 12;
            $y = random_int(38, 44);
            $shapes .= sprintf(
                '<text x="%d" y="%d" transform="rotate(%d %d %d)" fill="%s" font-family="monospace" font-size="30" font-weight="bold" text-anchor="middle">%s</text>',
                $x,
                $y,
                random_int(-12, 12),
                $x,
                $y - 12,
                $textColors[array_rand($textColors)],
                $character
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d"><rect width="100%%" height="100%%" fill="#f1f7fa"/>%3$s</svg>',
            $width,
            $height,
            $shapes
        );
    }
}
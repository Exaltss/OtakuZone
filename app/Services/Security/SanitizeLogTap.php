<?php
declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Log\Logger;

final class SanitizeLogTap
{
    public function __invoke(Logger $logger): void
    {
        if (!(bool) config('zonaotaku.security.log_sanitize', true)) {
            return;
        }

        $sanitizer = new LogSanitizer();

        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor($sanitizer);
            }
        }
    }
}

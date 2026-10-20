<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Console/Commands/ReencryptUrls.php
 * Fungsi : re-encrypt kolom sensitif saat rotasi APP_KEY/CIPHER_KEYS
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §4.9, §3.3
 */
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

final class ReencryptUrls extends Command
{
    protected $signature = 'reencrypt:urls
                            {--batch=200 : Ukuran chunk proses}
                            {--dry-run : Hitung saja, jangan tulis}';

    protected $description = 'Re-encrypt kolom sensitif: episodes.quality_urls, users.two_factor_secret, settings.value';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(50, (int) $this->option('batch'));

        $episodes = 0;
        $users = 0;
        $settings = 0;
        $errors = 0;

        Episode::query()->chunkById($batch, function ($rows) use (&$episodes, &$errors, $dryRun): void {
            foreach ($rows as $episode) {
                try {
                    $value = $episode->quality_urls;

                    if (!is_array($value)) {
                        continue;
                    }

                    if (!$dryRun) {
                        $episode->quality_urls = $value;
                        $episode->save();
                    }

                    $episodes++;
                } catch (Throwable) {
                    $errors++;
                }
            }
        });

        User::query()->whereNotNull('two_factor_secret')->chunkById($batch, function ($rows) use (&$users, &$errors, $dryRun): void {
            foreach ($rows as $user) {
                try {
                    $value = $user->two_factor_secret;

                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    if (!$dryRun) {
                        $user->two_factor_secret = $value;
                        $user->save();
                    }

                    $users++;
                } catch (Throwable) {
                    $errors++;
                }
            }
        });

        Setting::query()->whereNotNull('value')->chunkById($batch, function ($rows) use (&$settings, &$errors, $dryRun): void {
            foreach ($rows as $setting) {
                try {
                    $value = $setting->value;

                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    if (!$dryRun) {
                        $setting->value = $value;
                        $setting->save();
                    }

                    $settings++;
                } catch (Throwable) {
                    $errors++;
                }
            }
        });

        $this->info(sprintf(
            'reencrypt:urls dry_run=%s episodes=%d users=%d settings=%d errors=%d',
            $dryRun ? 'true' : 'false',
            $episodes,
            $users,
            $settings,
            $errors
        ));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}

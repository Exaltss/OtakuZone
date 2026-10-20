<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Stream\QualityResolver;
use App\Services\Stream\AdmissionControl;

echo "=== QualityResolver Test ===\n";
$resolver = new QualityResolver();
$urls = ['360' => 'a', '720' => 'b', '1080' => 'c'];
echo "Available: " . implode(',', $resolver->available($urls)) . "\n";
echo "Resolve (req 720): " . $resolver->resolve($urls, '720') . "\n";
echo "Resolve (req 480, fallback): " . $resolver->resolve($urls, '480') . "\n";
echo "Fallback from 720: " . $resolver->fallback('720') . "\n";
echo "Fallback from 360: " . var_export($resolver->fallback('360'), true) . "\n";

echo "\n=== AdmissionControl Test (Mock Redis) ===\n";
// Karena Redis mungkin tidak jalan di lokal, kita test degradasi.
$admission = AdmissionControl::make();
echo "Allow (Redis dead fallback): " . var_export($admission->allow(1), true) . "\n";

echo "\nOK: Stream Proxy Core components loaded.\n";

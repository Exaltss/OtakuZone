<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : app/Http/Controllers/Api/StreamProxyController.php
 * Fungsi : endpoint /stream/play/{token} tanpa session, tanpa csrf, tanpa gzip.
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §7.3
 */
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stream\StreamProxy;
use Illuminate\Http\Request;

final class StreamProxyController extends Controller
{
    public function __construct(private readonly StreamProxy $proxy) {}

    public function play(Request $request, string $token)
    {
        return $this->proxy->handle($request, $token);
    }
}

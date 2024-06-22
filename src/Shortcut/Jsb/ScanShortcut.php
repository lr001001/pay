<?php

declare(strict_types=1);

namespace Yansongda\Pay\Shortcut\Jsb;

use Yansongda\Artful\Contract\ShortcutInterface;
use Yansongda\Artful\Plugin\ParserPlugin;
use Yansongda\Pay\Plugin\Jsb\AddPayloadSignPlugin;
use Yansongda\Pay\Plugin\Jsb\AddRadarPlugin;
use Yansongda\Pay\Plugin\Jsb\Pay\Scan\PayPlugin;
use Yansongda\Pay\Plugin\Jsb\ResponsePlugin;
use Yansongda\Pay\Plugin\Jsb\StartPlugin;
use Yansongda\Pay\Plugin\Jsb\VerifySignaturePlugin;

class ScanShortcut implements ShortcutInterface
{
    public function getPlugins(array $params): array
    {
        return [
            StartPlugin::class,
            PayPlugin::class,
            AddPayloadSignPlugin::class,
            AddRadarPlugin::class,
            VerifySignaturePlugin::class,
            ResponsePlugin::class,
            ParserPlugin::class,
        ];
    }
}

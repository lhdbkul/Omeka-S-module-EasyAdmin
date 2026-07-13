<?php declare(strict_types=1);

namespace EasyAdmin\Service\File\Thumbnailer;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Disable the thumbnailer (NoThumbnail) when the setting is on, else keep the
 * thumbnailer set by the core, the local config or another module (Vips…),
 * captured at merge config time.
 */
class ConfiguredThumbnailerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        if ($services->get('Omeka\Settings')->get('easyadmin_disable_thumbnails')) {
            return $services->build(\Omeka\File\Thumbnailer\NoThumbnail::class);
        }
        $config = $services->get('Config');
        $default = $config['easyadmin']['thumbnailer_default'] ?? \Omeka\File\Thumbnailer\ImageMagick::class;
        return $services->build($default);
    }
}

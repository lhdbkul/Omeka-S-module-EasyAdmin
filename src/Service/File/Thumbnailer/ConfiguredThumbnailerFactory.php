<?php declare(strict_types=1);

namespace EasyAdmin\Service\File\Thumbnailer;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Return the thumbnailer selected in the settings, overriding the core alias.
 */
class ConfiguredThumbnailerFactory implements FactoryInterface
{
    protected $map = [
        'imagemagick' => \Omeka\File\Thumbnailer\ImageMagick::class,
        'imagick' => \Omeka\File\Thumbnailer\Imagick::class,
        'gd' => \Omeka\File\Thumbnailer\Gd::class,
        'nothumbnail' => \Omeka\File\Thumbnailer\NoThumbnail::class,
    ];

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $choice = (string) $services->get('Omeka\Settings')->get('easyadmin_thumbnailer', '');
        if (isset($this->map[$choice])) {
            $service = $this->map[$choice];
        } else {
            // Defer to the thumbnailer captured at merge config time (core,
            // local config or another module such as Vips).
            $config = $services->get('Config');
            $service = $config['easyadmin']['thumbnailer_default'] ?? \Omeka\File\Thumbnailer\ImageMagick::class;
        }
        return $services->build($service);
    }
}

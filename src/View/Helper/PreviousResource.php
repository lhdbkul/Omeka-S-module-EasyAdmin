<?php declare(strict_types=1);

namespace EasyAdmin\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class PreviousResource extends AbstractHelper
{
    use PreviousNextResourceTrait;

    /**
     * Get the public resource immediately before the current one.
     *
     * @param string $sourceQuery "session", "setting" else passed query.
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource, ?string $sourceQuery = null, array $query = []): ?AbstractResourceEntityRepresentation
    {
        $resourceName = $resource->resourceName();

        // TODO Manage query for media.
        // TODO Manage different queries by resource type.
        if ($resourceName === 'media') {
            return $this->previousMedia($resource);
        }

        $view = $this->getView();
        $query = $this->getQuery($resourceName, $sourceQuery, $query);

        $previous = $this->getPreviousAndNextResourceIds($resource, $query)[0];
        return $previous
            ? $view->api()->read($resourceName, ['id' => $previous])->getContent()
            : null;
    }
}

<?php

declare(strict_types=1);

namespace Baraja\Cms\Search;


use Baraja\Cms\LinkGenerator;
use Baraja\Doctrine\EntityManager;
use Baraja\Plugin\PluginManager;
use Baraja\Search\SearchAccessor;
use Baraja\StructuredApi\BaseEndpoint;

final class CmsGlobalSearchEndpoint extends BaseEndpoint
{
	public function __construct(
		private PluginManager $pluginManager,
		private SearchAccessor $searchAccessor,
		private EntityManager $entityManager,
	) {
	}


	public function actionDefault(string $query): void
	{
		$entityToPlugin = $this->pluginManager->getBaseEntityToPlugin();
		$pluginClassToPluginName = [];
		$entityMap = [];
		foreach ($entityToPlugin as $baseEntity => $pluginClass) {
			if (is_subclass_of($pluginClass, SearchablePlugin::class)) {
				/** @var SearchablePlugin $plugin */
				$plugin = $this->pluginManager->getPluginByType($pluginClass);
				$entityMap[$baseEntity] = $plugin->getSearchColumns();
				if (isset($pluginClassToPluginName[$pluginClass]) === false) {
					$pluginClassToPluginName[$pluginClass] = $this->pluginManager->getPluginNameByType($plugin);
				}
			}
		}

		$searchResult = $this->searchAccessor->get()->search(
			query: $query,
			entityMap: $entityMap,
			useAnalytics: false,
		);

		$results = [];
		foreach ($searchResult->getItems() as $searchItem) {
			$entityClass = $this->entityManager->getClassMetadata(get_class($searchItem->getEntity()))->rootEntityName;
			$pluginClass = $entityToPlugin[$entityClass] ?? null;
			$pluginName = $pluginClassToPluginName[$pluginClass] ?? null;
			$results[] = [
				'title' => $searchItem->getTitleHighlighted(),
				'snippet' => $searchItem->getSnippet(),
				'link' => LinkGenerator::generateInternalLink($pluginName . ':detail', [
					'id' => $searchItem->getId(),
				]),
			];
		}

		$this->sendJson([
			'query' => $query,
			'results' => $results,
		]);
	}
}

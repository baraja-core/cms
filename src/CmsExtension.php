<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;

class CmsExtension extends CompilerExtension
{

	private static $priority = [
		'Admin' => 100,
		'Api' => 90,
		'Front' => 10,
	];

	/**
	 * @return RouteList
	 */
	public static function createAdminRouter(): RouteList
	{
		$router = new RouteList('Admin');
		$router->addRoute('admin/api/<package>[/<signal>]', 'CmsApi:default');
		$router->addRoute('admin/<presenter>/<action>', 'Homepage:default');

		return $router;
	}

	/**
	 * @param RouteList $list
	 */
	public static function fixRouter(RouteList $list): void
	{
		$lp = (new \ReflectionObject($list))->getParentClass()->getProperty('list');
		$lp->setAccessible(true);
		$moduleList = $lp->getValue($list);

		usort($moduleList, function (array $a, array $b): int {
			$m = static function (array $item): string {
				return rtrim((string) $item[0]->getModule(), ':');
			};

			return (self::$priority[$m($a)] ?? 25) < (self::$priority[$m($b)] ?? 25) ? 1 : -1;
		});

		$lp->setValue($list, $moduleList);
	}

	/**
	 * @param ClassType $class
	 */
	public function afterCompile(ClassType $class): void
	{
		$initialize = $class->getMethod('initialize');

		$initialize->addBody(
			'$this->getService(\'routing.router\')[] = \Baraja\Cms\CmsExtension::createAdminRouter();'
			. "\n" . '\Baraja\Cms\CmsExtension::fixRouter($this->getService(\'routing.router\'));'
		);
	}

}
<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Endpoint;


use Nette\DI\Container;

interface ApiEndpoint
{

	/**
	 * @param Container $container
	 */
	public function __construct(Container $container);

}
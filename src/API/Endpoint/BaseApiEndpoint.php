<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Endpoint;


use Nette\Application\LinkGenerator;
use Nette\Application\UI\InvalidLinkException;
use Nette\DI\Container;
use Nette\DI\Extensions\InjectExtension;

abstract class BaseApiEndpoint implements ApiEndpoint
{

	/**
	 * @var Container
	 */
	private $container;

	/**
	 * @param Container $container
	 */
	final public function __construct(Container $container)
	{
		$this->container = $container;

		foreach (InjectExtension::getInjectProperties(\get_class($this)) as $property => $service) {
			if ($service !== Container::class) {
				$this->{$property} = $container->getByType($service);
			}
		}
	}

	/**
	 * Warning! Using this method is bad design pattern!
	 * For inject service use public property with @inject annotation.
	 * For lazy load use ->getByType().
	 *
	 * @internal
	 * @return Container
	 */
	final public function dangerouslyGetContainer(): Container
	{
		return $this->container;
	}

	/**
	 * @param string $type
	 * @return object|null
	 */
	final public function getByType(string $type)
	{
		return $this->container->getByType($type);
	}

	/**
	 * @param string $destination
	 * @param mixed[] $args
	 * @return string
	 * @throws InvalidLinkException
	 */
	final public function link(string $destination, array $args = []): string
	{
		static $linkGenerator;

		if ($linkGenerator === null) {
			/** @var LinkGenerator $linkGenerator */
			$linkGenerator = $this->getByType(LinkGenerator::class);
		}

		return $linkGenerator->link($destination, $args);
	}

}
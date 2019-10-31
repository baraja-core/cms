<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\Cms\Api\Endpoint\ApiEndpoint;
use Baraja\Cms\Api\Endpoint\ApiEndpointException;
use Baraja\Cms\Api\Endpoint\Redirect;
use Baraja\Cms\Helpers;
use Nette\DI\Container;
use Nette\DI\MissingServiceException;

final class CmsApiManager
{

	/**
	 * @var Container
	 */
	private $container;

	/**
	 * @param Container $container
	 */
	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	/**
	 * @param string $package
	 * @param string $signal
	 * @return mixed[]|null
	 * @throws ApiEndpointException|Redirect
	 */
	public function process(string $package, string $signal): ?array
	{
		$this->checkJsonHttpInput();

		if (class_exists($service = 'Baraja\Cms\Api\Endpoint\\' . Helpers::firstUpper($package) . 'Endpoint') === false) {
			throw new ApiEndpointException('Api endpoint "' . $package . '". Class "' . $service . '" does not exist.');
		}

		try {
			/** @var ApiEndpoint $endpoint */
			$endpoint = $this->container->getByType($service);
		} catch (MissingServiceException $e) {
			throw new ApiEndpointException('Service "' . $service . '" does not exist');
		}

		if (method_exists($endpoint, $method = 'action' . str_replace('.', '', Helpers::firstUpper($signal)))) {
			try {
				return $this->call($endpoint, $method, array_merge($_GET, $_POST));
			} catch (\Exception $e) {
				if ($e instanceof Redirect) {
					throw $e;
				}
				throw new ApiEndpointException($e->getMessage());
			}
		}

		return null;
	}

	private function checkJsonHttpInput(): void
	{
		if (\count($_POST) === 1 && preg_match('/^\{.*\}$/', $post = array_keys($_POST)[0]) && ($json = json_decode($post)) instanceof \stdClass) {
			foreach ($json as $key => $value) {
				$_POST[$key] = $value;
				unset($_POST[$post]);
			}
		}
	}

	/**
	 * @param ApiEndpoint $endpoint
	 * @param string $method
	 * @param mixed[] $args
	 * @return mixed[]|null
	 * @throws ApiEndpointException|\ReflectionException
	 */
	private function call(ApiEndpoint $endpoint, string $method, array $args): ?array
	{
		$ref = new \ReflectionMethod($endpoint, $method);

		$paramValues = [];

		foreach ($ref->getParameters() as $parameter) {
			$paramName = $parameter->getName();

			if (isset($args[$paramName])) {
				$type = $parameter->getType();
				$paramValues[$paramName] = $type === null
					? (string) $args[$paramName]
					: $this->resolveType($type->getName(), $type->allowsNull(), $args[$paramName]);
			} elseif ($parameter->isOptional() === false) {
				throw new ApiEndpointException('Parameter "' . $paramName . '" for method "' . $method . '" is required.');
			} else {
				$paramValues[$paramName] = $parameter->getDefaultValue();
			}
		}

		return call_user_func_array([$endpoint, $method], $paramValues);
	}

	private function resolveType(string $type, bool $allowsNull, $haystack)
	{
		if ($type === 'string') {
			$haystack = (string) $haystack;
			if ($allowsNull === true) {
				return $haystack === '' ? null : $haystack;
			}

			return $haystack;
		}

		if ($type === 'int') {
			return (int) $haystack;
		}

		if ($type === 'float') {
			return (float) $haystack;
		}

		if ($type === 'bool') {
			$haystack = mb_strtolower((string) $haystack, 'UTF-8');

			return $haystack === 'true' || $haystack === 'yes' || $haystack === 'ok';
		}

		return $haystack;
	}

}
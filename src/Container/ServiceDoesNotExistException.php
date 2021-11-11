<?php

declare(strict_types=1);

namespace Baraja\Cms\Container;


use Psr\Container\NotFoundExceptionInterface;

final class ServiceDoesNotExistException extends \InvalidArgumentException implements NotFoundExceptionInterface
{
}

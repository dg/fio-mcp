<?php declare(strict_types=1);

use DG\Fio\FioException;
use DG\Fio\McpToolCallGuard;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/**
 * Guard over a handler that throws whatever the test needs.
 */
function createGuard(?Throwable $throw): McpToolCallGuard
{
	return new McpToolCallGuard(new class ($throw) implements ReferenceHandlerInterface {
		public function __construct(
			private ?Throwable $throw,
		) {
		}


		public function handle(ElementReference $reference, array $arguments): mixed
		{
			return $this->throw ? throw $this->throw : 'result';
		}
	});
}


function callGuard(?Throwable $throw): mixed
{
	$reference = (new ReflectionClass(ElementReference::class))->newInstanceWithoutConstructor();
	return createGuard($throw)->handle($reference, []);
}


test('a successful call passes through', function () {
	Assert::same('result', callGuard(null));
});


test('a tool error is kept as it is', function () {
	$original = new ToolCallException('already shaped');
	$e = Assert::exception(fn() => callGuard($original), ToolCallException::class, 'already shaped');
	Assert::same($original, $e);
});


test('messages meant for the user are forwarded clean', function () {
	Assert::exception(
		fn() => callGuard(new FioException('Fio rejected the token.')),
		ToolCallException::class,
		'Fio rejected the token.',
	);
	Assert::exception(
		fn() => callGuard(new InvalidArgumentException('Invalid IBAN.')),
		ToolCallException::class,
		'Invalid IBAN.',
	);
});


test('a bug is reported with class and place', function () {
	Assert::exception(
		fn() => callGuard(new TypeError('boom')),
		ToolCallException::class,
		'boom (TypeError in McpToolCallGuard.phpt:%d%)',
	);
});

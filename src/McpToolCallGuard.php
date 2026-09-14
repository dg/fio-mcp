<?php declare(strict_types=1);

namespace DG\Fio;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;


/**
 * Converts anything a tool throws into a ToolCallException, which the SDK returns as a result
 * with isError=true, so the model sees the message instead of an opaque JSON-RPC -32603.
 *
 * Conversion rules (first match wins):
 *   - ToolCallException: already shaped by the tool (payment gate, bank rejection), rethrown unchanged.
 *   - FioException and InvalidArgumentException: messages written for the user, forwarded as they are.
 *   - any other \Throwable: wrapped with class and file:line so genuine bugs surface with context.
 */
final class McpToolCallGuard implements ReferenceHandlerInterface
{
	public function __construct(
		private readonly ReferenceHandlerInterface $handler,
	) {
	}


	/**
	 * @param array<string, mixed> $arguments
	 */
	public function handle(ElementReference $reference, array $arguments): mixed
	{
		try {
			return $this->handler->handle($reference, $arguments);
		} catch (ToolCallException $e) {
			throw $e;
		} catch (FioException|\InvalidArgumentException $e) {
			throw new ToolCallException($e->getMessage(), 0, $e);
		} catch (\Throwable $e) {
			throw new ToolCallException(
				sprintf('%s (%s in %s:%d)', $e->getMessage(), $e::class, basename($e->getFile()), $e->getLine()),
				0,
				$e,
			);
		}
	}
}

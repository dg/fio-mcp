<?php declare(strict_types=1);

use DG\Fio\FioClient;
use DG\Fio\McpTools;
use DG\Fio\StateStore;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Exception\ToolCallException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$tools = (new Discoverer)->discover(__DIR__ . '/..', ['src'])->getTools();


test('tool list, titles and annotations', function () use ($tools) {
	$names = array_keys($tools);
	sort($names);
	Assert::same(['fio_list_transactions', 'fio_send_domestic_payment', 'fio_send_sepa_payment'], $names);

	foreach ($tools as $name => $ref) {
		Assert::truthy($ref->tool->title, "tool $name is missing a title");
		Assert::true($ref->tool->annotations?->openWorldHint, "tool $name is missing openWorldHint");
	}

	Assert::true($tools['fio_list_transactions']->tool->annotations->readOnlyHint);
	foreach (['fio_send_domestic_payment', 'fio_send_sepa_payment'] as $name) {
		$a = $tools[$name]->tool->annotations;
		Assert::same([false, true, false], [$a->readOnlyHint, $a->destructiveHint, $a->idempotentHint], $name);
	}
});


test('schemas', function () use ($tools) {
	$schema = $tools['fio_list_transactions']->tool->inputSchema;
	Assert::same(['all', 'incoming', 'outgoing'], $schema['properties']['direction']['enum']);
	Assert::false(isset($schema['required']));

	Assert::same('date', $schema['properties']['dateFrom']['format']);
	Assert::same(1000, $schema['properties']['limit']['maximum']);

	$schema = $tools['fio_send_domestic_payment']->tool->inputSchema;
	Assert::same(['account', 'amount', 'currency'], $schema['required']);
	Assert::same('number', $schema['properties']['amount']['type']);
	Assert::same(0.01, $schema['properties']['amount']['minimum']);
	Assert::same(['standard', 'priority'], $schema['properties']['paymentType']['enum']);

	$schema = $tools['fio_send_sepa_payment']->tool->inputSchema;
	Assert::same(['iban', 'amount', 'recipientName'], $schema['required']);
	Assert::same(['standard', 'priority', 'instant'], $schema['properties']['paymentType']['enum']);
	Assert::same(105, $schema['properties']['message']['maxLength']);
});


test('payments are gated before the client is created', function () {
	$tools = new McpTools(static fn() => throw new RuntimeException('client must not be created'));
	Assert::exception(fn() => $tools->sendDomesticPayment('1234567899/2010', 1, 'CZK'), ToolCallException::class, '%a%FIO_ALLOW_PAYMENTS=1%a%');
	Assert::exception(fn() => $tools->sendSepaPayment('AT611904300234573201', 1, 'Hans'), ToolCallException::class, '%a%FIO_ALLOW_PAYMENTS=1%a%');
});


/**
 * @param  list<array{int, string}>  $responses
 */
function createTools(array $responses, ?array &$requests = null): McpTools
{
	$requests = [];
	$store = new StateStore(tempnam(sys_get_temp_dir(), 'fio-mcp-test'));
	$http = function (string $method, string $url, array $fields) use (&$responses, &$requests): array {
		$requests[] = [$method, $url, $fields];
		return array_shift($responses) ?? throw new LogicException('Unexpected request');
	};
	$client = new FioClient('token', $store, $http);
	return new McpTools(static fn() => $client, allowPayments: true);
}


test('filters are applied locally over one download', function () {
	$tools = createTools([[200, file_get_contents(__DIR__ . '/fixtures/statement.json')]], $requests);

	$all = $tools->listTransactions('2012-08-01', '2012-09-02');
	Assert::true($all['untrustedContent']);
	Assert::same(3, $all['count']);
	Assert::same([1_155_172_474, 1_155_172_473, 1_155_172_472], array_column($all['transactions'], 'id'));
	Assert::same(1335.05, $all['account']['closingBalance']);

	Assert::same([1_155_172_473, 1_155_172_472], array_column($tools->listTransactions('2012-08-01', '2012-09-02', direction: 'incoming')['transactions'], 'id'));
	Assert::same([1_155_172_474], array_column($tools->listTransactions('2012-08-01', '2012-09-02', direction: 'outgoing')['transactions'], 'id'));
	Assert::same([1_155_172_473], array_column($tools->listTransactions('2012-08-01', '2012-09-02', variableSymbol: '20240017')['transactions'], 'id'));
	Assert::same(0, $tools->listTransactions('2012-08-01', '2012-09-02', variableSymbol: '0')['count']);
	Assert::same([1_155_172_473], array_column($tools->listTransactions('2012-08-01', '2012-09-02', counterAccount: '2000145399/0800')['transactions'], 'id'));
	Assert::same([1_155_172_474, 1_155_172_473], array_column($tools->listTransactions('2012-08-01', '2012-09-02', text: 'HRAČKY')['transactions'], 'id'));

	$limited = $tools->listTransactions('2012-08-01', '2012-09-02', limit: 1);
	Assert::same(3, $limited['count']);
	Assert::count(1, $limited['transactions']);
	Assert::contains('newest 1 of 3', $limited['truncated']);

	Assert::count(1, $requests);
});


test('date validation', function () {
	$tools = createTools([]);
	Assert::exception(fn() => $tools->listTransactions('2024-02-01', '2024-01-01'), InvalidArgumentException::class, '%a%is after%a%');
	Assert::exception(fn() => $tools->listTransactions('1.1.2024'), InvalidArgumentException::class, "Invalid dateFrom '1.1.2024'%a%");
});


test('payment gets the MCP mark and is reported as queued', function () {
	$tools = createTools([
		[200, file_get_contents(__DIR__ . '/fixtures/statement.json')],
		[
			200,
			'<responseImport><result><errorCode>0</errorCode><idInstruction>7</idInstruction><status>ok</status></result></responseImport>',
		],
	], $requests);

	$result = $tools->sendDomesticPayment('1234567899/2010', 100, 'CZK', comment: 'Faktura 17', variableSymbol: '17');
	Assert::same('ok', $result['status']);
	Assert::same('7', $result['batchId']);
	Assert::contains('authorizes it', $result['note']);
	Assert::contains('<comment>MCP: Faktura 17</comment>', $requests[1][2]['file']->data);
	Assert::same([
		'currency' => 'CZK',
		'account' => '1234567899/2010',
		'amount' => '100.00',
		'date' => DG\Fio\Payment::today(),
		'variableSymbol' => '17',
		'comment' => 'MCP: Faktura 17',
	], $result['payment']);
	Assert::equal(new stdClass, $result['sums']);
});


test('a payment in another currency than the account is refused', function () {
	$tools = createTools([[200, file_get_contents(__DIR__ . '/fixtures/statement.json')]], $requests);

	Assert::exception(
		fn() => $tools->sendDomesticPayment('1234567899/2010', 100, 'EUR'),
		InvalidArgumentException::class,
		'The account is in CZK, a payment in EUR cannot be sent from it.',
	);
	Assert::count(1, $requests); // nothing was uploaded
});


test('SEPA payment from a CZK account is refused before upload', function () {
	$tools = createTools([[200, file_get_contents(__DIR__ . '/fixtures/statement.json')]], $requests);

	Assert::exception(
		fn() => $tools->sendSepaPayment('AT611904300234573201', 100, 'Hans Gruber', paymentType: 'instant'),
		InvalidArgumentException::class,
		'%a%only from a EUR account%a%',
	);
	Assert::count(1, $requests);
});


test('too long comment is refused, not truncated', function () {
	$tools = createTools([]);
	Assert::exception(
		fn() => $tools->sendDomesticPayment('1234567899/2010', 100, 'CZK', comment: str_repeat('x', 136)),
		InvalidArgumentException::class,
		'Comment must be at most 135 characters, 136 given.',
	);
});


test('rejected payment is a tool error', function () {
	$tools = createTools([
		[200, file_get_contents(__DIR__ . '/fixtures/statement.json')],
		[
			200,
			'<responseImport><result><errorCode>1</errorCode><status>error</status><message>Bad account.</message></result></responseImport>',
		],
	], $requests);

	Assert::exception(
		fn() => $tools->sendDomesticPayment('1234567899/2010', 100, 'czk'),
		ToolCallException::class,
		'Fio rejected the payment (status error, errorCode 1): Bad account.',
	);
	Assert::contains('<comment>MCP</comment>', $requests[1][2]['file']->data);
});

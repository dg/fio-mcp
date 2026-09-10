<?php declare(strict_types=1);

use DG\Fio\BankAccount;
use DG\Fio\DomesticPayment;
use DG\Fio\FioClient;
use DG\Fio\FioException;
use DG\Fio\StateStore;
use DG\Fio\TransportException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


const Token = 'SECRETtoken0123456789012345678901234567890123456789012345678901';

const AcceptedResponse = '<responseImport><result><errorCode>0</errorCode><idInstruction>105964331</idInstruction><status>ok</status></result></responseImport>';
const RejectedResponse = '<responseImport><result><errorCode>1</errorCode><status>error</status><message>Invalid account.</message></result></responseImport>';


/**
 * Client with a scripted HTTP layer; $requests collects [method, url, fields].
 * @param  list<array{int, string}|\RuntimeException>  $responses
 */
function createClient(array $responses, ?array &$requests = null, ?StateStore &$store = null): FioClient
{
	$requests = [];
	$store = new StateStore(tempnam(sys_get_temp_dir(), 'fio-mcp-test'));
	$http = function (string $method, string $url, array $fields) use (&$responses, &$requests): array {
		$requests[] = [$method, $url, $fields];
		$response = array_shift($responses) ?? throw new LogicException('Unexpected request');
		return $response instanceof RuntimeException ? throw $response : $response;
	};
	return new FioClient(Token, $store, $http);
}


function statementJson(): string
{
	return file_get_contents(__DIR__ . '/fixtures/statement.json');
}


test('periods export, the cursor endpoints are never used', function () {
	$client = createClient([[200, statementJson()]], $requests, $store);
	$statement = $client->getTransactions('2012-08-01', '2012-09-02');

	Assert::count(3, $statement->transactions);
	Assert::same([['GET', 'https://fioapi.fio.cz/v1/rest/periods/' . Token . '/2012-08-01/2012-09-02/transactions.json', []]], $requests);
	Assert::same('2400222222', $store->update(static fn(array &$state) => $state['account']['number']));
});


test('account comes from the cache filled by any export', function () {
	$client = createClient([[200, statementJson()]], $requests);
	$client->getTransactions('2012-08-01', '2012-09-02');
	Assert::same('CZK', $client->getAccount()->currency);
	Assert::count(1, $requests);
});


test('HTTP errors are explained and never reveal the token', function () {
	$cases = [
		404 => 'Fio rejected the request parameters%a%',
		409 => '%a%one request per token every 30 seconds%a%',
		413 => '%a%more than 50,000 movements%a%',
		422 => '%a%older than 90 days%a%Settings > API%a%',
		500 => 'Fio rejected the token%a%FIO_TOKEN%a%',
		503 => 'Fio returned HTTP 503: Maintenance ***',
	];
	foreach ($cases as $status => $message) {
		$client = createClient([[$status, '<p>Maintenance ' . Token . '</p>']]);
		$e = Assert::exception(fn() => $client->getTransactions('2024-01-01', '2024-01-31'), FioException::class, $message);
		Assert::notContains(Token, $e->getMessage());
	}
});


test('transport error never reveals the token', function () {
	$client = createClient([new RuntimeException('Could not resolve host for /periods/' . Token)]);
	$e = Assert::exception(fn() => $client->getTransactions('2024-01-01', '2024-01-31'), TransportException::class, 'Request to Fio failed: %a%');
	Assert::notContains(Token, $e->getMessage());
});


test('payment upload is multipart with the XML as a file', function () {
	$client = createClient([[200, statementJson()], [200, AcceptedResponse]], $requests);
	$result = $client->sendPayment(new DomesticPayment(BankAccount::parse('1234567899/2010'), 100, variableSymbol: '42'));

	Assert::true($result->isAccepted());
	Assert::same('105964331', $result->batchId);

	[$method, $url, $fields] = $requests[1];
	Assert::same('POST', $method);
	Assert::same('https://fioapi.fio.cz/v1/rest/import/', $url);
	Assert::same(Token, $fields['token']);
	Assert::same('xml', $fields['type']);
	Assert::type(CURLStringFile::class, $fields['file']);
	Assert::contains('<accountFrom>2400222222</accountFrom>', $fields['file']->data);
	Assert::contains('<vs>42</vs>', $fields['file']->data);
});


test('identical payment is refused unless allowed', function () {
	$client = createClient([[200, statementJson()], [200, AcceptedResponse], [200, AcceptedResponse]], $requests);
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 100, variableSymbol: '42');
	$client->sendPayment($payment);

	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, 'An identical payment%a%Ask the user%a%');
	Assert::count(2, $requests);

	$client->sendPayment($payment, allowDuplicate: true);
	Assert::count(3, $requests);

	// a different variable symbol is a different payment
	$client2 = createClient([[200, statementJson()], [200, AcceptedResponse], [200, AcceptedResponse]]);
	$client2->sendPayment(new DomesticPayment(BankAccount::parse('1234567899/2010'), 100, variableSymbol: '1'));
	Assert::noError(fn() => $client2->sendPayment(new DomesticPayment(BankAccount::parse('1234567899/2010'), 100, variableSymbol: '2')));
});


test('fingerprint survives a transport error, the state is unknown', function () {
	$client = createClient([[200, statementJson()], new RuntimeException('Operation timed out')]);
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 100);

	Assert::exception(fn() => $client->sendPayment($payment), TransportException::class, '%a%unknown whether Fio received the payment%a%');
	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, 'An identical payment%a%');
});


test('a gateway error keeps the fingerprint, the payment may have been queued', function () {
	$client = createClient([[200, statementJson()], [504, 'Gateway Timeout']]);
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 100);

	Assert::exception(fn() => $client->sendPayment($payment), TransportException::class, '%a%unknown whether Fio received the payment%a%');
	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, 'An identical payment%a%');
});


test('an unreadable import response keeps the fingerprint', function () {
	$client = createClient([[200, statementJson()], [200, '<html>Maintenance</html>']]);
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 100);

	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, '%a%unknown whether Fio received the payment%a%');
	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, 'An identical payment%a%');
});


test('a planted account cache is ignored, the seal does not match', function () {
	$client = createClient([[200, statementJson()]], $requests, $store);
	$store->update(static function (array &$state): void {
		$state['account'] = ['number' => '9999999999', 'bankCode' => '2010', 'currency' => 'CZK'];
		$state['accountSeal'] = 'forged';
	});

	Assert::same('2400222222', $client->getAccount()->number);
	Assert::count(1, $requests);
});


test('fingerprint is released when Fio refuses the payment', function () {
	$client = createClient([[200, statementJson()], [200, RejectedResponse], [409, ''], [200, AcceptedResponse]]);
	$payment = new DomesticPayment(BankAccount::parse('1234567899/2010'), 100);

	Assert::false($client->sendPayment($payment)->isAccepted());
	Assert::exception(fn() => $client->sendPayment($payment), FioException::class, '%a%one request per token every 30 seconds%a%');
	Assert::true($client->sendPayment($payment)->isAccepted());
});


test('unreadable export', function () {
	$client = createClient([[200, '<html>Maintenance</html>'], [200, '{"accountStatement":{"info":{}}}']]);
	Assert::exception(fn() => $client->getTransactions('2024-01-01', '2024-01-31'), FioException::class, 'Fio returned an unreadable export: %a%');
	Assert::exception(fn() => $client->getTransactions('2024-01-01', '2024-01-31'), FioException::class, 'Fio returned an unreadable export: Fio export is missing the account header.');
});


test('invalid payment for the account is refused before upload', function () {
	$client = createClient([[200, statementJson()]], $requests);
	Assert::exception(
		fn() => $client->sendPayment(new DG\Fio\SepaPayment('AT611904300234573201', 5, 'Hans')),
		InvalidArgumentException::class,
		'%a%only from a EUR account%a%',
	);
	Assert::count(1, $requests);
});

<?php
/**
 * xkcd comic metadata fetcher
 *
 * @link https://xkcd.com/json.html
 * @link https://publicapi.dev/xkcd-api
 *
 * @created      14.08.2025
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2025 smiley
 * @license      MIT
 *
 * @noinspection PhpUndefinedConstantInspection
 */
declare(strict_types=1);

use chillerlan\HTTP\CurlClient;
use chillerlan\HTTP\HTTPOptions;
use chillerlan\HTTP\Psr7\HTTPFactory;
use chillerlan\HTTP\Utils\MessageUtil;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';

const DIRECTORIES = [
#	'BUILDDIR' => __DIR__.'/../.build',
	'SRCDIR'   => __DIR__.'/../src',
	'TOOLDIR'  => __DIR__,
];

// create some directories
foreach(DIRECTORIES as $const => $dir){

	if(!is_dir($dir)){
		mkdir(directory: $dir, recursive: true);
	}

	// define the constants with the real path
	define($const, realpath($dir));
}

const FILE_CA   = TOOLDIR.'/cacert.pem'; // https://curl.se/ca/cacert.pem
const FILE_XKCD = SRCDIR.'/xkcd.json';

// invoke http client
$httpOptions = new HTTPOptions(['ca_info' => FILE_CA]);
$factory     = new HTTPFactory;
$http        = new CurlClient($factory, $httpOptions);

// invoke logger
$formatter   = (new LineFormatter(null, 'Y-m-d H:i:s', true, true))->setJsonPrettyPrint(true);
$logHandler  = (new StreamHandler('php://stdout', LogLevel::INFO))->setFormatter($formatter);
$logger      = new Logger('log', [$logHandler]);

// fetch the current data
$xkcd        = json_decode(file_get_contents(FILE_XKCD), true);
$num         = $xkcd[array_key_last($xkcd)]['id'] ?? 0; // higest number in dataset
$current     = 0; // latest comic id (fetch)

// fetch the latest comic
$request     = $factory->createRequest('GET', 'https://xkcd.com/info.0.json');
$response    = $http->sendRequest($request);

if($response->getStatusCode() !== 200){
	throw new RuntimeException('could not fetch latest');
}

$current = MessageUtil::decodeJSON($response, true)['num'];

// update the dataset
while($current > $num){

	// i see what you did there
	if($current === 404){
		$current--;
		continue;
	}

	$request  = $factory->createRequest('GET', sprintf('https://xkcd.com/%s/info.0.json', $current));
	$response = $http->sendRequest($request);

	if($response->getStatusCode() === 200){
		$data = MessageUtil::decodeJSON($response, true);
		// forcing a string key so that javascript doesn't get confused
		$xkcd[sprintf('xkcd-%s', $data['num'])] = [
			'id'         => $data['num'],
			'date'       => mktime(12, 0, 0, intval($data['month']), intval($data['day']), intval($data['year'])),
			'title'      => $data['safe_title'],
			'image'      => $data['img'],
			'alt'        => $data['alt'],
			'transcript' => $data['transcript'],
		];

		$logger->info(sprintf('fetched comic id [%s]: "%s"', $data['num'], $data['safe_title']));
	}

	$current--;
	sleep(1);
}

// write output
ksort($xkcd, SORT_NATURAL);

file_put_contents(FILE_XKCD, json_encode($xkcd, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

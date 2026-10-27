<?php
declare(strict_types=1);

namespace Drupal\Core\Config {
    interface ConfigFactoryInterface { public function get($name); }
}
namespace Drupal\Core\Logger {
    interface LoggerChannelFactoryInterface {}
}
namespace Drupal\key {
    interface KeyRepositoryInterface { public function getKey($id); }
}
namespace Drupal\flowspeech\Form {
    final class FlowSpeechSettingsForm { public const CONFIG_NAME = 'flowspeech.settings'; }
}
namespace GuzzleHttp {
    interface ClientInterface { public function getConfig($key); }
    final class Client {
        public static array $requests = [];
        public static array $queue = [];
        public static array $configs = [];
        public function __construct(array $config) { self::$configs[] = $config; }
        public function request($method, $url, $options) {
            self::$requests[] = compact('method', 'url', 'options');
            $response = array_shift(self::$queue);
            if (is_callable($response)) { $response = $response(compact('method', 'url', 'options')); }
            if ($response instanceof \Throwable) { throw $response; }
            if ($response === null) { throw new \RuntimeException('Unexpected request'); }
            $options['sink']->write($response['body']);
            return new class($response) {
                public function __construct(private array $response) {}
                public function getStatusCode() { return $this->response['status']; }
                public function getHeaders() { return $this->response['headers']; }
                public function getHeaderLine($name) { return (string) ($this->response['headers'][$name] ?? ''); }
                public function getBody() {
                    return new class($this->response['body']) {
                        public function __construct(private string $body) {}
                        public function rewind() {}
                        public function read($length) { return substr($this->body, 0, $length); }
                        public function detach() {
                            $stream = fopen('php://temp', 'r+');
                            fwrite($stream, $this->body);
                            rewind($stream);
                            return $stream;
                        }
                    };
                }
            };
        }
    }
}
namespace GuzzleHttp\Exception { class TransferException extends \RuntimeException {} }
namespace GuzzleHttp\Psr7 {
    final class Utils {
        public static function streamFor() {
            return new class {
                private string $body = '';
                public function write($chunk) { $this->body .= $chunk; return strlen($chunk); }
                public function getSize() { return strlen($this->body); }
                public function __toString() { return $this->body; }
                public function close() { $this->body = ''; }
            };
        }
    }
    final class FnStream {
        public static function decorate($stream, $methods) {
            return new class($stream, $methods) {
                public function __construct(private $stream, private $methods) {}
                public function write($chunk) { return ($this->methods['write'])($chunk); }
                public function close() { $this->stream->close(); }
            };
        }
    }
}
namespace {
    require __DIR__ . '/contract.php';
    require_once $sourceRoot . '/src/FlowSpeechApiService.php';
    $initial = $checks;
    $config = new class implements \Drupal\Core\Config\ConfigFactoryInterface {
        public function get($name) {
            return new class {
                public function get($key) { return ['api_key' => 'test-key', 'timeout_seconds' => 120][$key] ?? null; }
            };
        }
    };
    $http = new class implements \GuzzleHttp\ClientInterface {
        public function getConfig($key) { return 'fixture-handler'; }
    };
    $keys = new class implements \Drupal\key\KeyRepositoryInterface {
        public function getKey($id) {
            return new class { public function getKeyValue() { return 'fixture-only'; } };
        }
    };
    $logger = new class implements \Drupal\Core\Logger\LoggerChannelFactoryInterface {};
    $service = new \Drupal\flowspeech\FlowSpeechApiService($config, $http, $keys, $logger, $http);
    \GuzzleHttp\Client::$queue = [response(job()), response(wav())];
    $result = $service->textToSpeech('Hello', 'Kore', REQUEST_ID);
    check($result['binary'] === wav() && $result['mime_type'] === 'audio/wav', 'Drupal returns downloaded WAV');
    check($result['response']['usage']['chargedCredits'] === 5 && !isset($result['response']['data']), 'Drupal raw response is v1 job');
    $requests = \GuzzleHttp\Client::$requests;
    check(json_decode($requests[0]['options']['body'], true) === ['input' => INPUT], 'Drupal v1 mapping, no clientSource/clientVersion');
    check($requests[0]['options']['headers']['Authorization'] === 'Bearer fixture-only', 'Drupal Bearer');
    check($requests[1]['options']['headers'] === ['Accept' => 'audio/wav'], 'Drupal storage header isolation');
    foreach ($requests as $request) {
        check($request['options']['allow_redirects'] === false, 'Drupal redirects off');
        check($request['options']['auth'] === null && $request['options']['cookies'] === false, 'Drupal defaults disabled');
        check($request['options']['stream'] === false && $request['options']['verify'] === true &&
            isset($request['options']['sink']), 'Drupal bounded cURL sink with TLS');
        check($request['options']['timeout'] > 0 && $request['options']['timeout'] <= 30, 'Drupal timeout');
    }
    foreach (\GuzzleHttp\Client::$configs as $config) {
        check($config === ['handler' => 'fixture-handler', 'proxy' => []], 'Explicit mock handler, no default credentials/proxy');
    }
    \GuzzleHttp\Client::$queue = [
        response(['quota' => ['available' => 5], 'subscription' => null, 'limits' => []]),
        response(['voices' => [['name' => 'Kore']]]),
    ];
    check($service->getQuota()['quota']['available'] === 5, 'Drupal quota');
    check($service->getVoices()['voices'][0]['name'] === 'Kore', 'Drupal voices');
    $previous = $service->getClient();
    $service->setApiKey('replacement-fixture');
    check($previous !== $service->getClient(), 'Key override resets client');
    $request = ['url' => 'https://audio.example.test/a.wav', 'method' => 'GET',
        'headers' => ['Accept' => 'audio/wav'], 'body' => null, 'timeout' => 10, 'maxBytes' => 44];
    \GuzzleHttp\Client::$queue = [response(str_repeat('x', 45))];
    try { $service->transport($request); throw new RuntimeException('Expected size rejection'); }
    catch (\Drupal\flowspeech\Api\FlowSpeechException $error) { check($error->errorCode === 'response_too_large', 'Drupal stream size cap'); }
    \GuzzleHttp\Client::$queue = [new \GuzzleHttp\Exception\TransferException('sensitive URL/key/body')];
    try { $service->transport($request); throw new RuntimeException('Expected network failure'); }
    catch (\Drupal\flowspeech\Api\FlowSpeechException $error) {
        check($error->retryable && !str_contains($error->getMessage(), 'sensitive'), 'Drupal network error redacted');
    }
    echo 'PASS: ' . ($checks - $initial) . " Drupal adapter assertions.\n";
}

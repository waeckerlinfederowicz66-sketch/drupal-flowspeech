<?php
declare(strict_types=1);

namespace Drupal\ai\Attribute {
    #[\Attribute] class AiProvider { public function __construct(...$args) {} }
}
namespace Drupal\ai\Base {
    class AiProviderClientBase { protected array $configuration = []; }
}
namespace Drupal\ai\Exception { class AiBadRequestException extends \RuntimeException {} }
namespace Drupal\ai\OperationType\GenericType {
    class AudioFile {
        public function __construct(public string $binary, public string $mime, public string $filename) {}
    }
}
namespace Drupal\ai\OperationType\TextToSpeech {
    class TextToSpeechInput {
        public function __construct(private string $text) {}
        public function getText(): string { return $this->text; }
    }
    interface TextToSpeechInterface {}
    class TextToSpeechOutput {
        public function __construct(public array $files, public array $response, public array $tags) {}
    }
}
namespace Drupal\Core\Plugin { interface ContainerFactoryPluginInterface {} }
namespace Drupal\Core\StringTranslation { class TranslatableMarkup {} }
namespace {
    require __DIR__ . '/adapter.php';
    require $sourceRoot . '/src/Exception/FlowSpeechProviderException.php';
    require $sourceRoot . '/src/Plugin/AiProvider/FlowSpeechProvider.php';
    require $sourceRoot . '/src/Audio/WavEncoder.php';
    $initial = $checks;
    $service->setApiKey('fixture-only');
    $provider = new \Drupal\flowspeech\Plugin\AiProvider\FlowSpeechProvider();
    $property = new ReflectionProperty($provider, 'client');
    $property->setValue($provider, $service);
    $configuration = new ReflectionProperty($provider, 'configuration');
    $configuration->setValue($provider, ['voice' => 'Kore']);
    \GuzzleHttp\Client::$queue = [static fn($request) => response(job('success',
        ['requestId' => $request['options']['headers']['Idempotency-Key']])), response(wav())];
    $output = $provider->textToSpeech('Hello', 'flowspeech-tts');
    check($output->files[0]->binary === wav() && $output->files[0]->mime === 'audio/wav', 'Drupal AI AudioFile contains WAV');
    check(str_ends_with($output->files[0]->filename, '.wav'), 'Drupal AI filename');
    check($output->response['status'] === 'success' && $output->response['usage']['chargedCredits'] === 5, 'Drupal AI raw job metadata');
    foreach (['model', 'source', 'speed', 'format', 'speakers'] as $key) {
        $configuration->setValue($provider, [$key => 'legacy']);
        try { $provider->textToSpeech('Hello', 'flowspeech-tts'); throw new RuntimeException('Expected option rejection'); }
        catch (\Drupal\ai\Exception\AiBadRequestException $error) {
            check(str_contains($error->getMessage(), 'Unsupported'), 'Provider rejects ' . $key);
        }
    }
    $configuration->setValue($provider, []);
    try { $provider->textToSpeech('Hello', 'unknown'); throw new RuntimeException('Expected model rejection'); }
    catch (\Drupal\ai\Exception\AiBadRequestException $error) { check(true, 'Provider rejects unsupported model selection'); }
    \GuzzleHttp\Client::$queue = [static fn($request) => response(job('failed',
        ['requestId' => $request['options']['headers']['Idempotency-Key'], 'error' => ['code' => 'TTS_FAILED']]))];
    try { $provider->textToSpeech('Hello', 'flowspeech-tts'); throw new RuntimeException('Expected failed job'); }
    catch (\Drupal\ai\Exception\AiBadRequestException $error) {
        check($error->getPrevious() instanceof \Drupal\flowspeech\Api\FlowSpeechException, 'Provider retains structured recovery cause');
        check($error->getPrevious()->jobId === JOB_ID, 'Provider cause exposes job ID');
    }
    $privateUrl = 'https://private.example.test/audio.wav?signature=private-fixture';
    foreach (['fixture-only', $privateUrl] as $unsafe) {
        \GuzzleHttp\Client::$queue = [response(['error' => [
            'code' => $unsafe, 'message' => $unsafe, 'retryable' => false,
        ], 'traceId' => $unsafe], 403)];
        try { $provider->textToSpeech('Hello', 'flowspeech-tts'); throw new RuntimeException('Expected HTTP failure'); }
        catch (\Drupal\ai\Exception\AiBadRequestException $error) {
            $cause = $error->getPrevious();
            check($cause instanceof \Drupal\flowspeech\Api\FlowSpeechException, 'Safe provider cause remains structured');
            check($error->getMessage() === 'FlowSpeech API returned HTTP 403.', 'Provider HTTP message is local');
            check($cause->errorCode === 'http_error' && $cause->traceId === null && $cause->getCode() === 403, 'Provider filters unsafe code and trace');
            check($cause->requestId !== null && $cause->jobId === null, 'Provider preserves submit recovery ID');
            check(!str_contains((string) $error, $unsafe), 'Provider exception string excludes reflected value');
        }
    }
    \GuzzleHttp\Client::$queue = [static fn($request) => response(job('failed', [
        'requestId' => $request['options']['headers']['Idempotency-Key'],
        'error' => ['code' => 'fixture-only', 'message' => $privateUrl, 'retryable' => false],
        'traceId' => 'fixture-only',
    ]))];
    try { $provider->textToSpeech('Hello', 'flowspeech-tts'); throw new RuntimeException('Expected terminal failure'); }
    catch (\Drupal\ai\Exception\AiBadRequestException $error) {
        check($error->getMessage() === 'FlowSpeech job failed.' && $error->getPrevious()->errorCode === 'failed', 'Provider terminal error is local');
        check($error->getPrevious()->jobId === JOB_ID && $error->getPrevious()->requestId !== null, 'Terminal wrapper preserves recovery IDs');
        check(!str_contains((string) $error, 'fixture-only') && !str_contains((string) $error, $privateUrl), 'Terminal exception chain is safe');
    }
    $previousMax = ini_set('zend.exception_string_param_max_len', '4096');
    \GuzzleHttp\Client::$queue = [static fn($request) => response(job('success', [
        'requestId' => $request['options']['headers']['Idempotency-Key'],
        'result' => array_replace(job()['result'], ['url' => $privateUrl]),
    ])), response(['error' => ['message' => $privateUrl]], 403)];
    try { $provider->textToSpeech('Hello', 'flowspeech-tts'); throw new RuntimeException('Expected storage failure'); }
    catch (\Drupal\ai\Exception\AiBadRequestException $error) {
        check(!str_contains((string) $error, $privateUrl), 'Outer provider exception does not re-render private URL arguments');
        check(str_contains((string) $error, 'Stack trace:') && str_contains((string) $error, 'FlowSpeechClient->send()'), 'Safe chain preserves useful frame names');
        check($error->getPrevious()->jobId === JOB_ID, 'Download failure remains recoverable');
    } finally { ini_set('zend.exception_string_param_max_len', $previousMax); }
    $pcm = "\x01\x02\x03\x04";
    $encoded = \Drupal\flowspeech\Audio\WavEncoder::encode($pcm, 24000, 1, 16);
    check(substr($encoded, 0, 4) === 'RIFF' && substr($encoded, 8, 4) === 'WAVE', 'Legacy WavEncoder remains compatible');
    check(strlen($encoded) === 48 && substr($encoded, 44) === $pcm, 'Legacy WavEncoder bytes');
    try { \Drupal\flowspeech\Audio\WavEncoder::encode("\0", 24000, 1, 12); throw new RuntimeException('Expected invalid bit depth'); }
    catch (\InvalidArgumentException $error) { check(true, 'Legacy WavEncoder validates depth'); }
    echo 'PASS: ' . ($checks - $initial) . " Drupal provider/legacy WAV assertions.\n";
}

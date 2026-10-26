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
    require $sourceRoot . '/src/Plugin/AiProvider/FlowSpeechProvider.php';
    require $sourceRoot . '/src/Audio/WavEncoder.php';
    $initial = $checks;
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
    $pcm = "\x01\x02\x03\x04";
    $encoded = \Drupal\flowspeech\Audio\WavEncoder::encode($pcm, 24000, 1, 16);
    check(substr($encoded, 0, 4) === 'RIFF' && substr($encoded, 8, 4) === 'WAVE', 'Legacy WavEncoder remains compatible');
    check(strlen($encoded) === 48 && substr($encoded, 44) === $pcm, 'Legacy WavEncoder bytes');
    try { \Drupal\flowspeech\Audio\WavEncoder::encode("\0", 24000, 1, 12); throw new RuntimeException('Expected invalid bit depth'); }
    catch (\InvalidArgumentException $error) { check(true, 'Legacy WavEncoder validates depth'); }
    echo 'PASS: ' . ($checks - $initial) . " Drupal provider/legacy WAV assertions.\n";
}
